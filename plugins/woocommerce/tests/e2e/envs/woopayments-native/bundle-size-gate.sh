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
    "frontend-tracks.js": "dist/frontend-tracks.js",
    "blocks-express-checkout.js": None,
    "blocks-express-checkout.css": None,
    "woopay.js": "dist/woopay.js",
    "woopay.css": "dist/woopay.css",
    "woopay-express-button.js": "dist/woopay-express-button.js",
    "woopay-direct-checkout.js": "dist/woopay-direct-checkout.js",
    "blocks-common.js": None,
    "blocks-woopay-common.js": None,
    "blocks-woopay.css": None,
    "woopay-phone-validation.js": None,
    "product-details.js": "dist/product-details.js",
    "product-details.css": "dist/product-details.css",
    "cart-block.js": "dist/cart-block.js",
    "cart-block.css": "dist/cart-block.css",
    "success.js": "dist/success.js",
    "success.css": "assets/css/success.css",
    "page-blocks-checkout.js": ["dist/blocks-checkout.js", "dist/woopay.js", "dist/woopay-express-button.js", "dist/express-checkout.js"],
    "page-blocks-cart.js": ["dist/blocks-checkout.js", "dist/woopay-express-button.js", "dist/express-checkout.js", "dist/cart.js", "dist/cart-block.js", "dist/product-details.js"],
    "multi-currency-admin.js": "dist/multi-currency.js",
    "multi-currency-admin.css": "dist/multi-currency.css",
    "multi-currency-analytics.js": "dist/multi-currency-analytics.js",
    "multi-currency-switcher-block.js": "dist/multi-currency-switcher-block.js",
    "multi-currency-frontend.js": "dist/multi-currency-async-renderer.js",
    "multi-currency-frontend.css": "dist/multi-currency-async-renderer.css",
    "multi-currency-setup.js": "dist/wcpay-multi-currency-setup.js",
    "multi-currency-setup.css": "dist/wcpay-multi-currency-setup.css",
    "admin-shared.css": "dist/index.css",
    "admin-overview.js": "dist/chunks/wcpay-overview.js",
    "admin-money-movement.js": "dist/chunks/wcpay-money-movement.js",
    "admin-payouts.js": "dist/chunks/wcpay-payouts.js",
    "admin-documents.js": "dist/chunks/wcpay-documents.js",
    "admin-fraud-protection.js": "dist/chunks/wcpay-fraud-protection.js",
    "admin-capital.js": "dist/chunks/wcpay-capital.js",
    "admin-card-readers.js": "dist/chunks/wcpay-card-readers.js",
}

