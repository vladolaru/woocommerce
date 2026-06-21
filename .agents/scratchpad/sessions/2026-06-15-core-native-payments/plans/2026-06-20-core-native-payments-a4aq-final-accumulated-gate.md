---
session: 2026-06-15-core-native-payments
type: plan
by: codex
created: 2026-06-20 14:24
last_updated: 2026-06-20 15:53
target: A4aq final accumulated A4/N12 gate
reconciles:
  - ../analysis-a4aq-final-accumulated-gate.md
  - ../analysis-a4ap-accumulated-checkout-admin-gate.md
  - ../spec-conformance-baseline.md
  - ../supervisor-prompt-2026-06-18-2344-N12.md
status: draft
---

# A4aq Final Accumulated Gate Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use $subagent-driven-development (recommended) or $executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Establish a single fail-closed accumulated A4/N12 evidence gate over native WooPayments admin and checkout parity after A4ap, without flipping native admin readiness until the gate is actually green.

**Architecture:** Reuse the current A4 admin source/browser, measured bundle, and measured perf gates instead of replacing them. Add a shopper checkout Playwriter gate and a thin local orchestrator that runs every gate, streams progress, records pass/fail/incomplete results, scans logs, and writes one aggregate JSON artifact for the final readiness decision.

**Tech Stack:** Python 3 harness orchestration, Playwriter browser scripts, WooCommerce wp-env/WP-CLI, WooPayments merge harness shell gates, JSON evidence files, local Docker log/debug-log scans.

**Current result:** Implemented, review-fixed, and run. The accumulated gate is fail-closed `incomplete`, not pass: checkout browser, bundle, and log checks are green; admin browser is explicitly incomplete for target protected routes that are unavailable in the current account state; and `perf-compare` remains incomplete for missing deterministic `process_payment`, `refund`, and `capture` fixtures plus preinitialized REST route-registration timing.

---

## File Map

- Add: `tools/woopayments-merge/a4-checkout-browser-gate.playwriter.mjs`
- Add: `tools/woopayments-merge/a4aq-accumulated-gate.py`
- Add: `tools/woopayments-merge/a4aq-bundle-budget.json`
- Modify: `tools/woopayments-merge/HARNESS.md`
- Modify: `.agents/scratchpad/sessions/2026-06-15-core-native-payments/analysis-a4aq-final-accumulated-gate.md`
- Modify: `.agents/scratchpad/sessions/2026-06-15-core-native-payments/implementation-log.md`
- Modify: `.agents/scratchpad/sessions/2026-06-15-core-native-payments/staging-log.md`
- Modify: `.agents/scratchpad/sessions/2026-06-15-core-native-payments/spec-conformance-baseline.md`
- Modify: `.agents/scratchpad/sessions/2026-06-15-core-native-payments/README.md`

## Task 1: Checkout Browser Gate

- [x] **Step 1: Create the Playwriter gate skeleton.**

Create `tools/woopayments-merge/a4-checkout-browser-gate.playwriter.mjs` by following the structure of `a4-admin-browser-gate.playwriter.mjs`: local-only comment header, `gateSlug` from `WOOPAYMENTS_GATE_SLUG` or `state.gateSlug`, data/evidence paths under `.agents/scratchpad/sessions/2026-06-15-core-native-payments/data/${ gateSlug }`, `state.surfaceIds`, `state.viewportIds`, and progress JSON after every result.

The evidence schema should include:

```json
{
  "gate": "a4aq-checkout-browser-gate",
  "gateSlug": "a4aq",
  "status": "running|complete",
  "startedAt": "ISO-8601",
  "lastUpdatedAt": "ISO-8601",
  "surfaces": [],
  "viewports": [],
  "stores": [],
  "results": [],
  "failures": []
}
```

- [x] **Step 2: Add reusable browser assertions.**

Add helpers equivalent to the admin gate for failed responses, console issues, page errors, screenshots, script/resource capture, and token checks. Include checkout-specific helpers that return:

- `settings`: `window.wc?.wcSettings?.getSetting( 'woocommerce_payments_data', {} )` and `window.wc?.wcSettings?.getPaymentMethodData?.( 'woocommerce_payments' )`.
- `resources`: resource/script URLs matching `wc-payment-method-woopayments`, `woopayments-checkout`, `woopayments-express-checkout`, `woopayments-woopay`, `payment-methods-cards`, `woopayments-card-brands`, or `js.stripe.com/v3`.
- `labels`: visible checkout label text normalized from `body`.

