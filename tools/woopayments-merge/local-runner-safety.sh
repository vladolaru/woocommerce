#!/usr/bin/env bash

# Shared local-only command and URL validation for mutating WooPayments harness gates.

woopayments_normalize_local_url() {
	python3 - "$1" <<'PY'
import ipaddress
import sys
from urllib.parse import urlparse, urlunparse

parsed = urlparse(sys.argv[1])
host = (parsed.hostname or "").lower()
local_name = host in {"localhost", "host.docker.internal", "gateway.docker.internal"} or host.endswith(".localhost")
if not local_name:
    try:
        local_name = ipaddress.ip_address(host).is_loopback
    except ValueError:
        local_name = False
if parsed.scheme not in {"http", "https"} or not local_name:
    raise SystemExit(1)
if parsed.username or parsed.password or parsed.query or parsed.fragment:
    raise SystemExit(1)
path = parsed.path.rstrip("/")
print(urlunparse((parsed.scheme.lower(), parsed.netloc.lower(), path, "", "", "")))
PY
}

woopayments_validate_local_wp_runner() {
	python3 - "$1" <<'PY'
import os
import re
import shlex
import sys
from urllib.parse import urlparse

command = sys.argv[1]

def reject(reason):
    print(reason)
    raise SystemExit(1)

if any(character in command for character in ("\x00", "\r", "\n", "\t")):
    reject("control characters are not allowed")

if re.search(r"[;&|<>`$(){}!*?\[\]#~\\'\"]", command):
    reject("shell metacharacters are not allowed")

try:
    tokens = shlex.split(command, posix=True)
except ValueError:
    reject("runner syntax is invalid")

if not tokens:
    reject("runner is empty")

def is_local_host(host):
    host = host.lower()
    return host in {"localhost", "127.0.0.1", "::1", "host.docker.internal", "gateway.docker.internal"} or host.endswith(".localhost")

for index, token in enumerate(tokens):
    lowered = token.lower()
    if lowered == "--ssh" or lowered.startswith("--ssh="):
        reject("WP-CLI --ssh runners are remote")
    if lowered == "--http" or lowered.startswith("--http="):
        reject("WP-CLI --http runners are remote")
    if token.startswith("@"):
        reject("WP-CLI aliases are not accepted")
    if lowered == "--url" or lowered.startswith("--url="):
        value = tokens[index + 1] if lowered == "--url" and index + 1 < len(tokens) else token.partition("=")[2]
        parsed = urlparse(value if "://" in value else "//" + value)
        if not parsed.hostname or not is_local_host(parsed.hostname):
            reject("WP-CLI --url must resolve to a loopback/local host")
    if re.search(r"(?:^|[./:@_-])(wpcom|wordpress|a8c)\.com(?:$|[/:])", lowered):
        reject("remote Automattic/WPCOM hosts are not accepted")

for raw_url in re.findall(r"https?://[^\s]+", command, flags=re.IGNORECASE):
    parsed = urlparse(raw_url)
    host = (parsed.hostname or "").lower()
    if not is_local_host(host):
        reject("only loopback/local HTTP URLs are accepted")

launcher = os.path.basename(tokens[0]).lower()
wp_basenames = {"wp", "wp-cli", "wp-cli.phar", "wp.phar"}
package_manager_basenames = {"pnpm", "npm", "npx", "yarn"}

if launcher == "docker":
    docker_host = os.environ.get("DOCKER_HOST", "")
    docker_context = os.environ.get("DOCKER_CONTEXT", "")
    if docker_host and not docker_host.startswith("unix://"):
        reject("DOCKER_HOST must use a local Unix socket")
    if docker_context not in {"", "default"}:
        reject("DOCKER_CONTEXT must be empty or default")
    if len(tokens) < 4 or tokens[1] != "exec":
        reject("only local 'docker exec ... wp' runners are accepted")

    exec_tokens = tokens[2:]
    option_flags = {
        "-d", "--detach", "-i", "--interactive", "--privileged", "-t", "--tty"
    }
    options_with_values = {
        "--detach-keys", "-e", "--env", "--env-file", "-u", "--user", "-w", "--workdir"
    }
    index = 0
    while index < len(exec_tokens) and exec_tokens[index].startswith("-"):
        option = exec_tokens[index]
        if option == "--":
            index += 1
            break
        if option in option_flags:
            index += 1
            continue
        if option in options_with_values:
            if index + 1 >= len(exec_tokens):
                reject(f"docker exec option {option} lacks a value")
            index += 2
            continue
        if any(option.startswith(f"{name}=") for name in options_with_values if name.startswith("--")):
            index += 1
            continue
        reject(f"docker exec option {option} is not accepted")

    if index >= len(exec_tokens):
        reject("docker runner lacks a container name")
    index += 1  # Container name.
    if index >= len(exec_tokens):
        reject("docker runner lacks a command")

    docker_launcher = os.path.basename(exec_tokens[index]).lower()
    if docker_launcher in package_manager_basenames:
        reject("opaque package-manager WP runners are not accepted")
    if docker_launcher not in wp_basenames:
        reject("docker runner command must be a WP-CLI executable")
elif launcher in package_manager_basenames:
    reject("opaque package-manager WP runners are not accepted")
else:
    reject("runner must use an approved local Docker WP-CLI form")
PY
	local validation_status=$?
	if [ "$validation_status" -ne 0 ]; then
		return "$validation_status"
	fi

	local docker_bin
	docker_bin="$(
		python3 - "$1" <<'PY'
import os
import shlex
import sys

tokens = shlex.split(sys.argv[1], posix=True)
if tokens and os.path.basename(tokens[0]).lower() == "docker":
    print(tokens[0])
PY
	)"
	if [ -z "$docker_bin" ]; then
		return 0
	fi

	local docker_context docker_context_json docker_endpoint
	if ! docker_context="$("$docker_bin" context show 2>/dev/null)" || [ -z "$docker_context" ]; then
		printf 'Docker effective context could not be resolved\n'
		return 1
	fi
	if ! docker_context_json="$("$docker_bin" context inspect "$docker_context" 2>/dev/null)"; then
		printf 'Docker effective context could not be inspected\n'
		return 1
	fi
	docker_endpoint="$(
		printf '%s' "$docker_context_json" | python3 -c '
