#!/usr/bin/env python3
"""Focused regression checks for the multi-currency rates gate harness."""

from __future__ import annotations

import base64
import hashlib
import json
import os
import shutil
import signal
import subprocess
import sys
import tempfile
import time
from pathlib import Path

from tools.woopayments_test_runner import adapt_wp_runner_arguments


REPO = Path(__file__).resolve().parents[2]
SCRIPT = REPO / "tools/woopayments-merge/mc-rates-gate.sh"
REF_WP = "docker exec -i wcpay_wp_default wp --allow-root"
TARGET_WP = "docker exec -i target-cli-1 wp --allow-root --user=1"
REF_URL = "http://reference.localhost"
TARGET_URL = "http://target.localhost"


def run_gate(
    *args: str,
    env: dict[str, str] | None = None,
    adapt_runners: bool = True,
) -> subprocess.CompletedProcess[str]:
    process_env = os.environ.copy()
    if env:
        process_env.update(env)

    command_args = list(args)
    if "--ref" in command_args and "--target" in command_args:
        if "--ref-url" not in command_args and not any(
            item.startswith("--ref-url=") for item in command_args
        ):
            command_args.extend(("--ref-url", REF_URL))
        if "--target-url" not in command_args and not any(
            item.startswith("--target-url=") for item in command_args
        ):
            command_args.extend(("--target-url", TARGET_URL))

    if adapt_runners:
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


def test_usage_requires_ref_and_target() -> None:
    result = run_gate()

    assert result.returncode == 2
    assert "usage:" in result.stderr
    assert "--ref" in result.stderr
    assert "--target" in result.stderr


def test_print_plan_describes_rate_probe() -> None:
    result = run_gate(
        "--ref",
        REF_WP,
        "--target",
        TARGET_WP,
        "--currency-from",
        "USD",
        "--currencies-to",
        "GBP,EUR",
        "--print-plan",
    )

    assert result.returncode == 0, result.stderr
    payload = json.loads(result.stdout)

    assert payload["schema"] == "woopayments_mc_rates_gate_plan.v1"
    assert payload["currency_from"] == "USD"
    assert payload["currencies_to"] == ["GBP", "EUR"]
    assert payload["ref_wp"] == REF_WP
    assert payload["target_wp"] == TARGET_WP
    assert payload["ref_url"] == REF_URL
    assert payload["target_url"] == TARGET_URL
    assert "local-only WP runner validation before invocation" in payload["checks"]
    assert "read-only site identity verification before target mutation" in payload["checks"]
    assert "reference API client oracle remains read-only" in payload["checks"]
    assert (
        "target transactional option snapshot and exact restoration"
        in payload["checks"]
    )
    assert "cache regeneration after deletion" in payload["checks"]
    assert "Playwright storefront product price derived from target rate" in payload["checks"]


def write_executable(path: Path, source: str) -> None:
    path.parent.mkdir(parents=True, exist_ok=True)
    path.write_text(source, encoding="utf-8")
    path.chmod(0o755)


