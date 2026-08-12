# Provider-fidelity claims

These claims implement the 2026-08-08 decision before any new provider execution. A “single run” means one authorized, isolated, zero-retry family-suite invocation with one worker; a family suite may drive its explicitly named fixture matrix. Every result must retain public-safe exact-order/provider correlation and prove its own fixture preconditions and cleanup. Passing a family claim does not discharge every family member: `fidelity-partition.tsv` applies Condition 2 from each row's exact `residual_risk`.

The fixed contracts and selectors below are proposed for future implementation. No suite implements these fixed contracts yet, and the proposed selectors have not been run.

Each family states its claim in two parts. **Claim** is a proposition about what the real provider does and how native answers it — the thing a run establishes, and the thing a closure cites. **Falsified by** names how a run says it is wrong, so the statement can fail out loud rather than merely go unproven. The fixed contract below each pair is the oracle that makes the falsifier binding; a claim whose oracle is too weak to reject its falsifier is not established by a green run.

## Programme partition

| Family/treatment | Total | Dischargeable | Not dischargeable | Excluded | Not applicable |
|---|---:|---:|---:|---:|---:|
| `basic-card-charge` | 7 | 5 | 2 | 0 | 0 |
| `card-decline-vocabulary` | 18 | 14 | 4 | 0 | 0 |
| `saved-token-lifecycle` | 7 | 7 | 0 | 0 | 0 |
| `redirect-method-provider-outcome` | 10 | 8 | 2 | 0 | 0 |
| `refund-settlement` | 11 | 10 | 1 | 0 | 0 |
| `manual-authorization-capture` | 1 | 1 | 0 | 0 | 0 |
| `dispute-lifecycle` | 4 | 4 | 0 | 0 | 0 |
| `subscription-provider-lifecycle` | 12 | 11 | 1 | 0 | 0 |
| `multi-currency-settlement` | 9 | 5 | 4 | 0 | 0 |
| `3ds-authentication` (excluded) | 12 | 0 | 0 | 12 | 0 |
| `conventional` | 40 | 0 | 0 | 0 | 40 |
| **Programme** | **131** | **65** | **14** | **12** | **40** |

Scope is the 130 `PILOT-NATIVE-READINESS` rows plus one row pulled in from the `PILOT-PROVIDER-EXECUTION-BUDGET` gate. That row — `shopper-checkout-purchase.spec.ts:53 › Carding protection true › using a basic card` — was never blocked on native readiness, only on provider budget, and the `B1p` twin drives it inside a run that is already authorized. Pulling it in is a deliberate scope widening, not a correction to the readiness-gated derivation.

The partition derives nine runnable fidelity families, matching the decision's “about nine” estimate, and reproduces exactly 12 excluded `3ds-authentication` rows. It does not reproduce the estimate of roughly 79 dischargeable rows: exact Condition 2 containment yields 65 — 52 at first derivation, raised by the 2026-08-10 pre-run claim extensions recorded in `DECISIONS.md`. The estimate of 79 turns out to be the size of the fidelity population itself, so it assumed every family member would discharge; the decision's own text anticipated that some would not. The 14 that do not retain journey, UI, historical, configuration, coexistence, recovery, or timing residue outside their fixed contract. The 40 conventional rows may consume provider data, but their literal residual is not reducible to one provider-fidelity claim.

Six of those 65 discharge only because the `basic-card-charge` and `redirect-method-provider-outcome` contracts drive card-testing protection on as well as off — the five original twins plus the FSE twin `B1pf`. Protection enforcement is core-side and already proven there; what the protection-on twins add is that a token-bearing submission still settles to the same provider graph, and that a tokenless one produces no provider object at all. An earlier revision of this file excluded protection enforcement from both families and left the five original rows open; the exclusion was withdrawn once it was established that the existing `card-testing-protection.ts` driver can force and byte-restore the setting.

## Closure citation

An optional schema-v2 closure field, `fidelity_claim: "<family-slug>"`, cites the corresponding family section in this file. The existing local validator requires the cited row in `fidelity-partition.tsv` to use treatment `fidelity`, the exact same family, and Condition 2 verdict `dischargeable`. Historical and conventional closures without this field remain valid.

## `basic-card-charge`

### Claim

> **Claim.** One shopper checkout submission through native with provider test card `4242424242424242` produces exactly one PaymentIntent and one captured charge at the provider for USD 10.99, correlated to one run-owned Woo order that reaches `processing` or `completed`, and creates no second intent, charge, capture, order, or reusable payment method. Card-testing protection changes only whether native admits the submission: with protection on, a submission carrying a valid session token settles to that same graph, and one carrying no token never reaches the provider at all.
>
> **Falsified by.** `B1` observing a provider record whose identity, amount, currency, terminal status, or cardinality departs from that graph — including a duplicate object created by a single submission. `B1p` observing a token-bearing protection-on submission that settles to a different graph than `B1`, or a tokenless submission that produces any provider object. `B1c` observing a post-coupon local order total other than USD 10.99, an order that still carries a coupon line or a non-zero discount, or a provider graph departing from `B1`'s. `B1f` observing the FSE-surface submission departing from `B1`'s graph, or `B1pf` observing either of its submissions departing from the `B1p` outcomes on that surface.

### Fixed run contract

| Contract item | Fixed value |
|---|---|
| Intended selector | `WCPAY_RUNTIME=native pnpm --dir plugins/woocommerce test:e2e:with-env woopayments-native --project=woopayments-native-provider --workers=1 --retries=0 --grep "@fidelity:basic-card-charge"`, which collects exactly the five cases below from `plugins/woocommerce/tests/e2e/tests/woopayments-native/shopper/provider-fidelity-basic-card.spec.ts`. |
| `B1` input | Fresh guest shopper session on the native Blocks checkout, and the provider customer native creates for the order; one unique run-owned virtual product priced USD 10.99; cart contains only quantity one of that product and nothing else; native Card; provider test card `4242424242424242`, expiry `02/45`, CVC `424`; one Place order activation. The shopper is a guest rather than a signed-in customer because the protection-on twins have to read the WooCommerce *guest* session token native issues, and every case here must be the same shopper shape for "repeat `B1`" to mean anything. The expiry and security code are the ones the shared Blocks and Classic checkout drivers fill; neither carries provider meaning beyond being well-formed. |
| `B1` required outcome | Exactly one run-owned Woo order, one PaymentIntent for `1099 usd` in `succeeded`, one captured charge for `1099 usd`, and one capture occurrence; order is `processing` or `completed`; the provider charge is a Visa ending `4242`; the provider customer the intent is drawn on holds no attached payment method and the intent sets up no future usage; the cart the purchase emptied is empty; zero SetupIntents, challenges, Woo tokens, second checkout requests, second orders, second intents, or second charges. |
| `B1p` protection-on twin | Repeat `B1` on the native Classic shortcode checkout with card-testing protection on, driven through the existing `utils/woopayments-native/drivers/card-testing-protection.ts` controller with its byte-for-byte snapshot and verified restore, and the existing `utils/woopayments-native/drivers/classic-card-checkout.ts` submission driver, which refuses to submit unless the token the page exposes, the token the store's session row holds, and the token on the wire are one token. The Classic surface is fixed here because the ledger row this case discharges is the Classic `shopper-checkout-purchase.spec.ts` protection-on case and because that controller provisions exactly that page. One submission carrying a valid session token must reach the same `1099 usd` intent, captured charge, order status, and single-graph cardinality as `B1`. One submission with the session token absent must produce zero provider objects, zero paid order, and one native rejection. The original protection state, including the recorded original effective value, is restored before the case closes. This case supersedes the never-run `pilots/shopper-card-protection.spec.ts` registration of the same row, which drove only the admitted half. |
| `B1c` coupon add/remove | Repeat `B1` with one run-owned 100%-discount coupon applied and then removed on the Blocks checkout page before the single submission. While it is applied the Store API cart must report that coupon and a total of `0`; after removal it must report no coupon and `1099` again. The order the one submission creates must total USD 10.99 exactly, carry no coupon line and a zero discount total, and settle to `B1`'s exact `1099 usd` intent, captured charge, order status, and single-graph cardinality. The coupon is deleted and proven absent before the case closes. |
| `B1f` FSE surface | Repeat `B1` on the native Site Editor (FSE block-theme) checkout surface with card-testing protection off: one submission, and the same `1099 usd` one-intent/one-captured-charge/one-order graph as `B1`. That surface is the Blocks checkout rendered under a supported block theme the store does not normally run — `twentytwentyfour`, which `plugins/woocommerce/.wp-env.json` installs — activated and restored by one shared new snapshot/restore driver, `utils/woopayments-native/drivers/block-theme.ts`, also used later by the Site Editor 3DS spec. The store's own theme is already a block theme, so what this case adds over `B1` is the activation of a *second* supported one and the purchase settling under it, not the existence of an FSE surface; the case proves it met one by requiring WooCommerce's own `wp_is_block_theme()` body class and the activated theme's slug on the rendered checkout. |
| `B1pf` FSE protection-on twin | Repeat the `B1p` shape on that same FSE surface — the Blocks checkout under the activated block theme, not the Classic shortcode page: one token-bearing submission under protection on settles to `B1`'s graph, and one tokenless submission produces zero provider objects, zero paid order, and one native rejection, with the same byte-for-byte protection snapshot and verified restore. On this surface native hands the session token to the block payment method through its own enqueued configuration and the payment method reads it at submit, so that is the protection interaction this case drives; the Classic surface under the activated theme is not driven. `B1pf` carries the same forced-eligibility boundary as `B1p` (below). It uses the basic card and raises no 3DS challenge; theme 3DS overlay behavior stays outside this claim. |
| Convergence | Poll the exact order, PaymentIntent, and charge IDs every 2 seconds for at most 60 seconds; pass only after two consecutive reads return the same terminal IDs, amounts, currencies, and statuses. For the `B1p` and `B1pf` tokenless submissions, require the native rejection and then an empty provider-object interval of at least 10 seconds: across that interval the run's provider customer must stay free of attached payment methods and the admitted submission's graph must not move. The customer enumerated is the one the admitted half drew on — a refused submission never reaches the provider, so it creates no customer of its own to enumerate, and naming one would be an oracle that cannot be evaluated. |
| Cleanup/restoration | Empty the run shopper's cart; delete the run-owned product, the run-owned coupon, and browser/session data, and prove the coupon absent. Restore the card-testing protection snapshot and the store's active theme byte-for-byte, each with a verified read. No case in this family changes the gateway, the store or shopper currency, or any saved credential, and none saves a card, so no gateway, currency, local token, local default, or provider-customer default snapshot is taken and none is claimed — a purchase that attached anything reusable fails `B1`'s required outcome instead of being restored away. Retain the immutable paid order/provider graph under its run ID and exclude it from later discovery. Any unowned delta, second order, or restoration mismatch fails and quarantines the run IDs. |

