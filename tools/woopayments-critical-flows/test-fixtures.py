#!/usr/bin/env python3
"""Regression checks for critical-flow fixture setup wiring."""

from __future__ import annotations

import json
import os
import subprocess
import tempfile
from pathlib import Path


REPO = Path(__file__).resolve().parents[2]
FIXTURES = REPO / "tools/woopayments-critical-flows/setup/fixtures.sh"


def write_executable(path: Path, source: str) -> None:
    path.write_text(source, encoding="utf-8")
    path.chmod(0o755)


def run_fixture(
    *args: str,
    fake_wp_source: str,
    evidence_dir: Path,
    extra_env: dict[str, str] | None = None,
) -> subprocess.CompletedProcess[str]:
    fake_wp = evidence_dir / "fake-wp.sh"
    calls_log = evidence_dir / "wp-calls.jsonl"
    write_executable(fake_wp, fake_wp_source)

    return subprocess.run(
        ["bash", str(FIXTURES), *args],
        cwd=REPO,
        text=True,
        stdout=subprocess.PIPE,
        stderr=subprocess.PIPE,
        env={
            **os.environ,
            "TARGET_WP_COMMAND": str(fake_wp),
            "REF_WP_COMMAND": str(fake_wp),
            "EVIDENCE_DIR": str(evidence_dir),
            "WP_CALLS_LOG": str(calls_log),
            **(extra_env or {}),
        },
        check=False,
    )


def read_calls(evidence_dir: Path) -> list[list[str]]:
    path = evidence_dir / "wp-calls.jsonl"
    return [json.loads(line) for line in path.read_text(encoding="utf-8").splitlines() if line.strip()]


def logging_fake_wp(extra: str = "") -> str:
    return f"""#!/usr/bin/env bash
python3 - "$WP_CALLS_LOG" "$@" <<'PY'
import json
import sys
from pathlib import Path

path = Path(sys.argv[1])
with path.open("a", encoding="utf-8") as stream:
    stream.write(json.dumps(sys.argv[2:]) + "\\n")
PY
{extra}
printf '%s\\n' ""
"""


def test_fixture_products_create_missing_catalog_fixtures_once() -> None:
    with tempfile.TemporaryDirectory(prefix="critical-flow-fixtures-") as tmp:
        evidence_dir = Path(tmp)
        result = run_fixture(
            "fixture_products",
            "target",
            evidence_dir=evidence_dir,
            fake_wp_source=logging_fake_wp(
                """
if [ "$1" = "--user=1" ] && [ "$2" = "wc" ] && [ "$3" = "product" ] && [ "$4" = "list" ]; then
  case "$5" in
    --sku=cf-simple) printf '%s\\n' "111"; exit 0 ;;
    *) printf '%s\\n' ""; exit 0 ;;
  esac
fi
if [ "$1" = "--user=1" ] && [ "$2" = "wc" ] && [ "$3" = "product" ] && [ "$4" = "create" ]; then
  printf '%s\\n' '{"id":222}'
  exit 0
fi
"""
            ),
        )

        assert result.returncode == 0
        calls = read_calls(evidence_dir)
        create_calls = [call for call in calls if call[0:4] == ["--user=1", "wc", "product", "create"]]
        eval_calls = [call for call in calls if call[0:2] == ["--user=1", "eval"]]
        assert len(create_calls) == 2
        assert len(eval_calls) == 2
        assert not any("--sku=cf-simple" in call for call in create_calls)
        assert any("--sku=cf-affirm" in call and "--regular_price=50" in call for call in create_calls)
        assert any("--sku=cf-var" in call and "--type=variable" in call for call in create_calls)
        assert not any("--sku=cf-sub" in call for call in create_calls)
        assert not any("--sku=cf-trial" in call for call in create_calls)
        assert any("WC_Product_Subscription" in call[2] and "'cf-sub'" in call[2] for call in eval_calls)
        assert any("WC_Product_Subscription" in call[2] and "'cf-trial'" in call[2] for call in eval_calls)
        assert any("$trial_length = '14';" in call[2] for call in eval_calls)
        assert any("'_subscription_trial_length', $trial_length" in call[2] for call in eval_calls)


