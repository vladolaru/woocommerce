---
session: 2026-06-15-core-native-payments
type: analysis
by: codex
created: 2026-06-18 22:09
last_updated: 2026-06-18 22:40
target: wcpay_post_kyc_activation_email_send
reconciles:
  - analysis-a5a-cutover-preflight-closeout.md
status: final
---

# Analysis

Scope was read-only local source verification across:

- Reference plugin clone: `/Users/vladolaru/Work/a8c/woocommerce-payments`
- Native WooCommerce Core worktree: `/Users/vladolaru/Work/a8c/woocommerce-develop-2`

## Reference Behavior

Registration and scheduling:

- `includes/class-wc-payments.php:460` includes `class-wc-payments-post-kyc-activation-email-service.php`.
- `includes/class-wc-payments.php:562-563` registers the email class via `woocommerce_email_classes`; `includes/class-wc-payments.php:867-869` maps `WC_Payments_Email_Post_Kyc_Activation` to `includes/emails/class-wc-payments-email-post-kyc-activation.php`.
- `includes/class-wc-payments.php:650-651` constructs `WC_Payments_Post_Kyc_Activation_Email_Service` with `WC_Payments_Account`, `WC_Payment_Gateway_WCPay`, and `WC_Payments_Order_Service`, then calls `init_hooks()`.
- `includes/class-wc-payments-post-kyc-activation-email-service.php:21-27` defines hook `wcpay_post_kyc_activation_email_send`, sent option `wcpay_post_kyc_activation_email_sent_stages`, scheduled option `wcpay_post_kyc_activation_emails_scheduled`, and stages `[ 7, 14, 30 ]`; `:33` defines stale grace as 7 days.
- `includes/class-wc-payments-post-kyc-activation-email-service.php:74-80` registers three hooks: `add_option_wcpay_kyc_completion_date` to `schedule_stage_emails`, `wcpay_post_kyc_activation_email_send` to `send_email_for_stage`, and `admin_init` to `maybe_track_cta_click`.
- `includes/class-wc-payments-post-kyc-activation-email-service.php:91-117` schedules one Action Scheduler action per non-stale stage using `as_schedule_single_action( max( $send_at, $now + 60 ), self::SEND_HOOK, [ $stage ], 'woocommerce-payments' )`, then writes `wcpay_post_kyc_activation_emails_scheduled = '1'` with autoload false.

Callback behavior for `wcpay_post_kyc_activation_email_send`:

- `includes/class-wc-payments-post-kyc-activation-email-service.php:125-130` casts the stage to int and accepts only 7, 14, or 30.
- `:132-135` bails if the stage is already in `wcpay_post_kyc_activation_email_sent_stages`.
- `:139-145` requires `wcpay_kyc_completion_date` and bails if the action is more than 7 days late for its stage.
- `:147-149` re-checks eligibility at fire time.
- `:151-160` requires `WC()->mailer()` and the `WC_Payments_Email_Post_Kyc_Activation` email instance.
- `:162-166` bails silently if the email is disabled or has no recipient.
- `:168-174` calls `$email->trigger( $stage )`; failed send leaves the stage unconsumed.
- `:176-177` appends the stage and updates `wcpay_post_kyc_activation_email_sent_stages` with autoload false only after a successful send.

Eligibility dependencies:

- `includes/class-wc-payments-post-kyc-activation-email-service.php:185-208` requires gateway connected, Stripe account valid, not test-drive, payments enabled, not test/dev mode, KYC completion date present, and no live sale.
- Gateway connected delegates to account Stripe connection at `includes/class-wc-payment-gateway-wcpay.php:754-760`.
- Account validity checks cached account data, `details_submitted`, and `capabilities.card_payments !== 'unrequested'` at `includes/class-wc-payments-account.php:280-300`.
- KYC completion date option is `wcpay_kyc_completion_date` at `includes/class-wc-payments-account.php:50`; it is recorded once when payments are enabled, live, and not test-drive at `includes/class-wc-payments-account.php:2630-2657`.
- Live-sale flag is `wcpay_has_live_sale` at `includes/class-wc-payments-order-service.php:158`; `has_live_sale()` first reads that flag, then falls back to a one-order query for `woocommerce_payments` completed/processing orders with `_wcpay_mode = production`, setting the flag on hit at `includes/class-wc-payments-order-service.php:319-342`.

