# WooPayments extension compatibility

This folder holds what Woo extensions still expect from the WooPayments extension while the built-in WooPayments owns payments with an account connected: its `WC_Payments`, `WC_Payments_Features`, `WC_Payments_Utils` and `WCPay\Core\Mode` classes (their files live in `includes/legacy/woopayments-compat/`), its `WCPAY_VERSION_NUMBER` constant and two of its class names. It is removed in WooCommerce 12.0.0. Below the connected tier the loader declares nothing: until an account is connected the built-in WooPayments takes no card payment, so other plugins must not read WooPayments as present.

## What it holds

- `WooPaymentsExtensionFacadeLoader` defines `WCPAY_VERSION_NUMBER` and declares each of the four facade classes on first use. It declares nothing below the connected tier (never set up, onboarding started, no account data after a switch, a disconnected account) or in a recognized WooPayments activation request, and the extension's own classes win whenever it is active.
- `ClassAliases` declares two WooPayments extension class names as aliases of the classes that answer for them where those classes first reach a hook or WooCommerce Subscriptions: `WCPay\Constants\Payment_Type`, the third argument of `wcpay_metadata_from_order`, and `WC_Payments_Email_Failed_Authentication_Retry`, the retry email class WooCommerce Subscriptions instantiates by name. An autoloader that can load the extension's own class wins.
- The facade classes live in `includes/legacy/woopayments-compat/`, outside every tree Composer scans (see the placement rule).
- The extension's request and response classes (`WCPay\Core\Server\*`) and `WC_Payment_Gateway_WCPay` are not declared: the built-in WooPayments passes its own request objects to the request filters and keeps the `woocommerce_payments` gateway ID.
- The `WCPay\MultiCurrency` namespace lives in the [Multi-Currency compatibility boundary](../../../../MultiCurrency/Compat/README.md).

## Contract

| Symbol | Behavior | Known Woo-owned consumers |
|---|---|---|
| `WC_Payments` | Existence checks recognize the built-in WooPayments once an account is connected. `get_gateway()` returns the container's `WooPaymentsGateway`, whose `is_enabled()` answers like the extension gateway's for callers that ask it. `hide_gateways_on_settings_page()` does nothing, because there are no secondary gateway classes to hide. Both emit a deprecation notice. | WooCommerce Core Blueprint exporter, All Products for WooCommerce Subscriptions, AutomateWoo, WooCommerce PayPal Payments |
| `WC_Payments_Features` | Exposes the extension's 26 feature predicates, its 13 option-name constants, and its filtered `to_array()` of enabled features. Predicates read current account, settings and Multi-Currency ownership even if the account disconnects during the request. The existing bundled subscriptions predicate still returns `false`. Each method emits a deprecation notice. | All Products for WooCommerce Subscriptions, Conditional Shipping and Payments and Payfast (both call `is_customer_multi_currency_enabled()`) |
| `WC_Payments_Utils` | Exposes only `prepare_amount( $amount, $currency = 'USD' ): int`. It rounds to minor units, preserves zero-decimal currencies and emits a deprecation notice. | Gift Cards |
| `WC_Payments::mode()` / `WCPay\Core\Mode` | Returns one instance per request. `is_live()`, `is_test()`, `is_dev()`, `is_test_mode_onboarding()` and `get_dev_mode_triggers()` read the current account on connected and active stores. If the store becomes dormant, only `is_live()` returns true and the triggers list is empty. Each call emits a deprecation notice. | None known |
| `WCPAY_VERSION_NUMBER` | Reports `WooPaymentsClientVersion::VERSION`, the extension version whose platform contract the built-in WooPayments implements. | All Products for WooCommerce Subscriptions |

The feature facade groups its predicates and option constants as follows.

| Area | Predicates | Option constants |
|---|---|---|
| Payments and checkout | `are_payments_enabled()`, `is_woopay_enabled()`, `is_woopay_eligible()`, `is_woopay_force_signed_requests_enabled()`, `is_woopay_express_checkout_enabled()`, `is_woopay_direct_checkout_enabled()`, `is_woopay_global_theme_support_eligible()`, `is_dynamic_checkout_place_order_button_enabled()`, `is_amazon_pay_enabled()`, `is_ece_confirmation_tokens_enabled()` | `WOOPAY_EXPRESS_CHECKOUT_FLAG_NAME`, `WOOPAY_DIRECT_CHECKOUT_FLAG_NAME`, `WOOPAY_GLOBAL_THEME_SUPPORT_FLAG_NAME`, `WCPAY_DYNAMIC_CHECKOUT_PLACE_ORDER_BUTTON_FLAG_NAME`, `AMAZON_PAY_FLAG_NAME` |
| Subscriptions and billing | `is_wcpay_subscriptions_enabled()`, `is_wcpay_subscriptions_eligible()`, `is_stripe_billing_enabled()`, `is_stripe_billing_eligible()`, `should_use_stripe_billing()` | `WCPAY_SUBSCRIPTIONS_FLAG_NAME`, `STRIPE_BILLING_FLAG_NAME` |
| Multi-Currency | `is_customer_multi_currency_enabled()`, `is_mc_cache_optimized_enabled()` | `MC_CACHE_OPTIMIZED_FLAG_NAME` |
| Disputes and fraud | `is_frt_review_feature_active()`, `is_fraud_protection_welcome_tour_dismissed()`, `is_dispute_issuer_evidence_enabled()`, `is_dispute_additional_evidence_types_enabled()`, `is_dispute_outcome_view_enabled()`, `is_dispute_readiness_overview_enabled()` | `DISPUTE_ISSUER_EVIDENCE`, `DISPUTE_ADDITIONAL_EVIDENCE_TYPES`, `DISPUTE_OUTCOME_VIEW`, `DISPUTE_READINESS_OVERVIEW` |
| Documents, reports and deposits | `is_documents_section_enabled()`, `is_reports_area_enabled()`, `is_next_deposit_notice_dismissed()` | `REPORTS_AREA_FLAG_NAME` |

