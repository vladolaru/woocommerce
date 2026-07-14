#!/usr/bin/env python3
"""Regression checks for verify.sh full-evidence orchestration."""

from __future__ import annotations

import importlib.util
import json
import os
import re
import shlex
import shutil
import subprocess
import sys
import tempfile
from pathlib import Path
from types import SimpleNamespace


REPO = Path(__file__).resolve().parents[2]
SCRIPT = REPO / "tools/woopayments-merge/verify.sh"
LOCAL_RUNNER_SAFETY = REPO / "tools/woopayments-merge/local-runner-safety.sh"
OWNED_ORDER_CLEANUP = REPO / "tools/woopayments-merge/verify-owned-order-cleanup.php"
TIMEOUT_RUNNER = REPO / "tools/woopayments-merge/run-command-with-timeout.py"
MANUAL_EVIDENCE_CLASSIFIER = REPO / "tools/woopayments-merge/manual-evidence-classifier.py"
LPM_EVIDENCE = REPO / "tools/woopayments-merge/lpm_evidence.py"
A5G_SCRIPT = REPO / "tools/woopayments-merge/a5g-multisite-runtime-gate.py"
COMPARE_SCRIPT = REPO / "tools/woopayments-merge/compare-measured-gates.py"
REF_WP = "docker exec -i wcpay_wp_default wp --allow-root"
TARGET_WP = "docker exec -i target-cli-1 wp --allow-root --user=1"
ALL_LPM_METHODS = "sepa_debit,ideal,bancontact,klarna,affirm,afterpay_clearpay,eps,p24,multibanco,au_becs_debit,grabpay,wechat_pay,alipay"
FIXTURE_REF_CONTAINER = "verified-reference-wp"
FIXTURE_TARGET_CONTAINER = "verified-target-cli-1"
FIXTURE_REF_PROJECT = "verified-reference-project"
FIXTURE_TARGET_PROJECT = "verified-target-project"


def test_verifier_propagates_the_validated_runner_and_pinned_source_contracts() -> None:
    source = SCRIPT.read_text(encoding="utf-8")

    assert 'WCPAY_SOURCE_ROOT="${WCPAY_EXTENSION_ROOT:-$WCPAY_REPO}"' in source
    assert 'env WCPAY_SRC="$WCPAY_SOURCE_ROOT" WCPAY_SOURCE_REF="${WCPAY_EXTENSION_REF:-10.8.0}" bash "$SELF_DIR/bc-drift-gate.sh"' in source
    assert 'WOOPAYMENTS_APPROVED_REF_CONTAINER="$container"' in source
    assert 'WOOPAYMENTS_APPROVED_TARGET_CONTAINER="$container"' in source
    assert "export WOOPAYMENTS_APPROVED_REF_CONTAINER WOOPAYMENTS_APPROVED_TARGET_CONTAINER" in source


def test_narrow_perf_smoke_uses_the_bootstrap_probe_for_both_roles() -> None:
    source = SCRIPT.read_text(encoding="utf-8")

    assert 'perf-surface-gate.sh" capture --wp "$REF_WP"' in source
    assert 'perf-surface-gate.sh" capture --wp "$TARGET_WP"' in source
    assert 'perf-surface-gate.sh" compare --ref' in source
    assert '--gateway-initialization-only' in source
    assert 'perf-baseline.sh" check' not in source


def test_full_perf_compare_requires_two_successful_nonempty_captures() -> None:
    source = SCRIPT.read_text(encoding="utf-8")
    function_source = source[
        source.index("run_perf_surface_evidence()") : source.index(
            "run_full_evidence_gates()"
        )
    ]

    assert 'ref_capture_ok=0' in function_source
    assert 'target_capture_ok=0' in function_source
    assert '[ -s "$ref_perf" ]' in function_source
    assert '[ -s "$target_perf" ]' in function_source
    assert 'record "perf surface compare" BLOCKED' in function_source


def assert_occurs_in_order(haystack: str, markers: list[str]) -> None:
    position = -1
    for marker in markers:
        next_position = haystack.find(marker, position + 1)
        assert next_position != -1, f"{marker!r} was not found after offset {position}"
        position = next_position


def run_verify(*args: str) -> subprocess.CompletedProcess[str]:
    return subprocess.run(
        ["bash", str(SCRIPT), *args],
        cwd=REPO,
        text=True,
        stdout=subprocess.PIPE,
        stderr=subprocess.PIPE,
        check=False,
    )


def write_executable(path: Path, source: str) -> None:
    path.write_text(source, encoding="utf-8")
    path.chmod(0o755)


def write_verify_fixture(merge_dir: Path) -> Path:
    verify_copy = merge_dir / "verify.sh"
    write_executable(verify_copy, SCRIPT.read_text(encoding="utf-8"))
    (merge_dir / "local-runner-safety.sh").write_text(
        LOCAL_RUNNER_SAFETY.read_text(encoding="utf-8"),
        encoding="utf-8",
    )
    (merge_dir / "verify-owned-order-cleanup.php").write_text(
        OWNED_ORDER_CLEANUP.read_text(encoding="utf-8"),
        encoding="utf-8",
    )
    (merge_dir / "run-command-with-timeout.py").write_text(
        TIMEOUT_RUNNER.read_text(encoding="utf-8"),
        encoding="utf-8",
    )
    (merge_dir / "manual-evidence-classifier.py").write_text(
        MANUAL_EVIDENCE_CLASSIFIER.read_text(encoding="utf-8"),
        encoding="utf-8",
    )
    (merge_dir / "lpm_evidence.py").write_text(
        LPM_EVIDENCE.read_text(encoding="utf-8"),
        encoding="utf-8",
    )
    return verify_copy


def test_manual_evidence_classifier_is_a_standalone_verifier_dependency() -> None:
    source = SCRIPT.read_text(encoding="utf-8")
    classifier = MANUAL_EVIDENCE_CLASSIFIER.read_text(encoding="utf-8")

    assert 'python3 "$SELF_DIR/manual-evidence-classifier.py"' in source
    assert "def accept_lpm_all_methods()" not in source
    assert "def accept_lpm_all_methods()" in classifier


def write_runtime_identity_wp(path: Path, *, owner: str, site_url: str) -> None:
    write_executable(
        path,
        f'''#!/usr/bin/env bash
set -eu
if [ "${{1:-}}" = "eval-file" ]; then
    body="$(cat)"
    if printf '%s' "$body" | grep -q 'WCPAY_RUNTIME_IDENTITY'; then
        printf '%s\n' 'WCPAY_RUNTIME_IDENTITY:{{"runtime_owner":"{owner}","site_url":"{site_url}"}}'
        exit 0
    fi
fi
printf '{{}}\n'
''',
    )


def write_runtime_identity_wp_wrapper(
    path: Path, *, delegate: Path, owner: str, site_url: str
) -> None:
    path.parent.mkdir(parents=True, exist_ok=True)
    delegate_command = shlex.quote(str(delegate))
    write_executable(
        path,
        f'''#!/usr/bin/env bash
set -eu
if [ "${{1:-}}" = "eval-file" ]; then
    body="$(cat)"
    if printf '%s' "$body" | grep -q 'WCPAY_RUNTIME_IDENTITY'; then
        printf '%s\n' 'WCPAY_RUNTIME_IDENTITY:{{"runtime_owner":"{owner}","site_url":"{site_url}"}}'
        exit 0
    fi
    if printf '%s' "$body" | grep -q 'WCPAY_VERIFY_OWNED_ORDER_CLEANUP'; then
        if [ "${{CLEANUP_FAIL_OWNER:-}}" = "{owner}" ]; then
            printf '%s\n' 'WCPAY_VERIFY_OWNED_ORDER_CLEANUP:{{"success":false,"results":[{{"status":"delete_failed"}}]}}'
            exit 1
        fi
        printf '%s\n' 'WCPAY_VERIFY_OWNED_ORDER_CLEANUP:{{"success":true}}'
        exit 0
    fi
    printf '%s' "$body" | {delegate_command} "$@"
    exit $?
fi
exec {delegate_command} "$@"
''',
    )


def write_approved_docker_fixture(
    bin_dir: Path,
    *,
    ref_wp: Path,
    target_wp: Path,
) -> list[str]:
    bin_dir.mkdir(parents=True, exist_ok=True)
    ref_delegate = shlex.quote(str(ref_wp))
    target_delegate = shlex.quote(str(target_wp))
    write_executable(
        bin_dir / "docker",
        f'''#!/usr/bin/env bash
set -eu

if [ "${{1:-}} ${{2:-}}" = "context show" ]; then
    printf 'default\n'
    exit 0
fi
if [ "${{1:-}} ${{2:-}}" = "context inspect" ]; then
    printf '%s\n' '[{{"Endpoints":{{"docker":{{"Host":"unix:///fake/docker.sock"}}}}}}]'
    exit 0
fi
if [ "${{1:-}} ${{2:-}}" = "inspect --format" ]; then
    format="${{3:-}}"
    container="${{4:-}}"
    case "$format" in
        *com.docker.compose.project*)
            case "$container" in
                {FIXTURE_REF_CONTAINER}) printf '%s\n' {shlex.quote(FIXTURE_REF_PROJECT)} ;;
                {FIXTURE_TARGET_CONTAINER}) printf '%s\n' {shlex.quote(FIXTURE_TARGET_PROJECT)} ;;
                *) exit 1 ;;
            esac
            ;;
        *NetworkSettings.Ports*) printf '%s\n' '{{}}' ;;
        *) exit 1 ;;
    esac
    exit 0
fi
if [ "${{1:-}}" != "exec" ]; then
    exit 2
fi

shift
while [ "${{1:-}}" = "-i" ] || [ "${{1:-}}" = "--interactive" ]; do
    shift
done
container="${{1:-}}"
shift
if [ "${{1:-}}" != "wp" ]; then
    exit 2
fi
shift
while [ "${{1:-}}" = "--allow-root" ] || [[ "${{1:-}}" == --user=* ]]; do
    shift
done

case "$container" in
    {FIXTURE_REF_CONTAINER}) exec {ref_delegate} "$@" ;;
    {FIXTURE_TARGET_CONTAINER}) exec {target_delegate} "$@" ;;
    *) exit 1 ;;
esac
''',
    )

    return [
        "--ref",
        f"docker exec -i {FIXTURE_REF_CONTAINER} wp --allow-root --user=1",
        "--target",
        f"docker exec -i {FIXTURE_TARGET_CONTAINER} wp --allow-root --user=1",
        "--ref-compose-project",
        FIXTURE_REF_PROJECT,
        "--target-compose-project",
        FIXTURE_TARGET_PROJECT,
    ]


def test_verify_rejects_unsafe_runner_before_printing_execution_plan() -> None:
    result = run_verify(
        "--ref",
        REF_WP + " --ssh=merchant@example.test",
        "--target",
        TARGET_WP,
        "--print-full-evidence-plan",
    )

    assert result.returncode == 2
    assert "unsafe reference WP runner" in result.stderr
    assert result.stdout == ""


def test_compose_project_validation_rejects_a_runner_from_another_project(tmp_path: Path) -> None:
    fake_bin = tmp_path / "bin"
    fake_bin.mkdir()
    docker = fake_bin / "docker"
    write_executable(
        docker,
        '''#!/usr/bin/env bash
set -eu
case "${1:-} ${2:-}" in
    "context show") printf 'default\n' ;;
    "context inspect") printf '[{"Endpoints":{"docker":{"Host":"unix:///fake/docker.sock"}}}]\n' ;;
    "inspect --format") printf 'another-project\n' ;;
    *) exit 2 ;;
esac
''',
    )
    command = f"{docker} exec -i target-cli-1 wp --allow-root --user=1"
    result = subprocess.run(
        [
            "bash",
            "-c",
            'source "$1"; woopayments_validate_docker_compose_project "$2" expected-project',
            "bash",
            str(LOCAL_RUNNER_SAFETY),
            command,
        ],
        cwd=REPO,
        text=True,
        stdout=subprocess.PIPE,
        stderr=subprocess.PIPE,
        check=False,
    )

    assert result.returncode == 1
    assert "expected-project" in result.stdout
    assert "another-project" in result.stdout


def test_store_identity_accepts_exact_docker_published_port_alias(tmp_path: Path) -> None:
    docker = tmp_path / "docker"
    write_executable(
        docker,
        '''#!/usr/bin/env bash
set -eu
if [ "${1:-} ${2:-}" = "inspect --format" ]; then
    printf '%s\n' '{"80/tcp":[{"HostIp":"0.0.0.0","HostPort":"8082"}]}'
    exit 0
fi
exit 2
''',
    )
    runner = f"{docker} exec -i reference-wordpress wp --allow-root"
    result = subprocess.run(
        [
            "bash",
            "-c",
            '''source "$1"
woopayments_capture_local_store_identity() {
    printf 'plugin\thttp://localhost\n'
}
woopayments_validate_local_store_identity "$2" plugin http://localhost:8082
''',
            "bash",
            str(LOCAL_RUNNER_SAFETY),
            runner,
        ],
        cwd=REPO,
        text=True,
        stdout=subprocess.PIPE,
        stderr=subprocess.PIPE,
        check=False,
    )

    assert result.returncode == 0, result.stdout + result.stderr


def test_store_identity_rejects_unpublished_port_alias(tmp_path: Path) -> None:
    docker = tmp_path / "docker"
    write_executable(
        docker,
        '''#!/usr/bin/env bash
set -eu
if [ "${1:-} ${2:-}" = "inspect --format" ]; then
    printf '%s\n' '{"80/tcp":[{"HostIp":"0.0.0.0","HostPort":"8081"}]}'
    exit 0
fi
exit 2
''',
    )
    runner = f"{docker} exec -i reference-wordpress wp --allow-root"
    result = subprocess.run(
        [
            "bash",
            "-c",
            '''source "$1"
woopayments_capture_local_store_identity() {
    printf 'plugin\thttp://localhost\n'
}
woopayments_validate_local_store_identity "$2" plugin http://localhost:8082
''',
            "bash",
            str(LOCAL_RUNNER_SAFETY),
            runner,
        ],
        cwd=REPO,
        text=True,
        stdout=subprocess.PIPE,
        stderr=subprocess.PIPE,
        check=False,
    )

    assert result.returncode == 1
    assert "Observed store URL http://localhost, expected http://localhost:8082" in result.stdout


def test_verify_blocks_wrong_store_identity_before_running_gates(tmp_path: Path) -> None:
    repo = tmp_path / "repo"
    merge_dir = repo / "tools" / "woopayments-merge"
    ref_bin = tmp_path / "reference"
    target_bin = tmp_path / "target"
    merge_dir.mkdir(parents=True)
    ref_bin.mkdir()
    target_bin.mkdir()
    verify_copy = write_verify_fixture(merge_dir)
    ref_wp = ref_bin / "wp"
    target_wp = target_bin / "wp"
    write_runtime_identity_wp(ref_wp, owner="plugin", site_url="http://localhost:8082")
    write_runtime_identity_wp(target_wp, owner="native", site_url="http://localhost:8082")
    runner_bin = tmp_path / "runner-bin"
    runner_args = write_approved_docker_fixture(
        runner_bin,
        ref_wp=ref_wp,
        target_wp=target_wp,
    )

    result = subprocess.run(
        ["bash", str(verify_copy), *runner_args],
        cwd=repo,
        text=True,
        stdout=subprocess.PIPE,
        stderr=subprocess.PIPE,
        env={"PATH": f"{runner_bin}:/bin:/usr/bin:/usr/local/bin", "TMPDIR": str(tmp_path)},
        check=False,
    )

    assert result.returncode == 3
    assert "target store identity" in result.stderr
    assert "http://store8889.localhost:8889" in result.stderr
    assert "drift gate" not in result.stdout


def test_validate_scope_only_exits_before_running_gates(tmp_path: Path) -> None:
    repo = tmp_path / "repo"
    merge_dir = repo / "tools" / "woopayments-merge"
    ref_bin = tmp_path / "reference"
    target_bin = tmp_path / "target"
    gate_marker = tmp_path / "gate-ran"
    merge_dir.mkdir(parents=True)
    ref_bin.mkdir()
    target_bin.mkdir()
    verify_copy = write_verify_fixture(merge_dir)
    ref_wp = ref_bin / "wp"
    target_wp = target_bin / "wp"
    write_runtime_identity_wp(ref_wp, owner="plugin", site_url="http://localhost:8082")
    write_runtime_identity_wp(
        target_wp, owner="native", site_url="http://store8889.localhost:8889"
    )
    runner_bin = tmp_path / "runner-bin"
    runner_args = write_approved_docker_fixture(
        runner_bin,
        ref_wp=ref_wp,
        target_wp=target_wp,
    )
    write_executable(
        merge_dir / "bc-drift-gate.sh",
        f"#!/usr/bin/env bash\ntouch {gate_marker}\n",
    )

    result = subprocess.run(
        [
            "bash",
            str(verify_copy),
            *runner_args,
            "--validate-scope-only",
        ],
        cwd=repo,
        text=True,
        stdout=subprocess.PIPE,
        stderr=subprocess.PIPE,
        env={"PATH": f"{runner_bin}:/bin:/usr/bin:/usr/local/bin", "TMPDIR": str(tmp_path)},
        check=False,
    )

    assert result.returncode == 0, result.stdout + result.stderr
    assert "Execution scope validated" in result.stdout
    assert not gate_marker.exists()


