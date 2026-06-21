---
session: 2026-06-15-core-native-payments
type: analysis
by: codex
created: 2026-06-20 09:23
reconciles:
  - analysis-b3ai-woopay-frontend-assets.md
  - analysis-a4al-payment-detail-residual-parity.md
  - supervisor-prompt-2026-06-18-2344-N12.md
last_updated: 2026-06-20 10:03
status: final
---

# A4am Classic Card Checkout Parity

## Source-Backed Findings

The A4al checkout caveat was too broad. B3ai already closed the major native card/WooPay split-bundle and Blocks Payment Element styling pipeline gaps, including normal WooCommerce build workflows, `loader: 'never'`, appearance/font handling for Blocks, and separation of WooPay express assets. The remaining source-backed gap is narrower and belongs to the classic card checkout surface.

Native classic card checkout initializes Stripe Elements with only `mode`, `currency`, `paymentMethodCreation`, `paymentMethodTypes`, and `amount` when payment mode is active. It does not pass the card appearance pipeline, font rules, or `loader: 'never'` into `stripe.elements()`. This is in `plugins/woocommerce/client/legacy/js/frontend/woopayments-checkout.js`.

The reference classic WooPayments client computes DOM-derived appearance for `classicCheckout`, uses the shared font-rule extraction, and includes those options when creating Elements. The concrete references are `/Users/vladolaru/Work/a8c/woocommerce-payments/client/checkout/classic/payment-processing.js` and `/Users/vladolaru/Work/a8c/woocommerce-payments/client/checkout/upe-styles/index.js`.

Core already has a copied, native-safe appearance implementation in `plugins/woocommerce/client/blocks/assets/js/extensions/payment-methods/woopayments/upe-styles.js`, but it only knows `blocksCheckout` selectors and exposes `getBlocksCheckoutAppearance()`. Reusing that module for classic checkout is the right scope because it keeps card styling at the card abstraction level and does not tie card rendering to WooPay.

Native classic logos are rendered statically by PHP as the first three brands plus a `+ N` badge. The reference classic client replaces the placeholder logo with a responsive logo container, keyboard-openable hidden-brand popover, and viewport-specific visible-count handling. Core Blocks already has an equivalent popover component and CSS, so classic should get the same behavior in the legacy bundle instead of relying only on PHP output.

FR Cartes Bancaires is missing from the native card brand data. The reference `getCardBrands()` adds `cartes_bancaires` when `storeCountry === 'FR'`. Native `WooPaymentsCheckoutBridge` and `NativeWooPaymentsGateway` both currently list only Visa, Mastercard, American Express, Discover, JCB, and Union Pay.

The merge harness still has no deterministic checkout visual parity gate. That is a verification hardening follow-up for `tools/woopayments-merge`, ideally a narrow Playwriter gate comparing only the WooPayments classic card row and style facts across target/reference. It should not block this implementation slice if we add focused unit/PHP tests plus manual Playwriter proof.

## Implementation Boundary

This slice should modify only card-owned checkout pieces: `woopayments-checkout.js`, `woopayments-checkout.scss`, the shared `upe-styles.js` helper, checkout bridge/gateway card brand data, and focused tests. It should not move WooPay save-user logic, WooPay express button code, Apple/Google Pay express code, or the admin settings route work.

The preferred implementation was to add a `getClassicCheckoutAppearance()` export to the shared style helper using reference-compatible selectors and the existing cache/version flow, then consume it from classic card checkout. Implementation intentionally deviated after reading the legacy asset build: `woopayments-checkout.js` is a non-module legacy bundle, so the classic card surface now owns the same appearance/font/cache behavior inline rather than importing the Blocks helper. This keeps the behavior card-owned and avoids coupling classic checkout to WooPay or the Blocks build graph. For brand logos, the implementation renders from `config.paymentMethodsConfig.card.cardBrandIcons` when available so classic PHP, classic JS, and Blocks consume the same native card brand data.

## Closeout Result

A4am is implemented and committed locally as source/tests `fb2a0b2e88` plus changelog `d101c1d251`, with git range `995c3e332e...d101c1d251`. The slice restores native classic card checkout parity for the covered card-owned gaps: classic Stripe Elements now receives `loader: 'never'`, cached DOM-derived appearance, allowed font CSS rules, and manual card Payment Element options; classic card logos hydrate into the same visible logo plus overflow dialog pattern, including keyboard activation, focus restoration, hidden-brand description text, and checkout-fragment resize cleanup; the classic card CSS overrides legacy floated label images and styles the popover inside the classic checkout bundle; and FR Cartes Bancaires card-brand data is exposed through both the checkout bridge and gateway icon output with a tracked Core-owned asset.

Verification is green for this slice. RED/GREEN coverage was added for the Elements options, card logo popover/accessibility behavior, resize cleanup across `updated_checkout`, FR checkout bridge config, and FR gateway icon count. Focused Jest passed with 15 tests; focused PHPUnit passed for `NativeWooPaymentsGatewayTest` with 31 tests / 124 assertions and `WooPaymentsCheckoutBridgeTest` with 8 tests / 104 assertions; exact-file ESLint, targeted SCSS Stylelint, changed-file PHPCS, production PHPStan, classic asset build, changelog validation, `git diff --check -- . ':!.agents'`, and branch lint passed. Existing non-blocking noise remained: Composer/PHP 8.4 vendor deprecations, Changelogger `E_STRICT` deprecations, classic build autoprefixer/admin CSS warnings, and branch-wide ignored-file JS warnings.

Playwriter session `83` verified the target classic checkout card row at `http://store8889.localhost:8889/codex-classic-checkout/?add-to-cart=37`: visible brands are Visa, Mastercard, American Express, Discover plus `+ 2`; the overflow opens a focused `role="dialog"` with `JCB, Union Pay`; Escape closes it and returns focus to the trigger when no screenshot operation intervenes; browser console had only expected Stripe local HTTP/domain wallet warnings and the sandbox amount log. Screenshot evidence is `a4am-classic-payment-section-after-a11y-fix.png`. Target `debug.log` stayed empty, and non-empty target WooCommerce logs were only background WooCommerce Subscriptions queue DEBUG entries.
