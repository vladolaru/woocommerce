#!/usr/bin/env bash
set -euo pipefail

STORE_DIR="${1:-${A5_STORE_DIR:-${STORE_DIR:-$(pwd)}}}"
WPCOM_LOCAL="${WPCOM_LOCAL:-wpcom-local}"

fail() {
	echo "ERROR: $*" >&2
	exit 1
}

run_json_command() {
	local label="$1"
	shift

	echo "==> ${label}"
	if ! output="$("$@" 2>&1)"; then
		echo "${output}"
		fail "${label} failed"
	fi

	echo "${output}"
	if command -v jq >/dev/null 2>&1; then
		if ! printf '%s\n' "${output}" | jq -e '
			((.exit_code // 0) == 0)
			and (((.errors // []) | length) == 0)
			and ((((.context.checks_failed // 0) | tonumber) // 0) == 0)
			and (
				(.healthy // .ok // .success // false) == true
				or (
					.status == "healthy"
					or .status == "ok"
					or .status == "running"
					or .status == "connected"
					or .status == "success"
					or .status == "warning"
				)
			)
		' >/dev/null; then
			fail "${label} did not report a healthy JSON state"
		fi
	else
		case "${output}" in
			*'"healthy":true'*|*'"ok":true'*|*'"success":true'*|*'"status":"healthy"'*|*'"status":"ok"'*|*'"status":"running"'*|*'"status":"connected"'*|*'"status":"success"'*|*'"status":"warning"'*'"checks_failed":"0"'*)
				;;
			*)
				fail "${label} did not report an obvious healthy JSON state and jq is unavailable"
				;;
		esac
	fi
}

command -v "${WPCOM_LOCAL}" >/dev/null 2>&1 || fail "Command not found: ${WPCOM_LOCAL}"
[ -d "${STORE_DIR}" ] || fail "Store directory does not exist: ${STORE_DIR}"

cd "${STORE_DIR}"
echo "Using store directory: ${STORE_DIR}"
echo "Using wpcom-local command: ${WPCOM_LOCAL}"

run_json_command "wpcom-local env status" "${WPCOM_LOCAL}" --json env status
run_json_command "wpcom-local doctor" "${WPCOM_LOCAL}" --json doctor
run_json_command "wpcom-local identity status" "${WPCOM_LOCAL}" --json identity status
run_json_command "wpcom-local transact status" "${WPCOM_LOCAL}" --json transact status
run_json_command "wpcom-local tracks status" "${WPCOM_LOCAL}" --json tracks status
run_json_command "wpcom-local store doctor" "${WPCOM_LOCAL}" --json store doctor

echo "A5 local WPCOM readiness checks passed."