### Core-side proof

- `plugins/woocommerce/tests/php/src/Internal/Payments/PaymentProcessingServiceTest.php`: `test_process_checkout_completes_order_for_completed_outcome`, provider-effect ordering, referenced-success reconciliation, and locked-operation tests.
- `plugins/woocommerce/tests/php/src/Internal/Payments/NativeWooPaymentsGatewayTest.php`: `test_process_payment_delegates_to_processing_service`, `test_process_payment_redirects_duplicate_checkout_to_paid_session_order_before_processing`, and attached-success replay coverage.
- `plugins/woocommerce/tests/e2e/tests/woopayments-native/scenarios/card-payment.ts`: exact run/order/payment-method/intent/charge/amount/currency/cardinality and accessible receipt oracle used by `pilots/shopper-card-payment.spec.ts`.
- `plugins/woocommerce/tests/e2e/utils/woopayments-native/drivers/block-theme.ts` and its unit tests: the snapshot, activate, verified read and restore contract `B1f` and `B1pf` rest on, including its refusal to run when the target theme is already active — a store found on the E2E theme means an earlier run did not put it back, and adopting that as a baseline would launder an unrestored store into a passing run.
- HARNESS section 3 **Bucket-E parity** and **Financial reconciliation matrix** are the applicable post-drive joins.
- Card-testing protection is enforced entirely core-side, before the provider boundary: `NativeWooPaymentsGatewayTest::test_process_payment_rejects_invalid_fraud_prevention_token_when_enabled` (named for rejecting *before creating a payment context*), `::test_process_payment_skips_fraud_prevention_token_check_when_disabled`, and `WooPaymentsFraudPreventionServiceTest::test_verify_token_accepts_exact_session_token_only`.

### HARNESS residue and deliberate exclusions

Bucket-E is final-state-only, sampled-order evidence and excludes transition sequence plus customer/token/session/option state. Financial reconciliation covers only dimensions present on the driven order. A4aq proves only its named card-surface structure and browser-operability checks, not provider success. This claim deliberately excludes the Site Editor theme's 3DS overlay/presentation behavior (retained by the conventional Site Editor 3DS rows), same-page decline recovery, pay-for-order or historical cutover, 3DS/SCA, and pixel/copy parity.

Surfaces are not interchangeable here and no case may be read as evidence for one it does not drive. `B1`, `B1c`, `B1f` and `B1pf` drive the native Blocks checkout; `B1p` drives the Classic shortcode checkout. Two combinations are therefore outside the claim rather than merely unmentioned: the Classic surface under the activated block theme, and the Blocks surface under protection with the store's own theme. So is the signed-in shopper — every case here is a guest, for the reason `B1`'s input records.

`B1p` and its FSE twin `B1pf` carry the same eligibility boundary as the redirect family's protection-on twins. The target account reports `card_testing_protection_eligible: false`, so the driver asserts eligibility into the local account cache. Both twins establish that native enforces protection at its own boundary and that a token-bearing card submission still settles correctly at the provider; they establish nothing about whether the provider grants this account the capability. Provisioning of the eligibility flag remains outside this claim.

## `card-decline-vocabulary`

### Claim

> **Claim.** The provider's real decline vocabulary for the five listed test cards is the code set native's error mapping and failed-transaction rate limiter are keyed on: each card returns its fixed top-level error and decline-code pair, native renders the matching semantic error and exactly one failed provider object at the surface's exact unpaid-order cardinality with no duplicate local failure effect and no charge, capture, paid order, or attached token, and the checkout limiter increments once for top-level `card_declined` and `incorrect_cvc` and not at all for `expired_card` or `processing_error`.
>
> **Falsified by.** `D-PI-*` or `D-SI-*` observing a card that returns a different code pair, a code native maps to a different semantic error, a duplicate local failure effect from one submission, any success-side object, or a limiter delta departing from the allowlist Core's tests fix.

### Fixed run contract

