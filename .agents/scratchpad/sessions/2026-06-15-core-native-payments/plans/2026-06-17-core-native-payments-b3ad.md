---
session: 2026-06-15-core-native-payments
type: plan
by: codex
created: 2026-06-17 03:21
tool: writing-plans
reconciles:
  - implementation-log.md
  - staging-log.md
  - analysis-action-scheduler-handoff.md
  - plans/2026-06-17-core-native-payments-b3ac.md
status: final
---

# Core Native Payments B3ad Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use $executing-plans to implement this plan task-by-task. Use $test-driven-development before production code changes and preserve the restored harness/browser/log gates.

**Goal:** Preserve the remaining bounded WooPayments operational Action Scheduler queue handoff in the Core-native runtime without masking merchant-facing lifecycle work that still needs a full UI/email port.

**Architecture:** Add a focused Core-native operational queue service behind `NativePaymentsRuntimeArbiter::should_native_register()`. It will own the preserved operational hooks `wcpay_store_setup_sync`, `wcpay_update_saved_payment_method`, `wcpay_add_fee_breakdown_to_order_notes`, and `wcpay_update_compatibility_data`, plus the plugin-compatible producers for account-refresh/theme-change store signals where Core can faithfully reproduce the behavior. The service will use the existing `WooPaymentsActionSchedulerService`, `WooPaymentsAccountService`, shared order helpers, and `WooPaymentsApiClient` transport. It will not register no-op handlers for `wcpay_instant_deposit_reminder` or `wcpay_post_kyc_activation_email_send`; those remain explicit follow-up lifecycle surfaces because Core does not yet contain their WooPayments-specific inbox-note/email classes and templates.

**Tech Stack:** WooCommerce Core PHP, wp-env PHPUnit, PHPStan, PHPCS, restored WooPayments merge harness, browser verification against target/reference local stores, Docker log scans.

## Tasks

- [ ] **Task 1: Add API client RED coverage**

Add failing `WooPaymentsApiClientTest` coverage for `update_payment_method()`, `get_timeline()`, `send_store_setup()`, and `update_compatibility_data()`. Assert the exact WPCOM paths, HTTP methods, request bodies, test-mode injection, and return values. Expected RED: missing API client methods.

Run:

```bash
pnpm --filter='@woocommerce/plugin-woocommerce' test:php:env -- --filter 'WooPaymentsApiClientTest::test_update_payment_method_posts_billing_details|WooPaymentsApiClientTest::test_get_timeline_reads_timeline_endpoint|WooPaymentsApiClientTest::test_send_store_setup_posts_snapshot|WooPaymentsApiClientTest::test_update_compatibility_data_posts_compatibility_payload'
```

- [ ] **Task 2: Implement API client methods**

Extend `WooPaymentsApiClient` with the four methods above, using the existing private `request()` helper. Preserve plugin endpoint shapes: `payment_methods/{id}` `POST`, `timeline/{id}` `GET`, `accounts/store_setup` `POST` with `snapshot`, and `compatibility` `POST` with `compatibility_data`. Validate route IDs using the same conservative `^\w+$` pattern before path interpolation for payment method and timeline IDs. Re-run Task 1 and expect PASS.

- [ ] **Task 3: Extract shared order helpers with RED coverage**

Add focused tests for shared helper behavior before production changes. Extract order billing-details construction from `WooPaymentsProviderGatewayAdapter` into a small service usable by checkout processing and queue handlers. Extract fee-breakdown note creation into a small service that can render the same note from a captured timeline event and add it to an order. Existing checkout/payment tests should still pass after the extraction. Expected RED: helper classes/methods do not exist yet.

- [ ] **Task 4: Implement the operational queue service**

Create `WooPaymentsOperationalQueueService` implementing `RegisterHooksInterface`. Inject `NativePaymentsRuntimeArbiter`, `WooPaymentsActionSchedulerService`, `WooPaymentsApiClient`, `WooPaymentsAccountService`, and the shared order helper service. Register only when native owns runtime. Register these consumers: `wcpay_store_setup_sync`, `wcpay_update_saved_payment_method`, `wcpay_add_fee_breakdown_to_order_notes`, and `wcpay_update_compatibility_data`. Register these producers: `action_scheduler_ensure_recurring_actions` or admin fallback for the six-hour `wcpay_store_setup_sync` recurring action, `woocommerce_woocommerce_payments_updated` for immediate store setup sync, `woocommerce_payments_account_refreshed` and `after_switch_theme` for debounced compatibility sync. Use the existing scheduler wrapper for one-off jobs and `as_schedule_recurring_action()` for the recurring store setup job.

