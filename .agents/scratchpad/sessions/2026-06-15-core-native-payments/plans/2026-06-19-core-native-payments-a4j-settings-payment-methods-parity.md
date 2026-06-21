---
session: 2026-06-15-core-native-payments
type: plan
by: codex
created: 2026-06-19 01:05
target: exp/core-native-payments — A4j settings payment methods parity
reconciles:
  - supervisor-prompt-2026-06-18-2344-N12.md
  - analysis-a4j-settings-payment-methods-parity.md
status: final
last_updated: 2026-06-19 01:41
---

# A4j Settings Payment Methods Parity

## Objective

Close the N12 settings-page parity gap for the shared WooPayments payment-method list used by both "Payments accepted on checkout" and "Buy now, pay later". Native must stop rendering plain checkbox/status rows and instead expose reference-equivalent row composition, copy, controls, activation behavior, visual grouping, and Core-owned scoped styling for these two sections.

## Scope

In scope:

1. Native method definition map for all currently supported WooPayments payment method IDs, including labels, descriptions, Stripe capability keys, BNPL classification, manual-capture support, and Core-owned icon assets.
2. Shared rich payment-method list component for standard methods and BNPL methods.
3. Card row parity: card sorted first, locked when enabled, "Required" label, card-brand logos, and disabled uncheck.
4. Capability-status behavior: inactive, pending approval, pending verification, rejected, and unrequested-with-requirements states render badges/notices and disable or gate actions as the reference does.
5. Activation modal for unrequested methods with requirements, using native requirements labels and the account email from the native settings payload when available.
6. Manual-capture conflict banner in the standard payment methods section, plus automatic disabled rows for methods that do not support manual capture.
7. Native scoped SCSS for the rich list rows, banner, card logos, badges, notices, modal, and responsive layout.
8. Focused Jest/PHP coverage and browser parity smoke for the target settings page against the reference store where practical.

Out of scope for this slice:

1. Express checkout "Customize" sub-pages and live preview.
2. Payout bank-account block.
3. Fraud-protection Basic/Advanced radios and advanced sub-page.
4. General switch-to-live notice.
5. `FormBusyState`, VAT modal, PM promotions, and duplicate-payment-method notices unless the required payload is already source-proven during implementation.
6. Stripe Billing UI or behavior.

## Tasks

1. Add RED tests for the payment-method row parity in `plugins/woocommerce/client/admin/client/woopayments/settings/test/settings-page.test.tsx`: card is first and locked with card logos, BNPL renders through the same rich row component, manual-capture banner appears and disables incompatible methods, unrequested methods open an activation modal before enabling, and inactive/pending/rejected statuses render reference-style badges/notices.
2. Add RED backend coverage only if the UI needs missing payload fields already source-backed by native account data, likely `account_email` and `account_fees`. Keep any payload widening narrowly scoped to the settings contract and covered in `WooPaymentsSettingsServiceTest`.
3. Implement the native method-definition map and rich list components under `plugins/woocommerce/client/admin/client/woopayments/settings/`, keeping them independent from the standalone plugin global and using Core-owned assets.
4. Replace the current `PaymentMethodToggle`, `PaymentMethodsSettingsSection`, and `BuyNowPayLaterSettingsSection` internals with the shared rich list path while preserving the existing settings store hooks and single registry.
5. Add scoped styles to the native WooPayments settings bundle, importing them naturally through the settings entry so WooPayments-specific styling remains bundled only with the native WooPayments settings surface.
6. Run focused Jest, targeted ESLint/stylelint, TypeScript check if touched types require it, `git diff --check`, focused PHP tests if backend payload changes, PHP lint/PHPStan as applicable, and the A4 admin surface gate if route/readiness assertions are affected.
7. Use Playwriter for target settings browser verification and reference comparison for the implemented sections. Record screenshots/observations and explicitly list any residual N12 payment-method-section parity blockers rather than over-claiming.
8. Request focused review agents for accessibility, frontend/test quality, and reference parity before committing.
9. Update `analysis-a4j-settings-payment-methods-parity.md`, `implementation-log.md`, `staging-log.md`, and `spec-conformance-baseline.md`; then commit source/test and changelog as separate logical commits if gates pass.

## Acceptance Gates

1. The target settings page no longer shows plain checkbox/status rows for standard payment methods or BNPL methods.
2. Card, standard APM, and BNPL rows have reference-equivalent grouping: checkbox, icon, label, description, status/required badge, fee slot when data exists, notices, and action gating.
3. Enabling an unrequested method with requirements opens the activation modal first; confirming enables it through the existing store action; canceling leaves it disabled.
4. Manual capture surfaces the warning banner and disables incompatible rows before save, matching the reference behavior.
5. Styling is scoped to the native WooPayments settings bundle and does not add global admin or checkout assets.
6. A4 remains open after this slice unless the widened N12 settings/dashboard parity gate has actually passed; A5 readiness remains fail-closed.

## Closeout

A4j completed the shared native payment-method and BNPL row foundation. The implementation stayed on the existing native settings store, used Core-owned assets, and kept styling scoped to the native WooPayments settings chunk. The browser and test gates passed for the implemented scope, including card required/brand rows with Cartes Bancaires, account-country-specific Afterpay/Clearpay settings branding, manual-capture conflict behavior, activation modal gating, inactive notices, route loading, bundle build, and changelog validation.

Task 2 did not require backend payload widening in this slice. Source verification showed that account fees, duplicate notices, and PM promotions are not yet exposed by the native settings payload in the shape required to render the reference extras honestly, so those are carried as explicit reopened-A4 blockers rather than stubbed or hidden.

Task 8 was covered by read-only subagents plus local source/reference review. The first a11y and reference-integrity review pass found source-backed blockers; those were fixed before closeout with non-dismissible manual-capture notice behavior, row-level manual-capture disabled reasons, country-aware Afterpay/Clearpay settings definitions, and the missing Cartes Bancaires card asset. A focused re-review pass was requested before commit. Broader A4 settings/dashboard parity still needs review agents at the stage boundary.

Focused a11y and reference-integrity re-review agents approved with no findings. A4j was committed as source/assets/tests/styles `42846f540a` and changelog `47527b39fc`; git range `1400d54ed9...47527b39fc`.
