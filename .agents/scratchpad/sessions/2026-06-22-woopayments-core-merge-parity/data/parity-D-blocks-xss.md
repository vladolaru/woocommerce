---
session: 2026-06-22-woopayments-core-merge-parity
type: analysis
by: subagent:parity-D
created: 2026-06-22 22:55
tool: pirategoat-tools:full-code-review (follow-up parity check)
target: blocks checkout XSS — testingInstructions via dangerouslySetInnerHTML
reconciles:
  - ../README.md
status: final
---

# Parity D — Blocks checkout XSS (`195a39ae`)

## Finding under review

**195a39ae (CRITICAL)** — CORE `plugins/woocommerce/client/blocks/assets/js/extensions/payment-methods/woopayments/index.js:903-910` renders `testingInstructions` via `dangerouslySetInnerHTML` with no client-side sanitization. The value reaches JS through the `wcpay_payment_fields_js_config` WP filter, so a third-party plugin can overwrite it with `<script>`/`<img onerror>` on the checkout page (test mode only). The review noted the classic checkout path escapes via `wp_kses` while the blocks path does not.

## Verdict: PARITY (high confidence)

The WooPayments client (v10.8.0 / `develop`) exhibits the **identical** construct in its blocks checkout path: `dangerouslySetInnerHTML` on `testingInstructions`, no client-side sanitization, and the same server-side ordering where the per-method config (including `testingInstructions`) is exposed through the `wcpay_payment_fields_js_config` filter. This is a faithful port, not a regression introduced by the core merge. The classic-vs-blocks asymmetry the review described is real and present in both repos — it is a pre-existing property of WooPayments, not new in core.

This is therefore a **"do better than the client"** opportunity, not a must-fix regression. Core does not need to ship it to maintain parity, but the merge goal explicitly wants to beat the client on quality, and this is a CRITICAL-severity construct worth hardening (e.g. a final `wp_kses_post` on the value before it enters the config array, or client-side sanitization in the blocks render).

## Client evidence — JS (renders via `dangerouslySetInnerHTML`, no sanitization)

`client/checkout/blocks/payment-processor.js:255-266`:

```js
return (
    <SkeletonContext.Provider value={ CoreSkeleton }>
        { isTestMode && (
            <p
                className={ clsx( 'content', { [ `theme--${ theme }` ]: theme } ) }
                dangerouslySetInnerHTML={ {
                    __html: testingInstructions,   // line 263 — no sanitizeHTML / DOMPurify / escape
                } }
            />
        ) }
```

The prop is threaded straight from the server config, untouched: `client/checkout/blocks/index.js:74` passes `upeConfig.testingInstructions` into `getDeferredIntentCreationUPEFields(...)`, which forwards it through `client/checkout/blocks/payment-elements.js:139,146` to the processor. Grep for `sanitizeHTML|DOMPurify|dompurify` under `client/checkout/` returns **zero** matches — the client does NOT sanitize this value client-side.

## Client evidence — PHP (escapes at build, but BEFORE the filter)

`includes/class-wc-payments-checkout.php:588-596` builds the value with `WC_Payments_Utils::esc_interpolated_html(...)`:

```php
$config['testingInstructions'] = WC_Payments_Utils::esc_interpolated_html(
    /* translators: link to Stripe testing page */
    $payment_method->get_testing_instructions( $account_country ),
    [
        'a'      => '<a href="https://woocommerce.com/document/woopayments/testing-and-troubleshooting/testing/#test-cards" target="_blank">',
        'strong' => '<strong>',
        'number' => '<button type="button" class="js-woopayments-copy-test-number" aria-label="..." title="..."><i></i><span>',
    ]
);
```

`esc_interpolated_html` (`includes/class-wc-payments-utils.php:67-138`) escapes the base translatable text via `esc_html()` and only un-escapes the developer-supplied tags in `$element_map`. So the *first-party* value is safe. **But** this `$config` (the per-method `testingInstructions`) is placed into `paymentMethodsConfig` and the entire payload is then returned through the filter at `includes/class-wc-payments-checkout.php:283`:

```php
return apply_filters( 'wcpay_payment_fields_js_config', $payment_fields ); // line 283
```

The escaping happens *before* the filter, so a third-party `wcpay_payment_fields_js_config` callback can overwrite `paymentMethodsConfig['card']['testingInstructions']` with arbitrary HTML that the blocks JS then renders raw. Same vulnerability surface as core.

## Core side (for the asymmetry claim)

- Blocks JS: `plugins/woocommerce/client/blocks/assets/js/extensions/payment-methods/woopayments/index.js:903-910` — `dangerouslySetInnerHTML={ { __html: testingInstructions } }`, value sourced at lines 29-30 from `cardConfig?.testingInstructions || settings?.testingInstructions`. No client-side sanitization.
- Core builds the value at `src/Internal/Payments/Providers/WooPayments/WooPaymentsCheckoutBridge.php:497-517` (`get_card_testing_instructions()`): per-part escaping (`esc_attr__`, `esc_html`, `esc_url`) but **no final `wp_kses`** on the returned string; placed into `paymentMethodsConfig['card']['testingInstructions']` at line 457, then the whole config is returned through `apply_filters( 'wcpay_payment_fields_js_config', $config )` at line 288.
- **Asymmetry confirmed in core:** the classic path at `WooPaymentsCheckoutBridge.php:315-321` does sanitize at render — `echo wp_kses_post( $testing_instructions );` (line 319) — while the blocks path hands the value to JS unfiltered. This matches the review's claim and mirrors the client's own classic-vs-blocks split.

## Why PARITY, not REGRESSION

| Dimension | Client (v10.8.0) | Core (exp/core-native-payments) |
|-----------|------------------|----------------------------------|
| Blocks render | `dangerouslySetInnerHTML`, no JS sanitization | `dangerouslySetInnerHTML`, no JS sanitization |
| Value source | `wcpay_payment_fields_js_config` filter output | `wcpay_payment_fields_js_config` filter output |
| First-party escaping | `esc_interpolated_html` at build (before filter) | per-part escaping at build (before filter), no final kses |
| Filter runs after escaping | Yes — third-party can overwrite | Yes — third-party can overwrite |
| Classic path sanitizes | Yes (`wp_kses` family) | Yes (`wp_kses_post`, line 319) |

Both repos share the identical exploit path. Core did not introduce it. Classification: **PARITY**.

## Confidence

High. The client JS render (`__html: testingInstructions`, no sanitization), the server-side build-then-filter ordering, and the classic-vs-blocks asymmetry are all directly quoted from the v10.8.0 source. The only nuance is that the client's `esc_interpolated_html` and core's per-part escaping are not literally identical helpers, but they are functionally equivalent for this verdict: both protect the first-party string and both run before the `wcpay_payment_fields_js_config` filter, leaving the same third-party injection surface.
