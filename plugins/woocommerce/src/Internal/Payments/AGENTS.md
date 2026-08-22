# Agent documentation for the Native Payments runtime

**Location**: `src/Internal/Payments/`
**Purpose**: Provider-agnostic payments runtime (lifecycle, outcomes, persistence) plus the native WooPayments provider under `Providers/WooPayments/`.

## Follow-ups ledger (read before deferring work)

[`FOLLOW_UPS.md`](FOLLOW_UPS.md) in this directory is the durable ledger of outstanding obligations for the native WooPayments implementation — recurring reviews (e.g. the client-version bump review) and deliberately deferred mechanisms.

- **Before deferring work** in this subtree, append an entry there instead of leaving it in a session scratchpad, a PR comment, or an external tracker.
- **When resolving an entry**, remove it and note the resolving change in the same commit.
- **Before bumping** `Providers/WooPayments/WooPaymentsClientVersion.php`, read the ledger's client-version bump review entry — the constant moves only via that reviewed cadence.

## Related documentation

| Concern | Location |
|---------|----------|
| Implementation follow-ups | `src/Internal/Payments/FOLLOW_UPS.md` (this directory) |
| E2E harness coverage gaps | `tools/woopayments-merge/HARNESS.md` §3 (monorepo root) |
| E2E harness decisions | `plugins/woocommerce/tests/e2e/tests/woopayments-native/DECISIONS.md` |

## Architectural boundary

The neutral layer in this directory must not reference the WooPayments provider. Only these four files may `use` or name `Providers\WooPayments` classes: `NativePaymentsCliCommand.php`, `OrderPaymentLifecycleService.php`, `OrderPaymentStore.php`, `PaymentProcessingService.php`. Adding a fifth reference erodes the provider abstraction — route new provider needs through the provider contracts instead.
