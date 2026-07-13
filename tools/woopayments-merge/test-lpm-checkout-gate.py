#!/usr/bin/env python3
"""Focused regression checks for the LPM checkout gate harness."""

from __future__ import annotations

import base64
import json
import os
import shutil
import subprocess
import tempfile
from pathlib import Path

from tools.woopayments_test_runner import adapt_wp_runner_arguments


REPO = Path(__file__).resolve().parents[2]
SCRIPT = REPO / "tools/woopayments-merge/lpm-checkout-gate.sh"
BROWSER_DRIVER = REPO / "tools/woopayments-merge/lpm-checkout.playwriter.mjs"
NORMALIZER = REPO / "tools/woopayments-merge/normalize-bucket-e-cross.py"
PAYMENT_METHOD_FIXTURE_STATE = REPO / "tools/woopayments-merge/payment-method-fixture-state.php"
LPM_EVIDENCE = REPO / "tools/woopayments-merge/lpm_evidence.py"
REF_WP = "docker exec -i wcpay_wp_default wp --allow-root"
TARGET_WP = "docker exec -i target-cli-1 wp --allow-root --user=1"
REF_URL = "http://localhost:8082"
TARGET_URL = "http://store8889.localhost:8889"


def run_gate(*args: str, env: dict[str, str] | None = None) -> subprocess.CompletedProcess[str]:
	process_env = os.environ.copy()
	if env:
		process_env.update(env)
	command_args = list(args)
	if "--ref" in command_args and "--target" in command_args:
		if "--ref-url" not in command_args and not any(item.startswith("--ref-url=") for item in command_args):
			command_args.extend(("--ref-url", REF_URL))
		if "--target-url" not in command_args and not any(item.startswith("--target-url=") for item in command_args):
			command_args.extend(("--target-url", TARGET_URL))
	command_args, process_env = adapt_wp_runner_arguments(command_args, process_env)
	return subprocess.run(
		["bash", str(SCRIPT), *command_args],
		cwd=REPO,
		text=True,
		stdout=subprocess.PIPE,
		stderr=subprocess.PIPE,
		env=process_env,
		check=False,
	)


def normalize_bucket_record(record: dict) -> dict:
    result = subprocess.run(
        ["python3", str(NORMALIZER)],
        cwd=REPO,
        input=json.dumps(record) + "\n",
        text=True,
        stdout=subprocess.PIPE,
        stderr=subprocess.PIPE,
        check=False,
    )

    assert result.returncode == 0, result.stderr
    return json.loads(result.stdout)


def run_fixture_php(source: str, directory: Path) -> subprocess.CompletedProcess[str]:
    php = shutil.which("php")
    assert php is not None
    path = directory / "fixture-state-test.php"
    path.write_text(source, encoding="utf-8")
    return subprocess.run(
        [php, str(path)],
        text=True,
        stdout=subprocess.PIPE,
        stderr=subprocess.PIPE,
        check=False,
    )


def test_fixture_snapshot_fails_closed_on_database_read_error() -> None:
    with tempfile.TemporaryDirectory(prefix="lpm-fixture-php-test-") as tmp:
        tmp_path = Path(tmp)
        payload = base64.b64encode(json.dumps(["ideal", "EUR", "NL"]).encode()).decode()
        source = f"""<?php
define( 'ARRAY_A', 'ARRAY_A' );
function wp_json_encode( $value, $flags = 0 ) {{ return json_encode( $value, $flags ); }}
function sanitize_key( $value ) {{ return strtolower( preg_replace( '/[^a-z0-9_]/i', '', $value ) ); }}
function sanitize_text_field( $value ) {{ return (string) $value; }}
function get_option( $name, $default = false ) {{
    if ( 'wcpay_account_data' === $name ) {{
        return array( 'data' => array(
            'account_id' => 'acct_test',
            'test_publishable_key' => 'pk_test',
            'capabilities' => array( 'ideal_payments' => 'active' ),
        ) );
    }}
    if ( 'woocommerce_currency' === $name ) {{ return 'USD'; }}
    if ( 'woocommerce_default_country' === $name ) {{ return 'US:CA'; }}
    return $default;
}}
function update_option() {{ throw new RuntimeException( 'snapshot mode mutated options' ); }}
function delete_option() {{ throw new RuntimeException( 'snapshot mode mutated options' ); }}
function wp_cache_delete() {{ return true; }}
class FakeWpdb {{
    public $options = 'wp_options';
    public $last_error = '';
    public function prepare( $query, ...$args ) {{ return array( $query, $args ); }}
    public function get_row( $query, $format ) {{ $this->last_error = 'synthetic_read_failure'; return null; }}
}}
$wpdb = new FakeWpdb();
$args = array( 'snapshot-lpm-fixture', {json.dumps(payload)} );
require {json.dumps(str(PAYMENT_METHOD_FIXTURE_STATE))};
"""

        result = run_fixture_php(source, tmp_path)

        assert result.returncode == 0, result.stderr
        response = json.loads(result.stdout.splitlines()[-1])
        assert response["success"] is False
        assert response["mode"] == "snapshot-lpm-fixture"
        assert any("snapshot_read_failed" in error for error in response["errors"])


def test_fixture_restore_detects_stale_persistent_option_cache() -> None:
    with tempfile.TemporaryDirectory(prefix="lpm-fixture-php-test-") as tmp:
        tmp_path = Path(tmp)
        snapshot = {
            "success": True,
            "mode": "snapshot-lpm-fixture",
            "previous": {
                "option_snapshot": {
                    "fixture_option": {
                        "exists": True,
                        "option_value_b64": base64.b64encode(b"original").decode(),
                        "autoload": "no",
                    }
                }
            },
        }
        payload = base64.b64encode(json.dumps(snapshot).encode()).decode()
        source = f"""<?php
define( 'ARRAY_A', 'ARRAY_A' );
function wp_json_encode( $value, $flags = 0 ) {{ return json_encode( $value, $flags ); }}
function wp_cache_delete( $key, $group ) {{ return true; }}
function wp_cache_get( $key, $group, $force = false, &$found = null ) {{
    global $fake_cache;
    $found = array_key_exists( $key, $fake_cache );
    return $found ? $fake_cache[ $key ] : false;
}}
function maybe_serialize( $value ) {{ return is_string( $value ) ? $value : serialize( $value ); }}
$fake_cache = array( 'fixture_option' => 'mutated' );
function get_option( $name, $default = false ) {{
    global $fake_cache, $wpdb;
    if ( array_key_exists( $name, $fake_cache ) ) {{ return $fake_cache[ $name ]; }}
    return array_key_exists( $name, $wpdb->rows ) ? $wpdb->rows[ $name ]['option_value'] : $default;
}}
class FakeWpdb {{
    public $options = 'wp_options';
    public $last_error = '';
    public $rows = array( 'fixture_option' => array( 'option_value' => 'mutated', 'autoload' => 'yes' ) );
    public function prepare( $query, ...$args ) {{ return array( 'query' => $query, 'args' => $args ); }}
    public function query( $prepared ) {{
        $args = $prepared['args'];
        $this->rows[ $args[0] ] = array( 'option_value' => $args[1], 'autoload' => $args[2] );
        return 1;
    }}
    public function delete( $table, $where, $formats ) {{ unset( $this->rows[ $where['option_name'] ] ); return 1; }}
    public function get_row( $prepared, $format ) {{
        $name = $prepared['args'][0];
        return $this->rows[ $name ] ?? null;
    }}
}}
$wpdb = new FakeWpdb();
$args = array( 'restore-lpm-fixture', {json.dumps(payload)} );
require {json.dumps(str(PAYMENT_METHOD_FIXTURE_STATE))};
"""

        result = run_fixture_php(source, tmp_path)

        assert result.returncode == 0, result.stderr
        response = json.loads(result.stdout.splitlines()[-1])
        assert response["success"] is False
        assert response["option_count"] == 1
        assert response["restored_count"] == 1
        assert response["verified_count"] == 0
        assert response["mismatches"] == ["fixture_option:cache_value_mismatch"]


def test_fixture_restore_reclears_cache_after_database_verification() -> None:
    with tempfile.TemporaryDirectory(prefix="lpm-fixture-php-test-") as tmp:
        tmp_path = Path(tmp)
        snapshot = {
            "success": True,
            "mode": "snapshot-lpm-fixture",
            "previous": {
                "option_snapshot": {
                    "fixture_option": {
                        "exists": True,
                        "option_value_b64": base64.b64encode(b"original").decode(),
                        "autoload": "no",
                    }
                }
            },
        }
        payload = base64.b64encode(json.dumps(snapshot).encode()).decode()
        source = f"""<?php
define( 'ARRAY_A', 'ARRAY_A' );
function wp_json_encode( $value, $flags = 0 ) {{ return json_encode( $value, $flags ); }}
function maybe_serialize( $value ) {{ return is_string( $value ) ? $value : serialize( $value ); }}
$fake_cache = array( 'fixture_option' => 'mutated-before-restore' );
function wp_cache_delete( $key, $group ) {{
    global $fake_cache;
    if ( 'options' === $group ) {{ unset( $fake_cache[ $key ] ); }}
    return true;
}}
function wp_cache_get( $key, $group, $force = false, &$found = null ) {{
    global $fake_cache;
    $found = array_key_exists( $key, $fake_cache );
    return $found ? $fake_cache[ $key ] : false;
}}
function get_option( $name, $default = false ) {{
    global $fake_cache, $wpdb;
    if ( array_key_exists( $name, $fake_cache ) ) {{ return $fake_cache[ $name ]; }}
    return array_key_exists( $name, $wpdb->rows ) ? $wpdb->rows[ $name ]['option_value'] : $default;
}}
class FakeWpdb {{
    public $options = 'wp_options';
    public $last_error = '';
    public $rows = array( 'fixture_option' => array( 'option_value' => 'mutated', 'autoload' => 'yes' ) );
    public function prepare( $query, ...$args ) {{ return array( 'query' => $query, 'args' => $args ); }}
    public function query( $prepared ) {{
        $args = $prepared['args'];
        $this->rows[ $args[0] ] = array( 'option_value' => $args[1], 'autoload' => $args[2] );
        return 1;
    }}
    public function delete( $table, $where, $formats ) {{ unset( $this->rows[ $where['option_name'] ] ); return 1; }}
    public function get_row( $prepared, $format ) {{
        global $fake_cache;
        $name = $prepared['args'][0];
        $fake_cache[ $name ] = 'refreshed-during-database-verification';
        return $this->rows[ $name ] ?? null;
    }}
}}
$wpdb = new FakeWpdb();
$args = array( 'restore-lpm-fixture', {json.dumps(payload)} );
require {json.dumps(str(PAYMENT_METHOD_FIXTURE_STATE))};
"""

        result = run_fixture_php(source, tmp_path)

        assert result.returncode == 0, result.stderr
        response = json.loads(result.stdout.splitlines()[-1])
        assert response["success"] is True
        assert response["verified_count"] == 1
        assert response["mismatches"] == []


def test_fixture_stage_fails_when_effective_option_values_do_not_change() -> None:
    with tempfile.TemporaryDirectory(prefix="lpm-fixture-php-test-") as tmp:
        tmp_path = Path(tmp)
        payload = base64.b64encode(json.dumps(["ideal", "EUR", "NL"]).encode()).decode()
        source = f"""<?php
define( 'ARRAY_A', 'ARRAY_A' );
function wp_json_encode( $value, $flags = 0 ) {{ return json_encode( $value, $flags ); }}
function sanitize_key( $value ) {{ return strtolower( preg_replace( '/[^a-z0-9_]/i', '', $value ) ); }}
function sanitize_text_field( $value ) {{ return (string) $value; }}
$stored_options = array(
    'woocommerce_woocommerce_payments_settings' => array(
        'enabled' => 'yes',
        'test_mode' => 'no',
        'upe_enabled_payment_method_ids' => array( 'card' ),
    ),
    'wcpay_account_data' => array(
        'data' => array(
            'account_id' => 'acct_test',
            'test_publishable_key' => 'pk_test',
            'capabilities' => array( 'ideal_payments' => 'active' ),
        ),
    ),
    'woocommerce_currency' => 'USD',
    'woocommerce_default_country' => 'US:CA',
);
function get_option( $name, $default = false ) {{
    global $stored_options;
    return array_key_exists( $name, $stored_options ) ? $stored_options[ $name ] : $default;
}}
function update_option() {{ return false; }}
function delete_option() {{ return false; }}
function wp_cache_delete() {{ return true; }}
class FakeWpdb {{
    public $options = 'wp_options';
    public $last_error = '';
    public function prepare( $query, ...$args ) {{ return array( $query, $args ); }}
    public function get_row( $query, $format ) {{ return null; }}
}}
$wpdb = new FakeWpdb();
$args = array( 'stage-lpm-fixture', {json.dumps(payload)} );
require {json.dumps(str(PAYMENT_METHOD_FIXTURE_STATE))};
"""

        result = run_fixture_php(source, tmp_path)

        assert result.returncode == 0, result.stderr
        response = json.loads(result.stdout.splitlines()[-1])
        assert response["success"] is False
        assert response["mode"] == "stage-lpm-fixture"
        assert response["stage_verified"] is False
        assert response["stage_mismatches"]


def test_usage_requires_methods_ref_and_target() -> None:
    result = run_gate()

    assert result.returncode == 2
    assert "usage:" in result.stderr
    assert "--methods" in result.stderr
    assert "--ref" in result.stderr
    assert "--target" in result.stderr


def test_unknown_method_is_usage_error() -> None:
    result = run_gate(
        "--methods",
        "bogus",
        "--ref",
        REF_WP,
        "--target",
        TARGET_WP,
        "--print-plan",
    )

    assert result.returncode == 2
    assert "unsupported method: bogus" in result.stderr


def test_print_plan_reports_action_scheduler_drain_enabled_by_default() -> None:
    result = run_gate(
        "--methods",
        "ideal",
        "--ref",
        REF_WP,
        "--target",
        TARGET_WP,
        "--print-plan",
    )

    assert result.returncode == 0, result.stderr
    payload = json.loads(result.stdout)
    assert payload["action_scheduler_drain_enabled"] is True


def test_print_plan_reports_action_scheduler_drain_disabled_when_skipped() -> None:
    result = run_gate(
        "--methods",
        "ideal",
        "--ref",
        REF_WP,
        "--target",
        TARGET_WP,
        "--skip-action-scheduler-drain",
        "--print-plan",
    )

    assert result.returncode == 0, result.stderr
    payload = json.loads(result.stdout)
    assert payload["action_scheduler_drain_enabled"] is False


def test_print_plan_describes_wave_1_methods() -> None:
    result = run_gate(
        "--methods",
        "sepa_debit,ideal,bancontact,klarna,affirm,afterpay_clearpay",
        "--ref",
        REF_WP,
        "--target",
        TARGET_WP,
        "--print-plan",
    )

    assert result.returncode == 0, result.stderr
    payload = json.loads(result.stdout)

    assert payload["methods"] == [
        "sepa_debit",
        "ideal",
        "bancontact",
        "klarna",
        "affirm",
        "afterpay_clearpay",
    ]
    assert payload["fixtures"]["sepa_debit"]["currency"] == "EUR"
    assert payload["fixtures"]["sepa_debit"]["gateway_id"] == "woocommerce_payments_sepa_debit"
    assert payload["fixtures"]["ideal"]["currency"] == "EUR"
    assert payload["fixtures"]["bancontact"]["country"] == "BE"
    assert payload["fixtures"]["klarna"]["currency"] == "USD"
    assert payload["fixtures"]["klarna"]["country"] == "US"
    assert payload["fixtures"]["klarna"]["gateway_id"] == "woocommerce_payments_klarna"
    assert payload["fixtures"]["klarna"]["family"] == "hosted_action"
    assert payload["fixtures"]["klarna"]["automation_disposition"] == "manual_customer_action"
    assert payload["fixtures"]["affirm"]["currency"] == "USD"
    assert payload["fixtures"]["affirm"]["automation_disposition"] == "automated"
    assert payload["fixtures"]["afterpay_clearpay"]["country"] == "US"


def test_print_plan_describes_wave_2_methods() -> None:
    result = run_gate(
        "--methods",
        "eps,p24,multibanco,au_becs_debit,grabpay,wechat_pay,alipay",
        "--ref",
        REF_WP,
        "--target",
        TARGET_WP,
        "--print-plan",
    )

    assert result.returncode == 0, result.stderr
    payload = json.loads(result.stdout)

    assert payload["methods"] == [
        "eps",
        "p24",
        "multibanco",
        "au_becs_debit",
        "grabpay",
        "wechat_pay",
        "alipay",
    ]
    assert payload["fixtures"]["eps"]["country"] == "AT"
    assert payload["fixtures"]["p24"]["currency"] == "PLN"
    assert payload["fixtures"]["multibanco"]["family"] == "voucher"
    assert payload["fixtures"]["au_becs_debit"]["gateway_id"] == "woocommerce_payments_au_becs_debit"
    assert payload["fixtures"]["grabpay"]["currency"] == "SGD"
    assert payload["fixtures"]["wechat_pay"]["currency"] == "USD"
    assert payload["fixtures"]["wechat_pay"]["country"] == "US"
    assert payload["fixtures"]["wechat_pay"]["family"] == "customer_action"
    assert payload["fixtures"]["wechat_pay"]["stripe_payment_method_type"] == "wechat_pay"
    assert payload["fixtures"]["wechat_pay"]["automation_disposition"] == "manual_customer_action"
    assert payload["fixtures"]["alipay"]["currency"] == "USD"
    assert payload["fixtures"]["alipay"]["country"] == "US"
    assert payload["fixtures"]["alipay"]["family"] == "redirect"


def test_cross_store_normalizer_masks_non_card_provider_ids() -> None:
    normalized = normalize_bucket_record(
        {
            "order_id": "190",
            "meta": {
                "_charge_id": ["py_3TqyNKJd67Ti1EoI1ztRv86Z"],
                "_wcpay_payment_method_details": [
                    json.dumps(
                        {
                            "type": "ideal",
                            "ideal": {
                                "bank": "rabobank",
                                "transaction_id": "test_4386477049773246",
                            },
                            "affirm": {
                                "transaction_id": "TEST-D5U3",
                            },
                            "afterpay_clearpay": {
                                "order_id": "7ZQzBJS6nEsh1FSmFPKb",
                            },
                            "sepa_debit": {
                                "fingerprint": "7YMPM1mGG013Wcai",
                                "last4": "3201",
                                "mandate": "mandate_1Tr6IhJAuuJ9nlT3liugb04Z",
                            },
                        }
                    )
                ],
            },
        }
    )

    assert normalized["order_id"] == "<order>"
    assert normalized["meta"]["_charge_id"] == ["py_<id>"]
    details = json.loads(normalized["meta"]["_wcpay_payment_method_details"][0])
    assert details == {
        "affirm": {
            "transaction_id": "<volatile>",
        },
        "afterpay_clearpay": {
            "order_id": "<volatile>",
        },
        "ideal": {
            "bank": "rabobank",
            "transaction_id": "<volatile>",
        },
        "sepa_debit": {
            "fingerprint": "<volatile>",
            "last4": "3201",
            "mandate": "<volatile>",
        },
        "type": "ideal",
    }


def test_cross_store_normalizer_masks_multibanco_voucher_volatility() -> None:
    normalized = normalize_bucket_record(
        {
            "order_id": "203",
            "meta": {
                "_wcpay_multibanco_expiry": ["1784135414"],
                "_wcpay_multibanco_url": [
                    "https://payments.stripe.com/multibanco/voucher/test_YWNjdF8xVGpFTnJKQXV1SjlubFQz"
                ],
                "_wcpay_multibanco_reference": ["123456789"],
                "_wcpay_multibanco_entity": ["12345"],
            },
        }
    )

    assert normalized["meta"]["_wcpay_multibanco_expiry"] == ["<volatile>"]
    assert normalized["meta"]["_wcpay_multibanco_url"] == ["<volatile>"]
    assert normalized["meta"]["_wcpay_multibanco_reference"] == ["123456789"]
    assert normalized["meta"]["_wcpay_multibanco_entity"] == ["12345"]