def test_fixture_products_fails_closed_when_create_fails() -> None:
    with tempfile.TemporaryDirectory(prefix="critical-flow-fixtures-") as tmp:
        evidence_dir = Path(tmp)
        result = run_fixture(
            "fixture_products",
            "target",
            evidence_dir=evidence_dir,
            fake_wp_source=logging_fake_wp(
                """
if [ "$1" = "--user=1" ] && [ "$2" = "wc" ] && [ "$3" = "product" ] && [ "$4" = "list" ]; then
  printf '%s\\n' ""
  exit 0
fi
if [ "$1" = "--user=1" ] && [ "$2" = "wc" ] && [ "$3" = "product" ] && [ "$4" = "create" ]; then
  printf '%s\\n' "cannot create product" >&2
  exit 1
fi
"""
            ),
        )

        assert result.returncode == 1
        assert "FAIL product create failed: cf-simple" in result.stdout


def test_fixture_products_stops_after_first_create_failure() -> None:
    with tempfile.TemporaryDirectory(prefix="critical-flow-fixtures-") as tmp:
        evidence_dir = Path(tmp)
        result = run_fixture(
            "fixture_products",
            "target",
            evidence_dir=evidence_dir,
            fake_wp_source=logging_fake_wp(
                """
if [ "$1" = "--user=1" ] && [ "$2" = "wc" ] && [ "$3" = "product" ] && [ "$4" = "list" ]; then
  case "$5" in
    --sku=cf-simple) printf '%s\\n' ""; exit 0 ;;
    *) printf '%s\\n' "111"; exit 0 ;;
  esac
fi
if [ "$1" = "--user=1" ] && [ "$2" = "wc" ] && [ "$3" = "product" ] && [ "$4" = "create" ]; then
  printf '%s\\n' "cannot create product" >&2
  exit 1
fi
"""
            ),
        )

        assert result.returncode == 1
        assert "FAIL product create failed: cf-simple" in result.stdout
        calls = read_calls(evidence_dir)
        assert not any("--sku=cf-affirm" in call for call in calls)


def test_fixture_settings_merge_named_woopayments_toggles() -> None:
    with tempfile.TemporaryDirectory(prefix="critical-flow-fixtures-") as tmp:
        evidence_dir = Path(tmp)
        result = run_fixture(
            "fixture_settings",
            "target",
            "capture=automatic",
            "saved_cards=yes",
            "woopay=no",
            evidence_dir=evidence_dir,
            fake_wp_source=logging_fake_wp(
                """
if [ "$1" = "option" ] && [ "$2" = "get" ] && [ "$3" = "woocommerce_woocommerce_payments_settings" ]; then
  printf '%s\\n' '{"enabled":"no","manual_capture":"yes","saved_cards":"no","platform_checkout":"yes","test_mode":"no","upe_enabled_payment_method_ids":[]}'
  exit 0
fi
if [ "$1" = "option" ] && [ "$2" = "update" ] && [ "$3" = "woocommerce_woocommerce_payments_settings" ]; then
  printf '%s\\n' "Success"
  exit 0
fi
"""
            ),
        )

        assert result.returncode == 0
        calls = read_calls(evidence_dir)
        update_calls = [
            call
            for call in calls
            if call[0:3] == ["option", "update", "woocommerce_woocommerce_payments_settings"]
        ]
        assert len(update_calls) == 1
        payload = json.loads(update_calls[0][3])
        assert payload["enabled"] == "yes"
        assert payload["manual_capture"] == "no"
        assert payload["saved_cards"] == "yes"
        assert payload["platform_checkout"] == "no"
        assert payload["test_mode"] == "yes"
        assert payload["upe_enabled_payment_method_ids"] == ["card"]
        assert update_calls[0][4] == "--format=json"


