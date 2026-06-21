---
session: 2026-06-15-core-native-payments
type: analysis
by: codex
created: 2026-06-20 22:54
last_updated: 2026-06-20 22:59
target: A4ay fraud onboarding and tracking parity
reconciles:
  - review-a4au-settings-inventory.md
  - review-a4ay-fraud-explorer.md
  - review-a4ay-settings-general-explorer.md
  - supervisor-prompt-2026-06-18-2344-N12.md
status: draft
---

# A4ay Fraud Onboarding And Tracking Parity

## Prompt

> Continue working toward the active Core native payments goal after A4ax.

## Source-Backed Selection

After A4av, A4aw, and A4ax, the highest settings inventory items are closed: payment-method guidance, VAT deep-linking, and express checkout settings flags/notices/content. The next coherent source-backed settings gap is fraud onboarding and instrumentation. Native Core already has the mechanical Basic/Advanced fraud controls and an advanced fraud-protection sub-route, so this should not reopen a broad component hoist. The remaining patch-sized gap is the reference WooPayments onboarding tour plus Tracks continuity around fraud risk-level interactions and advanced-rule visibility/save events.

Native `FraudProtectionSettings` renders the Basic/Advanced controls, opens the Basic help modal, and links the Advanced route, but it does not call `recordEvent()` for risk preset changes or Basic modal views ([index.tsx](/Users/vladolaru/Work/a8c/woocommerce-develop-2/plugins/woocommerce/client/admin/client/woopayments/settings/fraud-protection/index.tsx:168), [index.tsx](/Users/vladolaru/Work/a8c/woocommerce-develop-2/plugins/woocommerce/client/admin/client/woopayments/settings/fraud-protection/index.tsx:214)). Reference WooPayments records `wcpay_fraud_protection_risk_level_preset_enabled` with the selected preset and `wcpay_fraud_protection_basic_modal_viewed` ([index.tsx](/Users/vladolaru/Work/a8c/woocommerce-payments/client/settings/fraud-protection/protection-levels/index.tsx:58), [index.tsx](/Users/vladolaru/Work/a8c/woocommerce-payments/client/settings/fraud-protection/protection-levels/index.tsx:65)).

Native has no fraud welcome tour component. Reference renders `FraudProtectionTour`, waits for the fraud settings reference element to become visible, persists `wcpay_fraud_protection_welcome_tour_dismissed`, and records `wcpay_fraud_protection_tour_clicked_through` or `wcpay_fraud_protection_tour_abandoned` on close ([tour/index.tsx](/Users/vladolaru/Work/a8c/woocommerce-payments/client/settings/fraud-protection/tour/index.tsx:34), [tour/index.tsx](/Users/vladolaru/Work/a8c/woocommerce-payments/client/settings/fraud-protection/tour/index.tsx:58)). The tour content itself is specific merchant guidance about enhanced fraud protection, choosing a filter level, taking control with Advanced settings, and reviewing blocked transactions ([steps.tsx](/Users/vladolaru/Work/a8c/woocommerce-payments/client/settings/fraud-protection/tour/steps.tsx:8)).

Native `FraudProtectionAdvancedSettingsPage` has the seven rule cards and save logic, but it does not record the reference advanced-settings save event or card-view events ([advanced/index.tsx](/Users/vladolaru/Work/a8c/woocommerce-develop-2/plugins/woocommerce/client/admin/client/woopayments/settings/fraud-protection/advanced/index.tsx:91), [advanced/index.tsx](/Users/vladolaru/Work/a8c/woocommerce-develop-2/plugins/woocommerce/client/admin/client/woopayments/settings/fraud-protection/advanced/index.tsx:360)). Reference records `wcpay_fraud_protection_advanced_settings_saved` with serialized settings and records one view event per advanced rule card through an `IntersectionObserver` ([advanced-settings/index.tsx](/Users/vladolaru/Work/a8c/woocommerce-payments/client/settings/fraud-protection/advanced-settings/index.tsx:46), [advanced-settings/index.tsx](/Users/vladolaru/Work/a8c/woocommerce-payments/client/settings/fraud-protection/advanced-settings/index.tsx:220), [advanced-settings/index.tsx](/Users/vladolaru/Work/a8c/woocommerce-payments/client/settings/fraud-protection/advanced-settings/index.tsx:225)).

The native platform support exists: `@woocommerce/tracks` is already used in native WooPayments admin surfaces, Core admin already provides `TourKit`, and the native settings option endpoint allowlists `wcpay_fraud_protection_welcome_tour_dismissed` ([WooPaymentsSettingsService.php](/Users/vladolaru/Work/a8c/woocommerce-develop-2/plugins/woocommerce/src/Internal/Payments/Providers/WooPayments/WooPaymentsSettingsService.php:131), [settings-data.test.ts](/Users/vladolaru/Work/a8c/woocommerce-develop-2/plugins/woocommerce/client/admin/client/woopayments/settings/test/settings-data.test.ts:238)). The missing piece is that the settings response does not expose the dismissed state to the frontend; adding a snake-case `is_welcome_tour_dismissed` field under `fraud_protection` keeps it in the existing fraud settings contract rather than resurrecting plugin-global `wcpaySettings`.

## Explorer Reconciliation

Herschel the 6th independently confirmed that the Basic/Advanced fraud controls, advanced fraud route, seven rule cards, fraud settings save contract, and legacy `/payments/fraud-protection` redirect are now present. The same source review confirmed A4ay's selected gap: no native tour UI, no exposed dismissed-tour state, and no fraud `recordEvent()` calls for preset changes, Basic modal views, advanced save, advanced card impressions, or tour close outcomes. The explorer also surfaced broader fraud residuals that should not be lost: native still lacks source-backed fraud ruleset GET/refresh/defaulting, reference-style advanced loading skeletons, and several advanced-card detail affordances including allowed-country notices, linked AVS/supported-country/IP text, currency-aware amount inputs, "Leave blank for no limit" help, and inline threshold warnings/errors. Those are real follow-up candidates but not required dependencies for the current tour/tracking patch.

Maxwell the 6th reviewed General/settings polish and confirmed those gaps are separate from A4ay. Switch-to-live tooltip/tracking/modal busy state, General enable-disable confirmation/tracking, test-mode help/tracking, payment-request save telemetry, per-section loading skeletons, and remaining docs links should be queued as later A4 follow-up work rather than mixed into the fraud slice.

## Boundaries

This slice should not broaden into the full N12 SCSS hoist or fraud visual rebuild. Basic/Advanced controls, the advanced subpage, rule cards, CVC/AVS/platform flags, and validation mechanics are already present and tested. A4ay should add the missing onboarding/tour/tracking continuity around those existing mechanics, plus the smallest settings response field needed to make the tour deterministic. It should keep WooPayments-specific fraud code in the existing WooPayments settings chunks and not touch WPCOM, reference plugin files, generic settings routes, checkout, or money movement.

## Verification Strategy

Start with RED tests in `WooPaymentsSettingsServiceTest`, `settings-page.test.tsx`, and `fraud-protection-advanced.test.tsx`: settings exposes the tour dismissed flag; risk-level changes and Basic modal clicks record reference events; the tour appears only when not dismissed and the fraud section is visible, saves the allowed option on close, and records clicked-through/abandoned; advanced save records the serialized ruleset; advanced rule-card visibility records each card once. Then implement minimal frontend helpers/components. Browser proof should load target `/woopayments/settings` and `/woopayments/settings/fraud-protection`, verify the fraud settings section and advanced route still render, and scan target debug/Docker logs; Tracks itself remains a Jest/sink-style assertion, not a browser-network assertion.
