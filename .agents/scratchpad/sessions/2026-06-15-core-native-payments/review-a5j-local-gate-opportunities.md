---
session: 2026-06-15-core-native-payments
type: review
by: subagent:codex-a5j-local-gate-auditor
created: 2026-06-21 03:11
model: gpt-5-codex
target: A5j local gate opportunity audit after A5i
reconciles:
  - tools/woopayments-merge/HARNESS.md
  - tools/woopayments-merge/README.md
  - analysis-a5i-local-readiness-decision-rollup.md
  - staging-log.md
  - data/a4bd-post-a4bc-accumulated-gate/a4aq-accumulated-gate.json
  - data/a5h-post-a4bd-cutover-readiness/a5f-rerun-1/a5f-cutover-rehearsal.json
  - data/a5h-post-a4bd-cutover-readiness/a5g/a5g-multisite-runtime-gate.json
last_updated: 2026-06-21 03:14
status: final
---

# A5j Local Gate Opportunities

## Verdict

RERUN_A5_ONLY.

If the release/default-on packet needs one more local freshness stamp after A5i, rerun only the A5 cutover gates: `a5f-cutover-rehearsal.py` against the connected target store and `a5g-multisite-runtime-gate.py` in `existing-tests` mode. Do not rerun the full A4aq accumulated gate by default. A4bd already passed after the last A4/N12 product slice, A5h already refreshed A5f/A5g after A4bd, A5i found no source-backed local product or gate slice remaining, and current git status shows no tracked product-code change after that baseline. The A5-only rerun adds local recency for cutover mechanics, local WPCOM/token/transport readiness, target debug-log cleanliness, and multisite runtime ownership; it still does not prove production/default-on rollout, canary/error-rate behavior, WPCOM production readiness, exact production perf, or release sequencing.

## Evidence Read

`tools/woopayments-merge/HARNESS.md` defines the local-only topology and warns that green gates mean only their bounded coverage. It documents A4aq as an accumulated admin/checkout/bundle/perf/log rollup, A5f-style cutover evidence through local target browser/WP-CLI state, A5g-style multisite runtime proof, and runbooks that remain judged rather than production rollout proof. `tools/woopayments-merge/README.md` still says the harness is transition-only and removable at A6, but A5i/A6 evidence supersedes the deletion assumption because `tools/woopayments-merge` is currently ignored local verification infrastructure with zero tracked files.

The tools inventory under `tools/woopayments-merge` contains broad orchestrators (`verify.sh`, `a4aq-accumulated-gate.py`, `a5f-cutover-rehearsal.py`, `a5g-multisite-runtime-gate.py`), bounded source/static gates (`bc-drift-gate.sh`, `a4-admin-surface-gate.py`, `bundle-size-gate.sh`, `perf-surface-gate.sh`, `compare-measured-gates.py`), A5 probes/helpers (`a5-local-wpcom-readiness.sh`, `a5-user-token-readiness.php`, `a5-transport-continuity.php`, cutover browser gates, mandatory/preflight MU helpers), and money/browser/runbook gates (`flow-drive.sh`, `financial-reconcile.sh`, `dispute-e2e-gate.sh`, `subscriptions-renewal-gate.sh`, `tracks-parity.sh`, `converted-currency-gate.sh`, `payout-evidence-gate.sh`). The script list is useful, but only the A5f/A5g pair materially strengthens the A5j packet without reopening the larger A4/admin/checkout or money-path matrix.

`analysis-a5i-local-readiness-decision-rollup.md` says A5i should not rerun A4bd/A5h gates unless source or environment changed, and records the local A4/A5 baseline as green under limitations while production/default-on remains deferred and fail-closed. `staging-log.md` A4bd/A5h/A5i/A6 entries agree: A4bd passed with limitations, A5h passed with limitations, A5i closed a documentation/decision slice, and A6 cleanup is held for release/default-on sequencing.