- [x] **Step 3: Define target/reference surfaces.**

Add a surface matrix with at least:

- `blocks-checkout-card`: target `http://store8889.localhost:8889/checkout/?a4aq=blocks-card`, reference `http://localhost:8082/checkout/?a4aq=blocks-card`, assertions for `#wcpay-core-blocks-payment-element`, `.wcpay-core-test-mode-instructions`, `[data-testid="payment-methods-logos"]`, reference-style card icon paths, `+ 2`, no standard saved-card checkbox when WooPay save-my-info is active, and Stripe iframe presence.
- `blocks-checkout-express`: same checkout URLs, assertions for `.wcpay-core-express-checkout`, `.wcpay-core-express-checkout__element`, WooPay split asset, ECE split asset, and Store API `extensions.wcpay.express_checkout_methods` when visible in intercepted responses or in `wcSettings`.
- `blocks-cart-express`: target/reference cart URLs, assertions for `.wcpay-core-express-checkout__element` and split express/WooPay assets when the cart has items.
- `classic-checkout-card`: target/reference classic checkout URLs currently used by A4am, assertions for `window.wcpay_core_checkout_config`, `#wcpay-core-checkout-form[data-wcpay-config]`, `#wcpay-core-payment-element`, card test instructions, card-brand overflow dialog, and no WooPay/ECE markup in add-payment mode.
- `classic-add-payment-method`: target/reference my-account add-payment-method URLs, assertions for `#add_payment_method`, `wcpay-setup-intent` after setup, and absence of WooPay/ECE express buttons.
- `product-express`: target/reference simple product URL, assertions for classic ECE wrapper and/or WooPay product button when enabled; record source/Jest caveat if the local product fixture cannot deterministically render both.

Keep caveats explicit: do not fail on BNPL/local payment-method fields, reference `methods_enabled_at_location`, reference order-attribution hidden inputs, or WooPay order-pay parity.

- [x] **Step 4: Implement per-surface checks.**

For each surface and viewport, navigate target/reference, wait for page load and a minimum settle, gather assertions, screenshot, resource URLs, failed responses, console issues, and page errors. Mark each result `passed` only when required tokens/selectors/assets are present, forbidden selectors/assets are absent, failed responses are empty, console issues are empty except known local Stripe/domain warnings, and page errors are empty.

- [x] **Step 5: Run a smoke pass.**

Run the new script through Playwriter with a narrow subset first:

```bash
WOOPAYMENTS_GATE_SLUG=a4aq-smoke npx playwriter@latest -s 1 --timeout 120000 -f tools/woopayments-merge/a4-checkout-browser-gate.playwriter.mjs
```

Expected: JSON progress lines, screenshots under the session data directory, and either a real failure to fix or a complete evidence file.

## Task 2: Accumulated Gate Orchestrator

- [x] **Step 1: Create the Python orchestrator skeleton.**

Create `tools/woopayments-merge/a4aq-accumulated-gate.py` with CLI:

```bash
tools/woopayments-merge/a4aq-accumulated-gate.py \
  --repo /Users/vladolaru/Work/a8c/woocommerce-develop-2 \
  --plugin-repo /Users/vladolaru/Work/a8c/woocommerce-payments \
  --ref-wp "docker exec -i wcpay_wp_default wp --allow-root" \
  --target-wp "docker exec -i <target-cli> wp --allow-root --user=1" \
  --playwriter-session 1 \
  --out-dir .agents/scratchpad/sessions/2026-06-15-core-native-payments/data/a4aq
```

Exit codes: `0` pass, `1` fail, `3` incomplete, `2` usage/preflight error.

- [x] **Step 2: Add aggregate JSON writing and progress output.**

Write `.agents/scratchpad/sessions/2026-06-15-core-native-payments/data/a4aq/a4aq-accumulated-gate.json` after every check. Use schema:

```json
{
  "schema": "woopayments_a4aq_accumulated_gate.v1",
  "gate": "a4aq-accumulated-gate",
  "status": "running|pass|fail|incomplete",
  "started_at": "ISO-8601",
  "last_updated_at": "ISO-8601",
  "environment": {
    "repo": "",
    "plugin_repo": "",
    "reference_url": "http://localhost:8082",
    "target_url": "http://store8889.localhost:8889"
  },
  "checks": [],
  "failures": [],
  "incomplete": [],
  "limitations": []
}
```

Print one human line and one JSON line for each check start/end:

```text
[a4aq] 01/08 RUN admin-source
{"event":"check_start","id":"admin-source","index":1,"total":8}
```

- [x] **Step 3: Run existing gates from the orchestrator.**

Add subprocess steps for:

- `a4-admin-surface-gate.py --repo --plugin-repo --out <out-dir>/admin-source.json`
- Playwriter admin browser gate with `WOOPAYMENTS_GATE_SLUG=a4aq` and output path from the script.
- Playwriter checkout browser gate with `WOOPAYMENTS_GATE_SLUG=a4aq`.
- `bundle-size-gate.sh capture --repo <plugin-repo> --profile wcpay-plugin --out <out-dir>/bundle-reference.json`
- `bundle-size-gate.sh capture --repo <repo> --profile wc-core --out <out-dir>/bundle-target.json`
- `bundle-size-gate.sh compare --ref <out-dir>/bundle-reference.json --target <out-dir>/bundle-target.json --budget tools/woopayments-merge/a4aq-bundle-budget.json`
- `perf-surface-gate.sh capture` for reference and target without money fixtures first; mark money-path probes incomplete if fixtures are missing.
- `perf-surface-gate.sh compare` and preserve exit `3` as aggregate incomplete rather than pass.

- [x] **Step 4: Add log scans.**

In the orchestrator, find running target/reference containers from Docker names and scan:

- target web container logs since the gate start time;
- reference web container logs since the gate start time;
- target `/var/www/html/wp-content/debug.log`;
- reference `/var/www/html/wp-content/debug.log`.

Fail on target PHP notices, warnings, deprecations, fatals, uncaught exceptions, stack traces, database errors, or actual 5xx markers. Classify reference-only known old notices as limitations, not target pass evidence.

- [x] **Step 5: Add the bundle budget.**

Create `tools/woopayments-merge/a4aq-bundle-budget.json` with explicit allowances for known native split-asset differences that A4am-A4ap already justified. Keep the default strict for all unmentioned assets. Do not use `allow_new` or growth budgets to hide unexpected WooPayments assets.

## Task 3: Run, Fix, Review, And Record

- [x] **Step 1: Run the orchestrator.**

Run:

```bash
tools/woopayments-merge/a4aq-accumulated-gate.py \
  --repo /Users/vladolaru/Work/a8c/woocommerce-develop-2 \
  --plugin-repo /Users/vladolaru/Work/a8c/woocommerce-payments \
  --ref-wp "docker exec -i wcpay_wp_default wp --allow-root" \
  --target-wp "docker exec -i $(docker ps --format '{{.Names}}' | grep -- '-cli-1' | grep -v tests | head -n1) wp --allow-root --user=1" \
  --playwriter-session 1 \
  --out-dir .agents/scratchpad/sessions/2026-06-15-core-native-payments/data/a4aq
```

Expected: fail-closed pass/fail/incomplete aggregate JSON. If it fails on real product behavior, fix the product, add focused tests, and rerun focused gates before rerunning A4aq. If it is incomplete because deterministic local proof is unavailable, record the limitation and source/Jest fallback; do not call it pass.

- [x] **Step 2: Use review agents.**

Dispatch at least:

- `e2e-tests-reviewer` for Playwriter gate reliability and locator quality.
- `performance-reviewer` for bundle/perf-budget honesty.
- `reliability-reviewer` for log-scan/error-classification and fail-closed behavior.

Source-verify findings before acting. Record durable review findings in `review-agent-findings.md` or the A4aq analysis.

- [x] **Step 3: Run focused static checks.**

Run:

```bash
python3 -m py_compile tools/woopayments-merge/a4aq-accumulated-gate.py
node --check tools/woopayments-merge/a4-checkout-browser-gate.playwriter.mjs
git diff --check -- . ':!.agents'
```

No markdown lint on `.agents/scratchpad`.

- [x] **Step 4: Update durable records.**

Update `analysis-a4aq-final-accumulated-gate.md`, `implementation-log.md`, `staging-log.md`, `spec-conformance-baseline.md`, and `README.md` with the final A4aq evidence. If the gate passes and no product blockers remain, record whether native admin readiness is still intentionally fail-closed or whether a separate explicit readiness-flip slice is now authorized.

- [x] **Step 5: Commit policy.**

The harness lives under ignored `tools/woopayments-merge`, so do not force-add it unless explicitly asked. If product fixes are needed, commit those product fixes and their changelog as normal local WC commits after gates pass. Do not push.
