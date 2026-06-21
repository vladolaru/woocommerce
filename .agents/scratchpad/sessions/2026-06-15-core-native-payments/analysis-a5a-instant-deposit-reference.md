---
session: 2026-06-15-core-native-payments
type: analysis
by: codex
created: 2026-06-18 22:09
last_updated: 2026-06-18 22:40
tool: woocommerce-backend-dev
target: wcpay_instant_deposit_reminder
reconciles:
  - analysis-a5a-cutover-preflight-closeout.md
status: final
---

# Analysis

Initial search found the legacy hook in `/Users/vladolaru/Work/a8c/woocommerce-payments/includes/class-wc-payments-account.php`, reference tests in `/Users/vladolaru/Work/a8c/woocommerce-payments/tests/unit/test-class-wc-payments-account.php`, and native Core references in `/Users/vladolaru/Work/a8c/woocommerce-develop-2/plugins/woocommerce/src/Internal/Payments/Providers/WooPayments/WooPaymentsCutoverController.php` and `/Users/vladolaru/Work/a8c/woocommerce-develop-2/plugins/woocommerce/tests/php/src/Internal/Payments/Providers/WooPayments/WooPaymentsOperationalQueueServiceTest.php`.

## Reference behavior

`WC_Payments_Account::INSTANT_DEPOSITS_REMINDER_ACTION` is the literal hook `wcpay_instant_deposit_reminder` in `/Users/vladolaru/Work/a8c/woocommerce-payments/includes/class-wc-payments-account.php:36`.

The reference plugin registers the callback during account hook setup: `add_action( self::INSTANT_DEPOSITS_REMINDER_ACTION, [ $this, 'handle_instant_deposits_inbox_reminder' ] );` at `/Users/vladolaru/Work/a8c/woocommerce-payments/includes/class-wc-payments-account.php:135-139`. The same registration is asserted in `/Users/vladolaru/Work/a8c/woocommerce-payments/tests/unit/test-class-wc-payments-account.php:126-139`.

The producer path is account refresh, not the reminder hook itself: `woocommerce_payments_account_refreshed` calls `handle_instant_deposits_inbox_note` at `/Users/vladolaru/Work/a8c/woocommerce-payments/includes/class-wc-payments-account.php:135-137`. That method returns on empty account data, returns when `instant_deposits_eligible` is empty/false, otherwise updates `wcpay_instant_deposits_previously_eligible`, includes `WC_Payments_Notes_Instant_Deposits_Eligible`, adds the note, and calls `maybe_add_instant_deposit_note_reminder()` at `/Users/vladolaru/Work/a8c/woocommerce-payments/includes/class-wc-payments-account.php:2693-2716`. The eligibility helper only checks `empty( $account['instant_deposits_eligible'] )` at `/Users/vladolaru/Work/a8c/woocommerce-payments/includes/class-wc-payments-account.php:2782-2795`.

The reminder callback itself includes the note class, hard-deletes the note by name via `WC_Payments_Notes_Instant_Deposits_Eligible::possibly_delete_note()`, then re-enters `handle_instant_deposits_inbox_note( $this->get_cached_account_data() )` at `/Users/vladolaru/Work/a8c/woocommerce-payments/includes/class-wc-payments-account.php:2754-2764`.

The scheduler path checks for an existing pending action with the same hook and, if missing, schedules a single job for `time() + ( 90 * DAY_IN_SECONDS )` at `/Users/vladolaru/Work/a8c/woocommerce-payments/includes/class-wc-payments-account.php:2766-2780`. The plugin scheduler group is `woocommerce_payments` at `/Users/vladolaru/Work/a8c/woocommerce-payments/includes/class-wc-payments-action-scheduler-service.php:16-18`; `pending_action_exists()` checks `as_has_scheduled_action( $hook, [], self::GROUP_ID )` at `/Users/vladolaru/Work/a8c/woocommerce-payments/includes/class-wc-payments-action-scheduler-service.php:251-260`; `schedule_job()` schedules under that default group at `/Users/vladolaru/Work/a8c/woocommerce-payments/includes/class-wc-payments-action-scheduler-service.php:205-249`.