import json
import sys

try:
    contexts = json.load(sys.stdin)
    endpoint = contexts[0]["Endpoints"]["docker"]["Host"]
except (KeyError, IndexError, TypeError, ValueError, json.JSONDecodeError):
    raise SystemExit(1)
print(endpoint)
' 2>/dev/null
	)" || {
		printf 'Docker effective endpoint could not be resolved\n'
		return 1
	}
	case "$docker_endpoint" in
		unix://*) ;;
		*)
			printf 'Docker effective context must use a local Unix socket\n'
			return 1
				;;
		esac
}

woopayments_docker_runner_details() {
	python3 - "$1" <<'PY'
import os
import shlex
import sys

tokens = shlex.split(sys.argv[1], posix=True)
if not tokens or os.path.basename(tokens[0]).lower() != "docker":
    raise SystemExit(0)

exec_tokens = tokens[2:]
option_flags = {"-d", "--detach", "-i", "--interactive", "--privileged", "-t", "--tty"}
options_with_values = {"--detach-keys", "-e", "--env", "--env-file", "-u", "--user", "-w", "--workdir"}
index = 0
while index < len(exec_tokens) and exec_tokens[index].startswith("-"):
    option = exec_tokens[index]
    if option == "--":
        index += 1
        break
    if option in option_flags:
        index += 1
        continue
    if option in options_with_values:
        index += 2
        continue
    if any(option.startswith(f"{name}=") for name in options_with_values if name.startswith("--")):
        index += 1
        continue
    raise SystemExit(1)

if index >= len(exec_tokens):
    raise SystemExit(1)
print(f"{tokens[0]}\t{exec_tokens[index]}")
PY
}