The required JSON artifacts are green: `data/a4bd-post-a4bc-accumulated-gate/a4aq-accumulated-gate.json` reports `status=pass`, 13 checks, `failures=[]`, `incomplete=[]`, with limitations for six protected-route guard passes and five reference log diagnostic lines; `data/a5h-post-a4bd-cutover-readiness/a5f-rerun-1/a5f-cutover-rehearsal.json` reports `status=pass`, `pass=true`, 27 phase results, target restored native-owned, standalone WooPayments inactive, local WPCOM/user-token/transport probes passing, and target debug log clean; `data/a5h-post-a4bd-cutover-readiness/a5g/a5g-multisite-runtime-gate.json` reports `status=pass`, `pass=true`, `runtime_mode=existing-tests`, 28 phase results, per-site/network runtime ownership proof, and cleanup back to single-site tests wp-env.

## Candidate Commands

### A5f connected target-store cutover rehearsal

Command:

```bash
python3 tools/woopayments-merge/a5f-cutover-rehearsal.py \
  --target-wp "docker exec -i <target-cli-container> wp --allow-root --user=1" \
  --target-url http://store8889.localhost:8889 \
  --playwriter-session <session-id> \
  --out-dir .agents/scratchpad/sessions/2026-06-15-core-native-payments/data/a5j-local-gate-opportunities/a5f
```

What it proves: local target-store cutover choreography still works now: baseline native ownership, plugin-owned default-off state while the standalone plugin is active, real browser soft disable, native restoration, temporary mandatory auto-deactivation, activation guard expected failure, blocked mandatory cutover under a synthetic preflight blocker, local WPCOM readiness, owner user-token readiness, WCPay V1 transport continuity, and a final target debug-log scan. This is backed by `tools/woopayments-merge/a5f-cutover-rehearsal.py`, the A5h plan command, and the prior clean artifact `data/a5h-post-a4bd-cutover-readiness/a5f-rerun-1/a5f-cutover-rehearsal.json`.

What it does not prove: production mandatory/default-on rollout, canary cohort parity, production error-rate, production WPCOM readiness, production perf, shopper money-path correctness, or release sequencing.

Expected environment risk: medium. The script intentionally mutates only the local target store: it clears/scans `debug.log`, activates/deactivates the standalone WooPayments plugin, writes and removes temporary MU helpers, and drives Playwriter browser gates. It has cleanup paths that remove helpers and restore native ownership, but a failed run should be inspected before rerun or manual cleanup. It does not require WPCOM sandbox access or WPCOM code changes.

Worth running now: yes, if A5j wants fresh local gate evidence beyond A5i readback. This is the highest-signal rerun because it directly exercises the A5 cutover/default-on decision boundary while staying local and fail-closed.

### A5g multisite runtime ownership gate

Command:

```bash
python3 tools/woopayments-merge/a5g-multisite-runtime-gate.py \
  --repo /Users/vladolaru/Work/a8c/woocommerce-develop-2 \
  --wcpay-repo /Users/vladolaru/Work/a8c/woocommerce-payments \
  --runtime-mode existing-tests \
  --existing-wp-env-dir /Users/vladolaru/Work/a8c/woocommerce-develop-2/plugins/woocommerce \
  --existing-tests-url http://store8889.localhost:8087 \
  --out-dir .agents/scratchpad/sessions/2026-06-15-core-native-payments/data/a5j-local-gate-opportunities/a5g
```

What it proves: multisite runtime ownership still follows the local arbiter matrix: native ownership on main and second sites when the standalone plugin is inactive, plugin ownership only where site-active, plugin ownership network-wide when network-active, and return to native ownership after deactivation. The prior A5h artifact proves this in `existing-tests` mode with 28 phases and cleanup back to single-site tests wp-env.

What it does not prove: connected-account behavior on multisite, production network rollout, production default-on, WPCOM production readiness, canary/error-rate, browser checkout, or money-path parity. It uses the WooCommerce tests wp-env at `http://store8889.localhost:8087`, not the connected target store at `:8889`.

