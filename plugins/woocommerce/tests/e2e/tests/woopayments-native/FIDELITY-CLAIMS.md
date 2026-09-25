# Provider-fidelity claims

These claims implement the 2026-08-08 decision before any new provider execution. A “single run” means one authorized, isolated, zero-retry family-suite invocation with one worker; a family suite may drive its explicitly named fixture matrix. Every result must retain public-safe exact-order/provider correlation and prove its own fixture preconditions and cleanup. Passing a family claim does not discharge every family member: `fidelity-partition.tsv` applies Condition 2 from each row's exact `residual_risk`.

Every family below is implemented and has been run against the real provider. Each `Intended selector` row is the exact invocation that collects that family, and the case count in it is measured from `--list` rather than counted off the contract table — the two differ where one contract item is driven by more than one case, or where one case drives two items. The dated corrections inside a family record where a run contradicted what that family asserted before it ran; they are kept as falsifications, not tidied away.

Each family states its claim in two parts. **Claim** is a proposition about what the real provider does and how native answers it — the thing a run establishes, and the thing a closure cites. **Falsified by** names how a run says it is wrong, so the statement can fail out loud rather than merely go unproven. The fixed contract below each pair is the oracle that makes the falsifier binding; a claim whose oracle is too weak to reject its falsifier is not established by a green run.

## Programme partition

| Family/treatment | Total | Dischargeable | Not dischargeable | Excluded | Not applicable |
| --- | ---: | ---: | ---: | ---: | ---: |
| `basic-card-charge` | 7 | 5 | 2 | 0 | 0 |
| `card-decline-vocabulary` | 18 | 14 | 4 | 0 | 0 |
| `saved-token-lifecycle` | 7 | 7 | 0 | 0 | 0 |
| `redirect-method-provider-outcome` | 9 | 8 | 1 | 0 | 0 |
| `refund-settlement` | 11 | 10 | 1 | 0 | 0 |
| `manual-authorization-capture` | 1 | 1 | 0 | 0 | 0 |
| `dispute-lifecycle` | 4 | 4 | 0 | 0 | 0 |
| `subscription-provider-lifecycle` | 12 | 11 | 1 | 0 | 0 |
| `multi-currency-settlement` | 8 | 8 | 0 | 0 | 0 |
| `multi-currency-payment-method-eligibility` | 2 | 2 | 0 | 0 | 0 |
| `3ds-authentication` (excluded) | 12 | 0 | 0 | 12 | 0 |
| `conventional` | 40 | 0 | 0 | 0 | 40 |
| **Programme** | **131** | **68** | **11** | **12** | **40** |

Scope is the 130 `PILOT-NATIVE-READINESS` rows plus one row pulled in from the `PILOT-PROVIDER-EXECUTION-BUDGET` gate. That row — `shopper-checkout-purchase.spec.ts:53 › Carding protection true › using a basic card` — was never blocked on native readiness, only on provider budget, and the `B1p` twin drives it inside a run that is already authorized. Pulling it in is a deliberate scope widening, not a correction to the readiness-gated derivation.

The partition derives ten runnable fidelity families, close to the decision's “about nine” estimate, and reproduces exactly 12 excluded `3ds-authentication` rows. It does not reproduce the estimate of roughly 79 dischargeable rows: exact Condition 2 containment yields 70 — 52 at first derivation, raised to 65 by the 2026-08-10 pre-run claim extensions recorded in `DECISIONS.md`, to 67 when the separate multi-currency payment-method eligibility family closed, to 68 when M2 gained its exact authorized transaction-details observation, and to 70 when service-layer reconciliation plus provider-free My Account proof closed the two historical-money residues. The estimate of 79 turns out to be the size of the fidelity population itself, so it assumed every family member would discharge; the decision's own text anticipated that some would not. The 9 that do not retain journey, UI, historical, configuration, coexistence, recovery, or timing residue outside their fixed contract. The 40 conventional rows may consume provider data, but their literal residual is not reducible to one provider-fidelity claim.

Six of those 68 discharge only because the `basic-card-charge` and `redirect-method-provider-outcome` contracts drive card-testing protection on as well as off — the five original twins plus the FSE twin `B1pf`. Protection enforcement is core-side and already proven there; what the protection-on twins add is that a token-bearing submission still settles to the same provider graph, and that a tokenless one produces no provider object at all. An earlier revision of this file excluded protection enforcement from both families and left the five original rows open; the exclusion was withdrawn once it was established that the existing `card-testing-protection.ts` driver can force and byte-restore the setting.

## Closure citation

An optional schema-v2 closure field, `fidelity_claim: "<family-slug>"`, cites the corresponding family section in this file. The existing local validator requires the cited row in `fidelity-partition.tsv` to use treatment `fidelity`, the exact same family, and Condition 2 verdict `dischargeable`. Historical and conventional closures without this field remain valid.

## `basic-card-charge`

### Claim

> **Claim.** One shopper checkout submission through native with provider test card `4242424242424242` produces exactly one PaymentIntent and one captured charge at the provider for USD 10.99, correlated to one run-owned Woo order that reaches `processing` or `completed`, and creates no second intent, charge, capture, order, or reusable payment method. Card-testing protection changes only whether native admits the submission: with protection on, a submission carrying a valid session token settles to that same graph, and one carrying no token never reaches the provider at all.
>
> **Falsified by.** `B1` observing a provider record whose identity, amount, currency, terminal status, or cardinality departs from that graph — including a duplicate object created by a single submission. `B1p` observing a token-bearing protection-on submission that settles to a different graph than `B1`, or a tokenless submission that produces any provider object. `B1c` observing a post-coupon local order total other than USD 10.99, an order that still carries a coupon line or a non-zero discount, or a provider graph departing from `B1`'s. `B1f` observing the FSE-surface submission departing from `B1`'s graph, or `B1pf` observing either of its submissions departing from the `B1p` outcomes on that surface.

### T.1 batch 1 narrowing (2026-09-25) — `B1p`, `B1c`, `B1f`, `B1pf` moved below the browser

Per the N-109 lowest-honest-layer audit, `B1p`, `B1c`, `B1f` and `B1pf` are retired from this file's browser suite; `B1` alone remains, trimmed to what only a browser and the real provider prove (see its `Falsified by` clause above and the trimmed `B1` row below). The rows below this note are kept as the falsifiable record of what those four cases established when they last ran; they are no longer collected by the selector. Their surviving assertions and new owners:

- `B1p` (card-testing-protection admission/refusal): `NativeWooPaymentsGatewayTest::test_process_payment_admits_matching_fraud_prevention_token_when_enabled` and the split-gateway data-provider row on `test_process_payment_rejects_invalid_fraud_prevention_token_when_enabled`; token exposure/injection is the new Jest case `'adds the fraud-prevention token before submitting a redirect split-gateway classic checkout'` in `client/legacy/js/frontend/test/woopayments-checkout.js`. Order status after a refusal is not asserted (client marks the order `failed`, native leaves it `pending`: finding F2, open for Task T.7).
- `B1f` (FSE surface, protection off): retired outright. No theme-dependent card-payment code path exists in `src/Internal/Payments`; the money graph is identical under any theme and is `WooPaymentsProviderGatewayAdapterTest::test_single_card_checkout_without_save_matches_11_1_request_shape`.
- `B1c` (coupon add/remove): retired outright. The coupon/total arithmetic is WooCommerce core cart logic with no payments-side owner. The Payment Element's setup/payment mode is decided from the page-load cart total on both runtimes — client `client/checkout/blocks/payment-elements.js:45` (`getUPEConfig('cartTotal')`) and native `WooPaymentsCheckoutBridge.php:470`/`index.js:514-516` — so neither runtime switches mode later regardless of a subsequent coupon change; this is not a native-specific gap.
- `B1pf` (FSE protection-on twin): both halves above apply; no new case needed beyond them.

### Fixed run contract

| Contract item | Fixed value |
| --- | --- |
| Intended selector (superseded 2026-09-25, was: collects exactly the five cases below) | `WCPAY_RUNTIME=native pnpm --dir plugins/woocommerce test:e2e:with-env woopayments-native --project=woopayments-native-provider --workers=1 --retries=0 --grep "@fidelity:basic-card-charge"`, which now collects exactly one case, `B1`, from `plugins/woocommerce/tests/e2e/tests/woopayments-native/shopper/provider-fidelity-basic-card.spec.ts`. |
| `B1` input | Fresh guest shopper session on the native Blocks checkout, and the provider customer native creates for the order; one unique run-owned virtual product priced USD 10.99; cart contains only quantity one of that product and nothing else; native Card; provider test card `4242424242424242`, expiry `02/45`, CVC `424`; one Place order activation. The shopper is a guest rather than a signed-in customer because the protection-on twins have to read the WooCommerce *guest* session token native issues, and every case here must be the same shopper shape for "repeat `B1`" to mean anything. The expiry and security code are the ones the shared Blocks and Classic checkout drivers fill; neither carries provider meaning beyond being well-formed. |
| `B1` required outcome (trimmed 2026-09-25) | Exactly one run-owned Woo order, one PaymentIntent for `1099 usd` in `succeeded`, one captured charge for `1099 usd`, and one capture occurrence; order is `processing` or `completed`; the provider charge is a Visa ending `4242`. Whether the provider customer holds an attached payment method, cart-emptying, and the order's total/coupon lines moved below the browser (see the T.1 batch 1 note above) or are WooCommerce core behavior; they are no longer asserted here. |
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
- Card-testing protection is enforced entirely core-side, before the provider boundary: `NativeWooPaymentsGatewayTest::test_process_payment_rejects_invalid_fraud_prevention_token_when_enabled` (named for rejecting *before creating a payment context*, now a card+split-gateway data provider), `::test_process_payment_skips_fraud_prevention_token_check_when_disabled`, `::test_process_payment_admits_matching_fraud_prevention_token_when_enabled` (T.1 batch 1), and `WooPaymentsFraudPreventionServiceTest::test_verify_token_accepts_exact_session_token_only`.
- T.1 batch 1 additions: `WooPaymentsProviderGatewayAdapterTest::test_single_card_checkout_without_save_matches_11_1_request_shape` (request shape without a save request); the Jest case `'adds the fraud-prevention token before submitting a redirect split-gateway classic checkout'` in `client/legacy/js/frontend/test/woopayments-checkout.js` (card-testing token injection for split gateways, formerly K8).

### HARNESS residue and deliberate exclusions

Bucket-E is final-state-only, sampled-order evidence and excludes transition sequence plus customer/token/session/option state. Financial reconciliation covers only dimensions present on the driven order. A4aq proves only its named card-surface structure and browser-operability checks, not provider success. This claim deliberately excludes the Site Editor theme's 3DS overlay/presentation behavior (retained by the conventional Site Editor 3DS rows), same-page decline recovery, pay-for-order or historical cutover, 3DS/SCA, and pixel/copy parity.

Surfaces are not interchangeable here and no case may be read as evidence for one it does not drive. `B1`, `B1c`, `B1f` and `B1pf` drive the native Blocks checkout; `B1p` drives the Classic shortcode checkout. Two combinations are therefore outside the claim rather than merely unmentioned: the Classic surface under the activated block theme, and the Blocks surface under protection with the store's own theme. So is the signed-in shopper — every case here is a guest, for the reason `B1`'s input records.

`B1p` and its FSE twin `B1pf` carry the same eligibility boundary as the redirect family's protection-on twins. The target account reports `card_testing_protection_eligible: false`, so the driver asserts eligibility into the local account cache. Both twins establish that native enforces protection at its own boundary and that a token-bearing card submission still settles correctly at the provider; they establish nothing about whether the provider grants this account the capability. Provisioning of the eligibility flag remains outside this claim.

## `card-decline-vocabulary`

### Claim

> **Claim.** The provider's real decline vocabulary for the five listed test cards is the code set native's error mapping and failed-transaction rate limiter are keyed on: each card returns its fixed top-level error and decline-code pair, native renders the matching semantic error and exactly one failed provider object at the surface's exact unpaid-order cardinality with no duplicate local failure effect and no charge, capture, paid order, or attached token, and the checkout limiter increments once for top-level `card_declined` and `incorrect_cvc` and not at all for `expired_card` or `processing_error`.
>
> **Falsified by.** `D-PI-*` or `D-SI-*` observing a card that returns a different code pair, a code native maps to a different semantic error, a duplicate local failure effect from one submission, any success-side object, or a limiter delta departing from the allowlist Core's tests fix.

### T.1 batch 2 narrowing (2026-09-25) — all nine `D-PI-*` checkout dispatches moved below the browser