def test_verify_runs_repository_gates_from_its_own_root(tmp_path: Path) -> None:
    repo = tmp_path / "repo"
    outside = tmp_path / "outside"
    merge_dir = repo / "tools" / "woopayments-merge"
    ref_bin = tmp_path / "reference"
    target_bin = tmp_path / "target"
    observed_cwd = tmp_path / "gate-cwd.txt"
    merge_dir.mkdir(parents=True)
    outside.mkdir()
    ref_bin.mkdir()
    target_bin.mkdir()
    verify_copy = write_verify_fixture(merge_dir)
    ref_wp = ref_bin / "wp"
    target_wp = target_bin / "wp"
    write_runtime_identity_wp(ref_wp, owner="plugin", site_url="http://localhost:8082")
    write_runtime_identity_wp(target_wp, owner="native", site_url="http://store8889.localhost:8889")
    runner_bin = tmp_path / "runner-bin"
    runner_args = write_approved_docker_fixture(
        runner_bin,
        ref_wp=ref_wp,
        target_wp=target_wp,
    )
    write_executable(
        merge_dir / "bc-drift-gate.sh",
        f"#!/usr/bin/env bash\nprintf '%s' \"$PWD\" > {observed_cwd}\nexit 3\n",
    )

    subprocess.run(
        ["bash", str(verify_copy), *runner_args],
        cwd=outside,
        text=True,
        stdout=subprocess.PIPE,
        stderr=subprocess.PIPE,
        env={"PATH": f"{runner_bin}:/bin:/usr/bin:/usr/local/bin", "TMPDIR": str(tmp_path)},
        check=False,
    )

    assert observed_cwd.read_text(encoding="utf-8") == str(repo)


def test_verify_rejects_identical_cross_store_runners_before_printing_plan() -> None:
    result = run_verify(
        "--ref",
        TARGET_WP,
        "--target",
        TARGET_WP,
        "--print-full-evidence-plan",
    )

    assert result.returncode == 2
    assert "distinct WP runners" in result.stderr
    assert result.stdout == ""


def write_fake_evidence_context_script(path: Path) -> None:
    write_executable(
        path,
        """#!/usr/bin/env python3
import json
import os
import sys
from pathlib import Path

Path(os.environ["INVOCATIONS_LOG"]).open("a", encoding="utf-8").write(
    "evidence_context.py|" + " ".join(sys.argv[1:]) + "\\n"
)
if "--out" in sys.argv:
    out = Path(sys.argv[sys.argv.index("--out") + 1])
    out.parent.mkdir(parents=True, exist_ok=True)
    out.write_text(
        json.dumps(
            {
                "schema": "woopayments_critical_flow_context.v1",
                "aggregate_run_id": "fake-run",
                "source": {},
                "stores": {"ref": {}, "target": {}},
                "fixtures": {"ref": {"subscription_id": "101"}, "target": {"subscription_id": "202"}},
                "context_sha256": "sha256:fake",
            }
        )
        + "\\n",
        encoding="utf-8",
    )
""",
    )


def load_a5g_gate_module():
    spec = importlib.util.spec_from_file_location("a5g_multisite_runtime_gate", A5G_SCRIPT)
    assert spec is not None
    assert spec.loader is not None
    module = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(module)
    return module


def test_full_evidence_plan_lists_final_gates() -> None:
    final_evidence_out = str(REPO / ".agents" / "tmp" / "woopayments-final-evidence-test")
    result = run_verify(
        "--ref",
        REF_WP,
        "--target",
        TARGET_WP,
        "--tracks-ref-store-id",
        "ref-store",
        "--tracks-target-store-id",
        "target-store",
        "--full-evidence-out-dir",
        final_evidence_out,
        "--print-full-evidence-plan",
    )

    assert result.returncode == 0, result.stderr
    assert "verify.sh --self-check" in result.stdout
    assert "verify.sh --ref" in result.stdout
    assert "--with-tracks" in result.stdout
    assert '--tracks-ref-store-id "ref-store"' in result.stdout
    assert '--tracks-target-store-id "target-store"' in result.stdout
    assert f'TRACKS_OUT_DIR="{final_evidence_out}/tracks-parity" bash' in result.stdout
    assert "lpm-checkout-gate.sh" in result.stdout
    assert ALL_LPM_METHODS in result.stdout
    assert "plugin-active-settings-gate.sh" in result.stdout
    assert "--stage-plugin-active-fixture" in result.stdout
    assert '--browser-runner "playwright"' in result.stdout
    assert "<required-admin-session>" not in result.stdout
    assert "<required-checkout-session>" not in result.stdout
    assert "mc-rates-gate.sh" in result.stdout
    assert "i18n-notes-gate.sh" in result.stdout
    assert "rest-route-parity.sh" in result.stdout
    assert "hook-shape-parity.sh" in result.stdout
    assert "subsystem-disposition-gate.sh" in result.stdout
    assert "money-path-parity-gate.sh" in result.stdout
    assert f'{final_evidence_out}/money-path-parity' in result.stdout
    assert "subscriptions-renewal-gate.sh compare" in result.stdout
    assert "--ref-subscription-id" in result.stdout
    assert "--target-subscription-id" in result.stdout
    assert "subscriptions-renewal-gate.sh preflight" not in result.stdout
    assert "token-continuity-gate.sh" in result.stdout
    assert "--stage-sepa-fixture" in result.stdout
    assert "--source-flow provider-setup-intent" in result.stdout
    assert "--checkout-product-id" not in result.stdout
    assert "--renewal-product-id" in result.stdout
    assert "a5f-cutover-rehearsal.py" in result.stdout
    assert "a5g-multisite-runtime-gate.py" in result.stdout
    assert "dispute-e2e-gate.sh" in result.stdout
    assert "payout-evidence-gate.sh" in result.stdout
    assert "converted-currency-gate.sh" in result.stdout
    assert f'{final_evidence_out}/converted-currency' in result.stdout
    assert "a4aq-accumulated-gate.py" in result.stdout
    assert "--plugin-repo" in result.stdout
    assert "/a4aq-accumulated" in result.stdout
    assert "bundle-size-gate.sh capture" in result.stdout
    assert "--profile wcpay-plugin" in result.stdout
    assert "--profile wc-core" in result.stdout
    assert "bundle-size-gate.sh compare" in result.stdout
    assert "--budget" in result.stdout
    assert "perf-fixtures-gate.py" in result.stdout
    assert "/perf-fixtures.json" in result.stdout
    assert "perf-surface-gate.sh capture --wp" in result.stdout
    assert "perf-surface-gate.sh compare" in result.stdout
    assert "woopayments-critical-flows/test-inventory.py" in result.stdout
    assert "woopayments-critical-flows/setup/fixtures.sh fixture_all ref" in result.stdout
    assert "woopayments-critical-flows/setup/fixtures.sh fixture_all target" in result.stdout
    assert "woopayments-critical-flows/evidence_context.py create" in result.stdout
    assert "/critical-flow-context.json" in result.stdout
    assert "sc04-saved-card-gate.py" in result.stdout
    assert "/sc04-saved-card" in result.stdout
    assert "--context-file" in result.stdout
    assert "woopayments-critical-flows/run.sh --store both --layer all" in result.stdout
    critical_flow_run_plan = next(
        line
        for line in result.stdout.splitlines()
        if "woopayments-critical-flows/run.sh --store both --layer all" in line
    )
    assert '--ref-url "http://localhost:8082"' in critical_flow_run_plan
    assert '--target-url "http://store8889.localhost:8889"' in critical_flow_run_plan
    assert "EVIDENCE_DIR=" in result.stdout
    assert "/critical-flows" in result.stdout
    assert "--agent-results-dir" in result.stdout
    assert "/critical-flows-agent-results" in result.stdout
    assert "--require-sc04" in result.stdout
    assert "--require-ms07" in result.stdout
    assert "<required-ref-subscription-id>" in result.stdout
    assert "<required-target-subscription-id>" in result.stdout
    assert "<required-sc04-reference-browser>" not in result.stdout
    assert "<required-sc04-reference-state>" not in result.stdout
    assert "<required-sc04-target-browser>" not in result.stdout
    assert "<required-sc04-target-state>" not in result.stdout
    assert "<required-ms07-reference-browser>" in result.stdout
    assert "<required-ms07-reference-state>" in result.stdout
    assert "<required-ms07-target-browser>" in result.stdout
    assert "<required-ms07-target-state>" in result.stdout
    assert "wp-env run tests-cli" in result.stdout
    assert "DELETE FROM wp_actionscheduler_actions" not in result.stdout
    assert "pnpm --filter=@woocommerce/plugin-woocommerce test:php:env -- --filter WooPayments" in result.stdout
    assert "pnpm --filter=@woocommerce/admin-library test:js" in result.stdout
    assert "pnpm --filter=@woocommerce/admin-library ts:check" in result.stdout
    assert "pnpm --filter=@woocommerce/plugin-woocommerce lint:changes:branch" in result.stdout
    assert "pnpm --filter=@woocommerce/plugin-woocommerce phpstan" in result.stdout
    assert_occurs_in_order(
        result.stdout,
        [
            "run-self-tests.sh",
            "verify.sh --self-check",
            "verify.sh --ref",
            "rest-route-parity.sh",
            "hook-shape-parity.sh",
            "subsystem-disposition-gate.sh",
            "i18n-notes-gate.sh",
            "money-path-parity-gate.sh",
            "subscriptions-renewal-gate.sh compare",
            "plugin-active-settings-gate.sh",
            "lpm-checkout-gate.sh",
            "mc-rates-gate.sh",
            "token-continuity-gate.sh",
            "a5f-cutover-rehearsal.py",
            "a5g-multisite-runtime-gate.py",
            "dispute-e2e-gate.sh",
            "payout-evidence-gate.sh --wp",
            "payout-evidence-gate.sh --wp",
            "converted-currency-gate.sh",
            "bundle-size-gate.sh capture",
            "bundle-size-gate.sh capture",
            "bundle-size-gate.sh compare",
            "perf-fixtures-gate.py",
            "perf-surface-gate.sh capture --wp",
            "perf-surface-gate.sh capture --wp",
            "perf-surface-gate.sh compare",
            "a4aq-accumulated-gate.py",
            "woopayments-critical-flows/test-inventory.py",
            "woopayments-critical-flows/setup/fixtures.sh fixture_all ref",
            "woopayments-critical-flows/setup/fixtures.sh fixture_all target",
            "woopayments-critical-flows/evidence_context.py create",
            "sc04-saved-card-gate.py",
            "woopayments-critical-flows/run.sh --store both --layer all",
            "wp-env run tests-cli",
            "pnpm --filter=@woocommerce/plugin-woocommerce test:php:env -- --filter WooPayments",
            "pnpm --filter=@woocommerce/admin-library test:js",
            "pnpm --filter=@woocommerce/admin-library ts:check",
            "pnpm --filter=@woocommerce/plugin-woocommerce lint:changes:branch",
            "pnpm --filter=@woocommerce/plugin-woocommerce phpstan",
        ],
    )


def test_full_evidence_plan_uses_provider_setup_intent_for_token_fixture() -> None:
    result = run_verify(
        "--ref",
        REF_WP,
        "--target",
        TARGET_WP,
        "--token-customer-id",
        "1",
        "--token-renewal-product-id",
        "116",
        "--print-full-evidence-plan",
    )

    assert result.returncode == 0, result.stderr
    token_command = next(
        line for line in result.stdout.splitlines() if "token-continuity-gate.sh" in line
    )
    assert '--customer-id "1"' in token_command
    assert "--source-flow provider-setup-intent" in token_command
    assert '--renewal-product-id "116"' in token_command
    assert "--checkout-product-id" not in token_command


def test_full_evidence_plan_defaults_to_playwright_without_persistent_sessions() -> None:
    result = run_verify(
        "--ref",
        REF_WP,
        "--target",
        TARGET_WP,
        "--print-full-evidence-plan",
    )

    assert result.returncode == 0, result.stderr
    assert "--browser-runner \"playwright\"" in result.stdout
    assert "<required-admin-session>" not in result.stdout
    assert "<required-checkout-session>" not in result.stdout

    browser_gate_lines = [
        line
        for line in result.stdout.splitlines()
        if any(
            gate_name in line
            for gate_name in (
                "plugin-active-settings-gate.sh",
                "lpm-checkout-gate.sh",
                "token-continuity-gate.sh",
                "a5f-cutover-rehearsal.py",
                "a4aq-accumulated-gate.py",
            )
        )
    ]
    assert browser_gate_lines
    assert all("--playwriter-session" not in line for line in browser_gate_lines)


def test_full_evidence_default_output_requires_tmpdir(tmp_path: Path) -> None:
    result = subprocess.run(
        [
            "bash",
            str(SCRIPT),
            "--self-check",
            "docker exec -i unavailable-cli wp",
            "--full-evidence",
        ],
        cwd=REPO,
        text=True,
        stdout=subprocess.PIPE,
        stderr=subprocess.PIPE,
        env={
            "PATH": "/bin:/usr/bin:/usr/local/bin",
            "TRACKS_OUT_DIR": str(tmp_path / "tracks"),
        },
        check=False,
    )

    assert result.returncode == 3
    assert "TMPDIR is required for default full-evidence output" in result.stderr


def test_full_evidence_plan_passes_perf_fixture_ids_to_perf_captures() -> None:
    result = run_verify(
        "--ref",
        REF_WP,
        "--target",
        TARGET_WP,
        "--perf-ref-process-order-id",
        "11",
        "--perf-target-process-order-id",
        "22",
        "--perf-ref-refund-order-id",
        "33",
        "--perf-target-refund-order-id",
        "44",
        "--perf-ref-capture-order-id",
        "55",
        "--perf-target-capture-order-id",
        "66",
        "--print-full-evidence-plan",
    )

    assert result.returncode == 0, result.stderr
    perf_commands = [
        line for line in result.stdout.splitlines() if "perf-surface-gate.sh capture" in line
    ]
    assert len(perf_commands) == 2
    assert "perf-fixtures-gate.py" not in result.stdout
    assert '--process-order-id "11"' in perf_commands[0]
    assert '--refund-order-id "33"' in perf_commands[0]
    assert '--capture-order-id "55"' in perf_commands[0]
    assert '--process-order-id "22"' in perf_commands[1]
    assert '--refund-order-id "44"' in perf_commands[1]
    assert '--capture-order-id "66"' in perf_commands[1]