| Contract item | Fixed value |
|---|---|
| Intended selector | Proposed future selector; it does not exist yet: `pnpm exec playwright test plugins/woocommerce/tests/e2e/tests/woopayments-native/provider-fidelity.spec.ts --project=chromium --grep "@fidelity:card-decline-vocabulary" --workers=1 --retries=0` |
| `D-PI-generic` | Classic native checkout, USD 10.01, card `4000000000000002`; exactly one PaymentIntent for `1001 usd` ends `requires_payment_method` with top-level error `card_declined` and decline code `generic_decline`, one semantic generic-decline alert, and exactly one unpaid run-owned Woo order. |
| `D-PI-expired` | Blocks native checkout, USD 10.02, card `4000000000000069`; exactly one PaymentIntent for `1002 usd` ends `requires_payment_method` with top-level error `expired_card` and decline code `expired_card`, one expired-card semantic alert, and exactly one unpaid run-owned Woo order. |
| `D-PI-insufficient` | Blocks native checkout, USD 10.03, card `4000000000009995`; exactly one PaymentIntent for `1003 usd` ends `requires_payment_method` with top-level error `card_declined` and decline code `insufficient_funds`, one insufficient-funds semantic alert, and exactly one unpaid run-owned Woo order. |
| `D-PI-cvc` | Blocks native checkout, USD 10.04, card `4000000000000127`; exactly one PaymentIntent for `1004 usd` ends `requires_payment_method` with top-level error `incorrect_cvc` and decline code `incorrect_cvc`, one incorrect-CVC semantic alert, and exactly one unpaid run-owned Woo order. |
| `D-PI-processing` | Classic native checkout, USD 10.05, card `4000000000000119`; exactly one PaymentIntent for `1005 usd` ends `requires_payment_method` with top-level error `processing_error` and decline code `processing_error`, one processing-error semantic alert, and exactly one unpaid run-owned Woo order. |
| `D-SI-*` | Five independent fresh My Account customers repeat the same card/code mapping through one SetupIntent each; every SetupIntent ends `requires_payment_method`, its payment method is unattached, and the case creates zero Woo orders, PaymentIntents, charges, captures, or tokens. |
| Shared negative outcome | Every case has exactly one submit gesture and one failed provider event, zero success/capture events, zero paid orders, zero second failed Woo order, zero duplicate local failure effect, and no raw diagnostic leakage. |
| Checkout limiter mapping | Give every `D-PI-*` case its own empty snapshotted limiter session. Core's exact increment allowlist is the top-level error-code set `{card_declined, incorrect_number, incorrect_cvc}`: `D-PI-generic` and `D-PI-insufficient` each add one timestamp through top-level `card_declined`; `D-PI-cvc` adds one through top-level `incorrect_cvc`; `D-PI-expired` with top-level `expired_card` and `D-PI-processing` with top-level `processing_error` each add zero. The `D-SI-*` SetupIntent cases do not traverse `process_payment` and must leave their empty limiter snapshots unchanged. This matrix has no `incorrect_number` provider fixture, so it makes no provider claim for that allowlist member. |
| Convergence | Poll the exact failed intent and local order/token deltas every 2 seconds for at most 45 seconds; require two consecutive identical terminal reads and an empty relevant-event interval for the final 4 seconds. |
| Cleanup/restoration | Delete or detach each run-owned unattached method; empty carts and remove run-owned products/browser sessions. Restore raw customer attachment/default, local token/default/cache, limiter, gateway, currency, and shopper-session snapshots byte-for-byte. Retain failed intents/orders under run IDs; any second order/effect or unowned delta fails and quarantines the affected IDs. |

### Core-side proof

- `plugins/woocommerce/tests/php/src/Internal/Payments/Providers/WooPayments/WooPaymentsErrorMessagesTest.php`: the full known-code catalog, decline-code precedence, generic fallback, and unsafe-error redaction.
- `plugins/woocommerce/tests/php/src/Internal/Payments/Providers/WooPayments/WooPaymentsIntentCodecTest.php`: failed-intent and structured transport-error mapping.
- `plugins/woocommerce/tests/php/src/Internal/Payments/Providers/WooPayments/WooPaymentsProviderGatewayAdapterTest.php::test_native_charge_failure_maps_structured_card_decline_to_shopper_message`.
- `plugins/woocommerce/tests/php/src/Internal/Payments/Providers/WooPayments/WooPaymentsCheckoutAjaxControllerTest.php::test_create_setup_intent_localizes_card_decline_api_errors`.
- `plugins/woocommerce/tests/php/src/Internal/Payments/NativeWooPaymentsGatewayTest.php`: `failed_transaction_limited_error_codes` fixes the top-level increment allowlist to `card_declined`, `incorrect_number`, and `incorrect_cvc`; `test_process_payment_bumps_failed_transaction_rate_limiter_for_decline_error_codes` and `test_process_payment_does_not_bump_failed_transaction_rate_limiter_for_other_errors` prove the positive and `processing_error` negative behavior.

### Correction 2026-08-12 — the provider returns a decline code for every card

This family's first authorized run falsified part of its own claim, and the record keeps the falsification rather than absorbing it.

The three case rows above said `expired_card`, `incorrect_cvc` and `processing_error` return **no** decline code. Read from `last_payment_error.decline_code` on the platform's own PaymentIntent passthrough, all three return a decline code that mirrors their top-level code, and the two `card_declined` cards carry their specific reason as before. So the provider returns a decline code for all five fixtures, and the rows are corrected to the observed pairs.

Nothing user-visible was wrong. `get_shopper_message()` prefers `decline_code` when the catalog contains it, and all three mirrored codes are in that catalog, so the mapped sentence is identical under either precedence — which is why every message assertion passed while only the code-pair assertion failed.

The claim's proposition and its Falsified-by clause are unchanged. A run observing "a card that returns a different code pair" is exactly what happened, and it is what a fixed contract is for: the wrong belief was written down before the run, so the run could contradict it. Later readers should treat the corrected pairs as observed fact and the original wording as the mistake it was, not as history to be tidied away.

### HARNESS residue and deliberate exclusions

Bucket-E can show the sampled order's final failed state but not the transition sequence or repeated attempts. A4aq covers structural checkout/add-payment-method behavior but does not establish provider failure vocabulary. This claim deliberately excludes 3DS/SCA, exact sentence/copy parity, accessibility announcement behavior beyond the semantic alert category, same-page recovery and retained cart/address state, successful-retry idempotency, a second timing sample, card-testing-protection configuration, and provider proof for the un-driven top-level `incorrect_number` limiter member.

## `saved-token-lifecycle`

### Claim

> **Claim.** A `4242` SetupIntent at the real provider yields exactly one payment-method-to-token relationship, that single token funds a USD 10.99 purchase with nothing else drawn on, and deleting it returns local tokens, provider attachments, and defaults to their recorded baseline. A second add attempt inside WooCommerce's 20-second cooldown is rejected natively and creates nothing at the provider, and a normal add after the cooldown attaches and deletes cleanly. The same one-to-one relationship holds when the credential is minted by a purchase instead of by a SetupIntent: one `4242` checkout submission carrying the save request leaves one paid `1099 usd` graph *and* exactly one provider method attached to the shopper's customer, stored by exactly one new Woo token — on the Classic surface and on the Blocks surface alike — and a checkout-origin token deletes as cleanly as a My Account one.
>
> **Falsified by.** `T1`–`T3` observing a token identity that diverges from the SetupIntent's method, a second attachment or token appearing at either side, a purchase funded by anything other than that token, or any baseline left unrestored after deletion. `T1b` observing a cooldown-window add attempt that produces any provider attachment, method, or token, a missing native rejection, or a post-cooldown add that fails to attach or delete cleanly. `T4` or `T5` observing a save-carrying checkout that pays without attaching, attaches a method no Woo token stores, stores a token whose method the payment did not use, produces a second attachment or token from one submission, or — for `T4` — leaves the deleted checkout-origin token or its provider attachment readable, or any baseline token, default, or attachment changed.

### Fixed run contract

