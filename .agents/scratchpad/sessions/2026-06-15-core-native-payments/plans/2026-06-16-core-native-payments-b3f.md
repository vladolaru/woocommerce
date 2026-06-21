---
session: 2026-06-15-core-native-payments
type: plan
by: codex
created: 2026-06-16 10:52
status: draft
---

# Core Native Payments B3f Provider Runtime Cleanup Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use $subagent-driven-development (recommended) or $executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Broaden the B3d `WooPaymentsLegacyRuntime` seam to cover the remaining WooPayments onboarding/runtime static lookups and the webhook logger lookup, then migrate those provider consumers in one coherent pass.

**Architecture:** Keep `LegacyProxy` only where the consumer needs generic WordPress function calls or admin/plugin state. `WooPaymentsLegacyRuntime` owns transition-time WooPayments runtime checks, `WC_Payments` gateway/account/API/logger discovery, and static account URL helpers. `WooPaymentsOnboardingAdapter` keeps its native-provider fallback behavior but delegates extension-active checks, legacy gateway lookup, account service lookup, and WooPayments account URLs to the runtime seam. `WooPaymentsEventIngestor` keeps `LegacyProxy` for `get_option` and `do_action`, but logs delivery-hook failures through the same runtime logger seam.

**Tech Stack:** WooCommerce Core PHP, concrete DI container services, PHPUnit via `pnpm --filter='@woocommerce/plugin-woocommerce' test:php:env`, PHPStan, PHPCS, plugin changelog file.

## Files

- Modify: `plugins/woocommerce/src/Internal/Payments/Providers/WooPayments/WooPaymentsLegacyRuntime.php`.
- Modify: `plugins/woocommerce/src/Internal/Payments/Providers/WooPayments/WooPaymentsOnboardingAdapter.php`.
- Modify: `plugins/woocommerce/src/Internal/Payments/Providers/WooPayments/WooPaymentsEventIngestor.php`.
- Modify: `plugins/woocommerce/tests/php/src/Internal/Payments/Providers/WooPayments/WooPaymentsLegacyRuntimeTest.php`.
- Modify: `plugins/woocommerce/tests/php/src/Internal/Payments/Providers/WooPayments/WooPaymentsOnboardingAdapterTest.php`.
- Modify: `plugins/woocommerce/tests/php/src/Internal/Payments/Providers/WooPayments/WooPaymentsEventIngestorTest.php`.
- Create: `plugins/woocommerce/changelog/add-native-payments-b3f-provider-runtime-cleanup`.

## Task 1: RED Runtime Boundary Coverage

- [ ] Add `WooPaymentsLegacyRuntimeTest` coverage for callable account URL helpers: `get_account_connect_url( $source )` returns the legacy account connect URL when callable and `null` otherwise; `get_account_overview_page_url()` returns the legacy overview URL when callable and `null` otherwise.
- [ ] Add `WooPaymentsOnboardingAdapterTest` coverage that initializes the adapter with `WooPaymentsLegacyRuntime` and a throwing `LegacyProxy` for `WC_Payments` static calls, then proves legacy extension availability, gateway lookup, account state, KYC fallback URL, and overview URL still work through the runtime seam.
- [ ] Add `WooPaymentsEventIngestorTest` coverage that a delivery-hook exception is logged through `WooPaymentsLegacyRuntime::get_logger()` rather than a direct `wc_get_logger` call on the ingestor proxy.
- [ ] RED command: `pnpm --filter='@woocommerce/plugin-woocommerce' test:php:env -- --filter 'WooPaymentsLegacyRuntimeTest|WooPaymentsOnboardingAdapterTest|WooPaymentsEventIngestorTest'`.
- [ ] Expected RED: runtime account URL helpers do not exist yet, onboarding `init()` does not accept the runtime seam, and event ingestor still gets the logger from its local legacy proxy.

## Task 2: GREEN Runtime Expansion and Consumer Migration

- [ ] Extend `WooPaymentsLegacyRuntime` with guarded account URL helper methods that use `is_callable` and `WC_Payments_Account` static calls behind `LegacyProxy`, returning `null` on missing runtime, non-callable helper, non-scalar URL, or thrown errors.
- [ ] Change `WooPaymentsOnboardingAdapter::init()` to accept `WooPaymentsLegacyRuntime $legacy_runtime` and `WooPaymentsProvider $provider`, remove its `LegacyProxy` property/getter, and route extension availability, legacy gateway lookup, account service lookup, KYC fallback URL, and overview URL through the runtime seam.
- [ ] Change `WooPaymentsEventIngestor::init()` to accept `OrderPaymentLifecycleService`, `LegacyProxy`, and `WooPaymentsLegacyRuntime`; keep the proxy for settings/actions and use `$this->legacy_runtime->get_logger()` when delivery hooks throw.
- [ ] GREEN command: `pnpm --filter='@woocommerce/plugin-woocommerce' test:php:env -- --filter 'WooPaymentsLegacyRuntimeTest|WooPaymentsOnboardingAdapterTest|WooPaymentsEventIngestorTest|WooPaymentsWebhookRestControllerTest|WooPaymentsWebhookReliabilityServiceTest|WooPaymentsProviderGatewayAdapterTest|WooPaymentsPaymentMethodDetailsServiceTest|WooPaymentsLegacyAccountAdapterTest|WooPaymentsLegacyApiClientAdapterTest'`.

## Task 3: Changelog and Gates

- [ ] Add changelog entry `plugins/woocommerce/changelog/add-native-payments-b3f-provider-runtime-cleanup` with `Significance: patch`, `Type: dev`, and a comment about centralizing WooPayments provider runtime discovery for onboarding and webhook logging.
- [ ] PHPStan: `composer exec -- phpstan analyse src/Internal/Payments/Providers/WooPayments/WooPaymentsLegacyRuntime.php src/Internal/Payments/Providers/WooPayments/WooPaymentsOnboardingAdapter.php src/Internal/Payments/Providers/WooPayments/WooPaymentsEventIngestor.php --memory-limit=2G`.
- [ ] PHPCS: `vendor/bin/phpcs -s src/Internal/Payments/Providers/WooPayments/WooPaymentsLegacyRuntime.php src/Internal/Payments/Providers/WooPayments/WooPaymentsOnboardingAdapter.php src/Internal/Payments/Providers/WooPayments/WooPaymentsEventIngestor.php tests/php/src/Internal/Payments/Providers/WooPayments/WooPaymentsLegacyRuntimeTest.php tests/php/src/Internal/Payments/Providers/WooPayments/WooPaymentsOnboardingAdapterTest.php tests/php/src/Internal/Payments/Providers/WooPayments/WooPaymentsEventIngestorTest.php`.
- [ ] Changed-file gates: `pnpm --filter=@woocommerce/plugin-woocommerce lint:php:changes`, `git diff --check`, `pnpm --filter=@woocommerce/plugin-woocommerce lint:php:changes:staged`, and `git diff --cached --check`.
- [ ] Commit source/tests as `refactor(payments): expand WooPayments runtime seam`.
- [ ] Commit changelog as `chore(payments): add provider runtime cleanup changelog`.

## Acceptance Notes

- No WPCOM code changes, commits, or pushes.
- No WooPayments plugin checkout changes.
- Keep `WooPaymentsEventIngestor` generic `LegacyProxy` usage for `get_option` and `do_action`; only the logger lookup moves because it already belongs to `WooPaymentsLegacyRuntime`.
- Keep `WooPaymentsCutoverController` out of scope because its `LegacyProxy` calls are admin/plugin activation and capability checks, not `WC_Payments` runtime discovery.
