---
session: 2026-06-15-core-native-payments
type: review
by: codex
created: 2026-06-17 16:10
tool: subagent-review-reconciliation
target: N6/N7 baseline blockers and coverage gaps
reconciles:
  - spec-conformance-baseline.md
  - staging-log.md
last_updated: 2026-06-20 06:40
status: draft
---

# Review Agent Findings

This is the rolling source of truth for subagent findings that must survive compaction. Every finding below was source-verified before being promoted into the baseline.

## 2026-06-17 N6 Panel

### Hypatia - Architecture Reviewer

Verdict: BLOCKED, high 1.

Finding: B0/B1 multi-currency standalone-domain conformance is blocked because generic `Internal\MultiCurrency` code still imports concrete WooPayments classes. Verified anchors: `plugins/woocommerce/src/Internal/MultiCurrency/MultiCurrencyAdminNoteController.php` imports and injects `Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsProvider`; `plugins/woocommerce/src/Internal/MultiCurrency/Providers/CurrencyRateProviderRegistryFactory.php` imports and injects `WooPaymentsCurrencyRateProviderRegistrar`. This contradicts the implementation-plan B0/B1 gate that multi-currency must run as a payments-independent core domain with manual rates and no payments provider. Tracked as canonical B0/B1 baseline cleanup, not B3 historical payments work.

Resolution: Fixed on 2026-06-17 18:51. `MultiCurrencyAdminNoteController` now uses `MultiCurrencyProviderAccountResolver`; `CurrencyRateProviderRegistryFactory` is default-empty and accepts only provider-neutral registrars; `WooPaymentsMultiCurrencyProviderBootstrap` supplies the WooPayments account adapter and rate registrar. RED `MultiCurrencyDomainMapTest` failed on the two concrete imports before the fix, and the focused multi-currency gate passed afterward with 63 tests and 345 assertions. This resolves Hypatia's concrete-import blocker only; it does not close unrelated N6/N7 blockers.

### Fermat - Reliability Reviewer

Verdict: BLOCKED, high 3.

Finding 1: A5 cutover preflight has no queue-handoff readiness failure. Verified anchors: `WooPaymentsCutoverController::get_preflight_failures()` has no queue readiness check; `tools/woopayments-merge/queue-handoff-manifest.json` lists `wcpay_instant_deposit_reminder` and `wcpay_post_kyc_activation_email_send`; `WooPaymentsOperationalQueueService::register()` does not bind those hooks; `WooPaymentsOperationalQueueServiceTest.php` explicitly asserts both are unregistered. The reference plugin registers `wcpay_instant_deposit_reminder` in `includes/class-wc-payments-account.php` and `wcpay_post_kyc_activation_email_send` in `includes/class-wc-payments-post-kyc-activation-email-service.php`, with post-KYC jobs scheduled in the legacy `woocommerce-payments` group.

Resolution: H15 fixed the cutover-preflight portion on 2026-06-17 19:19. `WooPaymentsCutoverController` now defaults pending operational queue hooks to `wcpay_instant_deposit_reminder` and `wcpay_post_kyc_activation_email_send`, appends `operational_queue_hooks_undispositioned` while they remain pending, and fails closed on invalid pending-hook filter values. This does not port the merchant-facing instant-deposit reminder or post-KYC activation email behavior; those remain A4/A5 follow-up slices before the new filter should be cleared in production.

Finding 2: Native webhook failure paths return 400/500 without local logging. Verified anchors: `plugins/woocommerce/src/Internal/Payments/Providers/WooPayments/WooPaymentsWebhookRestController.php` catches `InvalidArgumentException` and `Throwable` and returns `bad_request`/`error` envelopes without logging; reference `includes/admin/class-wc-rest-payments-webhook-controller.php` logs both invalid-webhook and generic exception paths with `Logger::error()`.

Finding 3: Financial and perf gates are narrower than the invariants. Verified anchors: `tools/woopayments-merge/HARNESS.md` documents financial reconciliation as refunds-only and perf as a narrow query-count probe; `tools/woopayments-merge/verify.sh` still reports deterministic PASS while broad perf, browser checkout, client-side tracks, and bundle size are outside the deterministic gate. This is now expanded by N7.

### Mill - API Contract Reviewer

Verdict: BLOCKED, high 2.

Finding 1: Surviving checkout/WooPay Tracks emitters are missing in native. Verified anchors: native `plugins/woocommerce/client/blocks/assets/js/extensions/payment-methods/woopayments/woopay/index.js` handles WooPay load/click/init with no `recordEvent` or `recordUserEvent`; native checkout assets only showed a place-order hook, not Tracks calls. Reference WooPayments emits `woopay_button_load`, `woopay_button_click`, `checkout_email_address_woopay_check`, `checkout_woopay_save_my_info_offered`, `checkout_save_my_info_click`, `woopay_skipped`, `checkout_place_order_button_click`, and express Apple Pay/Google Pay load/click events from `client/checkout` and `client/express-checkout`. This violates telemetry continuity for surviving surfaces in `bc-manifest.md`.

Finding 2: Queue handoff omits live Action Scheduler hook identities. This independently confirms Fermat finding 1.

## N7 Coverage Expansion

The user added N7 after the N6 panel. This converts the financial/perf coverage caveat into a fail-closed blocker before A4/A5. Required hardening: widen `financial-reconcile.sh` beyond refunds to charge amounts, captures/authorizations, full and partial refunds, dispute outcomes, payouts, fees, and multi-currency; add a Bucket-C WooCommerce Subscriptions renewal end-to-end conformance gate against reference and target; and exercise perf and bundle-size measurements instead of relying on runbook availability.

### Parfit - Performance Reviewer

Verdict: actionable gate design for N7(c), read-only.

Finding: `verify.sh` currently calls only the narrow `perf-baseline.sh` gateway-resolution probe, covering `available_gateways`, `all_gateways`, and `wcpay_is_available`. It excludes broad perf and bundle size. Source anchors: `tools/woopayments-merge/verify.sh`, `tools/woopayments-merge/perf-baseline.sh`, `plugins/woocommerce/package.json` scripts `build:admin`, `build:blocks`, `build:classic-assets`, `test:perf`, and `test:metrics`, plus `.github/workflows/scripts/run-metrics.sh` where existing CI metrics only gate `frontend.serverResponse > 10%`.

Recommended measured-gate shape: capture artifacts under `$TMPDIR/woopayments-measured-gates/<timestamp>/ref` and `/target`; build reference and target assets with the normal WooCommerce build scripts; capture existing gateway-resolution JSON via `perf-baseline.php`; run broad k6 smoke against `tests/performance/tests/main.js`; run frontend Playwright metrics with `test:metrics frontend`; capture bundle raw/gzip bytes for WooPayments checkout/WooPay Blocks/classic assets, multi-currency admin/settings assets, and route-level JS/CSS handles/transfers for checkout, add-payment-method/order-pay, Settings Payments, and multi-currency settings; capture server-surface metrics for `process_payment`, refund, capture, REST boot/controller instantiation, gateway registration, autoload bytes, and external request counts.

Recommended scripts: add `tools/woopayments-merge/bundle-size-gate.sh capture|compare`, `tools/woopayments-merge/perf-surface-gate.sh capture|compare`, and `tools/woopayments-merge/compare-measured-gates.py`; then wire `verify.sh` to report `bundle size RULE 3` and `perf surfaces RULE 1` as measured gates. Suggested thresholds from design-spec §5.3: target route JS/CSS bytes `<=` reference unless budgeted, payment-step render median `<=` reference, process/refund/capture wall time `<= +5%`, zero new DB queries, zero new external requests, gateway/action duplicate count `0`, REST boot time/instantiation count `<=` reference, no autoload byte increase, and `wcpay_account_data` non-autoloaded.

### Bohr - Subscriptions Explorer

Verdict: actionable gate design for N7(b), read-only.

Finding: native WooPayments has WC Subscriptions support in `plugins/woocommerce/src/Internal/Payments/NativeWooPaymentsGateway.php`; when WC Subscriptions is active, native `woocommerce_payments` adds subscription supports and registers `woocommerce_scheduled_subscription_payment_woocommerce_payments` plus `woocommerce_subscription_failing_payment_method_updated_woocommerce_payments`. Reference WooPayments preserves the same integration in `includes/compat/subscriptions/trait-wc-payment-gateway-wcpay-subscriptions.php`, including renewal payment, missing-token failure handling, and failing payment method updates.