Email class/templates:

- `includes/emails/class-wc-payments-email-post-kyc-activation.php:31-48` defines WC email id `wcpay_post_kyc_activation`, admin recipient defaulting to `admin_email`, plugin template base, HTML/plain templates, subject/heading placeholders, and plugin id `woocommerce_woocommerce_payments_`.
- `:89-116` validates stage, sets stage/placeholder, sets locale, sends when enabled and recipient exists, restores locale, records Tracks success/failure, and returns send success.
- `:125-143` points the CTA to `admin.php?page=wc-admin&path=/marketing&wcpay_referrer=post_kyc_email&wcpay_referrer_stage={stage}` with label `Promote my store`.
- `:151-189` renders `templates/emails/post-kyc-activation.php` and `templates/emails/plain/post-kyc-activation.php` with stage, heading, additional content, CTA URL/label, admin/plain flags, and email object.
- `templates/emails/post-kyc-activation.php:29-60` and `templates/emails/plain/post-kyc-activation.php:26-55` define stage-specific heading/body copy for stages 7, 14, and 30, CTA output, additional content, and WooCommerce email header/footer handling.

Side effects:

- Options: schedules marker `wcpay_post_kyc_activation_emails_scheduled` (`includes/class-wc-payments-post-kyc-activation-email-service.php:96-117`); sent-stage marker `wcpay_post_kyc_activation_email_sent_stages` (`:132-177`); KYC completion source `wcpay_kyc_completion_date` (`includes/class-wc-payments-account.php:50`, `:2630-2657`); live sale marker `wcpay_has_live_sale` (`includes/class-wc-payments-order-service.php:158`, `:253-265`, `:319-342`).
- Meta: eligibility fallback reads `_wcpay_mode` via `WC_Payments_Order_Service::WCPAY_MODE_META_KEY` in the live-order query (`includes/class-wc-payments-order-service.php:324-334`).
- Transients: first live sale deletes `wcpay_post_kyc_activation_eligible` at `includes/class-wc-payments-order-service.php:263-265`; account reset cleanup in native also deletes this transient at `plugins/woocommerce/src/Internal/Payments/Providers/WooPayments/WooPaymentsAccountService.php:377-395`.
- Tracks: email send success/failure events at `includes/emails/class-wc-payments-email-post-kyc-activation.php:106-112`; email CTA click at `includes/class-wc-payments-post-kyc-activation-email-service.php:218-240`; first live sale at `includes/class-wc-payments-order-service.php:267-269`.
- Redirect: email CTA click strips `wcpay_referrer` and `wcpay_referrer_stage` then exits (`includes/class-wc-payments-post-kyc-activation-email-service.php:235-240`).

## Tests Defining Reference Behavior

- `tests/unit/test-class-wc-payments-post-kyc-activation-email-service.php:101-197` covers eligibility gates including connection, account validity, test-drive, payments enabled, test/dev mode, KYC date, sticky live-sale flag, live-order fallback, and test-order exclusion.
- `:203-267` covers scheduling three actions, skip when already scheduled, skip stale stages, zero KYC value, and scheduled marker behavior.
- `:273-365` covers callback bails for invalid stage, already sent stage, ineligible state, stale action, successful email send marks stage, disabled email does not mark, and mailer failure does not mark.
- `:371-505` covers CTA tracking/redirect gates and hook registration.
- `tests/unit/emails/test-class-wc-payments-email-post-kyc-activation.php:28-47` covers CTA URL/referrer params and CTA label.
- `:49-56` covers removal of the heading settings field.
- `:58-103` covers trigger invalid stage, valid stage mutation, success, disabled email, and mailer failure.

