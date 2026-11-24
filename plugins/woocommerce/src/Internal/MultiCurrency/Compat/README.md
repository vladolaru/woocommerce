# WooPayments extension Multi-Currency compatibility

The global `WC_Payments_Multi_Currency()` and the classes below serve plugins written for the WooPayments extension. The loader declares them at `plugins_loaded` priority 0, or immediately if the action has run, when the built-in owns Multi-Currency and the request is not activating the WooPayments extension. The function and `MultiCurrency::instance()` return the same object. Affiliate uses it to convert commissions back to store currency. Hungarian Pickup Points tests for the function before using the singleton. The [payments compatibility boundary](../../Payments/Providers/WooPayments/Compat/README.md) owns the separate payments symbols.

## Contract

### `WCPay\MultiCurrency\MultiCurrency`

| Members | Answer | Known consumers |
|---|---|---|
| `CURRENCY_SESSION_KEY`, `CURRENCY_META_KEY`, `FILTER_PREFIX`, `CUSTOMER_CURRENCIES_KEY`, `RENDERING_MODE_SPEED`, `RENDERING_MODE_CACHE`, `RENDERING_MODE_OPTION`, `CACHE_AUTODETECT_DONE_OPTION`, `CACHE_RECOMMENDATION_DISMISSED_OPTION` | The extension's values, each read from the module constant that names the same stored key, option or hook prefix. | WooCommerce Payfast Gateway (the two keys), Advanced Dynamic Pricing (`FILTER_PREFIX`) |
| `instance(): self` | One facade instance for the request. | WooCommerce Deposits, WooCommerce Table Rate Shipping, Themify Product Filter, FOX Currency Switcher |
| `is_initialized()` | True while the built-in owns Multi-Currency. | Advanced Dynamic Pricing, Product Add-Ons |
| `get_frontend_prices()` | The registered price controller. Its shipping callback can be removed at priority 99. | Advanced Dynamic Pricing |
| `get_frontend_currencies()` | The registered controller that supplies the currency, decimals, separators and price format. | Gift Wrapper, Order Bump, Gift Cards Pro, Post-Purchase Offers, Recover Abandoned Cart |
| `get_price( $amount, string $type, ?MultiCurrencyCurrency $currency = null ): float` | Converts to the supplied currency, as in WooPayments 11.3.0. Omitting the currency or passing `null` uses the selected currency. | WooCommerce Deposits, Themify Product Filter (both use two arguments) |
| `get_raw_conversion( float $amount, string $to_currency, string $from_currency = '' ): float` | Converts through `PriceConverter`; an omitted source currency means the store currency. | WooCommerce Table Rate Shipping, Themify Product Filter |
| `get_selected_currency()`, `get_default_currency()`, `get_enabled_currencies()`, `get_available_currencies()`, `has_additional_currencies_enabled()`, `get_all_customer_currencies()`, `validate_currency_code( $code )` | Read the request's `MultiCurrencyState`; `validate_currency_code()` returns the upper-cased code of an available currency, else `false`. | WooCommerce Table Rate Shipping, Themify Product Filter, FOX Currency Switcher |
| `get_explicit_selected_currency()` | The request's `MultiCurrencyState`: the currency the shopper or a compatibility rule selected, or `null` when nothing is. | none known |
| `is_enabled()` (static) | `true`: the class is declared only while WooCommerce runs Multi-Currency. | none known |
| `get_compatibility()` | One `WCPay\MultiCurrency\Compatibility` per request (below). | Themify Product Filter |
| `get_store_currencies()`, `get_single_currency_settings( string $code )`, `get_public_config()`, `is_cache_optimized_mode()` | `DisplayService`; `get_single_currency_settings()` throws `InvalidCurrencyException` for a currency the store does not offer, as the extension does. | none known |
| `get_settings()` | The settings the Multi-currency REST route returns, plus `is_cache_optimized_feature_enabled` as `true`: cache-optimized rendering is always available here, where the extension reads its feature flag, on by default. | none known |
| `get_rendering_mode()`, `is_using_auto_currency_switching()`, `is_using_storefront_switcher()`, `has_active_session()`, `get_apply_charm_only_to_products()` | One-line reads of the options, session and filter the module reads, with its constants. | none known |
| `adjust_amount_for_selected_currency( $amount, $apply_charm_pricing = true )` | `PriceConverter`: the selected currency's rounding, and its charm unless the second argument is false. | none known |
| `get_cached_currencies()` | The cached automatic rates, after the request's state has refreshed them as it does when a rate provider is connected. | none known |
| `get_switcher_widget_markup( array $instance = array(), array $args = array() )` | `MultiCurrencySwitcherWidgetController`, the theme template tag's markup. | none known |
| `is_multi_currency_settings_page()` | `MultiCurrencySettingsController`'s tab check on the current admin screen. | none known |
| `remove_woo_admin_notes()` (static) | Deletes the inbox notes named `MultiCurrencyAdminNoteController::NOTE_NAME`, the extension's note name. | WooPayments Dev Tools ("clear notes") |
| Any member not listed in this table | Fails with PHP's own undefined-method error, which names the class and the method; no warning of ours. | none known |

