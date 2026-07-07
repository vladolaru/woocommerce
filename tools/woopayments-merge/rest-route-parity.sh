#!/usr/bin/env bash
#
# WooPayments REST route parity gate.
#
# Captures the reference WooPayments /wc/v3/payments REST route table and checks
# that the target native runtime registers the same methods/routes unless a row in
# rest-route-exceptions.txt explicitly signs off a superseded or dropped surface.

set -uo pipefail

SELF_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"

REF_WP=""
TARGET_WP=""
REF_STATE=""
TARGET_STATE=""
EXCEPTIONS="$SELF_DIR/rest-route-exceptions.txt"
OUT_DIR="${TMPDIR:-$SELF_DIR/.tmp}/rest-route-parity"
PRINT_PLAN=0
ROUTE_PREFIX="/wc/v3/payments/"

usage() {
	cat >&2 <<'USAGE'
usage:
  rest-route-parity.sh --ref "<ref wp>" --target "<target wp>" [options]
  rest-route-parity.sh --ref-state <file> --target-state <file> [options]

Options:
  --ref "<wp>"              Reference store WP-CLI command.
  --target "<wp>"           Target store WP-CLI command.
  --ref-state <file>        Pre-captured reference JSON route snapshot.
  --target-state <file>     Pre-captured target JSON route snapshot.
  --exceptions <file>       Tab-separated route exception manifest.
  --out-dir <path>          Evidence output directory.
  --print-plan              Print the live route probe plan as JSON, then exit.
  -h, --help                Show this help.
USAGE
}

usage_error() {
	printf 'FAIL: %s\n' "$*" >&2
	usage
	exit 2
}

blocked() {
	printf 'BLOCKED: %s\n' "$*" >&2
	exit 3
}

while [ "$#" -gt 0 ]; do
	case "$1" in
		--ref=*) REF_WP="${1#--ref=}"; shift ;;
		--ref) REF_WP="${2:-}"; shift 2 ;;
		--target=*) TARGET_WP="${1#--target=}"; shift ;;
		--target) TARGET_WP="${2:-}"; shift 2 ;;
		--ref-state=*) REF_STATE="${1#--ref-state=}"; shift ;;
		--ref-state) REF_STATE="${2:-}"; shift 2 ;;
		--target-state=*) TARGET_STATE="${1#--target-state=}"; shift ;;
		--target-state) TARGET_STATE="${2:-}"; shift 2 ;;
		--exceptions=*) EXCEPTIONS="${1#--exceptions=}"; shift ;;
		--exceptions) EXCEPTIONS="${2:-}"; shift 2 ;;
		--out-dir=*) OUT_DIR="${1#--out-dir=}"; shift ;;
		--out-dir) OUT_DIR="${2:-}"; shift 2 ;;
		--print-plan) PRINT_PLAN=1; shift ;;
		--help|-h) usage; exit 0 ;;
		*) usage_error "unknown argument: $1" ;;
	esac
done

print_plan() {
	python3 - "$REF_WP" "$TARGET_WP" "$OUT_DIR" "$EXCEPTIONS" "$ROUTE_PREFIX" <<'PY'
import json
import sys

ref_wp, target_wp, out_dir, exceptions, route_prefix = sys.argv[1:]
print(
    json.dumps(
        {
            "schema": "woopayments_rest_route_gate_plan.v1",
            "ref_wp": ref_wp,
            "target_wp": target_wp,
            "out_dir": out_dir,
            "exceptions": exceptions,
            "route_prefix": route_prefix,
        },
        sort_keys=True,
    )
)
PY
}

if [ "$PRINT_PLAN" -eq 1 ]; then
	if [ -z "$REF_WP" ] || [ -z "$TARGET_WP" ]; then
		usage_error "--ref and --target are required with --print-plan."
	fi
	print_plan
	exit 0
fi

if [ -n "$REF_STATE" ] || [ -n "$TARGET_STATE" ]; then
	if [ -z "$REF_STATE" ] || [ -z "$TARGET_STATE" ]; then
		usage_error "--ref-state and --target-state must be provided together."
	fi
	if [ ! -f "$REF_STATE" ]; then
		usage_error "reference state not found: $REF_STATE"
	fi
	if [ ! -f "$TARGET_STATE" ]; then
		usage_error "target state not found: $TARGET_STATE"
	fi
