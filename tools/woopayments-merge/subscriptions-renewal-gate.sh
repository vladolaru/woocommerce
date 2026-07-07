#!/usr/bin/env bash
#
# Bucket-C WC Subscriptions renewal conformance gate scaffold.
#
# Local-only harness for comparing WooPayments renewal behavior between the
# reference plugin store and the native target store. It intentionally does not
# create subscriptions: full conformance needs browser-created subscriptions so
# tokenization, customer IDs, setup_future_usage, order meta, and email behavior
# are real. Pass explicit subscription IDs created in each store.
#
# Examples:
#   tools/woopayments-merge/subscriptions-renewal-gate.sh preflight \
#     --ref "docker exec -i wcpay_wp_default wp --allow-root" \
#     --target "docker exec -i <target-cli-container> wp --allow-root --user=1"
#
#   tools/woopayments-merge/subscriptions-renewal-gate.sh compare \
#     --ref "docker exec -i wcpay_wp_default wp --allow-root" \
#     --target "docker exec -i <target-cli-container> wp --allow-root --user=1" \
#     --ref-subscription-id 123 \
#     --target-subscription-id 456

set -uo pipefail

SELF_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
DRIVER="$SELF_DIR/subscriptions-renewal-drive.php"

MODE=""
case "${1:-}" in
	preflight|--preflight)
		MODE="preflight"
		shift
		;;
	compare|drive|--compare|--drive)
		MODE="compare"
		shift
		;;
esac

REF_WP=""
TARGET_WP=""
REF_SUBSCRIPTION_ID=""
TARGET_SUBSCRIPTION_ID=""

usage() {
	cat >&2 <<'USAGE'
usage:
  subscriptions-renewal-gate.sh preflight --ref "<ref wp>" --target "<target wp>"
  subscriptions-renewal-gate.sh compare --ref "<ref wp>" --target "<target wp>" --ref-subscription-id <id> --target-subscription-id <id>

The compare mode requires explicit browser-created subscription IDs. This
scaffold does not seed subscriptions through CLI because that would bypass the
real checkout tokenization and email path this gate is meant to verify.
USAGE
}

while [ "$#" -gt 0 ]; do
	case "$1" in
		--ref=*) REF_WP="${1#--ref=}"; shift ;;
		--ref) REF_WP="${2:-}"; shift 2 ;;
		--target=*) TARGET_WP="${1#--target=}"; shift ;;
		--target) TARGET_WP="${2:-}"; shift 2 ;;
		--ref-subscription-id=*) REF_SUBSCRIPTION_ID="${1#--ref-subscription-id=}"; shift ;;
		--ref-subscription-id) REF_SUBSCRIPTION_ID="${2:-}"; shift 2 ;;
		--target-subscription-id=*) TARGET_SUBSCRIPTION_ID="${1#--target-subscription-id=}"; shift ;;
		--target-subscription-id) TARGET_SUBSCRIPTION_ID="${2:-}"; shift 2 ;;
		--help|-h) usage; exit 0 ;;
		*) echo "Unknown argument: $1" >&2; usage; exit 2 ;;
	esac
done

if [ -z "$MODE" ]; then
	MODE="compare"
fi

if [ ! -f "$DRIVER" ]; then
	echo "FAIL: PHP driver not found at $DRIVER" >&2
	exit 2
fi

if [ -z "$REF_WP" ] || [ -z "$TARGET_WP" ]; then
	echo "FAIL: both --ref and --target WP commands are required." >&2
	usage
	exit 2
fi

if [ "$MODE" = "compare" ]; then
	if [ -z "$REF_SUBSCRIPTION_ID" ] || [ -z "$TARGET_SUBSCRIPTION_ID" ]; then
		echo "FAIL: browser-created subscription IDs are required for compare mode." >&2
		echo "      Pass --ref-subscription-id and --target-subscription-id after creating equivalent WooPayments subscriptions in both stores." >&2
		exit 2
	fi

	case "$REF_SUBSCRIPTION_ID:$TARGET_SUBSCRIPTION_ID" in
		*[!0-9:]*|:*|*:)
			echo "FAIL: subscription IDs must be positive integers." >&2
			exit 2
			;;
	esac
fi

