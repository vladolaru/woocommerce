---
session: 2026-06-15-core-native-payments
type: analysis
by: codex
created: 2026-06-17 12:12
reconciles:
  - implementation-log.md
  - staging-log.md
status: draft
last_updated: 2026-06-17 15:41
---

# B3ai WooPay Frontend Assets Analysis

> **Prompt:** "Continue"

> **Prompt:** "Make sure you also take into account frontend code and assets that were used on removed deprecated surfaces and are no longer used anywhere else"

> **Prompt:** "Make sure the WooPayments specific frontend assets are build through the same workflows as the rest of WooCommerce codebase are, since these are now core owned surfaces/UI. They should just be handled naturally,m like everything else."

> **Prompt:** "When investigating platform side logic, use the local WPCOM repo clone at ~/Work/a8c/wpcom (read only) that is mounted in the local WPCOM env both stores are using."

> **Prompt:** "Do the WooPay buttons get the proper styling that they do on the reference store?"

> **Prompt:** "Having a button that is not properly styled is unacceptable regression."

> **Prompt:** "When we are implementing frontend work, CSS styling needs to be factored in and properly handled, on equal footing - the merge into core can't be only about functionality."

> **Prompt:** "Make sure you keep things bundled separately so the core maintains its frontend performance and adaptability to actually enabled features (e.g. WooPay may be disabled by the merchant)"

> **Prompt:** "Since we are talking about styling, I think the current card checkout implementation is suffering from the same gap (and maybe the rest of the checkout payment methods that WooPayments provides access to - if those are in a future slice, ignore for now). Here is how the reference store is displaying the WooPayments card payment method: [Image #1]"

> **Prompt:** "The test card number rendering is also mismatched on the card element"

> **Prompt:** "Card rendering should not be tied to WooPay. They are at the same level of abstraction, right?"

## Starting Point

B3ah closed native WooPay runtime session continuity but explicitly left a frontend parity gap: the reference WooPayments checkout loads WooPay express/save-my-info frontend globals and surfaces such as `wcpayConfig`, `wcpayAssets`, `wcpayExpressCheckoutParams`, a WooPay express iframe/button, and save-my-info phone UI, while the target Core-native checkout currently exposes only the card method/server config. This B3ai analysis will compare reference WooPayments frontend registration, Core-native frontend registration, and normal WooCommerce build workflows before writing the JIT implementation plan.

## Source Comparison

Core currently has two first-class native payment assets: `plugins/woocommerce/client/legacy/js/frontend/woopayments-checkout.js` registered as `wc-woopayments-checkout` through `WC_Frontend_Scripts`, and `plugins/woocommerce/client/blocks/assets/js/extensions/payment-methods/woopayments/index.js` registered as `wc-payment-method-woopayments` through `Automattic\WooCommerce\Blocks\Payments\Integrations\WooPayments`. These assets cover the card Payment Element, hidden payment method fields, setup-intent add-payment-method flow, redirect confirmation, card icons, and test-mode copy, but they do not render WooPay express checkout or WooPay save-my-info UI.

The standalone WooPayments reference splits the frontend across build outputs `checkout`, `blocks-checkout`, `woopay`, `woopay-express-button`, and `express-checkout`. The server registrations that matter for this chunk are `WC_Payments_Checkout::get_payment_fields_js_config()`, `WC_Payments_Blocks_Payment_Method::get_payment_method_script_handles()`, `WC_Payments_WooPay_Button_Handler`, `WC_Payments_Express_Checkout_Button_Display_Handler`, `WC_Payments_Express_Checkout_Button_Handler`, `WC_Payments::enqueue_assets_script()`, `WC_Payments::enqueue_woopay_common_config_script()`, and `WooPay_Save_User`.

The key preserved frontend globals are `wcpay_upe_config`/Blocks payment method data for payment-field config, `wcpayConfig` for WooPay shared config and express button initialization, `wcpayAssets.url` for chunk/public-path resolution in WooPayments bundles, `wcpayExpressCheckoutParams` for ECE/Stripe express checkout paths, and `woopayCheckout.PRE_CHECK_SAVE_MY_INFO` for the save-my-info default. Native Core has `wcpay_core_checkout_config` and Blocks payment data, but not the broader WooPay globals.

The correct Core build workflow is not WooPayments `dist/`. Classic checkout code belongs under `plugins/woocommerce/client/legacy/js/frontend/` and is built by `@woocommerce/classic-assets` via `pnpm --filter='@woocommerce/classic-assets' build`. Blocks payment-method code belongs under `plugins/woocommerce/client/blocks/assets/js/extensions/payment-methods/` and is built by the Blocks webpack entry map in `plugins/woocommerce/client/blocks/bin/webpack-entries.js` into `plugins/woocommerce/assets/client/blocks/`. Any WooPayments-specific frontend code moved into Core should be wired through those existing entry maps and asset APIs so watch/build workflows pick it up naturally.

