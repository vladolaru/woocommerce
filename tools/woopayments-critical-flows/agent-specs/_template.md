# Layer-A agent verification — prompt template

Use this to dispatch an AI agent with direct Playwright to verify ONE critical flow. Playwriter/chrome-devtools are explicit compatibility choices only when a flow documents why it needs a persistent, operator-visible Chrome session. Fill the `{{...}}` slots from the flow's `.md`, including `{{ORACLE_MODE}}`: `comparable` unless the flow explicitly says `Agent oracle mode: target-only`. The agent is the right tool when deterministic scripting is too flaky/unrealistic/complex (SCA modals, Stripe-hosted redirects, WooPay, dynamic admin journeys, visual/UX judgment).

---

You are verifying critical-flow **{{FLOW_ID}} — {{FLOW_NAME}}** for the native WooPayments-in-core merge. READ-ONLY w.r.t. code; you only drive browsers and observe. Do NOT modify product code, the reference store, or settings beyond what the flow's setup specifies.

**Oracle mode: `{{ORACLE_MODE}}`.** Follow exactly one mode:

- `comparable`: run the flow on the REFERENCE store first (`{{REF_URL}}` — current WooPayments extension, golden), capture the expected functional outcome + UX affordances, THEN run it on the TARGET store (`{{TARGET_URL}}` — native), and compare. Parity = target reproduces reference's functional outcome AND its discoverable affordances/feedback. Use identical fixtures on both stores ({{FIXTURES}}).
- `target-only`: do not drive or mutate the reference store. Run the additive continuity flow only on the TARGET store (`{{TARGET_URL}}` — native). Record the reference `store_result` as `BLOCKED` with `end_state: "not run — no WooPayments 10.8 reference equivalent"`, record the real target verdict, and keep `parity_verdict: "BLOCKED"` with a regression note that cross-store parity is not comparable. A target failure remains a functional/UX failure; target success does not turn the parity verdict into `PASS`.

**Steps (from the WooPayments testing-instructions wiki):**
{{STEPS}}

**Functional acceptance (must hold on target, matching the reference only in `comparable` mode):**
{{FUNCTIONAL_ACCEPTANCE}}

**UX checkpoints (affordances/feedback that must be present — styling may differ):**
{{UX_CHECKPOINTS}}

**Verdict rubric:** PASS · PASS—visual divergence (cosmetic only) · FAIL—functional (end-state wrong) · FAIL—UX (reachable in principle but a reference affordance/feedback is missing/broken on native) · BLOCKED (can't run locally — state why).

**Capture evidence to `evidence/{{FLOW_ID}}/`:** screenshot of the decisive state on every store required by the selected oracle mode, the network call(s) for the money/state mutation, the resulting order/subscription status + key meta, and any console/error output. Note cosmetic differences separately from functional/UX ones.

Return JSON: `{flow, oracle_mode, store_results:[{store, verdict, end_state, ux_observations, visual_diffs, evidence_paths}], parity_verdict, regression_note}`. Preserve the selected `oracle_mode` exactly. `parity_verdict` is the target-vs-reference conclusion for comparable flows and remains `BLOCKED`/not comparable for target-only flows. Do not pass a flow you could not actually complete — mark BLOCKED with the reason.