Expected environment risk: medium to medium-high. It resets the existing WooCommerce tests wp-env database, converts it to multisite, toggles plugin activation per-site and network-wide, then resets back to single-site. This is isolated from the connected target store, but disruptive to the tests env while running and dependent on Docker/wp-env health.

Worth running now: yes as the companion A5 rerun if A5f is rerun. It adds fresh local runtime-ownership evidence for multisite, which is closer to the release/default-on decision boundary than another admin/checkout parity sweep.

### A5 standalone readiness probes

Command:

```bash
tools/woopayments-merge/a5-local-wpcom-readiness.sh /Users/vladolaru/Work/a8c/woocommerce-develop-2
docker exec -i <target-cli-container> wp --allow-root --user=1 eval-file - < tools/woopayments-merge/a5-user-token-readiness.php
docker exec -i <target-cli-container> wp --allow-root --user=1 eval-file - < tools/woopayments-merge/a5-transport-continuity.php
```

What it proves: local WPCOM helper/config readiness, target store owner-token readiness, and native transport request shape with outbound HTTP blocked. These are exactly the late A5f phases `local-wpcom-readiness`, `owner-user-token-readiness`, and `transport-continuity`.

What it does not prove: browser cutover UX, mandatory auto-deactivation, plugin activation guard, debug-log cleanliness across a cutover window, or production WPCOM behavior.

Expected environment risk: low to medium. These are local probes and the transport probe blocks external HTTP, but they still depend on the target container and store state. The direct `eval-file -` form is easy to misrun if the target container is stale.

Worth running now: no as separate commands. Run the full A5f command instead so these probes are captured in context with cutover state and cleanup evidence.

### A4aq accumulated admin/checkout/bundle/perf/log gate

Command:

```bash
tools/woopayments-merge/a4aq-accumulated-gate.py \
  --repo /Users/vladolaru/Work/a8c/woocommerce-develop-2 \
  --plugin-repo /Users/vladolaru/Work/a8c/woocommerce-payments \
  --ref-wp "docker exec -i wcpay_wp_default wp --allow-root" \
  --target-wp "docker exec -i <target-cli-container> wp --allow-root --user=1" \
  --playwriter-session <session-id> \
  --out-dir .agents/scratchpad/sessions/2026-06-15-core-native-payments/data/a5j-local-gate-opportunities/a4aq
```

What it proves: the accumulated A4/N12 local rollup still passes across admin source/chunks, Playwriter admin state, base admin browser routes, target-only optional-account admin route coverage, checkout browser coverage, bundle reference/target/compare, perf fixtures/reference/target/compare, and target/reference log scan. This is the same gate that produced `data/a4bd-post-a4bc-accumulated-gate/a4aq-accumulated-gate.json`.

What it does not prove: exhaustive admin/content parity, full optional-route reference parity, exact production latency, complete checkout behavior such as 3DS/SCA/saved-method mutation/order-pay WooPay, production default-on, canary/error-rate, WPCOM production readiness, or release sequencing. The A4bd review explicitly records these limits in `review-a4bd-accumulated-gate.md`.

Expected environment risk: high compared with A5-only. It is long-running, browser-dependent, creates local perf fixtures/orders, temporarily mutates target account-cache flags for optional admin routes before restoring them, scans logs, and depends on both reference and target stores. It is local-only and designed to restore optional-admin cache state, but it is broader than the decision packet needs after no tracked product/admin/checkout change.

Worth running now: no by default. Rerun it only if there is a fresh product/admin/checkout source change, an unexplained environment drift, or a release owner explicitly wants a new full local accumulated baseline despite the limits. For A5j, it would mostly duplicate A4bd with a newer timestamp.

### A4 admin source/chunk gate

Command:

```bash
python3 tools/woopayments-merge/a4-admin-surface-gate.py \
  --repo /Users/vladolaru/Work/a8c/woocommerce-develop-2 \
  --plugin-repo /Users/vladolaru/Work/a8c/woocommerce-payments \
  --out .agents/scratchpad/sessions/2026-06-15-core-native-payments/data/a5j-local-gate-opportunities/admin-source.json
```

