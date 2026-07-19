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

## Coverage production result — BLOCKED (2026-07-19 UTC)

Matrix status remains `PENDING`. Committed-source run `20260719T213619Z-26385-partial`, under `evidence/coverage-production/MD-02/20260719T213607Z-md02-live-remediated/`, reported zero passed, zero failed, and two blocked store verdicts. Its context binds source commit `08237fff755fffbe38bf8a1be4c7ece609862cbb` and has payload digest `sha256:005a67976d90a5c7a3b9d8bae8f3c4e09ee5b58faad1d5963664672c796574ca`; the context file's byte digest is `c9f58a64ed4eb7feede7f062d46023b4c36d24ae010cea7eda6209fe5723c010`, and the rollup byte digest is `00c3f2f73713ad2cecd98ba94c9a0adfad33c0ca7e40acd2eab8f6650437db61`.

Neither store sent the required POST. Both raw browser artifacts stopped before request capture, and each store's pre/post/delayed decisive-state HMAC stayed identical. Both disputes remain `needs_response`, retain deadline `1785196799`, retain `submission_count=0`, preserve their customer evidence, and do not contain the MD-02 description. Reference emitted a sealed `unchanged_safe_failure` packet (`65acd81ad38d9f0de871e26c94acac4329729f07fc30d376c5c0d0471e43f138`) and manifest (`c3bafe5d7a88a43e9636cec953e8fef94f4d51d660a710dab1ac06b6b27ecd0b`). Target's raw browser artifact (`2f1d611967705d25aaae7d77786e575b818bc4d725047c87f61b10f75f315338`) and all three state projections were preserved, but the original committed validator refused to seal its internally contradictory early-blocked packet. The ephemeral run key is gone, so that target packet must not be reconstructed or re-signed.

The run and subsequent non-mutating, direct-Playwright diagnostics separated harness defects from the remaining blocker. Exact reference action selection, encoded WC Admin challenge-route detection, screenshot mask shape, and unknown request attachment semantics are now covered by regressions. Both stores can reach the exact visible description control. Reference masked capture succeeds. The native target evidence surface never stabilizes for Playwright screenshot capture: page and element screenshots time out after fonts load, and Playwright-owned CDP capture also hangs until the bounded runner closes the page. All diagnostic sessions were destroyed; no diagnostic filled or saved the form.

Punch-list: make the native target challenge surface stable enough for a load-bearing, PII-safe Playwright screenshot, then create a fresh committed context and rerun the exact one-save contract. Do not remove screenshots, relax the request witness, infer persistence from unchanged state, or replay one store independently to obtain a green result. The earlier append-only run `20260719T212349Z-86559-partial` is retained as a superseded pre-browser harness-integration blocker.

Agent oracle mode: comparable (dual-store; reference is the golden oracle).
