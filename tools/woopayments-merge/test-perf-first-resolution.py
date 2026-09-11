#!/usr/bin/env python3
"""Focused regressions for gateway-initialization performance evidence."""

from __future__ import annotations

import json
import os
import signal
import shutil
import subprocess
import time
from pathlib import Path

import pytest


REPO = Path(__file__).resolve().parents[2]
TOOLS = REPO / "tools/woopayments-merge"
BASELINE_PROBE = TOOLS / "perf-baseline.php"
BASELINE_GATE = TOOLS / "perf-baseline.sh"
SURFACE_GATE = TOOLS / "perf-surface-gate.sh"
APPROVED_WP_RUNNER = "docker exec -i wcpay_wp_default wp --allow-root"
ORACLE_WP_RUNNER = "docker exec -i wcpay_wp_codex_oracle_10_8 wp --allow-root"


def write_executable(path: Path, source: str) -> None:
    path.write_text(source, encoding="utf-8")
    path.chmod(0o755)


def make_fake_wordpress_harness(path: Path) -> None:
    path.write_text(
        """<?php
$late_bootstrap = '1' === (string) getenv( 'FAKE_WP_LATE_BOOTSTRAP' );
$GLOBALS['fake_hooks'] = array();
$GLOBALS['fake_did_actions'] = $late_bootstrap ? array( 'wc_payment_gateways_initialized' => 1 ) : array();

class FakeWpdb {
    public $queries = array();
}

class WP_Error {
    public function __construct( $code = '', $message = '', $data = null ) {
        unset( $code, $message, $data );
    }
}

class FakeGateway {
    public $id = 'woocommerce_payments';
}

class FakeGatewayRegistry {
    public $payment_gateways = array();

    public function __construct() {
        $this->payment_gateways = array( 'woocommerce_payments' => new FakeGateway() );
    }

    public function payment_gateways() {
        return $this->payment_gateways;
    }
}

function add_filter( $hook, $callback, $priority = 10, $accepted_args = 1 ) {
    $GLOBALS['fake_hooks'][ $hook ][ (int) $priority ][] = array( $callback, (int) $accepted_args );
    return true;
}

function add_action( $hook, $callback, $priority = 10, $accepted_args = 1 ) {
    return add_filter( $hook, $callback, $priority, $accepted_args );
}

function apply_filters( $hook, $value, ...$args ) {
    $callbacks = $GLOBALS['fake_hooks'][ $hook ] ?? array();
    ksort( $callbacks );
    foreach ( $callbacks as $priority_callbacks ) {
        foreach ( $priority_callbacks as $entry ) {
            list( $callback, $accepted_args ) = $entry;
            $all_args = array_merge( array( $value ), $args );
            $value = $callback( ...array_slice( $all_args, 0, $accepted_args ) );
        }
    }
    return $value;
}

function do_action( $hook, ...$args ) {
    $GLOBALS['fake_did_actions'][ $hook ] = ( $GLOBALS['fake_did_actions'][ $hook ] ?? 0 ) + 1;
    $callbacks = $GLOBALS['fake_hooks'][ $hook ] ?? array();
    ksort( $callbacks );
    foreach ( $callbacks as $priority_callbacks ) {
        foreach ( $priority_callbacks as $entry ) {
            list( $callback, $accepted_args ) = $entry;
            $callback( ...array_slice( $args, 0, $accepted_args ) );
        }
    }
}

function did_action( $hook ) {
    return $GLOBALS['fake_did_actions'][ $hook ] ?? 0;
}

function wp_json_encode( $value, $flags = 0 ) {
    return json_encode( $value, $flags );
}

$wpdb = new FakeWpdb();
$GLOBALS['wpdb'] = $wpdb;
require $argv[1];

if ( ! $late_bootstrap ) {
    add_filter(
        'woocommerce_payment_gateways',
        function ( $gateways ) use ( $wpdb ) {
            $wpdb->queries[] = array(
                "SELECT option_value FROM wp_options WHERE option_name = 'woocommerce_payments_settings' LIMIT 1",
                0.001,
                'FakeGateway->__construct, WC_Payment_Gateways->init',
            );
            apply_filters( 'pre_http_request', false, array(), 'https://api.example.test' );
            $gateways[] = 'FakeGateway';
            return $gateways;
        },
        10,
        1
    );
    apply_filters( 'woocommerce_payment_gateways', array() );
    do_action( 'wc_payment_gateways_initialized', new FakeGatewayRegistry() );
}

$initialization = $GLOBALS['woopayments_perf_gateway_initialization_probe'] ?? array(
    'status' => 'incomplete',
    'reason' => 'MU bootstrap probe did not publish evidence.',
);
$warm_metrics = array(
    'queries' => 0,
    'external_requests' => 0,
    'median_ms' => 1.0,
    'timing_sample_count' => 5,
    'measurement_mode' => 'warmed_repeated',
    'gateway_count' => 1,
    'gateway_ids' => array( 'woocommerce_payments' ),
    'duplicate_gateway_ids' => array(),
    'action_callback_count' => 1,
);
$result = array(
    'schema' => 'woopayments_measured_gate.v1',
    'mode' => 'perf',
    'probes' => array(
        'gateway_initialization' => $initialization,
        'gateway_first_resolution' => $initialization,
        'gateway_registration' => array( 'status' => 'measured', 'metrics' => $warm_metrics ),
        'rest_boot' => array(
            'status' => 'measured',
            'metrics' => array(
                'queries' => 0,
                'external_requests' => 0,
                'elapsed_ms' => 1.0,
                'timing_sample_count' => 1,
                'measurement_mode' => 'single_invocation_rest_api_init',
                'route_count' => 1,
                'payment_route_count' => 1,
                'route_registration_status' => 'measured',
                'controller_instantiation_count' => 1,
                'controller_instantiation_status' => 'measured',
            ),
        ),
        'autoload_options' => array( 'status' => 'measured', 'metrics' => array( 'autoload_bytes' => 100 ) ),
        'wcpay_account_data' => array( 'status' => 'measured', 'metrics' => array( 'autoload' => 'off' ) ),
        'process_payment' => array( 'status' => 'requires_fixture', 'reason' => 'fake fixture omitted' ),
        'refund' => array( 'status' => 'requires_fixture', 'reason' => 'fake fixture omitted' ),
        'capture' => array( 'status' => 'requires_fixture', 'reason' => 'fake fixture omitted' ),
    ),
);
echo wp_json_encode( $result, JSON_PRETTY_PRINT );
""",
        encoding="utf-8",
    )