What it proves: native WooPayments admin source/chunk guardrails still hold: expected route chunks exist, plugin-era route aliases are only the expected compatibility redirects, single-registry forbidden tokens are absent, and reference plugin admin baseline measurements can be recorded.

What it does not prove: browser route rendering, account-state route availability, checkout behavior, cutover, production default-on, or production rollout.

Expected environment risk: low. It is a source/assets read and JSON write only.

Worth running now: optional but not recommended for the verdict. It is safe and cheap, but A4aq already included it and no tracked admin source change exists after A4bd/A5i. It may be useful only as a small appendix if the A5j packet wants an extra static admin-chunk freshness line.

### BC/Tracks static drift gate

Command:

```bash
tools/woopayments-merge/bc-drift-gate.sh
```

What it proves: the local WooPayments plugin checkout still has no undispositioned static drift against the harness baseline across scheduler, PHP API, persisted data, endpoints, hooks/filters, and Tracks emitter categories.

What it does not prove: native Core reproduces the BC surface at runtime, prop-level Tracks parity, money-path correctness, cutover behavior, production rollout, or production WPCOM readiness. `HARNESS.md` explicitly describes this as drift on the reference/source surface, not proof of native reproduction.

Expected environment risk: low. It reads the local `../woocommerce-payments` checkout and diffs text baselines. Do not run `--update` in this audit context.

Worth running now: no for the A5j default-on packet unless the WooPayments plugin checkout changed since the last BC drift evidence. It is safe, but it is less decision-relevant than A5f/A5g and would not change the production/default-on boundary.

### Bundle and measured perf standalone gates

Command:

```bash
tools/woopayments-merge/bundle-size-gate.sh capture --repo /Users/vladolaru/Work/a8c/woocommerce-payments --profile wcpay-plugin --out .agents/scratchpad/sessions/2026-06-15-core-native-payments/data/a5j-local-gate-opportunities/bundle-reference.json
tools/woopayments-merge/bundle-size-gate.sh capture --repo /Users/vladolaru/Work/a8c/woocommerce-develop-2 --profile wc-core --out .agents/scratchpad/sessions/2026-06-15-core-native-payments/data/a5j-local-gate-opportunities/bundle-target.json
tools/woopayments-merge/bundle-size-gate.sh compare --ref .agents/scratchpad/sessions/2026-06-15-core-native-payments/data/a5j-local-gate-opportunities/bundle-reference.json --target .agents/scratchpad/sessions/2026-06-15-core-native-payments/data/a5j-local-gate-opportunities/bundle-target.json --budget tools/woopayments-merge/a4aq-bundle-budget.json
```

Command:

```bash
tools/woopayments-merge/perf-surface-gate.sh capture --wp "docker exec -i wcpay_wp_default wp --allow-root" --out .agents/scratchpad/sessions/2026-06-15-core-native-payments/data/a5j-local-gate-opportunities/perf-reference.json
tools/woopayments-merge/perf-surface-gate.sh capture --wp "docker exec -i <target-cli-container> wp --allow-root --user=1" --out .agents/scratchpad/sessions/2026-06-15-core-native-payments/data/a5j-local-gate-opportunities/perf-target.json
tools/woopayments-merge/perf-surface-gate.sh compare --ref .agents/scratchpad/sessions/2026-06-15-core-native-payments/data/a5j-local-gate-opportunities/perf-reference.json --target .agents/scratchpad/sessions/2026-06-15-core-native-payments/data/a5j-local-gate-opportunities/perf-target.json
```

What it proves: bundle captures check known asset presence/raw/gzip sizes and budgeted drift; perf captures check bounded WP-CLI probes for gateway registration, autoload option bytes, account data autoload state, REST route registration, and optional local money-path probes when fixtures are supplied.

What it does not prove: exact production latency, production asset performance, checkout render timing, production traffic behavior, or release default-on readiness. `HARNESS.md` and `review-a4bd-accumulated-gate.md` both frame this as local smoke evidence, not exact perf proof.