Recommended gate shape: do not seed subscriptions solely through CLI because the existing WC Subscriptions test-data CLI suppresses email and does not exercise a real browser-created WooPayments token flow. Use browser checkout on both stores for a subscription product so tokenization, `setup_future_usage`, customer ID, and subscription/order meta are real; then drive renewal through WP-CLI using the WC Subscriptions Health Check pattern from `woocommerce-subscriptions/src/Internal/HealthCheck/ToolRunner.php`: call `WC_Subscriptions_Manager::process_renewal()`, unschedule pending renewal only after an order exists, then call `WC_Subscriptions_Payment_Gateways::gateway_scheduled_subscription_payment()`, and read `$subscription->get_last_order( 'all', 'renewal' )`.

Recommended assertions: `wcs_order_contains_renewal( $renewal_order_id )` true; renewal belongs to the driven subscription; renewal order status processing/completed; subscription remains active; payment method `woocommerce_payments`; payment token exists; `_transaction_id`, `_intent_id`, `_charge_id`, `_payment_method_id`, and `_stripe_customer_id` style payment meta exists as appropriate; `parity-diff.sh` passes on renewal orders; renewal/failed-renewal email roster matches reference after normalization. Capture email evidence in the same eval-file using `woocommerce_mail_callback_params` and prevent real mail transport with `woocommerce_mail_callback`.

Failed-renewal path: deterministic missing saved-token renewal can remove the subscription token before renewal, then assert failed renewal order, on-hold subscription, note `Subscription renewal failed: No saved payment method found.`, and WCS failed-renewal invoice email parity. SCA-specific failed-renewal email parity is a likely product blocker: reference registers `failed_renewal_authentication` in `includes/compat/subscriptions/class-wc-payments-email-failed-renewal-authentication.php`, and no native equivalent was found under `plugins/woocommerce/src/Internal/Payments`.

Preconditions and caveats: target money-moving renewal cannot pass unless the target account is connected and native owns runtime; WC Subscriptions must be active in both stores; run in regular WC Subscriptions renewal mode because `gateway_scheduled_payments` can make `process_renewal()` return false when Stripe Billing owns scheduling; `wp wc-subs generate` is useful only for fixture research because it suppresses emails.

### Kant - Perf/Bundle Gate Implementer

Verdict: first-pass N7(c) gate scaffolding complete in ignored local harness; live captures exercised and currently fail/incomplete rather than green.

Implemented files: `tools/woopayments-merge/bundle-size-gate.sh`, `tools/woopayments-merge/perf-surface-gate.sh`, `tools/woopayments-merge/compare-measured-gates.py`, and `tools/woopayments-merge/HARNESS.md`.

Finding: the bundle gate can capture raw/gzip bytes for known WooPayments and multi-currency assets, records missing assets explicitly, and fails comparison on byte growth or presence drift unless a budget JSON is provided. Inline review found the original raw-path comparison too narrow for native Core, so the script now supports logical profiles for the reference WooPayments plugin and Core-native assets. Live capture data is stored under `data/n7-measured-gates/`. Bundle compare currently FAILs for missing or undispositioned target assets: classic card CSS, non-WooPay express checkout JS/CSS, multi-currency admin CSS, multi-currency analytics/setup/switcher assets, settings CSS, and `woopay-direct-checkout.js` gzip growth from `5600` to `5715`. The perf gate now captures gateway registration, callback count, autoload bytes, `wcpay_account_data` autoload state, external request count, and REST boot data without login-shell startup noise; the live compare is INCOMPLETE with favorable measured probes but still lacks process-payment, refund, capture, and REST controller-instantiation timings. Verification performed by the worker and coordinator: shell syntax checks, Python compilation, extracted PHP probe lint, bundle comparator fixtures, live reference/target captures, and fail-closed compare runs.

### McClintock - Subscriptions Renewal Gate Implementer

Verdict: first-pass N7(b) WC Subscriptions renewal gate scaffold complete in ignored local harness; live preflight passed, browser-created subscription IDs and real renewal parity runs still pending.

Implemented files: `tools/woopayments-merge/subscriptions-renewal-gate.sh`, `tools/woopayments-merge/subscriptions-renewal-drive.php`, and `tools/woopayments-merge/HARNESS.md`; scratchpad updates were made under this session folder.

Finding: `preflight` checks WC Subscriptions availability, reference plugin state, target native/plugin state, gateway supports, and renewal hook registration. `compare` requires explicit browser-created subscription IDs and fails closed when they are missing. The PHP driver blocks real email transport, captures email callback evidence, drives one renewal, and normalizes renewal/order/meta/token/customer facts for parity diff. Verification performed by the worker and coordinator: missing-ID compare guard failed closed as expected, `bash -n` for the shell gate passed, `php -l` for the driver passed, local harness docs lint passed, and live preflight against both local stores passed. Required follow-up: create browser subscriptions on reference and target, run the renewal and failed-renewal comparisons, and record any product parity gap as a tracked canonical slice.

### Dalton - Bundle Gate Classification

Verdict: the exercised N7(c) bundle failures are mostly product parity gaps, with two budget/disposition issues in the gate.

Finding: The bundle gate failed on logical assets captured from `data/n7-measured-gates/ref-bundle-20260617-1644.json` and `target-bundle-20260617-1644.json`. Source classification: `classic-card.css` is a true product gap because the reference enqueues `dist/checkout.css` from `/Users/vladolaru/Work/a8c/woocommerce-payments/includes/class-wc-payments-checkout.php`, while Core registers only the classic WooPayments script through `WooPaymentsCheckoutBridge` and `class-wc-frontend-scripts.php`. `express-checkout.js` and `express-checkout.css` are true product gaps because reference Apple/Google/Amazon express checkout registers `dist/express-checkout` assets through `/Users/vladolaru/Work/a8c/woocommerce-payments/includes/express-checkout/class-wc-payments-express-checkout-button-handler.php`, while Core currently has WooPay express support but no equivalent non-WooPay ECE source/asset. `multi-currency-admin.css` is a true product gap because reference registers `dist/multi-currency.css`, while Core's multi-currency settings projection exposes only a script and its React entry uses `.woocommerce-multi-currency-settings` classes without a stylesheet import/output. `multi-currency-analytics.js` is a true product gap because reference registers `dist/multi-currency-analytics` to add report filters/columns, while Core has backend analytics hooks but no matching admin JS hook/filter bundle. `multi-currency-switcher-block.js` is a true product gap because reference registers an editor script for `woocommerce-payments/multi-currency-switcher`, while Core registers the dynamic block server-side without an editor script. `multi-currency-setup.css` is a true product gap because reference lazy-loads setup UI styles and Core still points notes at `/payments/multi-currency-setup` without a matching route/source/asset. `settings-main.css` is a Core-owned consolidation/budget item because Settings Payments SCSS is imported into Core admin CSS output rather than a standalone `settings-main.css`. `woopay-direct-checkout.js` gzip growth is also a budget/disposition item because Core combines the reference `woopay-express-button` and `woopay-direct-checkout` concerns into one `woopayments-woopay.js`, so the gate currently compares a combined Core bundle against a smaller direct-only reference bundle.

### Aquinas the 2nd - H21 Bundle Refresh Review

Verdict: source-backed H21 classification, read-only.

Finding: H18-H20 resolved the stale non-WooPay express checkout missing-asset failures for the shopper ECE surfaces those slices implemented. Native now has separate classic and Blocks express checkout assets, and the `blocks-express-checkout.*` target assets are an expected Core split rather than a missing reference asset. The current true H21 bundle/product gap was `classic-card.css`: native classic checkout had WooPayments card JS and Blocks card CSS, but no classic card stylesheet mapped and enqueued for the classic card method. Multi-currency admin/setup/analytics/switcher assets and Settings Payments CSS still require separate product classification or implementation, not a blanket N7c pass.

Resolution: H21 adds Core `woopayments-checkout.css` through the normal classic asset workflow and enqueues it with the classic card method. The H21 bundle gate now passes for the current shopper classic card/WooPay mapping with explicit budget, while the multi-currency/admin/settings classifications remain future work.

### Kant the 2nd - H21 Perf Refresh Review

Verdict: fail-closed N7(c) measurement stance, read-only.

Finding: H21 should not describe `verify.sh` 7/7 or the existing perf harness as a full measured perf pass. Deterministic structural counts are useful, but local wall-clock timings remain smoke signals unless repeated enough to show stable large deltas. Missing capture fixtures and preinitialized REST route-registration timing must leave N7c incomplete rather than green.

Resolution: H21 records process/refund query and external-request counts, gateway/callback/controller counts, autoload bytes, and `wcpay_account_data` autoload state as stable signals. It records local process/refund timing medians only as smoke and keeps the measured perf gate `INCOMPLETE` for auth/capture and isolated REST route-registration timing.

### Planck - Subscriptions Gate Readiness

Verdict: usable existing browser-created subscriptions are available for the successful-renewal compare; failed-renewal/authentication parity remains a likely product blocker.