def write_executable(path: Path, source: str) -> None:
    path.parent.mkdir(parents=True, exist_ok=True)
    path.write_text(source, encoding="utf-8")
    path.chmod(0o755)


def make_invocation_spy(path: Path, log_path: Path) -> None:
    write_executable(
        path,
        f"""#!/usr/bin/env python3
from pathlib import Path

with Path({str(log_path)!r}).open("a", encoding="utf-8") as stream:
    stream.write("invoked\\n")
raise SystemExit(1)
""",
    )


def make_fake_remote_context_docker(path: Path, log_path: Path) -> None:
    write_executable(
        path,
        f"""#!/usr/bin/env bash
set -eu
printf '%s\n' "$*" >> {str(log_path)!r}
if [ "$1" = "context" ] && [ "$2" = "show" ]; then
    printf 'remote-ci\n'
    exit 0
fi
if [ "$1" = "context" ] && [ "$2" = "inspect" ]; then
    printf '[{{"Name":"remote-ci","Endpoints":{{"docker":{{"Host":"tcp://remote.example.test:2376"}}}}}}]\n'
    exit 0
fi
exit 41
""",
    )


def make_fake_local_docker(
    path: Path,
    delegates: dict[str, Path],
    port_bindings: dict[str, dict[str, list[dict[str, str]]]],
) -> None:
    delegate_paths = {container: str(delegate) for container, delegate in delegates.items()}
    write_executable(
        path,
        f"""#!/usr/bin/env python3
import json
import os
import sys

delegates = {delegate_paths!r}
port_bindings = {port_bindings!r}
args = sys.argv[1:]

if args[:2] == ["context", "show"]:
    print("default")
    raise SystemExit(0)
if args[:2] == ["context", "inspect"]:
    print(json.dumps([{{"Endpoints": {{"docker": {{"Host": "unix:///fake/docker.sock"}}}}}}]))
    raise SystemExit(0)
if args[:2] == ["inspect", "--format"]:
    print(json.dumps(port_bindings.get(args[-1], {{}}), separators=(",", ":")))
    raise SystemExit(0)
if not args or args[0] != "exec":
    raise SystemExit(2)

option_flags = {{"-d", "--detach", "-i", "--interactive", "--privileged", "-t", "--tty"}}
options_with_values = {{"--detach-keys", "-e", "--env", "--env-file", "-u", "--user", "-w", "--workdir"}}
index = 1
while index < len(args) and args[index].startswith("-"):
    option = args[index]
    if option == "--":
        index += 1
        break
    if option in option_flags:
        index += 1
        continue
    if option in options_with_values:
        index += 2
        continue
    if any(option.startswith(name + "=") for name in options_with_values if name.startswith("--")):
        index += 1
        continue
    raise SystemExit(2)

container = args[index]
delegate = delegates[container]
index += 2  # Skip the container and wp executable.
os.execv(delegate, [delegate, *args[index:]])
""",
    )


def test_rejects_unsafe_wp_runners_before_print_plan_or_invocation() -> None:
    with tempfile.TemporaryDirectory(prefix="lpm-gate-test-") as tmp:
        tmp_path = Path(tmp)
        ref_spy = tmp_path / "reference" / "wp"
        target_spy = tmp_path / "target" / "wp"
        invocation_log = tmp_path / "invocations.log"
        make_invocation_spy(ref_spy, invocation_log)
        make_invocation_spy(target_spy, invocation_log)

        for unsafe_suffix in (
            "--ssh=merchant@example.test",
            "--http=https://example.test",
            "--url=example.test",
            "https://store.wordpress.com",
            "; echo unsafe",
            "$(echo unsafe)",
        ):
            for unsafe_role in ("ref", "target"):
                invocation_log.unlink(missing_ok=True)
                result = run_gate(
                    "--methods",
                    "ideal",
                    "--ref",
                    f"{ref_spy} {unsafe_suffix}" if unsafe_role == "ref" else str(ref_spy),
                    "--target",
                    f"{target_spy} {unsafe_suffix}" if unsafe_role == "target" else str(target_spy),
                    "--print-plan",
                )

                assert result.returncode == 2
                assert f"unsafe --{unsafe_role} WP runner" in result.stderr
                assert not invocation_log.exists()


def test_rejects_remote_docker_environment_before_invocation() -> None:
    with tempfile.TemporaryDirectory(prefix="lpm-gate-test-") as tmp:
        tmp_path = Path(tmp)
        ref_spy = tmp_path / "reference" / "docker"
        target_spy = tmp_path / "target" / "docker"
        invocation_log = tmp_path / "invocations.log"
        make_invocation_spy(ref_spy, invocation_log)
        make_invocation_spy(target_spy, invocation_log)

        result = run_gate(
            "--methods",
            "ideal",
            "--ref",
            f"{ref_spy} exec -i reference wp",
            "--target",
            f"{target_spy} exec -i target wp",
            "--print-plan",
            env={"DOCKER_HOST": "ssh://remote.example.test"},
        )

        assert result.returncode == 2
        assert "unsafe --ref WP runner" in result.stderr
        assert not invocation_log.exists()


def test_rejects_persisted_remote_docker_context_before_runner_invocation() -> None:
    with tempfile.TemporaryDirectory(prefix="lpm-gate-test-") as tmp:
        tmp_path = Path(tmp)
        fake_docker = tmp_path / "docker"
        invocation_log = tmp_path / "docker-invocations.log"
        make_fake_remote_context_docker(fake_docker, invocation_log)

        result = run_gate(
            "--methods",
            "ideal",
            "--ref",
            f"{fake_docker} exec -i reference wp",
            "--target",
            f"{fake_docker} exec -i target wp",
            "--print-plan",
        )

        assert result.returncode == 2
        assert "unsafe --ref WP runner" in result.stderr
        invocations = invocation_log.read_text(encoding="utf-8").splitlines()
        assert "context show" in invocations
        assert any(line.startswith("context inspect") for line in invocations)
        assert not any(line.startswith("exec ") for line in invocations)


def test_rejects_opaque_package_manager_wp_runner_before_invocation() -> None:
    with tempfile.TemporaryDirectory(prefix="lpm-gate-test-") as tmp:
        tmp_path = Path(tmp)
        package_spy = tmp_path / "pnpm"
        target_spy = tmp_path / "target" / "wp"
        invocation_log = tmp_path / "invocations.log"
        make_invocation_spy(package_spy, invocation_log)
        make_invocation_spy(target_spy, invocation_log)

        result = run_gate(
            "--methods",
            "ideal",
            "--ref",
            f"{package_spy} wp",
            "--target",
            str(target_spy),
            "--print-plan",
        )

        assert result.returncode == 2
        assert "unsafe --ref WP runner" in result.stderr
        assert not invocation_log.exists()


def test_rejects_package_manager_wp_runner_nested_in_docker_exec() -> None:
    with tempfile.TemporaryDirectory(prefix="lpm-gate-test-") as tmp:
        tmp_path = Path(tmp)
        docker_spy = tmp_path / "docker"
        target_spy = tmp_path / "target" / "wp"
        invocation_log = tmp_path / "invocations.log"
        make_invocation_spy(docker_spy, invocation_log)
        make_invocation_spy(target_spy, invocation_log)

        result = run_gate(
            "--methods",
            "ideal",
            "--ref",
            f"{docker_spy} exec -i reference pnpm wp",
            "--target",
            str(target_spy),
            "--print-plan",
        )

        assert result.returncode == 2
        assert "unsafe --ref WP runner" in result.stderr
        assert not invocation_log.exists()


def make_fake_wp(
    path: Path,
    home_url: str,
    *,
    checkout_page_id: int = 7,
    classic_checkout_page_id: int = 90,
    product_id: int = 1001,
    stageable: bool = True,
    blocked_stage_methods: tuple[str, ...] = (),
    pretty_account_profile_json: bool = False,
    account_profile_ready: bool = False,
    enrich_order_evidence: bool = False,
    enriched_order_payment_method: str | None = None,
    enriched_order_status: str = "processing",
    enriched_intention_status: str = "succeeded",
    provider_available: bool = True,
    provider_payment_method_type: str | None = None,
    provider_intent_id: str | None = None,
    provider_observation_request_id: str | None = None,
    provider_runtime_adapter: str | None = None,
    provider_test_mode: bool = True,
    provider_blocker_code: str = "provider_transport_unavailable",
    provider_http_code: int = 0,
    restore_succeeds: bool = True,
    restore_failures_before_success: int = 0,
    product_cleanup_succeeds: bool = True,
    product_prepare_fails_after_create: bool = False,
) -> None:
    if provider_runtime_adapter is None:
        provider_runtime_adapter = (
            "legacy_woopayments_api_client"
            if home_url == REF_URL
            else "native_woocommerce_api_client"
        )
    stageable_literal = "1" if stageable else "0"
    enrich_order_evidence_literal = "1" if enrich_order_evidence else "0"
    enriched_order_payment_method_expr = (
        json.dumps(enriched_order_payment_method)
        if enriched_order_payment_method is not None
        else '"${LPM_GATE_GATEWAY_ID:-woocommerce_payments_ideal}"'
    )
    blocked_stage_methods_literal = json.dumps(list(blocked_stage_methods))
    account_profile_json_indent = "2" if pretty_account_profile_json else "None"
    account_profile_ready_literal = "True" if account_profile_ready else "False"
    provider_available_literal = "True" if provider_available else "False"
    provider_payment_method_type_literal = repr(provider_payment_method_type)
    provider_intent_id_literal = repr(provider_intent_id)
    provider_observation_request_id_literal = repr(provider_observation_request_id)
    provider_test_mode_literal = "True" if provider_test_mode else "False"
    provider_http_code_literal = str(provider_http_code)
    provider_availability = (
        "invalid"
        if not provider_available
        and 400 <= provider_http_code < 500
        and provider_http_code not in {401, 403, 408, 429}
        else "blocked"
    )
    provider_effective_blocker_code = (
        "provider_identity_invalid"
        if provider_availability == "invalid"
        else provider_blocker_code
    )
    restore_succeeds_literal = "true" if restore_succeeds else "false"
    restore_failures_before_success_literal = str(restore_failures_before_success)
    product_cleanup_succeeds_literal = "True" if product_cleanup_succeeds else "False"
    product_prepare_fails_after_create_literal = "1" if product_prepare_fails_after_create else "0"
    provider_api_client_class = (
        "WC_Payments_API_Client"
        if provider_runtime_adapter == "legacy_woopayments_api_client"
        else "Automattic\\WooCommerce\\Internal\\Payments\\Providers\\WooPayments\\Api\\WooPaymentsApiClient"
    )
    write_executable(
        path,
        f"""#!/usr/bin/env bash
set -euo pipefail
if [ -n "${{FAKE_WP_INVOCATIONS:-}}" ]; then
\tprintf '%s\\n' "$*" >> "$FAKE_WP_INVOCATIONS"
fi
stageable={stageable_literal}
enrich_order_evidence={enrich_order_evidence_literal}
state_file="${{FAKE_WP_STATE:-$0.state.json}}"
if [ "$1" = "option" ] && [ "$2" = "get" ] && [ "$3" = "home" ]; then
\tprintf '%s\\n' {json.dumps(home_url)}
\texit 0
fi
if [ "$1" = "option" ] && [ "$2" = "get" ] && [ "$3" = "woocommerce_checkout_page_id" ]; then
\tprintf '%s\\n' {checkout_page_id}
\texit 0
fi
if [ "$1" = "eval-file" ] && [ "$2" = "-" ] && [ "${{3:-}}" = "prepare-lpm-product" ]; then
\tcat >/dev/null
\tpython3 - "${{4:-}}" <<'PY'
import base64
import json
import sys

payload = json.loads(base64.b64decode(sys.argv[1]).decode("utf-8"))
if {product_prepare_fails_after_create_literal}:
    raise SystemExit(41)
print(json.dumps({{"success": True, "mode": "prepare-lpm-product", "role": payload["role"], "product_id": {product_id}, "sku": payload["sku"], "price": "45.00", "virtual": True}}))
PY
\texit 0
fi
if [ "$1" = "eval-file" ] && [ "$2" = "-" ] && [ "${{3:-}}" = "cleanup-lpm-product" ]; then
\tcat >/dev/null
\tpython3 - "${{4:-}}" <<'PY'
import base64
import json
import sys

payload = json.loads(base64.b64decode(sys.argv[1]).decode("utf-8"))
print(json.dumps({{"success": {product_cleanup_succeeds_literal}, "mode": "cleanup-lpm-product", "cleaned": {product_cleanup_succeeds_literal}, "product_id": {product_id}, "role": payload["role"], "sku": payload["sku"]}}))
PY
\texit 0
fi
if [ "$1" = "wc" ] && [ "$2" = "product" ] && [ "$3" = "list" ]; then
\tprintf '%s\\n' {product_id}
\texit 0
fi
if [ "$1" = "post" ] && [ "$2" = "list" ]; then
\tprintf '[{{"ID":{classic_checkout_page_id},"post_title":"Codex Classic Checkout","post_name":"codex-classic-checkout"}},{{"ID":{checkout_page_id},"post_title":"Checkout","post_name":"checkout"}}]\\n'
\texit 0
fi
if [ "$1" = "post" ] && [ "$2" = "list-legacy-products" ]; then
\tprintf '11\\n'
\texit 0
fi
if [ "$1" = "wcpay-dev" ] && [ "$2" = "test-lab" ] && [ "$3" = "account" ] && [ "$4" = "profile" ]; then
\tprofile="unknown"
\tfor arg in "$@"; do
\t\tcase "$arg" in
\t\t\t--profile=*) profile="${{arg#--profile=}}" ;;
\t\tesac
\tdone
\tpython3 - "$profile" <<'PY'
import json
import sys

profile = sys.argv[1]
ready = {account_profile_ready_literal}
profile_country = {{
    "p24": "PL",
    "au_becs_debit": "AU",
    "grabpay": "SG",
}}.get(profile, "")
provisionable = profile == "p24"
capability_status = "active" if ready else "rejected"
print(
    json.dumps(
        {{
            "success": True,
            "profile": {{
                "id": profile,
                "country": profile_country,
                "test_lab_provisionable": provisionable,
            }},
            "ready": ready,
            "status": "ready" if ready else "blocked",
            "message": None if ready else f"fake profile blocker for {{profile}}",
            "checks": {{
                f"{{profile}}_payments": {{
                    "status": capability_status,
                    "capability_status": capability_status,
                }}
            }},
        }},
        indent={account_profile_json_indent},
        sort_keys=True,
    )
)
PY
\texit 0
fi
if [ "$1" = "eval-file" ] && [ "$2" = "-" ] && [ "${{3:-}}" = "clear-lpm-cart-sessions" ]; then
\tcat >/dev/null
\tpython3 - "${{FAKE_WP_INVOCATIONS:-}}" <<'PY'
import json
import sys
from pathlib import Path

invocations_path = Path(sys.argv[1]) if len(sys.argv) > 1 and sys.argv[1] else None
if invocations_path:
    with invocations_path.open("a", encoding="utf-8") as stream:
        stream.write("clear-lpm-cart-sessions\\n")
print(json.dumps({{"success": True, "mode": "clear-lpm-cart-sessions", "deleted_sessions": 0, "deleted_transients": 0}}))
PY
\texit 0
fi
if [ "$stageable" = "1" ] && [ "$1" = "eval-file" ] && [ "$2" = "-" ] && [ "${{3:-}}" = "stage-lpm-fixture" ]; then
\tcat >/dev/null
\tpython3 - "$state_file" "${{4:-}}" "${{FAKE_WP_INVOCATIONS:-}}" <<'PY'
import base64
import json
import sys
from pathlib import Path

state_path = Path(sys.argv[1])
method, currency, country = json.loads(base64.b64decode(sys.argv[2]).decode("utf-8"))
invocations_path = Path(sys.argv[3]) if len(sys.argv) > 3 and sys.argv[3] else None
blocked_stage_methods = set(json.loads({json.dumps(blocked_stage_methods_literal)}))
if method in blocked_stage_methods:
    if invocations_path:
        with invocations_path.open("a", encoding="utf-8") as stream:
            stream.write(f"stage-lpm-fixture blocked {{method}} {{currency}} {{country}}\\n")
    print(json.dumps({{"success": False, "mode": "stage-lpm-fixture", "errors": [f"blocked fixture for {{method}}"], "method": method}}))
    sys.exit(0)
state_path.write_text(
    json.dumps(
        {{
            "settings": {{"upe_enabled_payment_method_ids": ["card", method]}},
            "currency": currency,
            "country": country,
        }},
        sort_keys=True,
    )
    + "\\n",
    encoding="utf-8",
)
if invocations_path:
    with invocations_path.open("a", encoding="utf-8") as stream:
        stream.write(f"stage-lpm-fixture decoded {{method}} {{currency}} {{country}}\\n")
print(json.dumps({{"success": True, "mode": "stage-lpm-fixture", "stage_verified": True, "stage_option_count": 18, "stage_verified_count": 18, "stage_mismatches": [], "previous": {{"settings": {{"upe_enabled_payment_method_ids": ["card"]}}, "currency": "USD", "country": "US:CA"}}}}))
PY
\texit 0
fi
if [ "$stageable" = "1" ] && [ "$1" = "eval-file" ] && [ "$2" = "-" ] && [ "${{3:-}}" = "snapshot-lpm-fixture" ]; then
\tcat >/dev/null
\tprintf '{{"success":true,"mode":"snapshot-lpm-fixture","previous":{{"settings":{{"upe_enabled_payment_method_ids":["card"]}},"currency":"USD","country":"US:CA"}}}}\\n'
\texit 0
fi
if [ "$stageable" = "1" ] && [ "$1" = "eval-file" ] && [ "$2" = "-" ] && [ "${{3:-}}" = "restore-lpm-fixture" ]; then
\tcat >/dev/null
\tprintf '{{"settings":{{"upe_enabled_payment_method_ids":["card"]}},"currency":"USD","country":"US:CA"}}\\n' > "$state_file"
\tpython3 - "$0.restore-count" <<'PY'
from pathlib import Path
import sys

path = Path(sys.argv[1])
count = int(path.read_text(encoding="utf-8")) if path.exists() else 0
count += 1
path.write_text(str(count), encoding="utf-8")
success = {str(restore_succeeds)} and count > {restore_failures_before_success_literal}
print('{{"success":' + ('true' if success else 'false') + ',"mode":"restore-lpm-fixture","errors":[]}}')
PY
\texit 0
fi
if [ "$enrich_order_evidence" = "1" ] && [ "$1" = "eval-file" ] && [ "$2" = "-" ] && [ "${{3:-}}" = "enrich-order-evidence" ]; then
\tcat >/dev/null
\tprintf '{{"success":true,"mode":"enrich-order-evidence","order_id":1001,"order_status":{json.dumps(enriched_order_status)},"order_payment_method":"%s","payment_intent_id":"pi_wp_1001","payment_method_id":"pm_wp_1001","intention_status":{json.dumps(enriched_intention_status)},"transaction_id":"pi_wp_1001","resolved_by":"payment_intent_id"}}\\n' {enriched_order_payment_method_expr}
\texit 0
fi
if [ "$1" = "eval-file" ] && [ "$2" = "-" ] && [ "${{3:-}}" = "observe-provider-payment-method" ]; then
\tcat >/dev/null
\tpython3 - "${{4:-}}" <<'PY'
import base64
import json
import sys

request = json.loads(base64.b64decode(sys.argv[1]).decode("utf-8"))
available = {provider_available_literal}
requested_intent_id = str(request["payment_intent_id"])
request_id = str(request["observation_request_id"])
provider_intent_id = {provider_intent_id_literal} or requested_intent_id
response_request_id = {provider_observation_request_id_literal} or request_id
observed_type = {provider_payment_method_type_literal} or str(
    request["expected_payment_method_type"]
)
print(
    json.dumps(
        {{
            "success": available,
            "mode": "observe-provider-payment-method",
            "availability": "available" if available else {json.dumps(provider_availability)},
            "blocker_code": "" if available else {json.dumps(provider_effective_blocker_code)},
            "observation_request_id": response_request_id,
            "requested_payment_intent_id": requested_intent_id,
            "provider_payment_intent_id": provider_intent_id,
            "provider_charge_payment_intent_id": provider_intent_id,
            "observed_payment_method_type": observed_type if available else "",
            "charge_id": "ch_provider_1001" if available else "",
            "payment_method_id": "pm_provider_1001" if available else "",
            "adapter": {json.dumps(provider_runtime_adapter)},
            "runtime_owner": {json.dumps("plugin" if provider_runtime_adapter == "legacy_woopayments_api_client" else "native")},
            "api_client_class": {json.dumps(provider_api_client_class)},
            "source": "intent.charge.payment_method_details.type",
            "transport_available": available,
            "account_id": "acct_provider_test" if available else "",
            "test_mode": {provider_test_mode_literal},
            "provider_http_code": {provider_http_code_literal},
            "message": "" if available else "fake provider transport unavailable",
        }},
        sort_keys=True,
    )
)
PY
\texit 0
fi
if [ "$1" = "eval" ]; then
\tprintf '%s\\n' {product_id}
\texit 0
fi
printf 'unexpected fake wp args: %s\\n' "$*" >&2
exit 1
""",
    )


