---
session: 2026-06-15-core-native-payments
type: review
by: subagent:explorer
created: 2026-06-21 00:22
last_updated: 2026-06-21 00:27
status: final
---

# A4ba Advanced Fraud UI Source Check

> **Prompt:** "Read-only source verification for the next reopened-A4/N12 fraud UI parity slice in `/Users/vladolaru/Work/a8c/woocommerce-develop-2`.
>
> Goal: verify the remaining advanced fraud UI residual after A4az. Do not edit files, do not access WPCOM sandbox, do not push.
>
> Inspect current native Core and the read-only WooPayments client reference:
> - Native Core: `plugins/woocommerce/client/admin/client/woopayments/settings/fraud-protection/advanced/index.tsx`
> - Native Core: `plugins/woocommerce/client/admin/client/woopayments/settings/fraud-protection/advanced/*`
> - Native Core: `plugins/woocommerce/client/admin/client/woopayments/settings/fraud-protection/index.tsx`
> - Native Core tests: `plugins/woocommerce/client/admin/client/woopayments/settings/test/fraud-protection-advanced.test.tsx` and `settings-page.test.tsx`
> - Reference: `/Users/vladolaru/Work/a8c/woocommerce-payments/client/settings/fraud-protection/advanced-settings/`
> - Reference: `/Users/vladolaru/Work/a8c/woocommerce-payments/client/settings/fraud-protection/`
>
> Questions:
> 1. What exact advanced fraud loading-state, error-state, rule-card detail, copy, interaction, or styling behaviors still differ between native and reference?
> 2. Which differences are merchant-facing and source-backed enough to include in the next coherent implementation slice?
> 3. Which should remain out of scope because they are non-critical, already covered, or require a broader design change?
>
> Write findings to `.agents/scratchpad/sessions/2026-06-15-core-native-payments/review-a4ba-advanced-fraud-ui-source-check.md` with scratchpad frontmatter (`session: 2026-06-15-core-native-payments`, `type: review`, `by: subagent:explorer`, current local time from `date`, `status: final`). No hard-wrapped prose. Return a concise severity/prioritization summary."

## Status

Read-only source verification complete. Product source files were inspected only. No WPCOM sandbox access, push, commit, or product-code edit occurred.

## Sources Checked

Native Core checked: `plugins/woocommerce/client/admin/client/woopayments/settings/fraud-protection/advanced/index.tsx`, `advanced/constants.ts`, `advanced/types.ts`, `advanced/utils.ts`, `advanced/style.scss`, `plugins/woocommerce/client/admin/client/woopayments/settings/fraud-protection/index.tsx`, `plugins/woocommerce/client/admin/client/woopayments/settings/fraud-protection/style.scss`, `plugins/woocommerce/client/admin/client/woopayments/settings/settings-page.tsx`, `plugins/woocommerce/client/admin/client/woopayments/settings/settings-busy-state.tsx`, `plugins/woocommerce/client/admin/client/woopayments/settings/test/fraud-protection-advanced.test.tsx`, and `plugins/woocommerce/client/admin/client/woopayments/settings/test/settings-page.test.tsx`.

WooPayments reference checked read-only: `/Users/vladolaru/Work/a8c/woocommerce-payments/client/settings/fraud-protection/index.tsx`, `protection-levels/index.tsx`, `protection-levels/styles.scss`, `advanced-settings/index.tsx`, `advanced-settings/rule-card.tsx`, `advanced-settings/rule-toggle.tsx`, `advanced-settings/rule-description.tsx`, `advanced-settings/rule-card-notice.tsx`, `advanced-settings/allow-countries-notice.tsx`, all rule cards under `advanced-settings/cards/`, `advanced-settings/utils.ts`, `advanced-settings/style.scss`, `advanced-settings/rule-card.scss`, `advanced-settings/rule-toggle.scss`, `advanced-settings/rule-description.scss`, and the reference tests under `advanced-settings/__tests__/` and `advanced-settings/cards/__tests__/`.

## Current Native Baseline After A4az

A4az is present at `HEAD` as `eafa398f37` after `edd99f99c8`, and the inspected fraud UI files have no Git-visible product diff. Native now has the frontend `'error'` sentinel handling in both the parent fraud section and advanced subpage, and the current tests cover the parent error notice/disabled fieldset/tour suppression, the advanced subpage error notice with unsavable controls hidden, save tracking, card impression tracking, validation focus, and preservation of existing `window.onbeforeunload` handlers.

