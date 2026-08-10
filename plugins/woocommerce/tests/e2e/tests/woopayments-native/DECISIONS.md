# WooPayments native contract ledger — decisions

Standing decisions that govern how the contracts in `client-contract-map.tsv` are dispositioned. Read this before interpreting the `planned_disposition` column.

Each entry records what was decided, what it displaces, and the risk accepted in choosing it. Append new decisions; do not rewrite settled ones.

## 2026-08-06 — Prove at the lowest honest layer

Where a contract can be proven either by a migrated client E2E test or by a native lower-layer test, the native lower layer is the default. End-to-end coverage is reserved for what no lower layer can reach.

"Irreducible" means exactly two things:

- Cutover, coexistence, rollback and historical backward-compatibility contracts. No unit test can prove that a payment token written by the WooPayments client plugin is chargeable by the native runtime.
- Payment provider fidelity — establishing that the real provider behaves as the test doubles assume.

### Why

- The client suite is not a trustworthy specification of its own contracts. Of 181 contracts, 34 carry an oracle their analysis flags as compromised, and 12 cannot fail at all — a missing `await`, five refund rows whose dialog assertion sits after a generator's first `yield`, an authentication helper that returns silently when no challenge appears. Migrating those faithfully produces green checks that verify nothing.
- A significant share of contracts are already proven natively, in several cases with stronger oracles than the E2E row they were waiting on. Exact refund arithmetic beats reading a rendered price string; "completes zero-total checkout without calling the provider" beats a row that asserts neither the zero total nor the order status.
- Provider-backed E2E is the slowest and scarcest layer available, and it cannot carry the whole parity burden at a workable rate.

### Consequences

- `planned_disposition` is advisory, not authoritative. Its most common value, "extract a shared scenario with thin runtime adapters", no longer carries a presumption in favour of a browser test.
- Accepting a disposition begins with a prior question: is this contract already proven natively? Only if the answer is no does the question of how to migrate the client test arise.
- Nothing outside the two irreducible categories is automatically entitled to a browser test.

### Accepted risk

A feature family whose contracts all move to the lower layer retains no mandatory assembled-product check. This was surfaced before the decision and accepted. If a family later proves fragile in practice, a thin end-to-end smoke can be added for that family without reopening this default.

## 2026-08-08 — The multi-currency settings screen is the native equivalent of the client's onboarding wizard

Native Core does not owe a first-run multi-currency onboarding wizard. The settings screen at `wc-settings&tab=wcpay_multi_currency`, whose "Add enabled currencies" modal is a search-filtered add/remove list, is the native equivalent of the client's stepped wizard. Contracts written against the wizard are dispositioned against that screen.

This governs all nine contracts in `merchant/multi-currency-on-boarding.spec.ts`, which split three ways:

- **Underlying capabilities** — multi-select, selection persisting to enabled currencies, geolocation switch offered. Native has these; prove them against the settings modal.
- **Wizard affordances** — excluding already-enabled currencies, suggested-currency ordering, geolocation preview. These exist only because a wizard exists. Pair them with the native behaviour that covers the underlying need, or retire them.
- **Already paired** — Storefront-theme switcher availability, proven by `MultiCurrencyStorefrontIntegrationControllerTest`.

One carve-out: the empty-selection submit guard is to be **built**, not dispositioned away. Native currently leaves the button enabled with nothing selected and submitting clears the selection. That is worth fixing on its own merits, independently of parity, and the fix happens to satisfy the contract.

### Why

- The wizard is a first-run affordance, and every store migrating from the client plugin is past first run by definition. A merchant with the client plugin configured already has currencies; they arrive at native's settings screen and manage them there. Wizard parity serves close to nobody while costing real net-new UI work.
- The divergence is a coherent design premise rather than an oversight: a management surface instead of a one-time flow. Verified live on the native store — no empty-selection guard, no suggested ordering, no geolocation preview.
- The one behaviour worth preserving is a static seven-code recommendation list (`USD, EUR, JPY, GBP, AUD, CAD, INR`) that ignores the store, the account and the merchant's geography. That is weak grounds for fossilizing plugin layout into Core.

### Consequences

