# WooPayments legacy facade compatibility

This folder is the removable boundary for plugin-owned compatibility surfaces that existing WooPayments links and Woo extensions still depend on. `LegacyFacadeLoader` loads global symbols only when the core-native runtime owns payments; the standalone WooPayments plugin retains every symbol while it owns the runtime. `LegacyAdminLinkHandler` accepts platform-issued `wcpay-link-handler` admin links under the same ownership rule. `WooPaymentsCompatClassAliases` declares two of the plugin's class names as aliases of the native classes where those classes first reach a hook or WooCommerce Subscriptions: `WCPay\Constants\Payment_Type`, the third argument of `wcpay_metadata_from_order`, and `WC_Payments_Email_Failed_Authentication_Retry`, the retry email class WooCommerce Subscriptions instantiates by name. An autoloader that can load the plugin's own class wins. The plugin's request and response classes (`WCPay\Core\Server\*`) are not declared: the request filters pass the provider's own request objects. The facade class files live in `includes/legacy/woopayments-compat/` (see the placement invariant). The plugin-shaped `WCPay\MultiCurrency` namespace lives in the separate [multi-currency compatibility boundary](../../../../MultiCurrency/Compat/README.md).

## Contract

| Symbol | Native behavior | Known Woo-owned consumers |
|---|---|---|
| `WC_Payments` | Makes existence gates recognize the active native runtime. `get_gateway()` returns the dependency-injection container's `WooPaymentsGateway`; `hide_gateways_on_settings_page()` is a safe no-op because native does not expose secondary plugin gateway classes. Both methods emit deprecation notices. | WooCommerce Core Blueprint exporter, All Products for WooCommerce Subscriptions, AutomateWoo, WooCommerce PayPal Payments |
| `WC_Payments_Features` | Makes the All Products for WooCommerce Subscriptions gate safe. `is_wcpay_subscriptions_enabled()` returns `false` because native subscription support is exposed by gateway capabilities, and emits a deprecation notice. | All Products for WooCommerce Subscriptions |
| `WCPAY_VERSION_NUMBER` | Reports `WooPaymentsClientVersion::VERSION`, the plugin version whose platform contract the native runtime implements. | All Products for WooCommerce Subscriptions |

`LegacyAdminLinkHandler` preserves authenticated platform email links by forwarding their arguments to the native user-token `links` API and safely redirecting the merchant to the returned URL. Access still requires `manage_woocommerce`; API failures return to the native overview with `wcpay-server-link-error=1`. It also records `wcpay_kyc_reminder_merchant_returned` for the platform's KYC reminder email links (`page=wc-admin&path=/payments/connect&wcpay-connect-redirect=<reminder>`) and redirects them to the native route for the legacy connect page. The plugin's WordPress.com reconnect link (`wcpay-reconnect-wpcom=1` with a `wcpay-reconnect-wpcom` nonce) restarts the Jetpack authorization flow and returns to the native settings; a failed site registration lands on Overview with `wcpay-server-link-error=1`.

The boundary intentionally does not declare, alias, or emulate `WC_Payment_Gateway_WCPay`. The native gateway preserves the `woocommerce_payments` gateway ID and WooCommerce capability contract, but it must not pretend to be the standalone plugin's concrete gateway class.

## Activation safety

WordPress sandbox-includes a plugin before adding it to the active-plugin list and provides no pre-sandbox activation hook, and the plugin's main file declares `WC_Payments_Features` as it loads. The loader therefore declares the two facade classes lazily, through an autoloader registered at `plugins_loaded` priority 0, so an activation that nothing in the request probed first leaves both names to the plugin, including activation paths with no recognizable request shape (an Action Scheduler activation callback, `activate_plugin()` from code). Because some extensions probe `WC_Payments_Features` early in every request, WooPayments activation requests the loader recognizes register no autoloader at all: the Plugins screen, bulk actions, installer AJAX, the core plugins REST controller, WooCommerce's `/wc-admin/plugins/activate` and `/wc-admin/plugins/install` routes, WooCommerce's `PluginsInstaller` URL, and WooPayments `activate`, `install --activate` and `toggle` commands in WP-CLI. Probing `WC_Payments` loads only that facade, which never references `WC_Payments_Features`. Ordinary and unrelated requests keep the facades available on use.

## Placement invariant

`LegacyWooPaymentsCompatibilityPlacementTest` requires every global `WC_Payments*` or namespaced `WCPay\` type declaration to live in `includes/legacy/woopayments-compat/` or `includes/legacy/woopayments-multi-currency-compat/`, outside every tree Composer scans for its classmaps: `src/` and `includes/rest-api/`, and `tests/php/src/` for development installs. The Jetpack autoloader's optimized classmap, built on every `composer install` and for the release zip, maps each class it finds in those trees whatever its namespace, so a facade placed there would be declared on first use on every store, plugin-owned ones included; outside them, only the loaders can declare the facades. Test fixtures that declare plugin-shaped names use the `.fixture` extension, which Composer does not scan. The test also requires exactly one composition-root registration for each compatibility loader and a removal README for each boundary. When a boundary is removed, update its expected loader and README entries in the same removal commit; do not move the facades back into a scanned tree.

## Removal

These compatibility surfaces are scheduled for removal in WooCommerce 12.0.0 after supported extensions no longer depend on the plugin-owned symbols and the platform no longer emits legacy admin links. Remove these pieces together:

- This production `Compat/` folder and the facade files in `includes/legacy/woopayments-compat/`.
- The `LegacyFacadeLoader` registration line in `includes/class-woocommerce.php`.
- The `LegacyAdminLinkHandler::class` root in `WooPaymentsProvider`.
- The `LegacyAdminLinkHandler` bootstrap-root expectations in `tests/php/src/Internal/Payments/PaymentsBootstrapTest.php`.
- The matching `tests/php/src/Internal/Payments/Providers/WooPayments/Compat/` tests and fixtures.
- This boundary's expected symbols, loader and README entries in `LegacyWooPaymentsCompatibilityPlacementTest`.
- The two call sites of `WooPaymentsCompatClassAliases`, which declares `WCPay\Constants\Payment_Type` and `WC_Payments_Email_Failed_Authentication_Retry` as aliases of the native classes on first use. `git grep -n WooPaymentsCompatClassAliases -- src/Internal/Payments` lists them: `WooPaymentsIntentRequestBuilder::metadata_from_order()` and `Subscriptions/WooPaymentsFailedRenewalAuthenticationEmail::set_store_owner_custom_email()`.
