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
