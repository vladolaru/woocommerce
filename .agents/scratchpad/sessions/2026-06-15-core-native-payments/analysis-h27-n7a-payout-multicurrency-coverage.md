---
session: 2026-06-15-core-native-payments
type: analysis
by: codex
created: 2026-06-18 06:05
tool: source-map
target: H27 N7a payout and converted-currency coverage
reconciles:
  - staging-log.md
  - implementation-log.md
  - review-agent-findings.md
  - spec-conformance-baseline.md
  - analysis-h25-n7a-dispute-payout-multicurrency.md
  - analysis-h26-n7a-provider-dispute-e2e.md
status: draft
last_updated: 2026-06-18 06:54
---

# H27 N7a Payout and Converted-Currency Coverage

## Scope

H27 should close the remaining N7a money-safety coverage gaps that are still open after H24-H26: payout evidence and converted-currency evidence. This is gate-hardening first, not product-code churn by default. Any product change needs a source-backed runtime failure, a failing regression first, and parity against the reference local store.

The user’s performance guidance applies to this slice’s measurement posture: do not turn noisy local timings into precise claims. Use performance timings as coarse smoke and large-delta signals only, record variability honestly, and mark anything high-variance as provisional rather than gating on false precision.

## Current Findings

Payout linkage is primarily a harness-driver/oracle gap. Reference WooPayments and native Core both preserve the order-level charge and charge balance transaction IDs. The extension order service stores `_charge_id` and `_wcpay_payment_transaction_id`; native Core has equivalent charge and balance-transaction meta via `OrderPaymentStore`, `WooPaymentsEventIngestor`, and `WooPaymentsCheckoutAjaxController`. Neither runtime stores payout/deposit IDs on the order, and adding such order meta in native Core would be an architectural drift from the reference.

Provider payout membership belongs in Stripe/platform reporting evidence. The local WPCOM reference path reads payout/deposit evidence in the reverse direction, from payout/deposit to balance transactions and expanded source data, instead of assuming the WC order contains payout state. The current harness tries order -> charge balance transaction -> `balance_transaction.payout`, which is too weak for manual Test Lab payouts.

The live payout probe on the reference store produced order `513`, charge `ch_3TjWD5JAuuJ9nlT30TdJI8Ve`, and manual payout `po_1TjWDFJAuuJ9nlT3tuCqAwzR`. `financial-reconcile.sh` passed the order but only with `ok: payout state absent or not yet linked`. The expanded charge balance transaction `txn_3TjWD5JAuuJ9nlT30P3qjwPX` had no `payout` field, and `stripe balance_transactions list --payout po_1TjWDFJAuuJ9nlT3tuCqAwzR --stripe-account acct_1TjENrJAuuJ9nlT3` failed because Stripe only allows that filter for automatic transfers, not manual payouts. This means a green current reconcile run is not payout-linkage evidence.

The payout subagent independently confirmed the same shape: Dev Tools creates provider-side Stripe test-mode payouts through `Payout_Operations`, instant charges use `pm_card_bypassPending` to make funds available, the checkout simulator returns order/intent/charge IDs, but `flow-drive.sh payout` currently emits no useful order-linked payout evidence because payout rows contain `payout_id` rather than `order_id`. The subagent’s separate scratchpad note is not the source of truth for this session; its useful findings are folded here.

Converted-currency coverage is also primarily a harness-driver/setup gap, with one likely native product gap behind it. Core and reference both have order/default-currency meta writers for true converted orders: `_wcpay_multi_currency_order_exchange_rate` and `_wcpay_multi_currency_order_default_currency` are written when order currency differs from store default. Refunds should inherit those keys and `_wcpay_multi_currency_stripe_exchange_rate` when present. The provider settlement exchange-rate key is written by the reference gateway after reading the Stripe charge balance transaction exchange rate; no equivalent native writer was found yet.

