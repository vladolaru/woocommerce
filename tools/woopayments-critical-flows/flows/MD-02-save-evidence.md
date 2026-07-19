# MD-02 — Save dispute evidence · HYBRID (A + D)

Guards the evidence submission UI's save path: a merchant challenging a dispute must be able to open the challenge form, fill evidence fields, save for later, and find that evidence persisted (still there after reload, still editable, dispute not yet submitted). The regression risk is a native evidence form that renders but loses input on save — the merchant believes they responded and silently forfeits the dispute. The dispute is created deterministically (Test Lab / `pm_card_createDispute` card `4000000000000259`, webhooks via `npm run listen` — see MD-01); the implementor's `dispute-e2e-gate.sh` polls for evidence side effects on the driven dispute, which corroborates the persistence assertion below but does not exercise the form UI this flow guards.

## Fixtures (both stores)

- One open `needs_response` dispute per store (reuse MD-01's fixture dispute before it is resolved).
- Known evidence strings: product description `MD02 evidence product description`, customer name from the fixture order.

## Layer A — agent-driven browser

BOTH stores:

1. Open Payments → Disputes (reference `admin.php?page=wc-admin&path=/payments/disputes`, target `.../path=/woopayments/disputes`) and open the fixture dispute.
2. **Confirm the challenge/respond affordance is discoverable** and opens the evidence form.
3. Fill the product description, preserve the fixture order's prefilled customer name, and attach no files. **Confirm an explicit "Save for later" affordance exists** and use it — do NOT submit.
4. **Confirm save feedback renders** (success notice, no error), then reload the evidence form. **Confirm the entered evidence is still present and editable.**
5. End state: evidence persisted as a draft; the dispute remains `needs_response` (not submitted) on both stores.

## Layer D — deterministic state assertion

- Fetch the dispute via internal REST; assert the evidence object contains the exact saved strings and `submitted` remains false / status remains `needs_response`.
- Assert the respond-by deadline is unchanged by the save (saving a draft must not mutate lifecycle state).
- Re-fetch after 30s to rule out async reversion of the saved evidence.
- Compare reference vs target: same persistence semantics for the same evidence payload.

Deterministic exerciser: `flows/MD-02-save-evidence.sh`. It binds each retained dispute to its passing MD-01 manifest, drives one isolated direct-Playwright save attempt, independently probes the exact current REST state before, immediately after, and after the delayed interval, records mutation trust and browser verdicts separately, and emits sealed runner manifests. A missing request witness, ambiguous mutation, cleanup failure, or incomplete artifact remains `BLOCKED`; an authoritative functional or UX mismatch remains `FAIL`.

Agent oracle mode: comparable (dual-store; reference is the golden oracle).
