---
session: 2026-06-15-core-native-payments
type: review
by: subagent:hegel-the-6th
created: 2026-06-20 20:12
last_updated: 2026-06-20 20:27
target: native WooPayments settings parity after A4at
reconciles:
  - analysis-a4au-post-a4at-accumulated-gate-refresh.md
status: final
---

# A4au Settings Inventory

## Read-Only Verification

Hegel the 6th verified the current native WooPayments settings source against the reference WooPayments client without editing files or running tests. Several earlier N12 settings bullets are now closed in Core: payout bank account, save busy state, duplicate notices, BNPL/rich method list mechanics, express subpage routes, fraud basic/advanced controls, and notifications/transactions/advanced controls.

## Prioritized Gaps

1. High: payment-method availability guidance. Native `payment-methods-list.tsx` has the rich list foundation, but lacks reference guidance from `use-payment-method-availability.tsx`: delayed-approval copy/link for Alipay and WeChat, pending-verification Overview link, rejected `Contact support` link, and missing-currency warning when multi-currency is off. This is the recommended next product slice because it affects standard methods and BNPL through one shared component. Native PHP already exposes `store_currency` and `is_multi_currency_enabled` in `WooPaymentsSettingsService.php`.

2. Medium: VAT modal settings deep-link. The reference settings page opens `woopayments-vat-details-modal=true` and renders `VatFormModal`; native settings has no equivalent query handling, although Core already has a documents VAT modal and VAT REST routes. This is self-contained and likely limited to deep-linked tax flows.

3. Medium: fraud onboarding and instrumentation. Native fraud controls and advanced settings exist, but reference has `FraudProtectionTour`, risk-level/basic-modal Tracks events, and advanced-card skeleton/tracking polish. The mechanics are present; guidance and analytics parity remain.

4. Medium: express subpage notice and flag/content parity. Native express routes and Customize links exist, but native `notices.tsx` always renders the appearance override notice while the reference returns null when no other buttons share settings. Reference also gates WooPay, Amazon, and dynamic list settings through feature flags and has richer WooPay help links.

5. Low/Medium: section copy, docs links, and loading polish. Native BNPL, transactions, payouts, notifications, and advanced controls exist, but reference sections include richer descriptions/docs links and per-section skeleton loading.

6. Low: sandbox switch-to-live visual/tracking fidelity. Native core behavior exists. Reference adds tooltip icons and Tracks events around the switch-to-live notice and modal.

## Disposition

A4au remains the immediate post-A4at accumulated-gate refresh because A4at landed after the last full A4ar rollup. If A4au passes without product failures, the next product slice should be payment-method guidance parity rather than a broad settings rewrite.
