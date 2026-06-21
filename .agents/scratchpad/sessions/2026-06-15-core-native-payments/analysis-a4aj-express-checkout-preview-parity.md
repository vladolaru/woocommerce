---
session: 2026-06-15-core-native-payments
type: analysis
by: codex
created: 2026-06-20 06:57
last_updated: 2026-06-20 07:30
reconciles:
  - analysis-a4l-express-checkout-settings-parity.md
  - analysis-a4af-settings-woopay-residual-parity.md
  - analysis-a4ag-next-parity-slice.md
  - analysis-a4ai-refund-modal-parity.md
  - supervisor-prompt-2026-06-18-2344-N12.md
status: final
---

# A4aj Express Checkout Preview Parity

> **Prompt:** "ok. continue"

## Starting Point

A4ai is committed and the product tree was clean at the start of this slice. Native admin readiness remains fail-closed under N12. Earlier A4 work already added the express checkout settings sub-routes, method detail pages, shared appearance controls, WooPay checkout preview, and express overview copy/legal/status parity, so the current source-backed residual is not "missing express checkout settings pages"; it is the static preview inside those pages.

## Reference Contract

The reference live preview starts in `/Users/vladolaru/Work/a8c/woocommerce-payments/client/settings/express-checkout-settings/general-payment-request-button-settings.js`. `GeneralPaymentRequestButtonSettings` reads `getExpressCheckoutConfig( 'stripe' )`, calls `loadStripe( publishableKey, { stripeAccount, locale } )`, and wraps `PaymentRequestButtonPreview` in Stripe `Elements`.

`/Users/vladolaru/Work/a8c/woocommerce-payments/client/settings/express-checkout-settings/payment-request-button-preview.js` renders one preview surface. If neither WooPay nor Apple Pay / Google Pay is enabled, it shows an info notice telling merchants to activate at least one express checkout. If WooPay is enabled, it renders a WooPay button preview. If Apple Pay / Google Pay is enabled and the current page is HTTPS, it renders the live Stripe Express Checkout preview; otherwise it renders the HTTPS/browser/device requirements notice.

`/Users/vladolaru/Work/a8c/woocommerce-payments/client/express-checkout/block-buttons/components/express-checkout-preview.js` is the live Apple Pay / Google Pay contract. It creates Elements with `mode: 'payment'`, `amount: 1000`, `currency: 'usd'`, `appearance.variables.borderRadius`, and `spacingUnit: '6px'`. It creates the `ExpressCheckoutElement` with height clamped to 40-55, theme mapping `dark -> black`, `light -> white`, and `light-outline -> white` for Google Pay or `white-outline` for Apple Pay, button type `default -> plain`, Apple Pay / Google Pay forced `always`, Amazon Pay / Link / PayPal / Klarna forced `never`, and `layout.overflow: 'never'`. If `onReady` reports no available payment methods, it shows a "Failed to preview" error notice.

The reference checkout Elements path also uses the full appearance/fonts pipeline and `loader: 'never'` in checkout Blocks. Native checkout Blocks already follow that `loader: 'never'` convention for Stripe Elements, so the settings preview should not regress into a loader-bearing Elements setup.

## Native Current State

Native `plugins/woocommerce/client/admin/client/woopayments/settings/express-checkout/appearance-settings.tsx` renders the same appearance controls and a Preview field, but the `ExpressCheckoutPreview` imported from `notices.tsx` always renders `ExpressCheckoutPreviewFallback`. That fallback is the requirements notice from `components.tsx`; there is no live Stripe preview path and no disabled-express-checkout state.

Native does not currently import `@stripe/react-stripe-js`, `@stripe/stripe-js`, `ExpressCheckoutElement`, or `loadStripe` in the admin settings package. Native checkout and express checkout frontend code use `window.Stripe` directly, and `WooPaymentsExpressCheckoutService::get_express_checkout_params()` already exposes the public Stripe config shape as `publishableKey`, `accountId`, and `locale`.

Native `WooPaymentsSettingsService::get_settings()` exposes button type, size, theme, border radius, WooPay enablement, payment-request enablement, and WooPay appearance/fonts. It does not expose the public Stripe preview config needed by the settings page. The config is not secret: the same publishable key, connected account ID, and locale are already shipped to shopper-facing express checkout surfaces.

Native WooPay checkout preview is already implemented separately in `woopay-preview.tsx` and used by `woopay-settings.tsx`. That preview is for WooPay checkout appearance, not the shared express checkout button preview. Card rendering and WooPay rendering stay at separate peer-level abstractions; this slice should not tie card or checkout Elements to WooPay.

## Architecture Decision

Use a settings-local Stripe preview adapter built around `window.Stripe`, not new admin dependencies on Stripe React packages. This keeps WooPayments-specific Stripe preview logic scoped to the WooPayments settings subpage, follows the native checkout implementation style, and avoids increasing the broader admin bundle with packages that Core admin does not currently use.

Add a read-only `express_checkout_preview` settings payload containing `stripe.publishableKey`, `stripe.accountId`, and `stripe.locale`. The payload should be sourced from `WooPaymentsAccountService` just like the existing frontend express checkout service. The frontend should fail closed to the existing requirements notice if the config is incomplete, the page is not HTTPS, or Stripe.js is unavailable.

Render a lightweight settings-local WooPay button preview when WooPay is enabled. The reference uses the real WooPay express button component, but native does not currently have a shared admin-safe import for that frontend checkout component. A settings-local preview button is enough for this residual because the full WooPay checkout appearance preview already exists, while the shared express preview needs to show that enabled express buttons participate in the shared style controls.

Keep `loader: 'never'` in the settings Elements options. The reference settings preview does not set it explicitly, but native checkout parity and the earlier checkout-style gap require it for Core-owned Stripe Elements surfaces.

## Planned Gates

RED coverage starts with PHP `WooPaymentsSettingsServiceTest` for the new `express_checkout_preview.stripe` settings payload. Frontend RED coverage goes into `express-checkout-settings.test.tsx` for disabled express checkouts, HTTP fallback plus WooPay preview, HTTPS live Stripe initialization/options, and no available wallets error handling.

Browser proof should load the native Apple Pay / Google Pay settings page on the target store, verify the deterministic HTTP fallback and absence of console/PHP notices, then use the same page to confirm the Preview section and button controls still render. A true wallet-available live Apple Pay / Google Pay render may not be deterministic in the local HTTP environment; the honest gate is source/Jest proof for the Stripe options plus browser proof for merchant-visible fallback behavior and no runtime errors.

Sagan's payment-detail explorer also returned during this slice. It found source-backed residuals in payment-detail timeline detail, payment-method details, dispute detail richness, summary financial layout, customer/order links, and `card_reader_fee` parity. That report is recorded here as the next A4 detail residual candidate; it is not part of A4aj because A4aj is the already-scoped settings preview gap.

## Closeout

A4aj restored the native express checkout settings preview parity for the Apple Pay / Google Pay settings route. The settings payload now exposes public Stripe preview config under `express_checkout_preview.stripe`, and the settings-local React preview renders the disabled-express notice, the WooPay preview, and a live Stripe Express Checkout Element on HTTPS when config is complete. The preview uses `loader: 'never'`, keeps the Stripe/WooPayments-specific logic inside the settings chunk, and falls back deterministically on HTTP or missing Stripe support.

The review loop found two real implementation risks and both were fixed before commit: a failed Stripe.js script element could previously leave a remount waiting forever, and the WooPay preview button's accessible name overrode the visible CTA. Final evidence and hashes are recorded in `staging-log.md`, `implementation-log.md`, `spec-conformance-baseline.md`, and the plan closeout.
