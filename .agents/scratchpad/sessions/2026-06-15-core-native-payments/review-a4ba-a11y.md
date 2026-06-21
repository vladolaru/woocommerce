---
session: 2026-06-15-core-native-payments
type: review
by: codex
created: 2026-06-21 00:45
tool: pirategoat-tools:a11y-reviewer
target: A4ba advanced fraud frontend accessibility
reconciles:
  - analysis-a4ba-advanced-fraud-ui-parity.md
  - review-a4ba-advanced-fraud-ui-source-check.md
status: final
last_updated: 2026-06-21 00:50
---

# A4ba Advanced Fraud Accessibility Review

> **Prompt:** "Review the current uncommitted A4ba frontend diff in /Users/vladolaru/Work/a8c/woocommerce-develop-2 for accessibility regressions only. Scope: plugins/woocommerce/client/admin/client/woopayments/settings/fraud-protection/advanced/index.tsx, style.scss, and the focused Jest test file. The implementation restores native WooPayments Advanced Fraud settings UI parity: loading skeleton/status, aria-busy while saving, linked notices, allowed-countries notice, purchase/order threshold controls and inline notices. Constraints: read-only review, do not edit product files, do not access WPCOM/sandbox, no commits/pushes. Verify against source and tests; focus on keyboard, labels, aria-describedby/live regions, link accessible names, focus rings, and mobile/responsive accessibility. Write findings to .agents/scratchpad/sessions/2026-06-15-core-native-payments/review-a4ba-a11y.md with scratchpad frontmatter, then summarize critical/high/medium findings in your final response. If none, say so and mention residual low risks."

## Status

Read-only accessibility review complete. No product files were edited, no WPCOM sandbox was accessed, and no commits or pushes were made. I inspected the uncommitted diff, current source, focused Jest coverage, and relevant upstream/local component behavior for `@wordpress/components` `Notice`/`@wordpress/a11y` and country-name decoding patterns.

- `plugins/woocommerce/client/admin/client/woopayments/settings/fraud-protection/advanced/index.tsx`
- `plugins/woocommerce/client/admin/client/woopayments/settings/fraud-protection/advanced/style.scss`
- `plugins/woocommerce/client/admin/client/woopayments/settings/test/fraud-protection-advanced.test.tsx`

I did not run Jest. The review is source/test inspection only.

## Findings

### Medium: Custom amount inputs lose a robust focus indicator in forced-colors

Location: `plugins/woocommerce/client/admin/client/woopayments/settings/fraud-protection/advanced/style.scss:139`

Problem: The new purchase-price amount input removes the actual input focus styles with `outline: none` and `box-shadow: none`, then relies on the wrapper `:focus-within` `box-shadow` for the visible focus ring. That wrapper ring is visible in the default color scheme, but box-shadow is not reliable in Windows high contrast/forced-colors mode. Keyboard users with forced colors enabled can tab into the minimum/maximum purchase price inputs without a dependable focus indicator.

WCAG: 2.4.7 Focus Visible and 1.4.11 Non-text Contrast.

Anti-pattern: AP-11, box-shadow-only focus styling without an outline fallback. It is also adjacent to AP-17 because the input outline is explicitly removed.

Fix: Keep the wrapper focus treatment but add an outline fallback, for example `outline: 2px solid #3858e9; outline-offset: -1px;` on `:focus-within`, plus a `@media (forced-colors: active)` rule using `outline-color: Highlight`. Alternatively, avoid removing the input outline and style the wrapper in addition to the native input focus.

Effort: 0.5 hours.

Confidence: 92.

### Medium: The visible currency prefix is hidden from assistive technology

Location: `plugins/woocommerce/client/admin/client/woopayments/settings/fraud-protection/advanced/index.tsx:356`

Problem: The new purchase-price fields show a currency prefix visually, but the prefix is rendered inside `aria-hidden="true"` and no equivalent currency cue is present in the label or `aria-describedby` text. A screen reader user hears "Minimum purchase price" or "Maximum purchase price" with "Leave blank for no limit", but not the visible `$`, `€`, `GBP`, or other store-currency cue. The currency prefix is meaningful input instruction, not decoration.

WCAG: 3.3.2 Labels or Instructions.

Anti-pattern: Meaningful visual text is hidden from the accessibility tree.

Fix: Add an accessible equivalent for the currency, ideally using the currency code to avoid ambiguous symbols. For example, give the input `aria-describedby={`${ helpId } ${ currencyHelpId }`}` and render a `screen-reader-text` node like `Currency: USD`, or include the currency in the field label/helper text. Keep the visible symbol `aria-hidden` only if the accessible equivalent is present.

Effort: 0.5 hours.

Confidence: 88.

### Medium: Allowed-country names are not decoded before announcement/display

Location: `plugins/woocommerce/client/admin/client/woopayments/settings/fraud-protection/advanced/index.tsx:147`

Problem: The new allowed-countries notice reads names directly from `window.wcSettings.countries` and joins them into the notice. Other WooCommerce admin code decodes these country names before display, and the WooPayments reference `AllowedCountriesNotice` also wraps the joined names in `decodeEntities`. If a country name contains an entity, the notice will render and announce the raw entity text instead of the country name, for example a screen reader can announce the entity spelling rather than the intended accented character.

WCAG: no direct criterion; this is a screen-reader/readability regression in user-facing instructional content.

Anti-pattern: Entity-encoded user-facing text is inserted into accessible content without decoding.

Fix: Import `decodeEntities` from `@wordpress/html-entities` and apply it when resolving or joining country names. Add a focused test with an encoded country name in `window.wcSettings.countries` and assert the decoded country text is rendered.

Effort: 0.5 hours.

Confidence: 84.

## Positive Checks

The loading state keeps a polite `role="status"` message and hides skeleton cards with `aria-hidden="true"` while avoiding focusable skeleton content. The saving state adds `aria-busy="true"` to the page surface and disables the busy Save button. The new linked notice copy uses real anchors with accessible text from `createInterpolateElement`, and the focused tests assert the link names for `selling locations`, `supported countries`, and the IP-address explainer. The threshold controls have explicit labels and helper text, and the order-item controls keep native `TextControl` labeling. The SCSS stacks threshold columns on narrow viewports.
