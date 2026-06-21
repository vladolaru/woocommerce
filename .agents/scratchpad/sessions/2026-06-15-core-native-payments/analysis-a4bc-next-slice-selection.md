---
session: 2026-06-15-core-native-payments
type: analysis
by: codex
created: 2026-06-21 01:43
last_updated: 2026-06-21 01:49
target: A4bc next reopened-A4 slice selection after A4bb
reconciles:
  - supervisor-prompt-2026-06-18-2344-N12.md
  - review-a4au-settings-inventory.md
  - review-a4ay-settings-general-explorer.md
  - analysis-a4bb-general-controls-parity.md
  - review-a4bc-section-polish-inventory.md
  - review-a4bc-navigation-badging-check.md
  - staging-log.md
status: draft
---

# A4bc Next Slice Selection

## Starting Point

A4bb is closed and committed locally as `014e549ae4` plus changelog `4187640e01`, git range `88f2739713...4187640e01`. The active reopened-A4/N12 backlog should now be re-evaluated from source rather than assumed from stale inventory bullets.

The current likely candidates are:

- Section loading/docs/copy polish across the native WooPayments settings sections. This is the main residual explicitly left out of A4bb by `analysis-a4bb-general-controls-parity.md` and remains listed in `review-a4ay-settings-general-explorer.md`.
- Persistent admin navigation and badging from N12 Surface 1. A4at closed provider-route reachability and made an explicit setup-required badge disposition, but N12's menu/badging language is broader enough that it needs one fresh source-backed check before being treated as closed or parked.
- A widened A4 exit-gate refresh after the recent reopened-A4 settings slices. This should wait until the next product polish slice is selected or intentionally declined.

## Subagent Dispatch

Two read-only explorers are being launched:

- Section polish explorer: compare current native settings sections against the read-only WooPayments client reference for remaining loading skeletons, docs links, and copy/content gaps after A4bb.
- Navigation/badging explorer: verify whether N12 persistent admin navigation and menu/badging remain source-backed blockers after A4at/A4bb or are already covered/dispositioned by current native route ownership.

## Provisional Direction

Do not touch product code until the explorer findings are reconciled. If section polish remains the only source-backed merchant-facing gap, A4bc should be a bounded "settings loading and docs/copy polish" slice rather than reopening broad component hoisting.

## Local Source Check

Native `plugins/woocommerce/client/admin/client/woopayments/settings/settings-page.tsx` still renders a single page-level loading line (`Loading WooPayments settings…`) while the reference settings manager wraps every major section in `LoadableSettingsSection` from `/Users/vladolaru/Work/a8c/woocommerce-payments/client/settings/loadable-settings-section.js`, which delegates to `LoadableBlock` from `/Users/vladolaru/Work/a8c/woocommerce-payments/client/components/loadable/index.tsx`. The reference keeps the settings section/chrome visible during loading and shows block placeholders; native hides the whole settings body until settings exist. This is a source-backed visual/content parity gap and is coherent enough to fix with a native-scoped `LoadableSettingsSection`/placeholder component.

The reference placeholder styling is small: `/Users/vladolaru/Work/a8c/woocommerce-payments/client/components/loadable/style.scss` defines `.is-loadable-placeholder` with a `loading-fade` animation, `prefers-reduced-motion: reduce`, and block/inline variants. A native implementation should scope the styles under `.woopayments-settings-page` or use a WooPayments-specific class to avoid global CSS contamination.

The remaining docs/copy deltas are source-backed and section-level:

- Payment methods: reference `payment-methods-section/index.js` describes "Based on their device type, location, and purchase history..." while native uses the shorter "Add and edit the payment methods customers can use at checkout." There is no reference docs link here, so the likely A4bc target is copy parity, not adding a link.
- Buy now, pay later: reference `buy-now-pay-later-section/index.js` describes "Boost sales by offering customers additional buying power and flexible payment options." and links to `https://woocommerce.com/document/woopayments/payment-methods/buy-now-pay-later/`; native uses "Offer flexible payment options when they are available for your account." and has no link.
- Transactions: reference `settings-manager/index.js` describes "Update your store's configuration to ensure smooth transactions." and links to `https://woocommerce.com/document/woopayments/`; native uses "Update transaction preferences, customer statements, and support contact details." and has no section docs link. The lower transaction controls are mostly already parity-covered.
- Payouts: reference `settings-manager/index.js` includes the payout delay description plus `Learn more about pending schedules` linking to `https://woocommerce.com/document/woopayments/payouts/payout-schedule/`; native has the delay description but no section-level link. Earlier A4 slices already restored the bank-account block and schedule notices, so this is just section description/link polish.
- Notifications: reference `notification-settings/index.tsx` includes the same "Receive important notifications..." copy plus `Learn more` linking to `https://woocommerce.com/document/woopayments/settings-guide/#account-notifications`; native has the copy but no section docs link.
- Advanced: reference `settings-manager/index.js` describes "More options for specific payment needs." and links to `https://woocommerce.com/document/woopayments/settings-guide/#advanced-settings`; native says "Configure payment features that apply to specific store needs." and has no section-level docs link. Multi-currency, deprecated subscriptions, and debug-mode control copy are already covered by native tests.

Initial candidate A4bc scope: add native section placeholders matching the reference loading structure, update section descriptions/docs links for Payment methods, BNPL, Transactions, Payouts, Notifications, and Advanced, and prove the behavior with focused settings-page Jest plus Playwriter visual/log proof. Non-goals: Stripe Billing, new routes, persistent menu/badging unless the navigation explorer verifies a blocker, and broad SCSS/component hoisting.

## Explorer Reconciliation

The section-polish explorer confirmed A4bc is a bounded source-backed slice: native settings still lack the reference per-section loading skeleton pattern, and section-level docs/copy gaps remain for BNPL, Transactions/manual-capture inline help, Payouts, Notifications, and Advanced. It also verified that the older A4av-A4bb bullets should stay closed: General controls, VAT deep link, payment-method row guidance, manual-capture confirmation, payout bank-account mechanics, notifications validation, and advanced controls.

The navigation/badging explorer confirmed that N12 persistent navigation and Disputes/Transactions badging do not block A4bc. Current native source owns WooPayments routes as Core Settings > Payments provider subroutes, preserves legacy `/payments/*` compatibility redirects, keeps full/restricted/onboarding account-state menu variants, and includes native Disputes/Transactions badges. The plugin-era setup-required top-level badge remains parked as a separate Core-native product/navigation decision rather than product code to port mechanically.

Decision: A4bc will implement settings section loading and docs/copy polish only. It will not reopen navigation, badging, General controls, payment-method row behavior, account-mode controls, Stripe Billing, routing, or WPCOM/platform work.