Finding: Reference subscription `332` is the best existing candidate if Checkout Blocks `created_via=store-api` counts as browser-created. Target native subscription `51` is active, WooPayments-paid, browser-created, and has subscription token plus `_payment_method_id` and `_stripe_customer_id`. Do not use reference `381` because it is pending with failed parent order and no token/meta; do not use target `49` because it lacks subscription token/payment/customer meta; do not use target `47` because it is pending with failed parent order and no token/meta. A stricter same-style classic reference pair could be created from product `378` (`Codex Reference Trial Subscription`) if needed, but the minimal compare can run with `--ref-subscription-id 332 --target-subscription-id 51`.

Finding: Successful-renewal compare may fail on email facts because the stores use different site titles (`WooCommerce Payments Dev` versus `woocommerce`) and the current harness normalization does not normalize site titles in email subjects. Failed-renewal parity is a stronger blocker: the current driver expects successful renewal order status, reference WooPayments registers `failed_renewal_authentication` and `failed_authentication_requested`, and native target does not register those classes at runtime.

### Rawls the 2nd - Subscriptions Checkout Request Explorer

Verdict: source-backed request-shape comparison, read-only.

Finding: Reference and native initial paid subscription checkout request shapes are broadly aligned for connected-account payment methods. Both submit `wcpay-payment-method` from Blocks, create a customer, send `payment_method`, `customer`, `payment_method_types=['card']`, and set `setup_future_usage=off_session` for recurring card payments. Native also sends recurring metadata (`payment_type=recurring`, `subscription_payment=initial`, `payment_context=regular_subscription`) from `WooPaymentsProviderGatewayAdapter`. Source anchors: reference `client/checkout/blocks/payment-processor.js`, reference `includes/class-wc-payment-gateway-wcpay.php`, reference `src/Internal/Service/OrderService.php`, native `StoreApi/Legacy.php`, native `NativeWooPaymentsGateway.php`, and native `WooPaymentsProviderGatewayAdapter.php`.

Finding: Reference pre-persists `_payment_method_id`, `_stripe_customer_id`, and `_wcpay_mode` before intent creation, while native writes payment/customer/intent meta only from the final `PaymentOutcome`. This means native loses useful evidence when the API call or later token-save path fails. Rawls also identified a native token-save failure path where a successful intent can be converted into a failed outcome with no carried payment/customer/intent IDs. Track as N7b reliability hardening if reproduced after the platform-payment-method root cause is addressed.

### Peirce the 2nd - WPCOM V1 Contract Explorer

Verdict: source-backed API-contract comparison, read-only local WPCOM clone only.

Finding: Native initial checkout calls `POST /wpcom/v2/sites/{blog_id}/wcpay/intentions`, which WPCOM forwards to Stripe `payment_intents`. WPCOM extracts only declared route args and strips local-only fields before proxying. The `payment_method` prefetch in `class-intentions-controller.php` happens before PaymentIntent creation for fee calculation. If the submitted `pm_...` is not retrievable under the connected account, WPCOM throws a WP REST `wcpay_bad_request` before any intent exists. That exactly matches the observed native failure pattern: `_intention_status=requires_payment_method` with no `_intent_id`, `_payment_method_id`, or `_charge_id`.

Finding: Native error mapping drops useful nested Stripe failure context. `WooPaymentsApiClient` preserves top-level `{ error: { code, message } }` or WP REST `{ code, message }`, but ignores nested `error.payment_intent`; `PaymentExceptionPolicy` then creates a failed outcome with empty provider ID/payment method/customer. This should be hardened so provider failure context and shopper-facing errors do not disappear, but the immediate live failure is the platform-owned payment method being sent as a connected-account `payment_method`.

### Cicero the 2nd - Refund Status Reliability Review

Verdict: high finding fixed.

Finding: Native refund normalization mapped every provider refund status except literal `pending` to a successful local outcome. Source anchor: `WooPaymentsProviderGatewayAdapter::normalize_refund_result()` converted missing or non-pending status to `PaymentOutcome::STATUS_COMPLETED`, which meant Stripe statuses such as `failed`, `canceled`, or `requires_action` could incorrectly write WooPayments refund success meta and notes. Fix: added RED/GREEN regression `WooPaymentsProviderGatewayAdapterTest::test_refund_fails_closed_for_failed_native_refund_status`; native refund normalization now fails closed for non-empty statuses outside `pending` and `succeeded`, preserves provider refund ID, status, failure reason/error code, and avoids `refund_meta`/`order_meta` success writes on failed outcomes. Verification: focused regression passed, and the wider native payment PHP gate passed with 122 tests and 660 assertions.

### Mill the 2nd - Renewal Customer Contract Review

Verdict: high finding fixed.

Finding: Scheduled subscription renewals could source `_stripe_customer_id` from the original subscription parent order when the renewal order had no customer meta. Source anchors: `NativeWooPaymentsGateway::get_renewal_order_customer_id()` originally fell back directly to `get_subscription_parent_order_for_renewal()`, while the current subscription can be updated after the parent order through saved-payment-method changes. That could pair the current saved payment method with a stale customer ID during renewal. Fix: added RED/GREEN regression `NativeWooPaymentsGatewayTest::test_scheduled_subscription_payment_uses_current_subscription_customer`; native renewal customer sourcing now prefers renewal order meta, then current subscription meta via the WCS renewal-order relationship, then parent-order fallback. Mandate lookup remains parent-order scoped. Verification: focused regression passed, broader native payment PHP gate passed with 122 tests and 660 assertions, and the live reference-vs-target Subscriptions renewal compare passed twice more with no appended warning/error log entries.

### Carver the 2nd - B0/B1 Boundary Architecture Review

Verdict: approved, critical 0, high 0, medium 0.

Finding: No blocking architecture or coupling regressions in the B0/B1 multi-currency provider-boundary slice. The reviewer confirmed generic `Internal\MultiCurrency` now uses `MultiCurrencyProviderAccountResolver`, the rate factory no longer imports WooPayments classes, WooPayments-specific account/rate wiring is centralized in `WooPaymentsMultiCurrencyProviderBootstrap`, and `class-woocommerce.php` still registers that bootstrap before the multi-currency controllers consume the resolver/factory.

Residual risk: `CurrencyRateProviderRegistryFactory::set_provider_registrars()` remains a replacement-based mutable singleton seam. This is acceptable for the current single WooPayments registrar, but future multi-provider registration should use an owned/additive registration shape or a clearer provider-composition configurer. The reviewer also noted that if the WooPayments bootstrap grows, a dedicated bootstrap test may be clearer than keeping integration coverage in the generic factory test.

### Arendt the 2nd - Webhook Failure Logging Reliability Review

Verdict: medium finding fixed.

Finding: Native webhook failure logging initially called `$logger->error()` while already handling webhook failures, so a custom/local WooCommerce logger exception could replace the intended `400 bad_request` or `500 error` webhook envelope. Source anchor: `WooPaymentsWebhookRestController::log_webhook_exception()`. Fix: added RED/GREEN `WooPaymentsWebhookRestControllerTest::test_logger_failures_do_not_replace_webhook_error_envelope`; native logging now catches and swallows logger write failures after retrieving the logger, preserving the webhook response contract. Verification: focused regression passed, related webhook/event gate passed with 29 tests and 81 assertions, and static checks passed.

### Kierkegaard the 2nd - Multi-Currency Boundary Recheck

Verdict: source-backed blocker refinement, read-only.

Finding: The original Hypatia production blocker is no longer present in `src/Internal/MultiCurrency`: a fixed-string/source scan shows no production import of `Internal\\Payments\\Providers\\WooPayments` under the generic multi-currency source tree. Remaining concrete coupling is ownership/bootstrap-level and test-level: `class-woocommerce.php` explicitly registers `WooPaymentsMultiCurrencyProviderBootstrap` before generic multi-currency controllers, and generic multi-currency tests still instantiate WooPayments provider bootstrap/registrars in `CurrencyRateProviderRegistryFactoryTest` and `MultiCurrencyStateBuilderFactoryTest`. Compatibility strings/hooks such as `_wcpay_multi_currency_*` and `wcpay_multi_currency_*` remain intentional Bucket-E/Bucket-C preservation, not concrete provider-class coupling.

Disposition: The production architecture blocker that Hypatia found was fixed by the B0/B1 provider-boundary slice. Keep the residual test/bootstrap cleanup as B0/B1 hygiene or future provider-composition hardening, but do not carry it forward as an active production-domain import blocker.

### Linnaeus the 2nd - Remaining Provider Event Mapping

Verdict: source-backed H30/H31 scope split, read-only.

