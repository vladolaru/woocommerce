#!/usr/bin/env bash
#
# First-pass measured bundle-size gate for the WooPayments merge harness.
#
# Captures raw/gzip byte sizes for known WooPayments and multi-currency route
# assets. Missing files are recorded explicitly so a capture cannot pretend a
# surface was measured when the built asset was absent.

set -uo pipefail

SELF_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
COMPARE="$SELF_DIR/compare-measured-gates.py"

usage() {
	cat >&2 <<'EOF'
usage:
  bundle-size-gate.sh capture --repo <path> --out <json> [--profile auto|wcpay-plugin|wc-core]
  bundle-size-gate.sh compare --ref <json> --target <json> [--budget <json>]

Budget JSON shape:
  {"assets":{"dist/index.js":{"raw_growth_bytes":1024,"gzip_growth_bytes":512}}}
  {"assets":{"dist/new.js":{"allow_new":true},"dist/old.js":{"allow_missing":true}}}
EOF
	exit 2
}

mode="${1:-}"
[ -n "$mode" ] || usage
shift

case "$mode" in
	capture)
		repo=""
		out=""
		profile="auto"
		while [ "$#" -gt 0 ]; do
			case "$1" in
				--repo)
					repo="${2:-}"; shift 2 ;;
				--out)
					out="${2:-}"; shift 2 ;;
				--profile)
					profile="${2:-}"; shift 2 ;;
				*)
					usage ;;
			esac
		done
		[ -n "$repo" ] && [ -n "$out" ] || usage
		if [ ! -d "$repo" ]; then
			echo "ERROR: repo not found: $repo" >&2
			exit 2
		fi
		mkdir -p "$(dirname "$out")"
		python3 - "$repo" "$out" "$profile" <<'PY'
import gzip
import json
import os
import sys

repo, out, requested_profile = sys.argv[1], sys.argv[2], sys.argv[3]

plugin_assets = {
    "settings-main.js": "dist/settings.js",
    "settings-main.css": "dist/settings.css",
    "classic-card.js": "dist/checkout.js",
    "classic-card.css": "dist/checkout.css",
    "blocks-card.js": "dist/blocks-checkout.js",
    "blocks-card.css": "dist/blocks-checkout.css",
    "express-checkout.js": "dist/express-checkout.js",
    "express-checkout.css": "dist/express-checkout.css",
    "blocks-express-checkout.js": None,
    "blocks-express-checkout.css": None,
    "woopay.js": "dist/woopay.js",
    "woopay.css": "dist/woopay.css",
    "woopay-express-button.js": "dist/woopay-express-button.js",
    "woopay-direct-checkout.js": "dist/woopay-direct-checkout.js",
    "multi-currency-admin.js": "dist/multi-currency.js",
    "multi-currency-admin.css": "dist/multi-currency.css",
    "multi-currency-analytics.js": "dist/multi-currency-analytics.js",
    "multi-currency-switcher-block.js": "dist/multi-currency-switcher-block.js",
    "multi-currency-frontend.js": "dist/multi-currency-async-renderer.js",
    "multi-currency-frontend.css": "dist/multi-currency-async-renderer.css",
    "multi-currency-setup.js": "dist/wcpay-multi-currency-setup.js",
    "multi-currency-setup.css": "dist/wcpay-multi-currency-setup.css",
}