- Three of the ledger's four `ambiguous-decision` gates are resolved by this one decision: `PILOT-MULTI-CURRENCY-ONBOARDING-AUTHORITY`, `PILOT-MULTI-CURRENCY-RECOMMENDATION-AUTHORITY` and `PILOT-MULTI-CURRENCY-GEOLOCATION-PREVIEW-AUTHORITY`. They were three framings of one architectural question, asked per row because the ledger had no way to ask it once.
- This supersedes the pending framing of Decision 4 (suggested currencies) recorded in the 2026-08-06 session notes. That row is a wizard affordance and takes the same treatment as its siblings.
- A contract asserting a wizard affordance is not evidence of a native gap. Do not open a native defect for one without first checking it against this decision.

### Accepted risk

Native ships no first-run guidance for multi-currency. A merchant who would have been walked through currency selection now meets a settings screen and a search field. That is accepted: discoverability of a settings screen is a product concern that can be addressed on its own terms, and it is not a parity obligation owed to the client plugin's contract set.

## 2026-08-08 — A provider fidelity run discharges its family, scoped to a named claim

> **Two premises in this decision were disproved when it was executed. The decision itself stands.** Read *Correction 2026-08-09* at the end of this section before acting on the excluded-family rationale or the row counts.

Where native already proves WooCommerce's half of a provider interaction against test doubles, one authorized provider run may discharge every row in that fidelity family — subject to two conditions, and with one family excluded.

**Condition 1: the fidelity claim is named in writing before the run.** Each family states what the run establishes, specifically enough to be wrong out loud. "The provider's real decline codes are the ones native's error mapping and failed-transaction rate limiter are keyed on" qualifies. "Card declines work" does not.

**Condition 2: a row discharges only if its residual risk is entirely contained in that claim.** Rows whose risk falls outside it stay open. Some members of a family will not discharge, and that is the mechanism working rather than failing.

**Excluded: `3ds-authentication` (12 rows).** Every other family rests on substantial core-side proof. This one does not — the assertion corpus contains two authentication-related assertions and both concern scheduled subscription payments, so no customer authentication challenge is exercised anywhere. There is nothing for a thin fidelity check to lean on. This family needs real journey coverage and must not be sold as a fidelity check.

### Why

- Fidelity is shared, and the unit of provider work should match. Nineteen rows that send a card charge ask one question about the provider's decline vocabulary, not nineteen. Re-running the journey per row re-proves request shaping, idempotency, state transitions and error mapping that the lower layer already proves against fakes.
- Provider execution is the scarcest resource in the programme. This is the difference between roughly 80 authorized runs and roughly 10, which is the difference between finishing and not.
- There is precedent in this ledger: two closed contracts sit inside `saved-token-lifecycle` and were closed exactly this way.

### Consequences

- Roughly 79 rows become dischargeable through about nine family runs; the 12 `3ds-authentication` rows take conventional treatment.
- A family's named fidelity claim is part of its closure evidence, not a note. A closure that cannot point to the claim its rows discharged against is incomplete.
- "Covered core-side" remains an argument for shrinking a provider run, never for skipping one. No family discharges without its run.

### Accepted risk

The failure mode is correlated. "Covered core-side" rests on the test doubles being faithful, so a double that diverges from the real provider is wrong for every row leaning on it at once, and a single family run may not surface a difference that one member row would have caught. This was surfaced before the decision and accepted. The mitigation is not more provider runs but the named claim: a fidelity statement specific enough to be falsified is what makes a wrong double visible.

### Correction 2026-08-09 — premises restated against the tree

Executing this decision required deriving the family partition and writing the nine claims. Doing that against the current tree disproved two of the premises recorded above. The decision, both conditions, and the exclusion all stand; the reasoning under them is corrected here. The original text is left intact so the change is visible.

**The `3ds-authentication` exclusion stands, but not for the recorded reason.** The 12-row population reproduces exactly. The rationale does not: the tree no longer contains only two authentication-related assertions, and Core now holds substantial lower-layer customer-action coverage. That surviving coverage is **not** grounds to reclassify these rows into a fidelity family. The exclusion rests on a different and still-true fact — no native browser journey performs a live authentication challenge, so there is no assembled-product evidence for a thin fidelity check to lean on. The original instruction is unchanged and load-bearing: this family needs real journey coverage and must not be sold as a fidelity check.