def make_fake_docker(tmp_path: Path) -> tuple[dict[str, str], Path, Path, Path]:
    bin_dir = tmp_path / "bin"
    bin_dir.mkdir()
    docker = bin_dir / "docker"
    invocation_log = tmp_path / "docker-invocations.jsonl"
    container_root = tmp_path / "container"
    mu_dir = container_root / "var/www/html/wp-content/mu-plugins"
    mu_dir.mkdir(parents=True)
    (mu_dir / "existing-mu-plugin.php").write_text("<?php // Must survive probe cleanup.\n", encoding="utf-8")
    harness = tmp_path / "fake-wordpress.php"
    make_fake_wordpress_harness(harness)

    write_executable(
        docker,
        """#!/usr/bin/env python3
import json
import os
import shutil
import subprocess
import sys
import time
from pathlib import Path

args = sys.argv[1:]
log = Path(os.environ['FAKE_DOCKER_LOG'])
with log.open('a', encoding='utf-8') as stream:
    stream.write(json.dumps(args) + '\\n')

if args[:2] == ['context', 'show']:
    print('default')
    raise SystemExit(0)
if args[:2] == ['context', 'inspect']:
    print('[{"Endpoints":{"docker":{"Host":"unix:///fake/docker.sock"}}}]')
    raise SystemExit(0)
if not args or args[0] != 'exec':
    raise SystemExit(91)

index = 1
child_env = os.environ.copy()
while index < len(args) and args[index].startswith('-'):
    option = args[index]
    if option in {'-e', '--env'}:
        key, value = args[index + 1].split('=', 1)
        child_env[key] = value
        index += 2
    elif option.startswith('--env='):
        key, value = option.removeprefix('--env=').split('=', 1)
        child_env[key] = value
        index += 1
    elif option in {'--detach-keys', '--env-file', '-u', '--user', '-w', '--workdir'}:
        index += 2
    else:
        index += 1

container = args[index]
command = args[index + 1:]
if container != os.environ.get('FAKE_APPROVED_CONTAINER', 'wcpay_wp_default'):
    raise SystemExit(92)

root = Path(os.environ['FAKE_CONTAINER_ROOT'])
def container_path(value):
    return root / value.lstrip('/')

if command and command[0] == 'tee':
    destination = container_path(command[-1])
    destination.write_bytes(sys.stdin.buffer.read())
    raise SystemExit(0)
if command and command[0] == 'test':
    target = container_path(command[-1])
    if command[1:3] == ['!', '-e']:
        raise SystemExit(0 if not target.exists() else 1)
    if command[1] == '-d':
        raise SystemExit(0 if target.is_dir() else 1)
    if command[1] == '-f':
        raise SystemExit(0 if target.is_file() else 1)
    raise SystemExit(93)
if command and command[0] == 'rm':
    container_path(command[-1]).unlink(missing_ok=True)
    raise SystemExit(0)
if not command or Path(command[0]).name not in {'wp', 'wp-cli', 'wp-cli.phar', 'wp.phar'}:
    raise SystemExit(94)

if 'eval' in command and 'eval-file' not in command:
    print('WPMERGE_PERF_MU_PLUGIN_DIR:/var/www/html/wp-content/mu-plugins')
    raise SystemExit(0)
if 'eval-file' not in command:
    raise SystemExit(95)

sys.stdin.read()
marker = os.environ.get('FAKE_WP_STARTED_MARKER')
if marker:
    Path(marker).write_text('started', encoding='utf-8')
if os.environ.get('FAKE_WP_BLOCK') == '1':
    time.sleep(60)
if os.environ.get('FAKE_WP_FAIL') == '1':
    raise SystemExit(9)

probes = sorted((root / 'var/www/html/wp-content/mu-plugins').glob('woopayments-perf-gateway-init-*.php'))
if probes:
    completed = subprocess.run(
        [shutil.which('php') or 'php', os.environ['FAKE_WP_HARNESS'], str(probes[0])],
        env=child_env,
        text=True,
        stdout=subprocess.PIPE,
        stderr=subprocess.PIPE,
        check=False,
    )
    sys.stdout.write(completed.stdout)
    sys.stderr.write(completed.stderr)
    raise SystemExit(completed.returncode)

print(json.dumps({
    'schema': 'woopayments_measured_gate.v1',
    'mode': 'perf',
    'probes': {
        'gateway_first_resolution': {
            'status': 'incomplete',
            'reason': 'The gateway registry was initialized before the eval-file probe.',
            'metrics': {'gateway_preinitialized': True, 'measurement_mode': 'preinitialized_first_observed_call'},
        }
    },
}))
""",
    )

    env = os.environ.copy()
    env.update(
        {
            "PATH": f"{bin_dir}{os.pathsep}{env['PATH']}",
            "TMPDIR": str(tmp_path / "host-tmp"),
            "FAKE_CONTAINER_ROOT": str(container_root),
            "FAKE_DOCKER_LOG": str(invocation_log),
            "FAKE_WP_HARNESS": str(harness),
        }
    )
    Path(env["TMPDIR"]).mkdir()
    return env, invocation_log, container_root, mu_dir