core_assets = {
    "settings-main.js": "plugins/woocommerce/assets/client/admin/chunks/settings-payments-main.js",
    "settings-main.css": None,
    "classic-card.js": "plugins/woocommerce/assets/js/frontend/woopayments-checkout.js",
    "classic-card.css": "plugins/woocommerce/assets/css/woopayments-checkout.css",
    "blocks-card.js": "plugins/woocommerce/assets/client/blocks/wc-payment-method-woopayments.js",
    "blocks-card.css": "plugins/woocommerce/assets/client/blocks/wc-payment-method-woopayments.css",
    "express-checkout.js": "plugins/woocommerce/assets/js/frontend/woopayments-express-checkout.js",
    "express-checkout.css": "plugins/woocommerce/assets/css/woopayments-express-checkout.css",
    "blocks-express-checkout.js": "plugins/woocommerce/assets/client/blocks/wc-payment-method-woopayments-express-checkout.js",
    "blocks-express-checkout.css": "plugins/woocommerce/assets/client/blocks/wc-payment-method-woopayments-express-checkout.css",
    "woopay.js": "plugins/woocommerce/assets/client/blocks/wc-payment-method-woopayments-woopay.js",
    "woopay.css": "plugins/woocommerce/assets/css/woopayments-woopay.css",
    "woopay-express-button.js": "plugins/woocommerce/assets/js/frontend/woopayments-woopay.js",
    "woopay-direct-checkout.js": "plugins/woocommerce/assets/js/frontend/woopayments-woopay.js",
    "multi-currency-admin.js": "plugins/woocommerce/assets/client/admin/wp-admin-scripts/multi-currency-settings.js",
    "multi-currency-admin.css": "plugins/woocommerce/assets/client/admin/multi-currency-settings/style.css",
    "multi-currency-analytics.js": None,
    "multi-currency-switcher-block.js": None,
    "multi-currency-frontend.js": "plugins/woocommerce/assets/js/frontend/multi-currency-async-renderer.js",
    "multi-currency-frontend.css": "plugins/woocommerce/assets/css/multi-currency-async-renderer.css",
    "multi-currency-setup.js": None,
    "multi-currency-setup.css": None,
}

def detect_profile(repo_path: str, requested: str) -> str:
    if requested in ("wcpay-plugin", "wc-core"):
        return requested
    if requested != "auto":
        raise SystemExit(f"ERROR: unknown profile {requested!r}")
    if os.path.isdir(os.path.join(repo_path, "plugins", "woocommerce")):
        return "wc-core"
    if os.path.isdir(os.path.join(repo_path, "dist")):
        return "wcpay-plugin"
    raise SystemExit("ERROR: could not auto-detect repo profile; pass --profile")

profile = detect_profile(repo, requested_profile)
asset_map = plugin_assets if profile == "wcpay-plugin" else core_assets

result = {
    "schema": "woopayments_measured_gate.v1",
    "mode": "bundle",
    "repo": os.path.abspath(repo),
    "profile": profile,
    "assets": {},
}
for logical_name, rel in sorted(asset_map.items()):
    if rel is None:
        result["assets"][logical_name] = {"status": "missing", "path": None}
        continue
    path = os.path.join(repo, rel)
    if not os.path.isfile(path):
        result["assets"][logical_name] = {"status": "missing", "path": rel}
        continue
    with open(path, "rb") as fh:
        data = fh.read()
    result["assets"][logical_name] = {
        "status": "present",
        "path": rel,
        "raw_bytes": len(data),
        "gzip_bytes": len(gzip.compress(data, compresslevel=9)),
    }

with open(out, "w", encoding="utf-8") as fh:
    json.dump(result, fh, indent=2, sort_keys=True)
    fh.write("\n")
print(f"Captured bundle sizes to {out}")
PY
		;;
	compare)
		ref=""
		target=""
		budget=""
		while [ "$#" -gt 0 ]; do
			case "$1" in
				--ref)
					ref="${2:-}"; shift 2 ;;
				--target)
					target="${2:-}"; shift 2 ;;
				--budget|--allowlist)
					budget="${2:-}"; shift 2 ;;
				*)
					usage ;;
			esac
		done
		[ -n "$ref" ] && [ -n "$target" ] || usage
		if [ -n "$budget" ]; then
			python3 "$COMPARE" bundle --ref "$ref" --target "$target" --budget "$budget"
		else
			python3 "$COMPARE" bundle --ref "$ref" --target "$target"
		fi
		;;
	*)
		usage ;;
esac