def test_full_evidence_can_acknowledge_manual_evidence_limitations() -> None:
    with tempfile.TemporaryDirectory(prefix="verify-accepted-manual-limitations-") as tmp:
        tmp_path = Path(tmp)
        repo = tmp_path / "repo"
        merge_dir = repo / "tools" / "woopayments-merge"
        critical_dir = repo / "tools" / "woopayments-critical-flows"
        critical_flows_dir = critical_dir / "flows"
        critical_setup_dir = critical_dir / "setup"
        fake_bin = tmp_path / "bin"
        fake_tmp = tmp_path / "tmp"
        fake_wpcom_home = tmp_path / "wpcom-local"
        wcpay_repo = tmp_path / "woocommerce-payments"
        fake_wp = fake_bin / "wp"
        invocations = tmp_path / "invocations.log"
        full_evidence_dir = tmp_path / "final-evidence"

        for path in (
            merge_dir,
            critical_dir,
            critical_flows_dir,
            critical_setup_dir,
            fake_bin,
            fake_tmp,
            fake_wpcom_home / "secrets",
            wcpay_repo,
        ):
            path.mkdir(parents=True, exist_ok=True)
        write_fake_evidence_context_script(critical_dir / "evidence_context.py")
        for flow_name, suffix in (
            ("SC-01-card-checkout", ".sh"),
            ("MA-10-i18n-order-notes", ".md"),
            ("MA-11-plugin-active-settings-screen", ".md"),
            ("MC-06-automatic-rates-refresh", ".md"),
            ("MS-07-admin-change-method", ".md"),
            ("SC-04-saved-card", ".md"),
            ("SC-14-lpm-wave-1-checkout", ".md"),
            ("SS-10-sepa-token-renewal-cutover", ".md"),
        ):
            (critical_flows_dir / f"{flow_name}{suffix}").write_text("fixture\n", encoding="utf-8")
        (fake_wpcom_home / "secrets" / "tracks.json").write_text(
            '{"sink_token":"wpcom_local_tracks_test"}\n',
            encoding="utf-8",
        )
        (wcpay_repo / "woocommerce-payments.php").write_text("<?php\n", encoding="utf-8")

        verify_copy = write_verify_fixture(merge_dir)
        (merge_dir / "a4aq-bundle-budget.json").write_text("{}\n", encoding="utf-8")

        fake_gate = """#!/usr/bin/env bash
set -eu
name="$(basename "$0")"
printf '%s|%s\n' "$name" "$*" >> "$INVOCATIONS_LOG"
out=""
out_dir=""
label=""
previous=""
for arg in "$@"; do
    if [ "$previous" = "--out" ]; then out="$arg"; previous=""; continue; fi
    if [ "$previous" = "--out-dir" ]; then out_dir="$arg"; previous=""; continue; fi
    if [ "$previous" = "--label" ]; then label="$arg"; previous=""; continue; fi
    previous="$arg"
done
if [ -n "$out" ]; then
    mkdir -p "$(dirname "$out")"
    printf '{}\n' > "$out"
fi
case "$name" in
    flow-drive.sh)
        printf '{"order_id":123}\n'
        ;;
    tracks-parity.sh)
        if [ "${1:-}" = "normalize" ]; then printf '{"event":"checkout"}\n'; fi
        ;;
    lpm-checkout-gate.sh)
        mkdir -p "$out_dir"
        mkdir -p "$out_dir/lpm-fixtures"
        cat > "$out_dir/lpm-fixtures/target-sepa_debit-stage.json" <<'JSON'
{"success":false,"mode":"stage-lpm-fixture","method":"sepa_debit","errors":["LPM fixture requires the connected WooPayments account capability sepa_debit_payments to be usable for browser checkout; current cached status is unrequested."],"previous":{"capability_key":"sepa_debit_payments","capability_status":"unrequested","account_cache":{"data":{"capabilities":{"sepa_debit_payments":"unrequested"}}}}}
JSON
        cat > "$out_dir/reference-p24-account-profile.json" <<'JSON'
{"success":true,"ready":false,"status":"blocked","profile":{"id":"p24","country":"PL","test_lab_provisionable":true},"checks":{"country":{"status":"wrong_country"},"p24_payments":{"status":"unrequested","capability_status":"unrequested"}}}
JSON
        cat > "$out_dir/target-au_becs_debit-account-profile.json" <<'JSON'
{"success":true,"ready":false,"status":"blocked","profile":{"id":"au_becs_debit","country":"AU","test_lab_provisionable":false},"checks":{"country":{"status":"wrong_country"},"au_becs_debit_payments":{"status":"missing","capability_status":null}}}
JSON
        cat > "$out_dir/reference-grabpay-account-profile.json" <<'JSON'
{"success":true,"ready":false,"status":"blocked","profile":{"id":"grabpay","country":"SG","test_lab_provisionable":false},"checks":{"country":{"status":"wrong_country"},"grabpay_payments":{"status":"missing","capability_status":null}}}
JSON
        cat > "$out_dir/lpm-cleanup-restore.json" <<'JSON'
{"schema":"woopayments_lpm_cleanup_restore.v1","status":"pass"}
JSON
        python3 - "$out_dir/lpm-checkout-gate.json" "$(dirname "$0")" <<'PY'
import json
import os
import sys
from pathlib import Path

sys.path.insert(0, sys.argv[2])
from lpm_evidence import EvidenceContext, classify_manual_completion

methods = "sepa_debit,ideal,bancontact,klarna,affirm,afterpay_clearpay,eps,p24,multibanco,au_becs_debit,grabpay,wechat_pay,alipay".split(",")
blocker_details = [
    {"code":"account_capability_unavailable","role":"target","method":"sepa_debit","artifact":"lpm-fixtures/target-sepa_debit-stage.json","message":"target/sepa_debit: could not stage LPM fixture"},
    {"code":"account_profile_ineligible","role":"reference","method":"p24","artifact":"reference-p24-account-profile.json","message":"reference/p24: account profile p24 readiness status=blocked"},
    {"code":"account_profile_ineligible","role":"target","method":"au_becs_debit","artifact":"target-au_becs_debit-account-profile.json","message":"target/au_becs_debit: account profile au_becs_debit readiness status=blocked"},
    {"code":"account_profile_ineligible","role":"reference","method":"grabpay","artifact":"reference-grabpay-account-profile.json","message":"reference/grabpay: account profile grabpay readiness status=blocked"},
]
account_blocked = {(detail["role"], detail["method"]) for detail in blocker_details}
manual_contracts = {
    "klarna": ("woocommerce_payments_klarna", "klarna", "hosted_action"),
    "wechat_pay": ("woocommerce_payments_wechat_pay", "wechat_pay", "customer_action"),
}
out_path = Path(sys.argv[1])
results = []
for role in ("reference", "target"):
    for method in methods:
        identity = (role, method)
        if identity in account_blocked:
            continue
        if method not in manual_contracts or (
            os.environ.get("FAKE_LPM_MANUAL_ASYMMETRIC") == "1"
            and identity == ("target", "klarna")
        ):
            results.append({"status":"pass","method":method,"role":role,"failures":[]})
            continue

        gateway_id, stripe_type, method_family = manual_contracts[method]
        base_url = "http://localhost:8082" if role == "reference" else "http://store8889.localhost:8889"
        order_id = 1001
        intent_id = f"pi_manual_{role}_{method}"
        limitation = "checkout did not reach an order-received URL with an order id"
        runtime_owner = "plugin" if role == "reference" else "native"
        adapter = "legacy_woopayments_api_client" if role == "reference" else "native_woocommerce_api_client"
        candidate = {
            "schema":"woopayments_lpm_checkout_browser_evidence.v1",
            "status":"fail",
            "role":role,
            "method":method,
            "surface":"classic",
            "base_url":base_url,
            "gateway_id":gateway_id,
            "stripe_payment_method_type":stripe_type,
            "method_family":method_family,
            "automation_disposition":"manual_customer_action",
            "order_id":order_id,
            "selected_gateway_id":gateway_id,
            "order_payment_method":gateway_id,
            "order_received_url":None,
            "payment_intent_id":intent_id,
            "used_base_card_gateway":False,
            "failures":[limitation, f"LPM checkout failed for {role}/{method}: {limitation}"],
            "page":{
                "failed_responses":[],
                "fatal_console_errors":[],
                "checkout_requests":[{
                    "method":"POST",
                    "url":f"{base_url}/?wc-ajax=checkout",
                    "payment_method":gateway_id,
                    "field_count":35,
                    "payment_method_error_code":"",
                    "payment_method_error_message":"",
                    "credentials":{"wcpay-payment-method":{"present":True,"credential_prefix":"pm_","length":27}},
                }],
                "checkout_responses":[{"status":200,"result":"success","order_id":order_id,"url":f"{base_url}/?wc-ajax=checkout"}],
            },
            "order_enrichment":{
                "order_status":"pending",
                "order_payment_method":gateway_id,
                "payment_intent_id":intent_id,
                "payment_method_id":f"pm_manual_{role}_{method}",
                "intention_status":"requires_action",
                "transaction_id":intent_id,
                "resolved_by":"payment_intent_id",
            },
            "provider_observation":{
                "schema":"woopayments_lpm_provider_observation.v1",
                "status":"pass",
                "role":role,
                "method":method,
                "order_id":order_id,
                "payment_intent_id":intent_id,
                "expected_payment_method_type":stripe_type,
                "observation_request_id":f"lpm-provider-{role}-{method}",
                "provider_payment_intent_id":intent_id,
                "provider_charge_payment_intent_id":intent_id,
                "observed_payment_method_type":stripe_type,
                "fetched":True,
                "matches_expected":True,
                "transport_available":True,
                "test_mode":True,
                "adapter":adapter,
                "runtime_owner":runtime_owner,
                "api_client_class":"FakeWooPaymentsApiClient",
                "account_id":"acct_test",
                "source":"intent.payment_method",
                "blocker_code":"",
                "errors":[],
                "message":"",
            },
        }
        context = EvidenceContext(role, method, base_url, gateway_id, stripe_type, method_family, "manual_customer_action")
        evidence, errors = classify_manual_completion(candidate, context)
        assert not errors, errors
        if os.environ.get("FAKE_LPM_MANUAL_HTTP_FAILURE") == "1" and identity == ("target", "klarna"):
            evidence["page"]["failed_responses"] = [{"status":500,"url":f"{base_url}/?wc-ajax=checkout"}]
        artifact_path = out_path.parent / f"{role}-{method}-classic.json"
        artifact_path.write_text(json.dumps(evidence) + "\\n", encoding="utf-8")
        results.append(evidence)
        message = f"{role}/{method}: manual payment authorization required"
        blocker_details.append({
            "code":"manual_payment_authorization_required",
            "role":role,
            "method":method,
            "artifact":str(artifact_path),
            "message":message,
            "provenance":evidence["manual_completion"],
        })
if os.environ.get("FAKE_LPM_TRUNCATE") == "1":
    results.pop()
payload = {
    "schema":"woopayments_lpm_checkout_gate_rollup.v1",
    "surface":"classic",
    "status":"blocked",
    "failures":[],
    "results":results,
    "blockers":[detail["message"] for detail in blocker_details],
    "blocker_details":blocker_details,
    "cleanup_restore":{"schema":"woopayments_lpm_cleanup_restore.v1","status":"pass"},
}
Path(sys.argv[1]).write_text(json.dumps(payload) + "\\n", encoding="utf-8")
PY
        if [ -n "${FAKE_LPM_BLOCKER_CODE:-}" ]; then
            python3 - "$out_dir/lpm-checkout-gate.json" "$FAKE_LPM_BLOCKER_CODE" <<'PY'
import json
import sys
from pathlib import Path

path = Path(sys.argv[1])
payload = json.loads(path.read_text(encoding="utf-8"))
payload["blocker_details"][0]["code"] = sys.argv[2]
path.write_text(json.dumps(payload) + "\\n", encoding="utf-8")
PY
        fi
        exit 3
        ;;
    token-continuity-gate.sh)
        mkdir -p "$out_dir"
        if [ "${FAKE_TOKEN_BLOCKER_MODE:-}" = "target_only_pass" ] || [ "${FAKE_TOKEN_BLOCKER_MODE:-}" = "malformed_target_only_pass" ]; then
            cat > "$out_dir/token-continuity-gate.json" <<'JSON'
{"schema":"woopayments_token_continuity_gate_rollup.v1","status":"pass","failures":[],"blockers":[],"source_flow":"provider_setup_intent","token_id":13,"source_token":{"success":true,"payment_method_id":"pm_unit","source_payment_method_customer_ready":true},"native_token_loader":{"success":true,"token_id":13,"gateway_id":"woocommerce_payments_sepa_debit","token_type":"wcpay_sepa","token_class":"WooPaymentsSepaToken"},"render_payment_methods":{"status":"pass","token_id":13,"token_visible":true,"page":{"payment_methods":{"token_visible":true}}},"renewal":{"success":true,"renewal_order_id":261,"renewal_processing_model":"asynchronous_processing","success_checks_failed":[]}}
JSON
            if [ "${FAKE_TOKEN_BLOCKER_MODE:-}" = "malformed_target_only_pass" ]; then
                python3 - "$out_dir/token-continuity-gate.json" <<'PY'
import json
import sys
from pathlib import Path

path = Path(sys.argv[1])
payload = json.loads(path.read_text(encoding="utf-8"))
payload["schema"] = "woopayments_token_continuity_gate_rollup.invalid"
payload.pop("failures")
payload["blockers"] = ""
path.write_text(json.dumps(payload) + "\\n", encoding="utf-8")
PY
            fi
            exit 0
        fi
        cat > "$out_dir/sepa-fixture-stage.json" <<'JSON'
{"success":false,"mode":"stage-lpm-fixture","method":"sepa_debit","errors":["LPM fixture requires the connected WooPayments account capability sepa_debit_payments to be usable for browser checkout; current cached status is unrequested."],"previous":{"capability_key":"sepa_debit_payments","capability_status":"unrequested","account_cache":{"data":{"capabilities":{"sepa_debit_payments":"unrequested"}}}}}
JSON
        if [ "${FAKE_TOKEN_BLOCKER_MODE:-}" = "unrelated_rollup" ]; then
            cat > "$out_dir/token-continuity-gate.json" <<'JSON'
{"status":"blocked","failures":[],"blockers":["SEPA browser runner exited before My Account evidence"]}
JSON
        elif [ "${FAKE_TOKEN_BLOCKER_MODE:-}" = "empty_rollup" ]; then
            printf '{}\n' > "$out_dir/token-continuity-gate.json"
        fi
        exit 3
        ;;
    payout-evidence-gate.sh)
        if [ "${FAKE_PAYOUT_BLOCKED:-0}" = "1" ]; then
            printf 'PASS: WC-side money records match the provider'"'"'s raw source for all reconciled orders across the widened matrix.\n'
            printf 'BLOCKED (%s): payout membership source did not include charge ch_test.\n' "$label"
            exit 3
        fi
        ;;
esac
exit 0
"""
        for script_name in (
            "bc-drift-gate.sh",
            "subsystem-disposition-gate.sh",
            "native-hook-naming-gate.sh",
            "run-self-tests.sh",
            "hook-shape-parity.sh",
            "rest-route-parity.sh",
            "i18n-notes-gate.sh",
            "flow-drive.sh",
            "parity-diff.sh",
            "perf-baseline.sh",
            "financial-reconcile.sh",
            "tracks-parity.sh",
            "subscriptions-renewal-gate.sh",
            "plugin-active-settings-gate.sh",
            "lpm-checkout-gate.sh",
            "mc-rates-gate.sh",
            "money-path-parity-gate.sh",
            "token-continuity-gate.sh",
            "dispute-e2e-gate.sh",
            "payout-evidence-gate.sh",
            "converted-currency-gate.sh",
            "bundle-size-gate.sh",
            "perf-surface-gate.sh",
        ):
            write_executable(merge_dir / script_name, fake_gate)

        write_executable(
            merge_dir / "a5f-cutover-rehearsal.py",
            """#!/usr/bin/env python3
import os
from pathlib import Path
Path(os.environ["INVOCATIONS_LOG"]).open("a", encoding="utf-8").write("a5f-cutover-rehearsal.py|\\n")
""",
        )
        write_executable(
            merge_dir / "a5g-multisite-runtime-gate.py",
            """#!/usr/bin/env python3
import os
from pathlib import Path
Path(os.environ["INVOCATIONS_LOG"]).open("a", encoding="utf-8").write("a5g-multisite-runtime-gate.py|\\n")
""",
        )
        write_executable(
            merge_dir / "a4aq-accumulated-gate.py",
            """#!/usr/bin/env python3
import json
import os
import sys
from pathlib import Path
Path(os.environ["INVOCATIONS_LOG"]).open("a", encoding="utf-8").write("a4aq-accumulated-gate.py|" + " ".join(sys.argv[1:]) + "\\n")
out_dir = Path(sys.argv[sys.argv.index("--out-dir") + 1])
out_dir.mkdir(parents=True, exist_ok=True)
if os.environ.get("FAKE_A4AQ_BLOCKED") == "1":
    (out_dir / "a4aq-accumulated-gate.json").write_text(json.dumps({"status":"incomplete","failures":[],"incomplete":["checkout-browser: browser evidence is incomplete; blockers=12"]}) + "\\n", encoding="utf-8")
    raise SystemExit(3)
(out_dir / "a4aq-accumulated-gate.json").write_text(json.dumps({"status":"pass","failures":[],"incomplete":[]}) + "\\n", encoding="utf-8")
""",
        )
        write_executable(
            merge_dir / "sc04-saved-card-gate.py",
            """#!/usr/bin/env python3
import os
import sys
from pathlib import Path
Path(os.environ["INVOCATIONS_LOG"]).open("a", encoding="utf-8").write("sc04-saved-card-gate.py|" + " ".join(sys.argv[1:]) + "\\n")
""",
        )

        write_executable(
            fake_wp,
            """#!/usr/bin/env bash
set -eu
printf 'wp|%s\n' "$*" >> "$INVOCATIONS_LOG"
if [ "${1:-}" = "eval-file" ]; then
    body="$(cat)"
    if printf '%s' "$body" | grep -q "HELPER_OPTION:"; then
        printf 'HELPER_OPTION:enabled:MQ==\n'
        printf 'HELPER_OPTION:sink_endpoint:aHR0cDovL29sZA==\n'
        printf 'HELPER_OPTION:sink_token:ZXhpc3Rpbmc=\n'
        printf 'HELPER_OPTION:capture_browser:MQ==\n'
        printf 'HELPER_OPTION:capture_server:MQ==\n'
    elif printf '%s' "$body" | grep -q "HELPER_RESTORED"; then
        printf 'HELPER_RESTORED\n'
    elif printf '%s' "$body" | grep -q "TRACKING:"; then
        printf 'TRACKING:yes\n'
    elif printf '%s' "$body" | grep -q "TRACKING_SET:"; then
        printf 'TRACKING_SET:%s\n' "${3:-}"
    else
        printf 'ready\n'
    fi
elif [ "${1:-}" = "wpcom-local" ] && [ "${2:-}" = "tracks" ] && [ "${3:-}" = "enable" ]; then
    printf 'Success: Local Tracks capture enabled.\n'
else
    printf '{}\n'
fi
""",
        )
        write_executable(
            fake_bin / "pnpm",
            """#!/usr/bin/env bash
set -eu
printf 'pnpm|%s\n' "$*" >> "$INVOCATIONS_LOG"
if [[ "$*" == *'pgrep -f "vendor/bin/phpuni[t]"'* ]]; then
    exit 1
fi
""",
        )
        write_executable(
            fake_bin / "wpcom-local",
            """#!/usr/bin/env bash
set -eu
if [ "${1:-}" = "tracks" ] && [ "${2:-}" = "path" ]; then
    printf 'Command: tracks path\n'
    printf 'Status: success\n'
    printf 'Context:\n'
    printf '%s\n' '- endpoint_url: http://wpcom.localhost:30001/__wpcom-local/tracks/events'
    exit 0
fi
exit 1
""",
        )
        write_executable(
            critical_dir / "test-inventory.py",
            """#!/usr/bin/env python3
import os
from pathlib import Path
Path(os.environ["INVOCATIONS_LOG"]).open("a", encoding="utf-8").write("critical-inventory|\\n")
""",
        )
        write_executable(
            critical_setup_dir / "fixtures.sh",
            """#!/usr/bin/env bash
set -eu
printf 'critical-fixtures|%s\n' "$*" >> "$INVOCATIONS_LOG"
""",
        )
        write_executable(
            critical_dir / "build-agent-results.py",
            """#!/usr/bin/env python3
import os
import sys
from pathlib import Path
Path(os.environ["INVOCATIONS_LOG"]).open("a", encoding="utf-8").write("build-agent-results.py|" + " ".join(sys.argv[1:]) + "\\n")
Path(sys.argv[sys.argv.index("--out-dir") + 1]).mkdir(parents=True, exist_ok=True)
""",
        )
        write_executable(
            critical_dir / "run.sh",
            """#!/usr/bin/env bash
set -eu
printf 'critical-run|%s\n' "$*" >> "$INVOCATIONS_LOG"
mkdir -p "${EVIDENCE_DIR:-.}"
python3 - "${EVIDENCE_DIR:-.}/rollup.json" <<'PY'
import json
import os
import sys
from collections import Counter
from pathlib import Path

flows = [
    "SC-01-card-checkout",
    "MA-10-i18n-order-notes",
    "MA-11-plugin-active-settings-screen",
    "MC-06-automatic-rates-refresh",
    "MS-07-admin-change-method",
    "SC-04-saved-card",
    "SC-14-lpm-wave-1-checkout",
    "SS-10-sepa-token-renewal-cutover",
]
results = []
for flow in flows:
    for store in ("ref", "target"):
        status = "PASS"
        if flow == "SC-14-lpm-wave-1-checkout":
            status = "BLOCKED"
        elif flow == "SS-10-sepa-token-renewal-cutover":
            status = "PASS" if store == "target" and os.environ.get("FAKE_TOKEN_BLOCKER_MODE") in {"target_only_pass", "malformed_target_only_pass"} else "BLOCKED"
        results.append({"flow":flow,"status":status,"store":store})
if os.environ.get("FAKE_CRITICAL_TRUNCATE") == "1":
    results.pop(0)
counts = Counter(result["status"] for result in results)
payload = {
    "schema":"woopayments_critical_flows_rollup.v1",
    "status":"blocked",
    "summary":{"passed":counts["PASS"],"failed":counts["FAIL"],"blocked":counts["BLOCKED"]},
    "results":results,
}
if os.environ.get("FAKE_CRITICAL_CONTEXT_MISSING") != "1":
    payload.update({"context_sha256":"sha256:fake","aggregate_run_id":"fake-run"})
Path(sys.argv[1]).write_text(json.dumps(payload) + "\\n", encoding="utf-8")
PY
exit 3
""",
        )

        ref_wp = fake_bin / "reference" / "wp"
        target_wp = fake_bin / "target" / "wp"
        write_runtime_identity_wp_wrapper(
            ref_wp, delegate=fake_wp, owner="plugin", site_url="http://localhost:8082"
        )
        write_runtime_identity_wp_wrapper(
            target_wp,
            delegate=fake_wp,
            owner="native",
            site_url="http://store8889.localhost:8889",
        )
        runner_args = write_approved_docker_fixture(
            fake_bin,
            ref_wp=ref_wp,
            target_wp=target_wp,
        )

        run_env = {
            "INVOCATIONS_LOG": str(invocations),
            "TMPDIR": str(fake_tmp),
            "WCPAY_REPO": str(wcpay_repo),
            "WPCOM_LOCAL_HOME": str(fake_wpcom_home),
            "PATH": str(fake_bin) + ":" + "/bin:/usr/bin:/usr/local/bin",
        }
        result = subprocess.run(
            [
                "bash",
                str(verify_copy),
                *runner_args,
                "--full-evidence-out-dir",
                str(full_evidence_dir),
                "--full-evidence",
                "--browser-runner",
                "playwright",
                "--acknowledge-manual-evidence-limitations",
                "--ref-subscription-id",
                "101",
                "--target-subscription-id",
                "202",
                "--token-customer-id",
                "cus_test",
                "--token-renewal-product-id",
                "116",
                "--perf-ref-process-order-id",
                "11",
                "--perf-target-process-order-id",
                "22",
                "--perf-ref-refund-order-id",
                "33",
                "--perf-target-refund-order-id",
                "44",
                "--perf-ref-capture-order-id",
                "55",
                "--perf-target-capture-order-id",
                "66",
            ],
            cwd=repo,
            text=True,
            stdout=subprocess.PIPE,
            stderr=subprocess.PIPE,
            env=run_env,
            check=False,
        )

        assert result.returncode == 3, result.stdout + result.stderr
        assert "Summary:" in result.stdout
        assert "1 blocked, 2 acknowledged manual" in result.stdout
        assert "Acknowledged manual evidence limitations:" in result.stdout
        assert "LPM all-method checkout:" in result.stdout
        assert "token continuity cutover:" in result.stdout
        assert "RESULT: INCOMPLETE" in result.stdout
        assert (full_evidence_dir / "manual-evidence-limitations.json").is_file()

        default_labels = (
            "LPM all-method checkout",
            "token continuity cutover",
            "critical flows full run",
        )

        def copy_evidence(suffix: str) -> Path:
            destination = tmp_path / f"final-evidence-{suffix}"
            shutil.copytree(full_evidence_dir, destination)
            lpm_rollup_path = destination / "lpm-all-methods" / "lpm-checkout-gate.json"
            lpm_rollup = json.loads(lpm_rollup_path.read_text(encoding="utf-8"))
            for detail in lpm_rollup["blocker_details"]:
                artifact = Path(str(detail.get("artifact") or ""))
                if artifact.is_absolute() and artifact.is_relative_to(full_evidence_dir):
                    detail["artifact"] = str(destination / artifact.relative_to(full_evidence_dir))
            write_payload(lpm_rollup_path, lpm_rollup)
            return destination

        def write_payload(path: Path, payload: dict) -> None:
            path.write_text(json.dumps(payload) + "\n", encoding="utf-8")

        def classify(evidence_dir: Path, labels=default_labels) -> dict:
            summary_path = evidence_dir / "manual-evidence-limitations.json"
            completed = subprocess.run(
                [
                    sys.executable,
                    str(merge_dir / "manual-evidence-classifier.py"),
                    str(evidence_dir),
                    str(summary_path),
                    ALL_LPM_METHODS,
                    str(repo),
                    *labels,
                ],
                cwd=repo,
                text=True,
                stdout=subprocess.PIPE,
                stderr=subprocess.PIPE,
                check=False,
            )
            assert completed.returncode == 0, completed.stdout + completed.stderr
            return json.loads(summary_path.read_text(encoding="utf-8"))

        def labels_for(summary: dict, classification: str) -> list[str]:
            return [entry["label"] for entry in summary[classification]]

        truncated_lpm_dir = copy_evidence("truncated-lpm")
        truncated_lpm_path = truncated_lpm_dir / "lpm-all-methods" / "lpm-checkout-gate.json"
        truncated_lpm_payload = json.loads(truncated_lpm_path.read_text(encoding="utf-8"))
        truncated_lpm_payload["results"].pop()
        write_payload(truncated_lpm_path, truncated_lpm_payload)
        truncated_lpm_summary = classify(truncated_lpm_dir)
        assert labels_for(truncated_lpm_summary, "blocked") == [
            "LPM all-method checkout",
            "critical flows full run",
        ]
        assert labels_for(truncated_lpm_summary, "accepted") == [
            "token continuity cutover"
        ]

        truncated_critical_dir = copy_evidence("truncated-critical")
        truncated_critical_path = truncated_critical_dir / "critical-flows" / "rollup.json"
        truncated_critical_payload = json.loads(
            truncated_critical_path.read_text(encoding="utf-8")
        )
        truncated_critical_payload["results"].pop()
        write_payload(truncated_critical_path, truncated_critical_payload)
        truncated_critical_summary = classify(truncated_critical_dir)
        assert labels_for(truncated_critical_summary, "blocked") == [
            "critical flows full run"
        ]

        rejected_dir = copy_evidence("rejected-lpm")
        rejected_path = rejected_dir / "lpm-all-methods" / "lpm-checkout-gate.json"
        rejected_payload = json.loads(rejected_path.read_text(encoding="utf-8"))
        rejected_payload["blocker_details"][0]["code"] = "fixture_stage_execution_failed"
        write_payload(rejected_path, rejected_payload)
        rejected_summary = classify(rejected_dir)
        assert rejected_summary["blocked"] == [
            {
                "label": "LPM all-method checkout",
                "reason": "No accepted manual-evidence classification matched.",
            },
            {
                "label": "critical flows full run",
                "reason": "No accepted manual-evidence classification matched.",
            },
        ]

        invalid_http_dir = copy_evidence("manual-http-failure")
        invalid_http_rollup_path = (
            invalid_http_dir / "lpm-all-methods" / "lpm-checkout-gate.json"
        )
        invalid_http_rollup = json.loads(
            invalid_http_rollup_path.read_text(encoding="utf-8")
        )
        invalid_http_result = next(
            item
            for item in invalid_http_rollup["results"]
            if item["role"] == "target" and item["method"] == "klarna"
        )
        failed_response = {
            "status": 500,
            "url": "http://store8889.localhost:8889/?wc-ajax=checkout",
        }
        invalid_http_result["page"]["failed_responses"] = [failed_response]
        invalid_http_artifact_path = (
            invalid_http_dir / "lpm-all-methods" / "target-klarna-classic.json"
        )
        invalid_http_artifact = json.loads(
            invalid_http_artifact_path.read_text(encoding="utf-8")
        )
        invalid_http_artifact["page"]["failed_responses"] = [failed_response]
        write_payload(invalid_http_rollup_path, invalid_http_rollup)
        write_payload(invalid_http_artifact_path, invalid_http_artifact)
        invalid_http_summary = classify(invalid_http_dir)
        assert labels_for(invalid_http_summary, "blocked") == [
            "LPM all-method checkout",
            "critical flows full run",
        ]

        asymmetric_dir = copy_evidence("manual-asymmetry")
        asymmetric_path = asymmetric_dir / "lpm-all-methods" / "lpm-checkout-gate.json"
        asymmetric_payload = json.loads(asymmetric_path.read_text(encoding="utf-8"))
        asymmetric_payload["results"] = [
            (
                {"status": "pass", "method": "klarna", "role": "target", "failures": []}
                if result_item.get("role") == "target"
                and result_item.get("method") == "klarna"
                else result_item
            )
            for result_item in asymmetric_payload["results"]
        ]
        removed_detail = next(
            detail
            for detail in asymmetric_payload["blocker_details"]
            if detail.get("role") == "target" and detail.get("method") == "klarna"
        )
        asymmetric_payload["blocker_details"].remove(removed_detail)
        asymmetric_payload["blockers"].remove(removed_detail["message"])
        write_payload(asymmetric_path, asymmetric_payload)
        asymmetric_summary = classify(asymmetric_dir)
        assert labels_for(asymmetric_summary, "blocked") == [
            "LPM all-method checkout",
            "critical flows full run",
        ]

        rejected_token_dir = copy_evidence("rejected-token")
        rejected_token_path = (
            rejected_token_dir / "token-continuity" / "token-continuity-gate.json"
        )
        write_payload(
            rejected_token_path,
            {
                "status": "blocked",
                "failures": [],
                "blockers": ["SEPA browser runner exited before My Account evidence"],
            },
        )
        rejected_token_summary = classify(rejected_token_dir)
        assert rejected_token_summary["blocked"] == [
            {
                "label": "token continuity cutover",
                "reason": "No accepted manual-evidence classification matched.",
            },
            {
                "label": "critical flows full run",
                "reason": "No accepted manual-evidence classification matched.",
            },
        ]

        empty_rollup_dir = copy_evidence("empty-token-rollup")
        write_payload(
            empty_rollup_dir
            / "token-continuity"
            / "token-continuity-gate.json",
            {},
        )
        empty_rollup_summary = classify(empty_rollup_dir)
        assert labels_for(empty_rollup_summary, "blocked") == [
            "token continuity cutover",
            "critical flows full run",
        ]

        valid_target_token = {
            "schema": "woopayments_token_continuity_gate_rollup.v1",
            "status": "pass",
            "failures": [],
            "blockers": [],
            "source_flow": "provider_setup_intent",
            "token_id": 13,
            "source_token": {
                "success": True,
                "payment_method_id": "pm_unit",
                "source_payment_method_customer_ready": True,
            },
            "native_token_loader": {
                "success": True,
                "token_id": 13,
                "gateway_id": "woocommerce_payments_sepa_debit",
                "token_type": "wcpay_sepa",
                "token_class": "WooPaymentsSepaToken",
            },
            "render_payment_methods": {
                "status": "pass",
                "token_id": 13,
                "token_visible": True,
                "page": {"payment_methods": {"token_visible": True}},
            },
            "renewal": {
                "success": True,
                "renewal_order_id": 261,
                "renewal_processing_model": "asynchronous_processing",
                "success_checks_failed": [],
            },
        }

        def stage_target_only_token_pass(evidence_dir: Path, payload: dict) -> None:
            write_payload(
                evidence_dir
                / "token-continuity"
                / "token-continuity-gate.json",
                payload,
            )
            critical_path = evidence_dir / "critical-flows" / "rollup.json"
            critical_payload = json.loads(critical_path.read_text(encoding="utf-8"))
            target_result = next(
                item
                for item in critical_payload["results"]
                if item["flow"] == "SS-10-sepa-token-renewal-cutover"
                and item["store"] == "target"
            )
            target_result["status"] = "PASS"
            critical_payload["summary"]["passed"] += 1
            critical_payload["summary"]["blocked"] -= 1
            write_payload(critical_path, critical_payload)

        target_only_dir = copy_evidence("target-only-token-pass")
        stage_target_only_token_pass(target_only_dir, valid_target_token)
        target_only_summary = classify(
            target_only_dir,
            ("LPM all-method checkout", "critical flows full run"),
        )
        assert labels_for(target_only_summary, "blocked") == [
            "critical flows full run"
        ]
        assert labels_for(target_only_summary, "accepted") == [
            "LPM all-method checkout"
        ]

        malformed_token_dir = copy_evidence("malformed-target-only-token-pass")
        malformed_target_token = dict(valid_target_token)
        malformed_target_token["schema"] = "woopayments_token_continuity_gate_rollup.invalid"
        malformed_target_token.pop("failures")
        malformed_target_token["blockers"] = ""
        stage_target_only_token_pass(malformed_token_dir, malformed_target_token)
        malformed_token_summary = classify(
            malformed_token_dir,
            ("LPM all-method checkout", "critical flows full run"),
        )
        assert labels_for(malformed_token_summary, "blocked") == [
            "critical flows full run"
        ]
        assert labels_for(malformed_token_summary, "accepted") == [
            "LPM all-method checkout"
        ]

        payout_blocked_dir = copy_evidence("payout-blocked")
        payout_summary = classify(
            payout_blocked_dir,
            (
                *default_labels,
                "payout evidence (reference)",
                "payout evidence (target)",
            ),
        )
        assert labels_for(payout_summary, "blocked") == [
            "critical flows full run",
            "payout evidence (reference)",
            "payout evidence (target)",
        ]

        a4aq_blocked_dir = copy_evidence("a4aq-blocked")
        a4aq_summary = classify(
            a4aq_blocked_dir,
            (*default_labels, "A4aq accumulated admin/checkout/perf evidence"),
        )
        assert labels_for(a4aq_summary, "blocked") == [
            "critical flows full run",
            "A4aq accumulated admin/checkout/perf evidence"
        ]

        missing_context_dir = copy_evidence("missing-critical-context")
        missing_context_path = missing_context_dir / "critical-flows" / "rollup.json"
        missing_context_payload = json.loads(
            missing_context_path.read_text(encoding="utf-8")
        )
        missing_context_payload.pop("context_sha256")
        missing_context_payload.pop("aggregate_run_id")
        write_payload(missing_context_path, missing_context_payload)
        missing_context_summary = classify(missing_context_dir)
        assert labels_for(missing_context_summary, "blocked") == [
            "critical flows full run"
        ]

