# WooPayments extension compatibility pins

The WooPayments extension compatibility matrix is an opt-in local profile. It installs the released packages of exact extension versions, runs only the `woopayments-native-extension-compat` Playwright project, and never joins the default WooCommerce test projects. The profile must target a disposable environment; the local runner rejects the manual native store on port `8889`.

## Pin matrix

These pins were last derived from the extension repositories on 2026-09-10.

| Extension | Dependency origin | Oldest executable pin | Latest pin | Covered contract |
| --- | --- | --- | --- | --- |
| WooCommerce Subscriptions | `7.5.0` | `7.5.0` | `9.2.0` | Native gateway supports, renewal payment method, payment-method changes, and APFS integration where available |
| WooCommerce Bookings | `2.0.9` | `3.5.3` | `3.10.0` | Native express methods stay unavailable for bookable products and carts |
| WooCommerce Deposits | `2.2.9` | `2.2.9` | `2.4.7` | The product-page express payload drops its line items (and keeps its total) while the cart holds a deposit, and keeps them when the deposit is declined; a fixed deposit added through the cart is converted to the shopper's currency once, on the cart line and in the cart total |
| WooCommerce Square | `4.7.4` | `4.7.4` | `5.5.0` | Native express methods stay unavailable for Square gift-card products and carts |
| WooCommerce PayPal Payments | `2.9.6` | `2.9.6` | `4.1.3` | PayPal does not offer Apple Pay or Google Pay alongside native WooPayments |

Bookings `2.0.9` is the historical dependency origin for the express-filter integration, but it is not executable on the matrix's current MariaDB because its availability-table schema uses the unquoted reserved identifier `to_date`. Bookings `3.5.3` is the first stable release after that origin whose installer and availability queries quote the identifier, so it is the executable oldest pin. The profile does not patch third-party extension code or schemas.

## Packages

The profile installs each pin's released package, the one merchants install, so nothing is built from source. Each package goes into its canonical plugin directory (for example `wp-content/plugins/woocommerce-subscriptions/`), replacing any copy already there.

- WooCommerce Square and WooCommerce PayPal Payments come from WordPress.org: the profile runs `wp plugin install https://downloads.wordpress.org/plugin/<slug>.<pin>.zip --force` in the selected wp-env service. It passes the versioned zip URL because `wp-env run` would take a `--version` flag as its own option.
- WooCommerce Subscriptions, WooCommerce Bookings and WooCommerce Deposits come from WooCommerce.com. Set `E2E_WOOPAYMENTS_EXTENSION_ZIPS` to a directory holding each pin's release zip named `<slug>.<version>.zip`, for example `woocommerce-subscriptions.7.5.0.zip`. When that variable is unset and a `wccom-ext` command is on `PATH`, the profile runs `wccom-ext get <slug> <version>` and copies in the plugin directory it prints. Otherwise the profile stops and names the zip it needs.

The released packages ship their compiled assets, including the PayPal Payments settings script that the Subscriptions settings-tab smoke in `merchant/payouts-disputes-smoke.spec.ts` loads. PayPal Payments `2.9.6` keeps its competing card and wallet onboarding and its settings module behind the feature-gated new-settings graph, so the compatibility probes set `PCP_SETTINGS_ENABLED=1` before WordPress bootstrap for both PayPal pins.

## Running the matrix

The matrix runs locally; no CI job runs it. Run each pin set on a fresh disposable E2E environment, from `plugins/woocommerce/`, with `TMPDIR` set and the WooCommerce.com packages available (see Packages):

```sh
export WCPAY_RUNTIME=native E2E_WOOPAYMENTS_EXTENSION_COMPAT=true
export E2E_WOOPAYMENTS_NATIVE_STORE_DIR="$PWD" E2E_WOOPAYMENTS_NATIVE_STORE_URL=http://localhost:8086
export E2E_WOOPAYMENTS_WP_ENV_CONFIG=.wp-env.e2e.json BASE_URL=http://localhost:8086 WP_BASE_URL=http://localhost:8086

pnpm env:e2e:restart
bash tests/e2e/envs/woopayments-native/fresh-activation-smoke.sh

tests/e2e/envs/woopayments-native/extensions-profile.sh \
	--pin-set oldest \
	--store-url http://localhost:8086 \
	--wp-env-config .wp-env.e2e.json \
	--wp-env-service cli

pnpm exec playwright test \
	--config=tests/e2e/envs/woopayments-native/playwright.config.ts \
	--project=woopayments-native-extension-compat \
	tests/e2e/tests/woopayments-native/compat \
	tests/e2e/tests/woopayments-native/merchant/payouts-disputes-smoke.spec.ts
```

Repeat from `pnpm env:e2e:restart` with `--pin-set latest`. Each pin set runs six cases: the five compat specs and the Subscriptions settings-tab smoke. The profile prints one `<extension> <version> active` line per extension and records the pin set in the `e2e_woopayments_extension_compat_profile` option the specs read.

The probes run through the shared `wpCLI`/`wpEvalJson` helpers, which target `.wp-env.e2e.json`'s `cli` service unless `E2E_WP_ENV_CONFIG` names another config. Unless `E2E_WOOPAYMENTS_EXTENSION_COMPAT` is exactly `true`, every compatibility spec skips itself. The profile project is serialized to one worker because the scenarios share WordPress plugin and option state.

## Pin refresh rule

Refresh the latest pins deliberately from released versions on WordPress.org and WooCommerce.com and run the complete oldest and latest matrix before changing this table. Move an oldest pin only when the recorded version cannot install or run on the supported matrix environment; retain the original dependency version in the dependency-origin column and document the incompatibility. Do not add compatibility shims that alter extension source or production database schemas.
