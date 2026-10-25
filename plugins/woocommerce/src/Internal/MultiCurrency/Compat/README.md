# WooPayments extension Multi-Currency compatibility

This folder holds what plugins written for the WooPayments extension call in its `WCPay\MultiCurrency` namespace, answered by WooCommerce's Multi-Currency while it runs Multi-Currency: the `MultiCurrency` class and the `Compatibility` class its `get_compatibility()` returns. `WooPaymentsExtensionMultiCurrencyFacadeLoader` declares both only after active plugins have loaded (`plugins_loaded` priority 0) and only when `MultiCurrencyRuntimeArbiter::is_builtin_owner()` is true; while the WooPayments extension runs Multi-Currency, the namespace is the extension's own. The facade class files live in `includes/legacy/woopayments-multi-currency-compat/` (see the placement rule). The extension's other classes and globals live in the separate [payments compatibility boundary](../../Payments/Providers/WooPayments/Compat/README.md). It is removed in WooCommerce 12.0.0.

The facades exist to spare these plugins a fatal error, not to emulate the extension: they answer every member the Multi-Currency module has a real answer for, from the module's own state, services and rules, and declare nothing else.

## Contract

### `WCPay\MultiCurrency\MultiCurrency`

| Members | Answer | Known consumers |
|---|---|---|
| `CURRENCY_SESSION_KEY`, `CURRENCY_META_KEY`, `FILTER_PREFIX`, `CUSTOMER_CURRENCIES_KEY`, `RENDERING_MODE_SPEED`, `RENDERING_MODE_CACHE`, `RENDERING_MODE_OPTION`, `CACHE_AUTODETECT_DONE_OPTION`, `CACHE_RECOMMENDATION_DISMISSED_OPTION` | The extension's values, each read from the module constant that names the same stored key, option or hook prefix. | WooCommerce Payfast Gateway (the two keys), Advanced Dynamic Pricing (`FILTER_PREFIX`) |
| `instance(): self` | One facade instance for the request. | WooCommerce Deposits, WooCommerce Table Rate Shipping, Themify Product Filter, FOX Currency Switcher |
| `get_price( $amount, string $type ): float` | Converts through `MultiCurrencyPriceConverter`, by way of the loader, which records a converted `product` price so the Deposits controller does not convert the same fixed amount again. | WooCommerce Deposits, Themify Product Filter |
| `get_raw_conversion( float $amount, string $to_currency, string $from_currency = '' ): float` | Converts through `MultiCurrencyPriceConverter`; an omitted source currency means the store currency. | WooCommerce Table Rate Shipping, Themify Product Filter |
| `get_selected_currency()`, `get_default_currency()`, `get_enabled_currencies()`, `get_available_currencies()`, `has_additional_currencies_enabled()`, `get_all_customer_currencies()`, `validate_currency_code( $code )` | Read the request's `MultiCurrencyState`; `validate_currency_code()` returns the upper-cased code of an available currency, else `false`. | WooCommerce Table Rate Shipping, Themify Product Filter, FOX Currency Switcher |
| `get_compatibility()` | One `WCPay\MultiCurrency\Compatibility` per request (below). | Themify Product Filter |
| `get_store_currencies()`, `get_single_currency_settings( string $code )`, `get_public_config()`, `is_cache_optimized_mode()` | `MultiCurrencyDisplayService`; `get_single_currency_settings()` throws `InvalidCurrencyException` for a currency the store does not offer, as the extension does. | none known |
| `get_settings()` | The settings the Multi-currency REST route returns, plus `is_cache_optimized_feature_enabled` as `true`: cache-optimized rendering is always available here, where the extension reads its feature flag, on by default. | none known |
| `get_rendering_mode()`, `is_using_auto_currency_switching()`, `is_using_storefront_switcher()`, `has_active_session()`, `get_apply_charm_only_to_products()` | One-line reads of the options, session and filter the module reads, with its constants. | none known |
| `adjust_amount_for_selected_currency( $amount, $apply_charm_pricing = true )` | `MultiCurrencyPriceConverter`: the selected currency's rounding, and its charm unless the second argument is false. | none known |
| `get_cached_currencies()` | The cached automatic rates, after the request's state has refreshed them as it does when a rate provider is connected. | none known |
| `get_switcher_widget_markup( array $instance = array(), array $args = array() )` | `MultiCurrencySwitcherWidgetController`, the theme template tag's markup. | none known |
| `is_multi_currency_settings_page()` | `MultiCurrencySettingsController`'s tab check on the current admin screen. | none known |
| `remove_woo_admin_notes()` (static) | Deletes the inbox notes named `MultiCurrencyAdminNoteController::NOTE_NAME`, the extension's note name. | WooPayments Dev Tools ("clear notes") |

The currencies these members return are the module's `MultiCurrencyCurrency` objects. They answer these methods of the extension's `Currency` class: `get_code()`, `get_id()`, `get_flag()`, `get_is_default()`, `get_name()`, `get_rate()`, `get_charm()`, `get_rounding()`, `get_symbol()`, `get_symbol_position()`, `get_is_zero_decimal()`, `get_last_updated()`, `set_charm()`, `set_rate()`, `set_rounding()`, `set_last_updated()` and `jsonSerialize()`. They do not answer `get_currency_name_from_code()`, a lookup in `get_woocommerce_currencies()` the module never needs, nor the extension's public `$code` and `$rate` properties, which are private here (read them through `get_code()` and `get_rate()`). Their JSON form adds `charm` and `rounding` to the extension's ten keys.

### `WCPay\MultiCurrency\Compatibility`