def make_fake_parity_diff(path: Path, invocations_path: Path) -> None:
    write_executable(
        path,
        f"""#!/usr/bin/env python3
import json
import pathlib
import sys

pathlib.Path({json.dumps(str(invocations_path))}).write_text(
    json.dumps({{"argv": sys.argv[1:]}}, sort_keys=True) + "\\n",
    encoding="utf-8",
)
print("PASS: fake Bucket-E parity diff")
""",
    )


def make_fake_playwriter(
    path: Path,
    *,
    omit_order_id: bool = False,
    omit_semantic_fields: bool = False,
    use_base_card_gateway: bool = False,
    query_order_received_url: bool = False,
    omit_order_received_url: bool = False,
    omit_payment_intent_id: bool = False,
    payment_intent_id: str = "pi_wp_1001",
    status: str = "pass",
    failure_messages: tuple[str, ...] = (),
    failed_responses: tuple[dict[str, object], ...] = (),
    fatal_console_errors: tuple[dict[str, object], ...] = (),
    exit_code: int = 0,
    provider_observation: dict | None = None,
    omit_target_multibanco_voucher: bool = False,
    mismatch_target_multibanco_voucher: bool = False,
) -> None:
    order_id_expr = "None" if omit_order_id else "1001"
    selected_gateway_expr = "None" if omit_semantic_fields else 'os.environ["LPM_GATE_GATEWAY_ID"]'
    order_payment_method_expr = (
        '"woocommerce_payments"' if use_base_card_gateway else selected_gateway_expr
    )
    order_received_url_expr = (
        '""'
        if omit_semantic_fields or omit_order_received_url
        else (
            'os.environ["LPM_GATE_BASE_URL"] + "/?page_id=7&order-received=1001&key=wc_order_unit"'
            if query_order_received_url
            else 'os.environ["LPM_GATE_BASE_URL"] + "/checkout/order-received/1001/"'
        )
    )
    payment_intent_expr = (
        '""'
        if omit_semantic_fields or omit_payment_intent_id
        else json.dumps(payment_intent_id)
    )
    failures_expr = json.dumps(list(failure_messages))
    failed_responses_expr = json.dumps(list(failed_responses))
    fatal_console_errors_expr = json.dumps(list(fatal_console_errors))
    provider_observation_expr = repr(provider_observation)
    write_executable(
        path,
        f"""#!/usr/bin/env python3
import json
import os
import pathlib
import sys

invocation_path = pathlib.Path(os.environ["FAKE_PLAYWRITER_INVOCATIONS"])
with invocation_path.open("a", encoding="utf-8") as stream:
    stream.write(json.dumps({{
        "argv": sys.argv[1:],
        "env": {{
            "role": os.environ.get("LPM_GATE_ROLE", ""),
            "method": os.environ.get("LPM_GATE_METHOD", ""),
            "surface": os.environ.get("LPM_GATE_SURFACE", ""),
            "base_url": os.environ.get("LPM_GATE_BASE_URL", ""),
            "gateway_id": os.environ.get("LPM_GATE_GATEWAY_ID", ""),
            "stripe_payment_method_type": os.environ.get("LPM_GATE_STRIPE_PAYMENT_METHOD_TYPE", ""),
            "automation_disposition": os.environ.get("LPM_GATE_AUTOMATION_DISPOSITION", ""),
            "product_id": os.environ.get("LPM_GATE_PRODUCT_ID", ""),
            "checkout_page_id": os.environ.get("LPM_GATE_CHECKOUT_PAGE_ID", ""),
            "evidence_path": os.environ.get("LPM_GATE_EVIDENCE_PATH", ""),
        }},
    }}, sort_keys=True) + "\\n")

if "-e" in sys.argv:
    sys.exit(0)

payload = {{
    "schema": "woopayments_lpm_checkout_browser_evidence.v1",
    "status": {json.dumps(status)},
    "role": os.environ["LPM_GATE_ROLE"],
    "method": os.environ["LPM_GATE_METHOD"],
    "surface": os.environ["LPM_GATE_SURFACE"],
    "base_url": os.environ["LPM_GATE_BASE_URL"],
    "gateway_id": os.environ["LPM_GATE_GATEWAY_ID"],
    "stripe_payment_method_type": os.environ["LPM_GATE_STRIPE_PAYMENT_METHOD_TYPE"],
    "method_family": os.environ["LPM_GATE_METHOD_FAMILY"],
    "automation_disposition": os.environ["LPM_GATE_AUTOMATION_DISPOSITION"],
    "order_id": {order_id_expr},
    "selected_gateway_id": {selected_gateway_expr},
    "order_payment_method": {order_payment_method_expr},
    "order_received_url": {order_received_url_expr},
    "payment_intent_id": {payment_intent_expr},
    "used_base_card_gateway": {str(use_base_card_gateway)},
    "failures": {failures_expr},
    "page": {{
        "failed_responses": {failed_responses_expr},
        "fatal_console_errors": {fatal_console_errors_expr},
        "checkout_requests": [{{
            "method": "POST",
            "url": os.environ["LPM_GATE_BASE_URL"] + "/?wc-ajax=checkout",
            "payment_method": os.environ["LPM_GATE_GATEWAY_ID"],
            "field_count": 35,
            "payment_method_error_code": "",
            "payment_method_error_message": "",
            "credentials": {{
                "wcpay-payment-method": {{
                    "present": True,
                    "credential_prefix": "pm_",
                    "length": 27,
                }},
            }},
        }}],
        "checkout_responses": [{{
            "status": 200,
            "result": "success",
            "order_id": 1001,
            "url": os.environ["LPM_GATE_BASE_URL"] + "/?wc-ajax=checkout",
        }}],
    }},
}}
stale_provider_observation = {provider_observation_expr}
if stale_provider_observation is not None:
    payload["provider_observation"] = stale_provider_observation
if os.environ["LPM_GATE_METHOD"] == "multibanco" and not (
    {omit_target_multibanco_voucher!r}
    and os.environ["LPM_GATE_ROLE"] == "target"
):
    payload["multibanco_voucher"] = {{
        "rendered": True,
        "visible": True,
        "entity": "12345",
        "reference": "123 456 789",
        "amount": "EUR 50.00",
        "share_link_present": True,
    }}
    if (
        {mismatch_target_multibanco_voucher!r}
        and os.environ["LPM_GATE_ROLE"] == "target"
    ):
        payload["multibanco_voucher"]["reference"] = "987 654 321"

evidence_path = pathlib.Path(os.environ["LPM_GATE_EVIDENCE_PATH"])
evidence_path.parent.mkdir(parents=True, exist_ok=True)
evidence_path.write_text(json.dumps(payload, sort_keys=True) + "\\n", encoding="utf-8")
sys.exit({exit_code})
""",
    )


def manual_completion_candidate(
    *,
    role: str = "reference",
    method: str = "klarna",
    failed_responses: list[dict[str, object]] | None = None,
    observed_payment_method_type: str | None = None,
) -> dict[str, object]:
    method_contracts = {
        "klarna": (
            "http://localhost:8082" if role == "reference" else TARGET_URL,
            "woocommerce_payments_klarna",
            "klarna",
            "hosted_action",
        ),
        "wechat_pay": (
            "http://localhost:8082" if role == "reference" else TARGET_URL,
            "woocommerce_payments_wechat_pay",
            "wechat_pay",
            "customer_action",
        ),
    }
    base_url, gateway_id, stripe_type, method_family = method_contracts[method]
    intent_id = "pi_manual_1001"
    browser_limitation = "checkout did not reach an order-received URL with an order id"
    runtime_owner = "plugin" if role == "reference" else "native"
    adapter = (
        "legacy_woopayments_api_client"
        if role == "reference"
        else "native_woocommerce_api_client"
    )

    return {
        "schema": "woopayments_lpm_checkout_browser_evidence.v1",
        "status": "fail",
        "role": role,
        "method": method,
        "surface": "classic",
        "base_url": base_url,
        "gateway_id": gateway_id,
        "stripe_payment_method_type": stripe_type,
        "method_family": method_family,
        "automation_disposition": "manual_customer_action",
        "order_id": 1001,
        "selected_gateway_id": gateway_id,
        "order_payment_method": gateway_id,
        "order_received_url": None,
        "payment_intent_id": intent_id,
        "used_base_card_gateway": False,
        "failures": [
            browser_limitation,
            f"LPM checkout failed for {role}/{method}: {browser_limitation}",
        ],
        "error": {
            "message": f"LPM checkout failed for {role}/{method}: {browser_limitation}",
        },
        "page": {
            "failed_responses": failed_responses or [],
            "fatal_console_errors": [],
            "checkout_requests": [
                {
                    "method": "POST",
                    "url": f"{base_url}/?wc-ajax=checkout",
                    "payment_method": gateway_id,
                    "field_count": 35,
                    "payment_method_error_code": "",
                    "payment_method_error_message": "",
                    "credentials": {
                        "wcpay-payment-method": {
                            "present": True,
                            "credential_prefix": "pm_",
                            "length": 27,
                        }
                    },
                }
            ],
            "checkout_responses": [
                {
                    "status": 200,
                    "result": "success",
                    "order_id": 1001,
                    "url": f"{base_url}/?wc-ajax=checkout",
                }
            ],
        },
        "order_enrichment": {
            "order_status": "pending",
            "order_payment_method": gateway_id,
            "payment_intent_id": intent_id,
            "payment_method_id": "pm_manual_1001",
            "intention_status": "requires_action",
            "transaction_id": intent_id,
            "resolved_by": "payment_intent_id",
        },
        "provider_observation": {
            "schema": "woopayments_lpm_provider_observation.v1",
            "status": "pass",
            "role": role,
            "method": method,
            "order_id": 1001,
            "payment_intent_id": intent_id,
            "expected_payment_method_type": stripe_type,
            "observation_request_id": "lpm-provider-unit",
            "provider_payment_intent_id": intent_id,
            "provider_charge_payment_intent_id": intent_id,
            "observed_payment_method_type": observed_payment_method_type or stripe_type,
            "fetched": True,
            "matches_expected": (observed_payment_method_type or stripe_type) == stripe_type,
            "transport_available": True,
            "test_mode": True,
            "adapter": adapter,
            "runtime_owner": runtime_owner,
            "api_client_class": "FakeWooPaymentsApiClient",
            "account_id": "acct_test",
            "source": "intent.payment_method",
            "blocker_code": "",
            "errors": [],
            "message": "",
        },
    }


def run_lpm_evidence(
    operation: str,
    evidence_path: Path,
    *,
    role: str = "reference",
    method: str = "klarna",
) -> subprocess.CompletedProcess[str]:
    contracts = {
        "klarna": ("woocommerce_payments_klarna", "klarna", "hosted_action"),
        "wechat_pay": (
            "woocommerce_payments_wechat_pay",
            "wechat_pay",
            "customer_action",
        ),
    }
    gateway_id, stripe_type, method_family = contracts[method]
    base_url = REF_URL if role == "reference" else TARGET_URL
    return subprocess.run(
        [
            "python3",
            str(LPM_EVIDENCE),
            operation,
            "--evidence",
            str(evidence_path),
            "--role",
            role,
            "--method",
            method,
            "--base-url",
            base_url,
            "--gateway-id",
            gateway_id,
            "--stripe-type",
            stripe_type,
            "--method-family",
            method_family,
            "--automation-disposition",
            "manual_customer_action",
        ],
        cwd=REPO,
        text=True,
        stdout=subprocess.PIPE,
        stderr=subprocess.PIPE,
        check=False,
    )


def test_manual_evidence_classifier_blocks_only_complete_provider_backed_candidate() -> None:
    with tempfile.TemporaryDirectory(prefix="lpm-manual-evidence-test-") as tmp:
        evidence_path = Path(tmp) / "reference-klarna-classic.json"
        evidence_path.write_text(
            json.dumps(manual_completion_candidate()) + "\n", encoding="utf-8"
        )

        result = run_lpm_evidence("classify-manual", evidence_path)

        assert result.returncode == 3, result.stdout + result.stderr
        classification = json.loads(result.stdout)
        assert classification["status"] == "blocked"
        assert classification["blocker_code"] == "manual_payment_authorization_required"
        evidence = json.loads(evidence_path.read_text(encoding="utf-8"))
        assert evidence["status"] == "blocked"
        assert evidence["failures"] == []
        assert evidence["manual_limitations"] == [
            "checkout did not reach an order-received URL with an order id",
            "LPM checkout failed for reference/klarna: checkout did not reach an order-received URL with an order id",
        ]
        assert evidence["manual_completion"]["provider_identity_proven"] is True
        assert evidence["manual_completion"]["checkout_request_proven"] is True
        assert evidence["manual_completion"]["no_failed_http_responses"] is True
        assert "error" not in evidence

        validation = run_lpm_evidence("validate-manual", evidence_path)
        assert validation.returncode == 0, validation.stdout + validation.stderr


def test_manual_evidence_classifier_allows_unavailable_success_response_body() -> None:
    with tempfile.TemporaryDirectory(prefix="lpm-manual-response-race-") as tmp:
        evidence_path = Path(tmp) / "target-klarna-classic.json"
        payload = manual_completion_candidate(role="target")
        payload["page"]["checkout_responses"] = []
        evidence_path.write_text(json.dumps(payload) + "\n", encoding="utf-8")

        result = run_lpm_evidence(
            "classify-manual", evidence_path, role="target", method="klarna"
        )

        assert result.returncode == 3, result.stdout + result.stderr
        evidence = json.loads(evidence_path.read_text(encoding="utf-8"))
        assert evidence["manual_completion"]["checkout_request_proven"] is True
        assert evidence["manual_completion"]["checkout_response_proven"] is False


def test_manual_evidence_classifier_rejects_http_and_provider_defects() -> None:
    mutations = (
        {
            "failed_responses": [
                {
                    "status": 500,
                    "url": f"{REF_URL}/?wc-ajax=checkout",
                    "body_sample": "Internal Server Error",
                }
            ]
        },
        {"observed_payment_method_type": "card"},
    )

    for index, mutation in enumerate(mutations):
        with tempfile.TemporaryDirectory(prefix=f"lpm-manual-reject-{index}-") as tmp:
            evidence_path = Path(tmp) / "reference-klarna-classic.json"
            evidence_path.write_text(
                json.dumps(manual_completion_candidate(**mutation)) + "\n",
                encoding="utf-8",
            )

            result = run_lpm_evidence("classify-manual", evidence_path)

            assert result.returncode == 1
            classification = json.loads(result.stdout)
            assert classification["status"] == "fail"
            assert classification["errors"]
            evidence = json.loads(evidence_path.read_text(encoding="utf-8"))
            assert evidence["status"] == "fail"
            assert "manual_completion" not in evidence


def test_manual_evidence_pair_requires_symmetric_blockers() -> None:
    with tempfile.TemporaryDirectory(prefix="lpm-manual-pair-test-") as tmp:
        tmp_path = Path(tmp)
        reference_path = tmp_path / "reference-klarna-classic.json"
        target_path = tmp_path / "target-klarna-classic.json"
        reference_path.write_text(
            json.dumps(manual_completion_candidate()) + "\n", encoding="utf-8"
        )
        target_payload = manual_completion_candidate(role="target")
        target_payload["status"] = "pass"
        target_payload["failures"] = []
        target_payload["order_received_url"] = f"{TARGET_URL}/checkout/order-received/1001/"
        target_path.write_text(json.dumps(target_payload) + "\n", encoding="utf-8")
        classified = run_lpm_evidence("classify-manual", reference_path)
        assert classified.returncode == 3

        result = subprocess.run(
            [
                "python3",
                str(LPM_EVIDENCE),
                "validate-pair",
                "--reference-evidence",
                str(reference_path),
                "--target-evidence",
                str(target_path),
                "--method",
                "klarna",
                "--automation-disposition",
                "manual_customer_action",
            ],
            cwd=REPO,
            text=True,
            stdout=subprocess.PIPE,
            stderr=subprocess.PIPE,
            check=False,
        )

        assert result.returncode == 1
        assert "symmetric" in result.stdout


def run_lpm_docker_alias_gate(
    tmp_path: Path, published_host_port: str
) -> tuple[subprocess.CompletedProcess[str], Path]:
    ref_wp = tmp_path / "reference" / "wp"
    target_wp = tmp_path / "target" / "wp"
    fake_docker = tmp_path / "docker"
    fake_playwriter = tmp_path / "fake-playwriter"
    fake_parity = tmp_path / "fake-parity-diff"
    playwriter_invocations = tmp_path / "playwriter-invocations.jsonl"
    out_dir = tmp_path / "evidence"

    make_fake_wp(
        ref_wp,
        "http://localhost",
        enrich_order_evidence=True,
        provider_runtime_adapter="legacy_woopayments_api_client",
    )
    make_fake_wp(target_wp, TARGET_URL, enrich_order_evidence=True)
    make_fake_local_docker(
        fake_docker,
        {"reference": ref_wp, "target": target_wp},
        {
            "reference": {
                "80/tcp": [
                    {"HostIp": "0.0.0.0", "HostPort": published_host_port}
                ]
            }
        },
    )
    make_fake_playwriter(fake_playwriter)
    make_fake_parity_diff(fake_parity, tmp_path / "parity-invocations.jsonl")

    result = run_gate(
        "--methods",
        "ideal",
        "--ref",
        f"{fake_docker} exec -i reference wp",
        "--target",
        f"{fake_docker} exec -i target wp",
        "--ref-url",
        REF_URL,
        "--target-url",
        TARGET_URL,
        "--playwriter-session",
        "unit",
        "--out-dir",
        str(out_dir),
        env={
            "DOCKER_CONTEXT": "",
            "DOCKER_HOST": "",
            "PLAYWRITER_BIN": str(fake_playwriter),
            "FAKE_PLAYWRITER_INVOCATIONS": str(playwriter_invocations),
            "LPM_PARITY_DIFF_BIN": str(fake_parity),
        },
    )
    return result, playwriter_invocations


def test_gate_accepts_exact_docker_published_url_alias() -> None:
    with tempfile.TemporaryDirectory(prefix="lpm-gate-test-") as tmp:
        result, playwriter_invocations = run_lpm_docker_alias_gate(
            Path(tmp), "8082"
        )

        assert result.returncode == 0, result.stderr
        assert playwriter_invocations.exists()


