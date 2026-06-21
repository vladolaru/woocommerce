---
session: 2026-06-15-core-native-payments
type: analysis
by: codex
created: 2026-06-18 22:11
last_updated: 2026-06-18 22:40
target: A5a cutover preflight closeout
reconciles:
  - analysis-action-scheduler-handoff.md
  - analysis-a4h-exit-gate.md
  - spec-conformance-baseline.md
  - staging-log.md
status: draft
---

# A5a Cutover Preflight Closeout

> **Prompt:** "When identifying bigger chunks of implementation (including more mechanical ones), consider using subagent implementors, including parallel ones where they don't risk trampling on each other, to conserve your own context and focus."

## Current Source State

A4h/N10 is committed and the browser/admin route gate established the native admin surfaces as baseline-sufficient, but `WooPaymentsCutoverController::get_preflight_failures()` still defaults `woocommerce_woopayments_native_admin_surfaces_ready` to `false`, so a clean store continues to report `native_admin_surfaces_unavailable` unless tests or local filters force it. The same preflight still reports `operational_queue_hooks_undispositioned` because `DEFAULT_PENDING_OPERATIONAL_QUEUE_HOOKS` contains `wcpay_instant_deposit_reminder` and `wcpay_post_kyc_activation_email_send`.

The provider-event blocker is already closed in source because `WooPaymentsEventIngestor::KNOWN_UNHANDLED_EVENT_TYPES` is an empty array. The legacy Stripe Billing guard remains intentionally data-dependent and must continue to block stores with preserved Stripe Billing subscription markers.

`WooPaymentsOperationalQueueServiceTest::test_registers_preserved_operational_hooks_when_native_owns_runtime()` currently asserts that `wcpay_instant_deposit_reminder` and `wcpay_post_kyc_activation_email_send` are not registered. That assertion now encodes the A5 blocker rather than the desired cutover state.

## Reference Behavior: Instant Deposit Reminder

Reference `WC_Payments_Account::INSTANT_DEPOSITS_REMINDER_ACTION` is `wcpay_instant_deposit_reminder`. The account service registers the callback and `handle_instant_deposits_inbox_reminder()` deletes the existing instant-deposits eligibility inbox note, then calls `handle_instant_deposits_inbox_note( $this->get_cached_account_data() )`.

`handle_instant_deposits_inbox_note()` bails on empty account data and on accounts without `instant_deposits_eligible`. Eligible accounts set `wcpay_instant_deposits_previously_eligible`, add the `wc-payments-notes-instant-deposits-eligible` admin note, and schedule another `wcpay_instant_deposit_reminder` 90 days later only when no pending reminder exists. The note class source is `includes/notes/class-wc-payments-notes-instant-deposits-eligible.php`; the reference unit tests around `test_handle_instant_deposits_inbox_note*` assert note creation, option write, and duplicate-scheduling guard.

Native already has account cache reads and a scheduler abstraction. It does not yet have an instant-deposits note helper. For cutover parity, native should register the preserved hook only under native runtime ownership, refresh the note from cached native account data, preserve the option key, and reschedule with the same 90-day cadence. This can live inside the operational queue owner as a small private note helper because the hook is a cutover queue handoff, not a new admin surface.

## Reference Behavior: Post-KYC Activation Email

Reference `WC_Payments_Post_Kyc_Activation_Email_Service::SEND_HOOK` is `wcpay_post_kyc_activation_email_send`. The service registers `add_option_wcpay_kyc_completion_date` to schedule stages 7, 14, and 30, registers the send hook, and registers an `admin_init` CTA click tracker. Existing queued actions call `send_email_for_stage( $stage )`.