def test_manual_lpm_acknowledgement_requires_structured_account_blocker_codes() -> None:
    verify_source = SCRIPT.read_text(encoding="utf-8")
    classifier_source = MANUAL_EVIDENCE_CLASSIFIER.read_text(encoding="utf-8")

    assert 'python3 "$SELF_DIR/manual-evidence-classifier.py"' in verify_source
    assert 'payload.get("blocker_details")' in classifier_source
    assert '"account_capability_unavailable"' in classifier_source
    assert '"account_profile_ineligible"' in classifier_source
    assert "MANUAL_BLOCKER_CODE" in classifier_source
    assert 'MANUAL_BLOCKER_CODE = "manual_payment_authorization_required"' in LPM_EVIDENCE.read_text(
        encoding="utf-8"
    )
    assert "validate_manual_completion" in classifier_source
    assert "validate_manual_pair" in classifier_source
    assert "any(method in blocker for method in accepted_lpm_methods)" not in classifier_source
    assert '"critical flows full run"' not in classifier_source
    assert "accept_critical_flows" not in classifier_source


def test_perf_surface_gate_emits_sanitized_money_query_groups() -> None:
    source = (REPO / "tools/woopayments-merge/perf-surface-gate.sh").read_text(encoding="utf-8")

    assert "top_query_groups" in source
    assert "summarize_query_groups" in source
    assert "summarize_query_caller" in source
    assert "EvalFile_Command::{closure}" in source
    assert "preg_replace( \"/'[^']*'/\", \"'?'\"" in source
    assert "preg_replace( '/\\b\\d+\\b/', '?'" in source


