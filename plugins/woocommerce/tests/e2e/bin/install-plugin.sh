#!/bin/bash

set -eo pipefail

if [[ -z "$PLUGIN_REPOSITORY" ]]; then
	echo "::error::PLUGIN_REPOSITORY must be set"
	exit 1
fi

if [[ -z "$PLUGIN_NAME" ]]; then
	echo "::error::PLUGIN_NAME must be set"
	exit 1
fi

if [[ -z "$PLUGIN_SLUG" ]]; then
	echo "::error::PLUGIN_SLUG must be set"
	exit 1
fi

# Command prefix for running wp-cli against the single-container E2E environment
# (started via `wp-env --config .wp-env.e2e.json`, whose container is `cli`).
wp_cli="pnpm wp-env:e2e run cli"

echo "Installing $PLUGIN_NAME from $PLUGIN_REPOSITORY"
download_url=$( curl -s "https://api.github.com/repos/$PLUGIN_REPOSITORY/releases/latest" | grep browser_download_url | cut -d '"' -f 4 || true )
if [[ -z "$download_url" ]]; then
	# The unauthenticated API call is rate-limited per runner IP; the release download redirect is not.
	download_url="https://github.com/$PLUGIN_REPOSITORY/releases/latest/download/$PLUGIN_SLUG.zip"
	echo "Release API returned no asset; falling back to $download_url"
fi
$wp_cli wp plugin install "$download_url" --force --activate || ( sleep 5 && $wp_cli wp plugin install "$download_url" --force --activate )

$wp_cli wp plugin list
$wp_cli wp plugin is-active "$PLUGIN_SLUG" || ( echo "Plugin \"$PLUGIN_SLUG\" is not active!" && exit 1 )
