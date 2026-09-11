# WooPayments extension compatibility pins

The WooPayments extension compatibility matrix is an opt-in profile. It installs exact extension sources into profile-owned plugin directories, runs only the `woopayments-native-extension-compat` Playwright project, and never joins the default WooCommerce test projects. The profile must target a disposable environment; the local runner rejects the manual native store on port `8889`.

## Pin matrix

These pins were last derived from the extension repositories on 2026-09-10.

| Extension | Dependency origin | Oldest executable pin | Latest pin | Covered contract |
| --- | --- | --- | --- | --- |
| WooCommerce Subscriptions | `7.5.0` | `7.5.0` | `9.2.0` | Native gateway supports, renewal payment method, payment-method changes, and APFS integration where available |
| WooCommerce Bookings | `2.0.9` | `3.5.3` | `3.10.0` | Native express methods stay unavailable for bookable products and carts |
| WooCommerce Deposits | `2.2.9` | `2.2.9` | `2.4.7` | Express line items stay hidden and fixed deposits are converted exactly once |
| WooCommerce Square | `4.7.4` | `4.7.4` | `5.5.0` | Native express methods stay unavailable for Square gift-card products and carts |
| WooCommerce PayPal Payments | `2.9.6` | `2.9.6` | `4.1.3` | PayPal does not offer Apple Pay or Google Pay alongside native WooPayments |

Bookings `2.0.9` is the historical dependency origin for the express-filter integration, but it is not executable on the matrix's current MariaDB because its availability-table schema uses the unquoted reserved identifier `to_date`. Bookings `3.5.3` is the first stable release after that origin whose installer and availability queries quote the identifier, so it is the executable oldest pin. The profile does not patch third-party extension code or schemas.

## Source builds

Every pin finishes with production-only Composer dependencies:

```sh
composer install --no-dev --prefer-dist --no-interaction --no-progress --optimize-autoloader
```

Bookings releases that use Strauss need that build-only dependency while generating their prefixed production packages. The profile detects the release script, installs the locked build tools, runs the release scripts, and then removes dev dependencies without rerunning those scripts:

```sh
composer install --prefer-dist --no-interaction --no-progress --optimize-autoloader
composer install --no-dev --prefer-dist --no-interaction --no-progress --optimize-autoloader --no-scripts
```

WooCommerce Square also requires its locked frontend build because activation expects an image copied into `build/images/`:

```sh
npm ci --no-audit --no-fund
npm run build:webpack
```

The matrix scenarios exercise server-side compatibility contracts for the other four extensions, and their checked-in runtime assets are sufficient. PayPal Payments `2.9.6` guards its competing card and wallet onboarding inside the extension's feature-gated new-settings graph, so compatibility probes set `PCP_SETTINGS_ENABLED=1` before WordPress bootstrap for both PayPal pins. Build logs remain quiet on success and are printed on failure.

## Local profile

Start the tracked disposable E2E environment from `plugins/woocommerce/`, then prepare either exact pin set:

```sh
pnpm wp-env:e2e start

tests/e2e/envs/woopayments-native/extensions-profile.sh \
	--pin-set oldest \
	--store-url http://localhost:8086 \
	--wp-env-config .wp-env.e2e.json \
	--wp-env-service cli
```

Use `--pin-set latest` for the latest side of the matrix. Private repositories use the operator's normal non-interactive Git credentials locally. `E2E_WOOPAYMENTS_EXTENSION_READ_TOKEN` is optional locally and, when present, is passed only as a GitHub request header rather than embedded in a clone URL.

Run the reserved Playwright project explicitly after the profile is active:

```sh
E2E_WOOPAYMENTS_EXTENSION_COMPAT=true \
E2E_WOOPAYMENTS_EXTENSION_WP_ENV_CONFIG=.wp-env.e2e.json \
E2E_WOOPAYMENTS_EXTENSION_WP_ENV_SERVICE=cli \
pnpm exec playwright test \
	--config=tests/e2e/envs/woopayments-native/playwright.config.ts \
	--project=woopayments-native-extension-compat \
	tests/e2e/tests/woopayments-native/compat
```

Unless `E2E_WOOPAYMENTS_EXTENSION_COMPAT` is exactly `true`, every compatibility spec skips itself. The profile project is serialized to one worker because the scenarios share WordPress plugin and option state. The explicit compat-directory selector keeps this matrix focused on the five pin-pair specs; other extension-tagged release smokes retain their own environment contract.

## CI profile

The `woopayments-native-extension-compat` CI job runs the oldest and latest profiles serially against `.wp-env.e2e.json`. It is limited to same-repository pull requests in `woocommerce/woocommerce`, remains outside the required `evaluate-project-jobs` gate until it completes three green runs, and reads private extension sources through the `E2E_WOOPAYMENTS_EXTENSION_READ_TOKEN` repository secret. Fork pull requests skip the job because GitHub does not expose repository secrets to them.

## Pin refresh rule

Refresh the latest pins deliberately from stable repository tags and run the complete oldest and latest matrix before changing this table. Move an oldest pin only when the recorded version cannot install or run on the supported matrix environment; retain the original dependency version in the dependency-origin column and document the incompatibility. Do not add compatibility shims that alter extension source or production database schemas.