def run_fake_surface_capture(
    tmp_path: Path,
    *,
    late_bootstrap: bool = False,
    fail_wp: bool = False,
    runner: str = APPROVED_WP_RUNNER,
) -> tuple[subprocess.CompletedProcess[str], dict, list[list[str]], Path]:
    env, invocation_log, container_root, _ = make_fake_docker(tmp_path)
    container = runner.split()[3]
    env["FAKE_APPROVED_CONTAINER"] = container
    if runner != APPROVED_WP_RUNNER:
        env["WOOPAYMENTS_APPROVED_REF_CONTAINER"] = container
    if late_bootstrap:
        env["FAKE_WP_LATE_BOOTSTRAP"] = "1"
    if fail_wp:
        env["FAKE_WP_FAIL"] = "1"
    output = tmp_path / "capture.json"
    result = subprocess.run(
        [
            "bash",
            str(SURFACE_GATE),
            "capture",
            "--wp",
            runner,
            "--out",
            str(output),
        ],
        cwd=REPO,
        env=env,
        text=True,
        stdout=subprocess.PIPE,
        stderr=subprocess.STDOUT,
        check=False,
    )
    payload = json.loads(output.read_text(encoding="utf-8")) if output.is_file() and output.stat().st_size else {}
    invocations = [json.loads(line) for line in invocation_log.read_text(encoding="utf-8").splitlines()]
    return result, payload, invocations, container_root


