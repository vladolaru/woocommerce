#!/usr/bin/env bash

set -euo pipefail

readonly SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd -P)"
readonly RUNNER="$SCRIPT_DIR/extensions-profile.sh"
readonly TEST_ROOT="$(mktemp -d "${TMPDIR:?TMPDIR is required}/woopayments-extension-profile.XXXXXX")"
trap 'rm -rf "$TEST_ROOT"' EXIT

fail() {
	echo "$1" >&2
	exit 1
}

[[ -x "$RUNNER" ]] || fail 'The extension compatibility profile runner is missing or not executable.'

mkdir -p "$TEST_ROOT/bin" "$TEST_ROOT/tmp"
touch "$TEST_ROOT/git.log" "$TEST_ROOT/composer.log" "$TEST_ROOT/npm.log" "$TEST_ROOT/pnpm.log"

cat > "$TEST_ROOT/bin/git" <<'FAKE_GIT'
#!/usr/bin/env bash
set -euo pipefail

printf '%s\n' "$*" >> "${E2E_FAKE_GIT_LOG:?}"
[[ "$1" == 'clone' ]] || exit 0

tag=''
for (( index = 1; index <= $#; index++ )); do
	if [[ "${!index}" == '--branch' ]]; then
		next=$(( index + 1 ))
		tag="${!next}"
		break
	fi
done
[[ -n "$tag" ]] || exit 2

destination="${!#}"
directory_name="$(basename "$destination")"
case "$directory_name" in
	woocommerce-subscriptions) main_file='woocommerce-subscriptions.php' ;;
	woocommerce-bookings) main_file='woocommerce-bookings.php' ;;
	woocommerce-deposits) main_file='woocommerce-deposits.php' ;;
	woocommerce-square) main_file='woocommerce-square.php' ;;
	woocommerce-paypal-payments) main_file='woocommerce-paypal-payments.php' ;;
	*) exit 3 ;;
esac

mkdir -p "$destination"
if [[ "$directory_name" == 'woocommerce-bookings' && "$tag" == '3.10.0' ]]; then
	printf '{"scripts":{"post-install-cmd":["@php vendor/bin/strauss"]}}\n' > "$destination/composer.json"
else
	printf '{"require":{"php":"*"}}\n' > "$destination/composer.json"
fi
printf '<?php\n/*\nPlugin Name: Fake extension\nVersion: %s\n*/\n' "$tag" > "$destination/$main_file"
FAKE_GIT
chmod +x "$TEST_ROOT/bin/git"

cat > "$TEST_ROOT/bin/composer" <<'FAKE_COMPOSER'
#!/usr/bin/env bash
set -euo pipefail

printf '%s\t%s\n' "$PWD" "$*" >> "${E2E_FAKE_COMPOSER_LOG:?}"
if grep -Fq 'vendor/bin/strauss' composer.json && [[ " $* " == *' --no-dev '* && " $* " != *' --no-scripts '* ]]; then
	exit 8
fi
mkdir -p vendor
touch vendor/autoload.php
FAKE_COMPOSER
chmod +x "$TEST_ROOT/bin/composer"

cat > "$TEST_ROOT/bin/npm" <<'FAKE_NPM'
#!/usr/bin/env bash
set -euo pipefail

printf '%s\t%s\n' "$PWD" "$*" >> "${E2E_FAKE_NPM_LOG:?}"
if [[ "$*" == 'run build:webpack' ]]; then
	mkdir -p build/images
	touch build/images/gift-card-featured-image.png
fi
FAKE_NPM
chmod +x "$TEST_ROOT/bin/npm"

cat > "$TEST_ROOT/bin/pnpm" <<'FAKE_PNPM'
#!/usr/bin/env bash
set -euo pipefail

printf '%s\n' "$*" >> "${E2E_FAKE_PNPM_LOG:?}"
joined=" $* "

if [[ "$joined" == *' wp option get siteurl '* ]]; then
	printf '%s\n' "${E2E_FAKE_STORE_URL:?}"
	exit 0
fi

if [[ "$joined" == *' tar -xf - '* ]]; then
	cat > /dev/null
	exit 0
fi

if [[ "$joined" == *' wp plugin is-active '* ]]; then
	[[ "$joined" == *'woopayments-extension-compat-'* ]]
	exit
fi