`send_email_for_stage()` bails on invalid stage, already sent stage, missing KYC completion date, stale action execution beyond the seven-day grace window, and ineligible account/store state. Eligibility requires a connected valid account, non-test-drive account, payments enabled, live mode, KYC date present, and no live WooPayments sale. Reference `WC_Payments_Order_Service::has_live_sale()` reads sticky option `wcpay_has_live_sale`, otherwise falls back to a single `wc_get_orders()` query for `payment_method=woocommerce_payments`, statuses `wc-completed` and `wc-processing`, and `_wcpay_mode=production`, then writes the sticky option on hit. Successful email delivery appends the stage to `wcpay_post_kyc_activation_email_sent_stages`; disabled emails and mailer failures do not consume the stage. The email class records `wcpay_post_kyc_activation_email_sent` on success and `wcpay_post_kyc_activation_email_send_failed` when the mailer rejects an enabled email.

Native does not yet register `WC_Payments_Email_Post_Kyc_Activation` or the email templates. Preserving only the scheduled hook would turn existing queue entries into silent no-ops because the handler resolves the email through `WC()->mailer()->get_emails()`. For cutover parity, native should register the email class with WooCommerce emails, keep the same email ID/settings key behavior, preserve the CTA URL/query parameters, and implement the scheduled send callback with the same stage, staleness, eligibility, delivery, and option semantics. This is merchant-facing but bounded to the cutover queue handoff.

## A5a Implementation Scope

The cohesive A5a slice should close both non-data-dependent cutover preflight blockers: make native admin surfaces ready by default after A4h/N10, and implement the two remaining operational queue hook dispositions. The cutover preflight default pending queue hooks should become empty only after the native queue service registers and handles both hooks.

The slice should not remove or weaken the legacy Stripe Billing guard, should not access or modify WPCOM, and should not port unrelated admin banner UI in this pass. If post-KYC in-app banner parity remains outside this hook handoff, record it separately rather than hiding it inside the preflight closeout.

## Initial Gates

RED/GREEN unit coverage should include `WooPaymentsOperationalQueueServiceTest` for hook registration, instant-deposit note refresh/reschedule behavior, post-KYC email registration, stage scheduling, send success, invalid/stale/ineligible/no-live-sale gates, and live-sale sticky fallback. `WooPaymentsCutoverControllerTest` should prove a clean ready store no longer reports admin/queue/provider blockers while a legacy Stripe Billing marker still blocks. After unit GREEN, run the A4 admin surface gate again, the focused PHP test set, PHPCS/PHPStan for touched files, and the broader `verify.sh`/cutover probe coverage required by the standing gate.

## Implemented Result

A5a now preserves the two operational queue hooks instead of treating them as undispositioned blockers. `WooPaymentsOperationalQueueService` registers `wcpay_instant_deposit_reminder`, `wcpay_post_kyc_activation_email_send`, the account-refresh producers for instant deposits and KYC completion, the `add_option_wcpay_kyc_completion_date` scheduler bridge, the post-KYC CTA tracker, and the WooCommerce email-class filter only when native owns the runtime. `WooPaymentsCutoverController::DEFAULT_PENDING_OPERATIONAL_QUEUE_HOOKS` is empty, the native admin readiness filter defaults to `true` after A4h/N10, and the provider-event blocker remains closed through an empty `WooPaymentsEventIngestor::KNOWN_UNHANDLED_EVENT_TYPES`.

Instant Deposit parity is bounded to the reference queue behavior: eligible account refreshes write `wcpay_instant_deposits_previously_eligible`, create the preserved Woo Admin note name `wc-payments-notes-instant-deposits-eligible`, and schedule the next `wcpay_instant_deposit_reminder` through the native scheduler under the `woocommerce_payments` group. The preserved reminder deletes the old note and refreshes from cached account data.

Post-KYC parity now includes both producer and consumer sides. Account refresh records `wcpay_kyc_completion_date` once for live, payments-enabled, non-test-drive accounts, using the reference `wcpay_kyc_submitted_date` versus account `created` fallback behavior. The option-add hook schedules stages 7, 14, and 30 while skipping stale stages. The queue consumer validates stage/staleness/sent-stage/eligibility, resolves the email through the WooCommerce email registry, bails if the preserved email class is missing or replaced, sends via the native `WooPaymentsPostKycActivationEmail`, and marks `wcpay_post_kyc_activation_email_sent_stages` only after successful delivery. The email class preserves ID `wcpay_post_kyc_activation`, settings option key prefix `woocommerce_woocommerce_payments_`, templates, CTA parameters, and Tracks success/failure events.

