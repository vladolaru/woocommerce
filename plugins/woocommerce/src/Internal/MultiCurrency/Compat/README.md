# WooPayments MultiCurrency compatibility

This folder is the removable boundary for the plugin-owned `WCPay\MultiCurrency\MultiCurrency` facade that Woo extensions use when the core-native multi-currency runtime owns conversion. `LegacyMultiCurrencyFacadeLoader` declares the facade only after active plugins have loaded and only when `MultiCurrencyRuntimeArbiter` selects Core. The standalone WooPayments plugin retains the namespace while it owns the runtime. Other plugin-shaped WooPayments globals live in the separate [payments compatibility boundary](../../Payments/Providers/WooPayments/Compat/README.md).

## Contract

| Facade method | Native behavior | Known Woo-owned consumers |
|---|---|---|
| `instance(): self` | Returns one compatibility facade instance for the request. | WooCommerce Deposits, WooCommerce Table Rate Shipping |
| `get_price( $amount, string $type ): float` | Delegates to `MultiCurrencyPriceProjectionService`. A successful `product` projection records request-local provenance so Deposits does not project the same fixed amount again. | WooCommerce Deposits |
| `get_selected_currency(): MultiCurrencyCurrency` | Reads the selected currency from the native request state. | WooCommerce Table Rate Shipping |
| `get_default_currency(): MultiCurrencyCurrency` | Reads the store currency from the native request state. | WooCommerce Table Rate Shipping |
| `get_raw_conversion( float $amount, string $to_currency, string $from_currency = '' ): float` | Delegates to `MultiCurrencyPriceProjectionService`; an omitted source currency means the native store currency. | WooCommerce Table Rate Shipping |

Every facade method emits a deprecation notice introduced in WooCommerce 11.0.0. Native services own all state and conversion math; this boundary only preserves observed extension call shapes.

## Deposits conversion provenance

WooCommerce Deposits projects a fixed deposit through `get_price( ..., 'product' )` before WooCommerce's own Deposits compatibility controller sees the cart item. The loader marks that successful projection for the current request. `MultiCurrencyDepositsCompatibilityController` preserves the incoming cart values when the marker is set, ensuring the selected-currency rate is applied once rather than twice. Failed projections do not set the marker, so the native fallback remains available.

## Placement invariant

`LegacyWooPaymentsCompatibilityPlacementTest` scans production PHP under `src/` and `includes/` and rejects global `WC_Payments*` or namespaced `WCPay\` type declarations outside a `Compat/` directory. It also requires exactly one composition-root registration for each compatibility loader and a removal README for each boundary. New plugin-shaped compatibility surfaces belong here only when grounded in an observed consumer contract and must delegate to native services.

## Removal

This facade is scheduled for removal in WooCommerce 12.0.0 after supported extensions no longer call the plugin-owned namespace. Remove these pieces together:

- This production `Compat/` folder.
- The `LegacyMultiCurrencyFacadeLoader` registration line in `includes/class-woocommerce.php`.
- The `LegacyMultiCurrencyFacadeLoader` import and request-provenance check in `MultiCurrencyDepositsCompatibilityController`.
- The matching `tests/php/src/Internal/MultiCurrency/Compat/` test and fixture.
- This boundary's expected symbol, loader and README entries in `LegacyWooPaymentsCompatibilityPlacementTest`.

Keep the native projection and state services; they are not compatibility code.
