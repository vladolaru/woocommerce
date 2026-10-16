# Stripe Billing module

Stripe Billing lets a store with WooCommerce Subscriptions bill subscription renewals at Stripe instead of charging them from the store. This folder holds WooPayments' opt-in Stripe Billing support, which follows client 11.1.0. The subscriptions bundled in WooPayments, which only run without WooCommerce Subscriptions, are not part of it; the module only owns their flag and the rule for when a store may still turn them on, which reads Stripe Billing data.

## Loading

`WooPaymentsStripeBillingModule` is the root. The provider bootstrap registers it for connected and active stores on every request type. On `plugins_loaded` priority 11, when the built-in WooPayments owns payments:

- WooCommerce Subscriptions inactive: the module loads nothing.
- WooCommerce Subscriptions active: the module loads, so subscriptions already billed at Stripe keep being served while the toggle is off.
- Staging copy (as WooCommerce Subscriptions reports it): the module loads but attaches no hooks, so nothing changes at Stripe for the live store.
- Toggle (`_wcpay_feature_stripe_billing`) on: new subscriptions also go to Stripe Billing.

Hook callbacks resolve their service from the container only when the hook fires, so registering them constructs no service and runs no query. `WooPaymentsStripeBillingModule::attach_maintenance_hooks()` holds the hooks that serve existing Stripe Billing data whatever the toggle; `attach_engaged_hooks()` holds those that only run while it is on.

The one exception is `StripeBillingMigrator`, built with `new` on every request once WooCommerce Subscriptions' `WCS_Background_Repairer` is known to exist, whatever the toggle, and never on a staging copy: it extends that class, which attaches its own Action Scheduler hooks in `init()`, so it cannot be resolved lazily or from the container. The module also registers the migration route `wc/v3/payments/settings/schedule-stripe-billing-migration` for every store where the built-in WooPayments owns payments, as client 11.1.0's settings controller does; it does nothing without the migrator. `php-stubs/wcs-background-repairer.php` declares the repairer classes for PHPStan only.

## Boundary

Code outside this folder names only `WooPaymentsStripeBillingModule`, and only in these seams, each a one-line delegation that does nothing when the module is not loaded:

| Seam | File | What it does |
|---|---|---|
| S1 | `WooPaymentsProvider.php` | registers the module root in the bootstrap matrix |
| S2 | `WooPaymentsGateway.php` | adjusts the gateway's subscription supports to the toggle |
| S3 | `Subscriptions/WooPaymentsSubscriptionsController.php` | skips the store's own renewal charge for Stripe-billed subscriptions |
| S4 | `Webhooks/WooPaymentsEventIngestor.php` | hands `invoice.upcoming`, `invoice.paid` and `invoice.payment_failed` to the module after the mode check; refuses them with client 11.1.0's reasons (400) when the module is not loaded |
| S5 | `WooPaymentsGateway.php` | fires `woocommerce_payments_changed_subscription_payment_method` after a payment method change |
| S6 | `WooPaymentsStoreSetupReportController.php` | reports whether Stripe Billing is enabled |
| S7 | `WooPaymentsSettingsService.php`, `WooPaymentsMerchantRestController.php` | the Stripe Billing settings fields, the toggle write, and the bundled subscriptions flag, its eligibility and its turn-off |
| S8 | `Subscriptions/WooPaymentsLegacySubscriptionsGuard.php`, `WooPaymentsCutoverReconciliationJob.php` | cutover rules for stores with Stripe Billing data |

The Stripe Billing fee context on payment intents is not a seam: the module sets `payment_context` through the existing `wcpay_metadata_from_order` filter.

Code inside this folder may use provider services from the container, the payments runtime's `OrderPaymentLock` and `TransientRowLock`, and WooCommerce and WooCommerce Subscriptions functions. It never uses `Compat/`.

`StripeBillingPlacementTest` enforces both rules.

## Extraction

To move Stripe Billing out of WooCommerce: delete this folder, revert the eight seams, remove the module root from the provider's bootstrap matrix, and delete `StripeBillingPlacementTest`.