def test_account_set_flags_patches_existing_cache_json() -> None:
    with tempfile.TemporaryDirectory(prefix="critical-flow-fixtures-") as tmp:
        evidence_dir = Path(tmp)
        result = run_fixture(
            "account_set_flags",
            "target",
            "is_documents_enabled=true",
            "has_card_readers_available=false",
            evidence_dir=evidence_dir,
            fake_wp_source=logging_fake_wp(
                """
if [ "$1" = "option" ] && [ "$2" = "get" ] && [ "$3" = "wcpay_account_data" ]; then
  printf '%s\\n' '{"status":"complete","is_documents_enabled":false,"has_card_readers_available":true}'
  exit 0
fi
if [ "$1" = "option" ] && [ "$2" = "update" ] && [ "$3" = "wcpay_account_data" ]; then
  printf '%s\\n' "Success"
  exit 0
fi
"""
            ),
        )

        assert result.returncode == 0
        calls = read_calls(evidence_dir)
        update_calls = [call for call in calls if call[0:3] == ["option", "update", "wcpay_account_data"]]
        assert len(update_calls) == 1
        payload = json.loads(update_calls[0][3])
        assert payload["status"] == "complete"
        assert payload["is_documents_enabled"] is True
        assert payload["has_card_readers_available"] is False
        assert update_calls[0][4] == "--format=json"


def test_fixture_coupons_creates_missing_checkout_and_subscription_coupons() -> None:
    with tempfile.TemporaryDirectory(prefix="critical-flow-fixtures-") as tmp:
        evidence_dir = Path(tmp)
        result = run_fixture(
            "fixture_coupons",
            "target",
            evidence_dir=evidence_dir,
            fake_wp_source=logging_fake_wp(
                """
if [ "$1" = "--user=1" ] && [ "$2" = "wc" ] && [ "$3" = "shop_coupon" ] && [ "$4" = "list" ]; then
  case "$5" in
    --code=cf-oneoff) printf '%s\\n' "555"; exit 0 ;;
    *) printf '%s\\n' ""; exit 0 ;;
  esac
fi
if [ "$1" = "--user=1" ] && [ "$2" = "wc" ] && [ "$3" = "shop_coupon" ] && [ "$4" = "create" ]; then
  printf '%s\\n' '{"id":777}'
  exit 0
fi
"""
            ),
        )

        assert result.returncode == 0
        calls = read_calls(evidence_dir)
        create_calls = [call for call in calls if call[0:4] == ["--user=1", "wc", "shop_coupon", "create"]]
        assert len(create_calls) == 2
        assert not any("--code=cf-oneoff" in call for call in create_calls)
        assert any(
            "--code=cf-signup" in call and "--discount_type=sign_up_fee" in call and "--amount=5" in call
            for call in create_calls
        )
        assert any(
            "--code=cf-recurring" in call and "--discount_type=recurring_fee" in call and "--amount=5" in call
            for call in create_calls
        )


def test_fixture_coupons_fails_closed_when_create_fails() -> None:
    with tempfile.TemporaryDirectory(prefix="critical-flow-fixtures-") as tmp:
        evidence_dir = Path(tmp)
        result = run_fixture(
            "fixture_coupons",
            "target",
            evidence_dir=evidence_dir,
            fake_wp_source=logging_fake_wp(
                """
if [ "$1" = "--user=1" ] && [ "$2" = "wc" ] && [ "$3" = "shop_coupon" ] && [ "$4" = "list" ]; then
  printf '%s\\n' ""
  exit 0
fi
if [ "$1" = "--user=1" ] && [ "$2" = "wc" ] && [ "$3" = "shop_coupon" ] && [ "$4" = "create" ]; then
  printf '%s\\n' "cannot create coupon" >&2
  exit 1
fi
"""
            ),
        )

        assert result.returncode == 1
        assert "FAIL coupon create failed: cf-signup" in result.stdout


