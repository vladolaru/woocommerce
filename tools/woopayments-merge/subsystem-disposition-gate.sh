#!/usr/bin/env bash
#
# Static inventory gate for the WooPayments extension subsystem disposition manifest.
#
# The gate enumerates extension PHP files under includes/ and src/, then verifies that every
# file has an exact manifest source path. DROPPED rows must carry explicit sign-off, date,
# and reason fields.

set -euo pipefail

SELF_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
REPO_ROOT="$(cd "$SELF_DIR/../.." && pwd)"
DEFAULT_EXTENSION_ROOT="$(cd "$REPO_ROOT/.." && pwd)/woocommerce-payments"

EXTENSION_ROOT="${WCPAY_EXTENSION_ROOT:-$DEFAULT_EXTENSION_ROOT}"
MANIFEST="$SELF_DIR/subsystem-disposition.md"

while [ "$#" -gt 0 ]; do
	case "$1" in
		--extension-root)
			EXTENSION_ROOT="$2"
			shift 2
			;;
		--manifest)
			MANIFEST="$2"
			shift 2
			;;
		-h|--help)
			echo "usage: subsystem-disposition-gate.sh [--extension-root PATH] [--manifest PATH]" >&2
			exit 2
			;;
		*)
			echo "Unknown arg: $1" >&2
			echo "usage: subsystem-disposition-gate.sh [--extension-root PATH] [--manifest PATH]" >&2
			exit 2
			;;
	esac
done

if [ ! -d "$EXTENSION_ROOT/includes" ] && [ ! -d "$EXTENSION_ROOT/src" ]; then
	echo "Extension root does not contain includes/ or src/: $EXTENSION_ROOT" >&2
	exit 2
fi

if [ ! -f "$MANIFEST" ]; then
	echo "Manifest not found: $MANIFEST" >&2
	exit 2
fi

python3 - "$EXTENSION_ROOT" "$MANIFEST" <<'PY'
from __future__ import annotations

import re
import sys
from dataclasses import dataclass
from pathlib import Path


VALID_DISPOSITIONS = {"PORTED", "SUPERSEDED", "DROPPED"}
PATH_RE = re.compile(r"`([^`]+)`")


@dataclass(frozen=True)
class ManifestRow:
    subsystem: str
    disposition: str
    patterns: tuple[str, ...]
    signed_off_by: str
    sign_off_date: str
    reason: str


@dataclass(frozen=True)
class InvalidManifestRow:
    subsystem: str
    disposition: str
    patterns: tuple[str, ...]


def normalize_header(value: str) -> str:
    return re.sub(r"[^a-z0-9]+", "_", value.strip().lower()).strip("_")


def split_markdown_row(line: str) -> list[str]:
    stripped = line.strip()
    if not stripped.startswith("|") or not stripped.endswith("|"):
        return []

    return [cell.strip() for cell in stripped.strip("|").split("|")]


def is_separator_row(cells: list[str]) -> bool:
    return bool(cells) and all(re.fullmatch(r":?-{3,}:?", cell.strip()) for cell in cells)


def path_patterns_from_cell(cell: str) -> tuple[str, ...]:
    patterns: list[str] = []
    for raw_pattern in PATH_RE.findall(cell):
        pattern = raw_pattern.strip()
        if pattern.startswith("ext:"):
            pattern = pattern[4:]
        pattern = pattern.lstrip("/")
        if pattern.startswith(("includes/", "src/")):
            patterns.append(pattern)

    return tuple(patterns)


def read_manifest_rows(manifest: Path) -> tuple[list[ManifestRow], list[InvalidManifestRow]]:
    rows: list[ManifestRow] = []
    invalid_rows: list[InvalidManifestRow] = []
    headers: list[str] | None = None

    for line in manifest.read_text(encoding="utf-8").splitlines():
        cells = split_markdown_row(line)
        if not cells:
            continue

        if headers is None:
            normalized = [normalize_header(cell) for cell in cells]
            if "extension_source" in normalized and "disposition" in normalized:
                headers = normalized
            continue

        if is_separator_row(cells):
            continue

        if len(cells) < len(headers):
            cells.extend([""] * (len(headers) - len(cells)))

        values = dict(zip(headers, cells))
        disposition = values.get("disposition", "").strip().strip("`").upper()
        patterns = path_patterns_from_cell(values.get("extension_source", ""))

        if disposition not in VALID_DISPOSITIONS:
            if patterns:
                invalid_rows.append(
                    InvalidManifestRow(
                        subsystem=values.get("subsystem", "").strip() or "(unnamed subsystem)",
                        disposition=disposition or "(blank)",
                        patterns=patterns,
                    )
                )
            continue

        if not patterns:
            continue

        rows.append(
            ManifestRow(
                subsystem=values.get("subsystem", "").strip() or "(unnamed subsystem)",
                disposition=disposition,
                patterns=patterns,
                signed_off_by=values.get("signed_off_by", "").strip(),
                sign_off_date=values.get("sign_off_date", "").strip(),
                reason=values.get("reason", "").strip(),
            )
        )

    return rows, invalid_rows