**Condition 2 yields 52 dischargeable rows, not roughly 79.** The recorded estimate turns out to be the size of the fidelity population itself, so it assumed every family member would discharge — which Condition 2's own text says will not happen. Exact containment against each row's literal `residual_risk` gives 52 dischargeable and 27 retaining residue, across nine families. `FIDELITY-CLAIMS.md` and `fidelity-partition.tsv` are authoritative for all counts; treat any number in this section as historical.

**One exclusion inside the claims was withdrawn on evidence.** An early draft excluded card-testing-protection enforcement from the `basic-card-charge` and `redirect-method-provider-outcome` claims, leaving five rows open. Enforcement is core-side — the gateway rejects before a payment context exists — and the target account's ineligibility is surmountable through the existing byte-restoring driver, so both families now drive protection on as well as off. Those five rows discharge. The claims record that this proves native's enforcement and not the provider's provisioning of the capability.

**Scope note.** The partition covers the 130 `PILOT-NATIVE-READINESS` rows plus one row pulled in from the `PILOT-PROVIDER-EXECUTION-BUDGET` gate, because its case runs inside an already-authorized run. That widening is labelled in `FIDELITY-CLAIMS.md`; it is not part of the readiness-gated derivation.

**Why this correction exists in this form.** The disproved authentication premise was in-session analysis that was never checked in, which is why executing this decision had to reproduce the observation rather than inherit it. A false premise supporting a correct conclusion is the more dangerous shape: a reader who checks it finds it false and may overturn a conclusion that is actually right. Record the ground a decision genuinely rests on, and correct it in place when the ground moves.

## 2026-08-10 — The 3ds and 3ds2 contract pairs consolidate

The client suite's `3ds` and `3ds2` contracts test one code path with two card numbers. They consolidate: one contract per journey, driven by the 3DS2 card, with the paired row retired against it rather than duplicated.

This governs the four rows in `shopper-myaccount-saved-cards.spec.ts` that split by card variant — adding the method, and purchasing with the saved method — and applies to any future row that proposes the same split.

### Why

- Neither implementation distinguishes the protocols. The client plugin calls `handleNextAction` and `confirmCardPayment` (`client/checkout/api/index.js:203,215,221`) and native calls the same SDK entry points from both checkout surfaces. A search of the client's `includes/`, `client/` and `src/` for `3ds2`, protocol versions, or `three_d_secure_usage` returns nothing. The provider negotiates the protocol with the issuer and renders whatever challenge comes back; both implementations await a result.
- The only `three_d_secure` mentions in the client are a REST schema entry declared as a bare object with no properties, and one line copying the provider's blob into a prepared response. Nothing in `client/` renders it. It is passthrough, not behaviour.
- So the split lives entirely in the test fixture — two card numbers in the client's `tests/e2e/config/default.ts` — with no code path behind it. A second row cannot catch anything the first does not, because there is nothing different to catch.
- The ledger already framed the test: the `3ds2` rows ask for a distinct outcome or a deliberate consolidation. The distinct outcome does not exist.

### Consequences

- The recorded rationale on those rows changes from an open question to a settled one. Their `excluded` treatment does not change: they remain outside fidelity discharge with the rest of the `3ds-authentication` family, and still need real journey coverage.
- `shopper/card-authentication.spec.ts` is scoped to the 3DS2 card deliberately, not provisionally.
- A future row proposing a 3DS1/3DS2 split must first show an implementation that distinguishes them. Absent that, it is one contract.

### Accepted risk

This is a claim about the two implementations, not about the provider. If the provider ever presented a materially different challenge shape per protocol, both drivers would observe it only as "a challenge", and a consolidated contract would not notice the difference. That is accepted, and it is the same reason the 2026-08-08 decision excluded this family from thin fidelity checks: authentication needs real journey coverage, and a journey that exercises a live challenge is where such a divergence would surface.

## 2026-08-10 — Six claims extend before their first authorized runs

Six family claims in `FIDELITY-CLAIMS.md` gain extension cases before any family run has executed: `basic-card-charge` (`B1c`, `B1f`, `B1pf`), `saved-token-lifecycle` (`T1b`), `redirect-method-provider-outcome` (`A1b`), `refund-settlement` (`R1v`, a re-scoped `R2`, `R7`), `dispute-lifecycle` (`DP-nav`), and `subscription-provider-lifecycle` (extensions to `S1`, `S2`, and `S6`, plus `S7`). Thirteen partition rows whose Condition 2 verdict was `not-dischargeable` flip to `dischargeable` because their recorded residual risk is now honestly contained by a named case:

- `merchant-subscriptions-renew-action-scheduler.spec.ts:64` — `S6` dispatches the seeded due action through the wp-cron loopback into `action_scheduler_run_queue` and the queue runner instead of the admin Run action; only cron timing semantics stay excluded.
- `shopper-subscriptions-purchase-multiple-subscriptions.spec.ts:53` — `S7` drives a two-product same-schedule basket with product-ID line attribution and one combined `2197 usd` intent/charge.
- `shopper-subscriptions-purchase-no-signup-fee.spec.ts:71` — `S2` now asserts at the record level that the parent order carries zero fee lines.
- `shopper-subscriptions-purchase-sign-up-fee.spec.ts:43` — `S1` now asserts one USD 9.99 recurring line and one USD 1.99 signup-fee line summing to the proven `1198 usd` provider total.
- `merchant-orders-full-refund.spec.ts:62` — `R1v` asserts the native transaction view's semantic amount, refunded status, and merchant reason, copy-flexibly with bounded polling.
- `merchant-orders-partial-refund.spec.ts:118` (two of three products) — `R2` re-scopes to a three-line order with order-item-ID line selection and per-line allocation, keeping the `333 usd` total.
- `shopper-bnpls-checkout.spec.ts:113` (Cash App Afterpay refund) — `R7` refunds a fresh `afterpay_clearpay` source charge built from the `A3` inputs under an explicitly extended convergence budget, with `RP`'s same-key replay applying.
- `merchant-disputes-view-details-via-order-notice.spec.ts:40` — `DP-nav` follows each dispute-created order-note link with exact order/dispute IDs, fails loudly on absence, and records an explicit native-version expectation.
- `shopper-checkout-cart-coupon.spec.ts:67` — `B1c` applies and removes a free coupon before the one submission and requires `B1`'s exact local total and provider graph.
- `shopper-checkout-purchase-site-editor.spec.ts:91` (both protection variants) — `B1f` and `B1pf` repeat `B1`/`B1p` on the native FSE surface, on one shared block-theme snapshot/restore driver; `B1pf` raises no 3DS challenge and says so.
- `shopper-myaccount-saved-cards.spec.ts:105` — `T1b` proves the 20-second cooldown rejection creates nothing at the provider and a post-cooldown add still attaches and deletes cleanly.
- `alipay-checkout-purchase.spec.ts:84` — `A1b` drives the `A1` proposition unconditionally through the native Blocks checkout surface.

### Why

- No family run has executed and no closure cites any `fidelity_claim`, so extending a claim now is naming cases in writing before the run that discharges them — exactly what Condition 1 of the 2026-08-08 decision requires. There is nothing retroactive to protect: a claim can grow freely until its first authorized run turns it into evidence.
- Extending a **Falsified by** case enumeration — `S1`–`S6` to `S1`–`S7`, `R1`–`R6` to `R1`–`R7`, and the named additions elsewhere — is a non-propositional range extension: it adds cases the run must drive without weakening any proposition an earlier case fixed. Doctrine: such range extensions are permitted before any authorized run, for the same Condition 1 reason. Once a family's first authorized run executes, its claim freezes; later coverage takes a new claim or an explicit correction entry.
- Each flip is justified only by containment: the new or extended case's fixed contract states the formerly-outside element of the row's literal `residual_risk`. Where a boundary remains, the case states it — `B1pf` raises no 3DS challenge, `DP-nav` does not touch historical-version/cutover compatibility, `S6` still does not assert cron timing semantics.

### Consequences

- The programme partition moves from 52 dischargeable and 27 not dischargeable to 65 and 14. `FIDELITY-CLAIMS.md` and `fidelity-partition.tsv` remain authoritative for all counts.
- The `residual_risk` column of every re-verdicted row is deliberately untouched. It is the Condition 2 input, not a description of the verdict; the flip lives entirely in the verdict and reason columns, so the containment judgment stays auditable against the original risk text.
- The three parked treatments are **not** part of this amendment and remain open: status-change confirmation-modal ownership across coexisting runtimes (`merchant-orders-status-change.spec.ts:141`), the retirement question for the duplicated expired-card decline row, and the multi-currency geolocation preview.

