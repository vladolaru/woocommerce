---
session: 2026-06-15-core-native-payments
type: review
by: codex
created: 2026-06-20 14:58
last_updated: 2026-06-20 15:01
tool: pirategoat-tools:performance-reviewer
target: A4AQ bundle/perf gate honesty and budgets
reconciles:
  - analysis-a4aq-final-accumulated-gate.md
status: final
---

# A4AQ Performance Review

> **Prompt:** "Review the A4aq bundle/perf gate honesty and budgets. Scope: tools/woopayments-merge/a4aq-accumulated-gate.py, tools/woopayments-merge/a4aq-bundle-budget.json, tools/woopayments-merge/bundle-size-gate.sh, tools/woopayments-merge/perf-surface-gate.sh, tools/woopayments-merge/compare-measured-gates.py, and evidence under .agents/scratchpad/sessions/2026-06-15-core-native-payments/data/a4aq/ for bundle/perf. Do not edit product files. Do not access WPCOM sandbox or remote services. You may write exactly one scratchpad review document at .agents/scratchpad/sessions/2026-06-15-core-native-payments/review-a4aq-performance.md with required frontmatter, no hard-wrapped prose. Focus on whether budgets hide unexpected assets, whether perf incomplete is classified honestly, whether measured probes support the claims, and whether any product performance regression is visible. Return severity-ranked findings with file/line/evidence references."

## Notes

No critical or high severity findings. The A4AQ accumulated gate is honest at the aggregate level: `data/a4aq/a4aq-accumulated-gate.json` records `perf-compare` as `status: "incomplete"` with exit code `3` and leaves the whole gate `status: "incomplete"` rather than pass. The evidence supports no visible current product performance regression: bundle target assets are mostly smaller than the plugin reference, the current new Blocks Express Checkout target assets are modest at `26632` raw / `8774` gzip bytes for JS and `766` raw / `269` gzip bytes for CSS, target autoload bytes are lower (`85363` vs `115833`), target `wcpay_account_data` is non-autoloaded, browser gates have zero failures, and target log scan is clean. The findings below are gate-honesty and future-regression-catch issues.

## Findings

### Medium: New Blocks Express Checkout assets are allowed without any byte ceiling

`tools/woopayments-merge/a4aq-bundle-budget.json:3` and `tools/woopayments-merge/a4aq-bundle-budget.json:6` set `allow_new: true` for `blocks-express-checkout.css` and `blocks-express-checkout.js` without `raw_bytes`, `gzip_bytes`, or growth limits. `tools/woopayments-merge/compare-measured-gates.py:108` through `tools/woopayments-merge/compare-measured-gates.py:113` then treats an allowed-new asset as disposed and immediately `continue`s before the normal size loop at `tools/woopayments-merge/compare-measured-gates.py:118` through `tools/woopayments-merge/compare-measured-gates.py:125`. In the A4AQ evidence, the target captures concrete sizes for those assets (`data/a4aq/bundle-target.json:15` through `data/a4aq/bundle-target.json:24`), but the aggregate compare output only reports `ALLOW blocks-express-checkout.css` and `ALLOW blocks-express-checkout.js` at `data/a4aq/a4aq-accumulated-gate.json:165`, not a size check or limit.

Impact: Today’s captured sizes are not a visible product regression, but the budget would also pass a much larger future `wc-payment-method-woopayments-express-checkout.js` or CSS file as long as the asset name remains the same. That weakens the bundle gate exactly on a shopper-facing express-checkout surface where cold-cache bytes matter at scale.

Recommendation: Give allowed-new assets explicit `raw_bytes` and `gzip_bytes` ceilings, or make `allow_new` only waive the presence delta while still applying any supplied size caps. If a new asset is intentionally uncapped for one run, the comparator should print that as an explicit incomplete/limitation rather than a clean `ALLOW`.

### Medium: Gateway registration metrics are hot-cache measurements but are presented as green perf signals

`tools/woopayments-merge/perf-surface-gate.sh:66` through `tools/woopayments-merge/perf-surface-gate.sh:78` warms the measured operation once before counting queries and then times repeated in-process calls. That helper is used for gateway registration at `tools/woopayments-merge/perf-surface-gate.sh:344` through `tools/woopayments-merge/perf-surface-gate.sh:349`. The resulting evidence reports target gateway registration as `queries: 0`, `external_requests: 0`, and `median_ms: 0` at `data/a4aq/perf-target.json:8` through `data/a4aq/perf-target.json:27`, and the aggregate compare prints that as `ok gateway_registration` in `data/a4aq/a4aq-accumulated-gate.json:233`.

Impact: A first-call gateway registration regression can still matter once per WordPress request, especially under cold cache or high traffic, but this probe would hide first-call database reads or setup cost behind the warm-up. The A4AQ analysis is otherwise careful about perf incompleteness, but the green `median_ms 0 -> 0` claim only supports warmed in-process behavior, not request-cold gateway registration.

Recommendation: Record this probe with an explicit `measurement_mode: warmed_cache` and add a separate `gateway_registration_cold` measurement before the warm-up, ideally in a fresh WP-CLI process or through a one-shot `measure_once()` path before `WC()->payment_gateways()->payment_gateways()` is primed. If cold isolation is not available, classify gateway-registration timing as incomplete instead of a green timing signal.

## Non-Findings

Perf incomplete is classified honestly. `tools/woopayments-merge/compare-measured-gates.py:218` through `tools/woopayments-merge/compare-measured-gates.py:229` requires `process_payment`, `refund`, and `capture`, and `tools/woopayments-merge/compare-measured-gates.py:272` through `tools/woopayments-merge/compare-measured-gates.py:277` marks preinitialized REST route registration incomplete. The A4AQ captures show all three money-path probes as `requires_fixture` in `data/a4aq/perf-target.json:74` through `data/a4aq/perf-target.json:84` and `data/a4aq/perf-reference.json:114` through `data/a4aq/perf-reference.json:124`; the aggregate evidence records `perf-compare` as incomplete and the full gate as incomplete at `data/a4aq/a4aq-accumulated-gate.json:226` through `data/a4aq/a4aq-accumulated-gate.json:233` and `data/a4aq/a4aq-accumulated-gate.json:308` through `data/a4aq/a4aq-accumulated-gate.json:317`.

No current product performance regression is visible in the provided A4AQ bundle/perf/browser/log evidence. The browser matrices are complete with zero failures for checkout (`data/a4aq/a4aq-checkout-browser-gate.json:1` through `data/a4aq/a4aq-checkout-browser-gate.json:23`, `data/a4aq/a4aq-checkout-browser-gate.json:13497`) and admin (`data/a4aq-admin-browser-gate.json:1` through `data/a4aq-admin-browser-gate.json:31`, final `failures: []`), and the perf evidence that is actually measured trends downward on gateway count, action callback count, REST controller instantiations, and autoload bytes.