else
	if [ -z "$REF_WP" ] || [ -z "$TARGET_WP" ]; then
		usage_error "provide --ref/--target or --ref-state/--target-state."
	fi
fi

if [ ! -f "$EXCEPTIONS" ]; then
	usage_error "exceptions manifest not found: $EXCEPTIONS"
fi
if ! command -v python3 >/dev/null 2>&1; then
	blocked "python3 is required."
fi

mkdir -p "$OUT_DIR"

last_json_line() {
	grep -E '^\{' | tail -1
}

capture_state() {
	local role="$1"
	local wp_cmd="$2"
	local output_file="$3"
	local raw rc json

	printf 'REST route parity gate: capturing %s store...\n' "$role" >&2
	# Intentionally split the WP runner string, matching this harness's WP="docker exec ..." convention.
	# shellcheck disable=SC2086
	raw="$($wp_cmd eval-file - "$role" <<'PHP' 2>&1
<?php
$role = isset( $args[0] ) ? (string) $args[0] : 'unknown';

if ( ! function_exists( 'rest_get_server' ) ) {
	require_once ABSPATH . WPINC . '/rest-api.php';
}

$server = rest_get_server();
$routes = $server->get_routes();
$captured = array();

foreach ( $routes as $route => $handlers ) {
	if ( '/wc/v3/payments' !== $route && 0 !== strpos( $route, '/wc/v3/payments/' ) ) {
		continue;
	}

	foreach ( (array) $handlers as $handler ) {
		if ( ! is_array( $handler ) || empty( $handler['methods'] ) ) {
			continue;
		}

		$methods = $handler['methods'];
		if ( is_string( $methods ) ) {
			$methods = array_map( 'trim', explode( ',', $methods ) );
		}

		foreach ( (array) $methods as $method => $enabled ) {
			if ( is_int( $method ) ) {
				$method = $enabled;
				$enabled = true;
			}

			if ( ! $enabled ) {
				continue;
			}

			$method = strtoupper( (string) $method );
			if ( in_array( $method, array( 'HEAD', 'OPTIONS' ), true ) ) {
				continue;
			}

			$captured[] = array(
				'method' => $method,
				'route'  => $route,
			);
		}
	}
}

usort(
	$captured,
	static function ( $a, $b ) {
		return strcmp( $a['method'] . ' ' . $a['route'], $b['method'] . ' ' . $b['route'] );
	}
);

echo json_encode(
	array(
		'schema' => 'woopayments_rest_route_capture.v1',
		'role'   => $role,
		'routes' => $captured,
	)
);
echo "\n";
PHP
)"
	rc=$?
	json="$(printf '%s\n' "$raw" | last_json_line)"
	if [ "$rc" -ne 0 ] || [ -z "$json" ]; then
		printf 'BLOCKED: %s REST route capture failed.\n' "$role" >&2
		printf '%s\n' "$raw" | tail -40 >&2
		exit 3
	fi

	printf '%s\n' "$json" > "$output_file"
}

if [ -z "$REF_STATE" ]; then
	REF_STATE="$OUT_DIR/reference-rest-routes.json"
	TARGET_STATE="$OUT_DIR/target-rest-routes.json"
	capture_state "reference" "$REF_WP" "$REF_STATE"
	capture_state "target" "$TARGET_WP" "$TARGET_STATE"
fi

python3 - "$REF_STATE" "$TARGET_STATE" "$EXCEPTIONS" "$OUT_DIR/rest-route-parity.json" <<'PY'
from __future__ import annotations

import json
import re
import sys
from dataclasses import dataclass
from pathlib import Path


VALID_DISPOSITIONS = {"SUPERSEDED", "DROPPED"}
IGNORED_METHODS = {"HEAD", "OPTIONS"}


@dataclass(frozen=True)
class RouteKey:
    method: str
    route: str

    def label(self) -> str:
        return f"{self.method} {self.route}"


@dataclass(frozen=True)
class ExceptionRow:
    key: RouteKey
    disposition: str
    native_successor: str
    signoff: str
    reason: str


def load_json(path: Path) -> dict:
    with path.open(encoding="utf-8") as stream:
        return json.load(stream)