| Contract item | Fixed value |
|---|---|
| Intended selector | `WCPAY_RUNTIME=native pnpm --dir plugins/woocommerce test:e2e:with-env woopayments-native --project=woopayments-native-provider --workers=1 --retries=0 --grep "@fidelity:saved-token-lifecycle"`, which collects exactly the six cases below from `plugins/woocommerce/tests/e2e/tests/woopayments-native/shopper/provider-fidelity-saved-tokens.spec.ts`. |
| `T1` create | Fresh customer with recorded zero-run-token baseline submits card `4242424242424242` once through native My Account. One SetupIntent succeeds, one provider method attaches to that customer, and exactly one Woo token stores that method; zero orders, PaymentIntents, charges, or captures. |
| `T1b` cooldown | Immediately after a successful save, submit one more add attempt through the same native My Account form during WooCommerce's 20-second cooldown: exactly one native rejection and an empty provider-attachment interval for the run customer — zero new attachments, methods, or Woo tokens. After the cooldown elapses, one normal add succeeds with one succeeded SetupIntent, one provider attachment, and one Woo token, and that token/method is deleted and verified absent. The anchoring save is the case's own rather than `T1`'s: fixture teardown and setup between two cases can consume most of a 20-second window, and a window that quietly expired would convert this rejection contract into a silently attached second card — a case that passes while proving nothing. The case therefore requires WooCommerce to still report the shopper rate-limited before it clicks, and deletes both cards it created. |
| `T2` reuse | One unique USD 10.99 product/cart selects the exact `T1` token once. Exactly one order, one `1099 usd` succeeded PaymentIntent, one captured charge, and one capture bind the same user, customer, provider method, and Woo token; no replacement/default token funds the order. |
| `T3` delete | One token-bound delete removes the exact Woo token and detaches the exact provider method; neither remains in a fresh local/provider read and no baseline token/default changes. |
| `T4` save at Classic checkout | One unique USD 10.99 product/cart on the run-provisioned Classic shortcode checkout, card `4242424242424242`, the save-to-account control ticked, one Place order activation. Exactly one run-owned order with one `1099 usd` succeeded PaymentIntent, one captured charge, and one capture; exactly one provider method attaches to the run customer, exactly one new Woo token stores that exact method, and that method is the one the payment used. The checkout-origin token is then deleted once: the exact Woo token and the exact provider attachment are absent from a fresh local and provider read two seconds apart, and every baseline token, default, and attachment is unchanged. This case establishes nothing about the My Account SetupIntent path — the token here is minted from the PaymentIntent by the order-effect applier, a different native path — and nothing about 3DS-authenticated saving, which the Classic authentication spec retains, nor about pay-for-order, express/wallet saving, or guest-save eligibility. |
| `T5` save at Blocks checkout | Repeat `T4`'s save half on the native Blocks checkout surface: one unique USD 10.99 product/cart, card `4242424242424242`, the Blocks save control ticked, one Place order activation, and the same one-order/one-`1099 usd`-intent/one-captured-charge graph with exactly one provider attachment and exactly one new Woo token storing that exact method. Kept distinct from `T4` rather than folded into it because Blocks is a third native path: a different payment element, a different submission route, and a different save control. Its token and method are then removed as exact cleanup, not as a proven deletion contract — the checkout-origin deletion contract is `T4`'s. It establishes nothing about express payment methods, guest checkout, 3DS, or the Site Editor checkout surface. |
| Convergence | Poll exact SetupIntent/method/token IDs and later PaymentIntent/charge IDs every 2 seconds for at most 60 seconds per phase; deletion requires two absent reads 2 seconds apart; the `T1b` cooldown rejection requires a subsequent empty provider-attachment interval of at least 10 seconds. `T4` and `T5` converge on their own order/intent/charge IDs under the same rule, and `T4`'s deletion under the same two-absent-reads rule. |
| Cleanup/restoration | Empty the run cart, delete its products, tokens, and browser session, and detach only run-owned methods. Restore raw local token/cache/default snapshots and the provider attachment set byte-for-byte, and retain the immutable purchase graphs under their run IDs. The provider customer's own default-method field is deliberately outside that promise: the store proxies a read of a customer's *attached methods* but not of the customer object, so a run can prove detachment and the local default but cannot read — and therefore must not claim to restore — the remote `invoice_settings.default_payment_method`. WooCommerce makes a shopper's first token the default (`WC_Payment_Token_Data_Store::create()`) and native mirrors that remotely (`WooPaymentsTokenService::handle_woocommerce_payment_token_set_default`), so a run against a shopper who starts with no token does move that field; that movement is recorded here rather than asserted away. A stale run token, changed baseline token/default, or unowned attachment fails cleanup. |

### Core-side proof

- `plugins/woocommerce/tests/php/src/Internal/Payments/Providers/WooPayments/WooPaymentsTokenServiceTest.php`: owned-token resolution, token creation/reuse, provider detachment, default-method updates, exact order/subscription attachment, and cache clearing.
- `plugins/woocommerce/tests/php/src/Internal/Payments/Providers/WooPayments/WooPaymentsOrderEffectApplierTest.php`: idempotent requested-token creation, selected-token attachment, and related-subscription synchronization.
- `plugins/woocommerce/tests/php/src/Internal/Payments/Providers/WooPayments/WooPaymentsCheckoutAjaxControllerTest.php`: token save before order completion and SetupIntent response handling.
- `plugins/woocommerce/tests/e2e/tests/woopayments-native/pilots/saved-method-cutover.spec.ts` and `plugins/woocommerce/tests/e2e/utils/woopayments-native/drivers/saved-cards.ts`: exact local/provider token identity, reuse, and detach-aware cleanup scaffolding. The cutover pilot's own add helper drives the *plugin* runtime's My Account markup, so the native surfaces `T1`, `T1b`, `T4`, and `T5` drive are separate helpers in the same driver.
- `plugins/woocommerce/tests/e2e/tests/woopayments-native/shopper/classic-card-authentication.spec.ts`: the 3DS twin of `T4`'s journey, already green on the Classic save-at-checkout surface. `T4` drives the same surface through the same `PlaywrightClassicCardCheckoutBrowser`, differing only in the card and in the absence of a challenge.

### HARNESS residue and deliberate exclusions

Bucket-E explicitly excludes customer/token/subscription state, and A4aq explicitly excludes saved-method mutation; those gaps are the reason this run must query exact token/customer/provider identities. This claim deliberately excludes 3DS-authenticated token creation/use, subscription renewal semantics, historical plugin-to-native cutover, and UI copy/layout.

Save-at-checkout token creation is *not* excluded: `T4` and `T5` name it, on the Classic and Blocks surfaces respectively, because it is a different native path from the My Account SetupIntent that `T1` drives — the credential is minted from the PaymentIntent by `WooPaymentsOrderEffectApplier`, alongside an order and a charge that `T1` explicitly forbids. Neither case may be read as evidence for the other's surface, and neither may be read as evidence for `T1`'s. What remains outside the claim on those surfaces is the pay-for-order route, express and wallet saving, guest-save eligibility (retained by the guest-save smoke and its core-side proof), the Site Editor checkout surface, 3DS-authenticated saving, and the remote default-method field named in Cleanup/restoration.

## `redirect-method-provider-outcome`

### Claim

> **Claim.** For each listed redirect method, native sends the real provider the correct method, amount, currency, and return URL, and the provider reaches that case's fixed redirect or terminal state with exactly one correlated order and intent graph and no second transaction. Card-testing protection changes only whether native admits the submission: with protection on, a submission carrying a valid session token reaches the provider and settles to the same graph as the protection-off case, and one carrying no token never reaches the provider at all.
>
> **Falsified by.** `A1`–`A5` or `A1b` observing a request whose method, amount, currency, or return URL diverges, a provider state other than the one fixed for that case, or a second transaction arising from one handoff. `A2p`–`A4p` observing a token-bearing protection-on submission that reaches a different provider graph than its protection-off twin, or a tokenless submission that produces any provider object.

### Fixed run contract

| Contract item | Fixed value |
|---|---|
| Intended selector | Proposed future selector; it does not exist yet: `pnpm exec playwright test plugins/woocommerce/tests/e2e/tests/woopayments-native/provider-fidelity.spec.ts --project=chromium --grep "@fidelity:redirect-method-provider-outcome" --workers=1 --retries=0` |
| `A1` Alipay | CTP off; Alipay enabled; shopper/store currency USD; one USD 12.00 order. Request method is `alipay`, amount `1200`, currency `usd`, and the run return URL; one provider redirect is followed once and the same PaymentIntent becomes `succeeded` with one captured charge. |
| `A1b` Alipay on Blocks | Repeat `A1`'s exact proposition — method `alipay`, amount `1200`, currency `usd`, run return URL, one redirect followed once, the same PaymentIntent `succeeded` with one captured charge — driven unconditionally through the native Blocks checkout surface. The method or surface being unavailable fails the case; there is no conditional skip. |
| `A2` Affirm | CTP off; Affirm enabled; USD 100.00 order and eligible US address. Request method is `affirm`, amount `10000`, currency `usd`, and the run return URL; one provider redirect is followed once and the same PaymentIntent becomes `succeeded` with one captured charge. |
| `A3` Cash App Afterpay | CTP off; Cash App Afterpay enabled; USD 100.00 order and eligible US address. Request method is `afterpay_clearpay`, amount `10000`, currency `usd`, and the run return URL; one provider handoff is followed once and the same PaymentIntent becomes `succeeded` with one captured charge. |
| `A4` Bancontact | CTP off; Bancontact enabled; shopper/store currency EUR; one EUR 12.34 order and eligible EU address. Request method is `bancontact`, amount `1234`, currency `eur`, and the run return URL; one provider redirect is followed once and the same PaymentIntent becomes `succeeded` with one captured charge. |
| `A5` Klarna handoff | Klarna enabled; USD 100.00 order and eligible US address. Request method is `klarna`, amount `10000`, currency `usd`, and the run return URL; the same PaymentIntent returns `requires_action` with one HTTPS provider redirect. This case stops before hosted authorization and requires zero charge/capture. |
| `A2p`, `A3p`, `A4p` protection-on twins | Repeat `A2`, `A3` and `A4` with card-testing protection on, driven through the existing `utils/woopayments-native/drivers/card-testing-protection.ts` controller: it snapshots `wcpay_account_data` and `wcpaydev_force_card_testing_protection_on` as raw rows, forces `card_testing_protection_eligible` true, and restores both byte-for-byte with a verified `rowsMatch` read, quarantining on mismatch. Each twin submits once with a valid session token and must reach the same method, amount, currency, terminal provider state, and single-charge graph as its protection-off case. Each twin also submits once with the session token absent and must produce zero provider objects, zero paid order, and one native rejection. Every twin restores the original protection state, including the recorded original effective value, before the next case. |
| Convergence | For `A1`–`A4` and the `A2p`–`A4p` token-bearing submissions, poll the exact intent/charge every 2 seconds for at most 90 seconds and require two identical terminal reads; for `A5`, require the exact `requires_action` redirect response within 30 seconds. For each tokenless submission, require the native rejection and then an empty provider-object interval of at least 10 seconds for that run's customer before passing. |
| Cleanup/restoration | Before every case record raw enabled-method, CTP, enabled-currency, store-currency, shopper-session currency, customer, attachment/default, and cart state. After the case restore every raw value byte-for-byte, empty the cart, and remove the run product/session. Retain immutable order/provider graphs by run ID. A changed original customer, currency, method, protection setting, or unowned delta fails cleanup. |