## Native Equivalents / Missing Pieces

- Native cutover currently treats `wcpay_post_kyc_activation_email_send` as a pending undispositioned operational queue hook: `plugins/woocommerce/src/Internal/Payments/Providers/WooPayments/WooPaymentsCutoverController.php:111-118`; preflight blocks while pending operational hooks remain at `:347-353`, and filtering is exposed at `:457-478`.
- Native `WooPaymentsOperationalQueueService` registers preserved operational hooks only when native owns runtime at `plugins/woocommerce/src/Internal/Payments/Providers/WooPayments/WooPaymentsOperationalQueueService.php:116-129`, but it does not register or implement `wcpay_post_kyc_activation_email_send`. The test explicitly asserts this hook remains unregistered at `plugins/woocommerce/tests/php/src/Internal/Payments/Providers/WooPayments/WooPaymentsOperationalQueueServiceTest.php:47-61`.
- Native account service already has account predicates close to parts of eligibility: `can_process_payments()` requires account id, publishable key, payments enabled, and details submitted at `plugins/woocommerce/src/Internal/Payments/Providers/WooPayments/WooPaymentsAccountService.php:657-664`; `has_working_account()` checks payments enabled at `:680-684`; `has_test_account()` checks `is_test_drive` at `:691-695`; `has_live_account()` checks `is_live` at `:716-720`; `get_mode()` returns live/test at `:751-752`.
- Native account reset cleanup deletes the legacy `wcpay_post_kyc_activation_eligible` transient at `plugins/woocommerce/src/Internal/Payments/Providers/WooPayments/WooPaymentsAccountService.php:377-395`.
- I found no native matches under `plugins/woocommerce` for `wcpay_post_kyc_activation_email_sent_stages`, `wcpay_post_kyc_activation_emails_scheduled`, `wcpay_kyc_completion_date`, `wcpay_has_live_sale`, `WC_Payments_Email_Post_Kyc_Activation`, or `post-kyc-activation` templates.

## Recommendation

Smallest parity-safe native scope for cutover is not a no-op. Preserve consumption semantics for existing scheduled jobs by adding a native handler for `wcpay_post_kyc_activation_email_send` that:

1. Registers only when native owns WooPayments runtime.
2. Accepts only stages 7, 14, and 30.
3. Reads and respects `wcpay_post_kyc_activation_email_sent_stages`.
4. Requires `wcpay_kyc_completion_date` and applies the same 7-day stale grace.
5. Re-checks eligibility with native account data where available: connected/can process, not test-drive, payments enabled/live mode, KYC date present, and no live-sale marker/fallback.
6. Sends the merchant email via a native `WC_Email` equivalent plus HTML/plain templates, records Tracks sent/failure events, and marks the stage consumed only on successful send.
7. Preserves email CTA click tracking and query stripping if sending emails with the same CTA referrer params.

If the product decision is to intentionally discontinue this reminder campaign at cutover, the smallest safe alternative is to explicitly register the legacy hook with a handler that validates stage/staleness and marks the legacy stage consumed without sending. That avoids orphaned scheduled actions retrying forever, but it is not exact reference parity because it drops merchant email, sent/failure Tracks, CTA tracking, and WooCommerce email settings behavior.

Risks:

- Implementing only an empty callback would unblock Action Scheduler errors but would not preserve reference behavior and would leave no marker that a stage was consumed.
- Implementing the email send without the same sent-stage and mailer-failure semantics can either duplicate emails or silently swallow failed sends.
- Native does not yet appear to have a first-live-sale helper equivalent to the plugin's `has_live_sale()` fallback/marker; recreating that check is needed to avoid emailing merchants after a first live order.
- Bringing over the email class/templates introduces public-facing copy and template override surface; copy/domain ownership should be confirmed before shipping in Core.
