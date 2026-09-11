#!/usr/bin/env bash
#
# WooPayments REST route parity gate.
#
# Captures the reference WooPayments /wc/v3/payments REST route table and checks
# that the target native runtime registers the same methods/routes unless a row in
# rest-route-exceptions.txt explicitly signs off a superseded or dropped surface.
# It also compares redacted response shapes for the mobile IPP bootstrap routes.

set -uo pipefail

SELF_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
LOCAL_RUNNER_SAFETY="$SELF_DIR/local-runner-safety.sh"

REF_WP=""
TARGET_WP=""
REF_STATE=""
TARGET_STATE=""
EXCEPTIONS="$SELF_DIR/rest-route-exceptions.txt"
OUT_DIR="${TMPDIR:-$SELF_DIR/.tmp}/rest-route-parity"
PRINT_PLAN=0
SELF_CHECK=0
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
  --self-check              Compare the plugin-owned reference store with itself.
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
		--self-check) SELF_CHECK=1; shift ;;
		--print-plan) PRINT_PLAN=1; shift ;;
		--help|-h) usage; exit 0 ;;
		*) usage_error "unknown argument: $1" ;;
	esac
done

if [ ! -f "$LOCAL_RUNNER_SAFETY" ]; then
	blocked "local runner safety library is missing: $LOCAL_RUNNER_SAFETY"
fi
# shellcheck source=./local-runner-safety.sh
source "$LOCAL_RUNNER_SAFETY"

validate_wp_runner() {
	local role="$1"
	local runner="$2"
	local reason

	if ! reason="$(woopayments_validate_local_wp_runner "$runner" 2>&1)"; then
		usage_error "unsafe --$role WP runner: ${reason:-runner validation failed}"
	fi
}