def test_perf_compare_surfaces_query_groups_when_money_query_limit_fails() -> None:
    def measured_probe(queries: int, groups: list[dict[str, object]] | None = None) -> dict[str, object]:
        return {
            "status": "measured",
            "metrics": {
                "queries": queries,
                "external_requests": 1,
                "median_ms": 1,
                "top_query_groups": groups or [],
            },
        }

    def capture(capture_queries: int, capture_groups: list[dict[str, object]]) -> dict[str, object]:
        return {
            "schema": "woopayments_measured_gate.v1",
            "mode": "perf",
            "probes": {
                "process_payment": measured_probe(10),
                "refund": measured_probe(8),
                "capture": measured_probe(capture_queries, capture_groups),
                "gateway_registration": {
                    "status": "measured",
                    "metrics": {
                        "gateway_count": 1,
                        "action_callback_count": 1,
                        "external_requests": 0,
                        "median_ms": 1,
                    },
                },
                "rest_boot": {
                    "status": "measured",
                    "metrics": {
                        "external_requests": 0,
                        "controller_instantiation_count": 1,
                        "route_registration_status": "measured",
                        "median_ms": 1,
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
            },
        }

    with tempfile.TemporaryDirectory(prefix="perf-compare-query-groups-") as temp_dir:
        ref = Path(temp_dir) / "ref.json"
        target = Path(temp_dir) / "target.json"
        ref.write_text(
            json.dumps(
                capture(
                    30,
                    [
                        {
                            "count": 2,
                            "sql": "SELECT * FROM wp_posts WHERE ID = ? LIMIT ?",
                            "top_callers": ["WC_Order_Data_Store_CPT->read"],
                        }
                    ],
                )
            ),
            encoding="utf-8",
        )
        target.write_text(
            json.dumps(
                capture(
                    39,
                    [
                        {
                            "count": 5,
                            "sql": "SELECT * FROM wp_posts WHERE ID = ? LIMIT ?",
                            "top_callers": ["OrderPaymentLifecycleService->apply_unlocked"],
                        }
                    ],
                )
            ),
            encoding="utf-8",
        )

        result = subprocess.run(
            ["python3", str(COMPARE_SCRIPT), "perf", "--ref", str(ref), "--target", str(target)],
            cwd=REPO,
            text=True,
            stdout=subprocess.PIPE,
            stderr=subprocess.PIPE,
            check=False,
        )

    assert result.returncode == 1
    assert "note  capture: target top query groups" in result.stdout
    assert "5x SELECT * FROM wp_posts WHERE ID = ? LIMIT ?" in result.stdout
    assert "OrderPaymentLifecycleService->apply_unlocked" in result.stdout


def test_full_evidence_executes_nested_self_check_and_tracks_verifier() -> None:
    with tempfile.TemporaryDirectory(prefix="verify-final-evidence-") as tmp:
        repo = Path(tmp) / "repo"
        merge_dir = repo / "tools" / "woopayments-merge"
        critical_dir = repo / "tools" / "woopayments-critical-flows"
        critical_setup_dir = critical_dir / "setup"
        wcpay_repo = Path(tmp) / "woocommerce-payments"
        merge_dir.mkdir(parents=True)
        critical_dir.mkdir(parents=True)
        critical_setup_dir.mkdir()
        write_fake_evidence_context_script(critical_dir / "evidence_context.py")
        wcpay_repo.mkdir()

        invocations = Path(tmp) / "invocations.log"
        fake_bin = Path(tmp) / "bin"
        fake_wp = fake_bin / "wp"
        fake_tmp = Path(tmp) / "tmp"
        fake_wpcom_home = Path(tmp) / "wpcom-local-home"
        fake_bin.mkdir()
        fake_tmp.mkdir()
        full_evidence_dir = Path(tmp) / "final-evidence"
        (fake_wpcom_home / "secrets").mkdir(parents=True)
        (fake_wpcom_home / "secrets" / "tracks.json").write_text(
            '{"sink_token":"wpcom_local_tracks_test"}\n',
            encoding="utf-8",
        )

        verify_copy = write_verify_fixture(merge_dir)
        (merge_dir / "a4aq-bundle-budget.json").write_text("{}\n", encoding="utf-8")
        (wcpay_repo / "woocommerce-payments.php").write_text("<?php\n", encoding="utf-8")

        fake_gate = """#!/usr/bin/env bash
set -eu
name="$(basename "$0")"
printf '%s|%s\n' "$name" "$*" >> "$INVOCATIONS_LOG"
out=""
previous=""
for arg in "$@"; do
    if [ "$previous" = "--out" ]; then
        out="$arg"
        previous=""
        continue
    fi
    previous="$arg"
done
if [ -n "$out" ]; then
    mkdir -p "$(dirname "$out")"
    printf '{}\n' > "$out"
fi
if [ "$name" = "flow-drive.sh" ]; then
    printf '{"order_id":123}\n'
fi
if [ "$name" = "tracks-parity.sh" ] && [ "${1:-}" = "normalize" ]; then
    printf '{"event":"checkout"}\n'
fi
exit 0
"""
        for script_name in (
            "bc-drift-gate.sh",
            "subsystem-disposition-gate.sh",
            "native-hook-naming-gate.sh",
            "run-self-tests.sh",
            "hook-shape-parity.sh",
            "rest-route-parity.sh",
            "i18n-notes-gate.sh",
            "flow-drive.sh",
            "parity-diff.sh",
            "perf-baseline.sh",
            "financial-reconcile.sh",
            "tracks-parity.sh",
            "subscriptions-renewal-gate.sh",
            "plugin-active-settings-gate.sh",
            "lpm-checkout-gate.sh",
            "mc-rates-gate.sh",
            "money-path-parity-gate.sh",
            "token-continuity-gate.sh",
            "dispute-e2e-gate.sh",
            "payout-evidence-gate.sh",
            "converted-currency-gate.sh",
            "bundle-size-gate.sh",
            "perf-surface-gate.sh",
        ):
            write_executable(merge_dir / script_name, fake_gate)
        fake_python_gate = """#!/usr/bin/env python3
import os
import sys
from pathlib import Path

Path(os.environ["INVOCATIONS_LOG"]).open("a", encoding="utf-8").write(
    Path(sys.argv[0]).name + "|" + " ".join(sys.argv[1:]) + "\\n"
)
"""
        for script_name in (
            "a5f-cutover-rehearsal.py",
            "a5g-multisite-runtime-gate.py",
            "a4aq-accumulated-gate.py",
            "sc04-saved-card-gate.py",
        ):
            write_executable(merge_dir / script_name, fake_python_gate)

        write_executable(
            fake_wp,
            """#!/usr/bin/env bash
set -eu
printf 'wp|%s\n' "$*" >> "$INVOCATIONS_LOG"
if [ "${1:-}" = "eval-file" ]; then
    body="$(cat)"
    if printf '%s' "$body" | grep -q "HELPER_OPTION:"; then
        printf 'HELPER_OPTION:enabled:MQ==\n'
        printf 'HELPER_OPTION:sink_endpoint:aHR0cDovL29sZA==\n'
        printf 'HELPER_OPTION:sink_token:ZXhpc3Rpbmc=\n'
        printf 'HELPER_OPTION:capture_browser:MQ==\n'
        printf 'HELPER_OPTION:capture_server:MQ==\n'
    elif printf '%s' "$body" | grep -q "HELPER_RESTORED"; then
        printf 'HELPER_RESTORED\n'
    elif printf '%s' "$body" | grep -q "TRACKING:"; then
        printf 'TRACKING:yes\n'
    elif printf '%s' "$body" | grep -q "TRACKING_SET:"; then
        printf 'TRACKING_SET:%s\n' "${3:-}"
    else
        printf 'ready\n'
    fi
elif [ "${1:-}" = "wpcom-local" ] && [ "${2:-}" = "tracks" ] && [ "${3:-}" = "enable" ]; then
    printf 'Success: Local Tracks capture enabled.\n'
else
    printf '{}\n'
fi
""",
        )
        write_executable(
            fake_bin / "pnpm",
            """#!/usr/bin/env bash
set -eu
printf 'pnpm|%s\n' "$*" >> "$INVOCATIONS_LOG"
if [[ "$*" == *'pgrep -f "vendor/bin/phpuni[t]"'* ]]; then
    exit 1
fi
""",
        )
        write_executable(
            fake_bin / "wpcom-local",
            """#!/usr/bin/env bash
set -eu
if [ "${1:-}" = "tracks" ] && [ "${2:-}" = "path" ]; then
    printf 'Command: tracks path\n'
    printf 'Status: success\n'
    printf 'Context:\n'
    printf '%s\n' '- endpoint_url: http://wpcom.localhost:30001/__wpcom-local/tracks/events'
    exit 0
fi
exit 1
""",
        )
        write_executable(
            critical_dir / "test-inventory.py",
            """#!/usr/bin/env python3
import os
from pathlib import Path
Path(os.environ["INVOCATIONS_LOG"]).open("a", encoding="utf-8").write("critical-inventory|\\n")
""",
        )
        write_executable(
            critical_setup_dir / "fixtures.sh",
            """#!/usr/bin/env bash
set -eu
printf 'critical-fixtures|EVIDENCE_DIR=%s|REF=%s|TARGET=%s|%s\n' "${EVIDENCE_DIR:-}" "${REF_WP_COMMAND:-}" "${TARGET_WP_COMMAND:-}" "$*" >> "$INVOCATIONS_LOG"
""",
        )
        write_executable(
            critical_dir / "run.sh",
            """#!/usr/bin/env bash
set -eu
printf 'critical-run|EVIDENCE_DIR=%s|REF=%s|TARGET=%s|%s\n' "${EVIDENCE_DIR:-}" "${REF_WP_COMMAND:-}" "${TARGET_WP_COMMAND:-}" "$*" >> "$INVOCATIONS_LOG"
""",
        )
        write_executable(
            critical_dir / "build-agent-results.py",
            """#!/usr/bin/env python3
import os
import sys
from pathlib import Path

Path(os.environ["INVOCATIONS_LOG"]).open("a", encoding="utf-8").write(
    Path(sys.argv[0]).name + "|" + " ".join(sys.argv[1:]) + "\\n"
)
if "--out-dir" in sys.argv:
    Path(sys.argv[sys.argv.index("--out-dir") + 1]).mkdir(parents=True, exist_ok=True)
""",
        )

        ref_wp = fake_bin / "reference" / "wp"
        target_wp = fake_bin / "target" / "wp"
        write_runtime_identity_wp_wrapper(
            ref_wp, delegate=fake_wp, owner="plugin", site_url="http://localhost:8082"
        )
        write_runtime_identity_wp_wrapper(
            target_wp,
            delegate=fake_wp,
            owner="native",
            site_url="http://store8889.localhost:8889",
        )
        runner_args = write_approved_docker_fixture(
            fake_bin,
            ref_wp=ref_wp,
            target_wp=target_wp,
        )

        result = subprocess.run(
            [
                "bash",
                str(verify_copy),
                *runner_args,
                "--tracks-ref-store-id",
                "ref-store",
                "--tracks-target-store-id",
                "target-store",
                "--full-evidence-out-dir",
                str(full_evidence_dir),
                "--full-evidence",
                "--browser-runner",
                "playwright",
                "--playwriter-session",
                "session-1",
                "--checkout-playwriter-session",
                "checkout-session-1",
                "--ms07-reference-browser",
                str(Path(tmp) / "ms07-reference-browser.json"),
                "--ms07-reference-state",
                str(Path(tmp) / "ms07-reference-state.json"),
                "--ms07-target-browser",
                str(Path(tmp) / "ms07-target-browser.json"),
                "--ms07-target-state",
                str(Path(tmp) / "ms07-target-state.json"),
                "--ref-subscription-id",
                "101",
                "--target-subscription-id",
                "202",
                "--token-customer-id",
                "cus_test",
                "--token-renewal-product-id",
                "116",
                "--perf-ref-process-order-id",
                "11",
                "--perf-target-process-order-id",
                "22",
                "--perf-ref-refund-order-id",
                "33",
                "--perf-target-refund-order-id",
                "44",
                "--perf-ref-capture-order-id",
                "55",
                "--perf-target-capture-order-id",
                "66",
            ],
            cwd=repo,
            text=True,
            stdout=subprocess.PIPE,
            stderr=subprocess.PIPE,
            env={
                "INVOCATIONS_LOG": str(invocations),
                "TMPDIR": str(fake_tmp),
                "WCPAY_REPO": str(wcpay_repo),
                "WPCOM_LOCAL_HOME": str(fake_wpcom_home),
                "PATH": str(fake_bin) + ":" + "/bin:/usr/bin:/usr/local/bin",
                "REF_WP_ADMIN_PASSWORD": "reference-admin-secret",
                "TARGET_WP_ADMIN_PASSWORD": "target-admin-secret",
            },
            check=False,
        )

        assert result.returncode == 0, result.stdout + result.stderr
        final_summary = result.stdout.rsplit("\nSummary:", maxsplit=1)[-1]
        assert "RESULT: PASS - full-evidence gates passed" in final_summary
        assert "this is NOT full merge verification" not in final_summary

        log_dir = full_evidence_dir / "logs"
        assert log_dir.is_dir()
        gate_logs = sorted(log_dir.glob("woopayments-merge-gate.*.log"))
        assert len(gate_logs) >= 20
        assert all("XXXXXX" not in path.name for path in gate_logs)
        self_check_logs = [
            path for path in gate_logs if "final-evidence-self-check-verifier" in path.name
        ]
        assert self_check_logs
        self_check_log = self_check_logs[0].read_text(encoding="utf-8")
        assert "GATE: final evidence self-check verifier" in self_check_log
        assert "COMMAND: bash" in self_check_log
        assert any(
            "WooPayments-merge verification loop" in path.read_text(encoding="utf-8")
            for path in gate_logs
        )
        all_gate_logs = "\n".join(path.read_text(encoding="utf-8") for path in gate_logs)
        assert "reference-admin-secret" not in all_gate_logs
        assert "target-admin-secret" not in all_gate_logs

        invocation_log = invocations.read_text(encoding="utf-8")
        ref_runner = runner_args[runner_args.index("--ref") + 1]
        target_runner = runner_args[runner_args.index("--target") + 1]
        assert "tracks-parity.sh|reset" not in invocation_log
        assert "tracks-parity.sh|mark " in invocation_log
        assert "tracks-parity.sh|normalize --mark " in invocation_log
        assert "--store ref-store" in invocation_log
        assert "--store target-store" in invocation_log
        assert (
            "tracks-parity.sh|diff "
            f"{full_evidence_dir}/tracks-parity/reference-tracks.txt "
            f"{full_evidence_dir}/tracks-parity/target-tracks.txt"
        ) in invocation_log
        assert "tracks-parity.sh|diff" in invocation_log
        assert "wp|wpcom-local tracks enable" in invocation_log
        assert "critical-run|EVIDENCE_DIR=" in invocation_log
        assert "critical-fixtures|EVIDENCE_DIR=" in invocation_log
        assert f"critical-fixtures|EVIDENCE_DIR={full_evidence_dir}/critical-flows|REF={ref_runner}|TARGET={target_runner}|" in invocation_log
        assert f"critical-run|EVIDENCE_DIR={full_evidence_dir}/critical-flows|REF={ref_runner}|TARGET={target_runner}|" in invocation_log
        critical_flow_run = next(
            line for line in invocation_log.splitlines() if line.startswith("critical-run|")
        )
        assert "--ref-url http://localhost:8082" in critical_flow_run
        assert "--target-url http://store8889.localhost:8889" in critical_flow_run
        assert "|fixture_all ref" in invocation_log
        assert "|fixture_all target" in invocation_log
        assert "evidence_context.py|create --repo " in invocation_log
        assert "--ref-subscription-id 101" in invocation_log
        assert "--target-subscription-id 202" in invocation_log
        assert f"--out {full_evidence_dir}/critical-flow-context.json" in invocation_log
        assert "sc04-saved-card-gate.py|--repo " in invocation_log
        assert f"--context-file {full_evidence_dir}/critical-flow-context.json" in invocation_log
        assert f"--out-dir {full_evidence_dir}/sc04-saved-card" in invocation_log
        assert "--browser-runner playwright" in invocation_log
        assert "build-agent-results.py|--context-file " in invocation_log
        assert "--out-dir " in invocation_log
        assert f"--context-file {full_evidence_dir}/critical-flow-context.json" in invocation_log
        assert "--require-sc04" in invocation_log
        assert "--sc04-reference-browser " in invocation_log
        assert f"{full_evidence_dir}/sc04-saved-card/reference-browser.json" in invocation_log
        assert "--sc04-reference-state " in invocation_log
        assert f"{full_evidence_dir}/sc04-saved-card/reference-state.json" in invocation_log
        assert "--sc04-target-browser " in invocation_log
        assert f"{full_evidence_dir}/sc04-saved-card/target-browser.json" in invocation_log
        assert "--sc04-target-state " in invocation_log
        assert f"{full_evidence_dir}/sc04-saved-card/target-state.json" in invocation_log
        assert "--require-ms07" in invocation_log
        assert "--ms07-reference-subscription-id" not in invocation_log
        assert "--ms07-target-subscription-id" not in invocation_log
        assert "--ms07-reference-browser " in invocation_log
        assert "ms07-reference-browser.json" in invocation_log
        assert "--ms07-reference-state " in invocation_log
        assert "ms07-reference-state.json" in invocation_log
        assert "--ms07-target-browser " in invocation_log
        assert "ms07-target-browser.json" in invocation_log
        assert "--ms07-target-state " in invocation_log
        assert "ms07-target-state.json" in invocation_log
        assert "--plugin-active-reference-gate " in invocation_log
        assert "--plugin-active-target-gate " in invocation_log
        assert "--lpm-gate " in invocation_log
        assert "--token-continuity-gate " in invocation_log
        assert (
            "|--store both --layer all "
            "--ref-url http://localhost:8082 "
            "--target-url http://store8889.localhost:8889 "
            "--agent-results-dir "
        ) in invocation_log
        assert f"--context-file {full_evidence_dir}/critical-flow-context.json" in invocation_log
        assert "/critical-flows-agent-results" in invocation_log
        assert invocation_log.count("flow-drive.sh|charge") == 3
        assert invocation_log.count("rest-route-parity.sh|") == 3
        assert invocation_log.count("hook-shape-parity.sh|") == 3
        assert invocation_log.count("subsystem-disposition-gate.sh|") == 3
        assert invocation_log.count("i18n-notes-gate.sh|") == 2
        assert "subscriptions-renewal-gate.sh|compare" in invocation_log
        assert invocation_log.count("money-path-parity-gate.sh|--ref ") == 1
        assert f"--out-dir {full_evidence_dir}/money-path-parity" in invocation_log
        assert "--ref-subscription-id 101" in invocation_log
        assert "--target-subscription-id 202" in invocation_log
        assert "plugin-active-settings-gate.sh|--target " in invocation_log
        assert "plugin-active-settings-gate.sh|--target " in invocation_log and "--browser-runner playwright" in invocation_log
        plugin_active_invocations = [
            line
            for line in invocation_log.splitlines()
            if line.startswith("plugin-active-settings-gate.sh|")
        ]
        assert len(plugin_active_invocations) == 2
        assert any("--runner-role reference" in line for line in plugin_active_invocations)
        assert any("--runner-role target" in line for line in plugin_active_invocations)
        assert "lpm-checkout-gate.sh|--methods" in invocation_log and "--browser-runner playwright" in invocation_log
        assert "--stage-plugin-active-fixture" in invocation_log
        assert "--stage-sepa-fixture" in invocation_log
        assert "--source-flow provider-setup-intent" in invocation_log
        assert "token-continuity-gate.sh|--target " in invocation_log and "--browser-runner playwright" in invocation_log
        assert "--checkout-product-id" not in invocation_log
        assert "--renewal-product-id 116" in invocation_log
        assert "--subscription-id sub_test" not in invocation_log
        assert "--out-dir " in invocation_log
        assert "/subscriptions-renewal" in invocation_log
        assert "a4aq-accumulated-gate.py|" in invocation_log
        assert "--browser-runner playwright" in invocation_log
        assert "--plugin-repo " in invocation_log
        assert "/a4aq-accumulated" in invocation_log
        assert "bundle-size-gate.sh|capture --repo " in invocation_log
        assert "--profile wcpay-plugin" in invocation_log
        assert "--profile wc-core" in invocation_log
        assert "bundle-size-gate.sh|compare --ref " in invocation_log
        assert "--budget " in invocation_log
        assert "/bundle-reference.json" in invocation_log
        assert "/bundle-target.json" in invocation_log
        assert "perf-surface-gate.sh|capture --wp " in invocation_log
        assert "perf-surface-gate.sh|compare --ref " in invocation_log
        assert "/perf-reference.json" in invocation_log
        assert "/perf-target.json" in invocation_log
        assert "--process-order-id 11" in invocation_log
        assert "--refund-order-id 33" in invocation_log
        assert "--capture-order-id 55" in invocation_log
        assert "--process-order-id 22" in invocation_log
        assert "--refund-order-id 44" in invocation_log
        assert "--capture-order-id 66" in invocation_log
        assert invocation_log.index("a4aq-accumulated-gate.py|") < invocation_log.index("critical-inventory|")
        assert invocation_log.index("bundle-size-gate.sh|capture --repo ") < invocation_log.index("a4aq-accumulated-gate.py|")
        assert invocation_log.index("perf-surface-gate.sh|capture --wp ") < invocation_log.index("a4aq-accumulated-gate.py|")
        assert invocation_log.index("critical-inventory|") < invocation_log.index("critical-fixtures|")
        assert invocation_log.index("|fixture_all ref") < invocation_log.index("|fixture_all target")
        assert invocation_log.index("|fixture_all target") < invocation_log.index("evidence_context.py|create")
        assert invocation_log.index("evidence_context.py|create") < invocation_log.index("sc04-saved-card-gate.py|")
        assert invocation_log.index("sc04-saved-card-gate.py|") < invocation_log.index("build-agent-results.py|")
        assert invocation_log.index("build-agent-results.py|") < invocation_log.index("critical-run|")
        assert 'pgrep -f "vendor/bin/phpuni[t]"' in invocation_log
        assert "pkill -f \"vendor/bin/phpuni[t]\"" not in invocation_log
        assert "DELETE FROM wp_actionscheduler_actions" not in invocation_log
        assert invocation_log.index("critical-run|") < invocation_log.index('pgrep -f "vendor/bin/phpuni[t]"')
        assert invocation_log.index('pgrep -f "vendor/bin/phpuni[t]"') < invocation_log.index("pnpm|--filter=@woocommerce/plugin-woocommerce test:php:env -- --filter WooPayments")
        assert_occurs_in_order(
            invocation_log,
            [
                "money-path-parity-gate.sh|--ref ",
                "subscriptions-renewal-gate.sh|compare",
                "plugin-active-settings-gate.sh|--target ",
                "lpm-checkout-gate.sh|--methods",
                "mc-rates-gate.sh|--ref ",
                "token-continuity-gate.sh|--target ",
                "a5f-cutover-rehearsal.py|",
                "a5g-multisite-runtime-gate.py|",
                "dispute-e2e-gate.sh|--ref ",
                "payout-evidence-gate.sh|--wp ",
                "payout-evidence-gate.sh|--wp ",
                "converted-currency-gate.sh|--ref ",
                "bundle-size-gate.sh|capture --repo ",
                "bundle-size-gate.sh|capture --repo ",
                "bundle-size-gate.sh|compare --ref ",
                "perf-surface-gate.sh|capture --wp ",
                "perf-surface-gate.sh|capture --wp ",
                "perf-surface-gate.sh|compare --ref ",
                "a4aq-accumulated-gate.py|",
                "critical-inventory|",
                "critical-fixtures|",
                "critical-fixtures|",
                "build-agent-results.py|",
                "critical-run|",
                "pnpm|--filter=@woocommerce/plugin-woocommerce test:php:env -- --filter WooPayments",
                "pnpm|--filter=@woocommerce/admin-library test:js",
                "pnpm|--filter=@woocommerce/admin-library ts:check",
                "pnpm|--filter=@woocommerce/plugin-woocommerce lint:changes:branch",
                "pnpm|--filter=@woocommerce/plugin-woocommerce phpstan",
            ],
        )
        assert "pnpm|--filter=@woocommerce/plugin-woocommerce test:php:env -- --filter WooPayments" in invocation_log
        assert "pnpm|--filter=@woocommerce/admin-library test:js" in invocation_log
        assert "pnpm|--filter=@woocommerce/admin-library ts:check" in invocation_log
        assert "pnpm|--filter=@woocommerce/plugin-woocommerce lint:changes:branch" in invocation_log
        assert "pnpm|--filter=@woocommerce/plugin-woocommerce phpstan" in invocation_log

        invocations.write_text("", encoding="utf-8")
        repeated = subprocess.run(
            result.args,
            cwd=repo,
            text=True,
            stdout=subprocess.PIPE,
            stderr=subprocess.PIPE,
            env={
                "INVOCATIONS_LOG": str(invocations),
                "TMPDIR": str(fake_tmp),
                "WCPAY_REPO": str(wcpay_repo),
                "WPCOM_LOCAL_HOME": str(fake_wpcom_home),
                "PATH": str(fake_bin) + ":" + "/bin:/usr/bin:/usr/local/bin",
            },
            check=False,
        )
        assert repeated.returncode == 3
        assert "full-evidence output directory is not empty" in repeated.stdout
        assert invocations.read_text(encoding="utf-8") == ""


def test_full_evidence_stops_after_nested_cleanup_failure() -> None:
    with tempfile.TemporaryDirectory(prefix="verify-cleanup-safety-stop-") as tmp_name:
        tmp = Path(tmp_name)
        repo = tmp / "repo"
        merge_dir = repo / "tools" / "woopayments-merge"
        merge_dir.mkdir(parents=True)
        verify_copy = write_verify_fixture(merge_dir)
        invocations = tmp / "invocations.log"
        fake_bin = tmp / "bin"
        fake_bin.mkdir()
        fake_tmp = tmp / "tmp"
        fake_tmp.mkdir()

        fake_gate = """#!/usr/bin/env bash
set -eu
name="$(basename "$0")"
printf '%s|%s\n' "$name" "$*" >> "$INVOCATIONS_LOG"
if [ "$name" = "flow-drive.sh" ]; then
    printf '{"order_id":123}\n'
fi
"""
        for script_name in (
            "bc-drift-gate.sh",
            "subsystem-disposition-gate.sh",
            "native-hook-naming-gate.sh",
            "run-self-tests.sh",
            "hook-shape-parity.sh",
            "rest-route-parity.sh",
            "flow-drive.sh",
            "parity-diff.sh",
            "perf-surface-gate.sh",
            "financial-reconcile.sh",
        ):
            write_executable(merge_dir / script_name, fake_gate)

        delegate = fake_bin / "wp"
        write_executable(
            delegate,
            """#!/usr/bin/env bash
set -eu
printf 'wp|%s\n' "$*" >> "$INVOCATIONS_LOG"
if [ "${1:-}" = "eval-file" ]; then
    cat >/dev/null
    printf 'ready\n'
else
    printf '{}\n'
fi
""",
        )
        ref_wp = fake_bin / "reference" / "wp"
        target_wp = fake_bin / "target" / "wp"
        write_runtime_identity_wp_wrapper(
            ref_wp,
            delegate=delegate,
            owner="plugin",
            site_url="http://localhost:8082",
        )
        write_runtime_identity_wp_wrapper(
            target_wp,
            delegate=delegate,
            owner="native",
            site_url="http://store8889.localhost:8889",
        )
        runner_args = write_approved_docker_fixture(
            fake_bin,
            ref_wp=ref_wp,
            target_wp=target_wp,
        )

        result = subprocess.run(
            [
                "bash",
                str(verify_copy),
                *runner_args,
                "--full-evidence",
                "--full-evidence-out-dir",
                str(tmp / "evidence"),
            ],
            cwd=repo,
            env={
                "CLEANUP_FAIL_OWNER": "plugin",
                "INVOCATIONS_LOG": str(invocations),
                "TMPDIR": str(fake_tmp),
                "PATH": str(fake_bin) + ":/bin:/usr/bin:/usr/local/bin",
            },
            text=True,
            stdout=subprocess.PIPE,
            stderr=subprocess.PIPE,
            check=False,
        )

        assert result.returncode == 1, result.stdout + result.stderr
        assert "safety stop: self-check cleanup failed" in result.stdout
        invocation_log = invocations.read_text(encoding="utf-8")
        assert invocation_log.count("flow-drive.sh|") == 1
        assert "tracks-parity.sh|" not in invocation_log
        assert "money-path-parity-gate.sh|" not in invocation_log
        assert "plugin-active-settings-gate.sh|" not in invocation_log


def test_cleanup_exit_70_skips_every_later_gate(tmp_path: Path) -> None:
    source = SCRIPT.read_text(encoding="utf-8")
    gate_runtime = source[
        source.index("PASS=(); FAILED=(); BLOCKED=(); ACKNOWLEDGED=()") : source.index(
            "gate_with_admin_credentials()"
        )
    ]
    for index, (cleanup_label, later_label) in enumerate(
        (
            ("converted-currency charge reconciliation", "bundle size capture (reference)"),
            ("A4aq accumulated admin/checkout/perf evidence", "critical flows inventory"),
            ("SC-04 context-bound saved-card browser evidence", "critical flows agent result synthesis"),
        )
    ):
        marker = tmp_path / f"later-gate-started-{index}"
        probe = tmp_path / f"probe-{index}.sh"
        probe.write_text(
            f"""#!/usr/bin/env bash
set -uo pipefail
SELF_DIR={shlex.quote(str(SCRIPT.parent))}
{gate_runtime}
gate {shlex.quote(cleanup_label)} bash -c 'exit 70'
gate {shlex.quote(later_label)} bash -c {shlex.quote(f'printf started > {marker}')}
exit 0
""",
            encoding="utf-8",
        )
        probe.chmod(0o755)

        result = subprocess.run(
            [str(probe)],
            cwd=REPO,
            env={**os.environ, "TMPDIR": str(tmp_path)},
            text=True,
            stdout=subprocess.PIPE,
            stderr=subprocess.PIPE,
            check=False,
        )

        assert result.returncode == 0, result.stdout + result.stderr
        assert f"safety stop armed: cleanup failed in {cleanup_label}" in result.stdout
        assert later_label in result.stdout
        assert not marker.exists()


def test_gate_commands_run_under_a_conservative_default_timeout(tmp_path: Path) -> None:
    source = SCRIPT.read_text(encoding="utf-8")
    # A hung docker exec or browser launch must not stall the no-HITL loop:
    # every gate runs bounded by default, overridable via env or per-gate prefix
    # (0 disables the bound).
    assert 'GATE_TIMEOUT_SECONDS_DEFAULT="${GATE_TIMEOUT_SECONDS_DEFAULT:-3600}"' in source
    assert 'timeout_seconds="${GATE_TIMEOUT_SECONDS:-${GATE_TIMEOUT_SECONDS_DEFAULT:-3600}}"' in source
    # The harness self-tests and quality gates keep their dedicated bounds.
    assert 'GATE_TIMEOUT_SECONDS="${HARNESS_SELF_TESTS_TIMEOUT_SECONDS:-2700}" gate "harness self-tests"' in source
    assert 'GATE_TIMEOUT_SECONDS="$FULL_EVIDENCE_QUALITY_GATE_TIMEOUT_SECONDS" gate "$label" "$@"' in source
    assert 'FULL_EVIDENCE_QUALITY_GATE_TIMEOUT_SECONDS="${FULL_EVIDENCE_QUALITY_GATE_TIMEOUT_SECONDS:-1800}"' in source

    gate_runtime = source[
        source.index("PASS=(); FAILED=(); BLOCKED=(); ACKNOWLEDGED=()") : source.index(
            "gate_with_admin_credentials()"
        )
    ]
    log_dir = tmp_path / "logs"
    probe = tmp_path / "gate-default-timeout-probe.sh"
    probe.write_text(
        f"""#!/usr/bin/env bash
set -uo pipefail
SELF_DIR={shlex.quote(str(SCRIPT.parent))}
GATE_LOG_DIR={shlex.quote(str(log_dir))}
{gate_runtime}
gate "default timed gate" bash -c 'exit 0'
GATE_TIMEOUT_SECONDS=0 gate "untimed opt-out gate" bash -c 'exit 0'
GATE_TIMEOUT_SECONDS=1 gate "hung gate" bash -c 'sleep 30'
printf 'hung_gate_rc=%s\\n' "$LAST_GATE_RC"
exit 0
""",
        encoding="utf-8",
    )
    probe.chmod(0o755)

    result = subprocess.run(
        ["bash", str(probe)],
        cwd=REPO,
        env={**os.environ, "TMPDIR": str(tmp_path)},
        text=True,
        stdout=subprocess.PIPE,
        stderr=subprocess.PIPE,
        check=False,
        timeout=30,
    )

    assert result.returncode == 0, result.stdout + result.stderr
    assert "hung_gate_rc=124" in result.stdout
    assert "[BLOCKED] hung gate" in result.stdout
    default_logs = sorted(log_dir.glob("woopayments-merge-gate.default-timed-gate.*.log"))
    untimed_logs = sorted(log_dir.glob("woopayments-merge-gate.untimed-opt-out-gate.*.log"))
    assert default_logs and "TIMEOUT_SECONDS: 3600" in default_logs[0].read_text(encoding="utf-8")
    assert untimed_logs and "TIMEOUT_SECONDS" not in untimed_logs[0].read_text(encoding="utf-8")


def test_flow_drive_diagnostics_are_logged_and_preconditions_block(tmp_path: Path) -> None:
    source = SCRIPT.read_text(encoding="utf-8")
    # flow-drive stderr is never discarded any more, and flow-drive's own 2/3
    # precondition exits are classified BLOCKED instead of an evidence-free FAIL.
    assert "2>/dev/null | sed -n" not in source
    assert 'record "flow-drive (charge)" BLOCKED' in source
    assert 'record "target flow-drive (charge)" BLOCKED' in source
    assert source.count("printf '      log: %s\\n' \"$FLOW_DRIVE_LOG\"") == 4

    gate_runtime = source[
        source.index("PASS=(); FAILED=(); BLOCKED=(); ACKNOWLEDGED=()") : source.index(
            "gate_with_admin_credentials()"
        )
    ]
    self_dir = tmp_path / "merge"
    self_dir.mkdir()
    write_executable(
        self_dir / "flow-drive.sh",
        """#!/usr/bin/env bash
echo "preconditions missing: connect a test account first" >&2
if [ "${FLOW_DRIVE_FAKE_MODE:-blocked}" = "pass" ]; then
    printf '{"order_id":123}\\n'
    exit 0
fi
exit 3
""",
    )
    log_dir = tmp_path / "logs"
    probe = tmp_path / "flow-drive-probe.sh"
    probe.write_text(
        f"""#!/usr/bin/env bash
set -uo pipefail
SELF_DIR={shlex.quote(str(self_dir))}
GATE_LOG_DIR={shlex.quote(str(log_dir))}
{gate_runtime}
flow_drive_charge "flow-drive (charge)" "wp-runner" charge --deterministic
printf 'rc=%s ids=[%s]\\n' "$FLOW_DRIVE_RC" "$FLOW_DRIVE_IDS"
printf 'log=%s\\n' "$FLOW_DRIVE_LOG"
export FLOW_DRIVE_FAKE_MODE=pass
flow_drive_charge "flow-drive (charge)" "wp-runner" charge --deterministic
printf 'rc2=%s ids2=[%s]\\n' "$FLOW_DRIVE_RC" "$FLOW_DRIVE_IDS"
exit 0
""",
        encoding="utf-8",
    )
    probe.chmod(0o755)

    result = subprocess.run(
        ["bash", str(probe)],
        cwd=REPO,
        env={**os.environ, "TMPDIR": str(tmp_path)},
        text=True,
        stdout=subprocess.PIPE,
        stderr=subprocess.PIPE,
        check=False,
        timeout=30,
    )

    assert result.returncode == 0, result.stdout + result.stderr
    # Precondition exit is surfaced (2/3 -> BLOCKED at the call site), never
    # silently flattened into an empty-ids FAIL.
    assert "rc=3 ids=[]" in result.stdout
    # A passing run still yields the parsed order ids.
    assert "rc2=0 ids2=[123 ]" in result.stdout
    log_line = next(line for line in result.stdout.splitlines() if line.startswith("log="))
    log_path = Path(log_line[len("log=") :])
    assert log_path.is_file()
    assert log_path.parent == log_dir
    assert log_path.name.startswith("woopayments-merge-gate.flow-drive-charge.")
    log_text = log_path.read_text(encoding="utf-8")
    assert "GATE: flow-drive (charge)" in log_text
    assert "preconditions missing: connect a test account first" in log_text
    assert "EXIT_CODE: 3" in log_text


def test_full_evidence_blocks_browser_gates_without_playwriter_session() -> None:
    with tempfile.TemporaryDirectory(prefix="verify-final-evidence-no-browser-") as tmp:
        repo = Path(tmp) / "repo"
        merge_dir = repo / "tools" / "woopayments-merge"
        critical_dir = repo / "tools" / "woopayments-critical-flows"
        critical_setup_dir = critical_dir / "setup"
        wcpay_repo = Path(tmp) / "woocommerce-payments"
        merge_dir.mkdir(parents=True)
        critical_dir.mkdir(parents=True)
        critical_setup_dir.mkdir()
        write_fake_evidence_context_script(critical_dir / "evidence_context.py")
        wcpay_repo.mkdir()

        invocations = Path(tmp) / "invocations.log"
        fake_bin = Path(tmp) / "bin"
        fake_wp = fake_bin / "wp"
        fake_tmp = Path(tmp) / "tmp"
        fake_wpcom_home = Path(tmp) / "wpcom-local-home"
        fake_bin.mkdir()
        fake_tmp.mkdir()
        full_evidence_dir = Path(tmp) / "final-evidence"
        (fake_wpcom_home / "secrets").mkdir(parents=True)
        (fake_wpcom_home / "secrets" / "tracks.json").write_text(
            '{"sink_token":"wpcom_local_tracks_test"}\n',
            encoding="utf-8",
        )

        verify_copy = write_verify_fixture(merge_dir)
        (merge_dir / "a4aq-bundle-budget.json").write_text("{}\n", encoding="utf-8")
        (wcpay_repo / "woocommerce-payments.php").write_text("<?php\n", encoding="utf-8")

        fake_gate = """#!/usr/bin/env bash
set -eu
name="$(basename "$0")"
printf '%s|%s\n' "$name" "$*" >> "$INVOCATIONS_LOG"
if [ "$name" = "flow-drive.sh" ]; then
    printf '{"order_id":123}\n'
fi
if [ "$name" = "tracks-parity.sh" ] && [ "${1:-}" = "normalize" ]; then
    printf '{"event":"checkout"}\n'
fi
exit 0
"""
        for script_name in (
            "bc-drift-gate.sh",
            "subsystem-disposition-gate.sh",
            "native-hook-naming-gate.sh",
            "run-self-tests.sh",
            "hook-shape-parity.sh",
            "rest-route-parity.sh",
            "i18n-notes-gate.sh",
            "flow-drive.sh",
            "parity-diff.sh",
            "perf-baseline.sh",
            "financial-reconcile.sh",
            "tracks-parity.sh",
            "subscriptions-renewal-gate.sh",
            "plugin-active-settings-gate.sh",
            "lpm-checkout-gate.sh",
            "mc-rates-gate.sh",
            "money-path-parity-gate.sh",
            "token-continuity-gate.sh",
            "dispute-e2e-gate.sh",
            "payout-evidence-gate.sh",
            "converted-currency-gate.sh",
            "bundle-size-gate.sh",
            "perf-surface-gate.sh",
        ):
            write_executable(merge_dir / script_name, fake_gate)

        fake_python_gate = """#!/usr/bin/env python3
import os
import sys
from pathlib import Path
Path(os.environ["INVOCATIONS_LOG"]).open("a", encoding="utf-8").write(
    Path(sys.argv[0]).name + "|" + " ".join(sys.argv[1:]) + "\\n"
)
"""
        for script_name in (
            "a5f-cutover-rehearsal.py",
            "a5g-multisite-runtime-gate.py",
            "a4aq-accumulated-gate.py",
        ):
            write_executable(merge_dir / script_name, fake_python_gate)
        write_executable(
            merge_dir / "perf-fixtures-gate.py",
            """#!/usr/bin/env python3
import json
import os
import sys
from pathlib import Path

Path(os.environ["INVOCATIONS_LOG"]).open("a", encoding="utf-8").write(
    Path(sys.argv[0]).name + "|" + " ".join(sys.argv[1:]) + "\\n"
)
out = Path(sys.argv[sys.argv.index("--out") + 1])
out.parent.mkdir(parents=True, exist_ok=True)
out.write_text(json.dumps({
    "status": "pass",
    "stores": {
        "reference": {"order_ids": {"process_payment": 11, "refund": 33, "capture": 55}},
        "target": {"order_ids": {"process_payment": 22, "refund": 44, "capture": 66}},
    },
}) + "\\n", encoding="utf-8")
""",
        )

        write_executable(
            fake_wp,
            """#!/usr/bin/env bash
set -eu
printf 'wp|%s\n' "$*" >> "$INVOCATIONS_LOG"
if [ "${1:-}" = "eval-file" ]; then
    body="$(cat)"
    if printf '%s' "$body" | grep -q "HELPER_OPTION:"; then
        printf 'HELPER_OPTION:enabled:MQ==\n'
        printf 'HELPER_OPTION:sink_endpoint:aHR0cDovL29sZA==\n'
        printf 'HELPER_OPTION:sink_token:ZXhpc3Rpbmc=\n'
        printf 'HELPER_OPTION:capture_browser:MQ==\n'
        printf 'HELPER_OPTION:capture_server:MQ==\n'
    elif printf '%s' "$body" | grep -q "HELPER_RESTORED"; then
        printf 'HELPER_RESTORED\n'
    elif printf '%s' "$body" | grep -q "TRACKING:"; then
        printf 'TRACKING:yes\n'
    elif printf '%s' "$body" | grep -q "TRACKING_SET:"; then
        printf 'TRACKING_SET:%s\n' "${3:-}"
    else
        printf 'ready\n'
    fi
elif [ "${1:-}" = "wpcom-local" ] && [ "${2:-}" = "tracks" ] && [ "${3:-}" = "enable" ]; then
    printf 'Success: Local Tracks capture enabled.\n'
else
    printf '{}\n'
fi
""",
        )
        write_executable(
            fake_bin / "pnpm",
            """#!/usr/bin/env bash
set -eu
printf 'pnpm|%s\n' "$*" >> "$INVOCATIONS_LOG"
if [[ "$*" == *'pgrep -f "vendor/bin/phpuni[t]"'* ]]; then
    exit 1
fi
""",
        )
        write_executable(
            fake_bin / "wpcom-local",
            """#!/usr/bin/env bash
set -eu
if [ "${1:-}" = "tracks" ] && [ "${2:-}" = "path" ]; then
    printf 'Command: tracks path\n'
    printf 'Status: success\n'
    printf 'Context:\n'
    printf '%s\n' '- endpoint_url: http://wpcom.localhost:30001/__wpcom-local/tracks/events'
    exit 0
fi
exit 1
""",
        )
        write_executable(
            critical_dir / "test-inventory.py",
            """#!/usr/bin/env python3
import os
from pathlib import Path
Path(os.environ["INVOCATIONS_LOG"]).open("a", encoding="utf-8").write("critical-inventory|\\n")
""",
        )
        write_executable(
            critical_setup_dir / "fixtures.sh",
            """#!/usr/bin/env bash
set -eu
printf 'critical-fixtures|%s\n' "$*" >> "$INVOCATIONS_LOG"
""",
        )
        write_executable(
            critical_dir / "run.sh",
            """#!/usr/bin/env bash
set -eu
printf 'critical-run|%s\n' "$*" >> "$INVOCATIONS_LOG"
""",
        )
        write_executable(
            critical_dir / "build-agent-results.py",
            """#!/usr/bin/env python3
import os
import sys
from pathlib import Path
Path(os.environ["INVOCATIONS_LOG"]).open("a", encoding="utf-8").write(
    Path(sys.argv[0]).name + "|" + " ".join(sys.argv[1:]) + "\\n"
)
if "--out-dir" in sys.argv:
    Path(sys.argv[sys.argv.index("--out-dir") + 1]).mkdir(parents=True, exist_ok=True)
""",
        )

        ref_wp = fake_bin / "reference" / "wp"
        target_wp = fake_bin / "target" / "wp"
        write_runtime_identity_wp_wrapper(
            ref_wp, delegate=fake_wp, owner="plugin", site_url="http://localhost:8082"
        )
        write_runtime_identity_wp_wrapper(
            target_wp,
            delegate=fake_wp,
            owner="native",
            site_url="http://store8889.localhost:8889",
        )
        runner_args = write_approved_docker_fixture(
            fake_bin,
            ref_wp=ref_wp,
            target_wp=target_wp,
        )

        result = subprocess.run(
            [
                "bash",
                str(verify_copy),
                *runner_args,
                "--full-evidence-out-dir",
                str(full_evidence_dir),
                "--full-evidence",
                "--browser-runner",
                "playwriter",
                "--ref-subscription-id",
                "101",
                "--target-subscription-id",
                "202",
                "--token-customer-id",
                "cus_test",
                "--token-renewal-product-id",
                "116",
            ],
            cwd=repo,
            text=True,
            stdout=subprocess.PIPE,
            stderr=subprocess.PIPE,
            env={
                "INVOCATIONS_LOG": str(invocations),
                "TMPDIR": str(fake_tmp),
                "WCPAY_REPO": str(wcpay_repo),
                "WPCOM_LOCAL_HOME": str(fake_wpcom_home),
                "PATH": str(fake_bin) + ":" + "/bin:/usr/bin:/usr/local/bin",
            },
            check=False,
        )

        assert result.returncode == 3, result.stdout + result.stderr
        assert "RESULT: INCOMPLETE" in result.stdout
        assert "plugin-active settings screen" in result.stdout
        assert "LPM all-method checkout" in result.stdout
        assert "token continuity cutover" in result.stdout
        assert "A5f cutover rehearsal" in result.stdout
        assert "A4aq accumulated admin/checkout/perf evidence" in result.stdout
        assert result.stdout.count("pass --playwriter-session") >= 3
        assert result.stdout.count("pass --checkout-playwriter-session") >= 2

        invocation_log = invocations.read_text(encoding="utf-8")
        assert "plugin-active-settings-gate.sh|" not in invocation_log
        assert "lpm-checkout-gate.sh|" not in invocation_log
        assert "token-continuity-gate.sh|" not in invocation_log
        assert "a5f-cutover-rehearsal.py|" not in invocation_log
        assert "a4aq-accumulated-gate.py|" not in invocation_log
        assert "build-agent-results.py|--context-file " in invocation_log
        assert "--require-sc04" in invocation_log
        assert "--require-ms07" in invocation_log
        assert "mc-rates-gate.sh|" in invocation_log
        assert "subscriptions-renewal-gate.sh|compare" in invocation_log
        assert "perf-fixtures-gate.py|--repo " in invocation_log
        assert " --ref-wp " in invocation_log
        assert " --target-wp " in invocation_log
        assert "--out " in invocation_log
        assert "/perf-fixtures.json" in invocation_log
        assert "perf-surface-gate.sh|capture --wp " in invocation_log
        assert "--process-order-id 11" in invocation_log
        assert "--refund-order-id 33" in invocation_log
        assert "--capture-order-id 55" in invocation_log
        assert "--process-order-id 22" in invocation_log
        assert "--refund-order-id 44" in invocation_log
        assert "--capture-order-id 66" in invocation_log
        assert invocation_log.index("converted-currency-gate.sh|--ref ") < invocation_log.index("perf-fixtures-gate.py|")
        perf_fixture_position = invocation_log.index("perf-fixtures-gate.py|")
        assert perf_fixture_position < invocation_log.index("perf-surface-gate.sh|capture --wp ", perf_fixture_position)


def test_full_evidence_quality_gate_timeout_is_blocked_not_hung() -> None:
    with tempfile.TemporaryDirectory(prefix="verify-final-evidence-timeout-") as tmp:
        repo = Path(tmp) / "repo"
        merge_dir = repo / "tools" / "woopayments-merge"
        critical_dir = repo / "tools" / "woopayments-critical-flows"
        critical_setup_dir = critical_dir / "setup"
        wcpay_repo = Path(tmp) / "woocommerce-payments"
        merge_dir.mkdir(parents=True)
        critical_dir.mkdir(parents=True)
        critical_setup_dir.mkdir()
        write_fake_evidence_context_script(critical_dir / "evidence_context.py")
        wcpay_repo.mkdir()

        invocations = Path(tmp) / "invocations.log"
        fake_bin = Path(tmp) / "bin"
        fake_wp = fake_bin / "wp"
        fake_tmp = Path(tmp) / "tmp"
        fake_wpcom_home = Path(tmp) / "wpcom-local-home"
        fake_bin.mkdir()
        fake_tmp.mkdir()
        full_evidence_dir = Path(tmp) / "final-evidence"
        (fake_wpcom_home / "secrets").mkdir(parents=True)
        (fake_wpcom_home / "secrets" / "tracks.json").write_text(
            '{"sink_token":"wpcom_local_tracks_test"}\n',
            encoding="utf-8",
        )

        verify_copy = write_verify_fixture(merge_dir)
        (merge_dir / "a4aq-bundle-budget.json").write_text("{}\n", encoding="utf-8")
        (wcpay_repo / "woocommerce-payments.php").write_text("<?php\n", encoding="utf-8")

        fake_gate = """#!/usr/bin/env bash
set -eu
name="$(basename "$0")"
printf '%s|%s\n' "$name" "$*" >> "$INVOCATIONS_LOG"
out=""
previous=""
for arg in "$@"; do
    if [ "$previous" = "--out" ]; then
        out="$arg"
        previous=""
        continue
    fi
    previous="$arg"
done
if [ -n "$out" ]; then
    mkdir -p "$(dirname "$out")"
    printf '{}\n' > "$out"
fi
if [ "$name" = "flow-drive.sh" ]; then
    printf '{"order_id":123}\n'
fi
if [ "$name" = "tracks-parity.sh" ] && [ "${1:-}" = "normalize" ]; then
    printf '{"event":"checkout"}\n'
fi
exit 0
"""
        for script_name in (
            "bc-drift-gate.sh",
            "subsystem-disposition-gate.sh",
            "native-hook-naming-gate.sh",
            "run-self-tests.sh",
            "hook-shape-parity.sh",
            "rest-route-parity.sh",
            "i18n-notes-gate.sh",
            "flow-drive.sh",
            "parity-diff.sh",
            "perf-baseline.sh",
            "financial-reconcile.sh",
            "tracks-parity.sh",
            "subscriptions-renewal-gate.sh",
            "plugin-active-settings-gate.sh",
            "lpm-checkout-gate.sh",
            "mc-rates-gate.sh",
            "money-path-parity-gate.sh",
            "token-continuity-gate.sh",
            "dispute-e2e-gate.sh",
            "payout-evidence-gate.sh",
            "converted-currency-gate.sh",
            "bundle-size-gate.sh",
            "perf-surface-gate.sh",
        ):
            write_executable(merge_dir / script_name, fake_gate)

        fake_python_gate = """#!/usr/bin/env python3
import os
import sys
from pathlib import Path
Path(os.environ["INVOCATIONS_LOG"]).open("a", encoding="utf-8").write(
    Path(sys.argv[0]).name + "|" + " ".join(sys.argv[1:]) + "\\n"
)
"""
        for script_name in (
            "a5f-cutover-rehearsal.py",
            "a5g-multisite-runtime-gate.py",
            "a4aq-accumulated-gate.py",
        ):
            write_executable(merge_dir / script_name, fake_python_gate)

        write_executable(
            fake_wp,
            """#!/usr/bin/env bash
set -eu
printf 'wp|%s\n' "$*" >> "$INVOCATIONS_LOG"
if [ "${1:-}" = "eval-file" ]; then
    body="$(cat)"
    if printf '%s' "$body" | grep -q "HELPER_OPTION:"; then
        printf 'HELPER_OPTION:enabled:MQ==\n'
        printf 'HELPER_OPTION:sink_endpoint:aHR0cDovL29sZA==\n'
        printf 'HELPER_OPTION:sink_token:ZXhpc3Rpbmc=\n'
        printf 'HELPER_OPTION:capture_browser:MQ==\n'
        printf 'HELPER_OPTION:capture_server:MQ==\n'
    elif printf '%s' "$body" | grep -q "HELPER_RESTORED"; then
        printf 'HELPER_RESTORED\n'
    elif printf '%s' "$body" | grep -q "TRACKING:"; then
        printf 'TRACKING:yes\n'
    elif printf '%s' "$body" | grep -q "TRACKING_SET:"; then
        printf 'TRACKING_SET:%s\n' "${3:-}"
    else
        printf 'ready\n'
    fi
elif [ "${1:-}" = "wpcom-local" ] && [ "${2:-}" = "tracks" ] && [ "${3:-}" = "enable" ]; then
    printf 'Success: Local Tracks capture enabled.\n'
else
    printf '{}\n'
fi
""",
        )
        write_executable(
            fake_bin / "pnpm",
            """#!/usr/bin/env bash
set -eu
printf 'pnpm|%s\n' "$*" >> "$INVOCATIONS_LOG"
if [[ "$*" == *'pgrep -f "vendor/bin/phpuni[t]"'* ]]; then
    if [ "${PHPUNIT_ALREADY_RUNNING:-0}" = "1" ]; then
        exit 0
    fi
    exit 1
fi
if [ "$*" = "--filter=@woocommerce/plugin-woocommerce test:php:env -- --filter WooPayments" ]; then
    printf 'simulating stuck WooPayments PHP suite without newline'
    sleep 30
fi
""",
        )
        write_executable(
            fake_bin / "wpcom-local",
            """#!/usr/bin/env bash
set -eu
if [ "${1:-}" = "tracks" ] && [ "${2:-}" = "path" ]; then
    printf 'Command: tracks path\n'
    printf 'Status: success\n'
    printf 'Context:\n'
    printf '%s\n' '- endpoint_url: http://wpcom.localhost:30001/__wpcom-local/tracks/events'
    exit 0
fi
exit 1
""",
        )
        write_executable(
            critical_dir / "test-inventory.py",
            """#!/usr/bin/env python3
import os
from pathlib import Path
Path(os.environ["INVOCATIONS_LOG"]).open("a", encoding="utf-8").write("critical-inventory|\\n")
""",
        )
        write_executable(
            critical_setup_dir / "fixtures.sh",
            """#!/usr/bin/env bash
set -eu
printf 'critical-fixtures|%s\n' "$*" >> "$INVOCATIONS_LOG"
""",
        )
        write_executable(
            critical_dir / "run.sh",
            """#!/usr/bin/env bash
set -eu
printf 'critical-run|%s\n' "$*" >> "$INVOCATIONS_LOG"
""",
        )
        write_executable(
            critical_dir / "build-agent-results.py",
            """#!/usr/bin/env python3
import os
import sys
from pathlib import Path
Path(os.environ["INVOCATIONS_LOG"]).open("a", encoding="utf-8").write(
    Path(sys.argv[0]).name + "|" + " ".join(sys.argv[1:]) + "\\n"
)
if "--out-dir" in sys.argv:
    Path(sys.argv[sys.argv.index("--out-dir") + 1]).mkdir(parents=True, exist_ok=True)
""",
        )

        ref_wp = fake_bin / "reference" / "wp"
        target_wp = fake_bin / "target" / "wp"
        write_runtime_identity_wp_wrapper(
            ref_wp, delegate=fake_wp, owner="plugin", site_url="http://localhost:8082"
        )
        write_runtime_identity_wp_wrapper(
            target_wp,
            delegate=fake_wp,
            owner="native",
            site_url="http://store8889.localhost:8889",
        )
        runner_args = write_approved_docker_fixture(
            fake_bin,
            ref_wp=ref_wp,
            target_wp=target_wp,
        )

        verify_args = [
            "bash",
            str(verify_copy),
            *runner_args,
            "--full-evidence-out-dir",
            str(full_evidence_dir),
            "--full-evidence",
            "--browser-runner",
            "playwright",
            "--critical-flow-agent-result",
            str(Path(tmp) / "SC-04-saved-card.json"),
            "--ms07-reference-browser",
            str(Path(tmp) / "ms07-reference-browser.json"),
            "--ms07-reference-state",
            str(Path(tmp) / "ms07-reference-state.json"),
            "--ms07-target-browser",
            str(Path(tmp) / "ms07-target-browser.json"),
            "--ms07-target-state",
            str(Path(tmp) / "ms07-target-state.json"),
            "--ref-subscription-id",
            "101",
            "--target-subscription-id",
            "202",
            "--token-customer-id",
            "cus_test",
            "--token-renewal-product-id",
            "116",
            "--perf-ref-process-order-id",
            "11",
            "--perf-target-process-order-id",
            "22",
            "--perf-ref-refund-order-id",
            "33",
            "--perf-target-refund-order-id",
            "44",
            "--perf-ref-capture-order-id",
            "55",
            "--perf-target-capture-order-id",
            "66",
        ]
        verify_env = {
            "FULL_EVIDENCE_QUALITY_GATE_TIMEOUT_SECONDS": "1",
            "INVOCATIONS_LOG": str(invocations),
            "TMPDIR": str(fake_tmp),
            "WCPAY_REPO": str(wcpay_repo),
            "WPCOM_LOCAL_HOME": str(fake_wpcom_home),
            "PATH": str(fake_bin) + ":" + "/bin:/usr/bin:/usr/local/bin",
        }

        try:
            result = subprocess.run(
                verify_args,
                cwd=repo,
                text=True,
                stdout=subprocess.PIPE,
                stderr=subprocess.PIPE,
                env=verify_env,
                check=False,
                # Budget for the whole fixture run: gates run wrapped in
                # run-command-with-timeout by default now, which adds one
                # python3 startup per gate (including the nested verifiers).
                timeout=45,
            )
        except subprocess.TimeoutExpired as exc:
            raise AssertionError("verify.sh did not enforce the quality-gate timeout") from exc

        assert result.returncode == 3, result.stdout + result.stderr
        assert "[BLOCKED] WooPayments PHP suite" in result.stdout
        assert "TIMEOUT: command exceeded 1s" in result.stdout
        assert "RESULT: INCOMPLETE" in result.stdout
        invocation_log = invocations.read_text(encoding="utf-8")
        assert 'pgrep -f "vendor/bin/phpuni[t]"' in invocation_log
        assert "DELETE FROM wp_actionscheduler_actions" not in invocation_log
        assert "pnpm|--filter=@woocommerce/plugin-woocommerce test:php:env -- --filter WooPayments" in invocation_log
        assert "pkill -f \"vendor/bin/phpuni[t]\"" in invocation_log
        assert invocation_log.index('pgrep -f "vendor/bin/phpuni[t]"') < invocation_log.index("pnpm|--filter=@woocommerce/plugin-woocommerce test:php:env -- --filter WooPayments")
        assert invocation_log.index("pnpm|--filter=@woocommerce/plugin-woocommerce test:php:env -- --filter WooPayments") < invocation_log.rindex("pkill -f \"vendor/bin/phpuni[t]\"")
        assert "pnpm|--filter=@woocommerce/admin-library test:js" in invocation_log

        invocations.write_text("", encoding="utf-8")
        active_args = verify_args.copy()
        active_args[active_args.index("--full-evidence-out-dir") + 1] = str(Path(tmp) / "active-phpunit-final-evidence")
        active_result = subprocess.run(
            active_args,
            cwd=repo,
            text=True,
            stdout=subprocess.PIPE,
            stderr=subprocess.PIPE,
            env={**verify_env, "PHPUNIT_ALREADY_RUNNING": "1"},
            check=False,
            # Same per-gate wrapper overhead applies; the run must still finish
            # promptly because the stuck PHPUnit suite is never started.
            timeout=30,
        )

        assert active_result.returncode == 3, active_result.stdout + active_result.stderr
        assert "another PHPUnit process is already running" in active_result.stdout
        active_invocations = invocations.read_text(encoding="utf-8")
        assert 'pgrep -f "vendor/bin/phpuni[t]"' in active_invocations
        assert "DELETE FROM wp_actionscheduler_actions" not in active_invocations
        assert "pnpm|--filter=@woocommerce/plugin-woocommerce test:php:env -- --filter WooPayments" not in active_invocations


def test_full_evidence_flag_is_documented_in_usage() -> None:
    result = run_verify()

    assert result.returncode == 2
    assert "--full-evidence" in result.stderr
    assert "accumulated final evidence plan" in result.stderr
    assert "--print-full-evidence-plan" in result.stderr
    assert "--checkout-playwriter-session" in result.stderr
    assert "--critical-flows-agent-results-dir" in result.stderr
    assert "--critical-flow-agent-result" in result.stderr
    assert "--ms07-reference-browser" in result.stderr


def test_timeout_runner_streams_an_explicit_stdin_file(tmp_path: Path) -> None:
    stdin_file = tmp_path / "input.txt"
    stdin_file.write_text("bounded cleanup input\n", encoding="utf-8")

    result = subprocess.run(
        [
            "python3",
            str(TIMEOUT_RUNNER),
            "--timeout",
            "2",
            "--stdin-file",
            str(stdin_file),
            "--",
            "sh",
            "-c",
            "cat",
        ],
        cwd=REPO,
        text=True,
        stdout=subprocess.PIPE,
        stderr=subprocess.PIPE,
        check=False,
    )

    assert result.returncode == 0, result.stdout + result.stderr
    assert result.stdout == "bounded cleanup input\n"


def test_timeout_runner_terminates_the_started_process_group() -> None:
    result = subprocess.run(
        [
            "python3",
            str(TIMEOUT_RUNNER),
            "--timeout",
            "0.1",
            "--",
            "sh",
            "-c",
            "sleep 5",
        ],
        cwd=REPO,
        text=True,
        stdout=subprocess.PIPE,
        stderr=subprocess.PIPE,
        check=False,
        timeout=3,
    )

    assert result.returncode == 124, result.stdout + result.stderr
    assert "TIMEOUT: command exceeded 0.1s; terminating process group." in result.stdout


def test_timeout_runner_does_not_block_on_a_descendant_holding_stdout() -> None:
    result = subprocess.run(
        [
            "python3",
            str(TIMEOUT_RUNNER),
            "--timeout",
            "0.1",
            "--",
            "python3",
            "-c",
            "import subprocess; subprocess.Popen(['sleep', '5'])",
        ],
        cwd=REPO,
        text=True,
        stdout=subprocess.PIPE,
        stderr=subprocess.PIPE,
        check=False,
        timeout=2,
    )

    assert result.returncode == 124, result.stdout + result.stderr
    assert "TIMEOUT: command exceeded 0.1s; terminating process group." in result.stdout


def test_a5g_disposable_cleanup_answers_wp_env_destroy_prompt(monkeypatch, tmp_path: Path) -> None:
    module = load_a5g_gate_module()
    work_dir = tmp_path / "a5g-wp-env"
    calls = []
    args = SimpleNamespace(
        repo=str(tmp_path / "repo"),
        wcpay_repo=str(tmp_path / "woocommerce-payments"),
        existing_wp_env_dir=None,
        out_dir=str(tmp_path / "out"),
        runtime_mode="disposable",
        keep_env=False,
        port=8899,
        start_attempts=1,
        existing_tests_url="http://store8889.localhost:8087",
    )

    work_dir.mkdir()
    gate = module.MultisiteRuntimeGate(args)
    gate.work_dir = work_dir
    gate.wp_env_started = True

    def fake_run_wp_env(phase_id: str, command: list[str], **kwargs):
        calls.append((phase_id, command, kwargs))
        return {"status": "pass"}

    monkeypatch.setattr(gate, "run_wp_env", fake_run_wp_env)

    gate.cleanup()

    assert calls
    assert calls[0][0] == "destroy-disposable-wp-env"
    assert calls[0][1] == ["destroy"]
    assert calls[0][2]["input_text"] == "y\n"
    assert not work_dir.exists()
    assert gate.work_dir is None


def test_a5g_main_installs_signal_handlers_for_disposable_cleanup(monkeypatch, tmp_path: Path) -> None:
    module = load_a5g_gate_module()
    registered = []

    class FakeGate:
        def __init__(self, args):
            self.args = args

        def run(self):
            return None

    monkeypatch.setattr(module, "MultisiteRuntimeGate", FakeGate)
    monkeypatch.setattr(module.signal, "getsignal", lambda signum: f"old-{signum}")
    monkeypatch.setattr(
        module.signal,
        "signal",
        lambda signum, handler: registered.append((signum, handler)),
    )

    result = module.main(
        [
            "--repo",
            str(tmp_path / "repo"),
            "--wcpay-repo",
            str(tmp_path / "woocommerce-payments"),
            "--out-dir",
            str(tmp_path / "out"),
        ]
    )

    assert result == 0
    assert [item[0] for item in registered[:3]] == [
        module.signal.SIGHUP,
        module.signal.SIGINT,
        module.signal.SIGTERM,
    ]
    assert all(callable(item[1]) for item in registered[:3])
    assert [item[1] for item in registered[3:]] == [
        f"old-{module.signal.SIGHUP}",
        f"old-{module.signal.SIGINT}",
        f"old-{module.signal.SIGTERM}",
    ]


def test_a5g_disposable_chooses_free_port_pair(monkeypatch, tmp_path: Path) -> None:
    module = load_a5g_gate_module()
    args = SimpleNamespace(
        repo=str(tmp_path / "repo"),
        wcpay_repo=str(tmp_path / "woocommerce-payments"),
        existing_wp_env_dir=None,
        out_dir=str(tmp_path / "out"),
        runtime_mode="disposable",
        keep_env=False,
        port=8891,
        start_attempts=1,
        existing_tests_url="http://store8889.localhost:8087",
    )

    monkeypatch.setattr(module, "is_tcp_port_available", lambda port: port not in {8891, 8892})

    gate = module.MultisiteRuntimeGate(args)

    assert gate.args.port == 8893
    assert gate.main_url == "http://localhost:8893"
    assert gate.command_env()["WP_ENV_PORT"] == "8893"
    assert gate.command_env()["WP_ENV_TESTS_PORT"] == "8894"


def test_a5g_disposable_work_dir_name_is_docker_reference_safe(monkeypatch, tmp_path: Path) -> None:
    module = load_a5g_gate_module()
    repo = tmp_path / "repo"
    wcpay_repo = tmp_path / "woocommerce-payments"
    (repo / "plugins/woocommerce").mkdir(parents=True)
    (repo / "plugins/woocommerce/node_modules/.bin").mkdir(parents=True)
    (repo / "plugins/woocommerce/woocommerce.php").write_text("<?php\n", encoding="utf-8")
    (repo / "plugins/woocommerce/node_modules/.bin/wp-env").write_text("#!/usr/bin/env sh\n", encoding="utf-8")
    wcpay_repo.mkdir()
    (wcpay_repo / "woocommerce-payments.php").write_text("<?php\n", encoding="utf-8")
    args = SimpleNamespace(
        repo=str(repo),
        wcpay_repo=str(wcpay_repo),
        existing_wp_env_dir=None,
        out_dir=str(tmp_path / "out"),
        runtime_mode="disposable",
        keep_env=False,
        port=8891,
        start_attempts=1,
        existing_tests_url="http://store8889.localhost:8087",
    )

    monkeypatch.setenv("TMPDIR", str(tmp_path))
    unsafe_path = tmp_path / "a5g-wp-env-ab_cd"

    def unsafe_mkdtemp(*args, **kwargs):
        unsafe_path.mkdir()
        return str(unsafe_path)

    monkeypatch.setattr(module.tempfile, "mkdtemp", unsafe_mkdtemp)

    gate = module.MultisiteRuntimeGate(args)
    gate.write_wp_env_config()

    assert gate.work_dir is not None
    assert re.fullmatch(r"a5g-wp-env-[a-f0-9]{12}", gate.work_dir.name)
    assert "_" not in gate.work_dir.name


def main() -> None:
    tests = [
        test_full_evidence_plan_lists_final_gates,
        test_full_evidence_plan_uses_provider_setup_intent_for_token_fixture,
        test_full_evidence_plan_passes_perf_fixture_ids_to_perf_captures,
        test_full_evidence_executes_nested_self_check_and_tracks_verifier,
        test_full_evidence_blocks_browser_gates_without_playwriter_session,
        test_full_evidence_flag_is_documented_in_usage,
        test_full_perf_compare_requires_two_successful_nonempty_captures,
    ]
    for test in tests:
        test()
        print(f"PASS {test.__name__}")


if __name__ == "__main__":
    main()
