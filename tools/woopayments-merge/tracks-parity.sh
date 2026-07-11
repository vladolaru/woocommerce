#!/usr/bin/env bash
#
# Tracks-parity differ (WooPayments → core merge verification harness).
#
# The runtime half of the telemetry-continuity contract (bc-manifest §0.3/§3.6): asserts the
# {event name, props contract} captured for one runtime matches the other. Captures at the
# **wpcom-local Tracks sink** — the local endpoint every store's Tracks (client `browser_tkq` AND
# server `server_pixel`) is posted to via the `wpcom-local-helper` bridge. This is the actual
# pipeline destination, so it is complete (client + server) — strictly better than capturing at
# individual emitters. Requires: the helper active on the store, and the wpcom-local sink enabled.
#
# Normalization (tracks-normalize.py) encodes the §0.3 boundary judgment: freeze event name + prop
# keys/types + stable enum string values + WCPay's deliberate custom props; drop the auto-injected
# envelope and mask volatile values (ids, uuids, versions, timestamps). Synthetic sink sources
# (mock/helper_smoke) are excluded.
#
# The sink is shared by all stores in the wpcom-local checkout. Capture without deleting another
# process's events: mark the append offset, drive one store's flow, then normalize only bytes added
# after that marker. Sub-commands:
#   tracks-parity.sh mark marker.json
#   tracks-parity.sh normalize --mark marker.json > out.txt
#   tracks-parity.sh diff a.txt b.txt       # diff two normalized captures; exit 1 on drift
#
# Cross-store use (the A4 gate — reference vs target):
#   tracks-parity.sh mark ref.json; <drive flow on :8082 reference>; tracks-parity.sh normalize --mark ref.json > ref.txt
#   tracks-parity.sh mark tgt.json; <drive same flow on :8889 target>; tracks-parity.sh normalize --mark tgt.json > tgt.txt
#   tracks-parity.sh diff ref.txt tgt.txt

set -uo pipefail

SELF_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
WLOCAL="${WLOCAL:-wpcom-local}"
CMD="${1:-}"

# Locate the sink NDJSON store (the supported way: ask the CLI).
sink_path() {
	$WLOCAL tracks path 2>/dev/null | grep -oE '/[^ ]*tracks-events\.ndjson' | head -1
}

case "$CMD" in
	mark)
		marker_path="${2:-}"
		if [ -z "$marker_path" ]; then
			echo "usage: tracks-parity.sh mark <marker.json>" >&2
			exit 2
		fi
		if ! command -v python3 >/dev/null 2>&1; then
			echo "python3 required for capture markers" >&2
			exit 2
		fi
		ndjson="$(sink_path)"
		if [ -z "$ndjson" ] || [ ! -f "$ndjson" ]; then
			echo "FAIL: could not locate the sink store (wpcom-local tracks path). Is the sink enabled?" >&2
			exit 2
		fi
		python3 - "$ndjson" "$marker_path" <<'PY'
import json
import os
import sys
from pathlib import Path

sink = Path(sys.argv[1]).resolve()
marker = Path(sys.argv[2])
stat = sink.stat()
payload = {
    "schema_version": 1,
    "sink_path": str(sink),
    "device": stat.st_dev,
    "inode": stat.st_ino,
    "offset": stat.st_size,
}
temporary = marker.with_name(f".{marker.name}.{os.getpid()}.tmp")
temporary.write_text(json.dumps(payload, sort_keys=True) + "\n", encoding="utf-8")
os.replace(temporary, marker)
PY
		echo "tracks capture marked: $marker_path"
		;;
	normalize)
		shift  # drop "normalize"; remaining args (e.g. --mark/--store) are parsed below
		if ! command -v python3 >/dev/null 2>&1; then
			echo "python3 required for normalization" >&2; exit 2
		fi
		marker_path=""
		normalizer_args=()
		while [ "$#" -gt 0 ]; do
			case "$1" in
				--mark)
					marker_path="${2:-}"
					shift 2
					;;
				*)
					normalizer_args+=( "$1" )
					shift
					;;
				esac
		done
		normalizer=( python3 "$SELF_DIR/tracks-normalize.py" )
		if [ "${#normalizer_args[@]}" -gt 0 ]; then
			normalizer+=( "${normalizer_args[@]}" )
		fi
		ndjson="$(sink_path)"
		if [ -z "$ndjson" ] || [ ! -f "$ndjson" ]; then
			echo "FAIL: could not locate the sink store (wpcom-local tracks path). Is the sink enabled?" >&2
			exit 2
		fi
		if [ -n "$marker_path" ]; then
			if [ ! -f "$marker_path" ]; then
				echo "FAIL: Tracks capture marker does not exist: $marker_path" >&2
				exit 2
			fi
			python3 - "$marker_path" "$ndjson" <<'PY' | "${normalizer[@]}"
import json
import os
import shutil
import sys
from pathlib import Path

marker_path = Path(sys.argv[1])
sink_path = Path(sys.argv[2]).resolve()
try:
    marker = json.loads(marker_path.read_text(encoding="utf-8"))
    if marker.get("schema_version") != 1:
        raise ValueError("unsupported marker schema")
    if marker.get("sink_path") != str(sink_path):
        raise ValueError("sink path mismatch")
    offset = marker.get("offset")
    if not isinstance(offset, int) or isinstance(offset, bool) or offset < 0:
        raise ValueError("invalid offset")
    with sink_path.open("rb") as stream:
        current = os.fstat(stream.fileno())
        if marker.get("device") != current.st_dev or marker.get("inode") != current.st_ino:
            raise ValueError("sink identity mismatch")
        if current.st_size < offset:
            raise ValueError("sink was truncated")
        stream.seek(offset)
        shutil.copyfileobj(stream, sys.stdout.buffer)
except Exception as error:
    print(f"FAIL: Tracks sink changed since the capture marker: {error}", file=sys.stderr)
    raise SystemExit(2)
PY
		else
			"${normalizer[@]}" < "$ndjson"
		fi
		;;
	diff)
		A="${2:-}"; B="${3:-}"
		if [ -z "$A" ] || [ -z "$B" ] || [ ! -f "$A" ] || [ ! -f "$B" ]; then
			echo "usage: tracks-parity.sh diff <normalized-a> <normalized-b>" >&2; exit 2
		fi
		d="$(diff "$A" "$B")"
		if [ -n "$d" ]; then
			echo "FAIL: Tracks contract drift (name/props) — telemetry continuity break (bc-manifest §0.3):"
			printf '%s\n' "$d" | sed 's/^/    /'
			echo "  '<' = $A only · '>' = $B only"
			exit 1
		fi
		echo "PASS: zero Tracks contract drift ($(wc -l < "$A" | tr -d ' ') events)."
		;;
	*)
		echo "usage: WLOCAL='<wpcom-local cmd>' tracks-parity.sh <mark marker.json|normalize [--mark marker.json]|diff a b>" >&2
		exit 2
		;;
esac