| Members | Answer |
|---|---|
| `override_selected_currency()`, `should_disable_currency_switching()`, `should_convert_coupon_amount( $coupon = null )`, `should_convert_product_price( $product = null )`, `should_return_store_currency()` | `MultiCurrencyCompatibilityController`, which has the same rules and filters. |
| `should_convert_shipping_amount( $shipping_method = null )` | `true` without a method, else the module's `wcpay_multi_currency_should_convert_shipping_amount` filter with its `true` default. |
| `should_hide_widgets()` | `should_disable_currency_switching()`, with the extension's own 6.5.0 deprecation notice. |

The class does not extend the extension's `WCPay\MultiCurrency\Compatibility\BaseCompatibility`, so a type check against that class fails.

### Not answered

`WC_Payments_Multi_Currency()` stays undefined: plugins that guard on `function_exists( 'WC_Payments_Multi_Currency' )` skip their WooPayments code today, and defining it would send them into the members below as well. Calling one of these members is an undefined-method error:

- On `MultiCurrency`: the hook callbacks and lifecycle methods (`__construct`, `init_hooks`, `init`, `init_rest_api`, `init_widgets`, `init_settings_pages`, `enqueue_admin_scripts`, `add_props_to_wcpay_js_config`, `add_woo_admin_notes`, `maybe_update_customer_currencies_option`, `update_selected_currency_by_url`, `update_selected_currency_by_geolocation`, `maybe_auto_enable_cache_rendering_mode`, `display_geolocation_currency_update_notice`, `set_new_customer_currency_meta`, `add_order_meta_on_refund`, `recalculate_cart`, `possible_simulation_activation`, `register_script_with_dependencies`, `get_file_version`), because the module registers its own; the service getters (`get_frontend_prices`, `get_frontend_currencies`, `get_storefront_integration`, `get_currency_switcher_widget`), which return the extension's internal objects; the writes (`set_enabled_currencies`, `update_selected_currency`, `update_single_currency_settings`, `update_settings`), which the settings screen and the switchers own; the settings preview internals (`is_simulation_enabled`, `get_multi_currency_onboarding_simulation_variables`), which have no consumer; `get_backend_formatted_wc_price()`, which has no module rule with the same contract; and `is_initialized()`, for which the module has no fact to report.
- On `Compatibility`: `init`, `init_compatibility_classes`, `get_compatibility_classes`, and the hook callbacks `attach_order_modifier` and `convert_order_prices`.

Every member emits a deprecation notice with the version 11.0.0 and no replacement, since no public successor exists, with two exceptions: the constants emit no notice, since PHP gives a class constant read no hook, and `Compatibility::should_hide_widgets()` emits the extension's own notice, version 6.5.0 naming `Compatibility::should_disable_currency_switching` (client 11.1.0 `includes/multi-currency/Compatibility.php:99-101`). The Multi-Currency services own all state and conversion math.

## Deposits conversion provenance

WooCommerce Deposits converts a fixed deposit through `get_price( ..., 'product' )` before the Deposits compatibility controller sees the cart item. The loader records that conversion for the current request. `MultiCurrencyDepositsCompatibilityController` leaves the incoming cart values alone when the record is set, so the selected currency's rate is applied once rather than twice. A conversion that throws records nothing, so the controller still converts the amount.

## Placement rule

`WooPaymentsExtensionFacadePlacementTest` requires every global `WC_Payments*` or namespaced `WCPay\` type declaration to live in `includes/legacy/woopayments-compat/` or `includes/legacy/woopayments-multi-currency-compat/`, outside every tree Composer scans for its classmaps: `src/` and `includes/rest-api/`, and `tests/php/src/` for development installs. The Jetpack autoloader's optimized classmap, built on every `composer install` and for the release zip, maps each class it finds in those trees whatever its namespace, so a facade placed there would be declared on first use on every store, including stores where the WooPayments extension runs; outside them, only the loaders can declare the facades. Test fixtures that declare the extension's class names use the `.fixture` extension, which Composer does not scan. The test also requires exactly one composition-root registration for each compatibility loader. A new compatibility surface belongs here only for a consumer contract observed in a plugin, and it must call the Multi-Currency services.

## Removed in WooCommerce 12.0.0

Remove these pieces together once supported extensions no longer call the `WCPay\MultiCurrency` namespace:

- This `Compat/` folder and the facade files in `includes/legacy/woopayments-multi-currency-compat/`.
- The one registration line, `WooPaymentsExtensionMultiCurrencyFacadeLoader::register()` in `includes/class-woocommerce.php`.
- The `WooPaymentsExtensionMultiCurrencyFacadeLoader` import and the provenance check in `MultiCurrencyDepositsCompatibilityController`.
- The tests and their fixtures in `tests/php/src/Internal/MultiCurrency/Compat/`.
- This boundary's expected symbols and loader entries in `WooPaymentsExtensionFacadePlacementTest`.
- The `$apply_charm_pricing` argument of `MultiCurrencyPriceConverter::adjust_amount_for_selected_currency()` and `MultiCurrencyPriceCalculator::get_adjusted_amount()`, which only the facade passes as false.
- The WooPayments extension's asset handles registered as aliases outside this folder: `wcpay-multi-currency-async-renderer` (script and style, `MultiCurrencyAsyncPriceRendererController::COMPAT_SCRIPT_HANDLE`), the switcher block's editor script handle (`MultiCurrencySwitcherBlockController::COMPAT_EDITOR_SCRIPT_HANDLE`) and `WCPAY_MULTI_CURRENCY_ANALYTICS` (`MultiCurrencyAnalyticsController`).

Keep the Multi-Currency conversion and state services; they are not compatibility code.