run_eval() {
	local wp_cmd="$1"
	local role="$2"
	local out_file="$3"
	shift 3
	local raw rc json

	# Intentionally split the WP runner string, matching the rest of this
	# harness's WP="docker exec ..." convention.
	# shellcheck disable=SC2086
	raw="$($wp_cmd eval-file - "$@" < "$DRIVER" 2>&1)"
	rc=$?
	json="$(printf '%s\n' "$raw" | grep -E '^\{' | tail -1)"

	if [ -z "$json" ]; then
		echo "FAIL ($role): driver produced no JSON output." >&2
		printf '%s\n' "$raw" | tail -20 >&2
		return 2
	fi

	printf '%s\n' "$json" > "$out_file"

	if [ "$rc" -ne 0 ]; then
		echo "FAIL ($role): driver exited $rc." >&2
		php "$DRIVER" normalize "$out_file" 2>/dev/null || printf '%s\n' "$json" >&2
		return "$rc"
	fi

	return 0
}

json_success() {
	php -r '
		$payload = json_decode(file_get_contents($argv[1]), true);
		exit(is_array($payload) && !empty($payload["success"]) ? 0 : 1);
	' "$1"
}

print_errors() {
	php -r '
		$payload = json_decode(file_get_contents($argv[1]), true);
		if (!is_array($payload)) { exit(0); }
		foreach (($payload["errors"] ?? array()) as $error) {
			fwrite(STDERR, "  - " . $error . PHP_EOL);
		}
	' "$1"
}

normalize_file() {
	php "$DRIVER" normalize "$1"
}

tmp_root="${TMPDIR:-}"
if [ -z "$tmp_root" ]; then
	echo "FAIL: TMPDIR is not set; refusing to write temp files outside the configured temp directory." >&2
	exit 2
fi
mkdir -p "$tmp_root"
work_dir="$(mktemp -d "$tmp_root/woopayments-subscriptions-renewal.XXXXXX")"
trap 'rm -rf "$work_dir"' EXIT

ref_preflight="$work_dir/ref-preflight.json"
target_preflight="$work_dir/target-preflight.json"

echo "Bucket-C WC Subscriptions renewal gate"
echo "  mode: $MODE"
echo

echo "Preflight: reference"
if ! run_eval "$REF_WP" "ref preflight" "$ref_preflight" preflight ref; then
	print_errors "$ref_preflight"
	exit 1
fi
if ! json_success "$ref_preflight"; then
	echo "FAIL: reference preflight did not pass." >&2
	print_errors "$ref_preflight"
	exit 1
fi
echo "  ok"

echo "Preflight: target"
if ! run_eval "$TARGET_WP" "target preflight" "$target_preflight" preflight target; then
	print_errors "$target_preflight"
	exit 1
fi
if ! json_success "$target_preflight"; then
	echo "FAIL: target preflight did not pass." >&2
	print_errors "$target_preflight"
	exit 1
fi
echo "  ok"

if [ "$MODE" = "preflight" ]; then
	echo
	echo "PASS: WC Subscriptions renewal preflight passed on reference and target."
	exit 0
fi

if [ "$MODE" != "compare" ]; then
	echo "Unknown mode: $MODE" >&2
	usage
	exit 2
fi

ref_drive="$work_dir/ref-drive.json"
target_drive="$work_dir/target-drive.json"
ref_norm="$work_dir/ref-normalized.json"
target_norm="$work_dir/target-normalized.json"

echo
echo "Drive renewal: reference subscription $REF_SUBSCRIPTION_ID"
if ! run_eval "$REF_WP" "ref drive" "$ref_drive" drive "$REF_SUBSCRIPTION_ID"; then
	print_errors "$ref_drive"
	exit 1
fi
if ! json_success "$ref_drive"; then
	echo "FAIL: reference renewal drive did not pass." >&2
	print_errors "$ref_drive"
	exit 1
fi
echo "  ok"

echo "Drive renewal: target subscription $TARGET_SUBSCRIPTION_ID"
if ! run_eval "$TARGET_WP" "target drive" "$target_drive" drive "$TARGET_SUBSCRIPTION_ID"; then
	print_errors "$target_drive"
	exit 1
fi
if ! json_success "$target_drive"; then
	echo "FAIL: target renewal drive did not pass." >&2
	print_errors "$target_drive"
	exit 1
fi
echo "  ok"

normalize_file "$ref_drive" > "$ref_norm"
normalize_file "$target_drive" > "$target_norm"

echo
echo "Compare normalized renewal facts"
if ! diff -u "$ref_norm" "$target_norm"; then
	echo "FAIL: normalized WC Subscriptions renewal facts differ." >&2
	echo "      Raw facts retained until process exit under $work_dir" >&2
	exit 1
fi

echo "PASS: WC Subscriptions renewal facts match reference."
exit 0
