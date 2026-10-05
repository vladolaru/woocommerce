#!/usr/bin/env bash
# Lists extension commits since a ref that touch forked paths, grouped by core file; marks shared-contract touches.
# Usage: drift-report.sh [--appendix <path>] <extension clone> <since-ref> [<until-ref>]
#   (for example: drift-report.sh ~/Work/a8c/woocommerce-paypal-payments 0083204e7 dev/develop)
# --appendix defaults to src/Internal/Payments/Providers/PayPal/contract-appendix.md in this plugin.
#
# A commit is marked **contract** when an added or removed line of its diff on the mapped path mentions a name from
# the list items of contract-appendix.md, or when it touches one of the ALWAYS_CONTRACT paths: the three DTO files core keeps
# byte-identical to the extension's (the stored class names are part of the data format, and the Jetpack manifest can
# serve core's copy while the extension runs), so any upstream change to them must be mirrored into core's
# SerializedClasses shim in the same release.
#
# path-map.json maps an extension path to the core file that holds its fork. A null value means the file was dropped in
# core (a dropped module): its commits are listed at the end under "(dropped in core)" and need no port, only a decision
# whether the drop still holds.
set -euo pipefail
HERE="$( cd "$( dirname "${BASH_SOURCE[0]}" )" && pwd )"
CONTRACT="$HERE/../../src/Internal/Payments/Providers/PayPal/contract-appendix.md"
USAGE="usage: drift-report.sh [--appendix <path>] <extension clone> <since-ref> [<until-ref>]"
ARGS=()
while [ $# -gt 0 ]; do
  case "$1" in
    -h|--help)
      sed -n '2,/^set -euo/p' "${BASH_SOURCE[0]}" | sed -e '/^set -euo/d' -e 's/^# \{0,1\}//'; exit 0 ;;
    --appendix)
      [ $# -ge 2 ] || { echo "--appendix needs a path" >&2; echo "$USAGE" >&2; exit 1; }
      CONTRACT="$2"; shift 2 ;;
    -*) echo "unknown option: $1" >&2; echo "$USAGE" >&2; exit 1 ;;
    *) ARGS+=("$1"); shift ;;
  esac
done
[ "${#ARGS[@]}" -ge 2 ] && [ "${#ARGS[@]}" -le 3 ] || { echo "$USAGE" >&2; exit 1; }
EXT="${ARGS[0]}"; SINCE="${ARGS[1]}"; UNTIL="${ARGS[2]:-dev/develop}"
MAP="$HERE/path-map.json"
test -f "$MAP" || { echo "path-map.json missing: $MAP" >&2; exit 1; }
test -f "$CONTRACT" || { echo "contract appendix missing: $CONTRACT" >&2; exit 1; }
ALWAYS_CONTRACT=(
  "modules/ppcp-settings/src/DTO/LocationStylingDTO.php"
  "modules/ppcp-settings/src/DTO/PayLaterMessagingDTO.php"
  "modules/ppcp-settings/src/DTO/OAuthConnectionDTO.php"
)
# Fail loudly on a wrong clone path or a bad ref instead of printing a clean-looking "no drift" report.
git -C "$EXT" rev-parse --git-dir > /dev/null 2>&1 || { echo "not a git clone: $EXT" >&2; exit 1; }
for ref in "$SINCE" "$UNTIL"; do
  git -C "$EXT" rev-parse --verify -q "$ref^{commit}" > /dev/null || { echo "unknown ref in $EXT: $ref" >&2; exit 1; }
done
NAMES="$(mktemp)"; DROPPED="$(mktemp)"; SHOWN="$(mktemp)"; trap 'rm -f "$NAMES" "$DROPPED" "$SHOWN"' EXIT
# Contract names: the first backticked token of each list item in the appendix ("- `name`"). Prose, the section notes and the
# extra backticked tokens in an item's trailing note are not names.
{ grep -E '^- `' "$CONTRACT" || [ $? -eq 1 ]; } | sed -E 's/^- `([^`]+)`.*/\1/' | sort -u > "$NAMES"
emit_commits() {
  echo "(extension: \`$from\`)"
  echo
  while read -r hash subject; do
    # Only the added and removed lines count: no commit message, no context lines, no file headers. The result goes to a file,
    # not into grep -q: with pipefail, grep exiting early would SIGPIPE the pipeline and read as "no match".
    git -C "$EXT" show --format= -U0 "$hash" -- "$from" | { grep -E '^[+-]' || [ $? -eq 1 ]; } | { grep -vE '^(\+\+\+|---) ' || [ $? -eq 1 ]; } > "$SHOWN"
    if [ "$always" = 1 ] || grep -qFf "$NAMES" "$SHOWN"; then
      echo "- **contract** $hash $subject"
    else
      echo "- $hash $subject"
    fi
  done <<< "$commits"
  echo
}
echo "# Drift report: $SINCE..$UNTIL ($(date '+%Y-%m-%d %H:%M'))"
echo
php -r '$m=json_decode(file_get_contents($argv[1]),true); foreach($m as $from=>$to) echo "$from\t" . ($to === null ? "__DROPPED__" : $to) . "\n";' "$MAP" | while IFS=$'\t' read -r from to; do
  commits=$(git -C "$EXT" log --format='%h %s' "$SINCE..$UNTIL" -- "$from")
  [ -z "$commits" ] && continue
  always=0
  for p in "${ALWAYS_CONTRACT[@]}"; do [ "$from" = "$p" ] && always=1; done
  # A dropped file has no core counterpart: collect it for the closing section instead of heading it by a core file.
  if [ "$to" = "__DROPPED__" ]; then
    emit_commits >> "$DROPPED"
  else
    echo "## $to"
    emit_commits
  fi
done
if [ -s "$DROPPED" ]; then
  echo "## (dropped in core)"
  cat "$DROPPED"
fi