def normalize_route(route: object) -> str:
    normalized = str(route).strip()
    normalized = normalized.replace("\\/", "/")
    normalized = re.sub(r"\(\?P<([^>]+)>[^)]*\)", r"{\1}", normalized)
    normalized = re.sub(r"/+", "/", normalized)
    if len(normalized) > 1:
        normalized = normalized.rstrip("/")
    return normalized


def route_key(route: dict) -> RouteKey | None:
    method = str(route.get("method", "")).strip().upper()
    if not method or method in IGNORED_METHODS:
        return None

    normalized_route = normalize_route(route.get("route", ""))
    if not normalized_route:
        return None

    return RouteKey(method=method, route=normalized_route)


def load_routes(payload: dict) -> set[RouteKey]:
    routes: set[RouteKey] = set()
    for route in payload.get("routes", []):
        if not isinstance(route, dict):
            continue
        key = route_key(route)
        if key is not None:
            routes.add(key)
    return routes


def parse_exceptions(path: Path) -> tuple[dict[RouteKey, ExceptionRow], list[str]]:
    exceptions: dict[RouteKey, ExceptionRow] = {}
    failures: list[str] = []

    for line_number, line in enumerate(path.read_text(encoding="utf-8").splitlines(), start=1):
        stripped = line.strip()
        if not stripped or stripped.startswith("#"):
            continue

        columns = line.split("\t")
        if len(columns) != 6:
            failures.append(f"exception row must contain 6 tab-separated fields: {path}:{line_number}")
            continue

        method, legacy_route, disposition, native_successor, signoff, reason = [column.strip() for column in columns]
        key = RouteKey(method=method.upper(), route=normalize_route(legacy_route))
        disposition = disposition.upper()
        row = ExceptionRow(
            key=key,
            disposition=disposition,
            native_successor=native_successor,
            signoff=signoff,
            reason=reason,
        )
        exceptions[key] = row

        if disposition not in VALID_DISPOSITIONS:
            failures.append(f"invalid exception disposition: {row.key.label()} {disposition}")
        if not signoff or not reason:
            failures.append(f"exception missing signoff/reason: {row.key.label()}")
        if disposition == "SUPERSEDED" and not native_successor:
            failures.append(f"superseded exception missing native successor: {row.key.label()}")

    return exceptions, failures


def write_rollup(
    path: Path,
    *,
    status: str,
    failures: list[str],
    exceptions_applied: list[str],
    reference: dict,
    target: dict,
    exception_rows: list[str],
) -> None:
    path.parent.mkdir(parents=True, exist_ok=True)
    path.write_text(
        json.dumps(
            {
                "schema": "woopayments_rest_route_gate_result.v1",
                "status": status,
                "failures": failures,
                "exceptions_applied": exceptions_applied,
                "reference": reference,
                "target": target,
                "exceptions": exception_rows,
            },
            sort_keys=True,
            indent=2,
        )
        + "\n",
        encoding="utf-8",
    )


ref_path = Path(sys.argv[1])
target_path = Path(sys.argv[2])
exceptions_path = Path(sys.argv[3])
rollup_path = Path(sys.argv[4])

reference = load_json(ref_path)
target = load_json(target_path)
ref_routes = load_routes(reference)
target_routes = load_routes(target)
exceptions, failures = parse_exceptions(exceptions_path)
exceptions_applied: list[str] = []

for key in sorted(exceptions, key=lambda route: route.label()):
    if key not in ref_routes:
        failures.append(f"stale exception route: {key.label()}")

for key in sorted(ref_routes - target_routes, key=lambda route: route.label()):
    if key in exceptions:
        exceptions_applied.append(key.label())
        continue
    failures.append(f"missing target route: {key.label()}")

exception_rows = [
    row.key.label()
    for row in sorted(exceptions.values(), key=lambda exception: exception.key.label())
]

if failures:
    write_rollup(
        rollup_path,
        status="fail",
        failures=failures,
        exceptions_applied=exceptions_applied,
        reference=reference,
        target=target,
        exception_rows=exception_rows,
    )
    for failure in failures:
        print(failure, file=sys.stderr)
    sys.exit(1)

write_rollup(
    rollup_path,
    status="pass",
    failures=[],
    exceptions_applied=exceptions_applied,
    reference=reference,
    target=target,
    exception_rows=exception_rows,
)
print("PASS: native WooPayments REST routes cover the reference route table.")
PY