### Core-side proof

- `plugins/woocommerce/tests/php/src/Internal/Payments/PaymentProcessingServiceTest.php`: redirect outcomes, non-Stripe redirect providers, and asynchronous providers without card data.
- `plugins/woocommerce/tests/php/src/Internal/Payments/Providers/WooPayments/WooPaymentsIntentCodecTest.php`: provider-redirect, asynchronous-debit, non-card title, and order-received URL mappings.
- `plugins/woocommerce/tests/php/src/Internal/Payments/Providers/WooPayments/WooPaymentsProviderGatewayAdapterTest.php`: split-method derivation, return URL, sanitized redirect, order-currency validation, and checkout-context validation.
- `plugins/woocommerce/tests/php/src/Internal/Payments/NativeWooPaymentsGatewayTest.php`: method-definition currency, account capability, amount-limit, BNPL address, and placement availability tests.
- `plugins/woocommerce/tests/php/src/Internal/Payments/Providers/WooPayments/PaymentMethods/WooPaymentsPaymentMethodRegistryTest.php`: exact `alipay`, `affirm`, `afterpay_clearpay`, `bancontact`, and `klarna` provider type IDs plus country/currency/amount eligibility.
- Card-testing protection is enforced entirely core-side, before the provider boundary: `NativeWooPaymentsGatewayTest::test_process_payment_rejects_invalid_fraud_prevention_token_when_enabled` (named for rejecting *before creating a payment context*), `::test_process_payment_skips_fraud_prevention_token_check_when_disabled`, and `::test_add_payment_method_rejects_invalid_fraud_prevention_token_when_enabled`.
- `plugins/woocommerce/tests/php/src/Internal/Payments/Providers/WooPayments/WooPaymentsFraudPreventionServiceTest.php`: session-token generation/persistence, exact-token-only verification, regeneration, and reading eligibility from the account cache.
- `plugins/woocommerce/tests/php/src/Internal/Payments/Providers/WooPayments/WooPaymentsCheckoutBridgeTest.php`: token exposure in classic checkout config and Blocks payment-method data when protection is enabled.

### HARNESS residue and deliberate exclusions

A4aq explicitly does not cover redirects or full wallet sheets; Bucket-E sees only final sampled-order state. This claim deliberately excludes hosted-page usability/content, cross-origin accessibility, Klarna completion beyond `A5`, Blocks/client wiring for methods other than Alipay — `A1b` drives the Alipay Blocks surface; the other methods' Blocks wiring stays outside — merchant admin presentation, method visibility/recomputation, and refunds.

The protection-on twins carry one boundary that must not be overstated. The target account reports `card_testing_protection_eligible: false`, so the driver asserts eligibility into the local account cache rather than receiving it from the platform. `A2p`–`A4p` therefore establish that native enforces protection at its own boundary and that a token-bearing submission still settles correctly at the provider; they establish nothing about whether the provider genuinely grants this account the capability, because the run supplies that premise itself. Provisioning of the eligibility flag remains outside this claim.

## `refund-settlement`

### Claim

> **Claim.** One refund request against the listed source charge for the listed amount and currency produces exactly one provider refund that reaches `succeeded`; WooCommerce stores that refund's identity and a matching refunded total; a deliberate byte-identical replay under the same idempotency key returns that same provider refund rather than creating another, leaving both the provider's and WooCommerce's refund counts at one; and the native transaction view presents each observed refund's semantic facts within a bounded propagation window — for `R1`'s refund its amount, refunded status, and merchant-supplied reason, and for `R3`'s refund its amount in the original charge currency and its refunded status.
>
> **Falsified by.** `R1`–`R7` observing a second refund on either side, a stored identity or refunded total that disagrees with the provider's, a refund that does not reach `succeeded`, or a replay that creates a new provider refund instead of returning the original. `R1v` observing a transaction view whose semantic amount, status, or reason disagrees with the provider refund, or one that fails to present them within the bounded window. `R3v` observing a transaction view whose amount, charge currency, or refunded status disagrees with the provider refund, one that presents the amount in a currency other than the source charge's, or one that fails to present them within the bounded window.

### Fixed run contract

| Contract item | Fixed value |
|---|---|
| Intended selector | Proposed future selector; it does not exist yet: `pnpm exec playwright test plugins/woocommerce/tests/e2e/tests/woopayments-native/provider-fidelity.spec.ts --project=chromium --grep "@fidelity:refund-settlement" --workers=1 --retries=0` |
| `R1` card full | Source is one captured `4242` USD 10.99 charge; refund `1099 usd`; one provider refund becomes `succeeded`, the Woo refund stores its ID once, and order refunded total is USD 10.99. |
| `R1v` transaction view | After `R1` converges, the merchant loads the native transaction view for `R1`'s charge and asserts the refund's semantic facts — amount USD 10.99, refunded status, and the merchant-supplied refund reason — with bounded async polling for propagation. The assertion is semantic, not copy-exact; native presentation wording may differ. |
| `R2` card partial | Source is one captured `4242` charge on a three-line USD 10.99 order; the refund selects the two intended lines by order-item ID for `333 usd`; one provider refund becomes `succeeded`, the Woo refund stores its ID once and its per-line allocation across exactly those two order items is asserted, and remaining refundable amount is USD 7.66. |
| `R3` foreign currency | Source is one captured `4242` EUR 12.34 charge; refund `1234 eur`; one provider refund becomes `succeeded`; Woo and provider refund currency remain EUR and settlement facts reconcile to the source balance transaction. The `R3v` transaction-view observation applies to this case. |
| `R3v` foreign-currency transaction view | After `R3` converges, the merchant loads the native transaction view for `R3`'s charge and asserts the refund's semantic facts — the EUR 12.34 amount stated in the source charge's own currency, and refunded status — with bounded async polling for propagation. The assertion is semantic, not copy-exact; native presentation wording, symbol placement, and locale formatting may differ, so the observation names the amount and the charge's currency rather than a formatted string. It observes only: it creates no charge, no refund, and no second provider object of any kind. |
| `R4` Alipay | Within this refund case, create a fresh Alipay USD 12.00 source charge using the `A1` method/address inputs; refund `1200 usd`; one provider refund becomes `succeeded` and is stored on the exact Woo refund. |
| `R5` Affirm | Within this refund case, create a fresh Affirm USD 100.00 source charge using the `A2` method/address inputs; refund `10000 usd`; one provider refund becomes `succeeded` and is stored on the exact Woo refund. |
| `R6` Bancontact | Within this refund case, create a fresh Bancontact EUR 12.34 source charge using the `A4` method/address inputs; refund `1234 eur`; one provider refund becomes `succeeded` and is stored on the exact Woo refund. |
| `R7` Cash App Afterpay | Within this refund case, create a fresh Cash App Afterpay USD 100.00 source charge using the `A3` method/address inputs; refund `10000 usd`; one provider refund reaches `succeeded` and is stored on the exact Woo refund. The `RP` same-key replay applies to this case, and its convergence runs under the extended budget stated below. |
| `RP` deliberate same-key replay | After the first refund has two stable `succeeded` reads, disable automatic HTTP retries for this probe and invoke the native WooPayments refund API client exactly once more with the byte-identical source charge, amount, reason, metadata, and recorded `Idempotency-Key` from that case's first POST. Journal exactly two refund POSTs total: the initial request and this named replay. The replay response must return the same provider refund ID and `succeeded` status; a fresh provider list read must contain exactly one refund with that ID/key/source charge; WooCommerce must still have one matching refund row, the same provider ID, and the unchanged refunded total. This is an intentional financial idempotency probe, not a Playwright, assertion, helper, or transport retry; `--retries=0` remains mandatory. |
| Convergence | Poll the exact provider refund ID and Woo refund metadata every 3 seconds for at most 180 seconds before `RP`; `R7` carries an explicitly extended budget for Cash App Afterpay's slower provider timeline — poll every 5 seconds for at most 600 seconds before `RP`; after the replay POST, poll every 3 seconds for at most 60 seconds and pass only after two identical reads of the same `succeeded` refund ID/status plus one final provider list and Woo-order read proving both refund counts and the refunded total remain one/unchanged. |
| Cleanup/restoration | Record and restore raw gateway, method, enabled-currency, store-currency, shopper/customer currency, customer attachment/default, local token/default, and cart snapshots byte-for-byte. Remove run products/carts/sessions. Retain immutable source charges/refunds and local financial history by run ID. Any changed original customer/configuration, second refund, or unowned delta fails and quarantines the graph. |