def first_resolution_probe(queries: int = 1, elapsed_ms: float = 1.0) -> dict:
    return {
        "status": "measured",
        "metrics": {
            "queries": queries,
            "external_requests": 0,
            "elapsed_ms": elapsed_ms,
            "timing_sample_count": 1,
            "measurement_mode": "first_in_process_gateway_resolution",
            "gateway_preinitialized": False,
            "gateway_count": 1,
            "gateway_ids": ["woocommerce_payments"],
            "top_query_groups": [
                {
                    "count": queries,
                    "sql": "SELECT option_value FROM wp_options WHERE option_name = '?' LIMIT ?",
                    "top_callers": ["WC_Payment_Gateways->init"],
                }
            ],
        },
    }


def gateway_initialization_probe(queries: int = 1, elapsed_ms: float = 1.0) -> dict:
    return {
        "status": "measured",
        "metrics": {
            "queries": queries,
            "external_requests": 0,
            "elapsed_ms": elapsed_ms,
            "timing_sample_count": 1,
            "measurement_mode": "gateway_initialization_lifecycle",
            "gateway_preinitialized": False,
            "gateway_count": 1,
            "gateway_ids": ["woocommerce_payments"],
            "lifecycle_start_hook": "woocommerce_payment_gateways",
            "lifecycle_end_hook": "wc_payment_gateways_initialized",
            "caller_backtrace": ["apply_filters('woocommerce_payment_gateways')"],
            "top_query_groups": [
                {
                    "count": queries,
                    "sql": "SELECT option_value FROM wp_options WHERE option_name = '?' LIMIT ?",
                    "top_callers": ["WC_Payment_Gateways->init"],
                }
            ],
        },
    }


def warm_surface() -> dict:
    return {
        "queries": 0,
        "median_ms": 1.0,
        "timing_sample_count": 5,
        "measurement_mode": "warmed_repeated",
    }


def baseline_capture(queries: int = 1, elapsed_ms: float = 1.0) -> dict:
    return {
        "first_gateway_resolution": first_resolution_probe(queries, elapsed_ms),
        "surfaces": {
            "available_gateways": warm_surface(),
            "all_gateways": warm_surface(),
            "wcpay_is_available": warm_surface(),
        },
    }


def money_probe() -> dict:
    return {
        "status": "measured",
        "metrics": {
            "queries": 1,
            "external_requests": 1,
            "elapsed_ms": 1.0,
            "timing_sample_count": 1,
            "measurement_mode": "single_invocation_blocked_http",
            "top_query_groups": [],
        },
    }


def surface_capture(
    queries: int = 1,
    elapsed_ms: float = 1.0,
    rest_elapsed_ms: float = 1.0,
) -> dict:
    initialization = gateway_initialization_probe(queries, elapsed_ms)
    return {
        "schema": "woopayments_measured_gate.v1",
        "mode": "perf",
        "probes": {
            "gateway_initialization": initialization,
            "gateway_first_resolution": initialization,
            "gateway_registration": {
                "status": "measured",
                "metrics": {
                    "queries": 0,
                    "external_requests": 0,
                    "median_ms": 1.0,
                    "timing_sample_count": 5,
                    "measurement_mode": "warmed_repeated",
                    "gateway_count": 1,
                    "action_callback_count": 1,
                    "duplicate_gateway_ids": [],
                },
            },
            "rest_boot": {
                "status": "measured",
                "metrics": {
                    "queries": 0,
                    "external_requests": 0,
                    "elapsed_ms": rest_elapsed_ms,
                    "timing_sample_count": 1,
                    "measurement_mode": "single_invocation_rest_api_init",
                    "route_count": 2,
                    "payment_route_count": 1,
                    "route_registration_status": "measured",
                    "controller_instantiation_count": 1,
                    "controller_instantiation_status": "measured",
                },
            },
            "autoload_options": {
                "status": "measured",
                "metrics": {"autoload_bytes": 100},
            },
            "wcpay_account_data": {
                "status": "measured",
                "metrics": {"autoload": "off"},
            },
            "process_payment": money_probe(),
            "refund": money_probe(),
            "capture": money_probe(),
        },
    }


