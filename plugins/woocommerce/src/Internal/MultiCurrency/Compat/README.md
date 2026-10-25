# WooPayments extension Multi-Currency compatibility

This folder holds the WooPayments extension's `WCPay\MultiCurrency\MultiCurrency` class that Woo extensions call, answered by WooCommerce's Multi-Currency while it runs Multi-Currency. `WooPaymentsExtensionMultiCurrencyFacadeLoader` declares the facade only after active plugins have loaded (`plugins_loaded` priority 0) and only when `MultiCurrencyRuntimeArbiter::is_builtin_owner()` is true; while the WooPayments extension runs Multi-Currency, the namespace is the extension's own. The facade class file lives in `includes/legacy/woopayments-multi-currency-compat/` (see the placement rule). The extension's other classes and globals live in the separate [payments compatibility boundary](../../Payments/Providers/WooPayments/Compat/README.md). It is removed in WooCommerce 12.0.0.

## Contract

| Facade method | Behavior | Known Woo-owned consumers |
|---|---|---|
| `instance(): self` | Returns one facade instance for the request. | WooCommerce Deposits, WooCommerce Table Rate Shipping |
| `get_price( $amount, string $type ): float` | Converts through `MultiCurrencyPriceConverter`. A converted `product` price is recorded for the request, so the Deposits controller does not convert the same fixed amount again. | WooCommerce Deposits |
| `get_selected_currency(): MultiCurrencyCurrency` | Reads the selected currency from the request's Multi-Currency state. | WooCommerce Table Rate Shipping |
| `get_default_currency(): MultiCurrencyCurrency` | Reads the store currency from the request's Multi-Currency state. | WooCommerce Table Rate Shipping |
| `get_raw_conversion( float $amount, string $to_currency, string $from_currency = '' ): float` | Converts through `MultiCurrencyPriceConverter`; an omitted source currency means the store currency. | WooCommerce Table Rate Shipping |

Every facade method emits a deprecation notice with the version 11.0.0. The Multi-Currency services own all state and conversion math; the facade only answers the call shapes extensions are known to use.

## Deposits conversion provenance

WooCommerce Deposits converts a fixed deposit through `get_price( ..., 'product' )` before the Deposits compatibility controller sees the cart item. The loader records that conversion for the current request. `MultiCurrencyDepositsCompatibilityController` leaves the incoming cart values alone when the record is set, so the selected currency's rate is applied once rather than twice. A conversion that throws records nothing, so the controller still converts the amount.

## Placement rule

`WooPaymentsExtensionFacadePlacementTest` requires every global `WC_Payments*` or namespaced `WCPay\` type declaration to live in `includes/legacy/woopayments-compat/` or `includes/legacy/woopayments-multi-currency-compat/`, outside every tree Composer scans for its classmaps: `src/` and `includes/rest-api/`, and `tests/php/src/` for development installs. The Jetpack autoloader's optimized classmap, built on every `composer install` and for the release zip, maps each class it finds in those trees whatever its namespace, so a facade placed there would be declared on first use on every store, including stores where the WooPayments extension runs; outside them, only the loaders can declare the facades. Test fixtures that declare the extension's class names use the `.fixture` extension, which Composer does not scan. The test also requires exactly one composition-root registration for each compatibility loader. A new compatibility surface belongs here only for a consumer contract observed in a Woo extension, and it must call the Multi-Currency services.

## Removed in WooCommerce 12.0.0

Remove these pieces together once supported extensions no longer call the `WCPay\MultiCurrency` namespace:

- This `Compat/` folder and the facade file in `includes/legacy/woopayments-multi-currency-compat/`.
- The one registration line, `WooPaymentsExtensionMultiCurrencyFacadeLoader::register()` in `includes/class-woocommerce.php`.
- The `WooPaymentsExtensionMultiCurrencyFacadeLoader` import and the provenance check in `MultiCurrencyDepositsCompatibilityController`.
- The test and its fixture in `tests/php/src/Internal/MultiCurrency/Compat/`.
- This boundary's expected symbol and loader entries in `WooPaymentsExtensionFacadePlacementTest`.
- The WooPayments extension's asset handles registered as aliases outside this folder: `wcpay-multi-currency-async-renderer` (script and style, `MultiCurrencyAsyncPriceRendererController::COMPAT_SCRIPT_HANDLE`), the switcher block's editor script handle (`MultiCurrencySwitcherBlockController::COMPAT_EDITOR_SCRIPT_HANDLE`) and `WCPAY_MULTI_CURRENCY_ANALYTICS` (`MultiCurrencyAnalyticsController`).

Keep the Multi-Currency conversion and state services; they are not compatibility code.
