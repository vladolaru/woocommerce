# WooPayments extension compatibility

This folder holds what Woo extensions still expect from the WooPayments extension while the built-in WooPayments owns payments: its `WC_Payments` and `WC_Payments_Features` classes (their files live in `includes/legacy/woopayments-compat/`), its `WCPAY_VERSION_NUMBER` constant and two of its class names. It is removed in WooCommerce 12.0.0.

## What it holds

- `WooPaymentsExtensionFacadeLoader` defines `WCPAY_VERSION_NUMBER` and declares the two facade classes on first use. It declares nothing in a recognized WooPayments activation request, and the extension's own classes win whenever it is active.
- `WooPaymentsCompatClassAliases` declares two WooPayments extension class names as aliases of the classes that answer for them where those classes first reach a hook or WooCommerce Subscriptions: `WCPay\Constants\Payment_Type`, the third argument of `wcpay_metadata_from_order`, and `WC_Payments_Email_Failed_Authentication_Retry`, the retry email class WooCommerce Subscriptions instantiates by name. An autoloader that can load the extension's own class wins.
- The facade classes live in `includes/legacy/woopayments-compat/`, outside every tree Composer scans (see the placement rule).
- The extension's request and response classes (`WCPay\Core\Server\*`) and `WC_Payment_Gateway_WCPay` are not declared: the built-in WooPayments passes its own request objects to the request filters and keeps the `woocommerce_payments` gateway ID.
- The `WCPay\MultiCurrency` namespace lives in the [Multi-Currency compatibility boundary](../../../../MultiCurrency/Compat/README.md).

## Contract

| Symbol | Behavior | Known Woo-owned consumers |
|---|---|---|
| `WC_Payments` | Existence checks recognize the built-in WooPayments. `get_gateway()` returns the container's `WooPaymentsGateway`. `hide_gateways_on_settings_page()` does nothing, because there are no secondary gateway classes to hide. Both emit a deprecation notice. | WooCommerce Core Blueprint exporter, All Products for WooCommerce Subscriptions, AutomateWoo, WooCommerce PayPal Payments |
| `WC_Payments_Features` | `is_wcpay_subscriptions_enabled()` returns `false` and emits a deprecation notice; the gateway's capabilities answer subscription support. | All Products for WooCommerce Subscriptions |
| `WCPAY_VERSION_NUMBER` | Reports `WooPaymentsClientVersion::VERSION`, the extension version whose platform contract the built-in WooPayments implements. | All Products for WooCommerce Subscriptions |

## Activation safety

WordPress includes a plugin in a sandbox before adding it to the active list and provides no hook before that, and the WooPayments extension declares `WC_Payments_Features` as it loads. The loader therefore declares the facades through an autoloader registered at `plugins_loaded` priority 0, so an activation that nothing probed first leaves both names to the extension, including activation paths with no recognizable request shape (an Action Scheduler callback, `activate_plugin()` from code).

Some extensions probe `WC_Payments_Features` early in every request, so the loader registers no autoloader at all in a request it recognizes as a WooPayments activation: the sandbox scrape, the Plugins screen, bulk actions, installer AJAX, the core plugins REST controller, WooCommerce's `/wc-admin/plugins/activate` and `/wc-admin/plugins/install` routes, WooCommerce's `PluginsInstaller` URL, and WooPayments `activate`, `install --activate` and `toggle` commands in WP-CLI. Probing `WC_Payments` loads only that facade, which never references `WC_Payments_Features`.

## Placement rule

`WooPaymentsExtensionFacadePlacementTest` requires every global `WC_Payments*` or namespaced `WCPay\` type declaration to live in `includes/legacy/woopayments-compat/` or `includes/legacy/woopayments-multi-currency-compat/`. Composer's optimized classmaps map every class in a scanned tree whatever its namespace (`src/`, `includes/rest-api/`, and `tests/php/src/` for development installs), so a facade placed there would be declared on first use on every store, extension-owned ones included. Test fixtures that declare these names use the `.fixture` extension, which Composer does not scan. The test also requires exactly one registration of each loader in the composition root and a removal README for each boundary.

## Removed in WooCommerce 12.0.0

Remove these pieces together once supported extensions no longer depend on the WooPayments extension's symbols:

- This `Compat/` folder.
- The facade files in `includes/legacy/woopayments-compat/`.
- The one registration line, `WooPaymentsExtensionFacadeLoader::register()` in `includes/class-woocommerce.php`.
- The two alias first-use sites, which call `WooPaymentsCompatClassAliases::register_compat_class_alias()`: `WooPaymentsIntentRequestBuilder::metadata_from_order()` and `Subscriptions/WooPaymentsFailedRenewalAuthenticationEmail::set_store_owner_custom_email()`. `git grep -n WooPaymentsCompatClassAliases -- src/Internal/Payments` lists them.
- The tests in `tests/php/src/Internal/Payments/Providers/WooPayments/Compat/`, with their `.fixture` files.
- This boundary's expected symbols, loader and README entries in `WooPaymentsExtensionFacadePlacementTest`, in the same removal commit.
- The extension's order-note wordings, which the built-in WooPayments renders so notes the extension wrote are recognised: the `woocommerce-payments` wordings in `WooPaymentsOrderNoteService` with its `extension_wordings()`, `WooPaymentsOrderNoteWordingSnapshot` with the cutover job's `capture_note_wordings()` calls, and the snapshot's stored option, `woocommerce_woopayments_note_wordings`, which the removal release deletes.