Per the T.1 provider-family audit, the nine `D-PI-*` checkout dispatches (Classic and Blocks, all five code pairs) are retired from this file's browser suite; `D-SI-*` is left for the next batch (superseded by the T.1 batch 3 narrowing below, which retires it too). One provider-backed decline-and-retry smoke for the whole family survives at `shopper/provider-fidelity-card-recovery.spec.ts:136`, driven by `runBlocksDeclineRecoverySmoke()`/`validateBlocksDeclineRecoverySmoke()` in `utils/woopayments-native/drivers/card-recovery.ts`: the first attempt (card `4000000000000002`) answers Store API HTTP 400 with `Error: Your card was declined.`, shown and announced in `#a11y-speak-assertive`; one provider read of the failed intent (`requires_payment_method`, `card_declined` + `generic_decline`, 1001 usd); the retry (card `4242424242424242`) pays the same draft order; and exactly one captured charge and one paid order across both attempts. Timeline ordering, cart-line identity, control focus and frame markers are not part of this trimmed claim; the existing `validateBlocksDeclineRecovery()` and its `card-recovery.test.ts` coverage, left untouched, still carry those. The rows below this note are kept as the falsifiable record of what the nine dispatches established when they last ran; they are no longer collected by the selector. Their surviving assertions and new owners, all cited from WooPayments 11.1.0 and the recorded local-platform envelope REC-1 (`Fixtures/rec-1-intention-declines.json`):

- Decline-envelope mapping (error code, wrapped message, declined PaymentIntent id kept) for all five code pairs, and the platform's own decline code, only as it surfaces through the mapped shopper message: `WooPaymentsProviderGatewayAdapterTest::test_native_charge_decline_envelope_maps_each_card_code`, a five-row data provider fed by REC-1's real HTTP bodies rather than hand-written fixtures. The same test also asserts the `allow` fraud meta box for all five (`card_error`) and the platform's `seller_message` in the merchant note for the two `card_declined`-coded pairs (client `api:2910-2914`).
- The shopper message catalog, including the observed mirrored-decline-code and absent-decline-code cases: `WooPaymentsErrorMessagesTest::test_get_shopper_message_maps_provider_decline_vocabulary`.
- Order persistence — status exactly `failed`, the declined intent id kept, exactly one failed-payment note, and the `allow` fraud meta box for a `card_error` decline: `PaymentProcessingServiceTest::test_woopayments_card_decline_fails_order_with_intent_note_and_allow_meta`. This is not the card-testing/rate-limiter refusal path (finding F2); a plain checkout decline matches the client here.
- The `expired_card` no-bump row on the checkout limiter allowlist: `NativeWooPaymentsGatewayTest::test_process_payment_does_not_bump_failed_transaction_rate_limiter_for_other_errors`'s `'expired card'` data row.
- Retry not blocked by a declined intent already attached to the order, and a failed same-cart session order not redirecting a retry: `WooPaymentsDuplicatePaymentPreventionServiceTest::test_check_payment_intent_attached_to_order_succeeded_rejects_invalid_status_or_order_ownership`'s `'requires payment method for current order'` row and `test_check_against_session_processing_order_returns_null_for_mismatches`'s `'same cart hash with failed session order'` row.

### T.1 batch 3 narrowing (2026-09-25) — the five `D-SI-*` My Account cases moved below the browser, and this family's spec file is retired

Per the T.1 provider-family audit, the five `D-SI-*` My Account SetupIntent dispatches are retired from this file's browser suite. With no case left in `plugins/woocommerce/tests/e2e/tests/woopayments-native/shopper/provider-fidelity-card-declines.spec.ts`, the file itself is deleted; this family's only surviving browser proof is the decline-and-retry smoke batch 2 carved out at `shopper/provider-fidelity-card-recovery.spec.ts:136`, which carries the sibling `@fidelity:card-decline-recovery` tag rather than this family's own. The rows below this note are kept as the falsifiable record of what the five `D-SI-*` dispatches established when they last ran; they are no longer collected by any selector. Their surviving assertions and new owner, cited from the recorded local-platform envelope REC-2 (`Fixtures/rec-2-setup-intent-declines.json`):

- SetupIntent decline mapping for all five code pairs, the customer created via `get_or_create_customer_id_for_user` before the transport call, and zero saved tokens: `WooPaymentsCheckoutAjaxControllerTest::test_create_setup_intent_localizes_card_decline_api_errors`, a five-row data provider fed by REC-2's real HTTP bodies rather than a single hand-written generic-decline case. The HTTP status is deliberately not asserted: native always answers 502 regardless of the platform's status, while the client passes the platform's own code through and maps 402 to 400 (finding F1, open for Task T.7).
- The refused-cooldown-before-any-SetupIntent-exists half of the My Account add path, which this family's rows never claimed: `WooPaymentsCheckoutAjaxControllerTest::test_create_setup_intent_refuses_inside_add_payment_method_rate_limit_without_provider_call` (see `saved-token-lifecycle`'s own batch 3 note).
- Three of Runner S's five items have no PHPUnit owner and are sanctioned drops, not silent gaps: S3 (the provider customer exists with no attached methods and no local tokens, stable for 10 seconds) is provider-only — no fake or lower-layer double can observe the real provider's attachment state; S4 (the case creates no order) is WC core's own SetupIntent-creates-no-order behavior, not a native claim; S5 (`#wcpay-core-payment-errors` rendered with `role=alert`) is shopper-facing DOM rendering, already covered below the browser by the existing Jest cases at `plugins/woocommerce/client/legacy/js/frontend/test/woopayments-checkout.js:3091` and `:3042`.
- After this batch, no live SetupIntent decline remains anywhere in this family: the only provider-backed decline still driven live is the checkout-side smoke at `shopper/provider-fidelity-card-recovery.spec.ts:136` (Runner P/B, not Runner S).

### Fixed run contract

| Contract item | Fixed value |
| --- | --- |
| Intended selector (superseded 2026-09-25, was: collects exactly the fourteen cases below; then, after T.1 batch 2, exactly the five `D-SI-*` cases) | `WCPAY_RUNTIME=native pnpm --dir plugins/woocommerce test:e2e:with-env woopayments-native --project=woopayments-native-provider --workers=1 --retries=0 --grep "@fidelity:card-decline-vocabulary"` now collects zero cases: the nine `D-PI-*` checkout dispatches moved below the browser in T.1 batch 2, and the five `D-SI-*` My Account dispatches moved below the browser in T.1 batch 3, retiring `provider-fidelity-card-declines.spec.ts` entirely. One provider-backed decline-and-retry smoke for the family survives at `shopper/provider-fidelity-card-recovery.spec.ts:136`, tagged `@fidelity:card-decline-recovery`. |
| `D-PI-generic` | Classic native checkout, USD 10.01, card `4000000000000002`; exactly one PaymentIntent for `1001 usd` ends `requires_payment_method` with top-level error `card_declined` and decline code `generic_decline`, one semantic generic-decline alert, and exactly one unpaid run-owned Woo order. |
| `D-PI-expired` | Client contracts `default::chromium::tests/e2e/specs/wcpay/shopper/shopper-checkout-failures.spec.ts:79::Shopper › Checkout › Failures with various cards › should throw an error that the card expiration date is in the past` and `default::chromium::tests/e2e/specs/wcpay/shopper/shopper-checkout-failures.spec.ts:121::Shopper › Checkout › Failures with various cards › should throw an error that the card was declined due to expired card` map to the single Classic expired-card case; physical row 108 is metadata consolidation onto that case, not another provider dispatch. The Classic and Blocks native checkout cases use USD 10.02 and card `4000000000000069`; exactly one PaymentIntent for `1002 usd` ends `requires_payment_method` with top-level error `expired_card` and decline code `expired_card`, one expired-card semantic alert, and exactly one unpaid run-owned Woo order. |
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
- `plugins/woocommerce/tests/php/src/Internal/Payments/NativeWooPaymentsGatewayTest.php`: `failed_transaction_limited_error_codes` fixes the top-level increment allowlist to `card_declined`, `incorrect_number`, and `incorrect_cvc`; `test_process_payment_bumps_failed_transaction_rate_limiter_for_decline_error_codes` and `test_process_payment_does_not_bump_failed_transaction_rate_limiter_for_other_errors` (now a data provider including `processing_error` and `expired_card`) prove the positive and negative behavior.
- T.1 batch 2 additions: `WooPaymentsProviderGatewayAdapterTest::test_native_charge_decline_envelope_maps_each_card_code` (REC-1-fed five-row decline-envelope data provider); `WooPaymentsErrorMessagesTest::test_get_shopper_message_maps_provider_decline_vocabulary`; `PaymentProcessingServiceTest::test_woopayments_card_decline_fails_order_with_intent_note_and_allow_meta`; `WooPaymentsDuplicatePaymentPreventionServiceTest::test_check_payment_intent_attached_to_order_succeeded_rejects_invalid_status_or_order_ownership`'s `'requires payment method for current order'` row and `test_check_against_session_processing_order_returns_null_for_mismatches`'s `'same cart hash with failed session order'` row.
- T.1 batch 3 addition: `WooPaymentsCheckoutAjaxControllerTest::test_create_setup_intent_localizes_card_decline_api_errors`, turned from a single hand-written generic-decline case into a REC-2-fed five-row data provider (see the narrowing note above).

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

### T.1 batch 3 narrowing (2026-09-25) — `T1b`, `T2`, `T3`, `T4` and `T5` moved below the browser; `T1` absorbs `T3`'s deletion

Per the T.1 provider-family audit, `T1b`, `T2`, `T3`, `T4` and `T5` are retired from this file's browser suite; `T1` is trimmed to the audit's retained smoke and now also drives the token's deletion itself, absorbing `T3`'s live detach proof rather than leaving deletion to a separate case. The rows below this note are kept as the falsifiable record of what the five retired cases established when they last ran; they are no longer collected by the selector. Their surviving assertions and new owners:

- The 20-second My Account cooldown refusing before any SetupIntent exists, with zero provider calls: `WooPaymentsCheckoutAjaxControllerTest::test_create_setup_intent_refuses_inside_add_payment_method_rate_limit_without_provider_call`. The HTTP status is deliberately not asserted (native 429 vs the client's mapped 400; finding F1, open for Task T.7).
- Paying with an already-saved token (`T2`) creating no second token: `WooPaymentsOrderEffectApplierTest::test_saved_token_effects_attach_selected_token`'s new token-count assertion; token resolution to its payment method and the billing-update request are existing `WooPaymentsProviderGatewayAdapterTest` coverage, unaffected by this batch.
- Deletion detaching the provider method (`T3`): existing `WooPaymentsTokenServiceTest::test_detaches_native_card_payment_methods_when_token_is_deleted`, RED-proven in this batch against a skip-detach mutation; `T1` below now also drives this deletion live.
- Classic and Blocks checkout-save-then-delete (`T4`, `T5`): the save flag reaching the gateway, the `setup_future_usage` off_session request, and the exactly-one token created from the intent's payment method are existing `NativeWooPaymentsGatewayTest`, `WooPaymentsProviderGatewayAdapterTest` and `WooPaymentsOrderEffectApplierTest` coverage; deletion is the same `WooPaymentsTokenServiceTest` case above, which does not distinguish a token's origin.
- `T1`'s own `expectUnrelatedTokensPreserved` assertion (an add must not remove or remap any of the shopper's other stored tokens) is a sanctioned drop, not a silent gap: nothing else owns it. The trimmed smoke's baseline-plus-one attachment check still catches a token being added to the wrong place, but a bug that left an *existing* token's stored payment method silently remapped without touching the count would no longer be caught anywhere in this family.

### Fixed run contract