The converted-currency subagent mapped the reconciler’s current coverage: it reads WC multi-currency keys and raw Stripe charge/balance data, compares provider `balance.exchange_rate` against `_wcpay_multi_currency_stripe_exchange_rate`, but does not yet fail converted orders missing `_wcpay_multi_currency_order_exchange_rate`, `_wcpay_multi_currency_order_default_currency`, `_wcpay_intent_currency`, refund status, or refund balance transaction IDs.

The live converted-currency probe did not create a true converted order. I enabled GBP on the reference store, set the first Test Lab customer’s `wcpay_currency` user meta to `GBP`, and drove the deterministic reference checkout. Order `514` charged successfully, but the order currency stayed `USD`, `_wcpay_intent_currency` stayed `usd`, all multi-currency order/settlement meta were empty, and Stripe charge/balance currency stayed `USD` with no exchange rate. The price total changed to `$40.00`, so the probe mutated pricing without exercising the selected-currency request lifecycle that makes the order itself converted. Hand-patching order meta would mask the bug and is not acceptable.

The root cause of that probe mismatch is now source-backed and locally reproduced. In WP-CLI, the reference `woocommerce_currency` and product price filters are registered, but `get_woocommerce_currency()` had already cached `USD` before the Test Lab simulator called `wp_set_current_user( 12 )`. After switching the current user, `get_woocommerce_currency()` still returned `USD` while product price conversion could read the user’s `wcpay_currency=GBP` meta directly, producing converted prices on an order whose currency remained USD. A faithful deterministic converted-currency driver must switch currency through the runtime selection path before `wc_create_order()` and clear the frontend currency cache; just setting user meta before the existing simulator is insufficient.

The source map confirms the likely native product gap once a true converted target order exists. Reference WooPayments calls `WC_Payment_Gateway_WCPay::attach_exchange_info_to_order()` after charge creation: when the store default currency equals the Stripe account default currency and the order currency differs, it fetches the charge, reads `balance_transaction.exchange_rate`, normalizes it with `WC_Payments_Utils::interpret_string_exchange_rate()`, and writes `_wcpay_multi_currency_stripe_exchange_rate`. Native Core currently records intent currency, charge ID, balance transaction ID, fee, and net in checkout/event paths, but I did not find an equivalent writer for `_wcpay_multi_currency_stripe_exchange_rate`; `WooPaymentsOrderDataService` formats the exchange rate for fee notes, not order meta.

## Implications

H27 should not claim “payout linkage complete” unless the local Stripe raw source exposes actual payout member balance transactions for the payout type the harness creates. For manual Test Lab payouts, the honest first gate can verify provider payout creation, amount/currency/status, payout balance transaction readability if available, and order charge/balance transaction preservation; it must explicitly mark per-order payout membership unverified when Stripe refuses the manual payout filter and no source-backed alternative exists.

H27 should build a real converted-currency fixture before changing product code. The fixture should select currency through supported runtime state, preferably browser/storefront checkout or a WP-CLI driver that simulates the frontend request/session lifecycle before order creation. It should not patch resulting order meta. Once a true converted reference order exists, target parity can identify whether native needs the missing `_wcpay_multi_currency_stripe_exchange_rate` writer.

The reconciler should become stricter only when the path is truly in scope. If order currency differs from default currency, it should fail when order/default exchange-rate meta is missing. If the provider balance transaction exposes `exchange_rate`, it should fail when `_wcpay_multi_currency_stripe_exchange_rate` is missing or mismatched. If provider/manual-payout linkage cannot be observed, the harness should say so directly rather than passing under a broad “money-safety complete” label.

## Open Source Checks Before Planning

- Confirm why the reference CLI deterministic driver changed price without changing order currency. Inspect reference `FrontendCurrencies`, `Compatibility::should_return_store_currency()`, selected-currency persistence, and native equivalents.
- Check whether WCPay Dev Tools can support native payout creation without conflating order linkage. If not, keep payout evidence provider-only unless there is a clean modular Dev Tools change that preserves extension compatibility.
- Decide whether the converted-currency fixture should be browser-first or a WP-CLI request-lifecycle script. Browser-first is more faithful but may be slower; CLI can be acceptable only if it exercises the same hooks and does not write final meta directly.