### Core-side proof

- `plugins/woocommerce/tests/php/src/Internal/Payments/PaymentProcessingServiceTest.php`: provider-success handling, distinct/equal-refund keys, `test_reprocessing_same_refund_instance_reuses_idempotency_key`, exact local-refund resolution, persisted provider metadata, and reconciliation on post-success local failure.
- `plugins/woocommerce/tests/php/src/Internal/Payments/Providers/WooPayments/Api/WooPaymentsApiClientTest.php::test_request_lifts_idempotency_key_and_preserves_filtered_params`: the refund POST sends the caller's exact `Idempotency-Key` header with the charge/body fields while keeping the key out of the JSON body.
- `plugins/woocommerce/tests/php/src/Internal/Payments/Providers/WooPayments/WooPaymentsProviderGatewayAdapterTest.php`: native refund preference, provider identity, and failed-status handling.
- `plugins/woocommerce/tests/php/src/Internal/Payments/Providers/WooPayments/WooPaymentsOrderEffectApplierTest.php`: compatibility metadata/notes after transport.
- `plugins/woocommerce/tests/php/src/Internal/Payments/Providers/WooPayments/WooPaymentsRefundEventHandlerTest.php`: synchronous refund plus webhook convergence.
- HARNESS section 3 **Financial reconciliation matrix** and **Bucket-E parity**.

### HARNESS residue and deliberate exclusions

Financial reconciliation proves only the `R1`–`R7` dimensions; Bucket-E proves final refund/order facts, not the transition sequence. The HARNESS runbook still calls for the full refund matrix. Merchant-visible presentation is in scope only through `R1v` and `R3v`, and only semantically: exact copy, wording, symbol placement, locale formatting, and layout are excluded there as everywhere else. This claim deliberately excludes refund-dialog presentation, coexistence ownership across runtimes, payout behavior, and every combination not listed above.

### Correction 2026-08-12 — the same-key replay is refused, not replayed

This family's first authorized run of `R7` falsified part of its own claim, and
the record keeps the falsification rather than absorbing it.

**What was asserted.** That a byte-identical refund request carrying the same
derived idempotency key "returns that same refund instead of creating a second"
— an idempotent replay.

**What was observed.** The provider evaluated the replay as a fresh request and
refused it:

    WooPaymentsApiException: Error: Charge py_3U3f31BzWlxcwgpP18XYGnag
    has already been refunded.

**Why.** The key never reaches Stripe. `WooPaymentsApiClient::request()` strips
`idempotency_key` out of the body and promotes it to an `Idempotency-Key` HTTP
header, and the platform reads it back as a *parameter* —
`get_param( 'idempotency-key' ) ?? get_param( 'idempotency_key' )` in
`Wcpay_Rest_Request::get_idempotency_key_for_stripe_proxy_request()` — which
never sees a header. The WooPayments client plugin does exactly the same thing,
so this is a standing contract mismatch rather than a native regression, and
native is at parity.

**What the case now establishes.** The property that actually protects money:
one same-key replay reaches the provider exactly once and creates no second
refund on either side — whether it is replayed or refused. The refused branch
additionally requires the refusal to name an over-refund of that exact charge,
so a refusal for any other reason still fails.

**What it no longer claims.** That idempotency is in force for refunds. On this
path it is not, and the over-refund guard is the only thing standing between a
replay and a duplicate. For a *full* refund that guard has no room to fail; a
partial refund does, and no case here exercises that.

**Boundary.** Established against the local Transact Platform checkout. It is
evidence about the WPCOM code path as it exists locally, not proof of production
platform behaviour; the header-versus-parameter mismatch should be confirmed
against production before it is treated as a live defect.

## `manual-authorization-capture`

### Claim

> **Claim.** A USD 10.99 checkout under manual capture leaves exactly one uncaptured authorization at the real provider, and one merchant capture action captures that same intent and charge, for that amount and currency, exactly once.
>
> **Falsified by.** `C1` observing an authorization that is captured without the merchant action, captured twice, or captured against a different intent, charge, amount, or currency.

### Fixed run contract

| Contract item | Fixed value |
|---|---|
| Intended selector | Proposed future selector; it does not exist yet: `pnpm exec playwright test plugins/woocommerce/tests/e2e/tests/woopayments-native/provider-fidelity.spec.ts --project=chromium --grep "@fidelity:manual-authorization-capture" --workers=1 --retries=0` |
| `C1` input | Snapshot raw capture mode, set manual capture, create one unique USD 10.99 product/cart, use card `4242424242424242`, and activate Place order once; then activate Capture once for the exact order. |
| Authorization outcome | Exactly one `1099 usd` PaymentIntent is `requires_capture`, its one charge is uncaptured for `1099 usd`, the order is `on-hold`, and exactly one authorization event/note exists. |
| Capture outcome | The same intent becomes `succeeded`; the same charge is captured for `1099 usd`; the order becomes `processing`; exactly one capture event/note exists; a final read proves no second capture. |
| Convergence | Poll exact intent, charge, and order IDs every 2 seconds for at most 60 seconds after authorization and again after capture; require two identical reads at each terminal state. |
| Cleanup/restoration | Restore raw capture mode and all gateway/currency/cart snapshots byte-for-byte; empty the cart and remove the run product/session. Retain the immutable order/authorization/capture graph by run ID. A second capture, changed setting, or unowned delta fails and quarantines the graph. |

### Core-side proof

- `plugins/woocommerce/tests/php/src/Internal/Payments/Providers/WooPayments/WooPaymentsProviderGatewayAdapterTest.php`: manual capture request shape, capture status normalization, exact context amount, fee-effect plan, failure preservation, and settlement exchange-rate effects.
- `plugins/woocommerce/tests/php/src/Internal/Payments/PaymentProcessingServiceTest.php`: shared capture/cancel claim, amount-sensitive idempotency, failed-capture state, and provider-success reconciliation.
- `plugins/woocommerce/tests/php/src/Internal/Payments/Providers/WooPayments/WooPaymentsOrderEffectApplierTest.php`: successful and failed capture effects.
- `plugins/woocommerce/tests/e2e/tests/woopayments-native/pilots/merchant-manual-capture.spec.ts`: exact current run composition and assertions.
- HARNESS section 3 **Financial reconciliation matrix** and **Bucket-E parity**.

### HARNESS residue and deliberate exclusions

Financial reconciliation covers capture only when an authorized fixture is actually driven, and Bucket-E cannot prove the authorization-to-capture transition sequence by itself. The runbook retains the full capture matrix. This claim deliberately excludes settings-screen presentation, capture cancellation/expiry, partial/multiple captures, methods that do not support capture, and un-driven currencies.

## `dispute-lifecycle`

### Claim