woopayments_validate_approved_docker_runner() {
	local runner="$1"
	local role="${2:-either}"
	local details docker_bin container
	local approved_ref="${WOOPAYMENTS_APPROVED_REF_CONTAINER:-wcpay_wp_default}"
	local approved_target="${WOOPAYMENTS_APPROVED_TARGET_CONTAINER:-}"

	if ! details="$(woopayments_docker_runner_details "$runner")" || [ -z "$details" ]; then
		printf 'runner must use a local Docker container\n'
		return 1
	fi
	IFS=$'\t' read -r docker_bin container <<< "$details"
	unset docker_bin

	case "$role" in
		ref|reference)
			if [ "$container" = "$approved_ref" ]; then
				return 0
			fi
			printf 'Docker runner container %s is not the approved reference container %s\n' "$container" "$approved_ref"
			return 1
			;;
		target)
			if [ -z "$approved_target" ]; then
				printf 'an approved target container must be exported before running a target gate\n'
				return 1
			fi
			if [ "$container" = "$approved_target" ]; then
				return 0
			fi
			printf 'Docker runner container %s is not the approved target container %s\n' "$container" "$approved_target"
			return 1
			;;
		either|'')
			if [ "$container" = "$approved_ref" ] || { [ -n "$approved_target" ] && [ "$container" = "$approved_target" ]; }; then
				return 0
			fi
			printf 'Docker runner container %s is not an approved reference or target container\n' "$container"
			return 1
			;;
		*)
			printf 'unknown Docker runner role: %s\n' "$role"
			return 1
			;;
	esac
}

woopayments_validate_docker_compose_project() {
	local runner="$1"
	local expected_project="$2"
	local details docker_bin container actual_project

	if ! details="$(woopayments_docker_runner_details "$runner")"; then
		printf 'Docker runner details could not be resolved\n'
		return 1
	fi
	if [ -z "$details" ]; then
		return 0
	fi
	if [ -z "$expected_project" ]; then
		printf 'Docker-backed WP runners require an explicit expected Compose project\n'
		return 1
	fi

	IFS=$'\t' read -r docker_bin container <<< "$details"
	if ! actual_project="$("$docker_bin" inspect --format '{{ index .Config.Labels "com.docker.compose.project" }}' "$container" 2>/dev/null)" || [ -z "$actual_project" ]; then
		printf 'Docker container %s Compose project label could not be inspected\n' "$container"
		return 1
	fi
	if [ "$actual_project" != "$expected_project" ]; then
		printf 'Docker container %s belongs to Compose project %s, expected %s\n' "$container" "$actual_project" "$expected_project"
		return 1
	fi
}

woopayments_docker_url_alias_is_published() {
	local runner="$1"
	local observed_url="$2"
	local expected_url="$3"
	local details docker_bin container ports_json

	if ! details="$(woopayments_docker_runner_details "$runner")" || [ -z "$details" ]; then
		return 1
	fi
	IFS=$'\t' read -r docker_bin container <<< "$details"
	if ! ports_json="$("$docker_bin" inspect --format '{{json .NetworkSettings.Ports}}' "$container" 2>/dev/null)" || [ -z "$ports_json" ]; then
		return 1
	fi

	python3 - "$observed_url" "$expected_url" "$ports_json" <<'PY'
import ipaddress
import json
import sys
from urllib.parse import urlparse


def effective_port(parsed):
    if parsed.port is not None:
        return parsed.port
    return 443 if parsed.scheme == "https" else 80


observed = urlparse(sys.argv[1])
expected = urlparse(sys.argv[2])
try:
    ports = json.loads(sys.argv[3])
except (TypeError, ValueError, json.JSONDecodeError):
    raise SystemExit(1)

if not isinstance(ports, dict):
    raise SystemExit(1)
if observed.scheme != expected.scheme:
    raise SystemExit(1)
if (observed.hostname or "").lower() != (expected.hostname or "").lower():
    raise SystemExit(1)
if observed.path.rstrip("/") != expected.path.rstrip("/"):
    raise SystemExit(1)

container_port = effective_port(observed)
host_port = effective_port(expected)
if container_port == host_port:
    raise SystemExit(1)

bindings = ports.get(f"{container_port}/tcp")
if not isinstance(bindings, list):
    raise SystemExit(1)

for binding in bindings:
    if not isinstance(binding, dict) or str(binding.get("HostPort", "")) != str(host_port):
        continue
    host_ip = str(binding.get("HostIp", ""))
    if host_ip in {"", "0.0.0.0", "::"}:
        raise SystemExit(0)
    try:
        if ipaddress.ip_address(host_ip).is_loopback:
            raise SystemExit(0)
    except ValueError:
        continue

raise SystemExit(1)
PY
}