Finding: After H29, native `WooPaymentsEventIngestor::KNOWN_UNHANDLED_EVENT_TYPES` contains `account.deleted`, `account.updated`, `invoice.paid`, `invoice.payment_failed`, `invoice.upcoming`, and `wcpay.notification`. Reference WooPayments account events refresh account data, clear all cached payment methods, and for account deletion run onboarding reset plus NOX option cleanup. Reference `wcpay.notification` passes webhook `data` directly to the remote note service, so native cannot require `data.object` for that event. Reference invoice events are subscription billing/order paths with renewal success/failure/upcoming-invoice side effects.

Disposition: H30 should migrate account lifecycle plus remote notification provider events together. Invoice events should remain fail-closed and tracked with the Bucket-C subscription invoice/renewal slice.

### Plato the 2nd - Operational Hooks and Webhook Logging Recheck

Verdict: source-backed blocker split, read-only.

Finding: `wcpay_instant_deposit_reminder` and `wcpay_post_kyc_activation_email_send` are still listed in `tools/woopayments-merge/queue-handoff-manifest.json`, still block cutover by default in `WooPaymentsCutoverController::DEFAULT_PENDING_OPERATIONAL_QUEUE_HOOKS`, and are still explicitly absent in `WooPaymentsOperationalQueueServiceTest`. Reference behavior includes instant-deposit inbox note/reminder scheduling on eligible account refresh and post-KYC activation email scheduling/sending at 7/14/30-day stages. Native already covers other operational hooks such as store setup sync, saved payment method updates, fee breakdown notes, compatibility updates, order tracking, webhook retry hooks, and failed event handling.

Finding: Webhook failure logging is no longer absent, but the native source differs from the reference source. Native currently logs through the runtime logger with source `native-payments-webhook`, and tests assert that source; reference logs through the WooPayments logger source `woopayments`.

Disposition: Implement operational queue disposition as its own A2/A5 slice and remove the two hooks from the pending preflight only after behavior lands. Treat logging-source parity as a separate observability decision, not part of H30 account/notification provider-event migration.

### Laplace the 2nd - H15 Queue Cutover Reliability Review

Verdict: approved, critical 0, high 0, medium 0.

Finding: No reliability blockers in the H15 queue cutover preflight diff. The reviewer confirmed the default pending hooks feed into `get_preflight_failures()`, `operational_queue_hooks_undispositioned` blocks cutover unless the filter explicitly returns an empty list, invalid filter values fail closed through `operational_queue_hooks_filter_invalid`, and focused tests directly cover the new blocker plus ready-path clearing.

### Ampere the 2nd - H15 Queue Cutover WP Architecture Review

Verdict: approved, critical 0, high 0, medium 0.

Finding: No WordPress/WooCommerce architecture blockers in the H15 queue cutover preflight diff. The reviewer confirmed the filter is properly prefixed and documented where applied, the default pending hook list matches the queue-service non-registration coverage, and the tests isolate provider-event readiness from operational queue readiness.

### Volta/Pascal - H16 Tracks Continuity Exploration

Verdict: source-verified implementation scope, read-only.

Finding: Reference WooPayments shopper checkout telemetry is not the same as Core generic admin Tracks. Reference `recordUserEvent()` posts to `platform_tracks`; the PHP receiver prefixes events with `wcpay_` and applies `wcpay_tracks_event_properties`. Core's `window.wcTracks.recordEvent()` path prefixes `wcadmin_` and is admin-footered, so using `@woocommerce/tracks` directly for shopper checkout parity would drift the contract. This is now anchored in `analysis-h16-tracks-continuity.md`.

Scope boundary: Restore surviving native checkout/WooPay events now: `checkout_place_order_button_click`, `woopay_button_load`, `woopay_button_click`, `checkout_woopay_save_my_info_offered`, and `checkout_save_my_info_click`. Do not fake `checkout_email_address_woopay_check` or `woopay_skipped` because the native email-iframe/skip surface does not currently exist. Leave Apple Pay / Google Pay tracking with the broader non-WooPay express checkout parity blocker.

### Lagrange the 2nd - H16 API Contract Review

Verdict: approved, critical 0, high 0, medium 0.

Finding: No blocking API/event contract drift in the H16 native Tracks continuity slice. The reviewer confirmed the native bridge preserves the reviewed WooPayments shopper Tracks AJAX names, nonce/body fields, event names/properties, server-side `wcpay_` prefixing, filters, identity endpoint shape, and guest/logged-in hooks for surviving card/WooPay checkout surfaces. Residual scope remains page-view/order-placed/email-check/mobile-enter/TOS/privacy/country-click telemetry and non-WooPay express events, which are explicitly out of H16.

### Curie the 2nd - H16 Architecture Review

Verdict: high and medium findings fixed, final re-review approved with critical 0, high 0, medium 0.

Finding 1: Native initially used generic `WC_Tracks_Client::get_identity()` for WooPayments shopper events and the `get_identity` endpoint, which could fall back to `_woocommerce_tracks_anon_id` / `woo:` identities and split pre/post-cutover shopper identity from the reference WooPayments `jetpack_tracks_wpcom_id` / `jetpack_tracks_anon_id` contract. Fix: `WooPaymentsFrontendTrackingController` now uses a provider-local Jetpack-compatible identity adapter for both event properties and `get_tracks_identity_response()`, preferring `jetpack_tracks_wpcom_id` for connected users and `jetpack_tracks_anon_id` / generated `jetpack:` anonymous IDs otherwise. RED/GREEN coverage was added in `WooPaymentsFrontendTrackingControllerTest`.

Finding 2: Native initially did not expose the server-side shopper-tracking-enabled boolean in card/WooPay frontend configs, so disabled tracking still sent avoidable frontend `platform_tracks` AJAX requests and relied entirely on the server no-op. Fix: `WooPaymentsCheckoutBridge` and `WooPaymentsWooPaySessionService` now consume `WooPaymentsFrontendTrackingController::is_shopper_tracking_enabled()` and expose `isShopperTrackingEnabled` / `is_shopper_tracking_enabled` through the provider configs; Blocks helper and classic tests cover the disabled no-op path. Curie re-reviewed and found no new reportable architecture issues.

### Zeno/Epicurus - H22 Express Checkout Tracks Exploration

Verdict: source-verified implementation scope, read-only.

Finding: Reference WooPayments Express Checkout shopper Tracks hard-preserve set is Apple Pay and Google Pay only: clients send unprefixed `applepay_button_load`, `gpay_button_load`, `applepay_button_click`, and `gpay_button_click` through `platform_tracks` with payload `{ source: <button_context> }`, and the server prefixes them to `wcpay_*`. Valid source values from the reference are `product`, `cart`, `checkout`, and `pay_for_order`. Reference anchors include `client/express-checkout/tracking.js`, shared ready/click handlers in `client/express-checkout/event-handlers.js`, shortcode/block shared handler callers, and PHP context mapping in `class-wc-payments-express-checkout-button-helper.php`. Native already marks the four server-prefixed Apple/Google events as `track_on_all_stores` in `WooPaymentsExpressCheckoutController::add_tracking_event_properties()`.

Finding: Amazon Pay has no reference shopper Tracks button event mapping. `amazonPay` ready and `amazon_pay` click are ignored by the reference tracking map, so H22 must not invent `amazonpay_button_*` events. This also means no PHP all-store tracking event expansion is needed for Amazon in H22.

Finding: Native classic ECE over-emitted before H22 by recording both Apple Pay and Google Pay load events for any truthy `availablePaymentMethods`, and both click events for every click. Native classic ECE params also had `ajax_url` and `nonce.platform_tracker` but not the `isShopperTrackingEnabled` / `is_shopper_tracking_enabled` flags that the frontend guard already checked. Native Blocks ECE had method-specific metadata but recorded load on any truthy `availablePaymentMethods` and did not pass express params tracking-enabled flags into `recordWooPaymentsUserEvent()`. H22 fixes target these gaps only.

### Singer and James the 2nd - H22 Final Diff Review

Verdict: approved, critical 0, high 0, medium 0.

API contract finding: No blocking API-contract issues in the H22 diff. The reviewer confirmed classic and Blocks ECE only send Apple/Google load/click event names with `{ source }`, Blocks has no Amazon Pay tracking event, express params expose disabled shopper tracking in both camel/snake forms, and `WooPaymentsFrontendTrackingController` still applies the server-side `wcpay_` prefix. Caveat: static final-diff review only; coordinator ran the Jest/PHPUnit/build gates.

Performance finding: No blocking performance or bundle-scope issues in the H22 diff. The reviewer found no new entrypoints, no tracking helper pulled outside the existing WooPayments bundles, no WooPay/card coupling, no unbounded tracking storms, and no tracking fetches when shopper tracking is disabled. Caveat: static final-diff review only; coordinator ran the normal classic and Blocks builds rather than relying on the reviewer for generated-asset measurement.