def run_baseline_check(tmp_path: Path, reference: dict, target: dict) -> subprocess.CompletedProcess[str]:
    gate = tmp_path / "perf-baseline.sh"
    shutil.copy2(BASELINE_GATE, gate)
    (tmp_path / "perf-baseline.php").write_text("<?php // Test placeholder.\n", encoding="utf-8")
    (tmp_path / "perf-baseline.json").write_text(json.dumps(reference), encoding="utf-8")

    wp_runner = tmp_path / "fake-wp"
    wp_runner.write_text(
        "#!/usr/bin/env bash\ncat >/dev/null\nprintf '%s\\n' \"$FAKE_PROBE_JSON\"\n",
        encoding="utf-8",
    )
    wp_runner.chmod(0o755)

    env = os.environ.copy()
    env.update(
        {
            "FAKE_PROBE_JSON": json.dumps(target),
            "TMPDIR": str(tmp_path),
            "WP": str(wp_runner),
        }
    )
    return subprocess.run(
        ["bash", str(gate), "check"],
        cwd=tmp_path,
        env=env,
        text=True,
        stdout=subprocess.PIPE,
        stderr=subprocess.STDOUT,
        check=False,
    )


def run_surface_compare(
    tmp_path: Path,
    reference: dict,
    target: dict,
    *,
    gateway_initialization_only: bool = False,
) -> subprocess.CompletedProcess[str]:
    ref_path = tmp_path / "ref.json"
    target_path = tmp_path / "target.json"
    ref_path.write_text(json.dumps(reference), encoding="utf-8")
    target_path.write_text(json.dumps(target), encoding="utf-8")
    args = ["bash", str(SURFACE_GATE), "compare", "--ref", str(ref_path), "--target", str(target_path)]
    if gateway_initialization_only:
        args.append("--gateway-initialization-only")
    return subprocess.run(
        args,
        cwd=REPO,
        text=True,
        stdout=subprocess.PIPE,
        stderr=subprocess.STDOUT,
        check=False,
    )


def run_baseline_php_probe(tmp_path: Path, preinitialize: bool = False) -> dict:
    php = shutil.which("php")
    if php is None:
        pytest.skip("php is not available")

    preinitialize_php = "WC_Payment_Gateways::instance();" if preinitialize else ""
    harness = tmp_path / "baseline-harness.php"
    harness.write_text(
        f"""<?php
class FakeWpdb {{
    public $queries = array();
}}
$wpdb = new FakeWpdb();
$GLOBALS['wpdb'] = $wpdb;
$GLOBALS['test_filters'] = array();

function add_filter( $hook, $callback, $priority = 10, $accepted_args = 1 ) {{
    $GLOBALS['test_filters'][ $hook ][] = $callback;
}}
function wp_json_encode( $value, $flags = 0 ) {{
    return json_encode( $value, $flags );
}}

class WP_CLI {{
    public static function line( $message ) {{
        echo $message;
    }}
}}
class FakeGateway {{
    public $id = 'woocommerce_payments';
    public function is_available() {{
        return true;
    }}
}}
class WC_Payment_Gateways {{
    protected static $_instance = null;
    private $gateways;

    private function __construct() {{
        global $wpdb;
        $wpdb->queries[] = array(
            "SELECT option_value FROM wp_options WHERE option_name = 'woocommerce_payments_settings' LIMIT 1",
            0.001,
            'WC_Payment_Gateways->init',
        );
        $this->gateways = array( 'woocommerce_payments' => new FakeGateway() );
    }}
    public static function instance() {{
        if ( null === self::$_instance ) {{
            self::$_instance = new self();
        }}
        return self::$_instance;
    }}
    public function payment_gateways() {{
        return $this->gateways;
    }}
    public function get_available_payment_gateways() {{
        return $this->gateways;
    }}
}}
class FakeWooCommerce {{
    public function payment_gateways() {{
        return WC_Payment_Gateways::instance();
    }}
}}
function WC() {{
    static $woocommerce = null;
    if ( null === $woocommerce ) {{
        $woocommerce = new FakeWooCommerce();
    }}
    return $woocommerce;
}}

{preinitialize_php}
require {json.dumps(str(BASELINE_PROBE))};
""",
        encoding="utf-8",
    )
    result = subprocess.run(
        [php, str(harness)],
        cwd=REPO,
        text=True,
        stdout=subprocess.PIPE,
        stderr=subprocess.PIPE,
        check=False,
    )
    assert result.returncode == 0, result.stderr
    return json.loads(result.stdout)


