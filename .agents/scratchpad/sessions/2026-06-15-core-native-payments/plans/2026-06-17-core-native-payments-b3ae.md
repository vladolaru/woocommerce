---
session: 2026-06-15-core-native-payments
type: plan
by: codex
created: 2026-06-17 04:16
last_updated: 2026-06-17 05:31
status: final
reconciles:
  - ../README.md
  - ../analysis-action-scheduler-handoff.md
  - ../implementation-log.md
  - ../staging-log.md
---

# B3ae Queue Cutover Finalization

## Trigger

After B3ad preserved the operational queue hooks for store setup, saved payment methods, fee notes, and compatibility data, the remaining Action Scheduler handoff risks are the failed webhook replay API source and the canceled authorization fee remediation batch loop. The Settings > Payments provider list issue was core/native wiring, not a local-environment limitation, and the target store now loads the generic provider list through the normal route/store path; this plan must not regress that UX.

## Scope

Preserve the remaining queue cutover surfaces that can silently orphan work after the standalone WooPayments plugin deactivates:

1. Replace the failed-webhook provider placeholder with the native WooPayments API client call to `webhook/failed_events`, preserving the legacy POST path, response shape, transient names, TTL, per-event process scheduling, follow-up fetch scheduling, and missing/invalid event handling.
2. Add a native canceled authorization fee remediation service that registers the preserved hooks only when native owns runtime:
   - `wcpay_remediate_canceled_authorization_fees`
   - `wcpay_remediate_canceled_authorization_fees_dry_run`
   - `wcpay_check_affected_auth_fee_orders`
3. Preserve the plugin’s option keys, batch pagination, dry-run behavior, stats/status lifecycle, query semantics for CPT and HPOS stores, WooPayments-only refund deletion, fee/net metadata cleanup, refunded-to-cancelled correction, order notes, analytics stats sync best-effort, and Action Scheduler self-rescheduling group `woocommerce-payments` for the financial remediation hooks.
4. Preserve generic Settings Payments provider parity when the browser gate exposes native WooPayments metadata gaps. In this chunk that means the native gateway must report the same saved-card and subscription support capabilities as the reference WooPayments gateway, so the existing provider-list UI can render WooPayments naturally without a WooPayments-specific frontend bypass.

## Non-Goals

- Do not implement the instant-deposit inbox reminder or post-KYC activation email jobs in this chunk; those require separate merchant-facing inbox/email surfaces and assets.
- Do not implement WooPayments mobile/IPP REST route continuity in this chunk. Subagent read-only exploration identified the missing `wc/v3/payments/*` connection-token, order terminal-payment, reader, receipt, and terminal-location routes as the next hard external contract chunk after this queue-cutover work.
- Do not edit WPCOM, WPCOM sandbox, or server code.
- Do not change the generic WooCommerce > Settings > Payments provider UX except as needed to verify it remains healthy.
- Do not mask implementation bugs by changing the harness.

## Implementation Steps

1. Add RED coverage for `WooPaymentsApiClient::get_failed_webhook_events()` that asserts POST `/sites/{blog}/wcpay/webhook/failed_events`, default `test_mode`, and decoded response shape.
2. Change `WooPaymentsFailedEventsProvider` to use `WooPaymentsApiClient` as its real source while retaining the existing filter as an additive override/test seam only if appropriate for current tests.
3. Add RED coverage for failed-event fetch error handling so an API exception does not fatally abort the AS request and does not schedule bogus follow-up work.
4. Add a `WooPaymentsCanceledAuthorizationFeeRemediationService` under the native WooPayments provider namespace, adapted from the reference plugin with Core-native names, `OrderUtil::custom_orders_table_usage_is_enabled()`, string status constant `canceled`, and defensive removal of any legacy/native cancel-authorization status hook only when available.
5. Register the remediation service through the runtime DI/boot path with the same native ownership guard as the other WooPayments queue services.
6. Add focused PHP unit tests for hook registration/guarding, affected-order lookup, live remediation mutation, dry-run non-mutation, batch status/stats, self-rescheduling in group `woocommerce-payments`, and affected-orders cache state.
7. Add focused gateway support regressions if the Settings Payments/browser parity gate finds native WooPayments support metadata diverging from the reference gateway.
8. Run the focused PHPUnit suites, then PHPStan on touched production PHP and targeted PHP lint on touched production/test PHP.
9. Run the restored harness gates and manual/browser gates:
   - Settings > Payments target store still loads generic provider list including WooPayments.
   - Reference Settings > Payments still matches expected provider-list behavior.
   - Blocks checkout target still shows WooPayments test-mode details.
   - Classic checkout target still shows WooPayments test-card details.
10. Scan Docker/debug logs since the implementation start timestamp and treat PHP notices/warnings as failures.
11. Request code review subagent coverage for the queue handoff, financial data mutation safety, Settings Payments provider parity, and WooPayments reference parity before committing.

## Verification Gates

- `pnpm --filter=@woocommerce/plugin-woocommerce test:php -- --filter WooPaymentsApiClientTest`
- `pnpm --filter=@woocommerce/plugin-woocommerce test:php -- --filter WooPaymentsWebhookReliabilityServiceTest`
- `pnpm --filter=@woocommerce/plugin-woocommerce test:php -- --filter WooPaymentsCanceledAuthorizationFeeRemediationServiceTest`
- `pnpm --filter=@woocommerce/plugin-woocommerce test:php -- --filter NativeWooPaymentsGatewayTest`
- Related native WooPayments PHPUnit suite if the focused tests uncover shared behavior changes.
- `composer exec -- phpstan analyse <touched production PHP> --memory-limit=2G` from `plugins/woocommerce`.
- `pnpm --filter=@woocommerce/plugin-woocommerce lint:php <touched PHP>` or repo-equivalent targeted lint, excluding `.agents/scratchpad`.
- Restored harness under `tools/woopayments-merge`.
- Browser checks for Settings > Payments and checkout surfaces on target and relevant reference pages.
- Compare target/reference WooPayments provider `supports` lists from the Settings Payments provider API after the browser gate.
- Fresh Docker/debug-log scan for notices, warnings, fatals, and deprecations since `2026-06-17 04:16 EEST`.

## Risks

- The financial remediation query mutates historical order data and analytics rows, so tests must prove WooPayments-created refunds are the only refunds deleted.
- Financial remediation uses the legacy hyphenated Action Scheduler group `woocommerce-payments`, unlike the webhook group `woocommerce_payments`; mixing them would leave existing queued actions orphaned or create duplicate scheduling.
- Failed webhook fetch must not regress the already-preserved transient handoff; a missing API response or API exception should be logged/skipped, not fatal.
- Gateway support parity must not overclaim merchant capabilities; it should match the reference WooPayments gateway's own saved-card and subscriptions feature gates rather than hardcoding the Settings Payments row.

## Outcome

Implemented as a single B3ae chunk. The plan expanded during browser/review gates to include native My Account add-payment-method setup-intent handling, Stripe `elements.submit()` ordering, add-payment-method Stripe error preservation, direct refund stats-row cleanup after successful refund deletion, and retry-safe live remediation cursor behavior. Final gates passed: widened native WooPayments queue/provider PHPUnit (450 tests, 1175 assertions), PHPStan, explicit PHPCS, changed PHP lint, focused classic Jest (9 tests), changed-file ESLint, normal classic asset build, `git diff --check`, restored cross-store harness 7/7 with reference order 422 and target order 104, target/reference browser checks for Settings Payments plus Blocks/classic/add-payment-method surfaces, strict Docker log scans with no PHP/WP error matches, and Galileo code review approval with critical 0, high 0, medium 0.
