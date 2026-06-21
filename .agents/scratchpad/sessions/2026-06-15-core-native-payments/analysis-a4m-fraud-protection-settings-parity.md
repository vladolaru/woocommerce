---
session: 2026-06-15-core-native-payments
type: analysis
by: codex
created: 2026-06-19 04:14
last_updated: 2026-06-19 04:19
target: exp/core-native-payments - A4m fraud protection settings parity
reconciles:
  - supervisor-prompt-2026-06-18-2344-N12.md
  - analysis-a4l-express-checkout-settings-parity.md
  - staging-log.md
status: draft
---

# A4m Fraud Protection Settings Parity

## Prompt

> Continue working toward the active thread goal.

## Working Frame

A4 remains reopened by N12 until native admin surfaces prove merchant reachability, functional/visual parity, and copy/content parity against the reference store. A4i closed persistent navigation for existing native routes, A4j/A4k closed the shared payment-method row foundation and fee/duplicate extras, and A4l closed express checkout Customize subpages. The next settings parity gap with a clean architectural boundary is fraud protection: the native main settings page still renders a simplified `SelectControl` with a non-reference `standard` option and a "rules preserved" count, while the reference uses a Basic/Advanced radio model with explanatory copy, a Basic help modal, an error notice, tour behavior, and a dedicated advanced fraud-protection subpage with rule cards.

## Source-Mapping Questions

1. Which reference fraud protection components and data helpers define the merchant-facing contract?
2. Which native settings store/backend fields already exist and which gaps remain?
3. Should the next slice include only the main Basic/Advanced selector or the advanced subpage too?
4. Which tests and browser gates prove parity without broadening into dashboard fraud-outcome surfaces?

## Findings

### Reference Contract

Reference `client/settings/fraud-protection/index.tsx` renders a card titled `Set your payment risk level`, the `ProtectionLevels` radio group, and `FraudProtectionTour`. `ProtectionLevels` reads `useCurrentProtectionLevel`, `useAdvancedFraudProtectionSettings`, `useSettings`, and `useGetSettings`, then renders exactly two merchant-facing choices in the current settings surface: `Basic` and `Advanced`. Although `constants.ts` still contains `STANDARD` and `HIGH`, the UI in `protection-levels/index.tsx` does not render those as selectable rows.

Reference Basic copy is `Provides the base level of platform protection.` and has a help icon that opens a `Basic filter level` modal. The modal explains that payments are blocked when active platform checks fail, with conditional bullets for AVS and CVC based on `wcpaySettings.accountStatus.fraudProtection.declineOnAVSFailure` and `declineOnCVCFailure`; it closes with `Got it`.

Reference Advanced copy is `Allows you to fine-tune the level of filtering according to your business needs.` and the action button is `Configure` when no advanced settings are configured, otherwise `Edit`. The button links to the plugin-era advanced fraud route `/payments/fraud-protection`; native should adapt this into the Core Settings > Payments provider route seam, not reintroduce plugin-era ownership.

Reference advanced page `client/settings/fraud-protection/advanced-settings/index.tsx` is a dedicated route. It renders the title `Advanced fraud protection`, a back link to the WooPayments settings section, a `Filter configuration` settings section, the description `Set up advanced fraud filters. Enable at least one filter to activate advanced protection.`, a busy overlay through `FormBusyState`, validation/error notices, loadable rule cards, and a footer `Save changes` button. It also handles dirty navigation, scroll-to-top validation errors, and toggles the saved `current_protection_level` to `basic` if no advanced filters are enabled.

The reference rule set is self-contained under `advanced-settings/`: `constants.ts`, `interfaces.ts`, `utils.ts`, `context.ts`, `rule-card.tsx`, `rule-toggle.tsx`, `rule-description.tsx`, `rule-card-notice.tsx`, `allow-countries-notice.tsx`, and cards for AVS Mismatch, International IP Address, IP Address Mismatch, Address Mismatch, Purchase Price Threshold, Order Items Threshold, and CVC Verification. The card helpers convert between UI state and the provider ruleset shape stored in `advanced_fraud_protection_settings`, including threshold values encoded as minor amount plus currency (`amount|currency`).

### Native State

Native `settings-page.tsx` still has `type FraudProtectionLevel = 'basic' | 'standard' | 'advanced'` and a `FraudProtectionSettingsSection` that renders a `SelectControl` with `Basic`, `Standard`, and `Advanced`. This is a direct N12 parity miss: `Standard` is a stale/non-reference merchant-facing option in the current settings UI, and the select loses the reference grouping/copy/modal/configure action.

Native settings store hooks already expose the key contract: `useCurrentProtectionLevel`, `useAdvancedFraudProtectionSettings`, `useSettings`, and `useGetSettings`. Actions already update `current_protection_level` and `advanced_fraud_protection_settings`. Selectors default current level to `basic` and advanced rules to an empty array. This means A4m should not invent a new store; it should adapt the reference component tree onto the existing `wc/payments/settings` store.