def test_gate_rejects_unpublished_docker_url_alias() -> None:
    with tempfile.TemporaryDirectory(prefix="lpm-gate-test-") as tmp:
        result, playwriter_invocations = run_lpm_docker_alias_gate(
            Path(tmp), "8081"
        )

        assert result.returncode == 3
        assert "reference WP runner home URL does not match" in result.stderr
        assert not playwriter_invocations.exists()


def test_preflight_rejects_missing_playwright_runner() -> None:
    result = run_gate(
        "--methods",
        "ideal",
        "--ref",
        REF_WP,
        "--target",
        TARGET_WP,
        "--browser-runner",
        "playwright",
        "--preflight-only",
        env={"PLAYWRIGHT_SCRIPT_RUNNER_BIN": "/missing/playwright-script-runner.mjs"},
    )

    assert result.returncode == 3
    assert "Playwright script runner is missing or not executable" in result.stderr


def test_preflight_rejects_missing_explicit_playwriter_launcher() -> None:
    result = run_gate(
        "--methods",
        "ideal",
        "--ref",
        REF_WP,
        "--target",
        TARGET_WP,
        "--playwriter-session",
        "unit",
        "--preflight-only",
        env={"PLAYWRITER_BIN": "/missing/playwriter"},
    )

    assert result.returncode == 3
    assert "Playwriter launcher is missing or not executable" in result.stderr


def test_gate_rejects_reused_output_directory_before_store_invocation() -> None:
    with tempfile.TemporaryDirectory(prefix="lpm-gate-test-") as tmp:
        tmp_path = Path(tmp)
        ref_wp = tmp_path / "reference" / "wp"
        target_wp = tmp_path / "target" / "wp"
        fake_playwriter = tmp_path / "fake-playwriter"
        fake_parity = tmp_path / "fake-parity-diff"
        invocations_path = tmp_path / "wp-invocations.log"
        playwriter_invocations = tmp_path / "playwriter-invocations.jsonl"
        parity_invocations = tmp_path / "parity-invocations.jsonl"
        out_dir = tmp_path / "evidence"
        snapshot_dir = out_dir / "lpm-fixtures"
        snapshot_dir.mkdir(parents=True)
        (snapshot_dir / "reference-ideal-snapshot.json").write_text(
            '{"success":true,"mode":"snapshot-lpm-fixture"}\n', encoding="utf-8"
        )

        make_fake_wp(ref_wp, REF_URL, enrich_order_evidence=True)
        make_fake_wp(target_wp, TARGET_URL, enrich_order_evidence=True)
        make_fake_playwriter(fake_playwriter, payment_intent_id="pi_unit_ideal")
        make_fake_parity_diff(fake_parity, parity_invocations)

        result = run_gate(
            "--methods",
            "ideal",
            "--ref",
            str(ref_wp),
            "--target",
            str(target_wp),
            "--playwriter-session",
            "unit",
            "--out-dir",
            str(out_dir),
            env={
                "PLAYWRITER_BIN": str(fake_playwriter),
                "FAKE_PLAYWRITER_INVOCATIONS": str(playwriter_invocations),
                "FAKE_WP_INVOCATIONS": str(invocations_path),
                "LPM_PARITY_DIFF_BIN": str(fake_parity),
            },
        )

        assert result.returncode == 3
        assert "fresh empty --out-dir" in result.stderr
        assert not invocations_path.exists()
        assert not playwriter_invocations.exists()


def run_action_scheduler_drain_gate(
    tmp_path: Path, *extra_args: str
) -> tuple[subprocess.CompletedProcess[str], Path, str]:
    ref_wp = tmp_path / "reference" / "wp"
    target_wp = tmp_path / "target" / "wp"
    fake_playwriter = tmp_path / "fake-playwriter"
    fake_parity = tmp_path / "fake-parity-diff"
    playwriter_invocations = tmp_path / "playwriter-invocations.jsonl"
    parity_invocations = tmp_path / "parity-invocations.jsonl"
    wp_invocations = tmp_path / "wp-invocations.txt"
    out_dir = tmp_path / "evidence"

    make_fake_wp(ref_wp, REF_URL, enrich_order_evidence=True)
    make_fake_wp(target_wp, TARGET_URL, enrich_order_evidence=True)
    make_fake_playwriter(fake_playwriter)
    make_fake_parity_diff(fake_parity, parity_invocations)

    result = run_gate(
        "--methods",
        "ideal",
        "--ref",
        str(ref_wp),
        "--target",
        str(target_wp),
        "--playwriter-session",
        "unit",
        "--out-dir",
        str(out_dir),
        *extra_args,
        env={
            "PLAYWRITER_BIN": str(fake_playwriter),
            "FAKE_PLAYWRITER_INVOCATIONS": str(playwriter_invocations),
            "FAKE_WP_INVOCATIONS": str(wp_invocations),
            "LPM_PARITY_DIFF_BIN": str(fake_parity),
        },
    )
    wp_log = (
        wp_invocations.read_text(encoding="utf-8") if wp_invocations.exists() else ""
    )
    return result, out_dir, wp_log


def test_full_gate_drains_action_scheduler_by_default() -> None:
    with tempfile.TemporaryDirectory(prefix="lpm-gate-drain-default-") as tmp:
        result, out_dir, wp_log = run_action_scheduler_drain_gate(Path(tmp))

        assert result.returncode == 0, result.stderr
        assert wp_log.count(
            "action-scheduler run --batch-size=100 --batches=5 --force"
        ) == 2
        assert wp_log.count("eval-file - restore-lpm-fixture") == 2
        assert wp_log.count("eval-file - cleanup-lpm-product") == 2
        cleanup = json.loads(
            (out_dir / "lpm-cleanup-restore.json").read_text(encoding="utf-8")
        )
        assert cleanup["status"] == "pass"
        rollup = json.loads(
            (out_dir / "lpm-checkout-gate.json").read_text(encoding="utf-8")
        )
        assert rollup["action_scheduler_drain_enabled"] is True


def test_full_gate_can_skip_action_scheduler_drain_without_skipping_cleanup() -> None:
    with tempfile.TemporaryDirectory(prefix="lpm-gate-drain-disabled-") as tmp:
        result, out_dir, wp_log = run_action_scheduler_drain_gate(
            Path(tmp), "--skip-action-scheduler-drain"
        )

        assert result.returncode == 0, result.stderr
        assert "action-scheduler run" not in wp_log
        assert wp_log.count("eval-file - restore-lpm-fixture") == 2
        assert wp_log.count("eval-file - cleanup-lpm-product") == 2
        assert "Action Scheduler queue drain intentionally disabled" in result.stderr
        cleanup = json.loads(
            (out_dir / "lpm-cleanup-restore.json").read_text(encoding="utf-8")
        )
        assert cleanup["status"] == "pass"
        rollup = json.loads(
            (out_dir / "lpm-checkout-gate.json").read_text(encoding="utf-8")
        )
        assert rollup["action_scheduler_drain_enabled"] is False


def test_full_gate_invokes_playwriter_driver_for_each_store_and_validates_evidence() -> None:
    with tempfile.TemporaryDirectory(prefix="lpm-gate-test-") as tmp:
        tmp_path = Path(tmp)
        ref_wp = tmp_path / "reference" / "wp"
        target_wp = tmp_path / "target" / "wp"
        fake_playwriter = tmp_path / "fake-playwriter"
        fake_parity = tmp_path / "fake-parity-diff"
        invocations_path = tmp_path / "playwriter-invocations.jsonl"
        parity_invocations_path = tmp_path / "parity-invocations.jsonl"
        out_dir = tmp_path / "evidence"

        make_fake_wp(ref_wp, "http://localhost:8082", enrich_order_evidence=True)
        make_fake_wp(target_wp, "http://store8889.localhost:8889", enrich_order_evidence=True)
        make_fake_playwriter(fake_playwriter)
        make_fake_parity_diff(fake_parity, parity_invocations_path)

        env = {
            **os.environ,
            "PLAYWRITER_BIN": str(fake_playwriter),
            "FAKE_PLAYWRITER_INVOCATIONS": str(invocations_path),
            "LPM_PARITY_DIFF_BIN": str(fake_parity),
        }

        result = run_gate(
            "--methods",
            "ideal",
            "--ref",
            str(ref_wp),
            "--target",
            str(target_wp),
            "--playwriter-session",
            "unit",
            "--out-dir",
            str(out_dir),
            env=env,
        )

        assert result.returncode == 0, result.stderr
        invocations = [
            json.loads(line)
            for line in invocations_path.read_text(encoding="utf-8").splitlines()
            if line
        ]
        assert len(invocations) == 4
        seed_invocations = [item for item in invocations if "-e" in item["argv"]]
        driver_invocations = [item for item in invocations if "-f" in item["argv"]]
        assert len(seed_invocations) == 2
        assert len(driver_invocations) == 2
        assert all("state.lpmCheckoutConfig" in " ".join(item["argv"]) for item in seed_invocations)
        assert all("woocommerce_payments_ideal" in " ".join(item["argv"]) for item in seed_invocations)
        assert all('"productId": "1001"' in " ".join(item["argv"]) for item in seed_invocations)
        assert all('"checkoutPageId": "90"' in " ".join(item["argv"]) for item in seed_invocations)
        assert {item["env"]["role"] for item in driver_invocations} == {"reference", "target"}
        assert {item["env"]["base_url"] for item in driver_invocations} == {
            "http://localhost:8082",
            "http://store8889.localhost:8889",
        }
        assert all("-s" in item["argv"] and "unit" in item["argv"] for item in invocations)
        assert all(str(REPO / "tools/woopayments-merge/lpm-checkout.playwriter.mjs") in item["argv"] for item in driver_invocations)

        rollup = json.loads((out_dir / "lpm-checkout-gate.json").read_text(encoding="utf-8"))
        assert rollup["status"] == "pass"
        assert len(rollup["results"]) == 2
        assert all(item["method"] == "ideal" for item in rollup["results"])
        assert all(item["gateway_id"] == "woocommerce_payments_ideal" for item in rollup["results"])
        assert all(item["stripe_payment_method_type"] == "ideal" for item in rollup["results"])
        assert all(item["order_id"] == 1001 for item in rollup["results"])
        assert all(item["selected_gateway_id"] == "woocommerce_payments_ideal" for item in rollup["results"])
        assert all(item["order_payment_method"] == "woocommerce_payments_ideal" for item in rollup["results"])
        assert all(item["payment_intent_id"] == "pi_wp_1001" for item in rollup["results"])
        assert all(
            item["order_enrichment"]["payment_intent_id"] == "pi_wp_1001"
            for item in rollup["results"]
        )

        parity_invocation = json.loads(parity_invocations_path.read_text(encoding="utf-8"))
        parity_args = parity_invocation["argv"]
        assert parity_args[0] == "--ref"
        assert parity_args[1].endswith("docker exec -i woopayments-test-reference-wp wp")
        assert parity_args[2] == "--target"
        assert parity_args[3].endswith("docker exec -i woopayments-test-target-cli-1 wp")
        assert parity_args[4:] == ["--target-ids", "1001", "1001"]


def test_gate_rejects_conflicting_browser_and_persisted_payment_intent_ids() -> None:
    with tempfile.TemporaryDirectory(prefix="lpm-gate-test-") as tmp:
        tmp_path = Path(tmp)
        ref_wp = tmp_path / "reference" / "wp"
        target_wp = tmp_path / "target" / "wp"
        fake_playwriter = tmp_path / "fake-playwriter"
        fake_parity = tmp_path / "fake-parity-diff"
        playwriter_invocations = tmp_path / "playwriter-invocations.jsonl"
        parity_invocations = tmp_path / "parity-invocations.jsonl"
        out_dir = tmp_path / "evidence"

        make_fake_wp(ref_wp, REF_URL, enrich_order_evidence=True)
        make_fake_wp(target_wp, TARGET_URL, enrich_order_evidence=True)
        make_fake_playwriter(fake_playwriter, payment_intent_id="pi_unit_ideal")
        make_fake_parity_diff(fake_parity, parity_invocations)

        result = run_gate(
            "--methods",
            "ideal",
            "--ref",
            str(ref_wp),
            "--target",
            str(target_wp),
            "--playwriter-session",
            "unit",
            "--out-dir",
            str(out_dir),
            env={
                "PLAYWRITER_BIN": str(fake_playwriter),
                "FAKE_PLAYWRITER_INVOCATIONS": str(playwriter_invocations),
                "LPM_PARITY_DIFF_BIN": str(fake_parity),
            },
        )

        assert result.returncode == 1
        assert "browser/persisted payment_intent_id conflict" in result.stderr
        evidence = json.loads(
            (out_dir / "reference-ideal-classic.json").read_text(encoding="utf-8")
        )
        assert evidence["payment_intent_id"] == "pi_unit_ideal"
        assert evidence["order_enrichment_conflicts"]["payment_intent_id"] == {
            "browser": "pi_unit_ideal",
            "persisted": "pi_wp_1001",
        }


def test_gate_rejects_multibanco_without_target_voucher_rendering() -> None:
    with tempfile.TemporaryDirectory(prefix="lpm-gate-multibanco-voucher-") as tmp:
        tmp_path = Path(tmp)
        ref_wp = tmp_path / "reference" / "wp"
        target_wp = tmp_path / "target" / "wp"
        fake_playwriter = tmp_path / "fake-playwriter"
        fake_parity = tmp_path / "fake-parity-diff"
        playwriter_invocations = tmp_path / "playwriter-invocations.jsonl"
        parity_invocations = tmp_path / "parity-invocations.jsonl"
        out_dir = tmp_path / "evidence"

        make_fake_wp(ref_wp, REF_URL, enrich_order_evidence=True)
        make_fake_wp(target_wp, TARGET_URL, enrich_order_evidence=True)
        make_fake_playwriter(
            fake_playwriter,
            omit_target_multibanco_voucher=True,
        )
        make_fake_parity_diff(fake_parity, parity_invocations)

        result = run_gate(
            "--methods",
            "multibanco",
            "--ref",
            str(ref_wp),
            "--target",
            str(target_wp),
            "--playwriter-session",
            "unit",
            "--out-dir",
            str(out_dir),
            env={
                "PLAYWRITER_BIN": str(fake_playwriter),
                "FAKE_PLAYWRITER_INVOCATIONS": str(playwriter_invocations),
                "LPM_PARITY_DIFF_BIN": str(fake_parity),
            },
        )

        assert result.returncode == 1
        assert "target/multibanco: missing Multibanco voucher rendering" in result.stderr


def test_gate_rejects_multibanco_voucher_mismatch_between_stores() -> None:
    with tempfile.TemporaryDirectory(prefix="lpm-gate-multibanco-mismatch-") as tmp:
        tmp_path = Path(tmp)
        ref_wp = tmp_path / "reference" / "wp"
        target_wp = tmp_path / "target" / "wp"
        fake_playwriter = tmp_path / "fake-playwriter"
        fake_parity = tmp_path / "fake-parity-diff"
        playwriter_invocations = tmp_path / "playwriter-invocations.jsonl"
        parity_invocations = tmp_path / "parity-invocations.jsonl"
        out_dir = tmp_path / "evidence"

        make_fake_wp(ref_wp, REF_URL, enrich_order_evidence=True)
        make_fake_wp(target_wp, TARGET_URL, enrich_order_evidence=True)
        make_fake_playwriter(
            fake_playwriter,
            mismatch_target_multibanco_voucher=True,
        )
        make_fake_parity_diff(fake_parity, parity_invocations)

        result = run_gate(
            "--methods",
            "multibanco",
            "--ref",
            str(ref_wp),
            "--target",
            str(target_wp),
            "--playwriter-session",
            "unit",
            "--out-dir",
            str(out_dir),
            env={
                "PLAYWRITER_BIN": str(fake_playwriter),
                "FAKE_PLAYWRITER_INVOCATIONS": str(playwriter_invocations),
                "LPM_PARITY_DIFF_BIN": str(fake_parity),
            },
        )

        assert result.returncode == 1
        assert "Multibanco voucher reference mismatch" in result.stderr


def test_gate_rejects_provider_observation_outside_test_mode() -> None:
    with tempfile.TemporaryDirectory(prefix="lpm-gate-test-") as tmp:
        tmp_path = Path(tmp)
        ref_wp = tmp_path / "reference" / "wp"
        target_wp = tmp_path / "target" / "wp"
        fake_playwriter = tmp_path / "fake-playwriter"
        fake_parity = tmp_path / "fake-parity-diff"
        playwriter_invocations = tmp_path / "playwriter-invocations.jsonl"
        parity_invocations = tmp_path / "parity-invocations.jsonl"
        out_dir = tmp_path / "evidence"

        make_fake_wp(
            ref_wp,
            REF_URL,
            enrich_order_evidence=True,
            provider_test_mode=False,
        )
        make_fake_wp(
            target_wp,
            TARGET_URL,
            enrich_order_evidence=True,
            provider_test_mode=False,
        )
        make_fake_playwriter(fake_playwriter, omit_payment_intent_id=True)
        make_fake_parity_diff(fake_parity, parity_invocations)

        result = run_gate(
            "--methods",
            "ideal",
            "--ref",
            str(ref_wp),
            "--target",
            str(target_wp),
            "--playwriter-session",
            "unit",
            "--out-dir",
            str(out_dir),
            env={
                "PLAYWRITER_BIN": str(fake_playwriter),
                "FAKE_PLAYWRITER_INVOCATIONS": str(playwriter_invocations),
                "LPM_PARITY_DIFF_BIN": str(fake_parity),
            },
        )

        assert result.returncode == 1
        assert "provider_observation must prove test mode" in result.stderr


def test_gate_records_fresh_provider_payment_method_observations_for_both_store_runtimes() -> None:
    with tempfile.TemporaryDirectory(prefix="lpm-gate-test-") as tmp:
        tmp_path = Path(tmp)
        ref_wp = tmp_path / "reference" / "wp"
        target_wp = tmp_path / "target" / "wp"
        fake_playwriter = tmp_path / "fake-playwriter"
        fake_parity = tmp_path / "fake-parity-diff"
        invocations_path = tmp_path / "playwriter-invocations.jsonl"
        parity_invocations_path = tmp_path / "parity-invocations.jsonl"
        wp_invocations_path = tmp_path / "wp-invocations.txt"
        out_dir = tmp_path / "evidence"

        make_fake_wp(
            ref_wp,
            "http://localhost:8082",
            enrich_order_evidence=True,
            provider_runtime_adapter="legacy_woopayments_api_client",
        )
        make_fake_wp(
            target_wp,
            "http://store8889.localhost:8889",
            enrich_order_evidence=True,
            provider_runtime_adapter="native_woocommerce_api_client",
        )
        make_fake_playwriter(fake_playwriter)
        make_fake_parity_diff(fake_parity, parity_invocations_path)

        result = run_gate(
            "--methods",
            "ideal",
            "--ref",
            str(ref_wp),
            "--target",
            str(target_wp),
            "--playwriter-session",
            "unit",
            "--out-dir",
            str(out_dir),
            env={
                **os.environ,
                "PLAYWRITER_BIN": str(fake_playwriter),
                "FAKE_PLAYWRITER_INVOCATIONS": str(invocations_path),
                "FAKE_WP_INVOCATIONS": str(wp_invocations_path),
                "LPM_PARITY_DIFF_BIN": str(fake_parity),
            },
        )

        assert result.returncode == 0, result.stderr
        wp_invocations = wp_invocations_path.read_text(encoding="utf-8")
        assert wp_invocations.count("eval-file - observe-provider-payment-method") == 2

        rollup = json.loads((out_dir / "lpm-checkout-gate.json").read_text(encoding="utf-8"))
        observations = {
            item["role"]: item["provider_observation"] for item in rollup["results"]
        }
        assert rollup["status"] == "pass"
        assert observations["reference"]["adapter"] == "legacy_woopayments_api_client"
        assert observations["target"]["adapter"] == "native_woocommerce_api_client"
        assert observations["reference"]["runtime_owner"] == "plugin"
        assert observations["target"]["runtime_owner"] == "native"
        assert {
            observation["status"] for observation in observations.values()
        } == {"pass"}
        assert {
            observation["expected_payment_method_type"]
            for observation in observations.values()
        } == {"ideal"}
        assert {
            observation["observed_payment_method_type"]
            for observation in observations.values()
        } == {"ideal"}
        assert {
            observation["payment_intent_id"] for observation in observations.values()
        } == {"pi_wp_1001"}
        assert {
            observation["provider_payment_intent_id"]
            for observation in observations.values()
        } == {"pi_wp_1001"}
        assert all(
            observation["observation_request_id"]
            for observation in observations.values()
        )
        assert len(
            {
                observation["observation_request_id"]
                for observation in observations.values()
            }
        ) == 2