Reviewer follow-up found two real gaps before final verification. Architecture review flagged that the first implementation constructed a fallback email outside the WooCommerce email registry; this was fixed by returning `null` and bailing when the registry omits or replaces `WC_Payments_Email_Post_Kyc_Activation`, with a regression test. Reliability review flagged that the first implementation preserved only the post-KYC consumer and missed the account-refresh KYC-completion producer; this was fixed by registering and implementing `maybe_record_kyc_completion_date()` and adding an end-to-end unit test from `woocommerce_payments_account_refreshed` to scheduled post-KYC actions. The final reliability re-review approved with critical 0, high 0, medium 0.

The target runtime probe showed native runtime enabled, separate plugin runtime inactive, and all A5a hook callbacks registered at priority 10. The first full cutover-preflight WP-CLI probe timed out after 20 seconds even though `WooPaymentsProvider::can_process_payments()` returned `true` quickly; source isolation pointed to the legacy Stripe Billing guard falling through to high-level order APIs on clean stores. This was fixed by making `WooPaymentsLegacySubscriptionsGuard` use direct bounded existence probes against both HPOS (`wc_orders`/`wc_orders_meta`) and CPT (`posts`/`postmeta`) marker storage instead of hydrating orders during preflight. A raw HPOS marker regression preserves coverage, and the real target cutover preflight now returns `preflight_failures: []` in about 1.05 seconds.

## Final Verification

- RED reviewer-driven tests failed as expected before the fixes: missing `maybe_record_kyc_completion_date`, missing account-refresh registration, and registry-filtered email still being sent.
- Focused reviewer-fix tests passed: `pnpm --filter=@woocommerce/plugin-woocommerce test:php:env -- --filter 'test_account_refresh_records_post_kyc_completion_and_schedules_staged_emails|test_account_refresh_does_not_overwrite_existing_post_kyc_completion_date|test_post_kyc_activation_email_job_skips_when_email_registry_omits_preserved_email|test_registers_preserved_operational_hooks_when_native_owns_runtime'` passed with 4 tests and 20 assertions.
- Focused A5a PHP suite passed after the guard change: `pnpm --filter=@woocommerce/plugin-woocommerce test:php:env -- --filter 'WooPaymentsOperationalQueueServiceTest|WooPaymentsCutoverControllerTest|WooPaymentsActionSchedulerServiceTest|WooPaymentsEventIngestorTest|NativeWooPaymentsGatewayTest'` passed with 153 tests and 527 assertions.
- PHPCS passed for the touched production PHP, tests, and email templates. Composer/PHPCS emitted existing auth/config deprecation warnings only.
- PHPStan passed for the touched production PHP, including `WooPaymentsLegacySubscriptionsGuard`.
- Runtime target hook probe passed: native runtime enabled, plugin runtime inactive, and `wcpay_instant_deposit_reminder`, `wcpay_post_kyc_activation_email_send`, account-refresh instant deposit, account-refresh KYC completion, KYC option-add, and email-class callbacks all registered at priority 10.
- Runtime target cutover preflight passed: `preflight_failures` was an empty array and elapsed time was about 1.05 seconds after replacing the high-level guard fallback.
- Target log scans after probes found no PHP notices, warnings, deprecations, fatals, parse errors, database errors, undefined-variable errors, or critical errors.
- Harness gates passed: `bc-drift-gate.sh` passed all categories, `a4-admin-surface-gate.py` passed with the same native chunk measurements as A4h, and `subscriptions-renewal-gate.sh preflight` passed on both reference and target stores.