## 2026-06-18 H23 Failed-Renewal Authentication Reviews

### Aristotle the 2nd - H23 API Contract Review

Verdict: medium finding fixed.

Finding: The first Core-native failed-renewal email implementation preserved WooPayments email class keys and ids but changed the template identifiers from the legacy WooPayments override names to Core-specific `emails/woopayments-*` paths. That would silently skip existing theme overrides or filters targeting `failed-renewal-authentication.php`, `plain/failed-renewal-authentication.php`, `failed-renewal-authentication-requested.php`, and `plain/failed-renewal-authentication-requested.php`. Fix: moved the templates to the legacy override names and updated both email classes; added focused assertions for the preserved template identifiers. Verification: the template identifier regression failed before the move and passed after.

### Hilbert the 2nd - H23 Reliability Review

Verdict: high and medium findings fixed; one medium parity note source-dispositioned.

Finding 1: `NativeWooPaymentsGateway::maybe_handle_subscription_customer_action_required()` fired `woocommerce_woocommerce_payments_payment_requires_action` before persisting failed renewal state, so a throwing email/template/third-party hook callback could leave the renewal pending and skip failure notes. Fix: caught and logged `Throwable` around the preserved hook while still failing the renewal and adding the WooPayments-style note. Verification: RED/GREEN `NativeWooPaymentsGatewayTest::test_scheduled_subscription_payment_fails_when_requires_action_hook_throws`.

Finding 2: Subscription email registration originally used object-level `woocommerce_email_classes` callbacks, so multiple native gateway instances could register duplicate email objects and duplicate failed-authentication hooks. Fix: moved registration to a class-level `NativeWooPaymentsGateway::add_subscription_emails()` callback guarded by `has_filter()`. Verification: RED/GREEN `NativeWooPaymentsGatewayTest::test_subscription_email_registration_is_idempotent_across_gateway_instances`.

Dispositioned note: the reviewer flagged global suppression of original WC Subscriptions retry emails. Source verification against the WooPayments extension showed the reference failed-renewal authentication email removes those hooks globally and does not restore them, so H23 intentionally preserves that behavior rather than inventing a different lifecycle.

### Mencius the 2nd - H23 Architecture Review

Verdict: medium finding fixed.

Finding: The native scheduled-renewal hook path originally extracted `_charge_id` from `PaymentOutcome::get_data()['meta']`, creating an implicit contract between WooPayments provider internals, the generic lifecycle meta bag, and the subscription hook payload. Fix: `WooPaymentsProviderGatewayAdapter` now exposes `charge_id` as explicit provider outcome data while keeping legacy `_charge_id` meta for order storage, and `NativeWooPaymentsGateway` prefers the explicit field with a meta fallback. Verification: RED/GREEN `WooPaymentsProviderGatewayAdapterTest::test_charge_sets_card_display_meta_before_returning_requires_action` and focused native gateway hook tests.

## 2026-06-18 N7c Sidecar Status After H23

### Hegel the 2nd - N7c Bundle/Perf Exploration

Verdict: N7c remains fail-closed; shopper ECE/card/WooPay bundle regressions from older captures are resolved or dispositioned, but multi-currency/admin assets and measured perf fixtures remain open.

Finding: Non-WooPay Express Checkout missing assets are stale after H18-H20, `classic-card.css` is resolved by H21 `woopayments-checkout.css`, and H22/H23 added no new frontend bundle scope. Current product gaps remain in multi-currency/admin assets: script-only multi-currency settings projection, no matching multi-currency analytics admin JS, and no editor/setup asset parity for the multi-currency switcher/setup surfaces. Measurement-profile gaps remain for Core-consolidated Settings Payments CSS and WooPay bundle consolidation budgets. Perf remains `INCOMPLETE` because capture/auth lacks a real `requires_capture` fixture and isolated REST route-registration timing is not yet measured. Recommended follow-up: an N7c admin/multi-currency bundle closeout plus perf fixture extension, with `verify.sh` kept separate from any measured bundle/perf pass.

### Faraday the 2nd - N7a Financial Matrix Exploration

Verdict: H24 auth/capture is the highest-throughput N7a slice because it closes one product gap and one deterministic driver gap while unlocking stronger perf fixtures.

Finding: Auth/capture remains open. Native capture execution exists through `WooPaymentsProviderGatewayAdapter::capture()`, but native card checkout does not create manual-capture PaymentIntents today: `WooPaymentsApiClient` defaults `capture_method` to `automatic`, and `WooPaymentsProviderGatewayAdapter::build_native_charge_request_data()` does not send a `capture_method`. The WooPayments extension sends the manual/automatic capture method on the PaymentIntent request, so native manual-capture intent creation is a product gap rather than only a harness issue.

Finding: Dispute outcome ingestion remains a product gap because native still classifies `charge.dispute.*` events as known-unhandled and throws for them. Payout linkage and target multi-currency money fixtures remain coverage/driver gaps first: the comparator can read payout and exchange-rate facts, but no proven target fixture exercises those paths end to end yet. Refund reconciliation is no longer the urgent N7a blocker after target/reference full and partial refunds reconciled and refund-status reliability was fixed.

Recommended next gates: add RED/GREEN coverage for native `capture_method`, add deterministic auth/capture harness drivers, run reference and target auth/capture orders through `financial-reconcile.sh`, and keep any payout/multi-currency/dispute findings separated as their own product or driver gaps after source verification.

### Harvey the 2nd - A4/A5 Readiness Exploration

Verdict: A4/A5 must remain fail-closed after H23/H24; admin dashboard, provider events, queue hooks, and broadened verification gates are not ready to declare baseline-green.

Finding: The A4 merchant admin dashboard is still not native. Cutover correctly fails closed on `native_admin_surfaces_unavailable`, `settings-payments-woopayments.tsx` remains a placeholder surface, and the full dashboard remains plugin-served per the supervisor prompt. Do not weaken this gate while H24 focuses on provider/service money paths.

Finding: Provider events remain undispositioned. Cutover fails closed on `provider_events_undispositioned`, and `WooPaymentsEventIngestor::KNOWN_UNHANDLED_EVENT_TYPES` still includes account, dispute, refund, invoice, and notification event families. Two operational queue hooks also remain product gaps: `wcpay_instant_deposit_reminder` and `wcpay_post_kyc_activation_email_send`.

Finding: Verification gates are not fully green. H23 did not claim the broader failed-renewal browser comparison, N7a still needs auth/capture/dispute/payout/multi-currency coverage, and N7c still has admin/multi-currency bundle and perf-fixture gaps. Recommended later slice: provider-event plus queue-readiness disposition while keeping A4 admin unavailable until native merchant dashboard parity exists.

## 2026-06-18 H24 Auth/Capture Reviews

### Copernicus the 2nd - H24 Reliability Review

Verdict: approved, critical 0, high 0, medium 0.

Finding: No blocking reliability findings in the tracked H24 product diff. The reviewer confirmed authorized orders now persist the PaymentIntent as transaction id, capture/cancel fall back through `_intent_id`, and native manual-capture intent creation is wired from the provider setting. Residual risk outside H24: the ignored perf harness still treats capture fixtures without a transaction id as incomplete, so product tests prove the `_intent_id` fallback but the perf harness does not gate it yet.

### Bacon the 2nd - H24 API Contract Review

Verdict: high finding fixed.

Finding: The first H24 manual-capture fix applied `manual_capture=yes` to every native charge request, including scheduled subscription renewals. Reference WooPayments builds renewal `Payment_Information` with null capture type and keeps renewal PaymentIntents automatic, so native would have left off-session renewals authorized/on-hold when merchants enabled manual capture. Fix: native `capture_method` is now `manual` only for non-renewal checkout and `automatic` for scheduled renewals. Verification: RED/GREEN `WooPaymentsProviderGatewayAdapterTest::test_charge_keeps_scheduled_renewal_capture_method_automatic_when_manual_capture_is_enabled` failed with `manual` before the fix and passed after; broader native payment PHP gate passed with 125 tests and 626 assertions.

## 2026-06-18 H25 Dispute Webhook Reviews

### Lorentz the 2nd - H25 Reliability Review

Verdict: high finding fixed.

Finding: Native lost-dispute closure initially ignored the `wc_create_refund()` return value. If WooCommerce failed to persist the local refund, native would still add the closed-dispute note and acknowledge the webhook, causing duplicate deliveries to skip by note dedupe while the order remained financially unreconciled. Fix: `WooPaymentsDisputeEventHandler::create_dispute_lost_refund()` now logs and throws when `wc_create_refund()` returns `WP_Error` or an unexpected value, before the closed note is added. Verification: RED/GREEN `WooPaymentsEventIngestorTest::test_dispute_closed_lost_fails_closed_when_local_refund_creation_fails` failed before the fix and passed after; the focused H25 regression subset passed with 8 tests and 46 assertions.