The remaining source-backed residual is frontend UI parity: loading/saving shell behavior, error-state rendering details, rule-card help/copy/details, threshold control affordances, and some visual treatment. I did not find a remaining A4az-style backend ruleset/source-of-truth gap in this pass because the requested scope was the current advanced UI files and tests.

## Exact Differences

### Loading And Saving State

Native returns only a plain status element during advanced settings loading: `advanced/index.tsx:532-537` renders `Loading WooPayments settings…` and exits before the advanced header, description, cards, footer, or card placeholders are present.

Reference keeps the advanced page shell visible while settings load: `advanced-settings/index.tsx:316-421` renders breadcrumb/layout/section/footer and wraps each rule card in `LoadableBlock isLoading={ isLoading } numLines={ 20 }` at `advanced-settings/index.tsx:356-397`. The reference save button is also explicitly disabled while `isLoading`, while native has no button during loading.

Reference wraps the advanced content in `FormBusyState isBusy={ isSaving }` at `advanced-settings/index.tsx:318-420`. Native only marks the Save button busy/disabled at `advanced/index.tsx:861-865`; the rest of the form remains interactive during save. Native already has a local `SettingsBusyState` component with equivalent `aria-busy`/screen-reader save status behavior in `settings-busy-state.tsx:8-34`, so the behavior is implementable without importing the WooPayments extension-only `FormBusyState`.

Native tests do not assert any card-level loading skeleton or whole-form saving busy state. Reference tests primarily snapshot the rendered advanced page and do not deeply assert skeleton text, but the reference source is explicit.

### Error State

Native advanced subpage renders the heading/description plus a non-dismissible error notice and hides the rule cards and Save footer entirely when `advancedFraudProtectionSettings === 'error'`: `advanced/index.tsx:543-582`. The native test intentionally asserts no rule checkbox and no Save button on this state at `fraud-protection-advanced.test.tsx:332-354`.

Reference advanced subpage renders the error notice but still renders the card stack and footer, with Save disabled by the sentinel: `advanced-settings/index.tsx:343-417`. Because `readRuleset( 'error' )` falls back to default UI state in `advanced-settings/utils.ts:278-385`, merchants still see the rule documentation and default controls even though saving is blocked.

Native parent fraud section is stricter than the reference in an error state. Native removes the configure `href` and disables the Configure/Edit button when the sentinel is present at `fraud-protection/index.tsx:190-197` and `fraud-protection/index.tsx:281-293`, with tests at `settings-page.test.tsx:3172-3187`; reference disables the fieldset at `protection-levels/index.tsx:136-139`, but the Configure button only checks whether Advanced is selected at `protection-levels/index.tsx:207-223`. This parent behavior is already covered natively and should not be reopened just for parity with a weaker reference guard.

Reference wraps the advanced cards in an `ErrorBoundary` at `advanced-settings/index.tsx:323-418`; native has no per-page error boundary in `advanced/index.tsx`. Core does have a general admin `ErrorBoundary` under `plugins/woocommerce/client/admin/client/error-boundary/`, but adding page-level boundaries consistently is broader than a card-detail parity slice.

### Rule-Card Detail And Copy

AVS Mismatch: native shows the unsupported-selling-locations warning as plain text with no link: `advanced/index.tsx:589-599`. Reference links `selling locations` to WooCommerce General settings through `getAdminUrl( { page: 'wc-settings', tab: 'general' } )`: `advanced-settings/cards/avs-mismatch.tsx:26-47`. The copy is otherwise materially the same.

International IP Address: native shows the all-countries disabled warning and otherwise a plain description with no links and no allowed-countries notice: `advanced/index.tsx:634-675`. Reference links `IP addresses` to an explanatory external URL and `supported countries` to WooCommerce General settings, then renders `AllowedCountriesNotice` whenever selling locations are not `all`: `advanced-settings/cards/international-ip-address.tsx:43-76`.

Allowed countries notice: native has no equivalent. Reference tells merchants whether orders from or outside the configured country list will be blocked or screened based on the current rule action and decodes country names from `wcSettings.countries`: `advanced-settings/allow-countries-notice.tsx:16-75`. Reference tests cover specific-country screened/blocked, all-except screened/blocked, and decoded HTML entities at `advanced-settings/__tests__/allow-countries-notice.test.js:42-132`.