print_plan() {
	python3 - "$REF_WP" "$TARGET_WP" "$OUT_DIR" "$EXCEPTIONS" "$ROUTE_PREFIX" "$SELF_CHECK" <<'PY'
import json
import sys

ref_wp, target_wp, out_dir, exceptions, route_prefix, self_check = sys.argv[1:]
print(
    json.dumps(
        {
            "schema": "woopayments_rest_route_gate_plan.v1",
            "ref_wp": ref_wp,
            "target_wp": target_wp,
            "out_dir": out_dir,
            "exceptions": exceptions,
            "route_prefix": route_prefix,
            "mobile_api_requests": [
                "GET /wc/v3/payments/accounts",
                "POST /wc/v3/payments/connection_tokens",
            ],
            "self_check": self_check == "1",
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
	validate_wp_runner "ref" "$REF_WP"
	validate_wp_runner "target" "$TARGET_WP"
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
	validate_wp_runner "ref" "$REF_WP"
	validate_wp_runner "target" "$TARGET_WP"
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
$mobile_api = array();
$runtime_owner = 'unknown';

if ( function_exists( 'wc_get_container' ) && class_exists( 'Automattic\\WooCommerce\\Internal\\Payments\\NativePaymentsRuntimeArbiter' ) ) {
	try {
		$arbiter = wc_get_container()->get( 'Automattic\\WooCommerce\\Internal\\Payments\\NativePaymentsRuntimeArbiter' );
		if ( is_object( $arbiter ) && method_exists( $arbiter, 'get_runtime_owner' ) ) {
			$runtime_owner = (string) $arbiter->get_runtime_owner();
		}
	} catch ( Throwable $throwable ) {
		unset( $throwable );
	}
}
if ( 'unknown' === $runtime_owner && class_exists( 'WC_Payments' ) ) {
	$runtime_owner = 'plugin';
}

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

$shape_of = static function ( $value ) use ( &$shape_of ) {
	if ( is_array( $value ) ) {
		$is_list = empty( $value ) || array_keys( $value ) === range( 0, count( $value ) - 1 );
		if ( $is_list ) {
			$item_shapes = array();
			foreach ( $value as $item ) {
				$item_shape = $shape_of( $item );
				$item_shapes[wp_json_encode( $item_shape )] = $item_shape;
			}
			ksort( $item_shapes );

			return array(
				'type'        => 'array',
				'item_shapes' => array_values( $item_shapes ),
			);
		}

		$fields = array();
		foreach ( $value as $key => $item ) {
			$fields[(string) $key] = $shape_of( $item );
		}
		ksort( $fields );

		return array(
			'type'   => 'object',
			'fields' => $fields,
		);
	}

	if ( is_object( $value ) ) {
		return $shape_of( get_object_vars( $value ) );
	}

	$type = gettype( $value );
	if ( 'double' === $type ) {
		$type = 'number';
	} elseif ( 'NULL' === $type ) {
		$type = 'null';
	}

	return array( 'type' => $type );
};

if ( function_exists( 'wp_set_current_user' ) ) {
	wp_set_current_user( 1 );
}

$mobile_requests = array(
	'accounts'          => array( 'GET', '/wc/v3/payments/accounts' ),
	'connection_tokens' => array( 'POST', '/wc/v3/payments/connection_tokens' ),
);

foreach ( $mobile_requests as $name => $request_spec ) {
	$request  = new WP_REST_Request( $request_spec[0], $request_spec[1] );
	$response = rest_do_request( $request );
	$mobile_api[$name] = array(
		'method' => $request_spec[0],
		'route'  => $request_spec[1],
		'status' => $response->get_status(),
		'shape'  => $shape_of( $response->get_data() ),
	);
}

echo json_encode(
	array(
		'schema'        => 'woopayments_rest_route_capture.v2',
		'role'          => $role,
		'runtime_owner' => $runtime_owner,
		'site_url'      => function_exists( 'home_url' ) ? home_url( '/' ) : '',
		'routes'        => $captured,
		'mobile_api'    => $mobile_api,
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

python3 - "$REF_STATE" "$TARGET_STATE" "$EXCEPTIONS" "$OUT_DIR/rest-route-parity.json" "$SELF_CHECK" <<'PY'
from __future__ import annotations

import json
import ipaddress
import re
import sys
from dataclasses import dataclass
from pathlib import Path
from urllib.parse import urlparse, urlunparse


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


def normalize_local_site_url(value: object) -> str | None:
    parsed = urlparse(str(value))
    host = (parsed.hostname or "").lower()
    local_host = host in {"localhost", "host.docker.internal", "gateway.docker.internal"} or host.endswith(
        ".localhost"
    )
    if not local_host:
        try:
            local_host = ipaddress.ip_address(host).is_loopback
        except ValueError:
            local_host = False
    if parsed.scheme not in {"http", "https"} or not local_host or parsed.username or parsed.password:
        return None
    return urlunparse((parsed.scheme.lower(), parsed.netloc.lower(), parsed.path.rstrip("/"), "", "", ""))


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
    blocked: list[str],
    exceptions_applied: list[str],
    reference: dict,
    target: dict,
    exception_rows: list[str],
    mobile_api: dict,
) -> None:
    path.parent.mkdir(parents=True, exist_ok=True)
    path.write_text(
        json.dumps(
            {
                "schema": "woopayments_rest_route_gate_result.v1",
                "status": status,
                "failures": failures,
                "blocked": blocked,
                "exceptions_applied": exceptions_applied,
                "reference": reference,
                "target": target,
                "exceptions": exception_rows,
                "mobile_api": mobile_api,
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
self_check = sys.argv[5] == "1"

reference = load_json(ref_path)
target = load_json(target_path)
ref_routes = load_routes(reference)
target_routes = load_routes(target)
exceptions, failures = parse_exceptions(exceptions_path)
exceptions_applied: list[str] = []
blocked: list[str] = []
mobile_api_result: dict[str, object] = {"status": "pending", "endpoints": {}}

expected_owners = {"reference": "plugin", "target": "plugin" if self_check else "native"}
site_urls: dict[str, str] = {}
for expected_role, payload in (("reference", reference), ("target", target)):
    if payload.get("role") != expected_role:
        blocked.append(f"{expected_role} snapshot role must be {expected_role}")
    expected_owner = expected_owners[expected_role]
    if payload.get("runtime_owner") != expected_owner:
        blocked.append(f"{expected_role} runtime owner must be {expected_owner}")
    normalized_site = normalize_local_site_url(payload.get("site_url"))
    if normalized_site is None:
        blocked.append(f"{expected_role} snapshot must identify a local site URL")
    else:
        site_urls[expected_role] = normalized_site

    if not isinstance(payload.get("mobile_api"), dict):
        blocked.append(f"{expected_role} snapshot is missing the mobile API smoke contract")

if len(site_urls) == 2:
    same_site = site_urls["reference"] == site_urls["target"]
    if self_check and not same_site:
        blocked.append("self-check snapshots must identify the same local site")
    if not self_check and same_site:
        blocked.append("cross-store snapshots must identify distinct local sites")

if blocked:
    write_rollup(
        rollup_path,
        status="blocked",
        failures=[],
        blocked=blocked,
        exceptions_applied=[],
        reference=reference,
        target=target,
        exception_rows=[],
        mobile_api={"status": "blocked", "endpoints": {}},
    )
    for reason in blocked:
        print(f"BLOCKED: {reason}", file=sys.stderr)
    sys.exit(3)

required_mobile_endpoints = {
    "accounts": ("GET", "/wc/v3/payments/accounts"),
    "connection_tokens": ("POST", "/wc/v3/payments/connection_tokens"),
}


def field_type(shape: object, *path: str) -> str | None:
    current = shape
    for key in path:
        if not isinstance(current, dict) or current.get("type") != "object":
            return None
        fields = current.get("fields")
        if not isinstance(fields, dict):
            return None
        current = fields.get(key)
    if not isinstance(current, dict):
        return None
    value = current.get("type")
    return value if isinstance(value, str) else None


for endpoint, (expected_method, expected_route) in required_mobile_endpoints.items():
    endpoint_result: dict[str, object] = {}
    mobile_api_result["endpoints"][endpoint] = endpoint_result
    captures: dict[str, dict] = {}

    for role, payload in (("reference", reference), ("target", target)):
        capture = payload["mobile_api"].get(endpoint)
        if not isinstance(capture, dict):
            failures.append(f"{role} mobile API probe missing endpoint: {endpoint}")
            continue
        captures[role] = capture
        endpoint_result[role] = capture

        if capture.get("method") != expected_method or normalize_route(capture.get("route", "")) != expected_route:
            failures.append(f"{role} mobile API probe has the wrong request contract: {endpoint}")

        status = capture.get("status")
        if not isinstance(status, int) or status < 200 or status >= 300:
            failures.append(f"{role} mobile API probe failed: {endpoint} returned HTTP {status}")

        shape = capture.get("shape")
        if not isinstance(shape, dict):
            failures.append(f"{role} mobile API probe has no response shape: {endpoint}")
        elif endpoint == "accounts":
            if field_type(shape, "account_id") != "string" or field_type(shape, "status") != "string":
                failures.append(f"{role} mobile accounts response does not describe a connected account")
        elif field_type(shape, "secret") != "string":
            failures.append(f"{role} connection token response does not contain a string secret")

    if len(captures) == 2 and captures["reference"].get("shape") != captures["target"].get("shape"):
        failures.append(f"mobile API response shape mismatch: {endpoint}")

mobile_api_result["status"] = "fail" if failures else "pass"

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
        blocked=[],
        exceptions_applied=exceptions_applied,
        reference=reference,
        target=target,
        exception_rows=exception_rows,
        mobile_api=mobile_api_result,
    )
    for failure in failures:
        print(failure, file=sys.stderr)
    sys.exit(1)

write_rollup(
    rollup_path,
    status="pass",
    failures=[],
    blocked=[],
    exceptions_applied=exceptions_applied,
    reference=reference,
    target=target,
    exception_rows=exception_rows,
    mobile_api=mobile_api_result,
)
print("PASS: native WooPayments REST routes and mobile API shapes match the reference runtime.")
PY