WooCommerce also checks these symbols internally.

- The core onboarding task `WooCommercePayments` probes `class_exists( '\WC_Payments' )` only. It is in no default task list, and its completion check also needs a registered gateway.
- `WooPaymentsExtensionRuntime::is_extension_version_less_than()` reads `WCPAY_VERSION_NUMBER` through `Constants::is_defined()` and answers `null` when it is absent. The Payments settings onboarding paths act only on `true === …`, so a store below the connected tier onboards as before. `is_loaded()` asks the extension owner first, so the facade never answers it.
- `WooPaymentsExtensionRuntime::get_supported_countries()` checks whether `WC_Payments_Utils::supported_countries()` is callable. With the built-in facade it still returns `null`.
- The Payments settings provider asks `WooPaymentsExtensionRuntime` for test, development and onboarding modes. The runtime reads the extension's mode only when the extension owns payments. Otherwise the provider uses its existing fallback for each answer.

## Activation safety

WordPress includes a plugin in a sandbox before adding it to the active list and provides no hook before that, and the WooPayments extension declares `WC_Payments_Features` as it loads. The loader therefore declares the facades through an autoloader registered at `plugins_loaded` priority 0, so an activation that nothing probed first leaves the names to the extension, including activation paths with no recognizable request shape (an Action Scheduler callback, `activate_plugin()` from code).

Some extensions probe `WC_Payments_Features` early in every request, so the loader registers no autoloader at all in a request it recognizes as a WooPayments activation: the sandbox scrape, the Plugins screen, bulk actions, installer AJAX, the core plugins REST controller, WooCommerce's `/wc-admin/plugins/activate` and `/wc-admin/plugins/install` routes, WooCommerce's `PluginsInstaller` URL, Jetpack's XML-RPC plugin installation and activation requests, and WooPayments `activate`, `install --activate` and `toggle` commands in WP-CLI. Probing `WC_Payments` loads only that facade, which never references `WC_Payments_Features`.

## Placement rule

`WooPaymentsExtensionFacadePlacementTest` requires every global `WC_Payments*` or namespaced `WCPay\` type declaration to live in `includes/legacy/woopayments-compat/` or `includes/legacy/woopayments-multi-currency-compat/`. Composer's optimized classmaps map every class in a scanned tree whatever its namespace (`src/`, `includes/rest-api/`, and `tests/php/src/` for development installs), so a facade placed there would be declared on first use on every store, extension-owned ones included. Test fixtures that declare these names use the `.fixture` extension, which Composer does not scan. The test also requires exactly one registration of each loader in the composition root.

## Removed in WooCommerce 12.0.0

Remove these pieces together once supported extensions no longer depend on the WooPayments extension's symbols:

- This `Compat/` folder, including the loader's Jetpack XML-RPC installation and activation check.
- The four files in `includes/legacy/woopayments-compat/`: `class-wc-payments.php` (including `mode()`), `class-wc-payments-features.php`, `class-wc-payments-utils.php` and `class-mode.php` (`WCPay\Core\Mode`).
- The one registration line, `WooPaymentsExtensionFacadeLoader::register()` in `includes/class-woocommerce.php`.
- The two alias first-use sites, which call `ClassAliases::register_compat_class_alias()`: `WooPaymentsIntentRequestBuilder::metadata_from_order()` and `Subscriptions/FailedRenewalAuthenticationEmail::set_store_owner_custom_email()`. `git grep -n ClassAliases -- src/Internal/Payments` lists them.
- `WooPaymentsGateway::is_enabled()`, which only callers reaching the gateway through `get_gateway()` use, with its test in `WooPaymentsGatewayTest`.
- The tests in `tests/php/src/Internal/Payments/Providers/WooPayments/Compat/`, with their `.fixture` files.
- This boundary's expected symbols and loader entries in `WooPaymentsExtensionFacadePlacementTest`, in the same removal commit.
- The extension's order-note wordings, which the built-in WooPayments renders so notes the extension wrote are recognised: the `woocommerce-payments` wordings in `WooPaymentsOrderNoteService` with its `extension_wordings()`, `WooPaymentsOrderNoteWordingSnapshot` with the cutover job's `capture_note_wordings()` calls, and the snapshot's stored option, `woocommerce_woopayments_note_wordings`, which the removal release deletes.