The saved-payment-method and fee-note handlers must apply the job's `is_test_mode` context through the preserved `wcpay_test_mode` filter for the duration of the call and remove that filter afterward. The fee-note handler must not mark the queued job successful by adding an empty note when timeline data is malformed or no captured event exists. Store setup and compatibility sync failures should surface through WooCommerce logging without fataling local admin requests.

- [ ] **Task 5: Add operational queue RED/GREEN tests**

Create `WooPaymentsOperationalQueueServiceTest` covering registration gating, preserved hook callback signatures, recurring store setup scheduling, immediate store setup sync, saved-payment-method billing-details API payload, compatibility-data scheduling and payload shape, fee-breakdown note creation from a captured timeline event, and no-op-safe bails for missing orders/malformed timeline. Also cover that no native handler is registered for `wcpay_instant_deposit_reminder` or `wcpay_post_kyc_activation_email_send` in this chunk, so those actions are not silently completed by Core without their missing UI/email side effects.

- [ ] **Task 6: Bootstrap and related gates**

Register the operational queue service beside the other native WooPayments queue services in `plugins/woocommerce/includes/class-woocommerce.php`. Run focused and related PHPUnit:

```bash
pnpm --filter='@woocommerce/plugin-woocommerce' test:php:env -- --filter 'WooPaymentsOperationalQueueServiceTest|WooPaymentsOrderTrackingServiceTest|WooPaymentsActionSchedulerServiceTest|WooPaymentsWebhookReliabilityServiceTest|WooPaymentsApiClientTest|NativePaymentsRuntimeArbiterTest|NativePaymentsGatewayRegistryTest|WooPaymentsProviderGatewayAdapterTest'
```

Run PHP syntax, production PHPStan, changed PHP lint, and diff checks:

```bash
php -l plugins/woocommerce/src/Internal/Payments/Providers/WooPayments/Api/WooPaymentsApiClient.php
php -l plugins/woocommerce/src/Internal/Payments/Providers/WooPayments/WooPaymentsOperationalQueueService.php
php -l plugins/woocommerce/includes/class-woocommerce.php
composer exec --working-dir=plugins/woocommerce -- phpstan analyse src/Internal/Payments/Providers/WooPayments/Api/WooPaymentsApiClient.php src/Internal/Payments/Providers/WooPayments/WooPaymentsOperationalQueueService.php --memory-limit=2G
pnpm --filter=@woocommerce/plugin-woocommerce lint:php:changes
git diff --check
```

- [ ] **Task 7: Local queue probes without WPCOM changes**

On the target local store, use WP-CLI probes to verify that native ownership registers the preserved operational queue hooks, schedules the recurring store setup action in group `woocommerce_payments`, can execute saved-payment-method and fee-note handlers with local API preemption/filtering only where needed to avoid nondeterministic external calls, and does not complete post-KYC/instant-deposit lifecycle actions via empty handlers. Do not access or modify WPCOM. Do not mutate the reference store except for read-only comparisons.

- [ ] **Task 8: Harness, browser, log, and review gates**

Run the restored cross-store harness, browser-check target Settings Payments plus target classic and Blocks checkout, and compare reference behavior whenever any UI difference appears. Expected: the generic WooCommerce > Settings > Payments provider list still loads with WooPayments as one provider, checkout test-mode guidance remains intact, and no fresh PHP notices/warnings/fatals or checkout JS/network failures appear. Request a focused code review on queue hook BC, Action Scheduler idempotency, runtime ownership gating, API payload shape, test-mode context preservation, Settings Payments non-regression, and the explicit follow-up disposition for lifecycle hooks.

- [ ] **Task 9: Changelog, logs, and commit**

Create `plugins/woocommerce/changelog/add-native-payments-b3ad-operational-queue`:

```text
Significance: patch
Type: dev
Comment: Preserve native WooPayments operational queue hooks.
```

Update `implementation-log.md`, `staging-log.md`, and `README.md` with final evidence. Commit source/tests and changelog as separate logical commits if both are present. Do not commit scratchpad or harness files, do not push, and do not touch WPCOM.