## Resolved Source Checks

- The reference deterministic driver mismatch is explained by selected-currency cache timing after `wp_set_current_user()`. H27 should use a new driver that calls the runtime currency selection path before order creation.
- The existing Test Lab manual payout path cannot be treated as per-order payout-membership evidence unless a source-backed provider query can map manual payout to member balance transactions. The live Stripe CLI rejected `balance_transactions list --payout` for the manual payout.
- Native has no located settlement exchange-rate meta writer equivalent to the reference gateway’s `attach_exchange_info_to_order()`. Treat this as a likely product fix only after a true converted target order fails the widened gate.

## Outcome

The converted-currency fixture is now real and deterministic for both stores. The flow drivers switch selected currency through the runtime selection services before checkout rather than patching final order meta. The converted-currency gate passed on reference order `522` and target order `220`, both GBP orders with store default USD, manual rate `0.80`, and Stripe settlement exchange rate `1.33127`.

The likely product gap was confirmed and fixed in native Core. Native now writes `_wcpay_multi_currency_stripe_exchange_rate` from the charge balance transaction on completed native charges and AJAX completions when the store default currency equals the account default and the order currency differs. Manual-capture normalization now carries the same completed-charge meta through `PaymentOutcome` so `PaymentProcessingService` can persist fee/net, charge transaction, and settlement exchange-rate meta after capture.

The account-default fallback parity gap was fixed at the source: native `WooPaymentsAccountService::get_account_default_currency()` now mirrors the WooPayments extension by falling back to lowercase `usd` when `store_currencies.default` is missing. This prevents converted-currency settlement meta from silently disappearing on incomplete account cache payloads.

Manual-capture converted-currency reconciliation passed on reference order `524` and target order `222` after capture. Both reconciliations verified charge amount/currency, captured amount, fee/net after settlement conversion, order exchange-rate/default-currency meta, intent currency, and Stripe exchange-rate meta against Stripe raw source.

WCPay Dev Tools payout operations now support the native Core runtime as well as the extension runtime. The Dev Tools change was committed in the Dev Tools repo as `80dd2af` (`fix(test-lab): support native payouts`). The native payout evidence gate now creates and reads a provider payout after reconciling the target native charge, matching reference behavior.

The payout-membership limitation remains honest and shared. Native target payout `po_1TjWogJd67Ti1EoIheBJFANi` and reference payout `po_1TjWpAJAuuJ9nlT3BS62jnxG` were created and readable, but neither provider query exposed deterministic membership for the fresh charge, so both gates exit BLOCKED for per-order manual payout linkage. H27 can claim payout creation/readability plus charge reconciliation, not full payout-membership proof.

The final review found a real exchange-rate formatting bug in the first implementation: integer interpreted settlement rates such as `10` were persisted as `1`. The fix is now covered by a RED/GREEN provider case and `format_exchange_rate()` preserves integer strings while still trimming fractional zeroes. The review also corrected missing `@since 11.0.0` annotations on new public methods.

H27 verification summary: focused Core PHPUnit passed after review fixes with 108 tests and 479 assertions; focused Dev Tools payout PHPUnit passed with 3 tests and 7 assertions; Core PHP syntax, production PHPStan, PHPCS changed-file lint, branch `lint:changes:branch`, harness syntax, harness Python compile, financial reconciliation fixtures, compare-measured-gates fixtures, converted-currency runtime gate, manual-capture converted-currency reconciliation, and reference/native payout evidence all ran. Existing unrelated Dev Tools `AccountManagerTest` and `EnvironmentTest` failures under the mounted native test environment remain outside H27 and should not be hidden. Core source/tests were committed as `4f216e015a` and changelog as `3043c12df0`; Dev Tools native payout support was committed as `80dd2af`.