### Socrates the 2nd - H25 API Contract Review

Verdict: high finding fixed.

Finding: Native dispute webhook handling initially defaulted malformed required fields into side effects. Missing `status` or `id` on `charge.dispute.closed` could complete the order or create a refund and acknowledge the event; missing `reason`, `amount`, `evidence_details`, `due_by`, or `status` on `charge.dispute.created` could place the order on hold with default content. The WooPayments extension uses required-property reads for these fields and fails closed when absent. Fix: `WooPaymentsDisputeEventHandler` now requires `charge` for all dispute events, `status/id` for closed events, and `status/reason/amount/evidence_details/due_by` for created events before adding notes, changing status, or refunding. Verification: RED/GREEN `test_dispute_closed_fails_closed_when_required_fields_are_missing` and `test_dispute_created_fails_closed_when_required_fields_are_missing` failed before the fix and passed after; the broader focused native payment gate passed with 146 tests and 681 assertions.

## 2026-06-18 H26 Provider-Created Dispute / Harness Reviews

### Russell the 2nd - H26 Architecture Review

Verdict: concern dispositioned as reference parity.

Finding: The reviewer flagged that update-style dispute webhooks clear caches only when `process_dispute_updated()` adds a new note, so duplicate update webhooks leave existing caches intact. Source verification against `woocommerce-payments/includes/class-wc-payments-webhook-processing-service.php` showed the reference extension returns before `delete_dispute_caches()` when the exact update note already exists. Native intentionally preserves that battle-tested duplicate-update behavior rather than inventing stricter invalidation semantics in this parity slice. DI and provider-boundary placement were accepted: the cache invalidator is WooPayments-provider-owned and resolved through the WooCommerce container when tests do not inject it.

### Locke the 2nd - H26 Reliability Review

Verdict: medium findings fixed; final follow-up PASS.

Finding 1: The first dispute e2e gate could pass without ever observing the created-dispute `on-hold` transition. Fix: `dispute-e2e-gate.sh` now requires `has_on_hold_transition`, sourced from current transient status or persisted order status-change notes, and compares that durable fact instead of final current status. Final current status is logged only because async payment/dispute event ordering can race between `on-hold` and `processing`.

Finding 2: The first financial comparator accepted generic dispute-note evidence when provider disputes had no WC dispute ID. Fix: `financial-reconcile-normalize.py` now requires WooCommerce order notes to match provider dispute amount/reason/current charge details; the fixture suite covers correct no-`_dispute_id` evidence, no side effect, wrong amount, and the `$150.00` superstring case for a `$50.00` provider dispute. Live reference order 511 and target order 211 reconciled after this fix.

### Fermat the 2nd - H26 Performance/Harness Measurement Review

Verdict: medium findings fixed; final follow-up PASS.

Finding: The narrow perf gate and measured comparator risked overclaiming. Fixes: `verify.sh` now labels the gate `perf smoke (gateway query-count)`; `compare-measured-gates.py` tightens low-baseline money-path query tolerance from `+10` to `+1`, while median timing only fails on large deltas; `HARNESS.md`, `perf-baseline.sh`, `perf-baseline.php`, and `tools/woopayments-merge/README.md` now state that broad §5.3 perf remains a separate stage gate. The only remaining RULE-1 wording in the harness explicitly says the narrow gate is not RULE-1 verification.

## 2026-06-18 H27 Payout / Converted-Currency Reviews

### Hypatia the 2nd - H27 Reliability Review

Verdict: medium findings fixed.

Finding: The first H27 settlement meta formatter stripped integer trailing zeroes from interpreted exchange rates, so a value such as `10` would have been persisted as `1`. Hypatia also found missing `@since` annotations on the new public methods. Fix: `WooPaymentsOrderDataService::format_exchange_rate()` now trims trailing zeroes only after a decimal point, the integer-rate regression failed before the fix and passed after, and the new public methods carry `@since 11.0.0`.

## 2026-06-18 H28 Pre-Slice Sidecar Findings

### Newton the 2nd - N7c Bundle/Perf Refresh Exploration

Verdict: N7c remains fail-closed; H18-H21 resolved stale shopper bundle blockers, but multi-currency/admin asset classifications and fresh perf fixtures remain open.

Finding: `express-checkout.*`, `blocks-express-checkout.*`, and `classic-card.css` are no longer current source-backed bundle blockers. Remaining not-product-proven bundle gaps are `multi-currency-admin.css`, `multi-currency-analytics.js`, `multi-currency-switcher-block.js`, and `multi-currency-setup.css`. `settings-main.css` is a Core consolidation/budget item rather than a standalone target asset, `woopay-direct-checkout.js` is a mapping/budget issue because Core combines WooPay direct and express into `woopayments-woopay.js`, and Blocks ECE is an intentional Core split. Perf probes are now exercisable if H28 mints fresh process, refund, and manual-capture fixtures; captured H27 IDs should not be reused for capture timing because they were already captured. Newton recommended treating `median_ms` as smoke only and gating mainly on queries, external requests, callback/controller counts, autoload bytes, and explicit missing coverage.

### Hume the 2nd - Provider-Event Cutover Exploration

Verdict: provider-event cutover remains blocked after H27 and should stay tracked separately from H28.

Finding: `WooPaymentsEventIngestor::KNOWN_UNHANDLED_EVENT_TYPES` still contains `account.deleted`, `account.updated`, `charge.refund.updated`, `charge.refunded`, `invoice.paid`, `invoice.payment_failed`, `invoice.upcoming`, and `wcpay.notification`. `WooPaymentsCutoverController` consumes that list directly and its tests still assert cutover fails with `provider_events_undispositioned`. Hume recommends migrating the refund webhook group first because `charge.refunded` and `charge.refund.updated` preserve direct money/order-state behavior and are less entangled than the invoice/subscriptions group. This is a source-backed A4/A5 blocker but not the selected H28 N7c slice.

## 2026-06-18 H28 Measured Perf / Bundle Reviews

### Franklin the 2nd - H28 Frontend/Bundle Review

Verdict: approved, no blocking findings.

Finding: The reviewer verified the SCSS import/build path, PHP manifest shape, controller asset availability/register/enqueue flow, and separate WooPayments bundle ownership. The admin build auto-discovers `wp-admin-scripts` entries and emits `multi-currency-settings/style.css` plus `style.asset.php`; WooPayments checkout/block assets remain separately wired through the Blocks build entries and PHP block integration. Residual note: the reviewer did not run the full admin webpack build or PHP unit tests, but the main session did.

### Carson the 2nd - H28 Performance Evidence Review

Verdict: approved, no blocking findings.

Finding: The reviewer confirmed the capture-failure lifecycle change keeps authorized orders `on-hold`, the Sift default gate avoids accidental Action Scheduler work, and capture failure outcomes carry `_intention_status=requires_capture` plus a WooPayments-style note. Evidence must be recorded as bundle `PASS with explicit H28 budget` and perf `INCOMPLETE`, not full PASS, because both perf captures have `route_registration_status=preinitialized`. Timings are coarse single-invocation blocked-HTTP smoke values; remaining analytics/switcher/setup bundle gaps are dispositioned, not proven equivalent.

## 2026-06-18 H29 Refund Webhook Reviews

### Ecosystem Integration Review - H29

Verdict: high and medium findings fixed.

Finding 1: The first H29 diff accepted only the exact `woocommerce_payments` gateway ID, so split-UPE orders such as `woocommerce_payments_sepa_debit` would fail refund webhooks even though the WooPayments extension and neighboring native dispute/payment ownership checks accept the WooPayments split-gateway prefix. Fix: `WooPaymentsRefundEventHandler` now accepts exact and prefixed WooPayments gateway IDs; regression coverage proves split-UPE refund handling.

Finding 2: The first H29 diff formatted merchant refund notes with plain `wc_price()`, dropping the explicit currency suffix that WooPayments preserves on multi-currency stores. Fix: native notes now prefer `WC_Payments_Explicit_Price_Formatter::get_explicit_price()` when available and use a native explicit-currency fallback for active multi-currency.

### Reliability / PHP Test Review - H29

Verdict: earlier high/medium findings fixed before final commit.

Finding 1: An earlier reliability review found retry paths could acknowledge incomplete refund side effects when an existing provider refund ID or existing failure note caused early return before status/meta were reconciled. Fix: existing created refunds still pass through note/meta reconciliation except for the explicit stale-pending-after-success guard, and failed-refund retries repair `_wcpay_refund_status` plus order status even when the failed note already exists.