> **Claim.** Three independent USD 50.00 disputes raised by the real provider retain their exact dispute, charge, and order identities through voluntary acceptance, winning evidence, and losing evidence; each traverses the status sequence listed for its case; native applies exactly one side effect per provider event; and each dispute-created order note links to the dispute details surface for those same identities.
>
> **Falsified by.** `DP1`–`DP3` observing a dispute, charge, or order identity replaced mid-lifecycle, a skipped intermediate state, a terminal state other than the one listed, or a second or missing native side effect for any event. `DP-nav` observing a missing dispute-created order note or link, or a navigation that lands on a different dispute or order identity.

### Fixed run contract

| Contract item | Fixed value |
|---|---|
| Intended selector | Proposed future selector; it does not exist yet: `pnpm exec playwright test plugins/woocommerce/tests/e2e/tests/woopayments-native/provider-fidelity.spec.ts --project=chromium --grep "@fidelity:dispute-lifecycle" --workers=1 --retries=0` |
| Shared creation | Three independent fresh shoppers/products use provider dispute test card `4000000000000259` for one captured `5000 usd` charge each. Each produces exactly one fraudulent dispute in `needs_response`, one created event, one `on-hold` order, and matching dispute ID, charge ID, order ID, amount, currency, reason, and due date. |
| `DP1` accept | Send one close request for the exact first dispute ID. The same dispute becomes `lost`; exactly one closed event/note and one capped local dispute-refund effect apply. No evidence-submit request occurs. |
| `DP2` win | Submit one physical-product evidence payload containing the provider's exact `winning_evidence` test value with `submit=true`. The same dispute becomes `under_review` and then `won`; exactly one update and one closed-won effect apply. |
| `DP3` lose | Submit one physical-product evidence payload containing the provider's exact `losing_evidence` test value with `submit=true`. The same dispute becomes `under_review` and then `lost`; exactly one update and one replay-safe capped local dispute-refund effect apply. |
| `DP-nav` order-notice navigation | After shared creation, for each of the three disputes the merchant loads the disputed order and follows the dispute-created order-note link (`get_dispute_url`) to the dispute details surface, which must present that row's exact dispute ID and order ID. An absent notice or link fails the case loudly — no silent early return. The case records the explicit native-version expectation it runs against; its latency is bounded by the shared 180-second creation convergence. |
| Convergence | Creation polls every 5 seconds for at most 180 seconds. Each accept/submission polls the same dispute every 5 seconds for at most 600 seconds, requiring the listed intermediate/terminal sequence and two identical terminal reads. A timeout, missing intermediate state, replacement ID, or second event/effect fails without replay. |
| Cleanup/restoration | Restore raw gateway, currency, method, customer-default, and shopper-session snapshots byte-for-byte; remove run carts/products/sessions. Retain all three immutable payment/dispute graphs and redacted request/response/event journals by run ID. No dispute action is retried. Any uncertain terminal state or unowned delta quarantines that graph. |

### Core-side proof

- `plugins/woocommerce/tests/php/src/Internal/Payments/Providers/WooPayments/WooPaymentsMoneyMovementRestControllerTest.php::test_dispute_update_and_close_forward_payloads`: exact dispute ID, evidence, `submit`, metadata, close action, response enrichment, and order correlation; its close-cache tests distinguish accepted platform mutation from failure.
- `plugins/woocommerce/tests/php/src/Internal/Payments/Providers/WooPayments/Api/WooPaymentsApiClientTest.php::test_update_dispute_uses_preserved_endpoint` and `::test_close_dispute_uses_preserved_endpoint`: exact provider paths, HTTP methods, and update body fields.
- `plugins/woocommerce/tests/php/src/Internal/Payments/Providers/WooPayments/WooPaymentsEventIngestorTest.php`: `test_dispute_created_marks_order_on_hold`, `test_dispute_closed_won_completes_order`, and `test_dispute_closed_lost_creates_local_refund` prove created/won/lost vocabulary, order effects, exact charge lookup, and replay-safe lost effects.
- `plugins/woocommerce/tests/php/src/Internal/Payments/Providers/WooPayments/WooPaymentsDisputeEventHandlerTest.php`: created/closed status notes, reason/due-date/amount/currency formatting, unified identity, and replay suppression/one-side-effect behavior.
- `plugins/woocommerce/client/admin/client/woopayments/admin/test/dispute-challenge-page.test.tsx`: exact-ID evidence updates, final `submit=true`, saved evidence-file readback, provider-failure presentation, and read-only terminal state.
- `plugins/woocommerce/client/admin/client/woopayments/admin/test/money-movement-pages.test.tsx`: exact-ID acceptance yielding `lost`, `under_review` submitted-evidence state, and `won`/`lost` outcome rendering.
- HARNESS section 3 **Provider-created dispute e2e**: deterministic created-dispute `on-hold` transition, order side effects, and provider financial reconciliation.
- HARNESS section 3 **Financial reconciliation matrix** for driven dispute identity/side effects.

### HARNESS residue and deliberate exclusions

The provider-created gate explicitly excludes later dispute lifecycle states, evidence submission, browser/admin flows, payouts, and race-sensitive final order status. It contributes proof only for creation. The cited controller/API/handler/admin tests provide mocked or lower-layer proof for the later request, event, and rendering paths; `DP1`–`DP3` must join those paths to the real provider and cannot borrow the created gate's green result. This claim deliberately excludes merchant evidence durability beyond the asserted readback, provider adjudication correctness outside `winning_evidence`/`losing_evidence`, review time beyond 600 seconds, draft save/lost-update behavior, dashboard layout/copy, historical-version/cutover compatibility, payout consequences, and final order status where payment/dispute event ordering races.

## `subscription-provider-lifecycle`

### Claim

> **Claim.** Each listed signup, payment-method change, and renewal preserves the exact order, subscription, customer, token, and provider relationships across the real provider boundary, and produces the one-occurrence payment outcome listed for that case with no duplicate subscription, renewal order, intent, or charge.
>
> **Falsified by.** `S1`–`S7` observing any of those relationships replaced rather than carried forward, a payment outcome other than the one listed, a parent-order line-item composition departing from the one listed for `S1`, `S2`, or `S7`, or a duplicate object created by a single signup, change, or renewal.

### Fixed run contract

| Contract item | Fixed value |
|---|---|
| Intended selector | Proposed future selector; it does not exist yet: `pnpm exec playwright test plugins/woocommerce/tests/e2e/tests/woopayments-native/provider-fidelity.spec.ts --project=chromium --grep "@fidelity:subscription-provider-lifecycle" --workers=1 --retries=0` |
| Shared input | Pinned active WooCommerce Subscriptions fixture; isolated tax-free/shipping-free store; USD 9.99 monthly product, one-month interval, native Card, and fresh provider customer. Paid cases use `4242424242424242`; the new-method case uses `5555555555554444`. |
| `S1` signup fee | Product has one USD 1.99 signup fee. One submit creates one USD 11.98 parent order, one active subscription, one token/method/customer graph, one `1198 usd` succeeded PaymentIntent, one captured charge, and zero renewal orders. Provider metadata identifies initial recurring payment. The parent order record carries exactly one USD 9.99 recurring line item and one USD 1.99 signup-fee line item summing to the proven `1198 usd` provider total. |
| `S2` no signup fee | Product has no signup fee. One submit creates one USD 9.99 parent order, one active subscription, one token/method/customer graph, one `999 usd` succeeded PaymentIntent, and one captured charge. The parent order record carries zero fee lines, asserting at the record level that the signup-fee component is exactly zero. |
| `S3` free-trial setup | Product has a 14-day free trial and no initial charge. One zero-total order creates one succeeded SetupIntent, one reusable provider method/Woo token, one active subscription, and zero PaymentIntents/charges. One separately journaled manual renewal uses that method for one `999 usd` succeeded/captured renewal payment. |
| `S4` change to new method | Seed the subscription with `4242`, record its exact token/method, then submit `4444` once through the change-payment flow. The subscription's recurring token changes to the exact new method; one manual off-session renewal charges only `4444` for `999 usd`. |
| `S5` select already-saved method | Seed saved `4242` and set the subscription to saved `4444`; record the before IDs, select saved `4242` once, and require the after recurring token to equal the original `4242` token and differ from `4444`. One manual off-session renewal charges only `4242` for `999 usd`. |
| `S6` renewal ownership | For one active subscription with saved `4242`, run one merchant manual renewal and one independently seeded due Action Scheduler renewal, the latter dispatched through the wp-cron loopback into `action_scheduler_run_queue` and the queue runner rather than the admin Run action. Each produces one distinct `999 usd` renewal order/intent/charge and advances its subscription once; no duplicate action, order, intent, charge, token, customer, or subscription exists. Cron timing semantics — when natural cron would fire — are not asserted. |
| `S7` multiple subscriptions | One basket holds quantity one each of two same-schedule USD 9.99 monthly subscription products, one carrying the USD 1.99 signup fee and one without. One submission creates one USD 21.97 parent order, one active subscription whose two line items are asserted by product ID, one token/method/customer graph, one `2197 usd` succeeded PaymentIntent, one captured charge, and zero renewal orders. |
| Convergence | Poll exact subscription/order/token/intent/charge IDs every 3 seconds for at most 120 seconds per phase; require two identical terminal reads and a final renewal-order/action count. |
| Cleanup/restoration | Delete run-owned products, subscriptions, renewal/parent orders, tokens, local customers, carts, actions, and browser sessions; detach run methods and restore raw gateway, currency, subscription-setting, local token/default/cache, provider attachment/default, and shopper-session snapshots byte-for-byte. Retain immutable payment objects in the redacted journal. Any run-owned subscription/action left active, baseline mutation, or duplicate financial object fails cleanup. |