def test_gate_rejects_native_runtime_for_reference_role() -> None:
    with tempfile.TemporaryDirectory(prefix="lpm-gate-test-") as tmp:
        tmp_path = Path(tmp)
        ref_wp = tmp_path / "reference" / "wp"
        target_wp = tmp_path / "target" / "wp"
        fake_playwriter = tmp_path / "fake-playwriter"
        fake_parity = tmp_path / "fake-parity-diff"
        out_dir = tmp_path / "evidence"

        make_fake_wp(
            ref_wp,
            REF_URL,
            enrich_order_evidence=True,
            provider_runtime_adapter="native_woocommerce_api_client",
        )
        make_fake_wp(target_wp, TARGET_URL, enrich_order_evidence=True)
        make_fake_playwriter(fake_playwriter)
        make_fake_parity_diff(fake_parity, tmp_path / "parity.json")

        result = run_gate(
            "--methods",
            "ideal",
            "--ref",
            str(ref_wp),
            "--target",
            str(target_wp),
            "--playwriter-session",
            "unit",
            "--out-dir",
            str(out_dir),
            env={
                "PLAYWRITER_BIN": str(fake_playwriter),
                "FAKE_PLAYWRITER_INVOCATIONS": str(tmp_path / "playwriter.jsonl"),
                "LPM_PARITY_DIFF_BIN": str(fake_parity),
            },
        )

        assert result.returncode == 1
        assert "reference provider observation runtime_owner must be plugin" in result.stderr
        rollup = json.loads((out_dir / "lpm-checkout-gate.json").read_text(encoding="utf-8"))
        assert rollup["status"] == "fail"


def test_gate_fails_core_parity_when_provider_reports_wrong_payment_method_type() -> None:
    with tempfile.TemporaryDirectory(prefix="lpm-gate-test-") as tmp:
        tmp_path = Path(tmp)
        ref_wp = tmp_path / "reference" / "wp"
        target_wp = tmp_path / "target" / "wp"
        fake_playwriter = tmp_path / "fake-playwriter"
        fake_parity = tmp_path / "fake-parity-diff"
        invocations_path = tmp_path / "playwriter-invocations.jsonl"
        parity_invocations_path = tmp_path / "parity-invocations.jsonl"
        out_dir = tmp_path / "evidence"

        make_fake_wp(
            ref_wp,
            "http://localhost:8082",
            enrich_order_evidence=True,
            provider_payment_method_type="card",
            provider_runtime_adapter="legacy_woopayments_api_client",
        )
        make_fake_wp(
            target_wp,
            "http://store8889.localhost:8889",
            enrich_order_evidence=True,
        )
        make_fake_playwriter(fake_playwriter)
        make_fake_parity_diff(fake_parity, parity_invocations_path)

        result = run_gate(
            "--methods",
            "ideal",
            "--ref",
            str(ref_wp),
            "--target",
            str(target_wp),
            "--playwriter-session",
            "unit",
            "--out-dir",
            str(out_dir),
            env={
                **os.environ,
                "PLAYWRITER_BIN": str(fake_playwriter),
                "FAKE_PLAYWRITER_INVOCATIONS": str(invocations_path),
                "LPM_PARITY_DIFF_BIN": str(fake_parity),
            },
        )

        assert result.returncode == 1
        assert "provider payment method type mismatch (Core parity)" in result.stderr
        rollup = json.loads((out_dir / "lpm-checkout-gate.json").read_text(encoding="utf-8"))
        observations = {
            item["role"]: item["provider_observation"] for item in rollup["results"]
        }
        assert rollup["status"] == "fail"
        assert observations["reference"]["status"] == "fail"
        assert observations["reference"]["observed_payment_method_type"] == "card"
        assert observations["reference"]["expected_payment_method_type"] == "ideal"
        assert observations["target"]["status"] == "pass"
        assert not parity_invocations_path.exists()


def test_gate_blocks_with_structured_provenance_when_provider_transport_is_unavailable() -> None:
    with tempfile.TemporaryDirectory(prefix="lpm-gate-test-") as tmp:
        tmp_path = Path(tmp)
        ref_wp = tmp_path / "reference" / "wp"
        target_wp = tmp_path / "target" / "wp"
        fake_playwriter = tmp_path / "fake-playwriter"
        fake_parity = tmp_path / "fake-parity-diff"
        invocations_path = tmp_path / "playwriter-invocations.jsonl"
        parity_invocations_path = tmp_path / "parity-invocations.jsonl"
        out_dir = tmp_path / "evidence"

        make_fake_wp(
            ref_wp,
            "http://localhost:8082",
            enrich_order_evidence=True,
            provider_available=False,
            provider_runtime_adapter="legacy_woopayments_api_client",
        )
        make_fake_wp(
            target_wp,
            "http://store8889.localhost:8889",
            enrich_order_evidence=True,
        )
        make_fake_playwriter(fake_playwriter)
        make_fake_parity_diff(fake_parity, parity_invocations_path)

        result = run_gate(
            "--methods",
            "ideal",
            "--ref",
            str(ref_wp),
            "--target",
            str(target_wp),
            "--playwriter-session",
            "unit",
            "--out-dir",
            str(out_dir),
            env={
                **os.environ,
                "PLAYWRITER_BIN": str(fake_playwriter),
                "FAKE_PLAYWRITER_INVOCATIONS": str(invocations_path),
                "LPM_PARITY_DIFF_BIN": str(fake_parity),
            },
        )

        assert result.returncode == 3, result.stderr
        rollup = json.loads((out_dir / "lpm-checkout-gate.json").read_text(encoding="utf-8"))
        observations = {
            item["role"]: item["provider_observation"] for item in rollup["results"]
        }
        assert rollup["status"] == "blocked"
        assert observations["reference"]["status"] == "blocked"
        assert observations["reference"]["blocker_code"] == "provider_transport_unavailable"
        assert observations["target"]["status"] == "pass"
        assert len(rollup["blocker_details"]) == 1
        detail = rollup["blocker_details"][0]
        assert (detail["role"], detail["method"], detail["code"]) == (
            "reference",
            "ideal",
            "provider_transport_unavailable",
        )
        assert detail["provenance"]["adapter"] == "legacy_woopayments_api_client"
        assert detail["provenance"]["api_client_class"] == "WC_Payments_API_Client"
        assert detail["provenance"]["payment_intent_id"] == "pi_wp_1001"
        assert detail["provenance"]["transport_available"] is False
        assert not parity_invocations_path.exists()


def test_gate_fails_resolved_provider_invariants_even_when_transport_is_blocked() -> None:
    with tempfile.TemporaryDirectory(prefix="lpm-gate-test-") as tmp:
        tmp_path = Path(tmp)
        ref_wp = tmp_path / "reference" / "wp"
        target_wp = tmp_path / "target" / "wp"
        fake_playwriter = tmp_path / "fake-playwriter"
        fake_parity = tmp_path / "fake-parity-diff"
        out_dir = tmp_path / "evidence"

        make_fake_wp(
            ref_wp,
            REF_URL,
            enrich_order_evidence=True,
            provider_available=False,
            provider_runtime_adapter="native_woocommerce_api_client",
            provider_test_mode=False,
        )
        make_fake_wp(target_wp, TARGET_URL, enrich_order_evidence=True)
        make_fake_playwriter(fake_playwriter)
        make_fake_parity_diff(fake_parity, tmp_path / "parity-invocations.jsonl")

        result = run_gate(
            "--methods",
            "ideal",
            "--ref",
            str(ref_wp),
            "--target",
            str(target_wp),
            "--playwriter-session",
            "unit",
            "--out-dir",
            str(out_dir),
            env={
                "PLAYWRITER_BIN": str(fake_playwriter),
                "FAKE_PLAYWRITER_INVOCATIONS": str(tmp_path / "playwriter.jsonl"),
                "LPM_PARITY_DIFF_BIN": str(fake_parity),
            },
        )

        assert result.returncode == 1
        assert "reference provider observation runtime_owner must be plugin" in result.stderr
        assert "provider_observation must prove test mode" in result.stderr
        rollup = json.loads((out_dir / "lpm-checkout-gate.json").read_text(encoding="utf-8"))
        assert rollup["status"] == "fail"


def test_gate_blocks_account_and_api_provider_unavailability() -> None:
    for blocker_code in (
        "provider_account_unavailable",
        "provider_api_request_unavailable",
    ):
        with tempfile.TemporaryDirectory(prefix="lpm-gate-test-") as tmp:
            tmp_path = Path(tmp)
            ref_wp = tmp_path / "reference" / "wp"
            target_wp = tmp_path / "target" / "wp"
            fake_playwriter = tmp_path / "fake-playwriter"
            fake_parity = tmp_path / "fake-parity-diff"
            out_dir = tmp_path / "evidence"

            make_fake_wp(
                ref_wp,
                "http://localhost:8082",
                enrich_order_evidence=True,
                provider_available=False,
                provider_runtime_adapter="legacy_woopayments_api_client",
                provider_blocker_code=blocker_code,
            )
            make_fake_wp(
                target_wp,
                "http://store8889.localhost:8889",
                enrich_order_evidence=True,
            )
            make_fake_playwriter(fake_playwriter)
            make_fake_parity_diff(fake_parity, tmp_path / "parity-invocations.jsonl")

            result = run_gate(
                "--methods",
                "ideal",
                "--ref",
                str(ref_wp),
                "--target",
                str(target_wp),
                "--playwriter-session",
                "unit",
                "--out-dir",
                str(out_dir),
                env={
                    **os.environ,
                    "PLAYWRITER_BIN": str(fake_playwriter),
                    "FAKE_PLAYWRITER_INVOCATIONS": str(
                        tmp_path / "playwriter-invocations.jsonl"
                    ),
                    "LPM_PARITY_DIFF_BIN": str(fake_parity),
                },
            )

            assert result.returncode == 3, result.stderr
            rollup = json.loads(
                (out_dir / "lpm-checkout-gate.json").read_text(encoding="utf-8")
            )
            assert rollup["status"] == "blocked"
            assert rollup["blocker_details"][0]["code"] == blocker_code
            assert (
                rollup["blocker_details"][0]["provenance"]["status"]
                == "blocked"
            )


def test_gate_fails_non_retriable_provider_identity_errors() -> None:
    source = SCRIPT.read_text(encoding="utf-8")
    assert "$non_retriable_client_error" in source
    assert "array( 401, 403, 408, 429 )" in source

    for provider_http_code in (400, 404):
        with tempfile.TemporaryDirectory(prefix="lpm-gate-test-") as tmp:
            tmp_path = Path(tmp)
            ref_wp = tmp_path / "reference" / "wp"
            target_wp = tmp_path / "target" / "wp"
            fake_playwriter = tmp_path / "fake-playwriter"
            fake_parity = tmp_path / "fake-parity-diff"
            out_dir = tmp_path / "evidence"

            make_fake_wp(
                ref_wp,
                REF_URL,
                enrich_order_evidence=True,
                provider_available=False,
                provider_http_code=provider_http_code,
            )
            make_fake_wp(target_wp, TARGET_URL, enrich_order_evidence=True)
            make_fake_playwriter(fake_playwriter)
            make_fake_parity_diff(fake_parity, tmp_path / "parity.json")

            result = run_gate(
                "--methods",
                "ideal",
                "--ref",
                str(ref_wp),
                "--target",
                str(target_wp),
                "--playwriter-session",
                "unit",
                "--out-dir",
                str(out_dir),
                env={
                    "PLAYWRITER_BIN": str(fake_playwriter),
                    "FAKE_PLAYWRITER_INVOCATIONS": str(tmp_path / "playwriter.jsonl"),
                    "LPM_PARITY_DIFF_BIN": str(fake_parity),
                },
            )

            assert result.returncode == 1
            rollup = json.loads(
                (out_dir / "lpm-checkout-gate.json").read_text(encoding="utf-8")
            )
            observation = next(
                item["provider_observation"]
                for item in rollup["results"]
                if item["role"] == "reference"
            )
            assert observation["status"] == "fail"
            assert observation["blocker_code"] == "provider_identity_invalid"
            assert observation["provider_http_code"] == provider_http_code


def test_gate_rejects_stale_browser_observation_and_mismatched_provider_intent() -> None:
    with tempfile.TemporaryDirectory(prefix="lpm-gate-test-") as tmp:
        tmp_path = Path(tmp)
        ref_wp = tmp_path / "reference" / "wp"
        target_wp = tmp_path / "target" / "wp"
        fake_playwriter = tmp_path / "fake-playwriter"
        fake_parity = tmp_path / "fake-parity-diff"
        invocations_path = tmp_path / "playwriter-invocations.jsonl"
        parity_invocations_path = tmp_path / "parity-invocations.jsonl"
        out_dir = tmp_path / "evidence"
        stale_observation = {
            "status": "pass",
            "observation_request_id": "stale-browser-observation",
            "payment_intent_id": "pi_wp_1001",
            "provider_payment_intent_id": "pi_wp_1001",
            "expected_payment_method_type": "ideal",
            "observed_payment_method_type": "ideal",
        }

        make_fake_wp(
            ref_wp,
            "http://localhost:8082",
            enrich_order_evidence=True,
            provider_intent_id="pi_stale_provider_object",
            provider_runtime_adapter="legacy_woopayments_api_client",
        )
        make_fake_wp(
            target_wp,
            "http://store8889.localhost:8889",
            enrich_order_evidence=True,
        )
        make_fake_playwriter(
            fake_playwriter,
            provider_observation=stale_observation,
        )
        make_fake_parity_diff(fake_parity, parity_invocations_path)

        result = run_gate(
            "--methods",
            "ideal",
            "--ref",
            str(ref_wp),
            "--target",
            str(target_wp),
            "--playwriter-session",
            "unit",
            "--out-dir",
            str(out_dir),
            env={
                **os.environ,
                "PLAYWRITER_BIN": str(fake_playwriter),
                "FAKE_PLAYWRITER_INVOCATIONS": str(invocations_path),
                "LPM_PARITY_DIFF_BIN": str(fake_parity),
            },
        )

        assert result.returncode == 1
        assert "provider PaymentIntent mismatch" in result.stderr
        rollup = json.loads((out_dir / "lpm-checkout-gate.json").read_text(encoding="utf-8"))
        observations = {
            item["role"]: item["provider_observation"] for item in rollup["results"]
        }
        assert rollup["status"] == "fail"
        assert observations["reference"]["status"] == "fail"
        assert observations["reference"]["payment_intent_id"] == "pi_wp_1001"
        assert (
            observations["reference"]["provider_payment_intent_id"]
            == "pi_stale_provider_object"
        )
        assert (
            observations["reference"]["observation_request_id"]
            != "stale-browser-observation"
        )
        assert observations["target"]["status"] == "pass"
        assert not parity_invocations_path.exists()


def test_provider_observer_uses_each_store_runtime_api_client() -> None:
    source = SCRIPT.read_text(encoding="utf-8")

    assert "WC_Payments::get_payments_api_client()" in source
    assert "->get_intent( $payment_intent_id )" in source
    assert "wc_get_container()->get( NativePaymentsRuntimeArbiter::class )" in source
    assert "->get_runtime_owner()" in source
    assert "wc_get_container()->get( WooPaymentsApiClient::class )" in source
    assert "->get_payment_intention( $payment_intent_id )" in source
    assert "payment_method_details" in source
    assert "payment_method_types" not in source[source.index("observe_provider_payment_method_type"):]


def test_provider_observer_normalizes_unexpanded_latest_charge_id() -> None:
    source = SCRIPT.read_text(encoding="utf-8")
    observer_source = source[source.index("observe_provider_payment_method_type") :]

    assert "if ( is_string( $charge ) )" in observer_source
    assert "$resource_id( $charge )" in observer_source
    assert "$fetch_charge( $charge_fields['id'] )" in observer_source


def test_full_gate_can_use_playwright_runner_without_playwriter_session() -> None:
    with tempfile.TemporaryDirectory(prefix="lpm-gate-test-") as tmp:
        tmp_path = Path(tmp)
        ref_wp = tmp_path / "reference" / "wp"
        target_wp = tmp_path / "target" / "wp"
        fake_runner = tmp_path / "fake-playwright-runner"
        fake_parity = tmp_path / "fake-parity-diff"
        invocations_path = tmp_path / "playwright-runner-invocations.jsonl"
        parity_invocations_path = tmp_path / "parity-invocations.jsonl"
        out_dir = tmp_path / "evidence"

        make_fake_wp(ref_wp, "http://localhost:8082", enrich_order_evidence=True)
        make_fake_wp(target_wp, "http://store8889.localhost:8889", enrich_order_evidence=True)
        make_fake_playwriter(fake_runner)
        make_fake_parity_diff(fake_parity, parity_invocations_path)

        result = run_gate(
            "--methods",
            "ideal",
            "--ref",
            str(ref_wp),
            "--target",
            str(target_wp),
            "--out-dir",
            str(out_dir),
            env={
                **os.environ,
                "BROWSER_RUNNER": "playwright",
                "PLAYWRIGHT_SCRIPT_RUNNER_BIN": str(fake_runner),
                "FAKE_PLAYWRITER_INVOCATIONS": str(invocations_path),
                "LPM_PARITY_DIFF_BIN": str(fake_parity),
            },
        )

        assert result.returncode == 0, result.stderr
        invocations = [
            json.loads(line)
            for line in invocations_path.read_text(encoding="utf-8").splitlines()
            if line
        ]
        assert len(invocations) == 2
        assert {item["env"]["role"] for item in invocations} == {"reference", "target"}
        assert all("-s" not in item["argv"] for item in invocations)
        assert all("-e" not in item["argv"] for item in invocations)
        assert all(str(REPO / "tools/woopayments-merge/lpm-checkout.playwriter.mjs") in item["argv"] for item in invocations)

        rollup = json.loads((out_dir / "lpm-checkout-gate.json").read_text(encoding="utf-8"))
        assert rollup["status"] == "pass"
        assert len(rollup["results"]) == 2