def make_invocation_spy(path: Path, log_path: Path) -> None:
    write_executable(
        path,
        f"""#!/usr/bin/env python3
from pathlib import Path

with Path({str(log_path)!r}).open("a", encoding="utf-8") as handle:
    handle.write("invoked\\n")
raise SystemExit(1)
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


def test_rejects_unsafe_wp_runners_before_either_command_is_invoked() -> None:
    with tempfile.TemporaryDirectory(prefix="mc-rates-gate-test-") as tmp:
        tmp_path = Path(tmp)
        ref_spy = tmp_path / "reference" / "wp"
        target_spy = tmp_path / "target" / "wp"
        invocation_log = tmp_path / "invocations.log"
        make_invocation_spy(ref_spy, invocation_log)
        make_invocation_spy(target_spy, invocation_log)

        unsafe_suffixes = (
            "--ssh=merchant@example.com",
            "--http=https://example.test",
            "--url=example.test",
            "https://store.wpcom.test",
            "https://store.wordpress.test",
            "https://store.example.com",
            "; echo unsafe",
            "| echo unsafe",
            "$(echo unsafe)",
            "\necho unsafe",
        )

        for unsafe_suffix in unsafe_suffixes:
            for unsafe_side in ("ref", "target"):
                invocation_log.unlink(missing_ok=True)
                ref_runner = (
                    f"{ref_spy} {unsafe_suffix}"
                    if unsafe_side == "ref"
                    else str(ref_spy)
                )
                target_runner = (
                    f"{target_spy} {unsafe_suffix}"
                    if unsafe_side == "target"
                    else str(target_spy)
                )

                result = run_gate(
                    "--ref",
                    ref_runner,
                    "--target",
                    target_runner,
                    "--currency-from",
                    "USD",
                    "--currencies-to",
                    "GBP",
                )

                assert result.returncode == 2
                assert f"unsafe --{unsafe_side} WP runner" in result.stderr
                assert not invocation_log.exists()


def test_print_plan_rejects_unsafe_runner() -> None:
    result = run_gate(
        "--ref",
        REF_WP + " --http=https://store.wordpress.com",
        "--target",
        TARGET_WP,
        "--print-plan",
    )

    assert result.returncode == 2
    assert "unsafe --ref WP runner" in result.stderr
    assert result.stdout == ""


def test_rejects_remote_docker_environment_before_invocation() -> None:
    with tempfile.TemporaryDirectory(prefix="mc-rates-gate-test-") as tmp:
        tmp_path = Path(tmp)
        ref_spy = tmp_path / "reference" / "docker"
        target_spy = tmp_path / "target" / "docker"
        invocation_log = tmp_path / "invocations.log"
        make_invocation_spy(ref_spy, invocation_log)
        make_invocation_spy(target_spy, invocation_log)

        for variable, value in (
            ("DOCKER_HOST", "ssh://remote.example.test"),
            ("DOCKER_CONTEXT", "remote-production"),
        ):
            invocation_log.unlink(missing_ok=True)
            result = run_gate(
                "--ref",
                f"{ref_spy} exec -i reference wp",
                "--target",
                f"{target_spy} exec -i target wp",
                env={variable: value},
            )

            assert result.returncode == 2
            assert "unsafe --ref WP runner" in result.stderr
            assert not invocation_log.exists()


def test_rejects_unapproved_wrapper_before_invocation() -> None:
    with tempfile.TemporaryDirectory(prefix="mc-rates-gate-test-") as tmp:
        tmp_path = Path(tmp)
        wrapper = tmp_path / "merchant-wp"
        invocation_log = tmp_path / "invocations.log"
        make_invocation_spy(wrapper, invocation_log)

        result = run_gate(
            "--ref",
            str(wrapper),
            "--target",
            TARGET_WP,
            adapt_runners=False,
        )

        assert result.returncode == 2
        assert "unsafe --ref WP runner" in result.stderr
        assert not invocation_log.exists()


def make_fake_wp(
    path: Path,
    *,
    role: str,
    site_url: str | None = None,
    identity_fingerprint: str | None = None,
    currencies: tuple[str, ...] = ("GBP",),
    provider: str = "woopayments",
    provider_registered: bool | None = None,
    provider_available: bool | None = None,
    rate: str = "0.80",
    native_owner: str = "native",
    cache_errored: bool = False,
    refresh_started_at: int = 2_000_000_000,
    fetched_at: int = 2_000_000_001,
    updated_at: int = 2_000_000_001,
    observed_at: int = 2_000_000_002,
    cache_absent_after_delete: bool = True,
    storefront_product_prepared: bool = True,
    storefront_prepare_command_fails_after_create: bool = False,
    call_log: Path | None = None,
    fail_on_marker: str = "",
    pause_on_marker: str = "",
    pause_ready_file: Path | None = None,
    restore_succeeds: bool = True,
    cache_restore_verified: bool = True,
    product_cleanup_succeeds: bool = True,
    reference_transact_error: str = "transact_authentication_failure",
    reference_route_error: str = "",
    large_snapshot_bytes: int = 0,
) -> None:
    if provider_registered is None:
        provider_registered = bool(provider)
    if provider_available is None:
        provider_available = bool(provider)
    if site_url is None:
        site_url = REF_URL if role == "reference" else TARGET_URL
    if identity_fingerprint is None:
        identity_fingerprint = hashlib.sha256(site_url.encode("utf-8")).hexdigest()

    write_executable(
        path,
        f"""#!/usr/bin/env python3
import base64
import hashlib
import json
import re
import sys
import time
from pathlib import Path

args = " ".join(sys.argv[1:])
role = {json.dumps(role)}
site_url = {json.dumps(site_url)}
identity_fingerprint = {json.dumps(identity_fingerprint)}
currencies = {json.dumps(list(currencies))}
provider = {json.dumps(provider)}
provider_registered = {provider_registered!r}
provider_available = {provider_available!r}
rate = {json.dumps(rate)}
native_owner = {json.dumps(native_owner)}
cache_errored = {cache_errored!r}
refresh_started_at = {refresh_started_at}
fetched_at = {fetched_at}
updated_at = {updated_at}
observed_at = {observed_at}
cache_absent_after_delete = {cache_absent_after_delete!r}
storefront_product_prepared = {storefront_product_prepared!r}
storefront_prepare_command_fails_after_create = {storefront_prepare_command_fails_after_create!r}
call_log = {json.dumps(str(call_log) if call_log else "")}
fail_on_marker = {json.dumps(fail_on_marker)}
pause_on_marker = {json.dumps(pause_on_marker)}
pause_ready_file = {json.dumps(str(pause_ready_file) if pause_ready_file else "")}
restore_succeeds = {restore_succeeds!r}
cache_restore_verified = {cache_restore_verified!r}
product_cleanup_succeeds = {product_cleanup_succeeds!r}
reference_transact_error = {json.dumps(reference_transact_error)}
reference_route_error = {json.dumps(reference_route_error)}
large_snapshot_bytes = {large_snapshot_bytes}

option_names = [
    "woocommerce_currency",
    "_wcpay_feature_customer_multi_currency",
    "wcpay_multi_currency_setup_completed",
    "wcpay_multi_currency_enabled_currencies",
    "wcpay_multi_currency_cached_currencies",
    "_transient_wcpay_currency_format",
    "_transient_timeout_wcpay_currency_format",
    "_transient_wcpay_locale_info",
    "_transient_timeout_wcpay_locale_info",
]
for currency in currencies:
    currency_lc = currency.lower()
    option_names.extend([
        "wcpay_multi_currency_exchange_rate_" + currency_lc,
        "wcpay_multi_currency_manual_rate_" + currency_lc,
        "wcpay_multi_currency_price_rounding_" + currency_lc,
        "wcpay_multi_currency_price_charm_" + currency_lc,
    ])
snapshot_options = {{}}
for option_name in option_names:
    exists = "manual_rate" not in option_name
    snapshot_options[option_name] = {{"exists": exists}}
    if exists:
        serialized_value = "serialized:" + option_name
        if option_name == "wcpay_multi_currency_cached_currencies" and large_snapshot_bytes:
            serialized_value += ":" + ("x" * large_snapshot_bytes)
        snapshot_options[option_name].update({{
            "option_value_b64": base64.b64encode(
                serialized_value.encode("utf-8")
            ).decode("ascii"),
            "autoload": "auto",
        }})
snapshot_payload = json.dumps({{
    "schema": "woopayments_mc_option_snapshot_payload.v1",
    "option_names": option_names,
    "options": snapshot_options,
}}, sort_keys=True, separators=(",", ":"))
snapshot_b64 = base64.b64encode(snapshot_payload.encode("utf-8")).decode("ascii")
snapshot_sha256 = hashlib.sha256(snapshot_payload.encode("utf-8")).hexdigest()

if call_log:
    with Path(call_log).open("a", encoding="utf-8") as handle:
        handle.write(json.dumps({{"role": role, "args": args}}) + "\\n")

if fail_on_marker and fail_on_marker in args:
    print("synthetic intermediate failure", file=sys.stderr)
    raise SystemExit(1)

if pause_on_marker and pause_on_marker in args:
    if pause_ready_file:
        Path(pause_ready_file).write_text("ready\\n", encoding="utf-8")
    time.sleep(60)

if args == "wc-native-payments status":
    print("Owner: " + native_owner)
    print("Native enabled: " + ("yes" if native_owner == "native" else "no"))
    raise SystemExit(0)

if "mc_rates_site_identity" in args:
    print(json.dumps({{
        "schema": "woopayments_mc_site_identity.v1",
        "role": role,
        "home_url": site_url,
        "site_url": site_url,
        "fingerprint": identity_fingerprint,
    }}))
    raise SystemExit(0)

if "mc_rates_configure" in args:
    print(json.dumps({{
        "role": role,
        "configured": True,
        "currency_from": "USD",
        "currencies_to": currencies,
        "refresh_started_at": refresh_started_at,
        "cache_was_present": True,
        "cache_deleted": cache_absent_after_delete,
        "cache_absent_after_delete": cache_absent_after_delete,
    }}))
    raise SystemExit(0)

if "mc_rates_snapshot_options" in args:
    print(json.dumps({{
        "schema": "woopayments_mc_option_snapshot.v1",
        "role": role,
        "snapshot_b64": snapshot_b64,
        "snapshot_sha256": snapshot_sha256,
        "option_names": option_names,
        "option_count": len(option_names),
    }}))
    raise SystemExit(0)

if "mc_rates_restore_options" in args:
    print(json.dumps({{
        "schema": "woopayments_mc_option_restore.v1",
        "role": role,
        "restored": restore_succeeds,
        "verified": restore_succeeds,
        "snapshot_sha256": snapshot_sha256,
        "option_count": 9 + 4 * len(currencies),
        "restored_count": (9 + 4 * len(currencies)) if restore_succeeds else 0,
        "cache_verified_count": (9 + 4 * len(currencies)) if restore_succeeds and cache_restore_verified else 0,
        "mismatches": [] if restore_succeeds else ["wcpay_multi_currency_cached_currencies"],
    }}))
    raise SystemExit(0)

if "mc_rates_build_state" in args:
    print(json.dumps({{
        "role": role,
        "provider": provider,
        "provider_registered": provider_registered,
        "provider_available": provider_available,
        "state_built": True,
    }}))
    raise SystemExit(0)

if "mc_rates_inspect_rates" in args:
    if cache_errored:
        print(json.dumps({{
            "role": role,
            "provider": provider,
            "provider_registered": provider_registered,
            "provider_available": provider_available,
            "cache_option": "wcpay_multi_currency_cached_currencies",
            "updated": None,
            "fetched": fetched_at,
            "observed_at": observed_at,
            "rates": {{}},
            "missing": currencies,
            "cache_errored": True,
            "consecutive_errors": 1,
        }}))
        raise SystemExit(0)

    print(json.dumps({{
        "role": role,
        "provider": provider,
        "provider_registered": provider_registered,
        "provider_available": provider_available,
        "cache_option": "wcpay_multi_currency_cached_currencies",
        "updated": updated_at,
        "fetched": fetched_at,
        "observed_at": observed_at,
        "rates": {{currency: rate for currency in currencies}},
        "missing": [],
        "cache_errored": False,
        "consecutive_errors": 0,
    }}))
    raise SystemExit(0)

if "mc_rates_prepare_storefront_product" in args:
    evidence_sku_match = re.search(r"\\$evidence_sku = '([^']+)'", args)
    evidence_sku = evidence_sku_match.group(1) if evidence_sku_match else ""
    if storefront_prepare_command_fails_after_create:
        print("synthetic transport failure after product creation", file=sys.stderr)
        raise SystemExit(1)
    if not storefront_product_prepared:
        print(json.dumps({{
            "prepared": False,
            "error_code": "storefront_product_prepare_failed",
        }}))
        raise SystemExit(0)
    print(json.dumps({{
        "prepared": True,
        "product_id": 4242,
        "sku": evidence_sku,
        "product_url": site_url + "/product/" + evidence_sku + "/",
        "base_price": "100.00",
        "currency": currencies[0],
    }}))
    raise SystemExit(0)

if "mc_rates_cleanup_storefront_product" in args:
    identity_safe = (
        "wc_get_product_id_by_sku" in args
        and "cleanup_product_identity_mismatch" in args
        and "get_sku()" in args
    )
    print(json.dumps({{
        "cleaned": product_cleanup_succeeds and identity_safe,
        "product_id": 4242,
    }}))
    raise SystemExit(0)

if "mc_rates_probe_reference_client" in args:
    print(json.dumps({{
        "server_connected": True,
        "transact_method": {{
            "ok": reference_transact_error == "",
            "error_code": reference_transact_error,
            "error_class": "WCPay\\\\Exceptions\\\\API_Exception" if reference_transact_error else "",
            "rates": {{}} if reference_transact_error else {{currency: rate for currency in currencies}},
        }},
        "wcpay_route_control": {{
            "ok": reference_route_error == "",
            "error_code": reference_route_error,
            "rates": {{}} if reference_route_error else {{currency: rate for currency in currencies}},
        }},
    }}))
    raise SystemExit(0)

print("unexpected fake wp args: " + args, file=sys.stderr)
raise SystemExit(1)
""",
    )


def make_fake_playwright(path: Path) -> None:
    write_executable(
        path,
        """#!/usr/bin/env python3
import json
import os
import sys

state = json.loads(os.environ["PLAYWRIGHT_RUNNER_STATE_JSON"])
if os.environ.get("FAKE_STOREFRONT_BROWSER_ERROR"):
    print("browser could not reach local storefront", file=sys.stderr)
    raise SystemExit(1)

price = os.environ.get("FAKE_STOREFRONT_PRICE", "80.00")
print(json.dumps({
    "schema": "woopayments_mc_storefront_browser_observation.v1",
    "probe_status": "observed",
    "http_status": 200,
    "product_id": state["product_id"],
    "sku": state["sku"],
    "requested_currency": state["currency"],
    "product_identity_matched": True,
    "price_element_visible": True,
    "displayed_price_text": "GBP " + price,
    "displayed_amount_text": price,
    "displayed_currency": "GBP",
    "page_url": state["product_url"] + "?currency=" + state["currency"],
}))
""",
    )


def browser_env(browser: Path, **overrides: str) -> dict[str, str]:
    return {
        "PLAYWRIGHT_SCRIPT_RUNNER_BIN": str(browser),
        **overrides,
    }


def run_mc_docker_alias_gate(
    tmp_path: Path, published_host_port: str
) -> tuple[subprocess.CompletedProcess[str], Path, Path]:
    ref_wp = tmp_path / "reference" / "wp"
    target_wp = tmp_path / "target" / "wp"
    fake_docker = tmp_path / "docker"
    browser = tmp_path / "playwright-runner"
    call_log = tmp_path / "wp-calls.jsonl"
    out_dir = tmp_path / "evidence"

    make_fake_wp(
        ref_wp,
        role="reference",
        site_url="http://localhost",
        call_log=call_log,
    )
    make_fake_wp(target_wp, role="target", call_log=call_log)
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
    make_fake_playwright(browser)

    result = run_gate(
        "--ref",
        f"{fake_docker} exec -i reference wp",
        "--target",
        f"{fake_docker} exec -i target wp",
        "--ref-url",
        "http://localhost:8082",
        "--target-url",
        TARGET_URL,
        "--out-dir",
        str(out_dir),
        env=browser_env(browser, DOCKER_CONTEXT="", DOCKER_HOST=""),
    )
    return result, out_dir, call_log


def make_python_stdin_interceptor(
    path: Path, *, marker: str, replacement_status: str | None = None
) -> None:
    replacement = replacement_status or ""
    write_executable(
        path,
        f"""#!/bin/bash
set -u
real_python={json.dumps(sys.executable)}
if [ "${{1:-}}" != "-" ]; then
    exec "$real_python" "$@"
fi
shift
script_file="$(mktemp "${{TMPDIR:-/tmp}}/mc-rates-python.XXXXXX")"
trap 'rm -f "$script_file"' EXIT
cat > "$script_file"
if grep -Fq {json.dumps(marker)} "$script_file"; then
    if [ -n {json.dumps(replacement)} ]; then
        "$real_python" "$script_file" "$@" >/dev/null || exit $?
        printf '%s\n' {json.dumps(replacement)}
        exit 0
    fi
    exit 47
fi
exec "$real_python" "$script_file" "$@"
""",
    )


def intercepted_python_env(interceptor: Path, browser: Path) -> dict[str, str]:
    return browser_env(
        browser,
        PATH=f"{interceptor.parent}{os.pathsep}{os.environ['PATH']}",
    )


def extract_inline_php(variable_name: str) -> str:
    source = SCRIPT.read_text(encoding="utf-8")
    start = f'{variable_name}="$(cat <<PHP\n'
    raw = source.split(start, 1)[1].split("\nPHP\n", 1)[0]
    return raw


def run_php_source(
    path: Path, source: str, input_text: str | None = None
) -> subprocess.CompletedProcess[str]:
    php = shutil.which("php")
    assert php is not None
    path.write_text(source, encoding="utf-8")
    return subprocess.run(
        [php, str(path)],
        input=input_text,
        text=True,
        stdout=subprocess.PIPE,
        stderr=subprocess.PIPE,
        check=False,
    )


def test_snapshot_php_fails_closed_on_database_read_error() -> None:
    with tempfile.TemporaryDirectory(prefix="mc-rates-php-test-") as tmp:
        tmp_path = Path(tmp)
        snippet = extract_inline_php("snapshot_php")
        snippet = snippet.replace("$CURRENCIES_TO_PHP", "array( 'GBP' )")
        snippet = snippet.replace("\\$", "$")
        source = f"""<?php
define( 'ARRAY_A', 'ARRAY_A' );
class WP_CLI {{ public static function line( $value ) {{ echo $value, "\\n"; }} }}
function wp_json_encode( $value, $flags = 0 ) {{ return json_encode( $value, $flags ); }}
class FakeWpdb {{
    public $options = 'wp_options';
    public $last_error = '';
    public function prepare( $query, ...$args ) {{ return array( $query, $args ); }}
    public function get_row( $query, $format ) {{
        $this->last_error = 'synthetic_database_failure';
        return null;
    }}
}}
$wpdb = new FakeWpdb();
$snapshot_role = 'target';
{snippet}
"""

        result = run_php_source(tmp_path / "snapshot.php", source)

        assert result.returncode != 0
        assert "snapshot_read_failed:woocommerce_currency" in (
            result.stdout + result.stderr
        )


def test_restore_php_detects_stale_persistent_option_cache() -> None:
    with tempfile.TemporaryDirectory(prefix="mc-rates-php-test-") as tmp:
        tmp_path = Path(tmp)
        payload = json.dumps(
            {
                "schema": "woopayments_mc_option_snapshot_payload.v1",
                "option_names": ["fixture_option"],
                "options": {
                    "fixture_option": {
                        "exists": True,
                        "option_value_b64": base64.b64encode(b"original").decode(
                            "ascii"
                        ),
                        "autoload": "no",
                    }
                },
            },
            separators=(",", ":"),
        )
        payload_b64 = base64.b64encode(payload.encode("utf-8")).decode("ascii")
        payload_sha256 = hashlib.sha256(payload.encode("utf-8")).hexdigest()
        snapshot_envelope = json.dumps(
            {
                "schema": "woopayments_mc_option_snapshot.v1",
                "role": "target",
                "snapshot_b64": payload_b64,
                "snapshot_sha256": payload_sha256,
            },
            separators=(",", ":"),
        )
        snippet = extract_inline_php("restore_php")
        snippet = snippet.replace("\\$role = '$role';", "\\$role = 'target';")
        snippet = snippet.replace("\\$", "$")
        source = f"""<?php
define( 'ARRAY_A', 'ARRAY_A' );
class WP_CLI {{ public static function line( $value ) {{ echo $value, "\\n"; }} }}
function wp_json_encode( $value, $flags = 0 ) {{ return json_encode( $value, $flags ); }}
$fake_cache = array( 'fixture_option' => 'mutated' );
function wp_cache_delete( $key, $group ) {{ return true; }}
function maybe_serialize( $value ) {{ return is_string( $value ) ? $value : serialize( $value ); }}
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
{snippet}
"""

        result = run_php_source(
            tmp_path / "restore.php", source, input_text=snapshot_envelope
        )

        assert result.returncode == 0, result.stderr
        restore = json.loads(result.stdout.splitlines()[-1])
        assert restore["restored"] is False
        assert restore["verified"] is False
        assert restore["cache_verified_count"] == 0
        assert restore["mismatches"] == ["fixture_option:cache_value_mismatch"]


def read_wp_calls(path: Path) -> list[dict[str, str]]:
    if not path.exists():
        return []
    return [json.loads(line) for line in path.read_text(encoding="utf-8").splitlines()]


def expected_mutated_options(*currencies: str) -> set[str]:
    option_names = {
        "woocommerce_currency",
        "_wcpay_feature_customer_multi_currency",
        "wcpay_multi_currency_setup_completed",
        "wcpay_multi_currency_enabled_currencies",
        "wcpay_multi_currency_cached_currencies",
        "_transient_wcpay_currency_format",
        "_transient_timeout_wcpay_currency_format",
        "_transient_wcpay_locale_info",
        "_transient_timeout_wcpay_locale_info",
    }
    for currency in currencies:
        currency_lc = currency.lower()
        option_names.update(
            {
                f"wcpay_multi_currency_exchange_rate_{currency_lc}",
                f"wcpay_multi_currency_manual_rate_{currency_lc}",
                f"wcpay_multi_currency_price_rounding_{currency_lc}",
                f"wcpay_multi_currency_price_charm_{currency_lc}",
            }
        )
    return option_names


def test_reference_oracle_remains_read_only_during_successful_gate() -> None:
    with tempfile.TemporaryDirectory(prefix="mc-rates-gate-test-") as tmp:
        tmp_path = Path(tmp)
        ref_wp = tmp_path / "reference" / "wp"
        target_wp = tmp_path / "target" / "wp"
        browser = tmp_path / "playwright-runner"
        call_log = tmp_path / "wp-calls.jsonl"
        out_dir = tmp_path / "evidence"

        make_fake_wp(ref_wp, role="reference", call_log=call_log)
        make_fake_wp(target_wp, role="target", call_log=call_log)
        make_fake_playwright(browser)

        result = run_gate(
            "--ref",
            str(ref_wp),
            "--target",
            str(target_wp),
            "--currency-from",
            "USD",
            "--currencies-to",
            "GBP",
            "--out-dir",
            str(out_dir),
            env=browser_env(browser),
        )

        assert result.returncode == 0, result.stderr
        reference_calls = [
            call["args"]
            for call in read_wp_calls(call_log)
            if call["role"] == "reference"
        ]
        assert len(reference_calls) == 2
        assert "mc_rates_site_identity" in reference_calls[0]
        assert "mc_rates_probe_reference_client" in reference_calls[1]
        assert not any(
            marker in call
            for call in reference_calls
            for marker in (
                "mc_rates_snapshot_options",
                "mc_rates_configure",
                "mc_rates_build_state",
                "mc_rates_inspect_rates",
                "mc_rates_restore_options",
            )
        )

        cleanup = json.loads(
            (out_dir / "mc-rates-cleanup-restore.json").read_text(encoding="utf-8")
        )
        assert cleanup["reference_snapshot"] == {"captured": False}
        assert cleanup["reference_option_restore"] == {
            "not_required": True,
            "restored": True,
            "verified": True,
        }


def test_unavailable_reference_oracle_blocks_before_target_mutation() -> None:
    with tempfile.TemporaryDirectory(prefix="mc-rates-gate-test-") as tmp:
        tmp_path = Path(tmp)
        ref_wp = tmp_path / "reference" / "wp"
        target_wp = tmp_path / "target" / "wp"
        call_log = tmp_path / "wp-calls.jsonl"
        out_dir = tmp_path / "evidence"

        make_fake_wp(
            ref_wp,
            role="reference",
            call_log=call_log,
            reference_transact_error="transact_authentication_failure",
            reference_route_error="wcpay_route_unavailable",
        )
        make_fake_wp(target_wp, role="target", call_log=call_log)

        result = run_gate(
            "--ref",
            str(ref_wp),
            "--target",
            str(target_wp),
            "--currency-from",
            "USD",
            "--currencies-to",
            "GBP",
            "--out-dir",
            str(out_dir),
        )

        assert result.returncode == 3
        assert "reference rate oracle unavailable" in result.stderr
        target_calls = [
            call["args"]
            for call in read_wp_calls(call_log)
            if call["role"] == "target"
        ]
        assert "mc_rates_site_identity" in target_calls[0]
        assert target_calls[1:] == ["wc-native-payments status"]


def test_gate_accepts_exact_docker_published_url_alias() -> None:
    with tempfile.TemporaryDirectory(prefix="mc-rates-gate-test-") as tmp:
        result, out_dir, _call_log = run_mc_docker_alias_gate(Path(tmp), "8082")

        assert result.returncode == 0, result.stderr
        identities = json.loads(
            (out_dir / "store-identities.json").read_text(encoding="utf-8")
        )
        assert identities["status"] == "pass"


def test_gate_rejects_unpublished_docker_url_alias() -> None:
    with tempfile.TemporaryDirectory(prefix="mc-rates-gate-test-") as tmp:
        result, out_dir, call_log = run_mc_docker_alias_gate(Path(tmp), "8081")

        assert result.returncode == 3
        identities = json.loads(
            (out_dir / "store-identities.json").read_text(encoding="utf-8")
        )
        assert identities["status"] == "blocked"
        assert identities["failure_details"][0]["code"] == (
            "store_identity_url_mismatch"
        )
        assert not any(
            "mc_rates_configure" in call["args"] for call in read_wp_calls(call_log)
        )


def test_identical_store_fingerprints_block_before_target_mutation() -> None:
    with tempfile.TemporaryDirectory(prefix="mc-rates-gate-test-") as tmp:
        tmp_path = Path(tmp)
        ref_wp = tmp_path / "reference" / "wp"
        target_wp = tmp_path / "target" / "wp"
        call_log = tmp_path / "wp-calls.jsonl"
        out_dir = tmp_path / "evidence"
        shared_fingerprint = "same-local-store"

        make_fake_wp(
            ref_wp,
            role="reference",
            identity_fingerprint=shared_fingerprint,
            call_log=call_log,
        )
        make_fake_wp(
            target_wp,
            role="target",
            identity_fingerprint=shared_fingerprint,
            call_log=call_log,
        )

        result = run_gate(
            "--ref",
            str(ref_wp),
            "--target",
            str(target_wp),
            "--out-dir",
            str(out_dir),
        )

        assert result.returncode == 3
        calls = read_wp_calls(call_log)
        assert not any(
            marker in call["args"]
            for call in calls
            for marker in (
                "mc_rates_snapshot_options",
                "mc_rates_configure",
                "mc_rates_build_state",
                "mc_rates_prepare_storefront_product",
            )
        )
        rollup = json.loads((out_dir / "mc-rates-gate.json").read_text(encoding="utf-8"))
        assert rollup["status"] == "blocked"
        assert any(
            item["code"] == "store_identity_collision"
            for item in rollup["failure_details"]
        )


def test_gate_restores_every_mutated_option_after_success() -> None:
    with tempfile.TemporaryDirectory(prefix="mc-rates-gate-test-") as tmp:
        tmp_path = Path(tmp)
        ref_wp = tmp_path / "reference" / "wp"
        target_wp = tmp_path / "target" / "wp"
        browser = tmp_path / "playwright-runner"
        call_log = tmp_path / "wp-calls.jsonl"
        out_dir = tmp_path / "evidence"

        make_fake_wp(
            ref_wp,
            role="reference",
            currencies=("GBP", "EUR"),
            call_log=call_log,
        )
        make_fake_wp(
            target_wp,
            role="target",
            currencies=("GBP", "EUR"),
            call_log=call_log,
        )
        make_fake_playwright(browser)

        result = run_gate(
            "--ref",
            str(ref_wp),
            "--target",
            str(target_wp),
            "--currency-from",
            "USD",
            "--currencies-to",
            "GBP,EUR",
            "--out-dir",
            str(out_dir),
            env=browser_env(browser),
        )

        assert result.returncode == 0, result.stderr
        assert "PASS:" in result.stdout

        cleanup = json.loads(
            (out_dir / "mc-rates-cleanup-restore.json").read_text(encoding="utf-8")
        )
        assert cleanup["schema"] == "woopayments_mc_cleanup_restore.v1"
        assert cleanup["status"] == "pass"
        assert cleanup["trigger"] == "normal"
        assert cleanup["reference_option_restore"]["restored"] is True
        assert cleanup["reference_option_restore"]["verified"] is True
        assert cleanup["target_option_restore"]["restored"] is True
        assert cleanup["target_option_restore"]["verified"] is True
        assert cleanup["product_cleanup"]["cleaned"] is True
        assert cleanup["reference_snapshot"] == {"captured": False}
        assert set(cleanup["target_snapshot"]["option_names"]) == (
            expected_mutated_options("GBP", "EUR")
        )
        snapshot_payload = json.loads(
            base64.b64decode(
                cleanup["target_snapshot"]["snapshot_b64"], validate=True
            )
        )
        assert snapshot_payload["options"][
            "wcpay_multi_currency_manual_rate_gbp"
        ] == {"exists": False}
        cached_currencies = snapshot_payload["options"][
            "wcpay_multi_currency_cached_currencies"
        ]
        assert cached_currencies["exists"] is True
        assert base64.b64decode(
            cached_currencies["option_value_b64"], validate=True
        ) == b"serialized:wcpay_multi_currency_cached_currencies"
        assert cached_currencies["autoload"] == "auto"

        calls = read_wp_calls(call_log)
        first_configure = min(
            index
            for index, call in enumerate(calls)
            if "mc_rates_configure" in call["args"]
        )
        snapshot_calls = [
            (index, call)
            for index, call in enumerate(calls)
            if "mc_rates_snapshot_options" in call["args"]
        ]
        assert {call["role"] for _, call in snapshot_calls} == {"target"}
        assert all(index < first_configure for index, _ in snapshot_calls)
        restore_calls = [
            call for call in calls if "mc_rates_restore_options" in call["args"]
        ]
        assert [call["role"] for call in restore_calls] == ["target"]

        rollup = json.loads((out_dir / "mc-rates-gate.json").read_text(encoding="utf-8"))
        cleanup_file = rollup["evidence_files"]["cleanup_restore"]
        assert cleanup_file["path"] == "mc-rates-cleanup-restore.json"
        assert len(cleanup_file["sha256"]) == 64


def test_gate_streams_large_option_snapshot_without_process_arguments() -> None:
    with tempfile.TemporaryDirectory(prefix="mc-rates-gate-large-snapshot-") as tmp:
        tmp_path = Path(tmp)
        ref_wp = tmp_path / "reference" / "wp"
        target_wp = tmp_path / "target" / "wp"
        browser = tmp_path / "playwright-runner"
        out_dir = tmp_path / "evidence"

        make_fake_wp(ref_wp, role="reference")
        make_fake_wp(
            target_wp,
            role="target",
            large_snapshot_bytes=512 * 1024,
        )
        make_fake_playwright(browser)

        result = run_gate(
            "--ref",
            str(ref_wp),
            "--target",
            str(target_wp),
            "--currency-from",
            "USD",
            "--currencies-to",
            "GBP",
            "--out-dir",
            str(out_dir),
            env=browser_env(browser),
        )

        assert result.returncode == 0, result.stderr
        snapshot_path = out_dir / "target-option-snapshot.json"
        assert snapshot_path.stat().st_size > 512 * 1024
        cleanup = json.loads(
            (out_dir / "mc-rates-cleanup-restore.json").read_text(encoding="utf-8")
        )
        assert cleanup["target_option_restore"]["verified"] is True


def test_gate_blocks_when_restored_values_are_not_visible_through_option_cache() -> None:
    with tempfile.TemporaryDirectory(prefix="mc-rates-gate-test-") as tmp:
        tmp_path = Path(tmp)
        ref_wp = tmp_path / "reference" / "wp"
        target_wp = tmp_path / "target" / "wp"
        browser = tmp_path / "playwright-runner"
        out_dir = tmp_path / "evidence"

        make_fake_wp(ref_wp, role="reference")
        make_fake_wp(
            target_wp,
            role="target",
            cache_restore_verified=False,
        )
        make_fake_playwright(browser)

        result = run_gate(
            "--ref",
            str(ref_wp),
            "--target",
            str(target_wp),
            "--out-dir",
            str(out_dir),
            env=browser_env(browser),
        )

        assert result.returncode == 3
        rollup = json.loads((out_dir / "mc-rates-gate.json").read_text(encoding="utf-8"))
        assert rollup["status"] == "blocked"
        assert any(
            item["code"] == "target_option_restore_failed"
            for item in rollup["failure_details"]
        )


def test_unknown_storefront_evidence_status_never_defaults_to_pass() -> None:
    with tempfile.TemporaryDirectory(prefix="mc-rates-gate-test-") as tmp:
        tmp_path = Path(tmp)
        ref_wp = tmp_path / "reference" / "wp"
        target_wp = tmp_path / "target" / "wp"
        browser = tmp_path / "playwright-runner"
        interceptor = tmp_path / "bin" / "python3"
        out_dir = tmp_path / "evidence"

        make_fake_wp(ref_wp, role="reference")
        make_fake_wp(target_wp, role="target")
        make_fake_playwright(browser)
        make_python_stdin_interceptor(
            interceptor,
            marker="woopayments_mc_storefront_price_evidence.v1",
            replacement_status="unknown",
        )

        result = run_gate(
            "--ref",
            str(ref_wp),
            "--target",
            str(target_wp),
            "--out-dir",
            str(out_dir),
            env=intercepted_python_env(interceptor, browser),
        )

        assert result.returncode == 3
        rollup = json.loads((out_dir / "mc-rates-gate.json").read_text(encoding="utf-8"))
        assert rollup["status"] == "blocked"
        assert any(
            item["code"] == "invalid_evidence_status"
            for item in rollup["failure_details"]
        )


def test_storefront_evidence_writer_failure_is_blocked() -> None:
    with tempfile.TemporaryDirectory(prefix="mc-rates-gate-test-") as tmp:
        tmp_path = Path(tmp)
        ref_wp = tmp_path / "reference" / "wp"
        target_wp = tmp_path / "target" / "wp"
        browser = tmp_path / "playwright-runner"
        interceptor = tmp_path / "bin" / "python3"
        out_dir = tmp_path / "evidence"

        make_fake_wp(ref_wp, role="reference")
        make_fake_wp(target_wp, role="target")
        make_fake_playwright(browser)
        make_python_stdin_interceptor(
            interceptor,
            marker="woopayments_mc_storefront_price_evidence.v1",
        )

        result = run_gate(
            "--ref",
            str(ref_wp),
            "--target",
            str(target_wp),
            "--out-dir",
            str(out_dir),
            env=intercepted_python_env(interceptor, browser),
        )

        assert result.returncode == 3
        rollup = json.loads((out_dir / "mc-rates-gate.json").read_text(encoding="utf-8"))
        assert rollup["status"] == "blocked"
        assert any(
            item["code"] == "storefront_evidence_write_failed"
            for item in rollup["failure_details"]
        )


def test_gate_restores_target_after_intermediate_target_failure() -> None:
    with tempfile.TemporaryDirectory(prefix="mc-rates-gate-test-") as tmp:
        tmp_path = Path(tmp)
        ref_wp = tmp_path / "reference" / "wp"
        target_wp = tmp_path / "target" / "wp"
        call_log = tmp_path / "wp-calls.jsonl"
        out_dir = tmp_path / "evidence"

        make_fake_wp(ref_wp, role="reference", call_log=call_log)
        make_fake_wp(
            target_wp,
            role="target",
            call_log=call_log,
            fail_on_marker="mc_rates_build_state",
        )

        result = run_gate(
            "--ref",
            str(ref_wp),
            "--target",
            str(target_wp),
            "--currency-from",
            "USD",
            "--currencies-to",
            "GBP",
            "--out-dir",
            str(out_dir),
        )

        assert result.returncode == 3
        assert "target rate refresh probe unavailable" in result.stderr
        cleanup = json.loads(
            (out_dir / "mc-rates-cleanup-restore.json").read_text(encoding="utf-8")
        )
        assert cleanup["status"] == "pass"
        assert cleanup["reference_option_restore"]["verified"] is True
        assert cleanup["target_option_restore"]["verified"] is True
        assert cleanup["product_cleanup"] == {"cleaned": True, "not_required": True}

        calls = read_wp_calls(call_log)
        target_failure_index = next(
            index
            for index, call in enumerate(calls)
            if call["role"] == "target" and "mc_rates_build_state" in call["args"]
        )
        restore_calls = [
            (index, call)
            for index, call in enumerate(calls)
            if "mc_rates_restore_options" in call["args"]
        ]
        assert [call["role"] for _, call in restore_calls] == ["target"]
        assert all(index > target_failure_index for index, _ in restore_calls)


def test_snapshot_failure_prevents_all_configuration_mutations() -> None:
    with tempfile.TemporaryDirectory(prefix="mc-rates-gate-test-") as tmp:
        tmp_path = Path(tmp)
        ref_wp = tmp_path / "reference" / "wp"
        target_wp = tmp_path / "target" / "wp"
        call_log = tmp_path / "wp-calls.jsonl"
        out_dir = tmp_path / "evidence"

        make_fake_wp(ref_wp, role="reference", call_log=call_log)
        make_fake_wp(
            target_wp,
            role="target",
            call_log=call_log,
            fail_on_marker="mc_rates_snapshot_options",
        )

        result = run_gate(
            "--ref",
            str(ref_wp),
            "--target",
            str(target_wp),
            "--currency-from",
            "USD",
            "--currencies-to",
            "GBP",
            "--out-dir",
            str(out_dir),
        )

        assert result.returncode == 3
        assert "target option snapshot probe unavailable" in result.stderr
        calls = read_wp_calls(call_log)
        assert not any("mc_rates_configure" in call["args"] for call in calls)
        assert not any("mc_rates_restore_options" in call["args"] for call in calls)
        rollup = json.loads((out_dir / "mc-rates-gate.json").read_text(encoding="utf-8"))
        assert rollup["status"] == "blocked"
        assert set(rollup["evidence_files"]) == {
            "store_identities",
            "reference_rate_refresh",
        }


def test_option_restore_failure_blocks_an_otherwise_passing_gate() -> None:
    with tempfile.TemporaryDirectory(prefix="mc-rates-gate-test-") as tmp:
        tmp_path = Path(tmp)
        ref_wp = tmp_path / "reference" / "wp"
        target_wp = tmp_path / "target" / "wp"
        browser = tmp_path / "playwright-runner"
        out_dir = tmp_path / "evidence"

        make_fake_wp(ref_wp, role="reference")
        make_fake_wp(target_wp, role="target", restore_succeeds=False)
        make_fake_playwright(browser)

        result = run_gate(
            "--ref",
            str(ref_wp),
            "--target",
            str(target_wp),
            "--currency-from",
            "USD",
            "--currencies-to",
            "GBP",
            "--out-dir",
            str(out_dir),
            env=browser_env(browser),
        )

        assert result.returncode == 3
        assert "PASS:" not in result.stdout
        assert "BLOCKED: target option restoration failed verification" in result.stderr
        cleanup = json.loads(
            (out_dir / "mc-rates-cleanup-restore.json").read_text(encoding="utf-8")
        )
        assert cleanup["status"] == "blocked"
        detail = next(
            item
            for item in cleanup["failure_details"]
            if item["code"] == "target_option_restore_failed"
        )
        assert detail["classification"] == "environment"

        rollup = json.loads((out_dir / "mc-rates-gate.json").read_text(encoding="utf-8"))
        assert rollup["status"] == "blocked"
        assert any(
            item["code"] == "target_option_restore_failed"
            for item in rollup["failure_details"]
        )


def test_option_restore_failure_overrides_core_mismatch_to_blocked() -> None:
    with tempfile.TemporaryDirectory(prefix="mc-rates-gate-test-") as tmp:
        tmp_path = Path(tmp)
        ref_wp = tmp_path / "reference" / "wp"
        target_wp = tmp_path / "target" / "wp"
        out_dir = tmp_path / "evidence"

        make_fake_wp(ref_wp, role="reference")
        make_fake_wp(
            target_wp,
            role="target",
            provider="",
            restore_succeeds=False,
        )

        result = run_gate(
            "--ref",
            str(ref_wp),
            "--target",
            str(target_wp),
            "--currency-from",
            "USD",
            "--currencies-to",
            "GBP",
            "--out-dir",
            str(out_dir),
        )

        assert result.returncode == 3
        assert "PASS:" not in result.stdout
        assert "BLOCKED: target option restoration failed verification" in (
            result.stderr
        )
        rollup = json.loads((out_dir / "mc-rates-gate.json").read_text(encoding="utf-8"))
        assert rollup["status"] == "blocked"
        assert any(
            item["code"] == "target_provider_not_woopayments"
            for item in rollup["failure_details"]
        )
        assert any(
            item["code"] == "target_option_restore_failed"
            for item in rollup["failure_details"]
        )


def test_product_cleanup_failure_is_environment_blocker_and_never_passes() -> None:
    with tempfile.TemporaryDirectory(prefix="mc-rates-gate-test-") as tmp:
        tmp_path = Path(tmp)
        ref_wp = tmp_path / "reference" / "wp"
        target_wp = tmp_path / "target" / "wp"
        browser = tmp_path / "playwright-runner"
        out_dir = tmp_path / "evidence"

        make_fake_wp(ref_wp, role="reference")
        make_fake_wp(
            target_wp,
            role="target",
            product_cleanup_succeeds=False,
        )
        make_fake_playwright(browser)

        result = run_gate(
            "--ref",
            str(ref_wp),
            "--target",
            str(target_wp),
            "--currency-from",
            "USD",
            "--currencies-to",
            "GBP",
            "--out-dir",
            str(out_dir),
            env=browser_env(browser),
        )

        assert result.returncode == 3
        assert "PASS:" not in result.stdout
        assert "BLOCKED: target storefront evidence product cleanup failed" in (
            result.stderr
        )
        cleanup = json.loads(
            (out_dir / "mc-rates-cleanup-restore.json").read_text(encoding="utf-8")
        )
        assert cleanup["status"] == "blocked"
        assert any(
            item["code"] == "storefront_fixture_cleanup_failed"
            and item["classification"] == "environment"
            for item in cleanup["failure_details"]
        )
        rollup = json.loads((out_dir / "mc-rates-gate.json").read_text(encoding="utf-8"))
        assert rollup["status"] == "blocked"


def test_prepare_transport_failure_still_cleans_product_by_known_sku() -> None:
    with tempfile.TemporaryDirectory(prefix="mc-rates-gate-test-") as tmp:
        tmp_path = Path(tmp)
        ref_wp = tmp_path / "reference" / "wp"
        target_wp = tmp_path / "target" / "wp"
        call_log = tmp_path / "wp-calls.jsonl"
        out_dir = tmp_path / "evidence"

        make_fake_wp(ref_wp, role="reference", call_log=call_log)
        make_fake_wp(
            target_wp,
            role="target",
            call_log=call_log,
            storefront_prepare_command_fails_after_create=True,
        )

        result = run_gate(
            "--ref",
            str(ref_wp),
            "--target",
            str(target_wp),
            "--currency-from",
            "USD",
            "--currencies-to",
            "GBP",
            "--out-dir",
            str(out_dir),
        )

        assert result.returncode == 3
        assert "target storefront evidence product probe unavailable" in result.stderr
        cleanup_calls = [
            call["args"]
            for call in read_wp_calls(call_log)
            if call["role"] == "target"
            and "mc_rates_cleanup_storefront_product" in call["args"]
        ]
        assert len(cleanup_calls) == 1
        assert "mc-rates-gate-" in cleanup_calls[0]
        cleanup = json.loads(
            (out_dir / "mc-rates-cleanup-restore.json").read_text(encoding="utf-8")
        )
        assert cleanup["status"] == "pass"
        assert cleanup["product_cleanup"]["cleaned"] is True


def test_signal_exit_restores_target_store() -> None:
    with tempfile.TemporaryDirectory(prefix="mc-rates-gate-test-") as tmp:
        tmp_path = Path(tmp)
        ref_wp = tmp_path / "reference" / "wp"
        target_wp = tmp_path / "target" / "wp"
        call_log = tmp_path / "wp-calls.jsonl"
        pause_ready = tmp_path / "pause-ready"
        out_dir = tmp_path / "evidence"

        make_fake_wp(ref_wp, role="reference", call_log=call_log)
        make_fake_wp(
            target_wp,
            role="target",
            call_log=call_log,
            pause_on_marker="mc_rates_build_state",
            pause_ready_file=pause_ready,
        )

        command_args, process_env = adapt_wp_runner_arguments(
            [
                "--ref",
                str(ref_wp),
                "--target",
                str(target_wp),
                "--ref-url",
                REF_URL,
                "--target-url",
                TARGET_URL,
                "--currency-from",
                "USD",
                "--currencies-to",
                "GBP",
                "--out-dir",
                str(out_dir),
            ],
            os.environ.copy(),
        )
        process = subprocess.Popen(
            [
                "bash",
                str(SCRIPT),
                *command_args,
            ],
            cwd=REPO,
            text=True,
            stdout=subprocess.PIPE,
            stderr=subprocess.PIPE,
            env=process_env,
            start_new_session=True,
        )
        deadline = time.monotonic() + 10
        while not pause_ready.exists() and process.poll() is None:
            if time.monotonic() >= deadline:
                process.kill()
                raise AssertionError("synthetic target build did not reach signal point")
            time.sleep(0.05)

        os.killpg(process.pid, signal.SIGTERM)
        stdout, stderr = process.communicate(timeout=15)

        assert process.returncode == 143, (stdout, stderr)
        cleanup = json.loads(
            (out_dir / "mc-rates-cleanup-restore.json").read_text(encoding="utf-8")
        )
        assert cleanup["trigger"] == "signal"
        assert cleanup["status"] == "pass"
        assert cleanup["target_option_restore"]["verified"] is True
        assert cleanup["reference_option_restore"]["verified"] is True
        calls = read_wp_calls(call_log)
        assert [
            call["role"]
            for call in calls
            if "mc_rates_restore_options" in call["args"]
        ] == ["target"]


def test_full_gate_compares_reference_and_target_rates() -> None:
    with tempfile.TemporaryDirectory(prefix="mc-rates-gate-test-") as tmp:
        tmp_path = Path(tmp)
        ref_wp = tmp_path / "reference" / "wp"
        target_wp = tmp_path / "target" / "wp"
        browser = tmp_path / "playwright-runner"
        out_dir = tmp_path / "evidence"

        make_fake_wp(ref_wp, role="reference")
        make_fake_wp(target_wp, role="target")
        make_fake_playwright(browser)

        result = run_gate(
            "--ref",
            str(ref_wp),
            "--target",
            str(target_wp),
            "--currency-from",
            "USD",
            "--currencies-to",
            "GBP",
            "--out-dir",
            str(out_dir),
            env=browser_env(browser),
        )

        assert result.returncode == 0, result.stderr
        assert (
            "PASS: native multi-currency rate refresh and storefront pricing matched "
            "the reference rate oracle." in result.stdout
        )

        rollup = json.loads((out_dir / "mc-rates-gate.json").read_text(encoding="utf-8"))
        assert rollup["status"] == "pass"
        assert rollup["target"]["provider"] == "woopayments"
        assert rollup["target"]["rates"] == {"GBP": "0.80"}
        assert rollup["reference"]["rates"] == {"GBP": "0.80"}

        reference_refresh = json.loads(
            (out_dir / "reference-rate-refresh.json").read_text(encoding="utf-8")
        )
        target_refresh = json.loads(
            (out_dir / "target-rate-refresh.json").read_text(encoding="utf-8")
        )
        storefront = json.loads(
            (out_dir / "target-storefront-price.json").read_text(encoding="utf-8")
        )

        assert reference_refresh["schema"] == "woopayments_mc_rate_refresh_evidence.v1"
        assert target_refresh["schema"] == "woopayments_mc_rate_refresh_evidence.v1"
        assert target_refresh["configure"]["cache_absent_after_delete"] is True
        assert target_refresh["observation"]["fetched"] >= target_refresh["configure"][
            "refresh_started_at"
        ]
        assert target_refresh["freshness"]["status"] == "pass"
        assert storefront["schema"] == "woopayments_mc_storefront_price_evidence.v1"
        assert storefront["status"] == "pass"
        assert storefront["observed_rate"] == "0.80"
        assert storefront["expected_price"] == "80.00"
        assert storefront["browser"]["displayed_amount_text"] == "80.00"
        assert storefront["cleanup"]["cleaned"] is True

        evidence_files = rollup["evidence_files"]
        assert evidence_files["reference_rate_refresh"]["path"] == (
            "reference-rate-refresh.json"
        )
        assert len(evidence_files["reference_rate_refresh"]["sha256"]) == 64
        assert evidence_files["target_rate_refresh"]["path"] == (
            "target-rate-refresh.json"
        )
        assert len(evidence_files["target_rate_refresh"]["sha256"]) == 64
        assert evidence_files["target_storefront_price"]["path"] == (
            "target-storefront-price.json"
        )
        assert len(evidence_files["target_storefront_price"]["sha256"]) == 64

        browser_driver = (out_dir / "mc-rates-storefront-driver.mjs").read_text(
            encoding="utf-8"
        )
        assert "body.single-product div.product .summary .price" in browser_driver
        assert "body.single-product .wp-block-woocommerce-product-price" in browser_driver


def test_gate_fails_when_target_provider_is_unavailable() -> None:
    with tempfile.TemporaryDirectory(prefix="mc-rates-gate-test-") as tmp:
        tmp_path = Path(tmp)
        ref_wp = tmp_path / "reference" / "wp"
        target_wp = tmp_path / "target" / "wp"
        out_dir = tmp_path / "evidence"

        make_fake_wp(ref_wp, role="reference")
        make_fake_wp(target_wp, role="target", provider="")

        result = run_gate(
            "--ref",
            str(ref_wp),
            "--target",
            str(target_wp),
            "--currency-from",
            "USD",
            "--currencies-to",
            "GBP",
            "--out-dir",
            str(out_dir),
        )

        assert result.returncode == 1
        assert "target provider is not woopayments" in result.stderr

        rollup = json.loads((out_dir / "mc-rates-gate.json").read_text(encoding="utf-8"))
        assert rollup["status"] == "fail"
        assert "target provider is not woopayments" in rollup["failures"]


def test_gate_blocks_when_registered_target_provider_is_environmentally_unavailable() -> None:
    with tempfile.TemporaryDirectory(prefix="mc-rates-gate-test-") as tmp:
        tmp_path = Path(tmp)
        ref_wp = tmp_path / "reference" / "wp"
        target_wp = tmp_path / "target" / "wp"
        out_dir = tmp_path / "evidence"

        make_fake_wp(ref_wp, role="reference")
        make_fake_wp(
            target_wp,
            role="target",
            provider="",
            provider_registered=True,
            provider_available=False,
        )

        result = run_gate(
            "--ref",
            str(ref_wp),
            "--target",
            str(target_wp),
            "--currency-from",
            "USD",
            "--currencies-to",
            "GBP",
            "--out-dir",
            str(out_dir),
        )

        assert result.returncode == 3
        assert "BLOCKED: target WooPayments rate provider is registered but unavailable" in (
            result.stderr
        )
        rollup = json.loads((out_dir / "mc-rates-gate.json").read_text(encoding="utf-8"))
        assert rollup["status"] == "blocked"
        detail = next(
            item
            for item in rollup["failure_details"]
            if item["code"] == "target_provider_environment_unavailable"
        )
        assert detail["classification"] == "provider_or_environment"


def test_gate_blocks_when_both_read_only_reference_rate_sources_are_unavailable() -> None:
    with tempfile.TemporaryDirectory(prefix="mc-rates-gate-test-") as tmp:
        tmp_path = Path(tmp)
        ref_wp = tmp_path / "reference" / "wp"
        target_wp = tmp_path / "target" / "wp"
        out_dir = tmp_path / "evidence"

        make_fake_wp(
            ref_wp,
            role="reference",
            reference_transact_error="transact_authentication_failure",
            reference_route_error="wcpay_route_unavailable",
        )
        make_fake_wp(target_wp, role="target")

        result = run_gate(
            "--ref",
            str(ref_wp),
            "--target",
            str(target_wp),
            "--currency-from",
            "USD",
            "--currencies-to",
            "GBP",
            "--out-dir",
            str(out_dir),
        )

        assert result.returncode == 3
        assert "BLOCKED: reference rate oracle unavailable" in result.stderr

        rollup = json.loads((out_dir / "mc-rates-gate.json").read_text(encoding="utf-8"))
        assert rollup["status"] == "blocked"
        assert "reference rate oracle unavailable" in rollup["failures"]
        assert rollup["target"] == {}


def test_blocked_reference_oracle_rollup_records_safe_transport_diagnostics() -> None:
    with tempfile.TemporaryDirectory(prefix="mc-rates-gate-test-") as tmp:
        tmp_path = Path(tmp)
        ref_wp = tmp_path / "reference" / "wp"
        target_wp = tmp_path / "target" / "wp"
        out_dir = tmp_path / "evidence"

        make_fake_wp(ref_wp, role="reference", cache_errored=True)
        make_fake_wp(target_wp, role="target")

        result = run_gate(
            "--ref",
            str(ref_wp),
            "--target",
            str(target_wp),
            "--currency-from",
            "USD",
            "--currencies-to",
            "GBP",
            "--out-dir",
            str(out_dir),
        )

        assert result.returncode == 3

        rollup = json.loads((out_dir / "mc-rates-gate.json").read_text(encoding="utf-8"))
        diagnostics = rollup["diagnostics"]["reference_client"]
        assert diagnostics["server_connected"] is True
        assert diagnostics["transact_method"]["ok"] is False
        assert diagnostics["transact_method"]["error_code"] == "transact_authentication_failure"
        assert diagnostics["wcpay_route_control"]["ok"] is True
        assert diagnostics["wcpay_route_control"]["rates"] == {"GBP": "0.80"}
        reference_evidence = json.loads(
            (out_dir / "reference-rate-refresh.json").read_text(encoding="utf-8")
        )
        assert reference_evidence["freshness"]["status"] == "pass"
        assert reference_evidence["source"] == (
            "standalone_plugin_wcpay_route_control"
        )


def test_gate_fails_before_rate_mutation_when_target_native_runtime_is_not_owner() -> None:
    with tempfile.TemporaryDirectory(prefix="mc-rates-gate-test-") as tmp:
        tmp_path = Path(tmp)
        ref_wp = tmp_path / "reference" / "wp"
        target_wp = tmp_path / "target" / "wp"
        out_dir = tmp_path / "evidence"

        make_fake_wp(ref_wp, role="reference")
        make_fake_wp(target_wp, role="target", native_owner="none")
        out_dir.mkdir()
        for filename in (
            "reference-rate-refresh.json",
            "target-rate-refresh.json",
            "target-storefront-price.json",
            "mc-rates-cleanup-restore.json",
        ):
            (out_dir / filename).write_text('{"stale":true}\n', encoding="utf-8")

        result = run_gate(
            "--ref",
            str(ref_wp),
            "--target",
            str(target_wp),
            "--currency-from",
            "USD",
            "--currencies-to",
            "GBP",
            "--out-dir",
            str(out_dir),
        )

        assert result.returncode == 3
        assert "BLOCKED: target native payments owner is not native: none" in result.stderr

        rollup = json.loads((out_dir / "mc-rates-gate.json").read_text(encoding="utf-8"))
        assert rollup["status"] == "blocked"
        assert "target native payments owner is not native: none" in rollup["failures"]
        assert set(rollup["evidence_files"]) == {"store_identities"}


def test_gate_fails_when_target_cache_was_not_regenerated_in_this_run() -> None:
    with tempfile.TemporaryDirectory(prefix="mc-rates-gate-test-") as tmp:
        tmp_path = Path(tmp)
        ref_wp = tmp_path / "reference" / "wp"
        target_wp = tmp_path / "target" / "wp"
        out_dir = tmp_path / "evidence"

        make_fake_wp(ref_wp, role="reference")
        make_fake_wp(
            target_wp,
            role="target",
            fetched_at=1_999_999_000,
            updated_at=1_999_999_000,
        )

        result = run_gate(
            "--ref",
            str(ref_wp),
            "--target",
            str(target_wp),
            "--currency-from",
            "USD",
            "--currencies-to",
            "GBP",
            "--out-dir",
            str(out_dir),
        )

        assert result.returncode == 1
        assert "target cache fetched timestamp predates this refresh" in result.stderr
        rollup = json.loads((out_dir / "mc-rates-gate.json").read_text(encoding="utf-8"))
        assert rollup["status"] == "fail"
        detail = next(
            item
            for item in rollup["failure_details"]
            if item["code"] == "stale_target_rate_cache"
        )
        assert detail["classification"] == "core"

        target_refresh = json.loads(
            (out_dir / "target-rate-refresh.json").read_text(encoding="utf-8")
        )
        assert target_refresh["freshness"]["status"] == "fail"


def test_gate_blocks_when_harness_cannot_prove_target_cache_deletion() -> None:
    with tempfile.TemporaryDirectory(prefix="mc-rates-gate-test-") as tmp:
        tmp_path = Path(tmp)
        ref_wp = tmp_path / "reference" / "wp"
        target_wp = tmp_path / "target" / "wp"
        out_dir = tmp_path / "evidence"

        make_fake_wp(ref_wp, role="reference")
        make_fake_wp(
            target_wp,
            role="target",
            cache_absent_after_delete=False,
        )

        result = run_gate(
            "--ref",
            str(ref_wp),
            "--target",
            str(target_wp),
            "--currency-from",
            "USD",
            "--currencies-to",
            "GBP",
            "--out-dir",
            str(out_dir),
        )

        assert result.returncode == 3
        assert "BLOCKED: target cache deletion could not be proven" in result.stderr
        rollup = json.loads((out_dir / "mc-rates-gate.json").read_text(encoding="utf-8"))
        detail = next(
            item
            for item in rollup["failure_details"]
            if item["code"] == "target_cache_reset_unproven"
        )
        assert detail["classification"] == "environment"


def test_gate_recomputes_storefront_price_match_from_observed_rate() -> None:
    with tempfile.TemporaryDirectory(prefix="mc-rates-gate-test-") as tmp:
        tmp_path = Path(tmp)
        ref_wp = tmp_path / "reference" / "wp"
        target_wp = tmp_path / "target" / "wp"
        browser = tmp_path / "playwright-runner"
        out_dir = tmp_path / "evidence"

        make_fake_wp(ref_wp, role="reference")
        make_fake_wp(target_wp, role="target")
        make_fake_playwright(browser)

        result = run_gate(
            "--ref",
            str(ref_wp),
            "--target",
            str(target_wp),
            "--currency-from",
            "USD",
            "--currencies-to",
            "GBP",
            "--out-dir",
            str(out_dir),
            env=browser_env(browser, FAKE_STOREFRONT_PRICE="75.00"),
        )

        assert result.returncode == 1
        assert "storefront price mismatch" in result.stderr
        rollup = json.loads((out_dir / "mc-rates-gate.json").read_text(encoding="utf-8"))
        assert rollup["status"] == "fail"
        detail = next(
            item
            for item in rollup["failure_details"]
            if item["code"] == "storefront_price_mismatch"
        )
        assert detail["classification"] == "core"

        storefront = json.loads(
            (out_dir / "target-storefront-price.json").read_text(encoding="utf-8")
        )
        assert storefront["status"] == "fail"
        assert storefront["observed_rate"] == "0.80"
        assert storefront["expected_price"] == "80.00"
        assert storefront["browser"]["displayed_amount_text"] == "75.00"


def test_gate_blocks_when_local_storefront_browser_is_unavailable() -> None:
    with tempfile.TemporaryDirectory(prefix="mc-rates-gate-test-") as tmp:
        tmp_path = Path(tmp)
        ref_wp = tmp_path / "reference" / "wp"
        target_wp = tmp_path / "target" / "wp"
        browser = tmp_path / "playwright-runner"
        out_dir = tmp_path / "evidence"

        make_fake_wp(ref_wp, role="reference")
        make_fake_wp(target_wp, role="target")
        make_fake_playwright(browser)

        result = run_gate(
            "--ref",
            str(ref_wp),
            "--target",
            str(target_wp),
            "--currency-from",
            "USD",
            "--currencies-to",
            "GBP",
            "--out-dir",
            str(out_dir),
            env=browser_env(browser, FAKE_STOREFRONT_BROWSER_ERROR="1"),
        )

        assert result.returncode == 3
        assert "BLOCKED: target storefront browser probe unavailable" in result.stderr
        rollup = json.loads((out_dir / "mc-rates-gate.json").read_text(encoding="utf-8"))
        assert rollup["status"] == "blocked"
        detail = next(
            item
            for item in rollup["failure_details"]
            if item["code"] == "storefront_browser_unavailable"
        )
        assert detail["classification"] == "environment"

        storefront = json.loads(
            (out_dir / "target-storefront-price.json").read_text(encoding="utf-8")
        )
        assert storefront["status"] == "blocked"
        assert storefront["cleanup"]["cleaned"] is True


def test_gate_blocks_when_storefront_product_fixture_cannot_be_prepared() -> None:
    with tempfile.TemporaryDirectory(prefix="mc-rates-gate-test-") as tmp:
        tmp_path = Path(tmp)
        ref_wp = tmp_path / "reference" / "wp"
        target_wp = tmp_path / "target" / "wp"
        out_dir = tmp_path / "evidence"

        make_fake_wp(ref_wp, role="reference")
        make_fake_wp(
            target_wp,
            role="target",
            storefront_product_prepared=False,
        )

        result = run_gate(
            "--ref",
            str(ref_wp),
            "--target",
            str(target_wp),
            "--currency-from",
            "USD",
            "--currencies-to",
            "GBP",
            "--out-dir",
            str(out_dir),
        )

        assert result.returncode == 3
        assert "BLOCKED: target storefront browser probe unavailable" in result.stderr
        rollup = json.loads((out_dir / "mc-rates-gate.json").read_text(encoding="utf-8"))
        assert rollup["status"] == "blocked"
        storefront = json.loads(
            (out_dir / "target-storefront-price.json").read_text(encoding="utf-8")
        )
        assert storefront["status"] == "blocked"
        assert storefront["product"]["error_code"] == (
            "storefront_product_prepare_failed"
        )
        assert storefront["cleanup"]["cleaned"] is True
        assert storefront["cleanup"].get("not_required") is not True


def test_gate_blocks_target_provider_refresh_error_separately_from_core() -> None:
    with tempfile.TemporaryDirectory(prefix="mc-rates-gate-test-") as tmp:
        tmp_path = Path(tmp)
        ref_wp = tmp_path / "reference" / "wp"
        target_wp = tmp_path / "target" / "wp"
        out_dir = tmp_path / "evidence"

        make_fake_wp(ref_wp, role="reference")
        make_fake_wp(target_wp, role="target", cache_errored=True)

        result = run_gate(
            "--ref",
            str(ref_wp),
            "--target",
            str(target_wp),
            "--currency-from",
            "USD",
            "--currencies-to",
            "GBP",
            "--out-dir",
            str(out_dir),
        )

        assert result.returncode == 3
        assert "BLOCKED: target provider rate refresh unavailable" in result.stderr
        rollup = json.loads((out_dir / "mc-rates-gate.json").read_text(encoding="utf-8"))
        assert rollup["status"] == "blocked"
        detail = next(
            item
            for item in rollup["failure_details"]
            if item["code"] == "target_provider_refresh_unavailable"
        )
        assert detail["classification"] == "provider_or_environment"


def main() -> None:
    tests = [
        test_usage_requires_ref_and_target,
        test_print_plan_describes_rate_probe,
        test_rejects_unsafe_wp_runners_before_either_command_is_invoked,
        test_print_plan_rejects_unsafe_runner,
        test_rejects_remote_docker_environment_before_invocation,
        test_rejects_unapproved_wrapper_before_invocation,
        test_snapshot_php_fails_closed_on_database_read_error,
        test_restore_php_detects_stale_persistent_option_cache,
        test_reference_oracle_remains_read_only_during_successful_gate,
        test_unavailable_reference_oracle_blocks_before_target_mutation,
        test_gate_accepts_exact_docker_published_url_alias,
        test_gate_rejects_unpublished_docker_url_alias,
        test_identical_store_fingerprints_block_before_target_mutation,
        test_gate_restores_every_mutated_option_after_success,
        test_gate_streams_large_option_snapshot_without_process_arguments,
        test_gate_blocks_when_restored_values_are_not_visible_through_option_cache,
        test_unknown_storefront_evidence_status_never_defaults_to_pass,
        test_storefront_evidence_writer_failure_is_blocked,
        test_gate_restores_target_after_intermediate_target_failure,
        test_snapshot_failure_prevents_all_configuration_mutations,
        test_option_restore_failure_blocks_an_otherwise_passing_gate,
        test_option_restore_failure_overrides_core_mismatch_to_blocked,
        test_product_cleanup_failure_is_environment_blocker_and_never_passes,
        test_prepare_transport_failure_still_cleans_product_by_known_sku,
        test_signal_exit_restores_target_store,
        test_full_gate_compares_reference_and_target_rates,
        test_gate_fails_when_target_provider_is_unavailable,
        test_gate_blocks_when_registered_target_provider_is_environmentally_unavailable,
        test_gate_blocks_when_both_read_only_reference_rate_sources_are_unavailable,
        test_blocked_reference_oracle_rollup_records_safe_transport_diagnostics,
        test_gate_fails_before_rate_mutation_when_target_native_runtime_is_not_owner,
        test_gate_fails_when_target_cache_was_not_regenerated_in_this_run,
        test_gate_blocks_when_harness_cannot_prove_target_cache_deletion,
        test_gate_recomputes_storefront_price_match_from_observed_rate,
        test_gate_blocks_when_local_storefront_browser_is_unavailable,
        test_gate_blocks_when_storefront_product_fixture_cannot_be_prepared,
        test_gate_blocks_target_provider_refresh_error_separately_from_core,
    ]
    for test in tests:
        test()
    print(f"PASS: {len(tests)} mc-rates gate regression tests passed.")


if __name__ == "__main__":
    main()