Finding 2: PHP test review found hook cleanup and assertion gaps: a test used broad action removal, failed/canceled update coverage did not assert order status, update mutation paths lacked lock coverage, malformed payload shapes were undercovered, and full-refund item behavior needed stronger proof. Fix: the refund-deleted test removes only its own callback, failed/canceled tests assert durable refund deletion and order status, update lock and malformed payload tests were added, and full-refund creation coverage exercises real order items while partial refunds remain itemless.

### Galileo the 2nd - H29 Adversarial Review

Verdict: high findings fixed.

Finding 1: A stale pending duplicate `charge.refunded` retry could downgrade `_wcpay_refund_status` from `successful` to `pending` for an already persisted refund ID. Fix: the handler ignores pending duplicate retries once the order already records successful refund status; the regression test fails before the guard and passes after.

Finding 2: Missing or malformed `captured` on `charge.refunded` failed open as an uncaptured no-op. Fix: `captured` is now a required strict boolean; explicit `false` remains the only uncaptured no-op, while missing or malformed values throw and keep the webhook fail-closed.

## 2026-06-18 H30 Account / Notification Provider Event Reviews

### Euler the 2nd - H30 Ecosystem Integration Review

Verdict: initial medium finding fixed; final re-review approved with no remaining H30 blockers.

Finding: The first H30 account-reset cleanup used two wrong preserved database-cache key names, `wcpay_business_types` and `wcpay_fraud_services`, instead of the reference `wcpay_business_types_data` and `wcpay_fraud_services_data`. Fix: `WooPaymentsAccountService::DATABASE_CACHE_OPTIONS` now mirrors the reference `Database_Cache::ALL_KEYS` names for those keys and the rest of the account-scoped preserved cache list. Verification: final ecosystem re-review confirmed the cache-key list, `wcpay.notification` top-level `data` dispatch before `data.object` extraction, account lifecycle cleanup/refresh/cache-clearing ordering, and invoice events remaining fail-closed.

### Euclid the 2nd - H30 Reliability Review

Verdict: initial high findings fixed; final re-review approved with critical 0, high 0, medium 0.

Finding 1: `account.deleted` initially reset local account state without enough account identity/retry protection, then the first hardening still allowed a partial cleanup to be acknowledged after strict refresh/cache clearing failed. Fix: native now validates the event account ID, marks the matching deletion as pending before cleanup, keeps the marker until strict refresh and payment-method cache cleanup complete, retries pending cleanup even after the account cache has been cleared, and ignores a stale pending marker when a different preserved account is currently connected.

Finding 2: `account.updated` initially used the tolerant refresh path, which could acknowledge stale fallback account data. Fix: webhook account events use `refresh_account_data_strict()`, which now fetches fresh provider data and verifies the durable `wcpay_account_data` write before returning.

Finding 3: preserved payment-method cache cleanup initially ignored non-sticky option deletes. Fix: `WooPaymentsTokenService::clear_all_cached_payment_methods()` deletes `_wcpay_payment_methods` user meta in a bounded batch, deletes `wcpay_pm_%` options in a bounded batch, verifies each selected option is gone, and throws for partial batches or database failures so webhook retries can complete cleanup.

Finding 4: remote note creation could silently acknowledge a notification even when note persistence failed. Fix: `WooPaymentsRemoteNoteService::put_note()` verifies the stored Woo Admin note exists with an ID after persistence and throws otherwise.

## 2026-06-18 A4h Reliability/API Contract Review

### Gauss the 3rd - A4h Transaction Details Contract Review

Verdict: approved, critical 0, high 0, medium 0.

Finding: No focused reliability/API-contract blockers remain after the A4h transaction-details follow-up. The reviewer verified that the new native payment-detail REST routes are runtime-arbiter gated and `manage_woocommerce` protected, `pi_*` and `ch_*` detail reads use dedicated REST endpoints rather than `/wc/v3/payments/transactions/{id}`, and native list/note links use `/woopayments/transactions/details` with PaymentIntent or charge IDs as the primary `id` plus balance transaction IDs as supplemental `transaction_id` context when available. Source anchors reviewed: `WooPaymentsPaymentDetailsRestController.php`, `transaction-details-page.tsx`, `money-movement/utils.ts`, `WooPaymentsProviderGatewayAdapter.php`, and `WooPaymentsDisputeEventHandler.php`. The subagent performed read-only source review only; main-session verification covers the focused PHPUnit/Jest/lint/PHPStan/browser gates.

## 2026-06-18 A5a Cutover Preflight Closeout Reviews

### Parfit the 3rd - A5a Architecture Review

Verdict: initial medium finding fixed; no blocking architecture issues remained.

Finding: The first A5a implementation resolved the post-KYC activation email by falling back to `new WooPaymentsPostKycActivationEmail()` when `WC()->mailer()->get_emails()` did not return the preserved `WC_Payments_Email_Post_Kyc_Activation` key. That bypassed the WooCommerce email registry seam, so integrations that removed or replaced the email class could still have the queued action send Core's default email. Fix: `WooPaymentsOperationalQueueService::get_post_kyc_activation_email()` now returns `?WooPaymentsPostKycActivationEmail`, and the send handler bails when the registry omits or replaces the preserved email. Regression coverage filters `woocommerce_email_classes` to an empty array and asserts the stage is not consumed.

Follow-up: The reviewer noted that the queue service now owns queue callbacks, note persistence, email lifecycle, CTA tracking, and eligibility/order lookups. This is acceptable for A5a closeout, but a later cleanup can split Instant Deposit notes and post-KYC activation email behavior into small delegate services once the cutover queue handoff is stable.

### Lovelace the 3rd - A5a Reliability Review

Verdict: initial high finding fixed.

Finding: The first A5a implementation preserved the `wcpay_post_kyc_activation_email_send` consumer but missed the producer that records `wcpay_kyc_completion_date` on `woocommerce_payments_account_refreshed`. The reference plugin records KYC completion once for live, payments-enabled, non-test-drive accounts, using `time()` when `wcpay_kyc_submitted_date` exists and otherwise falling back to account `created`. Without this producer, merchants completing KYC after native cutover would silently never schedule staged emails. Fix: native now registers `maybe_record_kyc_completion_date()` on account refresh, mirrors the reference guards and no-overwrite behavior, and has an end-to-end test from account refresh to post-KYC scheduled actions.

### Feynman the 3rd - A5a Reliability Re-Review

Verdict: approved, critical 0, high 0, medium 0.

Finding: No reliability findings remained after the registry-bail and KYC producer fixes. The reviewer confirmed the targeted fixes match the local WooPayments reference behavior.

## 2026-06-19 A4k Settings Row Payload / UI Reviews

### Schrodinger the 3rd - A4k Accessibility Re-Review

Verdict: approved, critical 0, high 0, medium 0.

Finding: No source-backed accessibility regressions remained after the A4k review fixes. The reviewer verified duplicate-notice dismissal restores focus to a stable row control, fee details use a native button with `aria-expanded`, `aria-controls`, `aria-describedby`, and Escape close handling, discount details are exposed through screen-reader text rather than `title`, and row controls keep sensible checkbox labels and descriptions. The reviewer also ran `pnpm --filter=@woocommerce/admin-library test:js -- client/woopayments/settings/test/settings-page.test.tsx --runInBand`, which passed with 15 tests.

### Zeno the 3rd - A4k API Contract Re-Review

Verdict: approved, critical 0, high 0, medium 0.

Finding: No API contract regressions remained in the focused settings payload/backend scope. The earlier split-gateway classification bug is fixed: exact `woocommerce_payments` maps to `card`, while prefixed gateway IDs such as `woocommerce_payments_klarna` strip the prefix and validate the suffix against supported payment methods before generic keyword matching. The regression test asserts Klarna duplicates land under `klarna` and `card` is absent. Frontend selectors/actions preserve the map shape.

### Ohm the 3rd - A4k WordPress Architecture Re-Review

Verdict: approved, critical 0, high 0, medium 0.

Finding: No WordPress/WooCommerce architecture issues remained in the focused A4k scope. The reviewer verified fee rows no longer hard-code `en-US`, duplicate notices are suppressed when a row status notice exists, and gateway duplicate inspection catches `Throwable` and fails closed to an empty map with test coverage.

## 2026-06-19 A4l Express Checkout Settings Reviews

### Aristotle the 4th - A4l Parity/API Review

Verdict: initial findings fixed.

Finding 1: The first main express checkout row rendered Link by Stripe whenever Link was available, even when card was disabled, while the reference treats Link as card-dependent. Fix: the main settings row now gates Link visibility on both card enabled state and Link availability.