### Accepted risk

Larger claims make longer runs, and a longer run has more ways to fail for reasons unrelated to the proposition under test — a fragile block-theme swap or a slow Cash App Afterpay refund can now block a family whose original cases were sound. That is accepted: the alternative is discharging these 13 rows against claims that do not contain their risk, which Condition 2 exists to prevent. If an extension case proves operationally unstable before the first authorized run, it can be split into its own claim without disturbing this doctrine.

## 2026-08-10 — The verification machinery simplifies to tests, inventory, and provider safety

Owner decision, recorded verbatim in intent: do not get stuck in false ceremony and over-convoluted verification; if machinery does not push the native WooPayments integration forward at high quality with full client parity and backward compatibility, remove it and put saner tests in place.

The evidence that forced this: on this same day, two contracts already proven by a green closed spec could not be closed because adding their annotations to that spec would invalidate a byte-frozen bundle attestation and demand a family re-attestation with fresh hash-bound reviews; and a behavior proven by a passing PHPUnit test (`WooPaymentsAddPaymentMethodIsolationTest`, run green: 2 tests, 3 assertions) had no legal closure shape at all, because closure required a collected Playwright annotation that a PHP test can never carry. The machinery built to prevent dishonest coverage claims had begun preventing honest coverage.

### What is removed

- Byte-frozen closure bundles: `source_test_sha256` attestation over targets plus transitive imports, and the rule that editing an attested file invalidates closure evidence. Tests may be refactored; git history is the audit trail.
- Hash-bound reviewer verdicts embedded in evidence packets, and required review roles per closure. Review happens in the normal development flow.
- Deferral-packet immutability, byte-exact `unlock_satisfactions`, slug-validated calibration-heading references, and the deferred→specified→closed two-commit transition enforcement (`--from-git-ref` reopen validation, the migration-state transition table, and disposition-transition `human-approved:` token gates).
- Mandatory evidence-packet schemas for closures, retirements, and deferrals. `evidence_path` becomes an optional pointer: if set, the file must exist; its content is no longer schema-validated.

### What is kept

- The 181-row ledger with its frozen upstream columns and hash — the parity inventory is the instrument, and its inventory half stays tamper-evident.
- The state machine's vocabulary (states, dispositions, support states) without transition ceremony. The closure rule becomes: a row is closed when its `target_path` names existing tracked tests and, for rows whose target is a Playwright spec, a collected test carries the row's `woopayments-contract` annotation with a title equal to `target_contract` and passes in its lane. Rows covered at a lower layer close against the covering PHPUnit/JS test path directly. Retired rows keep `not-applicable-retired` plus a retained-contract reference in `target_contract`; deferred rows keep a one-line reason in `gap_or_decision_reference`.
- The annotation↔ledger binding check (a closed row must point at a real, collected, passing test — coverage claims stay honest), relaxed from a deep-equal fixture to a per-row existence and identity check.
- `FIDELITY-CLAIMS.md`, the partition, and the Condition 1/2 discipline for provider runs, including the `fidelity_claim` citation check. Naming what a paid-provider run proves before running it is good test design, not ceremony.
- All provider-safety machinery: resource locks, quarantine receipts, the provider write journal, runtime readiness, transition allocation. That code protects a real financial test account.
- Existing evidence packets and calibration notes remain in the tree as historical record. Nothing is deleted; it is simply no longer load-bearing.

### Consequences

- The rows this machinery was blocking close on their merits: the two multi-currency frontend rows annotate their covering spec directly, and the add-method isolation row closes against its covering PHPUnit test.
- The gate pseudo-governance embedded in deferral packets (revision-cap authorizations, target/owner approval clauses) dissolves into the normal rule: fix the recorded defect, write the test, close the row. Real prior findings recorded in those packets (for example the refund-validation DOM-synchronization finding) still must be addressed in the tests that close those rows — removing ceremony does not remove the defects it recorded.
- Provider-run authorization remains with the owner: runs are still named in writing first, journaled, quarantined on uncertainty, and never performed for row count.