IP Address Mismatch: native renders the description as plain text: `advanced/index.tsx:681-706`. Reference links `IP address` to the same explanatory external URL: `advanced-settings/cards/ip-address-mismatch.tsx:27-39`.

Address Mismatch: I did not find material copy/functionality drift. Native `advanced/index.tsx:708-737` and reference `advanced-settings/cards/address-mismatch.tsx:14-39` express the same toggle label, description, and protection explanation.

CVC Verification: native and reference have equivalent merchant copy and the same WooPayments fraud-protection doc anchor for "Learn more": native `advanced/index.tsx:825-857`; reference `advanced-settings/cards/cvc-verification.tsx:16-55`. The remaining difference is notice styling/icon treatment, not missing copy or interaction.

Rule heading level/body wrapper: native uses `Card` with `h3` and class `woopayments-fraud-protection-rule` at `advanced/index.tsx:181-194`; reference uses `Card`, `CardBody`, `h4`, and class `fraud-protection-rule-card` at `advanced-settings/rule-card.tsx:18-29`. This is visible structure/styling, but not a content or interaction gap by itself.

### Threshold Controls And Validation Feedback

Purchase Price Threshold: native uses generic `TextControl type="number"` fields labeled `Minimum order amount` and `Maximum order amount` with no currency prefix, no placeholder, no helper text, no `Limits` subheading, and no inline warning/error before Save: `advanced/index.tsx:316-341`. Reference uses `AmountInput`, derives a currency symbol, labels fields `Minimum purchase price` and `Maximum purchase price`, uses placeholder `0.00`, adds `Leave blank for no limit` help, and renders inline warning/error notices for empty range and min greater than max: `advanced-settings/cards/purchase-price-threshold.tsx:27-141`.

Native still validates purchase thresholds on Save through a top error notice at `advanced/index.tsx:147-166` and `advanced/index.tsx:494-502`, but that only appears after the merchant clicks Save. Reference gives immediate inline guidance while the enabled rule is configured and also blocks Save through `PurchasePriceThresholdValidation`: `advanced-settings/cards/purchase-price-threshold.tsx:121-206`. Reference tests assert the inline warning/error and validation behavior at `cards/__tests__/purchase-price-threshold.test.js:93-280`.

Order Items Threshold: native uses generic `TextControl type="number"` fields labeled `Minimum items` and `Maximum items` with no placeholder, no helper text, no `Limits` subheading, no min/step attributes, no key filtering, and no inline warning/error before Save: `advanced/index.tsx:345-367`. Reference labels fields `Minimum items per order` and `Maximum items per order`, sets `placeholder="0"`, `min="1"`, `step="1"`, blocks `+`, `-`, `.`, `,`, and `e` key entry, adds `Leave blank for no limit` help, and renders inline warning/error notices for empty range and min greater than max: `advanced-settings/cards/order-items-threshold.tsx:63-136`.

Native still validates order item thresholds on Save through the top notice at `advanced/index.tsx:124-145`, but reference gives immediate inline guidance and validation coverage through `OrderItemsThresholdValidation`: `advanced-settings/cards/order-items-threshold.tsx:168-198`. Reference tests assert the inline warning/error and validation behavior at `cards/__tests__/order-items-threshold.test.js:79-260`.

### Interactions And Styling

Native rule enablement uses `CheckboxControl`: `advanced/index.tsx:231-241`. Reference uses `ToggleControl`: `advanced-settings/rule-toggle.tsx:83-91`. This is merchant-facing visual/interaction parity, although both expose a checkbox-like accessible control. Native’s improved rule-specific screen-reader label for `Filter action for %s` at `advanced/index.tsx:246-283` is better than the reference’s generic `Filter action` block at `rule-toggle.tsx:97-110`, and the native test protects it at `fraud-protection-advanced.test.tsx:304-330`; any toggle parity work should preserve that native accessibility improvement.

Native uses WordPress `Notice` inside cards and for top errors; reference uses WooPayments `InlineNotice` through `FraudProtectionRuleCardNotice`, with info/warning/error icons: native `advanced/index.tsx:566-581`, `advanced/index.tsx:590-599`, `advanced/index.tsx:635-643`, and `advanced/index.tsx:829-851`; reference `rule-card-notice.tsx:21-38`. This is a visible styling difference, and the info-style allowed-countries notice depends on it, but exact icon parity should be treated as lower priority than the missing notice content.