The currencies these members return are the module's `MultiCurrencyCurrency` objects. They answer these methods of the extension's `Currency` class: `get_code()`, `get_id()`, `get_flag()`, `get_is_default()`, `get_name()`, `get_rate()`, `get_charm()`, `get_rounding()`, `get_symbol()`, `get_symbol_position()`, `get_is_zero_decimal()`, `get_last_updated()`, `set_charm()`, `set_rate()`, `set_rounding()`, `set_last_updated()` and `jsonSerialize()`. They do not answer `get_currency_name_from_code()`, a lookup in `get_woocommerce_currencies()` the module never needs, nor the extension's public `$code` and `$rate` properties, which are private here (read them through `get_code()` and `get_rate()`). Their JSON form adds `charm` and `rounding` to the extension's ten keys.

### `WCPay\MultiCurrency\Compatibility`

| Members | Answer |
|---|---|
| `override_selected_currency()`, `should_disable_currency_switching()`, `should_convert_coupon_amount( $coupon = null )`, `should_convert_product_price( $product = null )`, `should_return_store_currency()` | `IntegrationsController`, which has the same rules and filters. |
| `should_convert_shipping_amount( $shipping_method = null )` | `true` without a method, else the module's `wcpay_multi_currency_should_convert_shipping_amount` filter with its `true` default. |
| `should_convert_coupon_min_max_amount( $coupon = null )` | `true` without a coupon, else the module's `wcpay_multi_currency_should_convert_coupon_min_max_amount` filter with its `true` default. |
| `should_hide_widgets()` | The same switching decision as `should_disable_currency_switching()`. |

The class does not extend the extension's `WCPay\MultiCurrency\Compatibility\BaseCompatibility`, so a type check against that class fails.

### Other legacy names

Gift Wrapper, Order Bump, Gift Cards Pro and Post-Purchase Offers call `WCPay\MultiCurrency\Utils::is_admin_api_request()`. Tier Pricing constructs the helper. POP invoice integrations can catch `WCPay\MultiCurrency\Exceptions\InvalidCurrencyException` and `InvalidCurrencyRateException`. These names alias the core exceptions and preserve the original message and code.

Name Your Price supplies its own WooPayments conversion hooks from version 4.0.0. The module supplies conversions for earlier versions.

### Remaining gaps

YayPricing's cross-currency reverse calculation calls `get_adjusted_price()`, which is protected on the extension and absent here. Tier Pricing can read private currency fields on some paths. Both it and Auctions can call unavailable currency names when Multi-Currency is off on a store that started on the built-in. Missing methods raise PHP's undefined-method error.

