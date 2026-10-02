# Stripe Billing module

Stripe Billing lets a store with WooCommerce Subscriptions bill subscription renewals at Stripe instead of charging them from the store. This folder holds the native port of the opt-in Stripe Billing flavor of WooPayments 11.1.0. The bundled "WCPay Subscriptions" flavor, which only runs without WooCommerce Subscriptions, is not part of it.

## Loading

`WooPaymentsStripeBillingModule` is the root. The provider bootstrap registers it for connected and active stores on every request type. On `plugins_loaded` priority 11, when native owns payments:

- WooCommerce Subscriptions inactive: the module loads nothing.
- WooCommerce Subscriptions active: the module loads, so subscriptions already billed at Stripe keep being served while the toggle is off.
- Staging copy (as WooCommerce Subscriptions reports it): the module loads but attaches no hooks, so nothing changes at Stripe for the live store.
- Toggle (`_wcpay_feature_stripe_billing`) on: new subscriptions also go to Stripe Billing.

Hook callbacks resolve their service from the container only when the hook fires, so registering them constructs no service and runs no query. `WooPaymentsStripeBillingModule::attach_maintenance_hooks()` holds the hooks that serve existing Stripe Billing data whatever the toggle; `attach_engaged_hooks()` holds those that only run while it is on.

## Boundary

Code outside this folder names only `WooPaymentsStripeBillingModule`, and only in these seams, each a one-line delegation that does nothing when the module is not loaded:

| Seam | File | What it does |
|---|---|---|
| S1 | `WooPaymentsProvider.php` | registers the module root in the bootstrap matrix |
| S2 | `NativeWooPaymentsGateway.php` | adjusts the gateway's subscription supports to the toggle |
| S3 | `NativeWooPaymentsGateway.php` | skips native renewal charges for Stripe-billed subscriptions |
| S4 | `WooPaymentsEventIngestor.php` | hands `invoice.*` webhook events to the module |
| S5 | `NativeWooPaymentsGateway.php` | fires `woocommerce_payments_changed_subscription_payment_method` after a payment method change |
| S6 | `WooPaymentsOperationalQueueService.php` | reports whether Stripe Billing is enabled |
| S7 | `WooPaymentsSettingsService.php`, `WooPaymentsMerchantRestController.php` | the Stripe Billing settings fields and the toggle write |
| S8 | `Subscriptions/WooPaymentsLegacySubscriptionsGuard.php`, `WooPaymentsCutoverReconciliationJob.php` | cutover rules for stores with Stripe Billing data |

The Stripe Billing fee context on payment intents is not a seam: the module sets `payment_context` through the existing `wcpay_metadata_from_order` filter.

Code inside this folder may use provider services from the container, the neutral `OrderPaymentLifecycleService`, and WooCommerce and WooCommerce Subscriptions functions. It never uses `Compat/`.

`StripeBillingPlacementTest` enforces both rules.

## Extraction

To move Stripe Billing out of WooCommerce: delete this folder, revert the eight seams, remove the module root from the provider's bootstrap matrix, and delete `StripeBillingPlacementTest`.