The note class builds a Woo Admin informational note named `wc-payments-notes-instant-deposits-eligible`, sourced from `woocommerce-payments`, with title/content/action metadata at `/Users/vladolaru/Work/a8c/woocommerce-payments/includes/notes/class-wc-payments-notes-instant-deposits-eligible.php:16-57`. It uses Core's `Automattic\WooCommerce\Admin\Notes\NoteTraits` at `/Users/vladolaru/Work/a8c/woocommerce-payments/includes/notes/class-wc-payments-notes-instant-deposits-eligible.php:8-17`. In native Core, `NoteTraits::possibly_add_note()` saves only when `can_be_added()` passes at `/Users/vladolaru/Work/a8c/woocommerce-develop-2/plugins/woocommerce/src/Admin/Notes/NoteTraits.php:83-99`, and `possibly_delete_note()` hard-deletes all notes with the class `NOTE_NAME` at `/Users/vladolaru/Work/a8c/woocommerce-develop-2/plugins/woocommerce/src/Admin/Notes/NoteTraits.php:143-165`.

## Reference tests

`test_handle_instant_deposits_inbox_note` expects two pending-action checks, one schedule call when the first check returns false, creation of the note, and `wcpay_instant_deposits_previously_eligible` becoming true in `/Users/vladolaru/Work/a8c/woocommerce-payments/tests/unit/test-class-wc-payments-account.php:3305-3343`.

`test_handle_instant_deposits_inbox_note_not_eligible` verifies no note and no previously-eligible option when `instant_deposits_eligible` is false in `/Users/vladolaru/Work/a8c/woocommerce-payments/tests/unit/test-class-wc-payments-account.php:3345-3366`.

`test_handle_instant_deposits_inbox_reminder_will_not_schedule_job_if_pending_action_exist` caches eligible account data, makes `pending_action_exists()` return true, and expects no new schedule call in `/Users/vladolaru/Work/a8c/woocommerce-payments/tests/unit/test-class-wc-payments-account.php:3368-3394`.

`test_handle_instant_deposits_inbox_reminder` caches eligible account data, makes `pending_action_exists()` return false, and expects scheduling during reminder handling in `/Users/vladolaru/Work/a8c/woocommerce-payments/tests/unit/test-class-wc-payments-account.php:3396-3425`.

`WC_Payments_Notes_Instant_Deposits_Eligible_Test::test_removes_note_on_extension_deactivation` verifies the same note name is gone after plugin deactivation in `/Users/vladolaru/Work/a8c/woocommerce-payments/tests/unit/notes/test-class-wc-payments-notes-instant-deposits-eligible.php:11-22`.

## Native equivalents and gaps

Native Core has the same Action Scheduler group in `WooPaymentsActionSchedulerService::GROUP_ID = 'woocommerce_payments'` at `/Users/vladolaru/Work/a8c/woocommerce-develop-2/plugins/woocommerce/src/Internal/Payments/Providers/WooPayments/WooPaymentsActionSchedulerService.php:18-23`, and a duplicate-safe single-action scheduler for same hook/args/group at `/Users/vladolaru/Work/a8c/woocommerce-develop-2/plugins/woocommerce/src/Internal/Payments/Providers/WooPayments/WooPaymentsActionSchedulerService.php:25-40`. The native method signature is `schedule_job( string $hook, array $args = array(), ?int $timestamp = null )`, unlike the plugin's timestamp-first signature.

Native Core has account cache access and emits the same `woocommerce_payments_account_refreshed` action on refresh in `/Users/vladolaru/Work/a8c/woocommerce-develop-2/plugins/woocommerce/src/Internal/Payments/Providers/WooPayments/WooPaymentsAccountService.php:147-190`; tests can seed it via `cache_account_data()` at `/Users/vladolaru/Work/a8c/woocommerce-develop-2/plugins/woocommerce/src/Internal/Payments/Providers/WooPayments/WooPaymentsAccountService.php:290-296`.

