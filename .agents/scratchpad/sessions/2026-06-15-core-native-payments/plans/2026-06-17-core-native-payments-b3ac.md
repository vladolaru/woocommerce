---
session: 2026-06-15-core-native-payments
type: plan
by: codex
created: 2026-06-17 02:51
tool: writing-plans
reconciles:
  - implementation-log.md
  - staging-log.md
  - plans/2026-06-17-core-native-payments-b3aa.md
  - plans/2026-06-17-core-native-payments-b3ab.md
status: final
---

# Core Native Payments B3ac Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use $executing-plans to implement this plan task-by-task. Use $test-driven-development before production code changes and preserve the restored harness/browser/log gates.

**Goal:** Restore WooPayments order-tracking queue continuity in the Core-native runtime by producing and consuming the preserved `wcpay_track_new_order` and `wcpay_track_update_order` Action Scheduler hooks.

**Architecture:** Native WooPayments should keep using WooCommerce's generic order update signal as the producer, exactly like the standalone plugin, but only when the native runtime owns the site. A new focused service will register `woocommerce_update_order`, `wcpay_track_new_order`, and `wcpay_track_update_order` behind `NativePaymentsRuntimeArbiter::should_native_register()`. It will schedule preserved WooPayments queue hooks only for WooPayments orders with a provider payment method ID and Sift enabled, and the queued handlers will send WooPayments-compatible order payloads through `WooPaymentsApiClient::track_order()` while preserving the `_new_order_tracking_complete` marker and `_wcpay_mode` mode guard.

**Tech Stack:** WooCommerce Core PHP, wp-env PHPUnit, PHPStan, PHPCS, restored WooPayments merge harness, browser verification against target/reference local stores, Docker log scans.

## Tasks

- [ ] **Task 1: Add API client RED coverage**

Modify `plugins/woocommerce/tests/php/src/Internal/Payments/Providers/WooPayments/Api/WooPaymentsApiClientTest.php` with a failing test for `WooPaymentsApiClient::track_order()`. Use `FakeWooPaymentsHttpClient` and assert that the client posts to `/sites/123/wcpay/tracking/order`, serializes `order_data` and `update`, keeps `test_mode` injection, uses `POST`, and returns the decoded result. Expected RED: fatal/error because `track_order()` does not exist.

Run:

```bash
pnpm --filter='@woocommerce/plugin-woocommerce' test:php:env -- --filter 'WooPaymentsApiClientTest::test_track_order_posts_to_tracking_order_endpoint'
```

- [ ] **Task 2: Implement the native tracking API method**

Modify `plugins/woocommerce/src/Internal/Payments/Providers/WooPayments/Api/WooPaymentsApiClient.php` to add `public function track_order( array $order_data, bool $update = false ): array`. It should call the existing private `request()` helper with payload `array( 'order_data' => $order_data, 'update' => $update )`, API path `tracking/order`, and method `POST`, matching the standalone plugin's endpoint shape while keeping the native transport and request filters. Re-run the Task 1 test and expect PASS.

- [ ] **Task 3: Add order-tracking service RED coverage**

Create `plugins/woocommerce/tests/php/src/Internal/Payments/Providers/WooPayments/WooPaymentsOrderTrackingServiceTest.php`. Cover these behaviors before creating production code: native ownership registers `woocommerce_update_order`, `wcpay_track_new_order`, and `wcpay_track_update_order`; plugin ownership registers none; a WooPayments order with Sift enabled and `_payment_method_id` schedules `wcpay_track_new_order`; an already tracked order schedules `wcpay_track_update_order`; non-WooPayments orders, missing payment method IDs, Sift-disabled config, and re-entrant `doing_action( 'wcpay_track_*' )` contexts schedule nothing; `track_new_order_action()` posts order data with `_payment_method_id`, `_stripe_customer_id`, and `_wcpay_mode`, then writes `_new_order_tracking_complete=yes` when the API returns `result=success`; `track_update_order_action()` posts with `update=true` and does not rewrite the creation marker; mode mismatch returns false and does not call the API. Expected RED: class not found.

Run:

```bash
pnpm --filter='@woocommerce/plugin-woocommerce' test:php:env -- --filter 'WooPaymentsOrderTrackingServiceTest'
```

- [ ] **Task 4: Implement the order-tracking service and bootstrap**