Native advanced dirty tracking is local and flips true on any control change: `advanced/index.tsx:396-435` and `advanced/index.tsx:487-492`. Reference computes whether the current UI differs from the original ruleset before registering its navigation warning: `advanced-settings/index.tsx:268-306`. That means native can warn after a merchant toggles a control back to its original value. This is source-backed and merchant-facing, but it is a broader dirty-state/route-navigation behavior than the card-detail residual.

Native advanced validation focuses the top error notice after Save failure: `advanced/index.tsx:437-447`, with a test at `fraud-protection-advanced.test.tsx:546-568`. Reference scrolls to the top on validation failure and renders a dismissible inline notice: `advanced-settings/index.tsx:178-184` and `advanced-settings/index.tsx:324-341`. Native’s focus behavior is arguably an accessibility improvement and should not be regressed just to match reference scroll behavior.

Native card spacing/layout is compressed into a 960px section with a CSS grid gap: `advanced/style.scss:1-67`. Reference uses the extension settings layout, card body wrapper, 24px card margins, a wider max-width, and wp-admin body/header overrides: `advanced-settings/style.scss:1-63`, `rule-card.scss:1-3`, and `rule-toggle.scss:1-20`. The exact extension layout should not be blindly copied into Core, but the threshold-control grouping and card-notice spacing are relevant when closing the visible card-detail gaps.

Reference’s IP Address Mismatch card id is `ip-address-mismatch`, while its observer mapping also uses `ip-address-mismatch`: `advanced-settings/cards/ip-address-mismatch.tsx:17-20` and `advanced-settings/index.tsx:53-54`. Native uses `ip-address-mismatch-card` consistently for the card id and event mapping: `advanced/index.tsx:71-72` and `advanced/index.tsx:677-680`, with impression tests at `fraud-protection-advanced.test.tsx:518-544`. This is not a merchant-facing gap and should stay out of scope.

## Recommended Next Coherent Slice

Include a focused A4ba frontend slice for advanced fraud card-detail and page-state parity: keep the advanced page shell visible during loading and render card-level placeholders or skeletons; wrap the advanced form in the existing native `SettingsBusyState` during save; decide deliberately whether the advanced error state should keep the card stack visible with Save disabled like reference or keep the current safer hidden-controls behavior, then update tests to encode that decision; add linked AVS/IP/supported-country copy; add the allowed-countries informational notice with blocked/screened language and decoded country names; replace the threshold controls with reference-equivalent labels, `Limits` grouping, currency-prefixed purchase amount fields, placeholders, `Leave blank for no limit` help, order-item min/step/key filtering, and inline threshold warning/error notices; optionally switch rule enablement from checkbox visual treatment to `ToggleControl` while preserving the native rule-specific radio-group accessible names.

This is merchant-facing and source-backed enough because it changes what merchants see while loading, saving, recovering from a fraud settings fetch error, understanding country/IP filters, and configuring min/max filters before Save. It is also coherent because it stays inside `fraud-protection/advanced/*`, the parent fraud section tests, and `fraud-protection-advanced.test.tsx`, with no backend/WPCOM dependency.

## Keep Out Of Scope

Do not reopen A4az backend ruleset refresh/defaulting in this slice; the current `HEAD` already includes the A4az commits and this pass did not find a new UI-source reason to touch backend ruleset lifecycle.

Do not change parent fraud section routing, Configure/Edit URL construction, or the stricter native error-state Configure disablement. Native tests already assert the provider route and safe disabled behavior, and the reference’s `wc-admin` route/link behavior is not directly portable to native provider settings.

Do not chase exact WooPayments extension layout/body/header CSS, `SettingsLayout`, `CardBody`, `h3` versus `h4`, or icon-perfect `InlineNotice` rendering unless needed to support the missing notices and threshold grouping. Those are visible but lower-value than missing guidance and controls, and the native provider settings surface has its own layout conventions.

Do not regress native accessibility improvements for filter-action radio groups or validation focus. Reference parity should be behavioral where it helps merchants, not a reason to remove better screen-reader context.

Do not treat card impression tracking, advanced settings saved tracking, welcome tour dismissal/tracking, or the parent fraud error sentinel as open residuals. Current native source and tests cover those A4ay/A4az outcomes.

Defer the advanced dirty-state deep-compare/navigation-warning parity unless the next slice explicitly owns broader unsaved-change behavior. It is source-backed, but it touches route/navigation semantics rather than the card-detail/loading residual.