def test_baseline_probe_isolates_first_resolution_from_warm_samples(tmp_path):
    capture = run_baseline_php_probe(tmp_path)

    first = capture["first_gateway_resolution"]
    assert first["status"] == "measured"
    assert first["metrics"]["queries"] == 1
    assert first["metrics"]["timing_sample_count"] == 1
    assert first["metrics"]["measurement_mode"] == "first_in_process_gateway_resolution"
    assert first["metrics"]["gateway_preinitialized"] is False
    assert "elapsed_ms" in first["metrics"]
    assert "median_ms" not in first["metrics"]

    assert capture["surfaces"]["all_gateways"]["queries"] == 0
    assert capture["surfaces"]["all_gateways"]["timing_sample_count"] == 5
    assert capture["surfaces"]["all_gateways"]["measurement_mode"] == "warmed_repeated"


def test_baseline_probe_refuses_to_call_preinitialized_gateway_state_first_resolution(tmp_path):
    capture = run_baseline_php_probe(tmp_path, preinitialize=True)

    first = capture["first_gateway_resolution"]
    assert first["status"] == "incomplete"
    assert first["metrics"]["gateway_preinitialized"] is True
    assert first["metrics"]["measurement_mode"] == "preinitialized_first_observed_call"


def test_capture_observes_gateway_initialization_from_pre_plugin_mu_bootstrap(tmp_path):
    result, capture, _, _ = run_fake_surface_capture(tmp_path)

    assert result.returncode == 0, result.stdout
    initialization = capture["probes"]["gateway_initialization"]
    assert initialization["status"] == "measured"
    metrics = initialization["metrics"]
    assert metrics["measurement_mode"] == "gateway_initialization_lifecycle"
    assert metrics["lifecycle_start_hook"] == "woocommerce_payment_gateways"
    assert metrics["lifecycle_end_hook"] == "wc_payment_gateways_initialized"
    assert metrics["queries"] == 1
    assert metrics["external_requests"] == 1
    assert 1 <= len(metrics["caller_backtrace"]) <= 12
    assert len(metrics["top_query_groups"]) <= 10
    assert all(len(group["top_callers"]) <= 3 for group in metrics["top_query_groups"])
    assert capture["probes"]["gateway_first_resolution"] == initialization


def test_capture_accepts_top_level_approved_oracle_reference_container(tmp_path):
    result, capture, _, _ = run_fake_surface_capture(tmp_path, runner=ORACLE_WP_RUNNER)

    assert result.returncode == 0, result.stdout
    assert capture["probes"]["gateway_initialization"]["status"] == "measured"


def test_capture_uses_unique_env_gate_and_removes_only_its_mu_probe(tmp_path):
    result, _, invocations, container_root = run_fake_surface_capture(tmp_path)

    assert result.returncode == 0, result.stdout
    installs = [args for args in invocations if "tee" in args]
    removals = [args for args in invocations if "rm" in args]
    assert len(installs) == 1
    assert len(removals) == 1
    installed_path = installs[0][-1]
    assert installed_path.startswith("/var/www/html/wp-content/mu-plugins/woopayments-perf-gateway-init-")
    assert installed_path.endswith(".php")
    assert removals[0][-1] == installed_path

    token = Path(installed_path).stem.removeprefix("woopayments-perf-gateway-init-")
    activation = f"WPMERGE_PERF_GATEWAY_INIT_TOKEN={token}"
    wp_invocations = [args for args in invocations if "eval-file" in args]
    assert len(wp_invocations) == 1
    assert activation in wp_invocations[0]
    assert [args for args in invocations if activation in args] == wp_invocations
    assert all("wcpay_wp_default" in args for args in invocations if args and args[0] == "exec")
    assert not (container_root / installed_path.lstrip("/")).exists()
    assert (container_root / "var/www/html/wp-content/mu-plugins/existing-mu-plugin.php").is_file()


def test_capture_removes_exact_mu_probe_when_wp_command_fails(tmp_path):
    result, _, invocations, container_root = run_fake_surface_capture(tmp_path, fail_wp=True)

    assert result.returncode == 2
    installs = [args for args in invocations if "tee" in args]
    removals = [args for args in invocations if "rm" in args]
    assert len(installs) == 1
    assert len(removals) == 1
    assert removals[0][-1] == installs[0][-1]
    assert not (container_root / installs[0][-1].lstrip("/")).exists()