def test_full_gate_uses_unique_owned_product_fixtures_and_cleans_them_up() -> None:
    with tempfile.TemporaryDirectory(prefix="lpm-gate-test-") as tmp:
        tmp_path = Path(tmp)
        ref_wp = tmp_path / "reference" / "wp"
        target_wp = tmp_path / "target" / "wp"
        fake_playwriter = tmp_path / "fake-playwriter"
        fake_parity = tmp_path / "fake-parity-diff"
        invocations_path = tmp_path / "playwriter-invocations.jsonl"
        parity_invocations_path = tmp_path / "parity-invocations.jsonl"
        wp_invocations = tmp_path / "wp-invocations.txt"
        out_dir = tmp_path / "evidence"

        make_fake_wp(ref_wp, "http://localhost:8082", product_id=1101, enrich_order_evidence=True)
        make_fake_wp(target_wp, "http://store8889.localhost:8889", product_id=2202, enrich_order_evidence=True)
        make_fake_playwriter(fake_playwriter)
        make_fake_parity_diff(fake_parity, parity_invocations_path)

        env = {
            **os.environ,
            "PLAYWRITER_BIN": str(fake_playwriter),
            "FAKE_PLAYWRITER_INVOCATIONS": str(invocations_path),
            "FAKE_WP_INVOCATIONS": str(wp_invocations),
            "LPM_PARITY_DIFF_BIN": str(fake_parity),
        }

        result = run_gate(
            "--methods",
            "ideal",
            "--ref",
            str(ref_wp),
            "--target",
            str(target_wp),
            "--playwriter-session",
            "unit",
            "--out-dir",
            str(out_dir),
            env=env,
        )

        assert result.returncode == 0, result.stderr
        wp_log = wp_invocations.read_text(encoding="utf-8")
        prepare_lines = [
            line for line in wp_log.splitlines() if "eval-file - prepare-lpm-product" in line
        ]
        cleanup_lines = [
            line for line in wp_log.splitlines() if "eval-file - cleanup-lpm-product" in line
        ]
        assert len(prepare_lines) == 2
        assert len(cleanup_lines) == 2
        fixture_payloads = [
            json.loads(base64.b64decode(line.split()[-1]).decode("utf-8"))
            for line in prepare_lines
        ]
        assert {payload["role"] for payload in fixture_payloads} == {
            "reference",
            "target",
        }
        assert len({payload["sku"] for payload in fixture_payloads}) == 2
        assert all(payload["sku"].startswith("woopayments-lpm-gate-") for payload in fixture_payloads)

        invocations = [
            json.loads(line)
            for line in invocations_path.read_text(encoding="utf-8").splitlines()
            if line
        ]
        driver_invocations = [item for item in invocations if "-f" in item["argv"]]
        assert {item["env"]["product_id"] for item in driver_invocations} == {
            "1101",
            "2202",
        }
        cleanup = json.loads(
            (out_dir / "lpm-cleanup-restore.json").read_text(encoding="utf-8")
        )
        assert cleanup["status"] == "pass"
        assert cleanup["products"]["reference"]["cleaned"] is True
        assert cleanup["products"]["target"]["cleaned"] is True


def test_product_cleanup_failure_blocks_an_otherwise_passing_gate() -> None:
    with tempfile.TemporaryDirectory(prefix="lpm-gate-test-") as tmp:
        tmp_path = Path(tmp)
        ref_wp = tmp_path / "reference" / "wp"
        target_wp = tmp_path / "target" / "wp"
        fake_playwriter = tmp_path / "fake-playwriter"
        fake_parity = tmp_path / "fake-parity-diff"
        parity_invocations_path = tmp_path / "parity-invocations.jsonl"
        wp_invocations = tmp_path / "wp-invocations.txt"
        out_dir = tmp_path / "evidence"

        make_fake_wp(ref_wp, REF_URL, enrich_order_evidence=True)
        make_fake_wp(
            target_wp,
            TARGET_URL,
            enrich_order_evidence=True,
            product_cleanup_succeeds=False,
        )
        make_fake_playwriter(fake_playwriter)
        make_fake_parity_diff(fake_parity, parity_invocations_path)

        result = run_gate(
            "--methods",
            "ideal",
            "--ref",
            str(ref_wp),
            "--target",
            str(target_wp),
            "--playwriter-session",
            "unit",
            "--out-dir",
            str(out_dir),
            env={
                "PLAYWRITER_BIN": str(fake_playwriter),
                "FAKE_WP_INVOCATIONS": str(wp_invocations),
                "LPM_PARITY_DIFF_BIN": str(fake_parity),
            },
        )

        assert result.returncode == 3
        rollup = json.loads((out_dir / "lpm-checkout-gate.json").read_text(encoding="utf-8"))
        assert rollup["status"] == "blocked"
        assert any(
            detail["code"] == "target_product_cleanup_failed"
            for detail in rollup["blocker_details"]
        )


def test_fixture_restore_failure_overrides_checkout_failure_to_blocked() -> None:
    with tempfile.TemporaryDirectory(prefix="lpm-gate-test-") as tmp:
        tmp_path = Path(tmp)
        ref_wp = tmp_path / "reference" / "wp"
        target_wp = tmp_path / "target" / "wp"
        fake_playwriter = tmp_path / "fake-playwriter"
        fake_parity = tmp_path / "fake-parity-diff"
        parity_invocations_path = tmp_path / "parity-invocations.jsonl"
        wp_invocations = tmp_path / "wp-invocations.txt"
        out_dir = tmp_path / "evidence"

        make_fake_wp(ref_wp, REF_URL, enrich_order_evidence=True)
        make_fake_wp(
            target_wp,
            TARGET_URL,
            enrich_order_evidence=True,
            restore_succeeds=False,
        )
        make_fake_playwriter(
            fake_playwriter,
            status="fail",
            failure_messages=("synthetic checkout failure",),
            exit_code=1,
        )
        make_fake_parity_diff(fake_parity, parity_invocations_path)

        result = run_gate(
            "--methods",
            "ideal",
            "--ref",
            str(ref_wp),
            "--target",
            str(target_wp),
            "--playwriter-session",
            "unit",
            "--out-dir",
            str(out_dir),
            env={
                "PLAYWRITER_BIN": str(fake_playwriter),
                "FAKE_WP_INVOCATIONS": str(wp_invocations),
                "LPM_PARITY_DIFF_BIN": str(fake_parity),
            },
        )

        assert result.returncode == 3
        rollup = json.loads((out_dir / "lpm-checkout-gate.json").read_text(encoding="utf-8"))
        assert rollup["status"] == "blocked"
        assert rollup["failures"]
        assert any(
            detail["code"] == "target_fixture_restore_failed"
            for detail in rollup["blocker_details"]
        )
        wp_log = wp_invocations.read_text(encoding="utf-8")
        assert wp_log.count("eval-file - restore-lpm-fixture") == 4
        assert int(Path(f"{target_wp}.restore-count").read_text(encoding="utf-8")) == 3
        assert int(Path(f"{ref_wp}.restore-count").read_text(encoding="utf-8")) == 1


def test_finalization_retries_a_transient_fixture_restore_failure() -> None:
    with tempfile.TemporaryDirectory(prefix="lpm-gate-test-") as tmp:
        tmp_path = Path(tmp)
        ref_wp = tmp_path / "reference" / "wp"
        target_wp = tmp_path / "target" / "wp"
        fake_playwriter = tmp_path / "fake-playwriter"
        fake_parity = tmp_path / "fake-parity-diff"
        parity_invocations_path = tmp_path / "parity-invocations.jsonl"
        wp_invocations = tmp_path / "wp-invocations.txt"
        out_dir = tmp_path / "evidence"

        make_fake_wp(ref_wp, REF_URL, enrich_order_evidence=True)
        make_fake_wp(
            target_wp,
            TARGET_URL,
            enrich_order_evidence=True,
            restore_failures_before_success=1,
        )
        make_fake_playwriter(fake_playwriter, omit_payment_intent_id=True)
        make_fake_parity_diff(fake_parity, parity_invocations_path)

        result = run_gate(
            "--methods",
            "ideal",
            "--ref",
            str(ref_wp),
            "--target",
            str(target_wp),
            "--playwriter-session",
            "unit",
            "--out-dir",
            str(out_dir),
            env={
                "PLAYWRITER_BIN": str(fake_playwriter),
                "FAKE_PLAYWRITER_INVOCATIONS": str(tmp_path / "playwriter.jsonl"),
                "FAKE_WP_INVOCATIONS": str(wp_invocations),
                "LPM_PARITY_DIFF_BIN": str(fake_parity),
            },
        )

        assert result.returncode == 3
        assert int(Path(f"{target_wp}.restore-count").read_text(encoding="utf-8")) == 2
        assert json.loads(Path(f"{target_wp}.state.json").read_text(encoding="utf-8")) == {
            "country": "US:CA",
            "currency": "USD",
            "settings": {"upe_enabled_payment_method_ids": ["card"]},
        }


def test_prepare_transport_failure_still_cleans_product_by_owned_sku() -> None:
    with tempfile.TemporaryDirectory(prefix="lpm-gate-test-") as tmp:
        tmp_path = Path(tmp)
        ref_wp = tmp_path / "reference" / "wp"
        target_wp = tmp_path / "target" / "wp"
        fake_playwriter = tmp_path / "fake-playwriter"
        fake_parity = tmp_path / "fake-parity-diff"
        parity_invocations_path = tmp_path / "parity-invocations.jsonl"
        wp_invocations = tmp_path / "wp-invocations.txt"
        out_dir = tmp_path / "evidence"

        make_fake_wp(ref_wp, REF_URL)
        make_fake_wp(
            target_wp,
            TARGET_URL,
            product_prepare_fails_after_create=True,
        )
        make_fake_playwriter(fake_playwriter)
        make_fake_parity_diff(fake_parity, parity_invocations_path)

        result = run_gate(
            "--methods",
            "ideal",
            "--ref",
            str(ref_wp),
            "--target",
            str(target_wp),
            "--playwriter-session",
            "unit",
            "--out-dir",
            str(out_dir),
            env={
                "PLAYWRITER_BIN": str(fake_playwriter),
                "FAKE_WP_INVOCATIONS": str(wp_invocations),
                "LPM_PARITY_DIFF_BIN": str(fake_parity),
            },
        )

        assert result.returncode == 3
        wp_log = wp_invocations.read_text(encoding="utf-8")
        assert "eval-file - cleanup-lpm-product" in wp_log


def test_full_gate_stages_and_restores_per_method_lpm_fixtures() -> None:
    with tempfile.TemporaryDirectory(prefix="lpm-gate-test-") as tmp:
        tmp_path = Path(tmp)
        ref_wp = tmp_path / "reference" / "wp"
        target_wp = tmp_path / "target" / "wp"
        fake_playwriter = tmp_path / "fake-playwriter"
        fake_parity = tmp_path / "fake-parity-diff"
        invocations_path = tmp_path / "playwriter-invocations.jsonl"
        parity_invocations_path = tmp_path / "parity-invocations.jsonl"
        wp_invocations = tmp_path / "wp-invocations.txt"
        ref_state = Path(f"{ref_wp}.state.json")
        target_state = Path(f"{target_wp}.state.json")
        out_dir = tmp_path / "evidence"

        make_fake_wp(ref_wp, "http://localhost:8082", stageable=True, enrich_order_evidence=True)
        make_fake_wp(target_wp, "http://store8889.localhost:8889", stageable=True, enrich_order_evidence=True)
        make_fake_playwriter(fake_playwriter)
        make_fake_parity_diff(fake_parity, parity_invocations_path)

        env = {
            **os.environ,
            "PLAYWRITER_BIN": str(fake_playwriter),
            "FAKE_PLAYWRITER_INVOCATIONS": str(invocations_path),
            "FAKE_WP_INVOCATIONS": str(wp_invocations),
            "LPM_PARITY_DIFF_BIN": str(fake_parity),
        }

        result = run_gate(
            "--methods",
            "sepa_debit",
            "--ref",
            str(ref_wp),
            "--target",
            str(target_wp),
            "--playwriter-session",
            "unit",
            "--out-dir",
            str(out_dir),
            env=env,
        )

        assert result.returncode == 0, result.stderr
        wp_log = wp_invocations.read_text(encoding="utf-8")
        assert wp_log.count("eval-file - stage-lpm-fixture") == 2
        assert wp_log.count("eval-file - restore-lpm-fixture") == 2
        assert "eval-file - clear-lpm-cart-sessions" not in wp_log
        assert wp_log.count("stage-lpm-fixture decoded sepa_debit EUR NL") == 2
        assert json.loads(ref_state.read_text(encoding="utf-8")) == {
            "settings": {"upe_enabled_payment_method_ids": ["card"]},
            "currency": "USD",
            "country": "US:CA",
        }
        assert json.loads(target_state.read_text(encoding="utf-8")) == {
            "settings": {"upe_enabled_payment_method_ids": ["card"]},
            "currency": "USD",
            "country": "US:CA",
        }


def test_lpm_gate_records_fixture_blockers_per_method_and_continues() -> None:
    with tempfile.TemporaryDirectory(prefix="lpm-gate-test-") as tmp:
        tmp_path = Path(tmp)
        ref_wp = tmp_path / "reference" / "wp"
        target_wp = tmp_path / "target" / "wp"
        fake_playwriter = tmp_path / "fake-playwriter"
        fake_parity = tmp_path / "fake-parity-diff"
        invocations_path = tmp_path / "playwriter-invocations.jsonl"
        parity_invocations_path = tmp_path / "parity-invocations.jsonl"
        wp_invocations = tmp_path / "wp-invocations.txt"
        out_dir = tmp_path / "evidence"

        make_fake_wp(ref_wp, "http://localhost:8082", blocked_stage_methods=("p24",), enrich_order_evidence=True)
        make_fake_wp(target_wp, "http://store8889.localhost:8889", blocked_stage_methods=("p24",), enrich_order_evidence=True)
        make_fake_playwriter(fake_playwriter)
        make_fake_parity_diff(fake_parity, parity_invocations_path)

        env = {
            **os.environ,
            "PLAYWRITER_BIN": str(fake_playwriter),
            "FAKE_PLAYWRITER_INVOCATIONS": str(invocations_path),
            "FAKE_WP_INVOCATIONS": str(wp_invocations),
            "LPM_PARITY_DIFF_BIN": str(fake_parity),
        }

        result = run_gate(
            "--methods",
            "ideal,p24,alipay",
            "--ref",
            str(ref_wp),
            "--target",
            str(target_wp),
            "--playwriter-session",
            "unit",
            "--out-dir",
            str(out_dir),
            env=env,
        )

        assert result.returncode == 3, result.stderr
        assert "BLOCKED:" in result.stderr
        assert "p24" in result.stderr

        wp_log = wp_invocations.read_text(encoding="utf-8")
        assert "stage-lpm-fixture blocked p24 PLN PL" not in wp_log
        assert wp_log.count("wcpay-dev test-lab account profile --profile=p24 --format=json") == 2
        assert wp_log.count("eval-file - restore-lpm-fixture") == 4
        assert "stage-lpm-fixture decoded ideal EUR NL" in wp_log
        assert "stage-lpm-fixture decoded alipay USD US" in wp_log

        invocations = [
            json.loads(line)
            for line in invocations_path.read_text(encoding="utf-8").splitlines()
            if line
        ]
        driver_invocations = [item for item in invocations if "-f" in item["argv"]]
        assert {item["env"]["method"] for item in driver_invocations} == {"ideal", "alipay"}
        assert len(driver_invocations) == 4

        rollup = json.loads((out_dir / "lpm-checkout-gate.json").read_text(encoding="utf-8"))
        assert rollup["status"] == "blocked"
        assert {item["method"] for item in rollup["results"]} == {"ideal", "alipay"}
        assert any("p24" in blocker for blocker in rollup["blockers"])
        assert any("account profile p24 readiness status=blocked ready=false" in blocker for blocker in rollup["blockers"])
        assert any(
            "account profile p24 readiness status=blocked ready=false provisionable=true country=PL checks=p24_payments:rejected"
            in blocker
            for blocker in rollup["blockers"]
        )
        assert any("provisioning=manual_account_lifecycle" in blocker for blocker in rollup["blockers"])
        assert {
            (detail["role"], detail["method"], detail["code"])
            for detail in rollup["blocker_details"]
        } == {
            ("reference", "p24", "account_profile_ineligible"),
            ("target", "p24", "account_profile_ineligible"),
        }
        assert (out_dir / "reference-p24-account-profile.json").exists()
        assert (out_dir / "target-p24-account-profile.json").exists()


def test_lpm_gate_stages_profile_method_only_after_ready_profile() -> None:
    with tempfile.TemporaryDirectory(prefix="lpm-gate-test-") as tmp:
        tmp_path = Path(tmp)
        ref_wp = tmp_path / "reference" / "wp"
        target_wp = tmp_path / "target" / "wp"
        fake_playwriter = tmp_path / "fake-playwriter"
        fake_parity = tmp_path / "fake-parity-diff"
        invocations_path = tmp_path / "playwriter-invocations.jsonl"
        parity_invocations_path = tmp_path / "parity-invocations.jsonl"
        wp_invocations = tmp_path / "wp-invocations.txt"
        out_dir = tmp_path / "evidence"

        make_fake_wp(ref_wp, "http://localhost:8082", account_profile_ready=True, enrich_order_evidence=True)
        make_fake_wp(target_wp, "http://store8889.localhost:8889", account_profile_ready=True, enrich_order_evidence=True)
        make_fake_playwriter(fake_playwriter)
        make_fake_parity_diff(fake_parity, parity_invocations_path)

        result = run_gate(
            "--methods",
            "p24",
            "--ref",
            str(ref_wp),
            "--target",
            str(target_wp),
            "--playwriter-session",
            "unit",
            "--out-dir",
            str(out_dir),
            env={
                **os.environ,
                "PLAYWRITER_BIN": str(fake_playwriter),
                "FAKE_PLAYWRITER_INVOCATIONS": str(invocations_path),
                "FAKE_WP_INVOCATIONS": str(wp_invocations),
                "LPM_PARITY_DIFF_BIN": str(fake_parity),
            },
        )

        assert result.returncode == 0, result.stderr

        wp_log = wp_invocations.read_text(encoding="utf-8")
        assert wp_log.count("wcpay-dev test-lab account profile --profile=p24 --format=json") == 2
        assert wp_log.count("stage-lpm-fixture decoded p24 PLN PL") == 2

        invocations = [
            json.loads(line)
            for line in invocations_path.read_text(encoding="utf-8").splitlines()
            if line
        ]
        driver_invocations = [item for item in invocations if "-f" in item["argv"]]
        assert {item["env"]["method"] for item in driver_invocations} == {"p24"}
        assert len(driver_invocations) == 2

        rollup = json.loads((out_dir / "lpm-checkout-gate.json").read_text(encoding="utf-8"))
        assert rollup["status"] == "pass"
        assert not rollup["blockers"]
        assert {item["method"] for item in rollup["results"]} == {"p24"}
        assert json.loads((out_dir / "reference-p24-account-profile.json").read_text(encoding="utf-8"))["ready"] is True
        assert json.loads((out_dir / "target-p24-account-profile.json").read_text(encoding="utf-8"))["status"] == "ready"


def test_lpm_gate_parses_pretty_account_profile_json_for_blockers() -> None:
    with tempfile.TemporaryDirectory(prefix="lpm-gate-test-") as tmp:
        tmp_path = Path(tmp)
        ref_wp = tmp_path / "reference" / "wp"
        target_wp = tmp_path / "target" / "wp"
        fake_playwriter = tmp_path / "fake-playwriter"
        fake_parity = tmp_path / "fake-parity-diff"
        invocations_path = tmp_path / "playwriter-invocations.jsonl"
        parity_invocations_path = tmp_path / "parity-invocations.jsonl"
        out_dir = tmp_path / "evidence"

        make_fake_wp(
            ref_wp,
            "http://localhost:8082",
            blocked_stage_methods=("p24",),
            pretty_account_profile_json=True,
            enrich_order_evidence=True,
        )
        make_fake_wp(
            target_wp,
            "http://store8889.localhost:8889",
            blocked_stage_methods=("p24",),
            pretty_account_profile_json=True,
            enrich_order_evidence=True,
        )
        make_fake_playwriter(fake_playwriter)
        make_fake_parity_diff(fake_parity, parity_invocations_path)

        result = run_gate(
            "--methods",
            "p24",
            "--ref",
            str(ref_wp),
            "--target",
            str(target_wp),
            "--playwriter-session",
            "unit",
            "--out-dir",
            str(out_dir),
            env={
                **os.environ,
                "PLAYWRITER_BIN": str(fake_playwriter),
                "FAKE_PLAYWRITER_INVOCATIONS": str(invocations_path),
                "LPM_PARITY_DIFF_BIN": str(fake_parity),
            },
        )

        assert result.returncode == 3, result.stderr

        rollup = json.loads((out_dir / "lpm-checkout-gate.json").read_text(encoding="utf-8"))
        assert rollup["status"] == "blocked"
        assert len(rollup["blockers"]) == 2
        assert not any("unparseable" in blocker for blocker in rollup["blockers"])
        assert sum("account profile p24 readiness status=blocked ready=false" in blocker for blocker in rollup["blockers"]) == 2
        assert sum(
            "account profile p24 readiness status=blocked ready=false provisionable=true country=PL checks=p24_payments:rejected"
            in blocker
            for blocker in rollup["blockers"]
        ) == 2
        assert sum("provisioning=manual_account_lifecycle" in blocker for blocker in rollup["blockers"]) == 2
        assert json.loads((out_dir / "reference-p24-account-profile.json").read_text(encoding="utf-8"))["ready"] is False
        assert json.loads((out_dir / "target-p24-account-profile.json").read_text(encoding="utf-8"))["status"] == "blocked"


