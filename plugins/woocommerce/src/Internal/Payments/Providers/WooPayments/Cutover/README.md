# WooPayments cutover

This folder moves a store from the WooPayments extension to the built-in WooPayments. An admin notice offers the switch, and one merchant click starts a durable job. The job checks the preflight, migrates the stored data, deactivates the extension and, in a later request, verifies that the built-in WooPayments owns the site. Activating the extension again rolls the switch back. On a multisite where the extension is network-active, a super admin first turns that into one activation per site.

| Class | Does |
|---|---|
| `CutoverController` | Shows the notices, takes the start click and the dismissals, applies the mandatory lever. On a store the built-in WooPayments owns, it names the active plugins whose renewals need the WooPayments extension. |
| `PluginLifecycleListener` | Hears plugin activation and deactivation on every request type and hands them to the controller. |
| `ReconciliationJob` | Runs a site's cutover and keeps the notice state. |
| `StateStore` | Reads and validates the cutover record, provides atomic writes, and manages its write lock. |
| `CutoverActionScheduler` | Holds the Action Scheduler identity of the job. |
| `PreflightService` | Evaluates the conditions that block the cutover and manages the active extension. |
| `DataMigration` | Migrates the extension's stored settings and options once per migration version. |
| `ExtensionBoundRenewalPlugins` | Lists the plugins that charge renewals through the WooPayments extension's own classes, finds the active ones and names them; the preflight holds the switch back while one is active. |
| `BundledSubscriptionsGuard` | Tells whether a store runs the bundled WooPayments subscriptions, which the cutover excludes. |
| `PlatformConnectionService` | Checks the WordPress.com connection the cutover needs. |
| `PerSiteActivationController` | Offers the network-wide to per-site activation conversion, checks network-wide activations of the extension and records each site's rollback after one. |

## Remove with this folder

Deleting the folder alone breaks the code that reads it. Make these edits in the same commit:

- `WooPaymentsProvider::get_classes_by_setup_tier()`: the three `$cutover_*` locals under the comment that points here, every tier row that spreads them, the `DataMigration` entry of `$active_maintenance_prefix`, and the loop that appends `$cutover_every_request` to every request type.
- `includes/class-woocommerce.php`: the multisite block that registers `PerSiteActivationController` (its multisite hooks on every request, its network admin notice in network admin). It sits outside the tier matrix because it must run where no setup tier is active.
- `WooPaymentsStatusReport`: the two lazy getters for the preflight service and the state store, the cutover Site Health test (`add_site_status_tests()`, `run_cutover_site_health_ajax_test()`, `run_cutover_site_health_test()` and their two ID constants), the `preflight_failures` field and `get_preflight_failures()`, and the record read in `has_support_diagnostics()`. `WooPaymentsCLICommand` prints the `preflight_failures` row.
- `NoticesPassthrough`: `KEPT_NOTICE_CLASSES` and the branch that keeps the cutover controller's notices on the Payments tab.
- `WooPaymentsRuntimeArbiter::has_switched_from_extension()`, which reads the record's `done` state and finalize token for the ownership rule. Without it the rule becomes "the WooPayments feature is on and the extension is not active", and `resolve_runtime_owner()` and the readers of `is_awaiting_switch()` (`WooPaymentsSetupTier`, `WooPaymentsPaymentsSettingsAdapter`, `PaymentsProviders` and the status report) simplify with it.
- `includes/class-wc-install.php`: the install seed of the record marker, `woocommerce_woopayments_cutover_state`.
- `WooPaymentsCanceledAuthorizationFeeRemediationService::can_schedule_cutover_remediation()`, which only the preflight calls.
- `StripeBilling/README.md`: the seam row for `BundledSubscriptionsGuard` and the seam count, and its path in `StripeBillingPlacementTest`.
- The tests in `tests/php/src/Internal/Payments/Providers/WooPayments/Cutover/`, and any test that names a class of this folder (`git grep -n 'WooPayments\\Cutover\\' -- tests`).

`git grep -n 'Cutover\\' -- src includes` outside this folder finds the class reads; the list above also names the reads that use no class name.

## How long it lives

- The cutover has no removal version. It exists while stores can still run the WooPayments extension.
- The rollback rule lives as long as the folder does. The cutover never deletes a setting that the extension version in `PreflightService::MINIMUM_CUTOVER_PLUGIN_VERSION` still reads (`RETIRED_OPTIONS` and `RETIRED_TRANSIENTS` on `DataMigration` list what it deletes). In the gateway settings it removes no key and changes no value's type: it adds missing form defaults, and drops from the enabled payment method list the methods that cannot be captured manually while manual capture is on, which the extension has filtered since 8.3.0, and the discontinued giropay and sofort, which the platform no longer processes and which the extension removes itself from 11.0.0 and 11.1.0; a rollback to the floor version reads a list without two methods it could not charge. It also clears the appearance caches in `APPEARANCE_TRANSIENTS`, which that version no longer reads. The floor version also reads every order, token and setting the built-in WooPayments writes for payments, cards and renewals (10.6.0 is the first that loads Amazon Pay tokens and registers the Amazon Pay gateway by default); when the built-in starts writing such a datum an older floor cannot read, the floor rises with it. The activation guard in `CutoverController` refuses the extension only on a store that never ran it.
- The cutover adds no `wc_update_*` routine. An upgrade routine is a one-shot key that is never deleted, so none belongs to this checklist.