woopayments_local_url_matches() {
	local runner="$1"
	local observed_url expected_url

	if ! observed_url="$(woopayments_normalize_local_url "$2")"; then
		return 1
	fi
	if ! expected_url="$(woopayments_normalize_local_url "$3")"; then
		return 1
	fi
	if [ "$observed_url" = "$expected_url" ]; then
		return 0
	fi

	woopayments_docker_url_alias_is_published "$runner" "$observed_url" "$expected_url"
}

woopayments_capture_local_store_identity() {
	local runner="$1"
	local raw rc line payload

	# The runner has already passed woopayments_validate_local_wp_runner.
	# shellcheck disable=SC2086
	raw="$($runner eval-file - <<'PHP' 2>&1
<?php
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
echo 'WCPAY_RUNTIME_IDENTITY:' . wp_json_encode(
	array(
		'runtime_owner' => $runtime_owner,
		'site_url'      => function_exists( 'home_url' ) ? home_url( '/' ) : '',
	)
) . "\n";
PHP
	)"
	rc=$?
	line="$(printf '%s\n' "$raw" | grep '^WCPAY_RUNTIME_IDENTITY:' | tail -1)"
	if [ "$rc" -ne 0 ] || [ -z "$line" ]; then
		printf 'WP runtime identity probe failed: %s\n' "$(printf '%s\n' "$raw" | tail -5)"
		return 1
	fi
	payload="${line#WCPAY_RUNTIME_IDENTITY:}"

	printf '%s' "$payload" | python3 -c '
import ipaddress
import json
import sys
from urllib.parse import urlparse, urlunparse

try:
    payload = json.load(sys.stdin)
except (TypeError, ValueError, json.JSONDecodeError):
    raise SystemExit(1)
owner = str(payload.get("runtime_owner", ""))
parsed = urlparse(str(payload.get("site_url", "")))
host = (parsed.hostname or "").lower()
is_local = host in {"localhost", "host.docker.internal", "gateway.docker.internal"} or host.endswith(".localhost")
if not is_local:
    try:
        is_local = ipaddress.ip_address(host).is_loopback
    except ValueError:
        is_local = False
if owner not in {"plugin", "native"} or parsed.scheme not in {"http", "https"} or not is_local:
    raise SystemExit(1)
site_url = urlunparse((parsed.scheme.lower(), parsed.netloc.lower(), parsed.path.rstrip("/"), "", "", ""))
print(f"{owner}\t{site_url}")
' || {
		printf 'WP runtime identity payload is invalid\n'
		return 1
	}
}

woopayments_validate_local_store_identity() {
	local runner="$1"
	local expected_owner="$2"
	local expected_url="$3"
	local identity observed_owner observed_url normalized_expected_url

	if ! identity="$(woopayments_capture_local_store_identity "$runner")"; then
		return 1
	fi
	IFS=$'\t' read -r observed_owner observed_url <<< "$identity"
	if ! normalized_expected_url="$(woopayments_normalize_local_url "$expected_url")"; then
		printf 'Expected store URL is not local: %s\n' "$expected_url"
		return 1
	fi
	if [ "$observed_owner" != "$expected_owner" ]; then
		printf 'Observed runtime owner %s, expected %s\n' "$observed_owner" "$expected_owner"
		return 1
	fi
	if ! woopayments_local_url_matches "$runner" "$observed_url" "$normalized_expected_url"; then
		printf 'Observed store URL %s, expected %s\n' "$observed_url" "$normalized_expected_url"
		return 1
	fi
}