def test_lpm_gate_does_not_clear_other_users_server_side_cart_state() -> None:
    source = SCRIPT.read_text(encoding="utf-8")

    assert "clear-lpm-cart-sessions" not in source
    assert "_woocommerce_persistent_cart_" not in source
    assert "$wpdb->usermeta" not in source
    assert "delete_user_meta" not in source


def test_gate_rejects_expected_url_that_does_not_match_wp_runner() -> None:
    with tempfile.TemporaryDirectory(prefix="lpm-gate-test-") as tmp:
        tmp_path = Path(tmp)
        ref_wp = tmp_path / "reference" / "wp"
        target_wp = tmp_path / "target" / "wp"
        fake_playwriter = tmp_path / "fake-playwriter"
        fake_parity = tmp_path / "fake-parity-diff"
        invocations_path = tmp_path / "playwriter-invocations.jsonl"
        parity_invocations_path = tmp_path / "parity-invocations.jsonl"
        wp_invocations_path = tmp_path / "wp-invocations.txt"
        out_dir = tmp_path / "evidence"

        make_fake_wp(ref_wp, "http://localhost", enrich_order_evidence=True)
        make_fake_wp(target_wp, "http://internal-target.localhost", enrich_order_evidence=True)
        make_fake_playwriter(fake_playwriter)
        make_fake_parity_diff(fake_parity, parity_invocations_path)

        env = {
            **os.environ,
            "PLAYWRITER_BIN": str(fake_playwriter),
            "FAKE_PLAYWRITER_INVOCATIONS": str(invocations_path),
            "FAKE_WP_INVOCATIONS": str(wp_invocations_path),
            "LPM_PARITY_DIFF_BIN": str(fake_parity),
        }

        result = run_gate(
            "--methods",
            "ideal",
            "--ref",
            str(ref_wp),
            "--target",
            str(target_wp),
            "--ref-url",
            "http://localhost:8082",
            "--target-url",
            "http://store8889.localhost:8889",
            "--playwriter-session",
            "unit",
            "--out-dir",
            str(out_dir),
            env=env,
        )

        assert result.returncode == 3
        assert "reference WP runner home URL does not match" in result.stderr
        assert "ensure-lpm-product" not in wp_invocations_path.read_text(encoding="utf-8")
        assert not invocations_path.exists()


def test_gate_enriches_query_order_received_evidence_from_wp_order_meta() -> None:
    with tempfile.TemporaryDirectory(prefix="lpm-gate-test-") as tmp:
        tmp_path = Path(tmp)
        ref_wp = tmp_path / "reference" / "wp"
        target_wp = tmp_path / "target" / "wp"
        fake_playwriter = tmp_path / "fake-playwriter"
        fake_parity = tmp_path / "fake-parity-diff"
        parity_invocations_path = tmp_path / "parity-invocations.jsonl"
        out_dir = tmp_path / "evidence"

        make_fake_wp(ref_wp, "http://localhost:8082", enrich_order_evidence=True)
        make_fake_wp(target_wp, "http://store8889.localhost:8889", enrich_order_evidence=True)
        make_fake_playwriter(
            fake_playwriter,
            query_order_received_url=True,
            omit_payment_intent_id=True,
        )

        env = {
            **os.environ,
            "PLAYWRITER_BIN": str(fake_playwriter),
            "FAKE_PLAYWRITER_INVOCATIONS": str(tmp_path / "playwriter-invocations.jsonl"),
            "LPM_PARITY_DIFF_BIN": str(fake_parity),
        }
        make_fake_parity_diff(fake_parity, parity_invocations_path)

        result = run_gate(
            "--methods",
            "ideal",
            "--ref",
            str(ref_wp),
            "--target",
            str(target_wp),
            "--playwriter-session",
            "unit",
            "--out-dir",
            str(out_dir),
            env=env,
        )

        assert result.returncode == 0, result.stderr
        rollup = json.loads((out_dir / "lpm-checkout-gate.json").read_text(encoding="utf-8"))
        assert rollup["status"] == "pass"
        assert len(rollup["results"]) == 2
        assert all(item["payment_intent_id"] == "pi_wp_1001" for item in rollup["results"])
        assert all(
            item["order_received_url"].endswith("/?page_id=7&order-received=1001&key=wc_order_unit")
            for item in rollup["results"]
        )
        assert all(item["order_enrichment"]["order_status"] == "processing" for item in rollup["results"])


def test_gate_does_not_promote_pending_action_order_to_pass() -> None:
    with tempfile.TemporaryDirectory(prefix="lpm-gate-test-") as tmp:
        tmp_path = Path(tmp)
        ref_wp = tmp_path / "reference" / "wp"
        target_wp = tmp_path / "target" / "wp"
        fake_playwriter = tmp_path / "fake-playwriter"
        fake_parity = tmp_path / "fake-parity-diff"
        parity_invocations_path = tmp_path / "parity-invocations.jsonl"
        out_dir = tmp_path / "evidence"

        make_fake_wp(ref_wp, "http://localhost:8082", enrich_order_evidence=True)
        make_fake_wp(target_wp, "http://store8889.localhost:8889", enrich_order_evidence=True)
        make_fake_playwriter(
            fake_playwriter,
            omit_order_id=True,
            omit_order_received_url=True,
            status="fail",
            failure_messages=("checkout did not create an order id",),
            exit_code=1,
        )
        make_fake_parity_diff(fake_parity, parity_invocations_path)

        env = {
            **os.environ,
            "PLAYWRITER_BIN": str(fake_playwriter),
            "FAKE_PLAYWRITER_INVOCATIONS": str(tmp_path / "playwriter-invocations.jsonl"),
            "LPM_PARITY_DIFF_BIN": str(fake_parity),
        }

        result = run_gate(
            "--methods",
            "klarna",
            "--ref",
            str(ref_wp),
            "--target",
            str(target_wp),
            "--playwriter-session",
            "unit",
            "--out-dir",
            str(out_dir),
            env=env,
        )

        assert result.returncode != 0
        rollup = json.loads((out_dir / "lpm-checkout-gate.json").read_text(encoding="utf-8"))
        assert rollup["status"] == "fail"
        assert len(rollup["results"]) == 2
        assert all(item["status"] == "fail" for item in rollup["results"])
        assert all(item["order_id"] == 1001 for item in rollup["results"])
        assert all("action_pending" not in item for item in rollup["results"])
        assert all(item["failures"] == ["checkout did not create an order id"] for item in rollup["results"])
        assert all(
            item["order_enrichment"]["resolved_by"] == "payment_intent_id"
            for item in rollup["results"]
        )
        assert any("missing order_received_url" in failure for failure in rollup["failures"])


def test_gate_classifies_symmetric_klarna_customer_action_as_manual_blockers() -> None:
    with tempfile.TemporaryDirectory(prefix="lpm-gate-manual-test-") as tmp:
        tmp_path = Path(tmp)
        ref_wp = tmp_path / "reference" / "wp"
        target_wp = tmp_path / "target" / "wp"
        fake_playwriter = tmp_path / "fake-playwriter"
        fake_parity = tmp_path / "fake-parity-diff"
        parity_invocations_path = tmp_path / "parity-invocations.jsonl"
        out_dir = tmp_path / "evidence"
        browser_limitation = "checkout did not reach an order-received URL with an order id"

        make_fake_wp(
            ref_wp,
            REF_URL,
            enrich_order_evidence=True,
            enriched_order_status="pending",
            enriched_intention_status="requires_action",
        )
        make_fake_wp(
            target_wp,
            TARGET_URL,
            enrich_order_evidence=True,
            enriched_order_status="pending",
            enriched_intention_status="requires_action",
        )
        make_fake_playwriter(
            fake_playwriter,
            omit_order_id=True,
            omit_order_received_url=True,
            status="fail",
            failure_messages=(browser_limitation,),
            exit_code=1,
        )
        make_fake_parity_diff(fake_parity, parity_invocations_path)

        result = run_gate(
            "--methods",
            "klarna",
            "--ref",
            str(ref_wp),
            "--target",
            str(target_wp),
            "--playwriter-session",
            "unit",
            "--out-dir",
            str(out_dir),
            env={
                "PLAYWRITER_BIN": str(fake_playwriter),
                "FAKE_PLAYWRITER_INVOCATIONS": str(
                    tmp_path / "playwriter-invocations.jsonl"
                ),
                "LPM_PARITY_DIFF_BIN": str(fake_parity),
            },
        )

        assert result.returncode == 3, result.stdout + result.stderr
        rollup = json.loads(
            (out_dir / "lpm-checkout-gate.json").read_text(encoding="utf-8")
        )
        assert rollup["status"] == "blocked"
        assert rollup["failures"] == []
        assert len(rollup["results"]) == 2
        assert all(item["status"] == "blocked" for item in rollup["results"])
        assert all(item["failures"] == [] for item in rollup["results"])
        assert all(
            item["manual_completion"]["blocker_code"]
            == "manual_payment_authorization_required"
            for item in rollup["results"]
        )
        assert {
            (detail["role"], detail["method"], detail["code"])
            for detail in rollup["blocker_details"]
        } == {
            ("reference", "klarna", "manual_payment_authorization_required"),
            ("target", "klarna", "manual_payment_authorization_required"),
        }
        assert not parity_invocations_path.exists()


def test_gate_rejects_browser_selected_gateway_when_persisted_order_uses_base_card() -> None:
    with tempfile.TemporaryDirectory(prefix="lpm-gate-test-") as tmp:
        tmp_path = Path(tmp)
        ref_wp = tmp_path / "reference" / "wp"
        target_wp = tmp_path / "target" / "wp"
        fake_playwriter = tmp_path / "fake-playwriter"
        fake_parity = tmp_path / "fake-parity-diff"
        parity_invocations_path = tmp_path / "parity-invocations.jsonl"
        out_dir = tmp_path / "evidence"

        make_fake_wp(
            ref_wp,
            "http://localhost:8082",
            enrich_order_evidence=True,
            enriched_order_payment_method="woocommerce_payments",
        )
        make_fake_wp(
            target_wp,
            "http://store8889.localhost:8889",
            enrich_order_evidence=True,
            enriched_order_payment_method="woocommerce_payments",
        )
        make_fake_playwriter(fake_playwriter)
        make_fake_parity_diff(fake_parity, parity_invocations_path)

        env = {
            **os.environ,
            "PLAYWRITER_BIN": str(fake_playwriter),
            "FAKE_PLAYWRITER_INVOCATIONS": str(tmp_path / "playwriter-invocations.jsonl"),
            "LPM_PARITY_DIFF_BIN": str(fake_parity),
        }

        result = run_gate(
            "--methods",
            "ideal",
            "--ref",
            str(ref_wp),
            "--target",
            str(target_wp),
            "--playwriter-session",
            "unit",
            "--out-dir",
            str(out_dir),
            env=env,
        )

        assert result.returncode == 1
        assert "persisted order_payment_method mismatch" in result.stderr
        rollup = json.loads((out_dir / "lpm-checkout-gate.json").read_text(encoding="utf-8"))
        assert rollup["status"] == "fail"


def test_gate_rejects_customer_action_evidence_without_order_received_url() -> None:
    with tempfile.TemporaryDirectory(prefix="lpm-gate-test-") as tmp:
        tmp_path = Path(tmp)
        ref_wp = tmp_path / "reference" / "wp"
        target_wp = tmp_path / "target" / "wp"
        fake_playwriter = tmp_path / "fake-playwriter"
        fake_parity = tmp_path / "fake-parity-diff"
        parity_invocations_path = tmp_path / "parity-invocations.jsonl"
        out_dir = tmp_path / "evidence"

        make_fake_wp(ref_wp, "http://localhost:8082", enrich_order_evidence=True)
        make_fake_wp(target_wp, "http://store8889.localhost:8889", enrich_order_evidence=True)
        make_fake_playwriter(fake_playwriter, omit_order_received_url=True)
        make_fake_parity_diff(fake_parity, parity_invocations_path)

        env = {
            **os.environ,
            "PLAYWRITER_BIN": str(fake_playwriter),
            "FAKE_PLAYWRITER_INVOCATIONS": str(tmp_path / "playwriter-invocations.jsonl"),
            "LPM_PARITY_DIFF_BIN": str(fake_parity),
        }

        result = run_gate(
            "--methods",
            "wechat_pay",
            "--ref",
            str(ref_wp),
            "--target",
            str(target_wp),
            "--playwriter-session",
            "unit",
            "--out-dir",
            str(out_dir),
            env=env,
        )

        assert result.returncode == 1
        assert "missing order_received_url" in result.stderr
        rollup = json.loads((out_dir / "lpm-checkout-gate.json").read_text(encoding="utf-8"))
        assert rollup["status"] == "fail"
        assert all(item["order_id"] == 1001 for item in rollup["results"])
        assert all(item["order_received_url"] == "" for item in rollup["results"])
        assert not parity_invocations_path.exists()


def test_gate_rejects_hosted_action_evidence_without_order_received_url() -> None:
    with tempfile.TemporaryDirectory(prefix="lpm-gate-test-") as tmp:
        tmp_path = Path(tmp)
        ref_wp = tmp_path / "reference" / "wp"
        target_wp = tmp_path / "target" / "wp"
        fake_playwriter = tmp_path / "fake-playwriter"
        fake_parity = tmp_path / "fake-parity-diff"
        parity_invocations_path = tmp_path / "parity-invocations.jsonl"
        out_dir = tmp_path / "evidence"

        make_fake_wp(ref_wp, "http://localhost:8082", enrich_order_evidence=True)
        make_fake_wp(target_wp, "http://store8889.localhost:8889", enrich_order_evidence=True)
        make_fake_playwriter(fake_playwriter, omit_order_received_url=True)
        make_fake_parity_diff(fake_parity, parity_invocations_path)

        env = {
            **os.environ,
            "PLAYWRITER_BIN": str(fake_playwriter),
            "FAKE_PLAYWRITER_INVOCATIONS": str(tmp_path / "playwriter-invocations.jsonl"),
            "LPM_PARITY_DIFF_BIN": str(fake_parity),
        }

        result = run_gate(
            "--methods",
            "klarna",
            "--ref",
            str(ref_wp),
            "--target",
            str(target_wp),
            "--playwriter-session",
            "unit",
            "--out-dir",
            str(out_dir),
            env=env,
        )

        assert result.returncode == 1
        assert "missing order_received_url" in result.stderr
        rollup = json.loads((out_dir / "lpm-checkout-gate.json").read_text(encoding="utf-8"))
        assert rollup["status"] == "fail"
        assert all(item["method_family"] == "hosted_action" for item in rollup["results"])
        assert all(item["order_id"] == 1001 for item in rollup["results"])
        assert all(item["order_received_url"] == "" for item in rollup["results"])
        assert not parity_invocations_path.exists()


def test_gate_fails_when_driver_evidence_omits_required_order_fields() -> None:
    with tempfile.TemporaryDirectory(prefix="lpm-gate-test-") as tmp:
        tmp_path = Path(tmp)
        ref_wp = tmp_path / "reference" / "wp"
        target_wp = tmp_path / "target" / "wp"
        fake_playwriter = tmp_path / "fake-playwriter"
        out_dir = tmp_path / "evidence"

        make_fake_wp(ref_wp, "http://localhost:8082")
        make_fake_wp(target_wp, "http://store8889.localhost:8889")
        make_fake_playwriter(fake_playwriter, omit_order_id=True)

        env = {
            **os.environ,
            "PLAYWRITER_BIN": str(fake_playwriter),
            "FAKE_PLAYWRITER_INVOCATIONS": str(tmp_path / "playwriter-invocations.jsonl"),
        }

        result = run_gate(
            "--methods",
            "ideal",
            "--ref",
            str(ref_wp),
            "--target",
            str(target_wp),
            "--playwriter-session",
            "unit",
            "--out-dir",
            str(out_dir),
            env=env,
        )

        assert result.returncode == 1
        assert "missing order_id" in result.stderr
        rollup = json.loads((out_dir / "lpm-checkout-gate.json").read_text(encoding="utf-8"))
        assert rollup["status"] == "fail"
        assert any("missing order_id" in failure for failure in rollup["failures"])


def test_gate_requires_semantic_lpm_checkout_evidence() -> None:
    with tempfile.TemporaryDirectory(prefix="lpm-gate-test-") as tmp:
        tmp_path = Path(tmp)
        ref_wp = tmp_path / "reference" / "wp"
        target_wp = tmp_path / "target" / "wp"
        fake_playwriter = tmp_path / "fake-playwriter"
        out_dir = tmp_path / "evidence"

        make_fake_wp(ref_wp, "http://localhost:8082")
        make_fake_wp(target_wp, "http://store8889.localhost:8889")
        make_fake_playwriter(fake_playwriter, omit_semantic_fields=True)

        env = {
            **os.environ,
            "PLAYWRITER_BIN": str(fake_playwriter),
            "FAKE_PLAYWRITER_INVOCATIONS": str(tmp_path / "playwriter-invocations.jsonl"),
        }

        result = run_gate(
            "--methods",
            "ideal",
            "--ref",
            str(ref_wp),
            "--target",
            str(target_wp),
            "--playwriter-session",
            "unit",
            "--out-dir",
            str(out_dir),
            env=env,
        )

        assert result.returncode == 1
        assert "missing selected_gateway_id" in result.stderr
        assert "missing order_payment_method" in result.stderr
        assert "missing order_received_url" in result.stderr
        assert "missing payment_intent_id" in result.stderr
        rollup = json.loads((out_dir / "lpm-checkout-gate.json").read_text(encoding="utf-8"))
        assert rollup["status"] == "fail"


def test_gate_rejects_base_card_gateway_fallback_evidence() -> None:
    with tempfile.TemporaryDirectory(prefix="lpm-gate-test-") as tmp:
        tmp_path = Path(tmp)
        ref_wp = tmp_path / "reference" / "wp"
        target_wp = tmp_path / "target" / "wp"
        fake_playwriter = tmp_path / "fake-playwriter"
        out_dir = tmp_path / "evidence"

        make_fake_wp(ref_wp, "http://localhost:8082")
        make_fake_wp(target_wp, "http://store8889.localhost:8889")
        make_fake_playwriter(fake_playwriter, use_base_card_gateway=True)

        env = {
            **os.environ,
            "PLAYWRITER_BIN": str(fake_playwriter),
            "FAKE_PLAYWRITER_INVOCATIONS": str(tmp_path / "playwriter-invocations.jsonl"),
        }

        result = run_gate(
            "--methods",
            "ideal",
            "--ref",
            str(ref_wp),
            "--target",
            str(target_wp),
            "--playwriter-session",
            "unit",
            "--out-dir",
            str(out_dir),
            env=env,
        )

        assert result.returncode == 1
        assert "order_payment_method mismatch" in result.stderr
        assert "used_base_card_gateway must be false" in result.stderr
        rollup = json.loads((out_dir / "lpm-checkout-gate.json").read_text(encoding="utf-8"))
        assert rollup["status"] == "fail"


