---
session: 2026-06-15-core-native-payments
type: analysis
by: subagent:Heisenberg
created: 2026-06-16 11:59
last_updated: 2026-06-16 12:12
target: native WooPayments and multi-currency runtime seams
status: final
---

# Runtime Seams Analysis

## Scope

Read-only scan of `plugins/woocommerce/src/Internal/Payments`, `plugins/woocommerce/src/Internal/Admin/Settings/PaymentsProviders/WooPayments`, `plugins/woocommerce/src/Internal/MultiCurrency`, and matching tests after `ea181a220b`. WPCOM was not touched.

## Findings

### 1. Provider-neutral multi-currency settings provider boundary

Best next slice for Option C after the current multi-currency runtime-service factory cleanup.

Concrete leakage:

- `plugins/woocommerce/src/Internal/MultiCurrency/MultiCurrencySettingsController.php` imports both the provider-neutral `MultiCurrencyAccountInterface` and `Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\MultiCurrency\WooPaymentsLegacyAccountAdapter`.
- `MultiCurrencySettingsController::init()` type-hints `WooPaymentsLegacyAccountAdapter` directly.
- `plugins/woocommerce/tests/php/src/Internal/MultiCurrency/MultiCurrencySettingsControllerTest.php` mirrors the concrete dependency with a `WooPaymentsLegacyAccountAdapter` subclass.

Why it matters: the generic multi-currency settings surface still depends on the first-party WooPayments adapter. A provider-neutral settings/runtime resolver would make settings consume a Core-owned capability boundary instead of a WooPayments concrete.

Risk: medium. It touches settings-page registration and onboarding CTA URLs, but the risk is mostly admin UI state. Focused coverage exists in `MultiCurrencySettingsControllerTest`; add source-boundary coverage in `MultiCurrencyDomainMapTest`.

### 2. WooPayments admin provider/service runtime boundary

Concrete remaining direct legacy checks:

- `WooPaymentsService.php` still checks `WC_Payments_Onboarding_Service` before resetting the legacy test-mode option.
- `WooPaymentsService.php` still checks `\WC_Payments_Utils::supported_countries`.
- `WooPayments.php` still uses `wc_get_container()->get()` for `WooPaymentsRestController` and `WooPaymentsService`.

Why it matters: WooPayments admin onboarding still owns transition-time legacy runtime checks directly instead of routing them through a provider-side admin/runtime collaborator.

Risk: medium-high because `WooPaymentsService` is large and heavily tested. Keep a first slice limited to repeated runtime lookups and provider-shell service access.

### 3. Multi-currency auxiliary service factory expansion

This was the B3k implementation candidate already in flight when this analysis returned. Concrete patterns included direct `new MultiCurrencyRequestContext()`, `new MultiCurrencySwitcherProjectionService(...)`, tracking, analytics, SQL, cache, and lifecycle service construction across multi-currency controllers.

Why it matters: B3e/B3h centralized price/frontend projection graphs, but auxiliary graphs remained controller-owned.

Risk: medium. Many files, mostly construction movement. Existing focused controller tests cover the behavior.

### 4. Native gateway direct container fallback

Concrete remaining fallback:

- `NativeWooPaymentsGateway.php` falls back to `wc_get_container()->get( PaymentProcessingService::class )`.
- `NativeWooPaymentsGateway.php` falls back to `wc_get_container()->get( WooPaymentsProvider::class )`.
- `NativeWooPaymentsGatewayTest.php` intentionally protects direct gateway instantiation resolving dependencies.

Risk: high relative to size because WooCommerce payment-gateway registration can instantiate gateways by class name. Removing this probably needs an instance/factory registration strategy.
