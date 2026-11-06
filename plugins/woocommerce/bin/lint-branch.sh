#!/usr/bin/env bash
# Lint branch
#
# Runs phpcs-changed, comparing the current branch to its "base" or "parent" branch.
# The base branch defaults to trunk, but another branch name can be specified as an
# optional positional argument.
#
# Example:
# ./lint-branch.sh base-branch
#
# When WC_PHPCS_CHECKSTYLE_FILE is set and PHPCS finds problems, the findings are also
# written to that path as a checkstyle report, for a workflow step to feed to cs2pr,
# which turns them into inline annotations on 'Files changed'. See the
# 'Lint: PHP inline annotations' step in .github/workflows/ci.yml.

baseBranch=${1:-"origin/trunk"}

# Validated outside the process substitution below, where a failure (unfetched base
# branch, shallow clone) would be swallowed and read as "no changed files": exit 0
# with PHPCS never run.
mergeBase=$(git merge-base HEAD "$baseBranch") || exit 1

# -z plus the read loop keeps one path per argument: a filename with a space or a
# glob character would otherwise split into several bogus arguments. (mapfile is
# not an option; macOS ships bash 3.2, which doesn't have it.)
changedFiles=()
while IFS= read -r -d '' file; do
    changedFiles+=("$file")
done < <(git diff -z "$mergeBase" --relative --name-only --diff-filter=d -- '*.php')