Finding 2: Apple Pay / Google Pay and Amazon Pay dynamic checkout-in-payment-methods-list controls were exposed without a native settings capability flag, which would overstate support if the backend contract changed. Fix: `WooPaymentsSettingsService` now exposes `is_express_checkout_in_payment_methods_list_supported`, and the frontend gates the dynamic-list control and checkout-only override behavior on that flag.

Finding 3: WooPay preview appearance/font data was passed unconditionally even when global theme support was not eligible/enabled. Fix: native passes WooPay appearance/font rules into the preview only when `isWooPayGlobalThemeSupportEnabled` is true, preserving the reference eligibility boundary.

Finding 4: WooPay logo upload accepted broad image types instead of the reference PNG/JPEG contract. Fix: the upload control now accepts `image/png,image/jpeg` only.

### Confucius the 4th - A4l Accessibility Review

Verdict: initial findings fixed.

Finding 1: The first save bars could become inaccessible when disabled because the button itself carried the disabled state and click handling. Fix: both express detail and main settings save buttons use the guarded accessible disabled pattern and ignore clicks while saving/clean rather than dropping focusability.

Finding 2: Multiple visible `Customize` links had identical accessible names. Fix: the visible label remains `Customize`, while each link now has a method-specific aria label such as `Customize WooPay`, `Customize Apple Pay / Google Pay`, and `Customize Amazon Pay`.

Finding 3: WooPay upload failures were announced through both a global notice and inline notice. Fix: the global `core/notices` dispatch was removed for this flow, leaving one inline notice source.

### Maxwell the 4th - A4l Architecture Review

Verdict: initial findings fixed.

Finding 1: Direct express checkout routes could render method controls before the settings payload was loaded or after it failed, which violated fail-closed settings behavior. Fix: the direct route now renders loading/error states only until a non-empty settings payload exists; method controls and save bar mount only after the settings payload is available.

Finding 2: The first express route bundled all method panels together. Fix: `WooPaymentsExpressCheckoutSettings` lazy-loads WooPay, Apple Pay / Google Pay, and Amazon Pay panels into separate method chunks under the shared express settings route chunk.

## 2026-06-20 A4ah Detail Authorization Actions Reviews

### Reliability Reviewer - A4ah Detail Actions

Verdict: initial finding fixed; re-review approved with critical 0, high 0, medium 0.

Finding: The first detail authorization implementation guarded both post-action reload and error notice dispatch by the original route key. That prevented stale success reloads, but it also meant a capture/cancel failure could become silent if the merchant navigated to another detail route while the request was pending. Fix: the action handler now always dispatches the failure notice, while stale-route guards still prevent old reloads and pending-state cleanup from mutating the new route. Regression coverage verifies that an authorization action failure after navigation still calls the error notice path.

### Accessibility Reviewer - A4ah Detail Actions

Verdict: initial finding fixed; re-review approved with critical 0, high 0, medium 0.

Finding: The first success focus restoration remembered that focus started in the authorization action area, but it did not re-check where focus was when the async request completed. If the merchant moved focus elsewhere while capture/cancel was pending, success could steal focus back to `Payment details`. Fix: the focus effect now restores focus only when the active element is `body` or still inside the replaced authorization action/notice region. Regression coverage verifies that user-moved focus is preserved after the pending action resolves.

### JS Test Reviewer - A4ah Detail Actions

Verdict: approved, critical 0, high 0, medium 0.

Finding: No blocking JS test-quality issues were reported in the focused money-movement test file. The final suite includes regression coverage for normal capture, fraud approve/block, pending accessible labels, focusable disabled actions, stale route guarding, authorization load/action failures, and the two review-fix edge cases.

## 2026-06-20 A4ai Refund Modal Parity Reviews

Subagent dispatcher note: callable subagent tooling was not exposed in this Codex run, so the A4ai review gate was completed inline and source-backed against the final diff, tests, browser proof, and logs.

### Money-Safety / Reliability Review - A4ai

Verdict: initial finding fixed; approved with critical 0, high 0, medium 0.

Finding: The first refund-route test fixture used `remove_all_actions( 'wc_payment_gateways_initialized' )` in `tearDown()`. That cleanup was broader than the fixture needed and could mask unrelated payment-gateway initialization callbacks in the shared test process. Fix: the test now stores its own refund-gateway initializer callback, removes only that callback at priority 100, resets the WC payment-gateway instance, and reruns the focused controller suite. Verification after the fix: `pnpm --filter=@woocommerce/plugin-woocommerce test:php:env -- --filter WooPaymentsMoneyMovementRestControllerTest` passed with 40 tests and 254 assertions; `pnpm --filter=@woocommerce/plugin-woocommerce lint:php:changes` passed.

Approved money-path facts: the native refund compatibility route requires a valid WooCommerce order, positive amount within `get_remaining_refund_amount()`, and `_charge_id` equality before calling `wc_create_refund( refund_payment => true, restock_items => true )`. The route does not port the reference plugin's direct no-order charge-refund path, so native refunds continue through the existing WooCommerce/native gateway refund pipeline, provider idempotency, refund metadata, order notes, and webhook follow-up.

### Frontend Accessibility / Interaction Review - A4ai

Verdict: approved, critical 0, high 0, medium 0.

Finding: No blocking accessibility issues remained in the final interaction diff. The action trigger uses `DropdownMenu` with the accessible label `Transaction actions`; modal title and primary action are `Refund transaction`; reason choices use `RadioControl`; the pending primary action uses `isBusy`, `accessibleWhenDisabled`, and a pending accessible name; closing restores focus to the action trigger, and successful refund reloads restore focus only through the existing detail-heading focus guard. Focused Jest covers success focus restoration, failure keeping the modal open, order-backed-only hiding, partial-refund-only state, and the HPOS order-URL fallback.

### API / Detail Contract Review - A4ai

Verdict: approved, critical 0, high 0, medium 0.

Finding: Browser proof found that the native payment-intent detail endpoint lacked local order context even when the transactions list had it. Fix: `WooPaymentsPaymentDetailsRestController` now enriches charge and payment-intent detail responses through `WooPaymentsMoneyMovementOrderService`; the service enriches standalone charges, `intent.charge`, and `intent.charges.data[]` without treating arbitrary entity `id` fields as charge IDs. Regression coverage asserts charge and PaymentIntent detail responses include the local order ID. Playwriter then verified the target native detail page exposes `Transaction actions` and the refund modal for an order-backed captured charge.

## 2026-06-20 A4al Payment Detail Residual Parity Reviews

### Accessibility Reviewer - A4al Reader Fee / Timeline Semantics

Verdict: initial finding fixed; re-review approved with critical 0, high 0, medium 0.

Finding: The initial card-reader-fee branch still allowed the parent transaction detail live status to announce `Transaction details loaded.` while the reader-fee component was independently loading or failing its reader summary. Fix: the parent detail route now suppresses that generic live status for `transaction_type=card_reader_fee`, leaving the reader-fee component to announce its own loading, empty, loaded, or error state. Regression coverage verifies the generic parent message is absent on the reader-fee route.

### Architecture Reviewer - A4al Route/Data Boundaries

Verdict: approved, critical 0, high 0, medium 0.

Finding: No blocking architecture issues were reported. The slice keeps `card_reader_fee` routing/read rendering at the money-movement detail layer, uses the existing native REST reader-charge endpoint without adding platform schema assumptions, keeps the richer timeline mapper bounded to the current provider payload, and avoids touching Reports reader-fee aggregation, checkout shopper surfaces, WPCOM code, WCPay Dev Tools, or money-moving mutations.

### JS Test Reviewer - A4al Focused Tests

Verdict: initial finding fixed; approved with critical 0, high 0, medium 0.

Finding: The first reader-fee detail test waited only for a generic `Card readers` heading, which could pass before the async summary rows settled. Fix: the test now waits for the concrete `tmr_reader_1` row before asserting the table and export state. The final focused suite covers reader helper path/signal behavior, metadata charge-type route construction, reader-fee table/error/empty/timeout states, parent live-status suppression, non-card detail rows, and timeline event copy.

### Reliability Reviewer - A4al Loading/Error Behavior

Verdict: initial findings fixed; re-review approved with critical 0, high 0, medium 0.

Findings: The first reader-charge summary fetch had no timeout/abort path; generic failure and empty summary states could be announced too similarly to a loaded success state; and browser evidence only covered a normal charge route, not the card-reader-fee branch. Fixes: `getWooPaymentsReaderChargeSummary()` now accepts and forwards an `AbortSignal`, the reader-fee component aborts after 15 seconds and shows a timeout-specific alert, empty summaries render `No reader details found.`, and Playwriter proof exercises a synthetic missing reader-fee transaction route that surfaces the controlled 404 error while suppressing the parent loaded message. The local target has no real `card_reader_fee` transaction rows, so the success table remains covered by Jest/source/harness rather than browser success data.