Native backend `WooPaymentsSettingsService` already returns `current_protection_level` and `advanced_fraud_protection_settings`, persists provider-backed fraud settings through `save_fraud_ruleset`, stores the transient `wcpay_fraud_protection_settings`, and updates option `current_protection_level`. It accepts `basic`, `standard`, `high`, and `advanced` for backend compatibility and canonical preset saves, but the frontend should not expose Standard/High as current merchant settings choices unless reference does. A4m should add backend tests only if the UI needs additional bootstrap fields such as fraud feature flags or account fraud protection status.

Native route registration can follow A4l: add a lazy Core-owned provider sub-route `/woopayments/settings/fraud-protection`, keep the main settings route exact, and link with `getSettingsPaymentsProviderRouteUrl()`. This keeps third-party/provider settings routing under WooCommerce Settings > Payments and avoids plugin-era `/payments/*` ownership.

### Slice Boundary

The right A4m slice should include both the main Basic/Advanced fraud selector and the advanced fraud-protection subpage. Splitting them would leave the `Advanced` row pointing to a missing route and would keep native merchant flow half-baked. The advanced page is sizable, but it is a coherent subtree with an existing data contract and local rule helpers. It should remain a WooPayments settings chunk/subchunk, not a generic provider-list dependency.

The slice should not include order fraud/risk metaboxes, fraud outcome transaction lists, dispute/risk dashboard pages, or checkout fraud-prevention tokens. Those belong to separate A3/A4 surfaces if source evidence shows gaps.

### RED Targets

Route tests should assert `/woopayments/settings/fraud-protection/advanced` registers immediately after the main settings/express routes, has a dedicated lazy chunk, and appears in the provider-route bootstrap list. URL helper tests should prove the route becomes an encoded Settings > Payments URL.

Main settings tests should fail first on the current select by asserting that `Fraud protection` renders `Set your payment risk level`, only Basic and Advanced radio options, the Basic and Advanced help text, no `Standard` option, a Basic help modal with AVS/CVC conditional bullets, and an enabled `Configure` or `Edit` link to the native advanced route only when Advanced is selected.

Advanced page tests should fail first because no native component exists. They should assert the route renders `Advanced fraud protection`, a back link to WooPayments settings, `Filter configuration`, the seven rule card headings, rule toggles, filter-action radios when the review feature flag is active, threshold inputs for purchase price/order items, validation errors for invalid thresholds, save behavior that writes `advanced_fraud_protection_settings`, and level fallback to `basic` when all rules are disabled.

Browser checks should compare reference `http://localhost:8082/wp-admin/admin.php?page=wc-settings&tab=checkout&section=woocommerce_payments` and target `http://store8889.localhost:8889/wp-admin/admin.php?page=wc-settings&tab=checkout&path=%2Fwoopayments%2Fsettings` for the main fraud section, then navigate to the advanced fraud route on both where possible. Use screenshots and DOM assertions for grouping/copy/controls; do not claim pixel-perfect styling.

### Open Decisions To Resolve In Plan

Native needs a local replacement for reference globals `wcpaySettings.featureFlags.isFRTReviewFeatureActive`, `wcpaySettings.accountStatus.fraudProtection`, `wcpaySettings.storeCurrency`, and `wcSettings.admin.preloadSettings.general`/`wcSettings.countries`. The plan should prefer passing these through the existing native settings/bootstrap payload or safe fallback helpers, not reading plugin globals. If source-backed account fraud status is unavailable in Core, the Basic modal can default to the reference-safe AVS/CVC enabled values while recording the bootstrap gap; however, the better implementation is to expose the cached account fraud protection fields through `WooPaymentsSettingsService`.

The reference tracks fraud-protection UI events. Tracks continuity is a hard requirement across surviving surfaces, but prior H22/H30 slices focused checkout/provider events. A4m should at least avoid inventing new event names; if native admin Tracks infrastructure exists in this bundle, wire the preserved event names, otherwise record admin fraud Tracks as an explicit follow-up rather than silently dropping the behavioral contract.

## Explorer Findings Reconciled

Jason confirmed the native fraud section is a thin `SelectControl` with `Basic`, `Standard`, and `Advanced`, plus a preserved-rules count, while the reference visible parity target is Basic/Advanced only. Jason also confirmed the existing native settings store already saves `current_protection_level` and `advanced_fraud_protection_settings`, and that the backend accepts legacy/canonical levels including `standard` and `high` for compatibility. The source-backed payload gaps are store currency, normalized AVS/CVC fraud flags, the FRT review feature flag, and selling-location settings for the international-IP and AVS warning behavior. The recommendation is one focused fraud slice with route `/woopayments/settings/fraud-protection`, preserving the existing save endpoint and key names while excluding Standard/High UI, welcome tour, admin Tracks parity, fraud transaction review surfaces, and WPCOM/server changes.

Sartre mapped the non-fraud N12 settings backlog and recommended payout/deposit bank-account parity as the next non-fraud settings slice because Core already has native deposits overview REST support and the missing UI is isolated to the Payouts settings section. Additional non-fraud gaps are sandbox/test switch-to-live notices, page-wide save busy state, VAT modal/backend, payment-method promotions/spotlight, notification email confirmation/validation, transaction support email/phone validation, and the intentionally excluded Stripe Billing surface. I am keeping payouts as the next candidate slice after A4m unless browser/source evidence changes the priority.
