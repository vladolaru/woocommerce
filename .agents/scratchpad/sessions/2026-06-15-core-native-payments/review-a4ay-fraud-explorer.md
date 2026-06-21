---
session: 2026-06-15-core-native-payments
type: review
by: subagent:Herschel the 6th
created: 2026-06-20 22:59
target: A4ay fraud protection parity inventory
reconciles:
  - analysis-a4ay-fraud-onboarding-tracking-parity.md
  - review-a4au-settings-inventory.md
status: final
---

# A4ay Fraud Explorer Findings

## Scope

Read-only source review of native WooPayments fraud-protection settings parity after A4av, A4aw, and A4ax. No source files were edited, no tests were run, and no WPCOM or remote sandbox access occurred.

## Closed Gaps

- The old native fraud selector was replaced. Earlier native code used a `SelectControl` with Basic/Standard/Advanced and preserved-rule counts; current native has reference-style Basic/Advanced controls, help modal, and fraud copy/link in `plugins/woocommerce/client/admin/client/woopayments/settings/settings-page.tsx` and `plugins/woocommerce/client/admin/client/woopayments/settings/fraud-protection/index.tsx`.
- A dedicated advanced fraud route/subpage exists through `plugins/woocommerce/client/admin/client/woopayments/admin/routes.tsx`.
- The advanced fraud page renders all seven rule cards, validation, save behavior, dirty-state beforeunload handling, and error handling in `plugins/woocommerce/client/admin/client/woopayments/settings/fraud-protection/advanced/index.tsx`.
- The backend/settings contract exposes fraud environment fields and saves canonical fraud rulesets in `plugins/woocommerce/src/Internal/Payments/Providers/WooPayments/WooPaymentsSettingsService.php`.
- Legacy `/payments/fraud-protection` redirects to native `/woopayments/settings/fraud-protection` in `plugins/woocommerce/src/Internal/Admin/Settings/PaymentsProviders/WooPayments/WooPaymentsAdminNavigationController.php`.

## Remaining Source-Backed Gaps

- Onboarding/tour is still missing. The reference renders `FraudProtectionTour` from `/Users/vladolaru/Work/a8c/woocommerce-payments/client/settings/fraud-protection/index.tsx`, with TourKit dismissal and tracking in `/Users/vladolaru/Work/a8c/woocommerce-payments/client/settings/fraud-protection/tour/index.tsx`. Native has the save-option endpoint but no tour UI and no exposed dismissed state in the settings payload.
- Fraud Tracks parity is missing. The reference records preset changes, Basic modal views, advanced save, card-view impressions, and tour completion/abandonment in `/Users/vladolaru/Work/a8c/woocommerce-payments/client/settings/fraud-protection/protection-levels/index.tsx` and `/Users/vladolaru/Work/a8c/woocommerce-payments/client/settings/fraud-protection/advanced-settings/index.tsx`. Native fraud handlers contain no `recordEvent` calls.
- Source-backed ruleset refresh remains a separate gap. Native reads `wcpay_fraud_protection_settings` from transient/local gateway option and only exposes `save_fraud_ruleset`, while the reference fetches the latest server rulesets and initializes Basic on not-found in `/Users/vladolaru/Work/a8c/woocommerce-payments/includes/class-wc-payment-gateway-wcpay.php`.
- Advanced loading is not visual-parity-backed. Native advanced page returns a plain status string; reference uses `FormBusyState` and per-card `LoadableBlock` skeletons.
- Advanced card detail parity remains thin: native lacks linked AVS/supported-country/IP text, allowed-country notices, currency-aware amount inputs, "Leave blank for no limit" help, and inline threshold warning/error notices compared with reference advanced-settings card components.

## Recommendation

Do not move off fraud entirely after the tour/tracking slice. A4ay can stay bounded to tour and Tracks, but follow-up fraud slices should close source-backed residuals: fraud ruleset GET/refresh/defaulting, advanced loading skeletons, and card-detail parity. Proof should include JS tests for tour rendering/dismissal, Tracks events, advanced loading skeletons, threshold inline notices, allowed-country notices, and linked card copy; PHP tests for latest-ruleset/transient-miss/not-found refresh behavior; and browser proof for main fraud, Basic modal, first-visit tour, advanced rules under different selling-country modes, save/error states, and loading/saving states.