Native Core has generic admin note storage APIs (`Notes::get_note_by_name()` at `/Users/vladolaru/Work/a8c/woocommerce-develop-2/plugins/woocommerce/src/Admin/Notes/Notes.php:95-113`) and a WooPayments remote note service that can create informational WooPayments-sourced notes from provider notification data in `/Users/vladolaru/Work/a8c/woocommerce-develop-2/plugins/woocommerce/src/Internal/Payments/Providers/WooPayments/WooPaymentsRemoteNoteService.php:21-50` and `/Users/vladolaru/Work/a8c/woocommerce-develop-2/plugins/woocommerce/src/Internal/Payments/Providers/WooPayments/WooPaymentsRemoteNoteService.php:70-146`. It does not appear to contain a native hard-coded equivalent for the exact instant-deposits eligible note name or the `instant_deposits_eligible` account-data producer.

Native `WooPaymentsOperationalQueueService::register()` currently registers store setup sync, saved-payment-method, fee-breakdown, compatibility-data, account-refreshed compatibility scheduling, theme scheduling, and recurring store setup scheduling; it does not register `wcpay_instant_deposit_reminder` at `/Users/vladolaru/Work/a8c/woocommerce-develop-2/plugins/woocommerce/src/Internal/Payments/Providers/WooPayments/WooPaymentsOperationalQueueService.php:113-129`. The corresponding test explicitly asserts `has_action( 'wcpay_instant_deposit_reminder', array( $service, 'handle_wcpay_instant_deposit_reminder' ) )` is false at `/Users/vladolaru/Work/a8c/woocommerce-develop-2/plugins/woocommerce/tests/php/src/Internal/Payments/Providers/WooPayments/WooPaymentsOperationalQueueServiceTest.php:44-60`.

Native cutover still lists `wcpay_instant_deposit_reminder` as a default pending operational queue hook at `/Users/vladolaru/Work/a8c/woocommerce-develop-2/plugins/woocommerce/src/Internal/Payments/Providers/WooPayments/WooPaymentsCutoverController.php:111-118`. Any non-empty pending operational hook list adds `operational_queue_hooks_undispositioned` to preflight failures at `/Users/vladolaru/Work/a8c/woocommerce-develop-2/plugins/woocommerce/src/Internal/Payments/Providers/WooPayments/WooPaymentsCutoverController.php:347-353`, with normalization at `/Users/vladolaru/Work/a8c/woocommerce-develop-2/plugins/woocommerce/src/Internal/Payments/Providers/WooPayments/WooPaymentsCutoverController.php:452-478`. The test covering this blocker is `/Users/vladolaru/Work/a8c/woocommerce-develop-2/plugins/woocommerce/tests/php/src/Internal/Payments/Providers/WooPayments/WooPaymentsCutoverControllerTest.php:335-348`.

## Recommendation

Smallest parity-safe native scope for this hook is to own only the legacy operational queue behavior, not a broader instant-payout feature: register `wcpay_instant_deposit_reminder` when native owns runtime; implement a handler that hard-deletes the legacy note name and then, if cached account data has truthy `instant_deposits_eligible`, recreates the same Woo Admin note shape and schedules the next single `wcpay_instant_deposit_reminder` for 90 days later in group `woocommerce_payments`, relying on the native scheduler's duplicate check. Also add the producer on `woocommerce_payments_account_refreshed` only if Core wants parity for newly eligible native accounts, because the reference's reminder job is only one path into the same note-refresh routine.

For cutover specifically, the absolute minimum is handling already-scheduled legacy `wcpay_instant_deposit_reminder` actions without fatal/no-op loss: register the consumer and recreate/delete according to cached account eligibility. Removing the hook from `DEFAULT_PENDING_OPERATIONAL_QUEUE_HOOKS` without a consumer would be behavior-losing for merchants with pending reminders.

Risks: the exact note copy links to WooPayments docs and uses `woocommerce-payments` text domain in the plugin; native Core would need equivalent strings under the Core text domain or use a projected note/remote-note path. Recreating and rescheduling this reminder preserves a marketing/admin inbox reminder, but it also revives a legacy notification loop in native Core. If native admin surfaces intentionally replace this reminder, parity-safe cutover should instead explicitly unschedule or acknowledge existing actions and document that disposition, not silently leave the hook pending or let scheduled jobs fire with no callback.