def test_perf_probe_serialization_handles_invalid_utf8_explicitly():
    source = SURFACE_GATE.read_text(encoding="utf-8")

    assert "JSON_PRETTY_PRINT | JSON_INVALID_UTF8_SUBSTITUTE" in source
    assert "WP-CLI perf probe JSON serialization failed" in source


def test_probe_writers_keep_their_paths_function_local():
    source = SURFACE_GATE.read_text(encoding="utf-8")
    gateway_writer = source[
        source.index("write_gateway_initialization_probe()") : source.index(
            "write_probe()"
        )
    ]
    main_writer = source[
        source.index("write_probe()") : source.index(
            "compare_gateway_initialization()"
        )
    ]

    assert 'local probe_file="$1"' in gateway_writer
    assert 'local probe_file="$1"' in main_writer


def test_capture_removes_exact_mu_probe_when_signalled(tmp_path):
    env, invocation_log, container_root, _ = make_fake_docker(tmp_path)
    marker = tmp_path / "wp-started"
    env.update({"FAKE_WP_BLOCK": "1", "FAKE_WP_STARTED_MARKER": str(marker)})
    output = tmp_path / "capture.json"
    process = subprocess.Popen(
        [
            "bash",
            str(SURFACE_GATE),
            "capture",
            "--wp",
            APPROVED_WP_RUNNER,
            "--out",
            str(output),
        ],
        cwd=REPO,
        env=env,
        text=True,
        stdout=subprocess.PIPE,
        stderr=subprocess.STDOUT,
        start_new_session=True,
    )
    try:
        deadline = time.monotonic() + 5
        while time.monotonic() < deadline and not marker.exists():
            time.sleep(0.02)
        assert marker.exists(), "fake WP runner did not start"
        os.killpg(process.pid, signal.SIGTERM)
        process.communicate(timeout=5)
    finally:
        if process.poll() is None:
            os.killpg(process.pid, signal.SIGKILL)
            process.communicate(timeout=5)

    assert process.returncode != 0
    invocations = [json.loads(line) for line in invocation_log.read_text(encoding="utf-8").splitlines()]
    installs = [args for args in invocations if "tee" in args]
    removals = [args for args in invocations if "rm" in args]
    assert len(installs) == 1
    assert len(removals) == 1
    assert removals[0][-1] == installs[0][-1]
    assert not (container_root / installs[0][-1].lstrip("/")).exists()
    assert (container_root / "var/www/html/wp-content/mu-plugins/existing-mu-plugin.php").is_file()


def test_too_late_mu_bootstrap_is_incomplete_and_compare_exits_three(tmp_path):
    result, capture, _, _ = run_fake_surface_capture(tmp_path, late_bootstrap=True)

    assert result.returncode == 0, result.stdout
    initialization = capture["probes"]["gateway_initialization"]
    assert initialization["status"] == "incomplete"
    assert "already initialized" in initialization["reason"].lower()

    compared = run_surface_compare(tmp_path, capture, capture)
    assert compared.returncode == 3, compared.stdout
    assert "RESULT: INCOMPLETE gateway initialization evidence" in compared.stdout


def test_surface_compare_accepts_legacy_first_resolution_schema(tmp_path):
    reference = surface_capture()
    target = surface_capture()
    for capture in (reference, target):
        capture["probes"].pop("gateway_initialization")
        capture["probes"]["gateway_first_resolution"] = first_resolution_probe()

    result = run_surface_compare(tmp_path, reference, target)

    assert result.returncode == 0, result.stdout
    assert "ok    gateway_initialization: queries 1 -> 1" in result.stdout


def test_gateway_initialization_only_compare_does_not_require_money_fixtures(tmp_path):
    reference = surface_capture()
    target = surface_capture()
    for capture in (reference, target):
        for name in ("process_payment", "refund", "capture"):
            capture["probes"][name] = {
                "status": "requires_fixture",
                "reason": f"{name} fixture omitted",
            }

    result = run_surface_compare(
        tmp_path,
        reference,
        target,
        gateway_initialization_only=True,
    )

    assert result.returncode == 0, result.stdout
    assert "ok    gateway_initialization: queries 1 -> 1" in result.stdout