| Contract item | Fixed value |
| --- | --- |
| Intended selector (superseded 2026-09-25, was: collects exactly the six cases below) | `WCPAY_RUNTIME=native pnpm --dir plugins/woocommerce test:e2e:with-env woopayments-native --project=woopayments-native-provider --workers=1 --retries=0 --grep "@fidelity:saved-token-lifecycle"`, which now collects exactly one case, `T1`, from `plugins/woocommerce/tests/e2e/tests/woopayments-native/shopper/provider-fidelity-saved-tokens.spec.ts`. |
| `T1` create (annotated 2026-09-25) | Fresh customer with recorded zero-run-token baseline submits card `4242424242424242` once through native My Account. One SetupIntent succeeds, one provider method attaches to that customer, and exactly one Woo token stores that method. The case then deletes that token itself: a fresh local read shows it absent and a fresh provider read shows its method detached, two seconds apart, absorbing what `T3` used to prove separately. This row previously also claimed "zero orders, PaymentIntents, charges, or captures"; that clause is retired in T.1 batch 3, since it is WC core's own SetupIntent-creates-no-order behavior rather than a native claim (audit §2.5), and the trimmed smoke no longer asserts it. |
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
- `plugins/woocommerce/tests/e2e/utils/woopayments-native/drivers/saved-cards.ts`: exact local/provider token identity, reuse, and detach-aware cleanup scaffolding, still driving `T1` above. T.1 batch 3 retired `plugins/woocommerce/tests/e2e/tests/woopayments-native/pilots/saved-method-cutover.spec.ts` (the cutover pilot, which drove the *plugin* runtime's My Account markup through a separate helper in the same driver): its plugin-origin token/customer tuple surviving reconciliation byte-for-byte is now `WooPaymentsCutoverReconciliationJobTest::test_reconciliation_preserves_plugin_origin_saved_card`, and that tuple reaching native checkout unchanged and settling is `NativeWooPaymentsGatewayTest::test_process_payment_reuses_plugin_origin_saved_card`; the classic-only transition claim also stays proven live at `transitions/historical-tokens.spec.ts:21`.
- `plugins/woocommerce/tests/e2e/tests/woopayments-native/shopper/classic-card-authentication.spec.ts`: the 3DS twin of `T4`'s journey, already green on the Classic save-at-checkout surface. `T4` drives the same surface through the same `PlaywrightClassicCardCheckoutBrowser`, differing only in the card and in the absence of a challenge.

### HARNESS residue and deliberate exclusions

Bucket-E explicitly excludes customer/token/subscription state, and A4aq explicitly excludes saved-method mutation; those gaps are the reason this run must query exact token/customer/provider identities. This claim deliberately excludes 3DS-authenticated token creation/use, subscription renewal semantics, historical plugin-to-native cutover, and UI copy/layout.

Save-at-checkout token creation is *not* excluded: `T4` and `T5` name it, on the Classic and Blocks surfaces respectively, because it is a different native path from the My Account SetupIntent that `T1` drives — the credential is minted from the PaymentIntent by `WooPaymentsOrderEffectApplier`, alongside an order and a charge that `T1` explicitly forbids. Neither case may be read as evidence for the other's surface, and neither may be read as evidence for `T1`'s. What remains outside the claim on those surfaces is the pay-for-order route, express and wallet saving, guest-save eligibility (retained by the guest-save smoke and its core-side proof), the Site Editor checkout surface, 3DS-authenticated saving, and the remote default-method field named in Cleanup/restoration.

## `redirect-method-provider-outcome`

### Claim

> **Claim.** For each listed redirect method, native sends the real provider the correct method, amount, currency, and return URL, and the provider reaches that case's fixed redirect or terminal state with exactly one correlated order and intent graph and no second transaction. Card-testing protection changes only whether native admits the submission: with protection on, a submission carrying a valid session token reaches the provider and settles to the same graph as the protection-off case, and one carrying no token never reaches the provider at all.
>
> **Falsified by.** `A1`–`A5` or `A1b` observing a request whose method, amount, currency, or return URL diverges, a provider state other than the one fixed for that case, or a second transaction arising from one handoff. `A2p`–`A4p` observing a token-bearing protection-on submission that reaches a different provider graph than its protection-off twin, or a tokenless submission that produces any provider object.

### T.1 batch 1 narrowing (2026-09-25) — only `A1` remains a browser case

Per the N-109 lowest-honest-layer audit, `A1b`, `A2`–`A5`, and the `A2p`–`A4p` twins are retired from this file's browser suite; `A1` (Alipay, classic checkout) alone remains, keeping the family's one provider-backed smoke. The rows below this note are kept as the falsifiable record of what those cases established when they last ran; they are no longer collected by the selector. Their surviving assertions and new owners:

- Request shape (method, minor amount, currency, `return_url`) and the requires_action intent's `STATUS_REQUIRES_REDIRECT` outcome for every method, including `A5` Klarna: `WooPaymentsProviderGatewayAdapterTest::test_charge_sends_split_redirect_method_request`, a data provider over alipay/affirm/afterpay_clearpay/bancontact/klarna. For alipay this is in addition to, not a replacement for, `A1`'s own unchanged `expectRequestedRedirect` assertion — see `A1`'s row below. The order left pending with no `_charge_id` for that outcome is owned separately by `PaymentProcessingServiceTest::test_process_checkout_returns_redirect_without_completing_order`.
- The `*_handle_redirect` → confirmation-hash mapping `A1`'s own correction (below) documents: `WooPaymentsIntentCodecTest::test_outcome_from_intention_maps_method_handle_redirect_to_confirmation_hash`.
- Redirect-return settlement (`A2`–`A4`'s "same intent, order `processing`, no token"): `WooPaymentsRedirectReturnControllerTest::test_handle_wp_confirms_redirect_method_return`, a data provider over alipay/affirm/afterpay_clearpay/bancontact (Bancontact settles under a `py_`-prefixed charge id).
- `A2p`–`A4p` card-testing-protection admission/refusal: `NativeWooPaymentsGatewayTest::test_process_payment_admits_valid_fraud_token_on_split_redirect_gateways` and the split-gateway data-provider row on `test_process_payment_rejects_invalid_fraud_prevention_token_when_enabled`. Order status after a refusal is not asserted (client marks the order `failed`, native leaves it `pending`: finding F2, open for Task T.7).
- `A1b` (Alipay on Blocks): payment-method-list presence is existing `WooPaymentsCheckoutBridgeTest.php:1104`; the order-received LPM logo/alt text is the new `WooPaymentsOrderSuccessPageTest::test_filters_lpm_payment_method_title_on_order_received_page's Alipay row`; request shape is the ADP row above.

### Fixed run contract

| Contract item | Fixed value |
| --- | --- |
| Intended selector (superseded 2026-09-25, was: collects exactly the nine cases below) | `WCPAY_RUNTIME=native pnpm --dir plugins/woocommerce test:e2e:with-env woopayments-native --project=woopayments-native-provider --workers=1 --retries=0 --grep "@fidelity:redirect-method-provider-outcome"`, which now collects exactly one case, `A1`, from `plugins/woocommerce/tests/e2e/tests/woopayments-native/shopper/provider-fidelity-redirect-methods.spec.ts`. |
| `A1` Alipay (annotated 2026-09-25) | CTP off; Alipay enabled; shopper/store currency USD; one USD 12.00 order. Request method `alipay`, amount `1200`, currency `usd`, and the run return URL; one provider redirect is followed once and the same PaymentIntent becomes `succeeded` with one captured charge. This case still asserts its own full request shape through `expectRequestedRedirect` (unchanged); `WooPaymentsProviderGatewayAdapterTest::test_charge_sends_split_redirect_method_request`'s alipay row now proves the same request shape independently, at a lower layer, so the claim no longer rests on the browser alone for it. What only this browser case proves is the client-side `alipay_handle_redirect` confirmation-hash handoff and the real Stripe return through `WooPaymentsRedirectReturnController::handle_wp`. |
| `A1b` Alipay on Blocks | Repeat `A1`'s exact proposition — method `alipay`, amount `1200`, currency `usd`, run return URL, one redirect followed once, the same PaymentIntent `succeeded` with one captured charge — driven unconditionally through the native Blocks checkout surface. The method or surface being unavailable fails the case; there is no conditional skip. |
| `A2` Affirm | CTP off; Affirm enabled; USD 100.00 order and eligible US address. Request method is `affirm`, amount `10000`, currency `usd`, and the run return URL; one provider redirect is followed once and the same PaymentIntent becomes `succeeded` with one captured charge. |
| `A3` Cash App Afterpay | CTP off; Cash App Afterpay enabled; USD 100.00 order and eligible US address. Request method is `afterpay_clearpay`, amount `10000`, currency `usd`, and the run return URL; one provider handoff is followed once and the same PaymentIntent becomes `succeeded` with one captured charge. |
| `A4` Bancontact | CTP off; Bancontact enabled; shopper/store currency EUR; one EUR 12.34 order and eligible EU address. Request method is `bancontact`, amount `1234`, currency `eur`, and the run return URL; one provider redirect is followed once and the same PaymentIntent becomes `succeeded` with one captured charge. |
| `A5` Klarna handoff | Row 90 (`default::chromium::tests/e2e/specs/wcpay/shopper/klarna-checkout-purchase.spec.ts:57::Klarna Checkout › allows to use Klarna as a payment method`): Klarna enabled; USD 100.00 order and eligible US address. Request method is `klarna`, amount `10000`, currency `usd`, and the run return URL; the same PaymentIntent returns `requires_action` with one HTTPS provider redirect. The original checkout page makes exactly one top-level navigation to that exact URL, with no popup or second navigation, then stops before hosted authorization with zero charge/capture. |
| `A2p`, `A3p`, `A4p` protection-on twins | Repeat `A2`, `A3` and `A4` with card-testing protection on, driven through the existing `utils/woopayments-native/drivers/card-testing-protection.ts` controller: it snapshots `wcpay_account_data` and `wcpaydev_force_card_testing_protection_on` as raw rows, forces `card_testing_protection_eligible` true, and restores both byte-for-byte with a verified `rowsMatch` read, quarantining on mismatch. Each twin submits once with a valid session token and must reach the same method, amount, currency, terminal provider state, and single-charge graph as its protection-off case. Each twin also submits once with the session token absent and must produce zero provider objects, zero paid order, and one native rejection. Every twin restores the original protection state, including the recorded original effective value, before the next case. |
| Convergence | For `A1`–`A4` and the `A2p`–`A4p` token-bearing submissions, poll the exact intent/charge every 2 seconds for at most 90 seconds and require two identical terminal reads; for `A5`, require the exact `requires_action` redirect response within 30 seconds. For each tokenless submission, require the native rejection and then an empty provider-object interval of at least 10 seconds for that run's customer before passing. |
| Cleanup/restoration | Before every case record raw enabled-method, CTP, enabled-currency, store-currency, shopper-session currency, customer, attachment/default, and cart state. After the case restore every raw value byte-for-byte, empty the cart, and remove the run product/session. Retain immutable order/provider graphs by run ID. A changed original customer, currency, method, protection setting, or unowned delta fails cleanup. |

### Core-side proof

- `plugins/woocommerce/tests/php/src/Internal/Payments/PaymentProcessingServiceTest.php`: redirect outcomes, non-Stripe redirect providers, and asynchronous providers without card data.
- `plugins/woocommerce/tests/php/src/Internal/Payments/Providers/WooPayments/WooPaymentsIntentCodecTest.php`: provider-redirect, asynchronous-debit, non-card title, and order-received URL mappings.
- `plugins/woocommerce/tests/php/src/Internal/Payments/Providers/WooPayments/WooPaymentsProviderGatewayAdapterTest.php`: split-method derivation, return URL, sanitized redirect, order-currency validation, and checkout-context validation.
- `plugins/woocommerce/tests/php/src/Internal/Payments/NativeWooPaymentsGatewayTest.php`: method-definition currency, account capability, amount-limit, BNPL address, and placement availability tests.
- `plugins/woocommerce/tests/php/src/Internal/Payments/Providers/WooPayments/PaymentMethods/WooPaymentsPaymentMethodRegistryTest.php`: exact `alipay`, `affirm`, `afterpay_clearpay`, `bancontact`, and `klarna` provider type IDs plus country/currency/amount eligibility.
- T.1 batch 1 additions: `WooPaymentsProviderGatewayAdapterTest::test_charge_sends_split_redirect_method_request` (request shape data provider); `WooPaymentsIntentCodecTest::test_outcome_from_intention_maps_method_handle_redirect_to_confirmation_hash`; `WooPaymentsRedirectReturnControllerTest::test_handle_wp_confirms_redirect_method_return` (redirect-return settlement data provider); `NativeWooPaymentsGatewayTest::test_process_payment_admits_valid_fraud_token_on_split_redirect_gateways`; `WooPaymentsOrderSuccessPageTest::test_filters_lpm_payment_method_title_on_order_received_page's Alipay row`.
- Card-testing protection is enforced entirely core-side, before the provider boundary: `NativeWooPaymentsGatewayTest::test_process_payment_rejects_invalid_fraud_prevention_token_when_enabled` (named for rejecting *before creating a payment context*), `::test_process_payment_skips_fraud_prevention_token_check_when_disabled`, and `::test_add_payment_method_rejects_invalid_fraud_prevention_token_when_enabled`.
- `plugins/woocommerce/tests/php/src/Internal/Payments/Providers/WooPayments/WooPaymentsFraudPreventionServiceTest.php`: session-token generation/persistence, exact-token-only verification, regeneration, and reading eligibility from the account cache.
- `plugins/woocommerce/tests/php/src/Internal/Payments/Providers/WooPayments/WooPaymentsCheckoutBridgeTest.php`: token exposure in classic checkout config and Blocks payment-method data when protection is enabled.

### Correction 2026-08-14 — the provider does not hand every redirect method back the same way, and neither runtime pretends it does

`A1`'s first passing run required four assumptions to be cleared, all of them the same
mistake: reading the generic `redirect_to_url` shape as if it were the only one. Three were
cleared on 2026-08-10 inside the test. The fourth and fifth are recorded here because they
are facts about the provider and about both runtimes, not about the harness.

**Asserted.** That for every method in this family the store answers Place order with the
hosted URL the intent names, and that the intent's `next_action` return URL is the
order-received URL on this store.

**Observed, for Alipay.** Neither holds, and the payment settles correctly anyway.

| | `redirect_to_url` methods (`A2`–`A5`) | `alipay_handle_redirect` (`A1`) |
| --- | --- | --- |
| Store's Place order answer | the hosted URL | `#wcpay-confirm-pi:{order}:{secret}:{nonce}` |
| `next_action` return URL | the merchant order-received URL | `https://pm-redirects.stripe.com/return/…` |
| Who performs the handoff | the browser, following the store's answer | the provider's own script |

**Cause of the first half.** `WooPaymentsIntentCodec::raw_next_action_redirect_url()`
returns `''` for any next-action type but `redirect_to_url`, so
`requires_confirmation_redirect()` holds and `WooPaymentsProviderGatewayAdapter` substitutes
the confirmation hash. **The WooPayments client plugin has the identical branch** at
`class-wc-payment-gateway-wcpay.php:2093-2107`: `redirect_to_url` gets the URL,
`multibanco_display_details` gets its own arm, and everything else — `*_handle_redirect`
included — falls into the same `else` that builds the same hash. Native is at parity, and
teaching native to follow `*_handle_redirect` server-side would *break* that parity rather
than restore it.

**Cause of the second half.** The provider interposes its own return hop for these methods,
and the merchant return URL native supplied is not observable on the intent at all — the
platform's PaymentIntent passthrough exposes no top-level `return_url` field, confirmed by
reading its full key set on 2026-08-14.

**Consequence for the claim.** The Claim's proposition is unchanged and still binding: the
provider is asked for the right method, amount, currency and return URL, and reaches the
fixed state with one correlated graph. What is scoped is *how* the two handoff facts are
observed, and only for methods whose next action is not `redirect_to_url`:

- The store's answer must be the confirmation hash naming this exact order.
- The intent's return URL must be the provider's own HTTPS hop, off this store.
- The run-identifying half moves to the *landed* URL, which `expectReturnedToStore` already
  asserts by origin, order ID, order key and gateway marker. For these methods that is the
  stronger oracle: it proves the shopper came back where native asked, rather than that a
  request field said they would.

Each branch carries a tripwire — an off-store answer where the hash is expected, or an
on-store return URL where the provider hop is expected — so a change in either runtime or
at the provider fails loudly and says to restore the direct assertions rather than keep the
scoped ones.

**Accepted risk.** For `*_handle_redirect` methods this family no longer proves from the
request that native handed the provider a return URL on this store. It proves that the
shopper returned to this run's own order-received page and that the payment settled there.
`A2`–`A5` are untouched and still carry the full direct read.

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
| --- | --- |
| Intended selector (superseded 2026-09-25, was: collects exactly the nine cases below) | `WCPAY_RUNTIME=native pnpm --dir plugins/woocommerce test:e2e:with-env woopayments-native --project=woopayments-native-provider --workers=1 --retries=0 --grep "@fidelity:refund-settlement"` now collects exactly one case, `R1` trimmed to audit §3, from `plugins/woocommerce/tests/e2e/tests/woopayments-native/merchant/provider-fidelity-refunds.spec.ts`; `R1v`, `R2`-`R7` and `RP` moved below the browser in T.1 batch 5a (see the narrowing note below). |
| `R1` card full | Source is one captured `4242` USD 10.99 charge; refund `1099 usd`; one provider refund becomes `succeeded`, the Woo refund stores its ID once, and order refunded total is USD 10.99. |
| `R1v` transaction view (retired 2026-09-25) | Removed from the browser suite in T.1 batch 5a; see the narrowing note below. |
| `R2` card partial (retired 2026-09-25) | Removed from the browser suite in T.1 batch 5a; see the narrowing note below. |
| `R3` foreign currency (retired 2026-09-25) | Removed from the browser suite in T.1 batch 5a; see the narrowing note below. |
| `R3v` foreign-currency transaction view (retired 2026-09-25) | Removed from the browser suite in T.1 batch 5a; see the narrowing note below. |
| `R4` Alipay (retired 2026-09-25) | Removed from the browser suite in T.1 batch 5a; see the narrowing note below. |
| `R5` Affirm (retired 2026-09-25) | Removed from the browser suite in T.1 batch 5a; see the narrowing note below. |
| `R6` Bancontact (retired 2026-09-25) | Removed from the browser suite in T.1 batch 5a; see the narrowing note below. |
| `R7` Cash App Afterpay (retired 2026-09-25) | Removed from the browser suite in T.1 batch 5a; see the narrowing note below. |
| `RP` deliberate same-key replay (retired 2026-09-25) | Removed from the browser suite in T.1 batch 5a and recorded as a platform observation, not a native claim; see the narrowing note below. |
| Cleanup/restoration | Record and restore raw gateway, method, enabled-currency, store-currency, shopper/customer currency, customer attachment/default, local token/default, and cart snapshots byte-for-byte. Remove run products/carts/sessions. Retain immutable source charges/refunds and local financial history by run ID. Any changed original customer/configuration, second refund, or unowned delta fails and quarantines the graph. |

The rows above are kept as the falsifiable record of what the nine retired cases established when they last ran; they are no longer collected by the selector.

### T.1 batch 5a narrowing (2026-09-25) — `R1v`, `R2`-`R7` and `RP` moved below the browser; `R1` trimmed to audit §3

Per `data/t1-provider-family-audit.md` §3 and §4 Batch 5, `R1v`, `R2`, `R3`, `R3v`, `R4`, `R5`, `R6`, `R7` and the `RP` same-key replay probe are retired from this file's browser suite, recorded from REC-5a (`data/rec-5a-refunds.md`, `Fixtures/rec-5a-refunds.json`, `Fixtures/rec-5a-timelines.json`, `Fixtures/rec-5a-refund-updated-event.json`). `R1` survives, trimmed to the round trip a browser alone can prove: a paid card order, a merchant-dispatched gateway refund, two stable succeeded reads of exactly one provider refund on the source charge, the Woo refund's stored identity, and the order's `refunded` status. See `DISPOSITION.tsv` rows 66-74 and `client-contract-map.tsv` rows 38-39/44-47/87/100/101/120 for the exact lower-layer owner of every moved assertion. Their surviving assertions and new owners:

- `R1v`'s and `R3v`'s semantic amount/status/reason (USD) and amount/charge-currency/status (EUR) transaction-view facts are NEW `money-movement-pages.test.tsx` cases fed unchanged by REC-5a R-b's recorded timelines. The client's own "Payment status changed to Refunded." status-change item (`map-events.js:938-974`) is native parity gap F3, left out and unowned until Task T.7 — not asserted in either direction. The client's fee line (composed from `fee_breakdown_v1`/`fee_rates` via `composeFeeString()`, `map-events.js:901-914`/`:354-430`) and its "Acquirer Reference Number (ARN) %s" label (`map-events.js:489-493`) are likewise left out and unowned until T.7, since native's own `Fee:`/`ARN:` rendering does not match either. Route/link is existing `WooPaymentsMoneyMovementRestControllerTest.php:868` and the WooPayments settings navigation test.
- `R2`'s by-order-item-ID line allocation (amount-only, two of three lines, USD 7.66 remaining) is NEW `WC_AJAX_Test::test_refund_line_items_allocates_amount_only_refund_to_two_of_three_lines_by_item_id`, driven through `PaymentProcessingService` and a `RecordingProvider`; its quantity-refund sibling (client `merchant-orders-partial-refund.spec.ts:72-75`/`:161-163`) is NEW `WC_AJAX_Test::test_refund_line_items_allocates_quantity_refund_to_two_of_three_lines_by_item_id`; the untouched-line-gets-no-line-item half is existing `WC_AJAX_Test::test_refund_line_items_amount_only_skips_untouched_items`.
- `R3`'s EUR settlement identity/status/note is the EUR row of NEW `WooPaymentsProviderGatewayAdapterTest::test_native_refund_over_fake_transport_persists_refund_identity_status_and_one_note` (REC-5a R-a, pair `eur_card_full_refund`) plus existing `WooPaymentsOrderNoteServiceTest.php`; the balance-transaction join (F9) is asserted directly in the same test against the recorded string id.
- `R4`/`R5`/`R6`'s pending-first-leg-to-succeeded settlement (K4) is proven end to end by NEW `WooPaymentsProviderGatewayAdapterTest::test_native_refund_over_fake_transport_stays_pending_until_webhook_confirms_succeeded`: the recorded REC-5a R-a pending Afterpay refund response over the fake transport, then the exact REC-5a R-c succeeded `charge.refund.updated` body through the real `WooPaymentsRefundEventHandler` (the recording carries no Alipay- or Bancontact-specific event, so the fixture proves the mechanism generically); existing `WooPaymentsProviderGatewayAdapterTest::test_refund_prefers_native_transport_when_available` and `WooPaymentsOrderEffectApplierTest::test_refund_effects_compose_compatibility_data` remain as narrower unit-level proofs of the pending leg alone. `R6`'s EUR settlement identity/status/note additionally shares `R3`'s owners above.
- `R7`'s settled-refund mechanism is the same one `R4`-`R6` cite (the K4 test's own recorded refund is this exact Afterpay charge). Its `RP` same-key replay has no client floor (F7: the client's own order-edit refund sends no idempotency key at all, `gw:2970-2976`) and is retired as a platform observation rather than a native claim — see the `RP` row below and the corrections that follow it. Key derivation/reuse is existing `PaymentProcessingServiceTest::test_two_equal_amount_partial_refunds_use_distinct_idempotency_keys` and `::test_reprocessing_same_refund_instance_reuses_idempotency_key`; the `Idempotency-Key` header transport is existing `WooPaymentsApiClientTest::test_request_lifts_idempotency_key_and_preserves_filtered_params`.
- F9 is settled by REC-5a R-a: the platform's refund `balance_transaction` is a bare string id in every recorded response, not the expanded object some native sync fixtures used; `WooPaymentsOrderEffects::balance_transaction_id()` already accepts either shape, so no code change was needed. The request body sent over the fake transport in `test_native_refund_over_fake_transport_persists_refund_identity_status_and_one_note` is compared field-by-field to the recorded request (`charge`, minor-unit `amount`, enumerated `reason`, `metadata.merchant_refund_reason`); every field matched, so no T.7 finding was needed there.

**`RP`, platform observation, not a native claim.** After the first refund had two stable `succeeded` reads, the probe disabled automatic HTTP retries and invoked the native WooPayments refund API client exactly once more with the byte-identical source charge, amount, reason, metadata, and recorded `Idempotency-Key`. On 2026-08-12 the platform refused the replay as a fresh over-refund attempt; on 2026-08-26 it instead replayed the original response (see the corrections below). Both are properties of the platform's own idempotency-key handling, not of native, which sent the key identically to the WooPayments client in both runs.

### Core-side proof

- `plugins/woocommerce/tests/php/src/Internal/Payments/PaymentProcessingServiceTest.php`: provider-success handling, distinct/equal-refund keys, `test_reprocessing_same_refund_instance_reuses_idempotency_key`, exact local-refund resolution, persisted provider metadata, and reconciliation on post-success local failure.
- `plugins/woocommerce/tests/php/src/Internal/Payments/Providers/WooPayments/Api/WooPaymentsApiClientTest.php::test_request_lifts_idempotency_key_and_preserves_filtered_params`: the refund POST sends the caller's exact `Idempotency-Key` header with the charge/body fields while keeping the key out of the JSON body.
- `plugins/woocommerce/tests/php/src/Internal/Payments/Providers/WooPayments/WooPaymentsProviderGatewayAdapterTest.php`: native refund preference, provider identity, and failed-status handling, plus (T.1 batch 5a) `test_native_refund_over_fake_transport_persists_refund_identity_status_and_one_note` (REC-5a R-a, USD and EUR, with a request-body-vs-recording comparison) and `test_native_refund_over_fake_transport_stays_pending_until_webhook_confirms_succeeded` (K4, REC-5a R-a pending Afterpay + REC-5a R-c succeeded webhook).
- `plugins/woocommerce/tests/php/src/Internal/Payments/Providers/WooPayments/WooPaymentsOrderEffectApplierTest.php`: compatibility metadata/notes after transport.
- `plugins/woocommerce/tests/php/src/Internal/Payments/Providers/WooPayments/WooPaymentsRefundEventHandlerTest.php`: synchronous refund plus webhook convergence, plus (T.1 batch 5a) `test_successful_synchronous_refund_followed_by_refund_updated_webhook_keeps_one_note` (REC-5a R-c).
- `plugins/woocommerce/tests/php/src/Internal/Payments/Providers/WooPayments/WooPaymentsEventIngestorTest.php::test_charge_refund_updated_succeeded_updates_matched_refund`: updated this batch to REC-5a R-c.
- `plugins/woocommerce/tests/php/includes/class-wc-ajax-test.php` (T.1 batch 5a): `test_refund_line_items_allocates_amount_only_refund_to_two_of_three_lines_by_item_id` and `test_refund_line_items_allocates_quantity_refund_to_two_of_three_lines_by_item_id`.
- `plugins/woocommerce/client/admin/client/woopayments/admin/test/money-movement-pages.test.tsx` (T.1 batch 5a): the recorded USD and EUR full-refund timeline cases, fed unchanged from REC-5a R-b; F3, the client's fee line, and its ARN label are left out and unowned until T.7 rather than asserted in either direction.
- HARNESS section 3 **Financial reconciliation matrix** and **Bucket-E parity**.

### HARNESS residue and deliberate exclusions

Financial reconciliation proves only the `R1`–`R7` dimensions; Bucket-E proves final refund/order facts, not the transition sequence. The HARNESS runbook still calls for the full refund matrix. Merchant-visible presentation is in scope only through `R1v` and `R3v`, and only semantically: exact copy, wording, symbol placement, locale formatting, and layout are excluded there as everywhere else. This claim deliberately excludes refund-dialog presentation, coexistence ownership across runtimes, payout behavior, and every combination not listed above.

### Correction 2026-08-26 — when the platform does replay, it replays the original response

The Task 13 regression re-run of `R7` took the *replayed* branch for the first time: the same-key replay reached the provider once and returned the same provider refund id, amount, currency and charge — no second refund on either side, which is the property the case protects. Native's transport is unchanged since the 2026-08-12 correction (the key still travels as the `Idempotency-Key` header), so the replay behaviour observed here is the platform's, not a native change, and the 2026-08-12 finding about the header-versus-parameter contract stands as a platform observation.

What the replayed branch had over-asserted: that the replayed body reads `succeeded`. An idempotent replay returns the original response, and Cash App Afterpay refunds are created `pending` and settle asynchronously, so the replayed body reads `pending` while the converged refund (already read twice as `succeeded` before the probe) is settled. The case now accepts `pending` or `succeeded` on the replayed body and keeps every identity assertion. Run record: 2026-08-26 `refund-settlement` 8/9 with `R7` failing only on this over-assertion; `R6` Bancontact passed on this run.

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

**Confirmed end to end 2026-08-12, and it is worse than a refusal.** The
reading above was inferred from code. It was then tested against the live
provider on one captured, un-refunded charge, twice, with a partial amount so
the over-refund guard could not mask the result:

| Key sent as | call 1 | call 2 | refunds created |
| --- | --- | --- | --- |
| `Idempotency-Key` header — what native and the client both do | `re_…0jqGhZrO` | `re_…0gA9BUKn` | **2** |
| `idempotency-key` body parameter | `re_…0vSc9Lim` | `re_…0vSc9Lim` | **1** |

Same charge, same client, same amount; only the transport differs. The platform
honours the key as a *request parameter* and ignores the HTTP header. It also
declares `idempotency_key` as a REST arg on its routes
(`class-base-controller.php:702`), which is the contract both callers are
missing.

So the over-refund guard is not a safety net in general — it only happens to be
one when the charge has no room left. A partial refund replayed under the same
key creates a second refund and moves money twice.

**What the case now establishes.** The property that actually protects money:
one same-key replay reaches the provider exactly once and creates no second
refund on either side — whether it is replayed or refused. The refused branch
additionally requires the refusal to name an over-refund of that exact charge,
so a refusal for any other reason still fails.

**What it no longer claims.** That idempotency is in force for refunds. On this
path it is not. `R7` refunds in full, so the over-refund guard turns the replay
into a refusal there; that is the guard's doing, not idempotency's, and it does
not generalise — see the confirmation above.

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
| --- | --- |
| Intended selector | `WCPAY_RUNTIME=native pnpm --dir plugins/woocommerce test:e2e:with-env woopayments-native --project=woopayments-native-provider --workers=1 --retries=0 --grep "@fidelity:manual-authorization-capture"`, which collects exactly the one cases below from `plugins/woocommerce/tests/e2e/tests/woopayments-native/pilots/merchant-manual-capture.spec.ts`. |
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
| --- | --- |
| Intended selector (superseded 2026-09-25, was: collects exactly the four cases below) | `WCPAY_RUNTIME=native pnpm --dir plugins/woocommerce test:e2e:with-env woopayments-native --project=woopayments-native-provider --workers=1 --retries=0 --grep "@fidelity:dispute-lifecycle"` now collects exactly one case, `DP-nav` trimmed to one dispute, from `plugins/woocommerce/tests/e2e/tests/woopayments-native/merchant/provider-fidelity-disputes.spec.ts`; `DP1`-`DP3` and `dispute-draft.spec.ts`'s draft case moved below the browser in T.1 batch 5b (see the narrowing note below). |
| Shared creation (narrowed 2026-09-25) | One fresh shopper/product uses provider dispute test card `4000000000000259` for one captured `5000 usd` charge. It produces exactly one fraudulent dispute in `needs_response`, one created event, one `on-hold` order, and matching dispute ID, charge ID, order ID, amount, currency, reason, and due date. Previously three independent disputes shared this creation step; see the narrowing note below. |
| `DP1` accept (retired 2026-09-25) | Removed from the browser suite in T.1 batch 5b; see the narrowing note below. |
| `DP2` win (retired 2026-09-25) | Removed from the browser suite in T.1 batch 5b; see the narrowing note below. |
| `DP3` lose (retired 2026-09-25) | Removed from the browser suite in T.1 batch 5b; see the narrowing note below. |
| Dispute draft save (retired 2026-09-25) | `dispute-draft.spec.ts` removed from the repository in T.1 batch 5b; see the narrowing note below. |
| `DP-nav` order-notice navigation | After shared creation, the merchant loads the disputed order and follows the dispute-created order-note link (`get_dispute_url`) to the dispute details surface, which must present the exact dispute ID and order ID. An absent notice or link fails the case loudly — no silent early return. The case records the explicit native-version expectation it runs against; its latency is bounded by the shared 180-second creation convergence. |
| Cleanup/restoration | Restore raw gateway, currency, method, customer-default, and shopper-session snapshots byte-for-byte; remove run carts/products/sessions. Retain the immutable payment/dispute graph and redacted request/response/event journal by run ID. Any uncertain terminal state or unowned delta quarantines that graph. |

The retired rows above are kept as the falsifiable record of what those cases established when they last ran; they are no longer collected by the selector.

### T.1 batch 5b narrowing (2026-09-25) — `DP1`-`DP3` and the dispute-draft case moved below the browser; `DP-nav` trimmed to one dispute

Per `data/t1-provider-family-audit.md` §2.8, §3 and §4 Batch 5, `DP1` (voluntary accept), `DP2` (winning evidence), `DP3` (losing evidence) and `dispute-draft.spec.ts`'s draft-save case are retired from this file's browser suite, recorded from REC-5b (`data/rec-5b-disputes.md`, `Fixtures/rec-5b-disputes.json`, `Fixtures/rec-5b-dispute-events.json`). `DP-nav` survives, trimmed to one dispute instead of three: a paid card checkout, the provider-raised dispute, the `on-hold` transition and created note, and the note's link reaching the native dispute details surface. See `DISPOSITION.tsv` rows 28 and 62-65, and `client-contract-map.tsv`'s dispute rows for the exact lower-layer owner of every moved assertion. Their surviving assertions and new owners:

- `DP1`'s close-forwarded-with-no-evidence request is existing `WooPaymentsMoneyMovementRestControllerTest::test_dispute_update_and_close_forward_payloads` plus a NEW assertion there that closing a dispute calls the API client exactly once (`close_dispute`, not also `get_dispute`/`update_dispute`); the wire body is NEW `WooPaymentsApiClientTest::test_close_dispute_posts_to_close_route_with_recorded_body`, which pins the recorded `{"test_mode": true}` close body (REC-5b R-d confirmed the caller passes no fields; the wire body is not literally empty) against client `includes/admin/class-wc-rest-payments-disputes-controller.php:164-167` and `api:748-757`.
- `DP1`'s and `DP3`'s capped local dispute refund is existing `WooPaymentsEventIngestorTest::test_dispute_closed_lost_creates_local_refund` (partial disputed amount) and `::test_dispute_closed_lost_creates_refund_for_each_distinct_dispute` (two distinct lost disputes on one charge each get their own refund, plus a replay of the first that must not create a third) plus NEW `::test_dispute_closed_lost_for_full_disputed_amount_refunds_order_total_with_line_items`, fed REC-5b R-e's `accept_closed_lost` body unchanged against a fixture order matched to its recorded charge ID: a full-amount dispute refunds the order total *with* the order's line items, unlike a partial one (client `os:632-661`).
- `DP2`'s and `DP3`'s evidence-forwarded-with-submit request is existing `WooPaymentsMoneyMovementRestControllerTest::test_dispute_update_and_close_forward_payloads` plus a NEW JSON-boolean `submit:true` case there; the GET-then-POST wire sequence (native's `update_dispute()` reads the dispute before posting evidence) is NEW `WooPaymentsApiClientTest::test_update_dispute_reads_dispute_then_posts_evidence_submit_and_metadata`, fed REC-5b R-d's `win_update_pre_read`/`win_update_submit` pair unchanged against client `api:689-722`.
- `DP2`'s won note with zero refunds is existing `WooPaymentsEventIngestorTest::test_dispute_closed_won_completes_order` plus a NEW `assertCount( 0, $order->get_refunds() )` there (client `os:632`, `os:692`).
- `dispute-draft.spec.ts`'s draft POST (`submit:false`, `product_description`, `__product_type` metadata) and its "Evidence saved!" feedback are existing `WooPaymentsMoneyMovementRestControllerTest::test_dispute_update_forwards_draft_evidence_clearing_fields` and Jest `dispute-challenge-page.test.tsx:811` (client `includes/admin/class-wc-rest-payments-disputes-controller.php:146-157`); its re-entry rehydration is NEW Jest `dispute-challenge-page.test.tsx` ("rehydrates a saved draft's product type and description from dispute metadata").
- REC-5b's own store observation: none of the three recorded charges had a Woo order, so native threw "Could not find WooPayments order via disputed charge ID" on every `charge.dispute.closed` event and wrote no order state. This confirms the ingestor tests must build their own fixture order matched to the recorded charge/intent IDs rather than depend on the recording having one, which is what the two new `WooPaymentsEventIngestorTest` cases above do.

**Platform observation, not a native claim.** REC-5b's forwarded-event log confirms the 2026-08-20 correction below still holds: the store received no `charge.dispute.updated` for either the won or the lost-on-evidence dispute, only `charge.dispute.created`, `charge.dispute.funds_withdrawn`/`funds_reinstated` and `charge.dispute.closed`. Nothing asserts this absence any longer, in the browser or below it; it is recorded here as what the platform does, not pinned as a requirement native must keep meeting.

### Correction 2026-08-20 — the platform never forwards `charge.dispute.updated`, so no update effect can exist

**Asserted.** `DP2` and `DP3` each required exactly one *update* effect alongside their closed effect: an order note reading "Payment dispute has been updated", written when evidence is submitted.

**Observed.** A run on 2026-08-20 drove `DP2` to its full `needs_response` → `under_review` → `won` sequence and found **zero** such notes on the order. The orders carried the created note, the `on-hold` transition, the funds-withdrawn note and the closed note — every other effect the family names.

**What established the cause.** `WooPaymentsDisputeEventHandler::process_dispute_updated()` writes that note for any supported dispute event that is not a funds movement, which means `charge.dispute.updated`. The store never receives one. The platform's `Charge_Event_Handler` forwards `charge.dispute.created`, `charge.dispute.closed`, `charge.dispute.funds_withdrawn` and `charge.dispute.funds_reinstated` to the merchant site; its `EVENT_CHARGE_DISPUTE_UPDATED` branch tracks the status transition, records it with Sift and caches the dispute, then breaks **without calling `forward_event()`**. The local Stripe listener runs unfiltered, so this is not a listener gap: the event reaches the platform and stops there.

Native is therefore at parity by construction — the WooPayments client plugin sits behind the same relay and cannot write the note either — and native's `default` branch is unreachable for the `updated` event type in production.

**Consequence for the claim (superseded 2026-09-25).** At the time, the update half of both rows was replaced by its negation plus a tripwire: each case asserted that no update note existed and said why. `DP2` and `DP3` are now retired (T.1 batch 5b narrowing above); REC-5b reconfirmed the absence, and it is recorded above as a platform observation rather than re-asserted anywhere. The closed-won, closed-lost, capped-refund, replay-safety and identity halves those cases proved are unchanged and still binding at their new PHPUnit/Jest owners.

**Accepted risk.** This family does not prove that a merchant sees any acknowledgement between submitting evidence and the dispute closing. It proves only that the submission reaches the provider and that the terminal outcome and its effects arrive.

### Core-side proof

- `plugins/woocommerce/tests/php/src/Internal/Payments/Providers/WooPayments/WooPaymentsMoneyMovementRestControllerTest.php::test_dispute_update_and_close_forward_payloads`: exact dispute ID, evidence, `submit`, metadata, close action, response enrichment, order correlation, close-dispute-only call count (T.1 batch 5b), and a JSON-boolean `submit:true` case (T.1 batch 5b); its close-cache tests distinguish accepted platform mutation from failure; `::test_dispute_update_forwards_draft_evidence_clearing_fields` for the draft-save path.
- `plugins/woocommerce/tests/php/src/Internal/Payments/Providers/WooPayments/Api/WooPaymentsApiClientTest.php` (T.1 batch 5b): NEW `test_close_dispute_posts_to_close_route_with_recorded_body` and `test_update_dispute_reads_dispute_then_posts_evidence_submit_and_metadata`, both fed REC-5b R-d recorded bodies unchanged.
- `plugins/woocommerce/tests/php/src/Internal/Payments/Providers/WooPayments/WooPaymentsEventIngestorTest.php`: `test_dispute_created_marks_order_on_hold`, `test_dispute_closed_won_completes_order` (extended T.1 batch 5b: zero refunds), `test_dispute_closed_lost_creates_local_refund`, and NEW `test_dispute_closed_lost_for_full_disputed_amount_refunds_order_total_with_line_items` (T.1 batch 5b, REC-5b R-e) prove created/won/lost vocabulary, order effects, exact charge lookup, and replay-safe lost effects.
- `plugins/woocommerce/tests/php/src/Internal/Payments/Providers/WooPayments/WooPaymentsDisputeEventHandlerTest.php`: created/closed status notes, reason/due-date/amount/currency formatting, unified identity, and replay suppression/one-side-effect behavior (extended T.1 batch 5b: the replayed-created-webhook case also pins exactly one note and the `on-hold` status).
- `plugins/woocommerce/client/admin/client/woopayments/admin/test/dispute-challenge-page.test.tsx`: exact-ID evidence updates, final `submit=true`, saved evidence-file readback, provider-failure presentation, read-only terminal state, and (T.1 batch 5b) draft rehydration on re-entry.
- `plugins/woocommerce/client/admin/client/woopayments/admin/test/money-movement-pages.test.tsx`: exact-ID acceptance yielding `lost`, `under_review` submitted-evidence state, and `won`/`lost` outcome rendering.
- HARNESS section 3 **Provider-created dispute e2e**: deterministic created-dispute `on-hold` transition, order side effects, and provider financial reconciliation.
- HARNESS section 3 **Financial reconciliation matrix** for driven dispute identity/side effects.

### HARNESS residue and deliberate exclusions

The provider-created gate explicitly excludes later dispute lifecycle states, evidence submission, browser/admin flows, payouts, and race-sensitive final order status. It contributes proof only for creation. The cited controller/API/handler/admin tests provide mocked or lower-layer proof for the later request, event, and rendering paths, fed REC-5b's recorded bodies where the case depends on the real provider wire shape. This claim deliberately excludes merchant evidence durability beyond the asserted readback, provider adjudication correctness outside `winning_evidence`/`losing_evidence`, review time beyond 600 seconds, dashboard layout/copy, historical-version/cutover compatibility, payout consequences, and final order status where payment/dispute event ordering races.

## `subscription-provider-lifecycle`

### Claim

> **Claim.** Each listed signup, payment-method change, and renewal preserves the exact order, subscription, customer, token, and provider relationships across the real provider boundary, and produces the one-occurrence payment outcome listed for that case with no duplicate subscription, renewal order, intent, or charge.
>
> **Falsified by.** `S1`–`S7` observing any of those relationships replaced rather than carried forward, a payment outcome other than the one listed, a parent-order line-item composition departing from the one listed for `S1`, `S2`, or `S7`, or a duplicate object created by a single signup, change, or renewal.

### Fixed run contract

| Contract item | Fixed value |
| --- | --- |
| Intended selector (superseded 2026-09-25, was: collects exactly the seven cases below) | `WCPAY_RUNTIME=native pnpm --dir plugins/woocommerce test:e2e:with-env woopayments-native --project=woopayments-native-provider --workers=1 --retries=0 --grep "@fidelity:subscription-provider-lifecycle"` now collects exactly one case, `S6` trimmed to its merchant-renewal half, from `plugins/woocommerce/tests/e2e/tests/woopayments-native/shopper/provider-fidelity-subscriptions.spec.ts`; `S1`-`S5` and `S7` moved below the browser in T.1 batch 4 (see the narrowing note below). |
| Shared input | Pinned active WooCommerce Subscriptions fixture; isolated tax-free/shipping-free store; USD 9.99 monthly product, one-month interval, native Card, and fresh provider customer. Paid cases use `4242424242424242`; the new-method case uses `5555555555554444`. |
| `S1` signup fee | Product has one USD 1.99 signup fee. One submit creates one USD 11.98 parent order, one active subscription, one token/method/customer graph, one `1198 usd` succeeded PaymentIntent, one captured charge, and zero renewal orders. Provider metadata identifies initial recurring payment. The parent order record carries exactly one line item — the product's own, at USD 11.98 — and no fee line, because Subscriptions charges a signup fee by raising the product's price for the initial payment. See the 2026-08-20 correction. |
| `S2` no signup fee | Product has no signup fee. One submit creates one USD 9.99 parent order, one active subscription, one token/method/customer graph, one `999 usd` succeeded PaymentIntent, and one captured charge. The parent order record carries a single USD 9.99 product line, asserting at the record level that no signup fee was charged. The absent fee line is not the discriminator — see the 2026-08-20 correction. |
| `S3` free-trial setup | Product has a 14-day free trial and no initial charge. One zero-total order creates one succeeded SetupIntent, one reusable provider method/Woo token, one active subscription, and zero PaymentIntents/charges. One separately journaled manual renewal uses that method for one `999 usd` succeeded/captured renewal payment. |
| `S4` change to new method | Seed the subscription with `4242`, record its exact token/method, then submit `4444` once through the change-payment flow. The subscription's recurring token changes to the exact new method; one manual off-session renewal charges only `4444` for `999 usd`. |
| `S5` select already-saved method | Seed saved `4242` and set the subscription to saved `4444`; record the before IDs, select saved `4242` once, and require the after recurring token to equal the original `4242` token and differ from `4444`. One manual off-session renewal charges only `4242` for `999 usd`. |
| `S6` renewal ownership | For one active subscription with saved `4242`, run one merchant manual renewal and one independently seeded Action Scheduler renewal, the latter completed by the Action Scheduler queue runner off the subscription's own near-future schedule rather than by the admin Run action or any hand-fired hook. The seed dates the next payment just ahead of the clock — WooCommerce Subscriptions never schedules a past-dated action — and the driver keeps dispatching the wp-cron loopback so a dormant store converges; which loopback enqueues the winning queue iteration is unrecorded by Action Scheduler and not asserted. See the 2026-08-20 correction. Each renewal produces one distinct `999 usd` renewal order/intent/charge and advances its subscription once; no duplicate action, order, intent, charge, token, customer, or subscription exists. Cron timing semantics — when natural cron would fire — are not asserted. |
| `S7` multiple subscriptions | One basket holds quantity one each of two same-schedule USD 9.99 monthly subscription products, one carrying the USD 1.99 signup fee and one without. One submission creates one USD 21.97 parent order, one active subscription whose two line items are asserted by product ID, one token/method/customer graph, one `2197 usd` succeeded PaymentIntent, one captured charge, and zero renewal orders. The parent order record carries two product lines and no fee line: USD 11.98 on the product that has the signup fee and USD 9.99 on the one that does not. |
| Convergence | Poll exact subscription/order/token/intent/charge IDs every 3 seconds for at most 120 seconds per phase; require two identical terminal reads and a final renewal-order/action count. |
| Cleanup/restoration | Delete run-owned products, subscriptions, renewal/parent orders, tokens, local customers, carts, actions, and browser sessions; detach run methods and restore raw gateway, currency, subscription-setting, local token/default/cache, provider attachment/default, and shopper-session snapshots byte-for-byte. Retain immutable payment objects in the redacted journal. Any run-owned subscription/action left active, baseline mutation, or duplicate financial object fails cleanup. |

### Correction 2026-08-20 — a signup fee is not a fee line, and no runtime decides that

**Asserted.** `S1` required the parent order record to carry one USD 9.99 recurring line item *and* one USD 1.99 signup-fee line item summing to `1198 usd`. `S7` required one fee line for the one product in the basket that carries a fee. `S2` treated its absent fee line as the record-level proof that no signup fee was charged.

**Observed.** A run of the corrected shape on 2026-08-20 measured the parent order of a USD 9.99 product with a USD 1.99 signup fee as **one line item totalling USD 11.98, with `fee_lines` empty**. `S2`'s parent order was the same shape at USD 9.99. Both cases passed against the corrected assertions in that run.

**What established the cause.** WooCommerce Subscriptions does not add a WooCommerce fee for a signup fee. During the initial-payment calculation it raises the product's own price: `WC_Subscriptions_Cart::set_subscription_prices_for_calculation()` returns `price + sign_up_fee` while `$calculation_type` is `none`, and the only `add_fee()` calls in the plugin serve third-party recurring fees (`apply_recurring_fees()`), never signup fees. The cart line therefore carries the combined amount and the order records it as the line total.

Neither runtime participates in this. The composition is settled before any gateway is consulted, so native and the WooPayments client necessarily record the same lines. The client's own e2e originals for these rows assert nothing about line composition at all — `shopper-subscriptions-purchase-sign-up-fee.spec.ts` checks only that the payment-details screen reads `$11.98`. The fee-line requirement was an addition this family invented on a wrong model of the record, not a ported client contract.

**Consequence for the claim.** The `S1`/`S7` rows now state the shape Subscriptions produces, and the discriminating assertion moves onto the line total, where the money actually is: `S1` proves 11.98 on the product line against the 9.99 `S2` proves for the same product without a fee, and `S7` attributes 11.98 and 9.99 to their exact product IDs — stronger than an anonymous fee line, because it says *which* product the fee belongs to. `S2`'s empty `fee_lines` assertion is retained but demoted: fee lines are absent either way, so on its own it discriminates nothing.

### Correction 2026-08-20 — a due renewal action cannot be seeded from the past, and the queue runner outruns its observer

**Asserted.** `S6` required a seed that moves the subscription's next payment into the past to leave exactly one *due pending* Action Scheduler renewal action, observed as such, then dispatched through the wp-cron loopback.

**Observed.** Two family runs on 2026-08-20 falsified both halves separately. With the past-dated seed, the subscription carried the requested dates and **zero pending renewal actions** — only cancelled ones. With the corrected near-future seed, the action was armed as scheduled and then **completed by the queue runner within one poll interval of falling due**, producing the correct `999 usd` renewal order, intent and captured charge — before any due-and-pending observation and before the driver's first wp-cron dispatch.

**What established the cause.** `WCS_Action_Scheduler::update_date()` cancels the pending action and returns before scheduling when the new timestamp is not in the future — "Only reschedule if it's in the future" — so no past-dated seed can ever produce a due pending action. And Action Scheduler's async queue runner is dispatched by ordinary store traffic, the convergence poll's own reads included, so a due action is claimed within seconds; "due and still pending" is a state the platform erases faster than a 3-second poll can witness it. Action Scheduler records no dispatcher identity on the action, so which loopback — the driver's wp-cron request or the store's own async request — enqueued the winning queue iteration is not provable from the durable record.

**Consequence for the claim.** The seed now dates the next payment 45 seconds ahead and the case observes the armed action inside a near horizon that unambiguously separates it from the month-out natural schedule. The discriminating half of the row is unchanged and still binding: the exact seeded action must reach `complete` and the renewal must come from the queue runner off the subscription's own schedule, with no admin Run gesture and no hand-fired hook anywhere in the case. The dispatch channel is narrowed out of the claim as unobservable; the driver keeps dispatching the wp-cron loopback so a dormant store still converges.

### T.1 batch 4 narrowing (2026-09-25) — `S1`, `S2`, `S3`, `S4`, `S5`, `S7` moved below the browser, and `S6` trimmed to its merchant-renewal half

Per the T.1 provider-family audit, `S1`, `S2`, `S3`, `S4`, `S5` and `S7` are retired from this file's browser suite (real WooCommerce Subscriptions is not loadable in PHPUnit — `W/Fixtures/LateLoadedSubscriptions.php` is a version stub — so the conservative split is: PHPUnit proves what WooPayments itself owns — request shape, token plan/effects, the scheduled-payment handler — while renewal-order creation, scheduling, payment count, signup-fee cart math and trial dates stay WCS's and are not re-proven). `S6` survives but is trimmed to its merchant-renewal half only: one signup with a new card and one token; "Process renewal" produces one renewal order, `999 usd` succeeded/captured on the signup token's exact payment method; and the renewal's PaymentIntent is distinct from the parent order's own. The Action Scheduler half (the seeded due action completed by the queue runner) is dropped from this family's browser proof entirely — K11: real WCS scheduling is unproven below the browser, and native owns only that the base renewal hook registers once (`NativeWooPaymentsGatewayTest::test_subscription_handler_registration_is_idempotent_across_gateway_instances`) and that the scheduled handler charges the exact active saved token (`NativeWooPaymentsGatewayTest::test_scheduled_subscription_payment_uses_saved_renewal_token`); no double proves the queue runner itself completing a due action. The rows below this note are kept as the falsifiable record of what the six retired cases established when they last ran; they are no longer collected by the selector. Their surviving assertions and new owners, all existing PHPUnit tests (no new fixtures needed beyond the batch's own `_charge_id` extension):

- `S1`/`S2`/`S7`'s general request-shape parity is `WooPaymentsProviderGatewayAdapterTest::test_subscription_checkout_composition_matches_11_1_request_shape` — but that test has no signup-fee/no-fee/multi-product-specific data row: all three of its rows use the same 12.34 order total and vary only checkout-creation source and initial-vs-renewal. What it actually proves for `S1`/`S2`/`S7`: the full request equals the expected shape (amount from the order total, `capture_method` automatic, currency, customer, `payment_method_types`/`payment_method`, `setup_future_usage` off_session) and the `payment_type` recurring/`subscription_payment` initial metadata classification (client `gw:1822` amount from order total, `gw:1829` off_session for merchant-initiated, `tr:382-390` recurring classification). The specific 1198/999/2197 usd amounts, line composition (fee inside the product line for `S1`, two lines for `S7`), pending-action cardinality and the active-subscription/token graph are WooCommerce Subscriptions' own behavior with no payments-side owner; `S1`'s client e2e oracle asserts only the merchant note text ("A payment of $11.98 was successfully charged."), not a rendered cart/checkout total.
- `S3`'s zero-total SetupIntent request/outcome and empty `_charge_id` are `WooPaymentsProviderGatewayAdapterTest::test_charge_prefers_native_setup_intent_for_zero_total_checkout` and `WooPaymentsOrderEffectApplierTest::test_setup_intent_effects_persist_provider_references` (extended this batch with the `_charge_id` assertion); its renewal reuses the exact active saved token, proven by `NativeWooPaymentsGatewayTest::test_scheduled_subscription_payment_uses_saved_renewal_token`.
- `S4`'s change-to-new-method transport is `NativeWooPaymentsGatewayTest::test_process_payment_updates_subscription_payment_method_after_successful_new_method_change`; its SetupIntent is `WooPaymentsProviderGatewayAdapterTest::test_validated_subscription_change_uses_real_customer_service_without_update`; the new token attached exactly once is `WooPaymentsOrderEffectApplierTest::test_requested_token_effects_are_idempotent`; the original token staying attached and unused is `WooPaymentsTokenServiceTest::test_attaches_token_to_order`.
- `S5`'s saved-method change transport is `NativeWooPaymentsGatewayTest::test_process_payment_transports_validated_saved_method_subscription_change`; WCS's own permission to update is `WooPaymentsSubscriptionAdminPaymentMethodHandlerTest::test_update_payment_method_for_subscriptions_allows_saved_tokens`; re-appending the earlier token is `NativeWooPaymentsGatewayTest::test_update_failing_payment_method_reappends_previously_used_renewal_token` plus `WooPaymentsTokenServiceTest::test_attaches_token_to_order`/`::test_syncs_active_token_order_to_related_subscriptions`; the selected token attached with no third token created is `WooPaymentsOrderEffectApplierTest::test_saved_token_effects_attach_selected_token` plus `WooPaymentsTokenServiceTest::test_reuses_existing_customer_token`.
- All renewals across `S3`/`S4`/`S5` share the same owner for "renewal on the exact expected method": `NativeWooPaymentsGatewayTest::test_scheduled_subscription_payment_uses_saved_renewal_token`.
- Row 91 (`shopper/free-trial-3ds.spec.ts:472`) is not deleted: it is held for the card-authentication (3DS) family's own pass, which this audit did not reach (K1). It is not part of this family's fixed contract and is tracked on its own DISPOSITION row.

### Core-side proof

- `plugins/woocommerce/tests/php/src/Internal/Payments/NativeWooPaymentsGatewayTest.php`: scheduled renewal with saved token/current customer, SetupIntent add-payment-method, change-payment handling, renewal failure/action behavior, and (T.1 batch 4) subscription-creation policy leaving a reusable card method automatic.
- `plugins/woocommerce/tests/php/src/Internal/Payments/Providers/WooPayments/WooPaymentsProviderGatewayAdapterTest.php`: merchant-initiated recurring request shape, saved-token resolution, recurring outcome/token plan, and zero-total SetupIntent transport, including (T.1 batch 4) the `requires_action` SetupIntent's `si` (not `pi`) confirmation redirect.
- `plugins/woocommerce/tests/php/src/Internal/Payments/Providers/WooPayments/WooPaymentsOrderEffectApplierTest.php`: recurring token/subscription synchronization and SetupIntent references, including (T.1 batch 4) the empty `_charge_id` on a SetupIntent-only effect plan.
- `plugins/woocommerce/tests/php/src/Internal/Payments/Providers/WooPayments/WooPaymentsTokenServiceTest.php`, `Subscriptions/WooPaymentsSubscriptionMethodPolicyTest.php`, and `Subscriptions/WooPaymentsSubscriptionAdminPaymentMethodHandlerTest.php`: renewal token identity, method policy, and subscription payment-method updates.

### HARNESS residue and deliberate exclusions

Bucket-E explicitly excludes subscription and token state. HARNESS section 3's judged runbook explicitly retains subscription renewals/token metadata, and the Bucket-C renewal scaffold is not a provider-success claim. This claim deliberately excludes 3DS/SCA, shopper/merchant UI and date formatting, product/line-item/fee-component arithmetic beyond the exact `S1`/`S2`/`S7` record-level line items and provider totals, cron timing semantics — dispatch through the wp-cron loopback is inside `S6`; when natural cron would fire is not — historical version/cutover behavior, and payment-method management presentation beyond the exact `S4`/`S5` token transition.

## `multi-currency-settlement`

### Claim

> **Claim.** Each listed order carries exactly one provider amount and currency graph; the converted settlement metadata WooCommerce stores — the exchange rate and the settlement amount — equals the authoritative provider balance transaction rather than a locally recomputed figure; the stored fee and net equal what the platform delivers to the order-writing path, which is what the WooPayments client stores from the same data; the exact M2 order, PaymentIntent, charge, and balance transaction are reachable through the authorized merchant transaction route and render as `€12.34` in `EUR`; and a later shopper-currency change leaves the stored order and its provider graph unchanged.
>
> **Falsified by.** `M1`–`M3` observing a provider amount or currency that diverges from the order, a stored exchange rate or settlement amount that disagrees with the balance transaction, a stored fee or net that diverges from the figure the platform delivers, the authorized M2 transaction page showing another graph or shopper amount/currency, or a historical order mutated by a subsequent currency change.
>
> The fee-and-net clause reads as it does because of the 2026-08-13 correction below; it is deliberately narrower than the settlement clause and carries a tripwire that fires when the platform defect is fixed.

### Fixed run contract

| Contract item | Fixed value |
| --- | --- |
| Intended selector (superseded 2026-09-25, was: collects exactly the three cases below) | `M1`, `M2` and `M3` are retired from this file's browser suite in T.1 batch 4 (see the narrowing note below); `provider-fidelity-multi-currency.spec.ts` had no case left and is deleted, so this selector now collects zero cases. |
| Row 42 selector (retired 2026-09-25) | No longer applicable: `M2` no longer exists as a browser case (T.1 batch 4). |
| `M1` USD | Store settlement currency USD; shopper currency USD; one USD 10.99 `4242` order. Exactly one `1099 usd` succeeded PaymentIntent and captured charge bind the exact order. |
| `M2` EUR conversion and transaction details | Store settlement currency USD; shopper currency EUR; one EUR 12.34 `4242` order. Exactly one `1234 eur` succeeded PaymentIntent and captured charge bind the order; Woo stored exchange rate and USD settlement amount equal the exact provider balance-transaction fields. The provider response supplies the numeric rate; equality, not a guessed rate, is asserted. Stored fee and net are scoped to parity with the WooPayments client — see the 2026-08-13 correction. The same graph is then opened through the authorized merchant transaction route and its summary must show exactly `€12.34` and `EUR`, not the converted settlement amount. |
| `M3` immutability | After `M2`, switch the same shopper session from EUR to USD and hard-reload order receipt/My Account. The `M2` order ID, `1234 eur` total, intent/charge IDs, and stored/provider settlement graph remain byte-identical to their `M2` snapshots. No new order or charge occurs. |
| Convergence | Poll exact order/intent/charge/balance-transaction IDs every 2 seconds for at most 60 seconds; require two identical terminal reads before and after `M3`. |
| Cleanup/restoration | Restore raw enabled-currency set, store currency, shopper-session/customer currency, gateway, customer-default, cart, and local token/default snapshots byte-for-byte; empty carts and remove run products/sessions. Retain immutable financial graphs by run ID. Any changed original currency/customer/configuration, second order/charge, or unowned delta fails cleanup. |

### Correction 2026-08-13 — the stored fee and net are the presentment ones, and that is not native's doing

**Asserted.** `M2` required the fee and net WooCommerce stores to equal the provider balance transaction's — 87 / 1337 USD for the EUR 12.34 fixture — on the same footing as the exchange rate and settlement amount.

**Observed.** A run stored 0.75 / 11.59 EUR: the charge's `application_fee_amount` in the presentment currency. The rate and settlement amount were correct, so only the fee half of the clause failed.

**What established the cause.** The platform attaches its `fee_breakdown_v1` envelope at three points, not one. `GET /charges/{id}` returned `totals.fee = 87 usd`, `totals.net = 1337 usd`, `fx: 1 eur → 1.15396 usd`, and the order's own timeline note already read `$0.87` / `$13.37`. So the settlement figures exist and the platform reports them correctly where it expands the balance transaction.

The order-writing path does not get them. The builder derives everything from `charge.balance_transaction`, and a forwarded `payment_intent.succeeded` carries that as a bare string — Stripe does not expand sub-objects in event payloads. Every fallback in `charge_context()` then lands on the charge itself: store currency becomes the presentment currency, fee becomes the application fee, exchange rate becomes `0`. The envelope built there reports the same number the legacy inference reported, under the wrong currency label.

Both implementations take `$intent->get_charge()` at checkout — the WooPayments client at `class-wc-payment-gateway-wcpay.php:2206,2407,4237`, native through `WooPaymentsOrderEffects::transaction_fee_from_charge`. **They store the same value.** Native is at parity, and the defect is platform-side, filed as TRAPLAT-4144.

**Consequence for the claim.** The fee-and-net half of `M2` is scoped to parity: the case now asserts that the read-surface envelope carries the settlement figures, that the stored figures are the presentment ones, and — as a tripwire — that the two differ. When TRAPLAT-4144 lands the tripwire fails, which is the signal to restore the original requirement rather than to weaken the case again. The exchange-rate and settlement-amount half of the claim is unchanged and still binding.

**Accepted risk.** Between now and that fix, this family does not prove that a converted order stores the fee a merchant is actually charged. It proves only that native stores what the platform hands it, and that the platform hands the same thing to both runtimes.

### T.1 batch 4 narrowing (2026-09-25) — `M1`, `M2` and `M3` moved below the browser; `provider-fidelity-multi-currency.spec.ts` retired

Per the T.1 provider-family audit, `M1`, `M2` and `M3` are retired from this file's browser suite. REC-3 (`Fixtures/rec-3-eur-charge.json`) recorded local WPCOM's response to a 12.34 EUR card charge on a USD account: `charges.data[0].balance_transaction` arrives as an **expanded object** with `exchange_rate` on the synchronous create-and-confirm response itself (F4 resolved in native's favor — no extra `GET charge` is needed). With no case left in `provider-fidelity-multi-currency.spec.ts`, the file is deleted. Same-session Blocks re-render after a currency change and a real bank-redirect settlement in EUR are still proven live by the `multi-currency-payment-method-eligibility` family's own `EL:129` smoke. The rows below this note are kept as the falsifiable record of what `M1`-`M3` established when they last ran; they are no longer collected by any selector. Their surviving assertions and new owners:

- `M1`'s receipt currency suffix on a default-currency order is existing `MultiCurrencyExplicitPriceControllerTest` coverage; the `_wcpay_intent_currency`/`_wcpay_payment_transaction_id` meta is `WooPaymentsOrderEffectsTest::test_payment_intent_meta_projects_completed_effects`; no order-rate/default-currency meta on a default-currency order is `MultiCurrencyPriceProjectionServiceTest::test_does_not_project_order_meta_for_default_currency_orders`; no settlement rate when order and account currency are equal is NEW `WooPaymentsOrderDataServiceTest::test_get_settlement_exchange_rate_order_meta_skips_provider_rate_when_order_and_account_currencies_are_equal`.
- `M2`'s synchronous-path settlement exchange rate, charge id, transaction id, and presentment-currency fee/net (from `application_fee_amount`, EUR minor units, → 0.75/11.59 EUR) are NEW `WooPaymentsOrderEffectApplierTest::test_payment_intent_effects_persist_settlement_meta_for_converted_order`, fed by REC-3. The exact provider rate is existing `WooPaymentsOrderDataServiceTest::test_get_settlement_exchange_rate_order_meta_preserves_provider_rate_for_converted_order`; `order_exchange_rate`/`order_default_currency` meta are existing `MultiCurrencyFrontendPricesControllerTest::test_converts_free_shipping_minimums_and_persists_order_meta` and `MultiCurrencyPriceProjectionServiceTest::test_projects_order_exchange_rate_meta_for_non_default_orders`. The merchant transaction-page summary (`€12.34`, USD breakdown) is a separate, already-existing claim with its own owner, unrelated to REC-3: its own earlier M2 recording (rate 1.14667, not REC-3's 1.13905) feeds `money-movement-pages.test.tsx`'s `'renders charge gross in shopper currency and settlement amounts in balance currency'`, paired with existing `WooPaymentsMoneyMovementRestControllerTest::test_payment_detail_intent_route_preserves_shopper_and_settlement_money`; no new Jest was needed (K9).
    - The 0.75 EUR fee/net figure is checkout-time parity, not a native-only reading: the client's own checkout completion path (`gw:2206`, `attach_transaction_fee_to_order()`, `os:1741-1781`) falls back to the same `application_fee_amount` when the charge it has in hand carries no `fee_breakdown_v1` -- exactly REC-3's synchronous create-and-confirm shape. But the recorded `GET charge` response (the same fixture, F4's other half) carries `fee_breakdown_v1` with `totals.fee.amount` 85 usd, and the client re-runs `attach_transaction_fee_to_order()` from a webhook-driven capture path (`os:1681`) that may receive a charge shaped like that GET response. So the client's fee/net meta can end up overwritten to 0.85/13.37 USD after that later event, while native's `_wcpay_net`/fee meta is written once, earlier, at checkout, and never revisited in this batch's scope -- a variant of the F6 timing finding, not re-tested here.
- `M3`'s session-switch persistence is existing `MultiCurrencySelectedCurrencyControllerTest::test_updates_currency_from_url_parameter`; nothing writes order meta on a currency switch, so settlement identity is unaffected by construction. Multi-Currency's selected-currency machinery has no admin-order-total equivalent at all -- `MultiCurrencyExplicitPriceController::get_explicit_price_args()` never reads a "selected currency", only whatever currency `wc_price_args` already carries -- so the residual clause here is narrower than "selected vs. order currency": NEW `MultiCurrencyExplicitPriceControllerTest::test_admin_order_total_never_substitutes_the_store_default_for_the_given_currency` proves that formatter never substitutes the store default for whatever currency it is handed.
- The TRAPLAT-4144 tripwire from the 2026-08-13 correction above no longer has a live assertion (the browser case that carried it is gone); it is recorded here as a platform observation rather than re-created as a PHPUnit assertion, since PHPUnit only exercises native's own code against fixed fixtures and cannot observe a platform-side defect landing.

### Core-side proof

- `plugins/woocommerce/tests/php/src/Internal/Payments/Providers/WooPayments/MultiCurrency/WooPaymentsCurrencyRateProviderTest.php`: usable-account availability, provider rate delegation, and supported-currency responses.
- `plugins/woocommerce/tests/php/src/Internal/Payments/Providers/WooPayments/WooPaymentsProviderGatewayAdapterTest.php`: settlement exchange-rate effect planning and order-currency method validation.
- `plugins/woocommerce/tests/php/src/Internal/Payments/Providers/WooPayments/WooPaymentsCheckoutAjaxControllerTest.php::test_update_order_status_persists_settlement_exchange_rate_meta_for_converted_currency_charge`.
- `plugins/woocommerce/tests/php/src/Internal/Payments/Providers/WooPayments/WooPaymentsMoneyMovementRestControllerTest.php` and `plugins/woocommerce/client/admin/client/woopayments/admin/test/money-movement-pages.test.tsx`: shopper and settlement money remain distinct through REST projection and formatted transaction details.
- `plugins/woocommerce/tests/php/src/Internal/Payments/Providers/WooPayments/WooPaymentsCutoverReconciliationJobTest.php::test_reconciliation_preserves_plugin_origin_multi_currency_orders` plus `plugins/woocommerce/tests/e2e/tests/woopayments-native/shopper/multi-currency.spec.ts`: actual cutover normalization preserves exact WooPayments 11.1.0-shaped USD and EUR order tuples, and provider-free My Account renders the stored money unchanged before and after an EUR selection and hard reload.
- T.1 batch 4 additions: `WooPaymentsOrderEffectApplierTest::test_payment_intent_effects_persist_settlement_meta_for_converted_order` (REC-3-fed synchronous-path settlement meta); `WooPaymentsOrderDataServiceTest::test_get_settlement_exchange_rate_order_meta_skips_provider_rate_when_order_and_account_currencies_are_equal`; `MultiCurrencyExplicitPriceControllerTest::test_admin_order_total_never_substitutes_the_store_default_for_the_given_currency`.
- T.1 batch 6 narrowing (2026-09-25) — corrected under N-122 (2026-09-25): batch 6 retired `shopper/multi-currency.spec.ts` from the browser suite, claiming its EUR conversion and persistence assertions already had PHPUnit/Jest owners and only the My Account case needed one. An owner re-check (`data/t1-batch-6-n122-recheck.md`) found the rendered conversion on a real product page, its persistence across a real navigation, and the rendered My Account order-currency join were all unowned by the cited PHPUnit tests, which pin inputs by hand rather than exercising the request lifecycle. `shopper/multi-currency.spec.ts` is restored to the browser suite pending T.4 review; the PHPUnit/Jest owners named above remain additional lower-layer coverage alongside it, not replacements for it.
- HARNESS section 3 **Financial reconciliation matrix** for charge amount/currency, fee/net, and exchange-rate metadata, plus **Bucket-E parity** for sampled final order facts.

### HARNESS residue and deliberate exclusions

Financial reconciliation covers only dimensions on driven orders and therefore requires explicit USD/non-default/converted fixtures; Bucket-E does not cover options, shopper session currency, or transitions. The runbook retains the full multi-currency matrix. This claim deliberately excludes settings/onboarding, switcher/UI formatting outside the exact M2 transaction summary, payment-method visibility/eligibility, historical migration/cutover, and refunds (covered by `refund-settlement`).

## `multi-currency-payment-method-eligibility`

### Claim

> **Claim.** Payment-method eligibility follows the active shopper currency in both directions without changing the configured store default. A same-session USD-to-EUR switch retains Card and adds Bancontact, while a same-session EUR-to-USD switch retains Card and removes Bancontact. Each transition then pays once with the selected eligible method and leaves exactly one succeeded PaymentIntent, one captured charge, one order, and no reusable provider credential.
>
> **Falsified by.** Either transition showing a missing, duplicate, inaccessible, or hidden-but-enabled method; the configured default changing with the shopper session; the rendered checkout total and Store API cart disagreeing on currency; the selected method changing before dispatch; or the purchase producing the wrong method, amount, currency, state, order cardinality, provider cardinality, or reusable attachment.

### Fixed run contract

| Contract item | Fixed value |
| --- | --- |
| Intended selector (superseded 2026-09-25, was: collects exactly the two serial cases below) | `WCPAY_RUNTIME=native pnpm --dir plugins/woocommerce exec playwright test --config=tests/e2e/envs/woopayments-native/playwright.config.ts --project=woopayments-native-provider --workers=1 --retries=0 --grep "@fidelity:payment-method-eligibility"` now collects exactly one case, `USD to EUR` trimmed per the note below, from `plugins/woocommerce/tests/e2e/tests/woopayments-native/shopper/provider-fidelity-payment-method-eligibility.spec.ts`. |
| USD to EUR | Configured default remains USD; the same shopper cart/session changes from USD to EUR; the rendered total changes from USD to EUR; the exact accessible method set changes from Card to Card plus Bancontact; selected Bancontact creates one `1099 eur` succeeded PaymentIntent and captured charge. The store-default-still-USD read was dropped in T.1 batch 4 (WooCommerce core session state, not a native payments claim). |
| EUR to USD (retired 2026-09-25) | Removed from the browser suite in T.1 batch 4; see the narrowing note below. |
| Product and shopper | The retained case owns one fresh virtual, nontaxable product priced 10.99 and fills a Belgian billing address before both eligibility snapshots. |
| Restoration | The configured default, enabled currency, neutral conversion settings, and enabled-method set are restored through their supported settings routes. |

### T.1 batch 4 narrowing (2026-09-25) — `EUR to USD` (`EL:252`) moved below the browser

Per the T.1 provider-family audit, the EUR-to-USD case (row 9, formerly the second of two serial cases) is retired from this file's browser suite. Bancontact's removal from the availability matrix (`'USD/Bancontact after EUR' => false`) is existing `NativeWooPaymentsGatewayTest::test_gateway_availability_recalculates_for_currency_and_payment_method_definition_changes`; the card settlement half is absorbed by the retained `basic-card-charge` family smoke, since a plain 1099 usd card purchase is not method-eligibility-specific; flipping the store default to EUR was WooCommerce core session state with no native-specific claim. `USD to EUR` survives as the family's sole live smoke — it is the direction that adds a redirect method (Bancontact) rather than only removing one, so it is the harder-to-fake half of the claim; K5 notes it has only passed in read-only reconciliation, with `MC:1472` trimmed to provider graph plus admin summary as the fallback smoke if a live run cannot be reproduced.

### Core-side proof and browser residue

- `plugins/woocommerce/tests/php/src/Internal/Payments/NativeWooPaymentsGatewayTest.php` owns the one-gateway USD/Card, USD/Bancontact, EUR/Card, EUR/Bancontact, and return-to-USD availability matrix against the WooPayments client 11.1.0 currency rules (`test_gateway_availability_recalculates_for_currency_and_payment_method_definition_changes`, `test_gateway_availability_keeps_shopper_country_rules_separate_from_merchant_country_rules`).
- `plugins/woocommerce/tests/php/src/Internal/Payments/Providers/WooPayments/WooPaymentsIntentRequestBuilderTest.php::test_setup_future_usage_respects_payment_method_reusability`: no reusable method from a redirect credential.
- The retained browser case remains necessary for same-session checkout re-rendering, accessible control visibility, shopper selection, and the real order/PaymentIntent/charge graph. Lower-layer availability tests cannot prove those surfaces or effects.

## Excluded: `3ds-authentication`

Exactly 12 rows are enumerated with treatment `excluded`. The corrected rationale is narrower than the decision's recorded premise: Core has substantial mocked/lower-layer customer-action proof in classic JS, Blocks JS, gateway, codec, processing, adapter, AJAX-controller, and error-message tests. What it does not have is a native provider/browser journey that invokes and proves a real challenge. A thin provider check therefore has no assembled authentication journey to join and cannot discharge these rows.

HARNESS section 3 reinforces the boundary: A4aq and the judged checkout matrix explicitly leave 3DS/SCA outside deterministic coverage. The two Site Editor 3DS rows remain conventional because their distinguishing residual is theme-specific overlay/focus/stacking behavior; they are not folded into the 12-row generic authentication exclusion.

## Thin-proof finding

No second excluded fidelity family was required. `dispute-lifecycle` remains the thinnest accepted family, but the current controller/API client, event-ingestor/handler, and admin tests provide substantial lower-layer proof for exact update/close requests, created/won/lost event effects, replay suppression, evidence submission, acceptance, and status rendering. Its provider claim is limited to `DP1`–`DP3` plus the `DP-nav` navigation join and their 180/600-second waits and explicitly does not promote HARNESS's created-only gate into later-lifecycle proof. Dispute drafts and browser/history behavior stay conventional or fail Condition 2; order-notice navigation now sits inside `DP-nav` rather than outside the claim. `redirect-method-provider-outcome` is one proposed matrix invocation, not a claim that one method represents all methods; every `A1`–`A5` fixture and the `A1b` Blocks twin must run or its associated result remains open.