- On `MultiCurrency`: the hook callbacks and lifecycle methods (`__construct`, `init_hooks`, `init`, `init_rest_api`, `init_widgets`, `init_settings_pages`, `enqueue_admin_scripts`, `add_props_to_wcpay_js_config`, `add_woo_admin_notes`, `maybe_update_customer_currencies_option`, `update_selected_currency_by_url`, `update_selected_currency_by_geolocation`, `maybe_auto_enable_cache_rendering_mode`, `display_geolocation_currency_update_notice`, `set_new_customer_currency_meta`, `add_order_meta_on_refund`, `recalculate_cart`, `possible_simulation_activation`, `register_script_with_dependencies`, `get_file_version`), because the module registers its own; the service getters (`get_storefront_integration`, `get_currency_switcher_widget`), which return the extension's internal objects; the writes (`set_enabled_currencies`, `update_selected_currency`, `update_single_currency_settings`, `update_settings`), which the settings screen and the switchers own; the settings preview internals (`is_simulation_enabled`, `get_multi_currency_onboarding_simulation_variables`), which have no consumer; `get_backend_formatted_wc_price()`, which has no module rule with the same contract.
- On `Compatibility`: `init`, `init_compatibility_classes`, `get_compatibility_classes`, and the hook callbacks `attach_order_modifier` and `convert_order_prices`.

Each method reports incorrect usage once per request through WordPress with version `11.4.0` and names its replacement or announces removal in 12.0.0. Calling WordPress directly keeps live AJAX and REST requests quiet when debugging is off. Reading a class constant emits no notice. The Multi-Currency services own all state and conversion math.

## Placement rule

`WooPaymentsExtensionFacadePlacementTest` requires the global accessor, currency exception aliases, and every global `WC_Payments*` or namespaced `WCPay\` type declaration to live in `includes/legacy/woopayments-compat/` or `includes/legacy/woopayments-multi-currency-compat/`, outside every tree Composer scans for its classmaps: `src/` and `includes/rest-api/`, and `tests/php/src/` for development installs. The Jetpack autoloader's optimized classmap, built on every `composer install` and for the release zip, maps each class it finds in those trees whatever its namespace, so a facade placed there would be declared on first use on every store, including stores where the WooPayments extension runs; outside them, only the loaders can declare the facades. Test fixtures that declare the extension's class names use the `.fixture` extension, which Composer does not scan. The test also requires exactly one composition-root registration for each compatibility loader. A new compatibility surface belongs here only for a consumer contract observed in a plugin, and it must call the Multi-Currency services.

## Public currency utility on extension-owned sites

When the runtime arbiter selects the extension, `MultiCurrencyUtil` uses `ExtensionCurrencyBridge` to read enabled rates and convert amounts through the extension's accessor. Remove the bridge, its utility branches and its extension-specific tests when the WooPayments extension no longer runs Multi-Currency.

## Removed in WooCommerce 12.0.0

Remove these pieces together once supported extensions no longer call the `WCPay\MultiCurrency` namespace:

- The facade loader, shared accessor and compatibility notice trait in this folder and the global accessor, Utils, exception alias files and existing facades in `includes/legacy/woopayments-multi-currency-compat/`.
- The one registration line, `WooPaymentsExtensionFacadeLoader::register()` in `includes/class-woocommerce.php`.
- The facade tests and their fixtures in `tests/php/src/Internal/MultiCurrency/Compat/`.
- This boundary's expected symbols and loader entries in `WooPaymentsExtensionFacadePlacementTest` and the accessor's name and path in `bin/check-new-functions.php`.
- The `$apply_charm_pricing` argument of `PriceConverter::adjust_amount_for_selected_currency()` and `PriceCalculator::get_adjusted_amount()`, which only the facade passes as false.
- The WooPayments extension's asset handles registered as aliases outside this folder: `wcpay-multi-currency-async-renderer` (script and style, `MultiCurrencyAsyncPriceRendererController::COMPAT_SCRIPT_HANDLE`), the switcher block's editor script handle (`MultiCurrencySwitcherBlockController::COMPAT_EDITOR_SCRIPT_HANDLE`) and `WCPAY_MULTI_CURRENCY_ANALYTICS` (`MultiCurrencyAnalyticsController`).

Keep the Multi-Currency conversion and state services; they are not compatibility code.
