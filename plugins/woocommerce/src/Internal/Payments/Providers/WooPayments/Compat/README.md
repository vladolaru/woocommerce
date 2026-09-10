# WooPayments legacy facade compatibility

This folder is the removable boundary for plugin-owned global symbols that Woo extensions still use to detect WooPayments. `LegacyFacadeLoader` loads them only when the core-native runtime owns payments; the standalone WooPayments plugin retains every symbol while it owns the runtime.

## Contract

| Symbol | Native behavior | Known Woo-owned consumers |
|---|---|---|
| `WC_Payments` | Makes existence gates recognize the active native runtime. `get_gateway()` returns the dependency-injection container's `NativeWooPaymentsGateway` and emits a deprecation notice. | All Products for WooCommerce Subscriptions, AutomateWoo, WooCommerce PayPal Payments |
| `WC_Payments_Features` | Makes the All Products for WooCommerce Subscriptions gate safe. `is_wcpay_subscriptions_enabled()` returns `false` because native subscription support is exposed by gateway capabilities, and emits a deprecation notice. | All Products for WooCommerce Subscriptions |
| `WCPAY_VERSION_NUMBER` | Reports `WooPaymentsClientVersion::VERSION`, the plugin version whose platform contract the native runtime implements. | All Products for WooCommerce Subscriptions |

The boundary intentionally does not declare, alias, or emulate `WC_Payment_Gateway_WCPay`. The native gateway preserves the `woocommerce_payments` gateway ID and WooCommerce capability contract, but it must not pretend to be the standalone plugin's concrete gateway class.

## Activation safety

WordPress sandbox-includes a plugin before adding it to the active-plugin list and provides no pre-sandbox activation hook. The loader therefore waits until `plugins_loaded` priority 0 and leaves the global names free during WooPayments activation requests from the Plugins screen, bulk actions, installer AJAX, the core plugins REST controller, and WooPayments activation/install commands in WP-CLI. Ordinary and unrelated WP-CLI commands retain the native facades. A programmatic activator whose later `activate_plugin()` call has no recognizable WordPress request shape must return `false` from `woocommerce_native_payments_should_load_legacy_facades` before `plugins_loaded` priority 0.

## Removal

These facades are scheduled for removal in WooCommerce 12.0.0 after supported extension versions no longer depend on the plugin-owned symbols. Remove this `Compat` folder and the `LegacyFacadeLoader` registration line in `includes/class-woocommerce.php` together.