### Core-side proof

- `plugins/woocommerce/tests/php/src/Internal/Payments/NativeWooPaymentsGatewayTest.php`: scheduled renewal with saved token/current customer, SetupIntent add-payment-method, change-payment handling, and renewal failure/action behavior.
- `plugins/woocommerce/tests/php/src/Internal/Payments/Providers/WooPayments/WooPaymentsProviderGatewayAdapterTest.php`: merchant-initiated recurring request shape, saved-token resolution, recurring outcome/token plan, and zero-total SetupIntent transport.
- `plugins/woocommerce/tests/php/src/Internal/Payments/Providers/WooPayments/WooPaymentsOrderEffectApplierTest.php`: recurring token/subscription synchronization and SetupIntent references.
- `plugins/woocommerce/tests/php/src/Internal/Payments/Providers/WooPayments/WooPaymentsTokenServiceTest.php`, `Subscriptions/WooPaymentsSubscriptionMethodPolicyTest.php`, and `Subscriptions/WooPaymentsSubscriptionAdminPaymentMethodHandlerTest.php`: renewal token identity, method policy, and subscription payment-method updates.

### HARNESS residue and deliberate exclusions

Bucket-E explicitly excludes subscription and token state. HARNESS section 3's judged runbook explicitly retains subscription renewals/token metadata, and the Bucket-C renewal scaffold is not a provider-success claim. This claim deliberately excludes 3DS/SCA, shopper/merchant UI and date formatting, product/line-item/fee-component arithmetic beyond the exact `S1`/`S2`/`S7` record-level line items and provider totals, cron timing semantics — dispatch through the wp-cron loopback is inside `S6`; when natural cron would fire is not — historical version/cutover behavior, and payment-method management presentation beyond the exact `S4`/`S5` token transition.

## `multi-currency-settlement`

### Claim

> **Claim.** Each listed order carries exactly one provider amount and currency graph; the converted money metadata WooCommerce stores equals the authoritative provider balance transaction rather than a locally recomputed figure; and a later shopper-currency change leaves the stored order and its provider graph unchanged.
>
> **Falsified by.** `M1`–`M3` observing a provider amount or currency that diverges from the order, stored conversion metadata that disagrees with the balance transaction, or a historical order mutated by a subsequent currency change.

### Fixed run contract

| Contract item | Fixed value |
|---|---|
| Intended selector | Proposed future selector; it does not exist yet: `pnpm exec playwright test plugins/woocommerce/tests/e2e/tests/woopayments-native/provider-fidelity.spec.ts --project=chromium --grep "@fidelity:multi-currency-settlement" --workers=1 --retries=0` |
| `M1` USD | Store settlement currency USD; shopper currency USD; one USD 10.99 `4242` order. Exactly one `1099 usd` succeeded PaymentIntent and captured charge bind the exact order. |
| `M2` EUR conversion | Store settlement currency USD; shopper currency EUR; one EUR 12.34 `4242` order. Exactly one `1234 eur` succeeded PaymentIntent and captured charge bind the order; Woo stored exchange rate, fee, net, and USD settlement amount equal the exact provider balance-transaction fields. The provider response supplies the numeric rate; equality, not a guessed rate, is asserted. |
| `M3` immutability | After `M2`, switch the same shopper session from EUR to USD and hard-reload order receipt/My Account. The `M2` order ID, `1234 eur` total, intent/charge IDs, and stored/provider settlement graph remain byte-identical to their `M2` snapshots. No new order or charge occurs. |
| Convergence | Poll exact order/intent/charge/balance-transaction IDs every 2 seconds for at most 60 seconds; require two identical terminal reads before and after `M3`. |
| Cleanup/restoration | Restore raw enabled-currency set, store currency, shopper-session/customer currency, gateway, customer-default, cart, and local token/default snapshots byte-for-byte; empty carts and remove run products/sessions. Retain immutable financial graphs by run ID. Any changed original currency/customer/configuration, second order/charge, or unowned delta fails cleanup. |

### Core-side proof

- `plugins/woocommerce/tests/php/src/Internal/Payments/Providers/WooPayments/MultiCurrency/WooPaymentsCurrencyRateProviderTest.php`: usable-account availability, provider rate delegation, and supported-currency responses.
- `plugins/woocommerce/tests/php/src/Internal/Payments/Providers/WooPayments/WooPaymentsProviderGatewayAdapterTest.php`: settlement exchange-rate effect planning and order-currency method validation.
- `plugins/woocommerce/tests/php/src/Internal/Payments/Providers/WooPayments/WooPaymentsCheckoutAjaxControllerTest.php::test_update_order_status_persists_settlement_exchange_rate_meta_for_converted_currency_charge`.
- HARNESS section 3 **Financial reconciliation matrix** for charge amount/currency, fee/net, and exchange-rate metadata, plus **Bucket-E parity** for sampled final order facts.

### HARNESS residue and deliberate exclusions

Financial reconciliation covers only dimensions on driven orders and therefore requires explicit USD/non-default/converted fixtures; Bucket-E does not cover options, shopper session currency, or transitions. The runbook retains the full multi-currency matrix. This claim deliberately excludes settings/onboarding, switcher/UI formatting, payment-method visibility/eligibility, transaction-page navigation/link compatibility, historical migration/cutover, and refunds (covered by `refund-settlement`).

## Excluded: `3ds-authentication`

Exactly 12 rows are enumerated with treatment `excluded`. The corrected rationale is narrower than the decision's recorded premise: Core has substantial mocked/lower-layer customer-action proof in classic JS, Blocks JS, gateway, codec, processing, adapter, AJAX-controller, and error-message tests. What it does not have is a native provider/browser journey that invokes and proves a real challenge. A thin provider check therefore has no assembled authentication journey to join and cannot discharge these rows.

HARNESS section 3 reinforces the boundary: A4aq and the judged checkout matrix explicitly leave 3DS/SCA outside deterministic coverage. The two Site Editor 3DS rows remain conventional because their distinguishing residual is theme-specific overlay/focus/stacking behavior; they are not folded into the 12-row generic authentication exclusion.

## Thin-proof finding

No second excluded fidelity family was required. `dispute-lifecycle` remains the thinnest accepted family, but the current controller/API client, event-ingestor/handler, and admin tests provide substantial lower-layer proof for exact update/close requests, created/won/lost event effects, replay suppression, evidence submission, acceptance, and status rendering. Its provider claim is limited to `DP1`–`DP3` plus the `DP-nav` navigation join and their 180/600-second waits and explicitly does not promote HARNESS's created-only gate into later-lifecycle proof. Dispute drafts and browser/history behavior stay conventional or fail Condition 2; order-notice navigation now sits inside `DP-nav` rather than outside the claim. `redirect-method-provider-outcome` is one proposed matrix invocation, not a claim that one method represents all methods; every `A1`–`A5` fixture and the `A1b` Blocks twin must run or its associated result remains open.