if [[ "$joined" == *' wp plugin get '* ]]; then
	plugin="${joined#* wp plugin get }"
	plugin="${plugin%% *}"
	case "$plugin" in
		woopayments-extension-compat-subscriptions/*)
			[[ "${E2E_FAKE_PIN_SET:?}" == 'oldest' ]] && echo '7.5.0' || echo '9.2.0'
			;;
		woopayments-extension-compat-bookings/*)
			[[ "${E2E_FAKE_PIN_SET:?}" == 'oldest' ]] && echo '3.5.3' || echo '3.10.0'
			;;
		woopayments-extension-compat-deposits/*)
			[[ "${E2E_FAKE_PIN_SET:?}" == 'oldest' ]] && echo '2.2.9' || echo '2.4.7'
			;;
		woopayments-extension-compat-square/*)
			[[ "${E2E_FAKE_PIN_SET:?}" == 'oldest' ]] && echo '4.7.4' || echo '5.5.0'
			;;
		woopayments-extension-compat-paypal-payments/*)
			[[ "${E2E_FAKE_PIN_SET:?}" == 'oldest' ]] && echo '2.9.6' || echo '4.1.3'
			;;
		*) exit 4 ;;
	esac
	exit 0
fi
FAKE_PNPM
chmod +x "$TEST_ROOT/bin/pnpm"

run_profile() {
	local pin_set="$1"
	env \
		PATH="$TEST_ROOT/bin:$PATH" \
		TMPDIR="$TEST_ROOT/tmp" \
		E2E_FAKE_GIT_LOG="$TEST_ROOT/git.log" \
		E2E_FAKE_COMPOSER_LOG="$TEST_ROOT/composer.log" \
		E2E_FAKE_NPM_LOG="$TEST_ROOT/npm.log" \
		E2E_FAKE_PNPM_LOG="$TEST_ROOT/pnpm.log" \
		E2E_FAKE_STORE_URL='http://localhost:8086' \
		E2E_FAKE_PIN_SET="$pin_set" \
		"$RUNNER" \
			--pin-set "$pin_set" \
			--store-url 'http://localhost:8086' \
			--wp-env-config '.wp-env.e2e.json' \
			--wp-env-service 'cli'
}

run_profile oldest

for wp_env_config in .wp-env.json .wp-env.e2e.json; do
	jq -e '.config.WP_MEMORY_LIMIT == "512M" and .config.WP_MAX_MEMORY_LIMIT == "512M"' "$SCRIPT_DIR/../../../../$wp_env_config" > /dev/null || fail "$wp_env_config must give extension profiles a 512M WordPress memory ceiling."
done

for expected in \
	'woocommerce-subscriptions.git 7.5.0' \
	'woocommerce-bookings.git 3.5.3' \
	'woocommerce-deposits.git 2.2.9' \
	'woocommerce-square.git 4.7.4' \
	'woocommerce-paypal-payments.git 2.9.6'; do
	repo="${expected% *}"
	tag="${expected##* }"
	grep -Eq "clone .*--branch $tag .*${repo}" "$TEST_ROOT/git.log" || fail "Oldest profile did not clone $repo at $tag."
done

[[ "$(wc -l < "$TEST_ROOT/composer.log" | tr -d ' ')" -eq 5 ]] || fail 'Every oldest extension source must receive a production Composer install.'
[[ "$(wc -l < "$TEST_ROOT/npm.log" | tr -d ' ')" -eq 2 ]] || fail 'The Square source must receive its locked frontend build.'
grep -Fq $'woocommerce-square\tci' < <(sed 's#/.*/woocommerce-square#/woocommerce-square#' "$TEST_ROOT/npm.log") || fail 'The Square build must install its package-lock dependencies with npm ci.'
grep -Fq $'woocommerce-square\trun build:webpack' < <(sed 's#/.*/woocommerce-square#/woocommerce-square#' "$TEST_ROOT/npm.log") || fail 'The Square build must produce its runtime assets.'
grep -Fq -- '--config .wp-env.e2e.json run cli wp option get siteurl' "$TEST_ROOT/pnpm.log" || fail 'The profile did not verify the tracked E2E environment origin through its CLI service.'
if grep -Fq -- '--config .wp-env.e2e.json run tests-cli' "$TEST_ROOT/pnpm.log"; then
	fail 'The tracked E2E profile attempted to use the disabled tests environment.'
fi
for slug in subscriptions bookings deposits square paypal-payments; do
	grep -Fq "woopayments-extension-compat-$slug/" "$TEST_ROOT/pnpm.log" || fail "The profile did not activate its owned $slug slug."
done

: > "$TEST_ROOT/git.log"
: > "$TEST_ROOT/composer.log"
: > "$TEST_ROOT/npm.log"
: > "$TEST_ROOT/pnpm.log"
run_profile latest

for expected in \
	'woocommerce-subscriptions.git 9.2.0' \
	'woocommerce-bookings.git 3.10.0' \
	'woocommerce-deposits.git 2.4.7' \
	'woocommerce-square.git 5.5.0' \
	'woocommerce-paypal-payments.git 4.1.3'; do
	repo="${expected% *}"
	tag="${expected##* }"
	grep -Eq "clone .*--branch $tag .*${repo}" "$TEST_ROOT/git.log" || fail "Latest profile did not clone $repo at $tag."
done

normalized_composer_log="$(sed 's#/.*/woocommerce-bookings#/woocommerce-bookings#' "$TEST_ROOT/composer.log")"
grep -Fq $'woocommerce-bookings\tinstall --prefer-dist --no-interaction --no-progress --optimize-autoloader' <<< "$normalized_composer_log" || fail 'Latest Bookings must install its Strauss build tool before its release build.'
grep -Fq $'woocommerce-bookings\tinstall --no-dev --prefer-dist --no-interaction --no-progress --optimize-autoloader --no-scripts' <<< "$normalized_composer_log" || fail 'Latest Bookings must prune build-only dependencies without rerunning its release scripts.'

: > "$TEST_ROOT/git.log"
if env \
	PATH="$TEST_ROOT/bin:$PATH" \
	TMPDIR="$TEST_ROOT/tmp" \
	E2E_FAKE_GIT_LOG="$TEST_ROOT/git.log" \
	E2E_FAKE_COMPOSER_LOG="$TEST_ROOT/composer.log" \
	E2E_FAKE_NPM_LOG="$TEST_ROOT/npm.log" \
	E2E_FAKE_PNPM_LOG="$TEST_ROOT/pnpm.log" \
	E2E_FAKE_STORE_URL='http://store8889.localhost:8889' \
	E2E_FAKE_PIN_SET='oldest' \
	"$RUNNER" \
		--pin-set oldest \
		--store-url 'http://store8889.localhost:8889' \
		--wp-env-config '.wp-env.json' \
		--wp-env-service cli; then
	fail 'The profile accepted the forbidden manual native store on port 8889.'
fi
[[ ! -s "$TEST_ROOT/git.log" ]] || fail 'The forbidden-store guard ran after cloning external sources.'

echo 'Extension compatibility profile tests passed.'