## Implications

B3ai should be a larger coherent frontend/runtime package: expose the missing WooPay config keys from `WooPaymentsCheckoutBridge`, register/enqueue Core-owned WooPay shared/express/save-user assets on the same shopper surfaces as the reference, render the WooPay express placeholder on checkout/cart/product surfaces where the reference would, wire Blocks WooPay express/save-user behavior into the existing Core Blocks payment-method entry or adjacent Core Blocks entry, and add focused tests that fail when `wcpayConfig`, `wcpayAssets`, `wcpayExpressCheckoutParams`, `woopayCheckout`, the express placeholder, or save-user metadata are absent. B3ai should not try to port all Apple Pay/Google Pay/Amazon Pay ECE behavior unless evidence shows it is required for WooPay parity in this package; however, the config shape and `wcpayExpressCheckoutParams` contract need to be accounted for because the reference WooPay express stack shares that data.

## WooPay Button Styling Regression

Browser comparison on 2026-06-17 confirmed the native target button was not styled like the reference store. Target `http://store8889.localhost:8889/checkout/` rendered `.woopay-express-button` as a plain browser-default `<button>` with text `WooPay`, no SVG logo, width `66.1406px`, `display: inline-block`, grey `rgb(239, 239, 239)` background, `2px outset` border, Arial `13.3333px`, and no `.button-content` wrapper. Screenshot evidence is in `data/target-woopay-button-before-styling-fix.png`.

Reference `http://localhost:8082/checkout/` rendered the WooPay button as a full-width branded button: width `290.117px`, height `48px`, `display: flex`, purple `rgb(135, 62, 255)` background, white text/logo color, no browser border, `border-radius: 4px`, system font at `18px`/`500`, `letter-spacing: 0.8px`, a `.button-content` wrapper, and inline WooPay SVG. Screenshot evidence is in `data/reference-woopay-button-before-styling-fix.png`.

Root cause: native Core currently hydrates the server placeholder with an ad hoc local text button, while the standalone WooPayments reference enqueues a dedicated `woopay-express-button` React bundle and `woopay.css` from the WooPayments build. The native merge must not rely on WooPayments `dist/`, but it also cannot treat CSS as optional. The fix should move the branded button markup and styling contract into Core-owned assets, preserve the existing click/session behavior, and keep WooPay-specific CSS bundled separately behind the native WooPayments payment-method/frontend path rather than adding it to global checkout CSS. This matters for performance and adaptability because stores with WooPay disabled should not load WooPay-specific CSS.

## Card Checkout Styling And Save-User Regression

The first target browser tab was a zero-due subscription checkout and therefore showed no payment methods; that was the wrong cart state for card parity. The paid target checkout tab, after a fresh reload, loaded `wc-payment-method-woopayments.js`, `wc-payment-method-woopayments-woopay.js`, `wc-payment-method-woopayments-woopay.css`, `woopayments-woopay.js`, and `woopayments-woopay.css`. The WooPay button was no longer browser-default after reload: it rendered full-width, purple, flex-based, SVG-backed markup. Screenshot evidence is in `data/target-card-payment-method-b3ai-after-reload.png`.

The live reference Blocks checkout at `http://localhost:8082/checkout/` still showed two card-surface gaps versus target. First, the reference card label row renders `Card` and the `Test Mode` badge on the left with card brand logos aligned to the right; target renders the badge/icons before the `Card` label, causing the row to read visually as `Test Mode ... +2 Card`. Second, reference renders a `Save my info` heading and `Securely save my information for 1-click checkout` checkbox below the card payment method; target does not. Screenshot evidence is in `data/reference-card-payment-method-b3ai-live.png`.

Root cause in source: B3ai moved WooPay save-user rendering entirely into the new WooPay express bundle and kept Core’s card payment method label/icon rendering inline. The standalone WooPayments reference has dedicated Blocks components/styles for the payment method label/logo row (`client/checkout/blocks/payment-method-label.js`, `client/checkout/blocks/payment-methods-logos/*`) and also keeps the WooPay save-user opt-in available on the card checkout path when account/settings allow it. The next fix needs to restore those card-owned frontend pieces without merging WooPay express styling into the base card bundle or loading WooPay express assets when WooPay is unavailable.

## Card Element Rendering Mismatch

After the card label/save-user patch, the target Blocks checkout still does not match the reference card element. Browser probes at the same 1136px viewport showed both stores give the Stripe payment element roughly the same outer content width (`594.49px`), but the reference iframe is `127.91px` tall and renders the card number as a full-width first row with expiry and security code below, while the target iframe is only `73.51px` tall and renders card number, expiry, and security code in a single row. This rules out the immediate outer container width as the primary cause and points to a Stripe Payment Element initialization/configuration difference.

