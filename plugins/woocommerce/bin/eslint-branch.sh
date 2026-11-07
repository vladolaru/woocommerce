#!/usr/bin/env bash
# Lint branch
#
# Runs eslint, comparing the current branch to its "base" or "parent" branch.
# The base branch defaults to trunk, but another branch name can be specified as an
# optional positional argument.
#
# Changed files are grouped by the package folder that owns their eslint config
# (client/admin, client/blocks, client/legacy, admin-screens, else the plugin root)
# and eslint runs from that folder, as each package's own lint:js does. That is
# where eslint reads the package's eslint-suppressions.json, so bulk-suppressed
# violations stay suppressed here too.
#
# Example:
# ./eslint-branch.sh base-branch

baseBranch=${1:-"origin/trunk"}

# git diff --relative and the package folders below are relative to the plugin root.
cd "$(dirname "${BASH_SOURCE[0]}")/.." || exit 1

# shellcheck disable=SC2046
changedFiles=$(git diff $(git merge-base HEAD $baseBranch) --relative --name-only --diff-filter=d -- '*.js' '*.jsx' '*.ts' '*.tsx')

# Only complete this if changed files are detected.
if [[ -z $changedFiles ]]; then
    echo "No changed files detected."
    exit 0
fi

# Prints the folder, relative to the plugin root, of the nearest eslint config at or above a file.
owner_of() {
    local dir
    dir=$(dirname "$1")
    while [[ $dir != "." && $dir != "/" ]]; do
        if [[ -f "$dir/eslint.config.mjs" || -f "$dir/eslint.config.js" || -f "$dir/eslint.config.cjs" ]]; then
            echo "$dir"
            return
        fi
        dir=$(dirname "$dir")
    done
    echo "."
}

fileOwners=""
while IFS= read -r file; do
    fileOwners+=$(printf '%s\t%s' "$(owner_of "$file")" "$file")$'\n'
done <<< "$changedFiles"

status=0
while IFS= read -r owner; do
    files=()
    while IFS= read -r file; do
        if [[ $owner == "." ]]; then
            files+=("$file")
        else
            files+=("${file#"$owner"/}")
        fi
    done < <(printf '%s' "$fileOwners" | awk -F'\t' -v owner="$owner" '$1 == owner { print $2 }')

    # A changed client/blocks file is linted with that package's own flat config,
    # whose import/webpack resolver loads its webpack.config.js. That config only
    # exports an iterable when WP_EXPERIMENTAL_MODULES is set, matching its lint:js.
    (cd "$owner" && WP_EXPERIMENTAL_MODULES=true pnpm eslint "${files[@]}") || status=1
done < <(printf '%s' "$fileOwners" | cut -f1 | sort -u)

exit $status
