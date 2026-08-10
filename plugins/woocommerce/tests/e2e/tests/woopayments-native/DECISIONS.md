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