def test_real_browser_driver_is_not_the_incomplete_capture_stub() -> None:
    source = BROWSER_DRIVER.read_text(encoding="utf-8")

    assert "Submit-capable LPM checkout flow is not implemented yet" not in source
    assert "status: 'incomplete'" not in source
    assert "async function selectPaymentMethod" in source
    assert "async function submitCheckout" in source
    assert "async function approveExternalAuthorization" in source
    assert "async function extractOrderEvidence" in source
    assert "async function waitForPaymentElementReady" in source
    assert "selected_gateway_id:" in source
    assert "order_payment_method:" in source
    assert "used_base_card_gateway:" in source
    assert "state.lpmCheckoutConfig" in source
    assert "addToCartUrl" in source
    assert "async function gotoLocalPage" in source
    assert "gateway_html_sample" in source
    assert "frame_diagnostics" in source
    assert "input_diagnostics" in source
    assert "data-elements-stable-field-name" in source
    assert "requestfailed" in source
    assert "failed_requests" in source
    assert "stripe_runtime" in source
    assert "checkout_config" in source
    assert "payment_methods_config_keys" in source
    assert "selected_method_config_present" in source


def test_browser_driver_extracts_query_order_received_urls_and_defers_intent_enrichment() -> None:
    source = BROWSER_DRIVER.read_text(encoding="utf-8")

    assert "function orderIdFromOrderReceivedUrl" in source
    assert "searchParams.get( 'order-received' )" in source
    assert "PaymentIntent id was not observed in checkout responses or page state" not in source


def test_browser_driver_requires_completed_order_received_for_all_method_families() -> None:
    source = BROWSER_DRIVER.read_text(encoding="utf-8")

    assert "const pendingActionMethodFamilies = new Set" not in source
    assert "acceptsPendingActionEvidence" not in source
    assert "if ( ! reachedOrderReceived )" in source
    assert "checkout did not reach an order-received URL with an order id" in source


def test_debit_browser_filler_does_not_fill_every_visible_input_for_sepa() -> None:
    source = BROWSER_DRIVER.read_text(encoding="utf-8")

    assert "const inputType = await input.getAttribute( 'type' )" in source
    assert "/^(checkbox|radio|hidden|submit|button|reset)$/i.test( inputType || '' )" in source
    assert "const isStripeFrame =" in source
    assert "const isSelectedGatewayInput =" in source
    assert "if ( ! isStripeFrame && ! isSelectedGatewayInput )" in source
    assert "method === 'sepa_debit' && isStripeFrame" in source
    assert "await waitForPaymentElementReady( page )" in source


def test_payment_element_option_selector_is_gateway_scoped() -> None:
    source = BROWSER_DRIVER.read_text(encoding="utf-8")

    assert "async function selectPaymentElementOptions( page )" in source
    assert "const isSelectedGatewaySelect = await select.evaluate" in source
    assert "if ( ! isStripeFrame && ! isSelectedGatewaySelect )" in source
    assert "const paymentElement = await waitForPaymentElementReady( page )" in source
    assert "const selectedOptions = await selectPaymentElementOptions( page )" in source


def test_browser_driver_starts_checkout_from_guest_empty_cart_session() -> None:
    source = BROWSER_DRIVER.read_text(encoding="utf-8")

    assert "async function clearLocalCheckoutSession( page )" in source
    assert "wp_woocommerce_session_" in source
    assert "woocommerce_cart_hash" in source
    assert "woocommerce_items_in_cart" in source
    assert "function isLocalWordPressAuthCookie( name )" in source
    assert "wordpress_logged_in_" in source
    assert "wordpress_sec_" in source
    assert "name === 'wordpress_test_cookie'" in source
    assert "remainingLocalCheckoutCookies" in source
    assert "getCDPSession( { page } )" in source
    assert "Network.getCookies" in source
    assert "url: baseUrl" in source
    assert "Network.deleteCookies" in source
    assert "clearCookies" not in source
    assert "await clearLocalCheckoutSession( page )" in source
    assert source.index("await clearLocalCheckoutSession( page )") < source.index(
        "await resetCartToSingleGateProduct( page )"
    )


def test_browser_driver_clears_store_api_cart_storage_before_add_to_cart() -> None:
    source = BROWSER_DRIVER.read_text(encoding="utf-8")

    assert "function isWooCommerceStoreApiCartStorageKey( name )" in source
    assert "storeApiCartData" in source
    assert "storeApiCartHash" in source
    assert "storeApiNonce" in source
    assert "async function clearLocalCheckoutBrowserStorage( page, options = {} )" in source
    assert "window.localStorage.removeItem( key )" in source
    assert "window.sessionStorage.removeItem( key )" in source
    assert "await clearLocalCheckoutBrowserStorage( page )" in source
    assert source.index("await clearLocalCheckoutBrowserStorage( page )") < source.index(
        "await resetCartToSingleGateProduct( page )"
    )


def test_browser_driver_asserts_single_gate_product_cart_before_submit() -> None:
    source = BROWSER_DRIVER.read_text(encoding="utf-8")

    assert "async function readGateCartState( page )" in source
    assert "async function assertSingleGateProductInCart( page )" in source
    assert "expected_product_id:" in source
    assert "Number.parseInt( productId, 10 )" in source
    assert "cart_state: cartState" in source
    assert "Checkout cart must contain exactly one LPM gate product" in source
    assert "const cartState = await assertSingleGateProductInCart( page );" in source
    assert source.index("await navigateToCheckout( page );") < source.index(
        "const cartState = await assertSingleGateProductInCart( page );"
    )
    assert source.index("const cartState = await assertSingleGateProductInCart( page );") < source.index(
        "await fillBillingFields( page );"
    )


def test_browser_driver_cart_guard_falls_back_to_checkout_body_text() -> None:
    source = BROWSER_DRIVER.read_text(encoding="utf-8")

    assert "body_gate_product_quantity" in source
    assert "body_has_gate_product" in source
    assert "body_gate_product_quantity === 1" in source
    assert "/WooPayments LPM Checkout Gate Product" in source


def test_browser_driver_cart_guard_reads_store_api_cart_when_markup_hides_items() -> None:
    source = BROWSER_DRIVER.read_text(encoding="utf-8")

    assert "/wp-json/wc/store/v1/cart" in source
    assert "/?rest_route=/wc/store/v1/cart" in source
    assert "credentials: 'include'" in source
    assert "store_api_fetch_cart" in source
    assert "store_api_fetch_cart.items_count === 1" in source
    assert "store_api_fetch_cart.matching_product_quantity === 1" in source


def test_browser_driver_resets_store_api_cart_to_single_gate_product() -> None:
    source = BROWSER_DRIVER.read_text(encoding="utf-8")

    assert "async function resetCartToSingleGateProduct( page )" in source
    assert "cart/add-item" in source
    assert "cart/items/" in source
    assert "method: 'DELETE'" in source
    assert "method: 'POST'" in source
    assert "await resetCartToSingleGateProduct( page )" in source
    assert source.index("await clearLocalCheckoutSession( page )") < source.index(
        "await resetCartToSingleGateProduct( page )"
    )
    assert source.index("await resetCartToSingleGateProduct( page )") < source.index(
        "const cartState = await assertSingleGateProductInCart( page );"
    )


def test_browser_driver_does_not_treat_cart_state_probe_as_checkout_failure() -> None:
    source = BROWSER_DRIVER.read_text(encoding="utf-8")

    assert "function isCartStateProbeResponse( url )" in source
    assert "/wc/store/v1/cart" in source
    assert "rest_route=/wc/store/v1/cart" in source
    assert "if ( isCartStateProbeResponse( url ) )" in source
    assert source.index("if ( isCartStateProbeResponse( url ) )") < source.index(
        "const shouldCaptureFailure = status >= 400"
    )


def test_browser_driver_records_sanitized_checkout_response_summaries() -> None:
    source = BROWSER_DRIVER.read_text(encoding="utf-8")

    assert "function redactSensitiveText" in source
    assert "function summarizeCheckoutResponse" in source
    assert "function isCheckoutAjaxResponse" in source
    assert "wc-ajax=checkout" in source
    assert "messages:" in source
    assert "redirect:" in source
    assert "body_sample:" in source
    assert "pi_[redacted]_secret_[redacted]" in source
    assert "checkoutResponses" in source
    assert "checkout_responses" in source
    assert "function installResponseCapture(" in source
    assert "responseBodyTimeoutMs = 5000" in source
    assert "shouldCaptureFailure" in source
    assert "failedResponses.push( {" in source
    assert "pendingResponseCaptures" in source
    assert "settlePendingResponseCaptures" in source
    assert "await settlePendingResponseCaptures( pendingResponseCaptures );" in source


def test_browser_driver_bounds_unavailable_response_body_capture() -> None:
    source = BROWSER_DRIVER.read_text(encoding="utf-8")
    capture_source = source[
        source.index("function installResponseCapture")
        : source.index("function installCheckoutRequestCapture")
    ]
    script = f"""
function isCartStateProbeResponse() {{ return false; }}
function isCheckoutAjaxResponse() {{ return false; }}
function redactSensitiveText( value ) {{ return value; }}
function plainTextSample( value ) {{ return value; }}
function intentIdsFromText() {{ return []; }}
{capture_source}

const handlers = {{}};
const page = {{ on: ( event, handler ) => {{ handlers[ event ] = handler; }} }};
const pending = new Set();
installResponseCapture( page, new Set(), [], [], pending, 5 );
handlers.response( {{
    status: () => 200,
    url: () => 'http://store.test/checkout/order-received/123/',
    text: () => new Promise( () => {{}} ),
}} );

const guard = setTimeout( () => process.exit( 7 ), 250 );
( async () => {{
    await settlePendingResponseCaptures( pending );
    clearTimeout( guard );
    process.stdout.write( JSON.stringify( {{ pending: pending.size }} ) );
}} )();
"""

    result = subprocess.run(
        ["node", "-e", script],
        cwd=REPO,
        text=True,
        stdout=subprocess.PIPE,
        stderr=subprocess.PIPE,
        check=False,
    )

    assert result.returncode == 0, result.stderr
    assert json.loads(result.stdout) == {"pending": 0}


def test_browser_driver_records_sanitized_checkout_request_summaries() -> None:
    source = BROWSER_DRIVER.read_text(encoding="utf-8")

    assert "function summarizeCheckoutCredential" in source
    assert "function summarizeCheckoutRequest" in source
    assert "function installCheckoutRequestCapture" in source
    assert "request.postData()" in source
    assert "new URLSearchParams" in source
    assert "'wcpay-payment-method'" in source
    assert "'wcpay-confirmation-token'" in source
    assert "'wcpay-payment-method-sepa'" in source
    assert "credential_prefix" in source
    assert "checkoutRequests" in source
    assert "checkout_requests" in source


def test_browser_driver_redacts_nested_sensitive_evidence_at_write_boundary() -> None:
    source = BROWSER_DRIVER.read_text(encoding="utf-8")
    text_start = source.index("function redactSensitiveText")
    value_start = source.index("function redactSensitiveValue")
    value_end = source.index("function plainTextSample")
    redaction_source = source[text_start:value_start] + source[value_start:value_end]
    payload = {
        "url": "http://store.local/?token=klarna-session-token#wcpay-confirm-pi:1:pi_unit_secret_topsecret:nonce",
        "nested": [
            {
                "frame_url": "https://example.test/?client_secret=pi_unit_secret_nestedsecret"
            },
            {
                "encoded_frame_url": "https://example.test/#return%3Api_unit_secret_encodedsecret%3Anonce"
            },
        ],
    }
    result = subprocess.run(
        [
            "node",
            "-e",
            redaction_source
            + "\nprocess.stdout.write(JSON.stringify(redactSensitiveValue("
            + json.dumps(payload)
            + ")));",
        ],
        cwd=REPO,
        text=True,
        stdout=subprocess.PIPE,
        stderr=subprocess.PIPE,
        check=False,
    )

    assert result.returncode == 0, result.stderr
    serialized = result.stdout
    assert "pi_unit_secret_" not in serialized
    assert "topsecret" not in serialized
    assert "nestedsecret" not in serialized
    assert "encodedsecret" not in serialized
    assert "klarna-session-token" not in serialized
    assert "[redacted]" in serialized
    write_boundary = source[
        source.index("function writeEvidence") : source.index("function logText")
    ]
    assert "redactSensitiveValue" in write_boundary


def test_browser_driver_waits_for_checkout_progress_after_submit() -> None:
    source = BROWSER_DRIVER.read_text(encoding="utf-8")

    assert "function hasCheckoutAdvanced( page )" in source
    assert "async function waitForCheckoutProgress( page, checkoutRequests, checkoutResponses" in source
    assert "checkoutResponses.length > 0" in source
    assert "checkoutRequests.length > 0" in source
    assert "await submitCheckout( page, checkoutRequests, checkoutResponses );" in source
    submit_checkout_source = source[
        source.index("async function submitCheckout")
        : source.index("function orderIdFromOrderReceivedUrl")
    ]
    assert "waitForCheckoutProgress( page, checkoutRequests, checkoutResponses" in submit_checkout_source
    assert "waitForLoadState( 'networkidle'" not in submit_checkout_source


def test_browser_driver_billing_helpers_are_field_type_aware() -> None:
    source = BROWSER_DRIVER.read_text(encoding="utf-8")

    assert "async function fillTextInputValue( locator, value" in source
    assert "inputValue()" in source
    assert "dispatchEvent( new Event( 'input'" in source
    assert "async function firstExistingLocator( roots, selectors )" in source
    assert "const isFillable = await candidate.locator.evaluate" in source
    assert "tagName === 'input' || tagName === 'textarea' || element.isContentEditable" in source
    assert "( await firstVisibleLocator( roots, selectors ) ) || ( await firstExistingLocator( roots, selectors ) )" in source


def test_browser_driver_handles_klarna_playground_authorization() -> None:
    source = BROWSER_DRIVER.read_text(encoding="utf-8")

    assert "const klarnaTestPhoneByCountry" in source
    assert "US: '+13106683312'" in source
    assert "/klarna\\.com/i.test( currentUrl )" in source
    assert "filled_klarna_test_phone" in source
    assert "filled_klarna_test_code" in source
    assert "const hasSubmittedKlarnaPhone" in source
    assert "const rootTexts = await Promise.all" in source
    assert "/Enter the 6-digit code|Enter code/i.test( text )" in source
    assert "'123456'" in source
    assert "pressSequentially( value" in source
    assert "value_set: codeValueSet" in source
    assert "press( 'Enter'" in source
    assert "submitted_klarna_test_code" in source
    assert "'[data-testid=\"kaf-button\"]'" in source


def test_browser_driver_waits_for_klarna_ready_control_and_store_return() -> None:
    source = BROWSER_DRIVER.read_text(encoding="utf-8")

    assert "async function firstActionableLocator( roots, selectors )" in source
    assert "const matches = await root.locator( selector ).all()" in source
    assert "await locator.scrollIntoViewIfNeeded" in source
    assert "closest( 'button,a,[role=\"button\"]" in source
    assert "document.elementFromPoint" in source
    assert "! is_topmost" in source
    assert "const candidate = await firstActionableLocator( roots, selectors )" in source
    assert "async function firstReadyKlarnaAuthorizationControl( roots )" in source
    assert "async function waitForKlarnaAuthorizationReturn( page, timeout = 90000, previousUrl = '', previousText = '' )" in source
    assert "advanced: true" in source
    assert "currentText !== previousText" in source
    assert "'[data-testid=\"offers-selector-continue-button\"]'" in source
    assert "'[data-testid=\"confirm-and-pay\"]'" in source
    assert "/loading/i.test( text )" in source
    assert "aria-disabled" in source
    assert "clicked_klarna_authorization_control" in source
    assert "waitForKlarnaAuthorizationReturn( page," in source
    assert "rootTexts.join( '\\n' )" in source
    assert "clicked_authorization_control" in source
    assert source.index("if ( /klarna\\.com/i.test( currentUrl ) )") < source.index(
        "if ( await maybeClick( roots, approvalSelectors, 15000 ) )"
    )


def test_browser_driver_retries_gateway_selection_while_checkout_overlay_clears() -> None:
    source = BROWSER_DRIVER.read_text(encoding="utf-8")

    assert "for ( let attempt = 0; attempt < 3; attempt++ )" in source
    assert "'.blockUI.blockOverlay'" in source
    assert "const selectedByScript = await page.evaluate" in source
    assert "input.dispatchEvent( new Event( 'change', { bubbles: true } ) )" in source


def test_lpm_fixture_stages_split_gateway_settings_option() -> None:
    source = PAYMENT_METHOD_FIXTURE_STATE.read_text(encoding="utf-8")

    assert "$split_settings_option = 'woocommerce_woocommerce_payments_'" in source
    assert "function woopayments_merge_payment_method_fixture_split_method_ids" in source
    assert "'amazon_pay'" in source
    assert "$previous_split_settings_map" in source
    assert "$split_settings['enabled']" in source
    assert "$split_settings['enabled'] = 'no'" in source
    assert "$split_settings['upe_enabled_payment_method_ids']" in source
    assert "$staged_split_settings" in source
    assert "foreach ( $staged_split_settings as $option_name => $split_settings )" in source
    assert "'split_settings_exists'" in source
    assert "'split_settings_map'" in source and "$previous_split_settings_map" in source
    assert "'isolated_split_method_ids'" in source and "$split_method_ids" in source
    assert "foreach ( $previous['split_settings_map'] as $split_settings_option => $entry )" in source
    assert "delete_option( $split_settings_option )" in source
    assert "woopayments_merge_payment_method_fixture_snapshot_options" in source
    assert "woopayments_merge_payment_method_fixture_restore_options" in source
    assert "'option_snapshot'" in source
    assert "'option_value_b64'" in source
    assert "'autoload'" in source
    assert "$wpdb->last_error" in source
    assert "woopayments_merge_payment_method_fixture_option_cache_contains" in source
    assert "wp_cache_get( $cache_key, 'options'" in source
    assert "'verified_count'" in source


def test_lpm_fixture_stages_account_capability_cache() -> None:
    source = PAYMENT_METHOD_FIXTURE_STATE.read_text(encoding="utf-8")

    assert "$account_option  = 'wcpay_account_data'" in source
    assert "$capability_key" in source and "$method . '_payments'" in source
    assert "$previous_account_data" in source
    assert "test_publishable_key" in source
    assert "connected WooPayments account cache" in source
    assert "$previous_capability_status" in source
    assert "$capability_status_label     = 'missing'" in source
    assert "current cached status is %2$s" in source
    assert "'capability_status'" in source and "$previous_capability_status" in source
    assert "'active' !== $previous_capability_status" in source
    assert "function woopayments_merge_payment_method_fixture_can_override_disabled_capability" in source
    assert "'disabled' === $previous_capability_status" in source
    assert "'sepa_debit' !== $method" in source
    assert "$account_data['capability_requirements'][ $capability_key ]" in source
    assert "$account_data['fees'][ $method ]" in source
    assert "'capability_fixture_override'" in source
    assert "acct_lpm_fixture" not in source
    assert "$account_data['country']" in source and "= $country" in source
    assert "$account_data['capabilities'][ $capability_key ]" in source and "= 'active'" in source
    assert "$account_data['store_currencies']['default']" in source and "strtolower( $currency )" in source
    assert "'account_cache_exists'" in source
    assert "delete_option( $account_option )" in source


def test_lpm_gate_uses_shared_payment_method_fixture_driver() -> None:
    source = SCRIPT.read_text(encoding="utf-8")

    assert 'PAYMENT_METHOD_FIXTURE_STATE="$SELF_DIR/payment-method-fixture-state.php"' in source
    assert '< "$PAYMENT_METHOD_FIXTURE_STATE"' in source
    assert "snapshot-lpm-fixture" in source
    assert "stage-lpm-fixture" in source
    assert "restore-lpm-fixture" in source


def main() -> None:
    tests = [
        value
        for name, value in globals().items()
        if name.startswith("test_") and callable(value)
    ]
    for test in tests:
        test()
        print(f"PASS {test.__name__}")


if __name__ == "__main__":
    main()