# Classic scripts are the .min.js files stores serve (the client's dist/ is minified too). The client
# bundles FingerprintJS into its card scripts, so native's separate copy is counted with each card script.
# Admin route chunks pair with the client's dist/chunks/wcpay-<route>.js; the client's shared app shell
# (dist/index.js) has no native counterpart, because core's settings page hosts the native routes.
CORE_ADMIN_CHUNKS = "plugins/woocommerce/assets/client/admin/chunks/"
FINGERPRINTJS = "plugins/woocommerce/assets/js/fingerprintjs/fp.umd.min.js"
# The client bundles its fraud-scripts loader into its Blocks card bundle; native ships it as its own handle.
BLOCKS_FRAUD_SCRIPTS = "plugins/woocommerce/assets/client/blocks/wc-payment-method-woopayments-fraud-scripts.js"
# The client bundles its appearance code into its classic checkout script; native ships it as the card script's dependency.
CLASSIC_APPEARANCE = "plugins/woocommerce/assets/js/frontend/utils/woopayments-appearance.min.js"
CORE_BLOCKS = "plugins/woocommerce/assets/client/blocks/"
# Code the Blocks card, WooPay and express scripts share ships once, in two chunks those scripts depend on. Each
# chunk is its own asset, the per-script assets leave it out, and the page assets count every file once.
BLOCKS_CARD = CORE_BLOCKS + "wc-payment-method-woopayments.js"
BLOCKS_WOOPAY = CORE_BLOCKS + "wc-payment-method-woopayments-woopay.js"
BLOCKS_EXPRESS = CORE_BLOCKS + "wc-payment-method-woopayments-express-checkout.js"
BLOCKS_COMMON = CORE_BLOCKS + "wc-payment-method-woopayments-common.js"
BLOCKS_WOOPAY_COMMON = CORE_BLOCKS + "wc-payment-method-woopayments-woopay-common.js"
MESSAGING = "plugins/woocommerce/assets/js/frontend/woopayments-payment-method-messaging.min.js"
CART_BLOCK_MESSAGING = CORE_BLOCKS + "wc-woopayments-cart-block-payment-method-messaging.js"
core_assets = {
    "settings-main.js": CORE_ADMIN_CHUNKS + "settings-payments-woopayments.js",
    "settings-main.css": CORE_ADMIN_CHUNKS + "settings-payments-woopayments.style.css",
    "classic-card.js": ["plugins/woocommerce/assets/js/frontend/woopayments-checkout.min.js", FINGERPRINTJS, CLASSIC_APPEARANCE],
    "classic-card.css": "plugins/woocommerce/assets/css/woopayments-checkout.css",
    "blocks-card.js": [BLOCKS_CARD, FINGERPRINTJS, BLOCKS_FRAUD_SCRIPTS],
    "blocks-card.css": "plugins/woocommerce/assets/client/blocks/wc-payment-method-woopayments.css",
    "express-checkout.js": "plugins/woocommerce/assets/js/frontend/woopayments-express-checkout.min.js",
    "express-checkout.css": "plugins/woocommerce/assets/css/woopayments-express-checkout.css",
    "frontend-tracks.js": "plugins/woocommerce/assets/js/frontend/woopayments-frontend-tracks.min.js",
    "blocks-express-checkout.js": BLOCKS_EXPRESS,
    "blocks-express-checkout.css": "plugins/woocommerce/assets/client/blocks/wc-payment-method-woopayments-express-checkout.css",
    "woopay.js": BLOCKS_WOOPAY,
    # The client's woopay.css holds its save-user styles for both checkouts; native keeps the classic save-user and
    # WooPay button styles in this stylesheet, the Blocks save-user styles in blocks-card.css and the Blocks WooPay
    # button styles in blocks-woopay.css (the client's are in its blocks-checkout.css).
    "woopay.css": "plugins/woocommerce/assets/css/woopayments-woopay.css",
    "woopay-express-button.js": "plugins/woocommerce/assets/js/frontend/woopayments-woopay.min.js",
    "woopay-direct-checkout.js": "plugins/woocommerce/assets/js/frontend/woopayments-woopay.min.js",
    "blocks-common.js": BLOCKS_COMMON,
    "blocks-woopay-common.js": BLOCKS_WOOPAY_COMMON,
    "blocks-woopay.css": CORE_BLOCKS + "wc-payment-method-woopayments-woopay.css",
    # Loaded only once the shopper opts in to saving their details with WooPay.
    "woopay-phone-validation.js": CORE_BLOCKS + "wc-woopayments-phone-validation.js",
    "product-details.js": MESSAGING,
    "product-details.css": "plugins/woocommerce/assets/css/woopayments-payment-method-messaging.css",
    "cart-block.js": CART_BLOCK_MESSAGING,
    "cart-block.css": CORE_BLOCKS + "wc-woopayments-cart-block-payment-method-messaging.css",
    "success.js": "plugins/woocommerce/assets/js/frontend/woopayments-order-success.min.js",
    "success.css": "plugins/woocommerce/assets/css/woopayments-order-success.css",
    # Every WooPayments script each side loads on first view of the Blocks checkout and the Blocks cart, each file
    # counted once (WordPress, WooCommerce and Stripe scripts aside), with WooPay and Apple Pay/Google Pay buttons on,
    # payment method messaging on, WooPay direct checkout off, shopper tracking off, and before the shopper opts in to
    # saving their details. The client also loads its express-checkout.js on both pages, and its cart.js and its
    # product-details.js messaging on the cart; native's messaging on the cart is its Cart-block script.
    "page-blocks-checkout.js": [BLOCKS_CARD, BLOCKS_WOOPAY, BLOCKS_EXPRESS, BLOCKS_COMMON, BLOCKS_WOOPAY_COMMON, FINGERPRINTJS, BLOCKS_FRAUD_SCRIPTS],
    "page-blocks-cart.js": [BLOCKS_WOOPAY, BLOCKS_EXPRESS, BLOCKS_COMMON, BLOCKS_WOOPAY_COMMON, BLOCKS_FRAUD_SCRIPTS, CART_BLOCK_MESSAGING],
    "multi-currency-admin.js": "plugins/woocommerce/assets/client/admin/wp-admin-scripts/multi-currency-settings.js",
    "multi-currency-admin.css": "plugins/woocommerce/assets/client/admin/multi-currency-settings/style.css",
    "multi-currency-analytics.js": None,
    "multi-currency-switcher-block.js": None,
    "multi-currency-frontend.js": "plugins/woocommerce/assets/js/frontend/multi-currency-async-renderer.min.js",
    "multi-currency-frontend.css": "plugins/woocommerce/assets/css/multi-currency-async-renderer.css",
    "multi-currency-setup.js": None,
    "multi-currency-setup.css": None,
    "admin-shared.css": CORE_ADMIN_CHUNKS + "settings-payments-woopayments-shared.style.css",
    "admin-overview.js": CORE_ADMIN_CHUNKS + "settings-payments-woopayments-overview.js",
    "admin-money-movement.js": CORE_ADMIN_CHUNKS + "settings-payments-woopayments-money-movement.js",
    "admin-payouts.js": CORE_ADMIN_CHUNKS + "settings-payments-woopayments-payouts.js",
    "admin-documents.js": CORE_ADMIN_CHUNKS + "settings-payments-woopayments-documents.js",
    "admin-fraud-protection.js": CORE_ADMIN_CHUNKS + "settings-payments-woopayments-fraud-protection-settings.js",
    "admin-capital.js": CORE_ADMIN_CHUNKS + "settings-payments-woopayments-capital.js",
    "admin-card-readers.js": CORE_ADMIN_CHUNKS + "settings-payments-woopayments-card-readers.js",
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
    # A list is one logical asset made of several files a page loads together; each file is
    # compressed on its own, as it travels.
    rels = rel if isinstance(rel, list) else [rel]
    paths = [os.path.join(repo, item) for item in rels]
    if not all(os.path.isfile(path) for path in paths):
        result["assets"][logical_name] = {"status": "missing", "path": rel}
        continue
    raw_bytes = 0
    gzip_bytes = 0
    for path in paths:
        with open(path, "rb") as fh:
            data = fh.read()
        raw_bytes += len(data)
        gzip_bytes += len(gzip.compress(data, compresslevel=9))
    result["assets"][logical_name] = {
        "status": "present",
        "path": rel,
        "raw_bytes": raw_bytes,
        "gzip_bytes": gzip_bytes,
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
