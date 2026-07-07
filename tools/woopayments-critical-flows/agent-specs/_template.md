# Layer-A agent verification — prompt template

Use this to dispatch an AI agent (Playwriter / chrome-devtools) to verify ONE critical flow on BOTH stores and judge functional + UX parity. Fill the `{{...}}` slots from the flow's `.md`. The agent is the right tool when deterministic scripting is too flaky/unrealistic/complex (SCA modals, Stripe-hosted redirects, WooPay, dynamic admin journeys, visual/UX judgment).

---

You are verifying critical-flow **{{FLOW_ID}} — {{FLOW_NAME}}** for the native WooPayments-in-core merge. READ-ONLY w.r.t. code; you only drive browsers and observe. Do NOT modify product code, the reference store, or settings beyond what the flow's setup specifies.

**Oracle method:** run the flow on the REFERENCE store first (`{{REF_URL}}` — current WooPayments extension, golden), capture the expected functional outcome + UX affordances, THEN run it on the TARGET store (`{{TARGET_URL}}` — native), and compare. Parity = target reproduces reference's functional outcome AND its discoverable affordances/feedback. Use identical fixtures both sides ({{FIXTURES}}).

**Steps (from the WooPayments testing-instructions wiki):**
{{STEPS}}

**Functional acceptance (must hold on target, matching reference):**
{{FUNCTIONAL_ACCEPTANCE}}

**UX checkpoints (affordances/feedback that must be present — styling may differ):**
{{UX_CHECKPOINTS}}

**Verdict rubric:** PASS · PASS—visual divergence (cosmetic only) · FAIL—functional (end-state wrong) · FAIL—UX (reachable in principle but a reference affordance/feedback is missing/broken on native) · BLOCKED (can't run locally — state why).

**Capture evidence to `evidence/{{FLOW_ID}}/`:** screenshot of the decisive state on each store, the network call(s) for the money/state mutation, the resulting order/subscription status + key meta, and any console/error output. Note cosmetic differences separately from functional/UX ones.

Return JSON: `{flow, store_results:[{store, verdict, end_state, ux_observations, visual_diffs, evidence_paths}], parity_verdict, regression_note}`. `parity_verdict` is the target-vs-reference conclusion. Do not pass a flow you could not actually complete — mark BLOCKED with the reason.