def test_surface_probe_does_not_reflect_or_reset_gateway_singleton():
    source = SURFACE_GATE.read_text(encoding="utf-8")

    assert "ReflectionProperty( WC_Payment_Gateways::class, '_instance' )" not in source
    assert "WC_Payment_Gateways::$_instance" not in source
    assert "setValue( null" not in source


def test_surface_probe_keeps_warmed_and_money_paths_in_later_eval_process():
    source = SURFACE_GATE.read_text(encoding="utf-8")

    assert "'gateway_initialization'" in source
    assert "$gateway_measure = $measure(" in source
    assert "$process_payment_probe = $measure_process_payment();" in source
    assert "$refund_probe          = $measure_refund();" in source
    assert "$capture_probe         = $measure_capture();" in source

    measure_once = source[source.index("$measure_once ="):source.index("$requires_fixture =")]
    assert "'elapsed_ms'" in measure_once
    assert "'timing_sample_count' => 1" in measure_once
    assert "'median_ms'" not in measure_once


def test_rest_boot_one_shot_uses_elapsed_time_not_a_fake_median():
    source = SURFACE_GATE.read_text(encoding="utf-8")
    rest_slice = source[
        source.index("$progress( 'measuring REST route registration' )") : source.index(
            "$routes = array_keys"
        )
    ]

    assert "'elapsed_ms'" in rest_slice
    assert "$rest_measure_raw['elapsed_ms']" in rest_slice
    assert "$rest_measure_raw['median_ms']" not in rest_slice
    assert "'measurement_mode'    => 'single_invocation_blocked_http'" in source


def test_rest_boot_one_shot_time_is_diagnostic_only(tmp_path):
    result = run_surface_compare(
        tmp_path,
        surface_capture(rest_elapsed_ms=1),
        surface_capture(rest_elapsed_ms=10_000),
    )

    assert result.returncode == 0, result.stdout
    assert (
        "note  rest_boot: elapsed_ms 1 -> 10000 "
        "(single invocation; diagnostic only)"
    ) in result.stdout


def test_money_path_one_shot_time_is_diagnostic_only(tmp_path):
    reference = surface_capture()
    target = surface_capture()
    for name in ("process_payment", "refund", "capture"):
        reference["probes"][name]["metrics"]["elapsed_ms"] = 1
        target["probes"][name]["metrics"]["elapsed_ms"] = 10_000

    result = run_surface_compare(tmp_path, reference, target)

    assert result.returncode == 0, result.stdout
    for name in ("process_payment", "refund", "capture"):
        assert (
            f"note  {name}: elapsed_ms 1 -> 10000 "
            "(single invocation; diagnostic only)"
        ) in result.stdout


@pytest.mark.parametrize(
    ("runner", "capture_factory", "label"),
    [
        (run_baseline_check, baseline_capture, "gateway_first_resolution"),
        (run_surface_compare, surface_capture, "gateway_initialization"),
    ],
    ids=("phase-1-baseline", "measured-surface"),
)
def test_gateway_initialization_query_growth_fails(tmp_path, runner, capture_factory, label):
    result = runner(tmp_path, capture_factory(queries=1), capture_factory(queries=2))

    assert result.returncode == 1, result.stdout
    assert f"REGRESS {label}: queries 1 -> 2" in result.stdout
    assert "target top query groups" in result.stdout


@pytest.mark.parametrize(
    ("runner", "capture_factory", "expected_note"),
    [
        (
            run_baseline_check,
            baseline_capture,
            "note  gateway_first_resolution: elapsed_ms 1 -> 10000 "
            "(single first-resolution sample; diagnostic only)",
        ),
        (
            run_surface_compare,
            surface_capture,
            "note  gateway_initialization: elapsed_ms 1 -> 10000 "
            "(single gateway-initialization sample; diagnostic only)",
        ),
    ],
    ids=("phase-1-baseline", "measured-surface"),
)
def test_gateway_initialization_one_shot_time_is_diagnostic_only(
    tmp_path, runner, capture_factory, expected_note
):
    result = runner(tmp_path, capture_factory(elapsed_ms=1), capture_factory(elapsed_ms=10_000))

    assert result.returncode == 0, result.stdout
    assert expected_note in result.stdout