def enumerate_extension_files(extension_root: Path) -> list[str]:
    files: list[str] = []

    for directory in ("includes", "src"):
        root = extension_root / directory
        if not root.exists():
            continue
        for path in root.rglob("*.php"):
            if path.is_file():
                files.append(path.relative_to(extension_root).as_posix())

    return sorted(files)


def matches(pattern: str, relative_path: str) -> bool:
    return pattern == relative_path


def is_exact_php_source_path(pattern: str) -> bool:
    if not pattern.endswith(".php"):
        return False

    if not pattern.startswith(("includes/", "src/")):
        return False

    return not any(character in pattern for character in ("*", "?", "[", "]"))


def main() -> int:
    extension_root = Path(sys.argv[1]).resolve()
    manifest = Path(sys.argv[2]).resolve()

    extension_files = enumerate_extension_files(extension_root)
    rows, invalid_rows = read_manifest_rows(manifest)

    unmatched = [
        relative_path
        for relative_path in extension_files
        if not any(matches(pattern, relative_path) for row in rows for pattern in row.patterns)
    ]
    stale_patterns = [
        (row, pattern)
        for row in rows
        for pattern in row.patterns
        if not any(matches(pattern, relative_path) for relative_path in extension_files)
    ]
    dropped_without_signoff = [
        row
        for row in rows
        if row.disposition == "DROPPED" and (not row.signed_off_by or not row.sign_off_date or not row.reason)
    ]
    non_exact_patterns = [
        (row, pattern)
        for row in rows
        for pattern in row.patterns
        if not is_exact_php_source_path(pattern)
    ]
    disposition_counts = {
        disposition: sum(1 for row in rows if row.disposition == disposition)
        for disposition in sorted(VALID_DISPOSITIONS)
    }
    signed_dropped_rows = [
        row
        for row in rows
        if row.disposition == "DROPPED" and row.signed_off_by and row.sign_off_date and row.reason
    ]

    print("WooPayments subsystem disposition gate")
    print(f"  extension root: {extension_root}")
    print(f"  manifest: {manifest}")
    print(f"  manifest rows: {len(rows)}")
    print(f"  extension files: {len(extension_files)}")
    print(
        "  disposition counts: "
        f"PORTED={disposition_counts['PORTED']} "
        f"SUPERSEDED={disposition_counts['SUPERSEDED']} "
        f"DROPPED={disposition_counts['DROPPED']}"
    )
    print(f"  signed DROPPED rows: {len(signed_dropped_rows)}")

    if unmatched:
        print()
        print(f"unmatched extension files ({len(unmatched)}):")
        for relative_path in unmatched[:200]:
            print(f"  - {relative_path}")
        if len(unmatched) > 200:
            print(f"  ... {len(unmatched) - 200} more")

    if dropped_without_signoff:
        print()
        print(f"dropped rows missing sign-off ({len(dropped_without_signoff)}):")
        for row in dropped_without_signoff:
            print(f"  - {row.subsystem}")

    if stale_patterns:
        print()
        print(f"manifest source patterns matching no extension files ({len(stale_patterns)}):")
        for row, pattern in stale_patterns[:200]:
            print(f"  - {row.subsystem}: {pattern}")
        if len(stale_patterns) > 200:
            print(f"  ... {len(stale_patterns) - 200} more")

    if invalid_rows:
        print()
        print(f"invalid disposition rows ({len(invalid_rows)}):")
        for row in invalid_rows:
            print(f"  - {row.subsystem}: {row.disposition}")

    if non_exact_patterns:
        print()
        print(f"non-exact manifest source patterns ({len(non_exact_patterns)}):")
        for row, pattern in non_exact_patterns[:200]:
            print(f"  - {row.subsystem}: {pattern}")
        if len(non_exact_patterns) > 200:
            print(f"  ... {len(non_exact_patterns) - 200} more")

    if unmatched or dropped_without_signoff or stale_patterns or invalid_rows or non_exact_patterns:
        print()
        print("RESULT: FAIL")
        return 1

    print()
    print(f"RESULT: PASS - {len(extension_files)} extension files covered by {len(rows)} manifest rows.")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
PY
