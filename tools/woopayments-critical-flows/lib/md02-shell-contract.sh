#!/usr/bin/env bash
# Pure MD-02 supervisor verdict classification shared by the flow and its tests.

md02_classify_verdict() { # <gate-rc> <assertion-failed> <assertion-blocked> <log-rc> <comparison-rc>
	local gate_rc="$1" assertion_failed="$2" assertion_blocked="$3" log_rc="$4" comparison_rc="$5"
	if [ "$gate_rc" -eq 70 ]; then
		printf 'cleanup 70\n'
	elif [ "$gate_rc" -eq 3 ] || [ "$assertion_blocked" -eq 1 ] || [ "$log_rc" -eq 3 ] || [ "$comparison_rc" -eq 3 ]; then
		printf 'blocked 3\n'
	elif [ "$gate_rc" -eq 1 ] || [ "$assertion_failed" -eq 1 ] || [ "$log_rc" -eq 1 ] || [ "$comparison_rc" -eq 1 ]; then
		printf 'fail 1\n'
	else
		printf 'pass 0\n'
	fi
}