Create `plugins/woocommerce/src/Internal/Payments/Providers/WooPayments/WooPaymentsOrderTrackingService.php` implementing `RegisterHooksInterface`. Inject `NativePaymentsRuntimeArbiter`, `WooPaymentsActionSchedulerService`, `WooPaymentsApiClient`, and `WooPaymentsAccountService` through `final public function init()`. Add constants for `wcpay_track_new_order`, `wcpay_track_update_order`, `_new_order_tracking_complete`, and a filter name such as `woocommerce_woopayments_native_fraud_services_config` that defaults to Sift enabled so local/native parity is not blocked by the removed plugin fraud service. Register hooks only when native owns runtime. Add `handle_woocommerce_update_order( $order_id, $order = null )`, `track_new_order_action( $order_id )`, and `track_update_order_action( $order_id )` as public callbacks with `@internal` docblocks. Schedule jobs through `WooPaymentsActionSchedulerService::schedule_job()` with args `array( 'order_id' => (int) $order_id )`, preserve the new-vs-update split based on `_new_order_tracking_complete`, and build the API payload from `$order->get_data()` plus `_payment_method_id`, `_stripe_customer_id`, and `_wcpay_mode`. Use `WooPaymentsAccountService::is_test_mode_enabled()` to compare `_wcpay_mode` against `test` or `prod`, matching the plugin's mode guard. Add `$container->get( Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsOrderTrackingService::class )->register();` beside the other native WooPayments runtime registrations in `plugins/woocommerce/includes/class-woocommerce.php`. Re-run Task 3 and expect PASS.

- [ ] **Task 5: Run related PHP gates**

Run focused and related PHPUnit:

```bash
pnpm --filter='@woocommerce/plugin-woocommerce' test:php:env -- --filter 'WooPaymentsOrderTrackingServiceTest|WooPaymentsActionSchedulerServiceTest|WooPaymentsWebhookReliabilityServiceTest|WooPaymentsApiClientTest|NativePaymentsRuntimeArbiterTest|NativePaymentsGatewayRegistryTest'
```

Run PHP syntax, production PHPStan, changed PHP lint, and diff checks:

```bash
php -l plugins/woocommerce/src/Internal/Payments/Providers/WooPayments/Api/WooPaymentsApiClient.php
php -l plugins/woocommerce/src/Internal/Payments/Providers/WooPayments/WooPaymentsOrderTrackingService.php
php -l plugins/woocommerce/includes/class-woocommerce.php
php -l plugins/woocommerce/tests/php/src/Internal/Payments/Providers/WooPayments/Api/WooPaymentsApiClientTest.php
php -l plugins/woocommerce/tests/php/src/Internal/Payments/Providers/WooPayments/WooPaymentsOrderTrackingServiceTest.php
composer exec --working-dir=plugins/woocommerce -- phpstan analyse src/Internal/Payments/Providers/WooPayments/Api/WooPaymentsApiClient.php src/Internal/Payments/Providers/WooPayments/WooPaymentsOrderTrackingService.php --memory-limit=2G
pnpm --filter=@woocommerce/plugin-woocommerce lint:php:changes
git diff --check
```

- [ ] **Task 6: Exercise local queue continuity without WPCOM changes**

On the target local store only, create or reuse a WooPayments-paid order with `_payment_method_id`, `_stripe_customer_id`, `_wcpay_mode`, and payment method `woocommerce_payments`. Trigger `woocommerce_update_order` through WP-CLI or an isolated probe while native owns the runtime, then assert a pending `wcpay_track_new_order` action exists in group `woocommerce_payments`. Execute the queued handler through local WP-CLI with the native API call filtered or preempted locally if deterministic WPCOM transport is not appropriate, and assert `_new_order_tracking_complete=yes` is written. Trigger another order update and assert `wcpay_track_update_order` is scheduled. Do not access or modify WPCOM, and do not mutate the reference store except for read-only comparisons.

- [ ] **Task 7: Run harness, browser, logs, and review gates**

Run the restored cross-store harness:

```bash
bash tools/woopayments-merge/verify.sh --ref 'docker exec -i wcpay_wp_default wp --allow-root' --target 'docker exec -i 24860d14de30dc62f7b324ebef10b5fb-cli-1 wp --allow-root --user=1'
```

Browser-check target Settings Payments plus target classic and Blocks checkout. Expected: Settings Payments provider list still loads with one Core-owned WooPayments row; classic and Blocks checkout still expose WooPayments test-mode guidance and Stripe iframe/session behavior. Compare the reference store when any UI difference appears. Scan target/reference Docker logs and target debug log for fresh PHP notices, warnings, deprecations, fatals, stack traces, database errors, or checkout JS/network failures. Treat fresh notices as failures unless clearly self-caused by probes and timestamp-dispositioned. Request a read-only code review focused on queue hook BC, mode/test-account behavior, API payload shape, runtime ownership gating, Action Scheduler idempotency, and Settings Payments/checkout non-regression.

- [ ] **Task 8: Changelog, session logs, and commit**

Create `plugins/woocommerce/changelog/add-native-payments-b3ac-order-tracking`:

```text
Significance: patch
Type: dev
Comment: Preserve native WooPayments order-tracking queue hooks.
```

Update `implementation-log.md`, `staging-log.md`, and `README.md` with final evidence. Commit source/tests and changelog as separate logical commits if both are present. Do not commit scratchpad or harness files, do not push, and do not touch WPCOM.
