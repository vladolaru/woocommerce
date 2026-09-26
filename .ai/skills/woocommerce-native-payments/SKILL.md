---
name: woocommerce-native-payments
description: Invoke when changing code under `plugins/woocommerce/src/Internal/Payments/`. Covers the neutral-layer/provider boundary rule and the WooPaymentsClientVersion bump policy for the native WooPayments runtime.
---

# WooCommerce Native Payments

`src/Internal/Payments/` is the provider-agnostic payments runtime (lifecycle, outcomes, persistence) plus the native WooPayments provider under `Providers/WooPayments/`.

## Neutral-layer boundary

The neutral layer (everything in `src/Internal/Payments/` outside `Providers/WooPayments/`) must not reference the WooPayments provider. Only these four files may `use` or name `Providers\WooPayments` classes:

- `NativePaymentsCliCommand.php`
- `OrderPaymentLifecycleService.php`
- `OrderPaymentStore.php`
- `PaymentProcessingService.php`

Adding a fifth reference erodes the provider abstraction. Route new provider needs through the provider contracts instead of reaching into `Providers\WooPayments` directly. This rule is documented, not yet enforced by a test.

## `WooPaymentsClientVersion` bump policy

`Providers/WooPayments/WooPaymentsClientVersion.php` declares the WooPayments plugin release whose platform behavior the native runtime was verified against. The platform gates payment-method availability (`minimum_client_version`), account-status mapping, and response shapes on the version each request reports, so the constant must move deliberately:

1. For each WooPayments release above the current constant, review every platform-side `is_client_version_at_least()` gate and `minimum_client_version` entry between the two versions.
2. Port the behavior each gate unlocks into the native runtime.
3. Bump the constant and its pinned-value test in one reviewed change.

Never bumping silently costs native stores new payment methods and response improvements; bumping without the review serves responses the native runtime does not understand. Read the full bump-policy docblock on `WooPaymentsClientVersion` before touching the constant.