Expected environment risk: bundle is low; perf is medium because it uses live local WP-CLI, can be incomplete without valid fixtures, and without explicit order IDs is less comparable than the A4aq fixture-backed perf run.

Worth running now: no as standalone A5j work. A4bd already ran fixture-backed bundle/perf inside the accumulated gate, and no product/source change exists after A4bd. Standalone perf without refreshed fixtures would be weaker than the existing A4bd evidence.

### Full `verify.sh` cross-store loop and money-path gates

Command:

```bash
tools/woopayments-merge/verify.sh --ref "docker exec -i wcpay_wp_default wp --allow-root" --target "docker exec -i <target-cli-container> wp --allow-root --user=1"
```

Related commands include `flow-drive.sh`, `financial-reconcile.sh`, `dispute-e2e-gate.sh`, `subscriptions-renewal-gate.sh compare`, `tracks-parity.sh`, `converted-currency-gate.sh`, and `payout-evidence-gate.sh`.

What it proves: depending on prerequisites, `verify.sh` can drive local charge fixtures, static drift, Bucket-E parity, narrow query-count smoke, and Stripe-backed financial reconciliation for the supplied orders; related gates can prove bounded dispute, subscription, Tracks, currency, or payout dimensions when their fixtures and sinks are valid.

What it does not prove: production default-on rollout, canary/error-rate, production WPCOM readiness, release sequencing, complete browser checkout, or full money-path matrix unless every relevant flow is driven and reconciled. `HARNESS.md` repeatedly says supplied-order financial reconciliation proves only dimensions present on those driven orders.

Expected environment risk: medium-high to high. These commands can create local orders/charges/refunds/disputes/subscriptions, require connected accounts, Stripe CLI/login or local listener state for raw-source reconciliation, wpcom-local Tracks sink state for telemetry parity, and real browser-created fixtures for subscription comparison. They are valid harness tools, but not safe "just rerun" candidates for the post-A5i decision packet without a specific money/telemetry question.

Worth running now: no. Use only if the release/default-on packet has a specific unanswered money-path, Tracks, subscription, dispute, currency, or payout question. A generic full rerun risks adding noisy local-money evidence while still not proving production rollout.

### Focused PHP guard tests

Command:

```bash
pnpm --filter=@woocommerce/plugin-woocommerce test:php:env -- --filter 'NativePaymentsRuntimeArbiterTest|WooPaymentsCutoverControllerTest|WooPaymentsEventIngestorTest'
```

What it proves: source-level guards for fail-closed native runtime defaulting, mandatory cutover defaulting, and provider event disposition still pass in the WooCommerce PHP test env.

What it does not prove: browser cutover, multisite runtime behavior, local WPCOM/token/transport readiness, production default-on, canary/error-rate, or production perf.

Expected environment risk: low to medium. It uses the standard local test environment, not WPCOM sandbox or product/harness edits.

Worth running now: already covered by `analysis-a5j-release-default-on-readiness.md`, which records this exact command passing with 123 tests and 391 assertions. Include that result in the decision packet, but no additional rerun is needed unless source changes after that test.

## Recommended A5j Packet Line

Recommended local addendum if A5-only reruns are performed: "A5j refreshed the local A5 gates only. A5f passed against the connected local target store, covering soft/mandatory cutover choreography, local WPCOM readiness, owner-token readiness, WCPay V1 transport continuity, cleanup to native ownership, and target debug-log cleanliness. A5g passed in the isolated tests wp-env, covering per-site and network-active runtime ownership and cleanup back to single-site. These reruns strengthen local cutover recency only; production/default-on rollout, canary/error-rate, production WPCOM readiness, exact production perf, and release sequencing remain deferred and fail-closed."

Recommended line if no rerun is performed: "No local harness rerun was required after A5i because no tracked product source changed after the A4bd/A5h green baseline; the only useful optional rerun would be A5f/A5g for freshness, not proof of production rollout."
