---
session: 2026-06-15-core-native-payments
type: review
by: subagent:a11y-reviewer
created: 2026-06-20 07:17
tool: pirategoat-tools:a11y-reviewer
target: A4aj express checkout settings preview
status: draft
---

# A4aj Accessibility Review

## Scope

Read-only review of the current uncommitted A4aj express checkout preview slice on `exp/core-native-payments`, focused on `plugins/woocommerce/client/admin/client/woopayments/settings/express-checkout/express-checkout-preview.tsx`, `style.scss`, `components.tsx`, and `plugins/woocommerce/client/admin/client/woopayments/settings/test/express-checkout-settings.test.tsx`. I also read the mount point in `appearance-settings.tsx`, the local screenshots in `data/a4aj-express-preview-target*.png`, WordPress `Notice` implementation in local `node_modules`, and the reference WooPayments preview/button implementation under `/Users/vladolaru/Work/a8c/woocommerce-payments` for parity context.

## Findings

### Medium: WooPay preview button hides the visible CTA from assistive technology

Location: `plugins/woocommerce/client/admin/client/woopayments/settings/express-checkout/express-checkout-preview.tsx:200`

The WooPay preview is rendered as a semantic `<button>` with visible text derived from the selected call-to-action (`WooPay`, `Buy with WooPay`, `Donate with WooPay`, or `Book with WooPay`), but the component overrides the button's accessible name with the fixed `aria-label="WooPay express checkout preview"` at lines 200-203. For the `buy`, `donate`, and `book` variants, screen reader users and speech-input users do not get the same label that sighted users see in the preview, and the accessible name no longer contains the visible label. Because the element is exposed as a button, this falls under WCAG 2.5.3 Label in Name even though it is removed from the tab order with `tabIndex={ -1 }`.

Impact: A merchant using a screen reader cannot confirm from the preview whether the selected CTA renders as “Buy with WooPay”, “Donate with WooPay”, or “Book with WooPay”; they only hear the generic preview name. Speech-input users also cannot target the visible label if this preview is exposed in their command model. The current test at `plugins/woocommerce/client/admin/client/woopayments/settings/test/express-checkout-settings.test.tsx:492` locks in the generic accessible name by querying for `name: /WooPay express checkout preview/i`.

Reference context: the WooPayments extension preview ultimately uses `WoopayExpressCheckoutButton`, whose accessible label is the rendered button text unless a saved card is shown (`/Users/vladolaru/Work/a8c/woocommerce-payments/client/checkout/woopay/express-button/woopay-express-checkout-button.js:436-447`). The native preview diverges by replacing that label with generic preview context.

Fix: Decide explicitly between a disabled button preview and a non-interactive visual. If this should remain a disabled button preview, prefer a native disabled button and keep the visible CTA as the accessible name, adding preview context through `aria-describedby` or adjacent text rather than overriding the label. If this should be purely visual, render a non-interactive preview container such as `role="img"` with an accessible name like “WooPay button preview: Buy with WooPay”. In either case, update the focused tests to cover the chosen behavior across at least one non-default CTA, including that the preview is not in the sequential tab order and that the accessible name includes the visible CTA text.

Confidence: 0.88. Effort: 0.5-1 hour.

## Positive Notes

The preview is intentionally kept out of the sequential keyboard order with `tabIndex={ -1 }`, so it does not add a dead stop for keyboard-only users on the HTTP/local preview path shown in the screenshots.

`ExpressCheckoutInlineNotice` uses `@wordpress/components` `Notice`, and the local implementation calls `speak()` with polite defaults for `info` and assertive defaults for `error`. The fallback and failure notices are non-dismissible, so they do not add extra focus targets or require focus-return handling.

The supplied desktop and mobile screenshots show the fallback notice wrapping inside the preview frame without obvious text overflow or overlap. The dark WooPay preview has strong text contrast; the light and outline variants also use black text on white, with the outline variant using a black inset border.

## Residual Risks

I did not run the focused Jest suite in this read-only review. The source review shows coverage for the HTTP fallback, Stripe initialization behavior, script load retry, and no-wallet failure notice, but the tests do not currently assert the preview's disabled/non-interactive semantics, tab-order exclusion, or CTA-specific accessible name. Those should be added with the fix above.

The live Stripe Apple Pay / Google Pay preview is mounted into an empty container and relies on Stripe's Express Checkout Element for the iframe/button accessibility semantics. I did not flag that as a finding because the code uses Stripe's official element and shows a WordPress notice when the preview cannot be rendered, but runtime browser verification on a real HTTPS/browser/device combination remains the best way to confirm the iframe output.