def test_fixture_shipping_configures_flat_and_free_shipping_methods() -> None:
    with tempfile.TemporaryDirectory(prefix="critical-flow-fixtures-") as tmp:
        evidence_dir = Path(tmp)
        result = run_fixture(
            "fixture_shipping",
            "target",
            evidence_dir=evidence_dir,
            fake_wp_source=logging_fake_wp(
                """
if [ "$1" = "--user=1" ] && [ "$2" = "eval" ]; then
  printf '%s\\n' "critical flow shipping fixture ready"
  exit 0
fi
"""
            ),
        )

        assert result.returncode == 0
        calls = read_calls(evidence_dir)
        eval_calls = [call for call in calls if call[0:2] == ["--user=1", "eval"]]
        assert len(eval_calls) == 1
        script = eval_calls[0][2]
        assert "WC_Shipping_Zone( 0 )" in script
        assert "flat_rate" in script
        assert "'cost'" in script
        assert "'20'" in script
        assert "free_shipping" in script
        assert "requires' => ''" in script


def test_fixture_shipping_fails_closed_when_wp_eval_fails() -> None:
    with tempfile.TemporaryDirectory(prefix="critical-flow-fixtures-") as tmp:
        evidence_dir = Path(tmp)
        result = run_fixture(
            "fixture_shipping",
            "target",
            evidence_dir=evidence_dir,
            fake_wp_source=logging_fake_wp(
                """
if [ "$1" = "--user=1" ] && [ "$2" = "eval" ]; then
  printf '%s\\n' "shipping setup failed" >&2
  exit 1
fi
"""
            ),
        )

        assert result.returncode == 1
        assert "FAIL shipping fixture setup failed" in result.stdout


def test_fixture_all_stops_when_coupon_setup_fails() -> None:
    with tempfile.TemporaryDirectory(prefix="critical-flow-fixtures-") as tmp:
        evidence_dir = Path(tmp)
        result = run_fixture(
            "fixture_all",
            "target",
            evidence_dir=evidence_dir,
            fake_wp_source=logging_fake_wp(
                """
if [ "$1" = "--user=1" ] && [ "$2" = "wc" ] && [ "$3" = "product" ] && [ "$4" = "list" ]; then
  printf '%s\\n' "111"
  exit 0
fi
if [ "$1" = "option" ] && [ "$2" = "get" ] && [ "$3" = "woocommerce_woocommerce_payments_settings" ]; then
  printf '%s\\n' '{"enabled":"yes"}'
  exit 0
fi
if [ "$1" = "option" ] && [ "$2" = "update" ] && [ "$3" = "woocommerce_woocommerce_payments_settings" ]; then
  printf '%s\\n' "Success"
  exit 0
fi
if [ "$1" = "--user=1" ] && [ "$2" = "wc" ] && [ "$3" = "shop_coupon" ] && [ "$4" = "list" ]; then
  printf '%s\\n' ""
  exit 0
fi
if [ "$1" = "--user=1" ] && [ "$2" = "wc" ] && [ "$3" = "shop_coupon" ] && [ "$4" = "create" ]; then
  printf '%s\\n' "cannot create coupon" >&2
  exit 1
fi
if [ "$1" = "--user=1" ] && [ "$2" = "eval" ]; then
  printf '%s\\n' "shipping should not run after coupon failure" >&2
  exit 0
fi
"""
            ),
        )

        assert result.returncode == 1
        assert "FAIL coupon create failed: cf-signup" in result.stdout
        calls = read_calls(evidence_dir)
        assert not any(call[0:2] == ["--user=1", "eval"] for call in calls)


def main() -> None:
    tests = [
        test_fixture_products_create_missing_catalog_fixtures_once,
        test_fixture_products_fails_closed_when_create_fails,
        test_fixture_products_stops_after_first_create_failure,
        test_fixture_settings_merge_named_woopayments_toggles,
        test_account_set_flags_patches_existing_cache_json,
        test_fixture_coupons_creates_missing_checkout_and_subscription_coupons,
        test_fixture_coupons_fails_closed_when_create_fails,
        test_fixture_shipping_configures_flat_and_free_shipping_methods,
        test_fixture_shipping_fails_closed_when_wp_eval_fails,
        test_fixture_all_stops_when_coupon_setup_fails,
    ]
    for test in tests:
        test()
        print(f"PASS {test.__name__}")


if __name__ == "__main__":
    main()