# Only complete this if changed files are detected.
if [[ ${#changedFiles[@]} -eq 0 ]]; then
    echo "No changed files detected."
    exit 0
fi

# Run all checks even if an earlier one fails, then report a non-zero status if any failed,
# so a failure in one check is never masked by a later one passing.
status=0

# Cache phpcs output only in CI, where the checkstyle render below re-runs the same
# check: with the cache primed by the first run, the re-run is mostly cache reads.
# Local runs stay cache-free, so no .phpcs-changed-cache file is left behind.
cacheArgs=()
[[ -n $WC_PHPCS_CHECKSTYLE_FILE ]] && cacheArgs=('--cache')

# phpcs gets its own status besides the shared accumulator: the checkstyle report
# below must be tied to phpcs itself failing, not to any other check that sets status.
phpcsStatus=0

# One phpcs-changed run over a large branch (an upstream merge can bring 800+ files)
# takes minutes, close to the CI Lint job's 10-minute limit. Above 200 files, and with
# more than one core, the files are split into chunks that run in parallel, one per core
# and at most 4 (the CI runner's count). Smaller diffs, under two minutes on CI even as
# one run, keep the single run below unchanged.
chunkCount=$(getconf _NPROCESSORS_ONLN 2>/dev/null)
[[ $chunkCount =~ ^[0-9]+$ ]] || chunkCount=1
[[ $chunkCount -gt 4 ]] && chunkCount=4
[[ ${#changedFiles[@]} -gt 200 ]] || chunkCount=1

# Fills chunkFiles with the files of chunk $1, in their original order.
selectChunk() {
    local i
    chunkFiles=()
    for i in "${!changedFiles[@]}"; do
        [[ ${chunkOf[i]} -eq $1 ]] && chunkFiles+=("${changedFiles[i]}")
    done
}

# Normalizes the checkstyle report's paths; see the checkstyle render below.
prefixPaths() {
    sed -e "s|<file name=\"${prefix}|<file name=\"|g" \
        -e "s|<file name=\"|<file name=\"${prefix}|g"
}

# Narrows chunkFiles to the files that chunk $1's readable report names, in either form
# (see the checkstyle render below). Keeps the whole chunk when the report names no file,
# or a file the chunk wasn't given.
selectReported() {
    local file names reported=()
    names=$'\n'$(sed -n 's/^FILE: //p' "$chunkDir/$1.txt")$'\n'
    for file in "${chunkFiles[@]}"; do
        [[ $names == *$'\n'"$file"$'\n'* || $names == *$'\n'"$prefix$file"$'\n'* ]] && reported+=("$file")
    done
    [[ ${#reported[@]} -gt 0 && ${#reported[@]} -eq $(grep -c '^FILE: ' "$chunkDir/$1.txt") ]] &&
        chunkFiles=("${reported[@]}")
}

if [[ $chunkCount -gt 1 ]]; then
    # Every chunk writes to its own files in here, so no output interleaves.
    chunkDir=$(mktemp -d) || exit 1
    trap 'rm -rf "$chunkDir"' EXIT

    # Chunks are balanced by byte size, largest file first. Each file counts one extra
    # byte, so even empty files spread out and no chunk is left without files.
    chunkOf=()
    chunkBytes=()
    for (( chunk = 0; chunk < chunkCount; chunk++ )); do
        chunkBytes[chunk]=0
    done
    while read -r i size; do
        smallest=0
        for (( chunk = 1; chunk < chunkCount; chunk++ )); do
            [[ ${chunkBytes[chunk]} -lt ${chunkBytes[smallest]} ]] && smallest=$chunk
        done
        chunkOf[i]=$smallest
        (( chunkBytes[smallest] += size + 1 ))
    done < <(
        for i in "${!changedFiles[@]}"; do
            printf '%s ' "$i"
            wc -c < "${changedFiles[i]}" || echo 0
        done | sort -k2,2nr
    )

    echo "Linting ${#changedFiles[@]} changed PHP files in $chunkCount parallel chunks."
    # No --cache here: its file (./.phpcs-changed-cache) is shared by every run in this
    # directory, so parallel runs would overwrite it and could read it half-written.
    for (( chunk = 0; chunk < chunkCount; chunk++ )); do
        selectChunk "$chunk"
        {
            composer exec phpcs-changed -- -s --git --git-base "$baseBranch" "${chunkFiles[@]}" \
                > "$chunkDir/$chunk.txt" 2> "$chunkDir/$chunk.err"
            echo $? > "$chunkDir/$chunk.status"
        } &
    done
    wait

    # The reports print in chunk order once all chunks are done. A chunk that left no
    # status behind (it died before writing one) counts as failed.
    for (( chunk = 0; chunk < chunkCount; chunk++ )); do
        cat "$chunkDir/$chunk.txt"
        cat "$chunkDir/$chunk.err" >&2
        [[ $(cat "$chunkDir/$chunk.status" 2>/dev/null) == 0 ]] || phpcsStatus=1
    done
else
    composer exec phpcs-changed -- -s --git --git-base "$baseBranch" "${cacheArgs[@]}" "${changedFiles[@]}" || phpcsStatus=1
fi
status=$phpcsStatus

# The readable report above is the log people dig into; this re-runs the same check
# only to render the same findings as checkstyle for cs2pr (phpcs-changed can only
# emit one format per run). Guarded on failure so green runs never pay for it.
#
# GitHub resolves annotation paths from the repository root, so every path must carry
# this directory's prefix exactly once or the annotation silently never attaches to
# the diff. phpcs-changed names findings two ways (verified in v2.12.0, incl. locally
# with a planted violation): for modified files, getNewMessages() renames them to the
# `git diff --no-prefix` header path, which is repository-rooted; for new files there
# is no diff, so the cwd-relative CLI path survives. Stripping the prefix before
# adding it normalizes both forms to a single prefix.
#
# In chunks, without the cache, a full re-run would be a second full pass, so only the
# files with findings run again: those a failed chunk's readable report names
# (phpcs-changed exits 0 only when it has no findings to report). cs2pr reads one
# document, so the chunks' <file> elements are merged under one XML declaration and one
# <checkstyle> root. A chunk whose output isn't checkstyle is left out, with a note.
if [[ -n $WC_PHPCS_CHECKSTYLE_FILE && $phpcsStatus -eq 1 ]]; then
    prefix=$(git rev-parse --show-prefix)
    if [[ $chunkCount -gt 1 ]]; then
        for (( chunk = 0; chunk < chunkCount; chunk++ )); do
            [[ $(cat "$chunkDir/$chunk.status" 2>/dev/null) == 0 ]] && continue
            selectChunk "$chunk"
            selectReported "$chunk"
            composer exec phpcs-changed -- --git --git-base "$baseBranch" --report=checkstyle "${chunkFiles[@]}" \
                2> "$chunkDir/$chunk.xml.err" | prefixPaths > "$chunkDir/$chunk.xml" &
        done
        wait

        merged=0
        for (( chunk = 0; chunk < chunkCount; chunk++ )); do
            report="$chunkDir/$chunk.xml"
            [[ -e $report ]] || continue
            cat "$report.err" >&2
            if [[ $(head -n 1 "$report") != '<?xml'* || $(sed -n 2p "$report") != '<checkstyle'* ||
                $(tail -n 1 "$report") != '</checkstyle>' ]]; then
                echo "Chunk $(( chunk + 1 )) of $chunkCount wrote no checkstyle report; its findings are only in the report above." >&2
                continue
            fi
            [[ $merged -eq 1 ]] || head -n 2 "$report"
            merged=1
            sed '1,2d;$d' "$report"
        done > "$WC_PHPCS_CHECKSTYLE_FILE"
        [[ $merged -eq 1 ]] && echo '</checkstyle>' >> "$WC_PHPCS_CHECKSTYLE_FILE"
    else
        composer exec phpcs-changed -- --git --git-base "$baseBranch" --report=checkstyle "${cacheArgs[@]}" "${changedFiles[@]}" |
            prefixPaths > "$WC_PHPCS_CHECKSTYLE_FILE"
    fi
fi

# Also verify that no new PHP functions are added.
php ./bin/check-new-functions.php HEAD "$baseBranch" || status=1

exit $status