The reference WooPayments Blocks flow creates Stripe Elements through `@stripe/react-stripe-js` with `appearance` from `getAppearance( 'blocks_checkout' )`, `fonts` from `getFontRulesFromPage()`, `loader: 'never'`, and the reference `getStripeElementOptions()` also adds terms handling and Link wallet behavior. Native Core currently creates Elements directly with only mode/currency/manual creation/card method type/amount, then mounts a Payment Element with hidden billing fields and all wallets disabled. The next debugging step is to isolate which option difference controls the card element row rendering before changing production code.

The first attempted ownership model would have reused `WooPaymentsWooPaySessionService` for the card style cache version because that class already stores `wcpay_styles_cache_version`. That is the wrong abstraction: card and WooPay are sibling checkout surfaces, not parent/child. The corrected model introduces a neutral native WooPayments frontend styles helper for cache-version ownership, with the card bundle owning card Payment Element rendering/options and WooPay remaining only a WooPay-specific consumer.

Runtime probes on the reference and target stores showed that cached appearance/fonts alone do not reproduce the live reference card iframe height in a manual Stripe mount. The option that changed the manual target from the compressed one-row card element toward the reference multi-row card element was the reference `terms` path: `terms: { card: 'always' }`, which WooPayments passes when `shouldSavePayment` is true or the cart contains a subscription. Native Core was not passing reusable-method terms at all, and it was not exposing `cartContainsSubscription` or a neutral styles cache version to the Blocks card bundle. The implemented fix now adds neutral style cache data, cached Blocks checkout appearance/font rules, `loader: 'never'`, and reference-compatible reusable-method terms derived from card state rather than WooPay state.

## Final Card And Button Settlement

The user's final screenshot was intentionally partial and only showed the field, so the accepted signal is not a pixel-level conclusion from that crop. The useful conclusion is narrower: card rendering must remain owned by the card payment method and initialized through the same WooPayments appearance/fonts/options pipeline as the reference, while WooPay express remains a separate sibling bundle and style surface.

The remaining confirmed WooPay button mismatch was not the branded markup anymore; it was button settings drift. Reference WooPayments derives button height from `payment_request_button_size` and reads `payment_request_button_border_radius`. Native Core was reading stale `payment_request_button_height` and a wrong radius key, which produced a 44px target button while the reference rendered 48px for `medium`. The regression test now pins the extension behavior, and production now maps `small/default` to `40`, `medium` to `48`, `large` to `55`, and preserves an empty border-radius option so the default 4px rendering remains controlled by the button implementation.

Post-fix browser evidence at `data/target-checkout-button-style-after-fix-b3ai.json` shows the target native checkout passing `height: "48"`, `radius: ""`, `size: "medium"`, `forceNetworkSavedCards: true`, and `paymentMethodsConfig.card.forceNetworkSavedCards: true`. The rendered WooPay button is 48px high with default/computed 4px radius, and the card PaymentElement iframe is platform scoped with no `stripeAccount` in its URL. This closes the concrete B3ai styling/config drift while leaving non-card express payment methods outside this slice unless future evidence makes them part of the same acceptance surface.

## Review Follow-Up And Console Settlement

The reviewer found three real follow-up gaps: WooPay enablement still needed to require cached account `platform_checkout_eligible`, product-page WooPay needed to add the selected product to cart before initialization, and classic save-user assets needed to load even when the express button itself was hidden. Those were fixed with RED/GREEN PHP and JS regressions rather than hidden in the harness.

The final browser gate then exposed a Stripe appearance warning caused by modern computed CSS color syntax (`color(srgb ... / alpha)`) in the appearance payload. The fix normalizes modern `color(srgb ...)` and space/slash `rgb(...)` values recursively, including cached `localStorage` appearance, before passing appearance to Stripe Elements. Final target checkout evidence shows the paid cart, WooPay express button, Card Test Mode badge/instructions, PaymentElement, Save my info, no unavailable-methods message, split asset loading, and no Stripe appearance warnings. The remaining console warnings match reference/global checkout behavior rather than a target-only B3ai regression.

The final product-page WooPay follow-up was not a styling issue: it was request-shape parity for variable products. The browser-side sequence already posted add-to-cart before WooPay initialization, but the server handler initially ignored top-level `attribute_*` form fields, so selected variation attributes could be lost before `WC()->cart->add_to_cart()`. The fix preserves those request fields explicitly and covers them with a RED/GREEN PHP regression. This keeps the WooPay product button behavior aligned with the selected storefront form state without changing the harness to hide the bug.

Final focused review approved the follow-up with no remaining important findings. The accepted B3ai behavior is now that card rendering, WooPay express rendering, WooPay save-user UI, and product-page WooPay add-to-cart are separate surfaces with shared account/runtime state but separate asset ownership and load conditions.
