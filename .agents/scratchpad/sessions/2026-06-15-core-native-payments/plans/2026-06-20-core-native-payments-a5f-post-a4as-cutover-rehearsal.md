---
session: 2026-06-15-core-native-payments
type: plan
by: codex
created: 2026-06-20 18:07
last_updated: 2026-06-20 18:29
target: A5f post-A4as cutover rehearsal
reconciles:
  - analysis-a5-next-slice-reground.md
  - plans/2026-06-20-core-native-payments-a5e-soft-cutover-handoff-proof.md
  - staging-log.md
  - spec-conformance-baseline.md
  - implementation-log.md
status: draft
---

# Core Native Payments A5f Post-A4as Cutover Rehearsal Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use $subagent-driven-development (recommended) or $executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Re-run the WooPayments plugin-to-native cutover handoff against the current post-A4ar/A4as branch and local target store, with current preflight readiness and mandatory cutover still default-off.

**Architecture:** A5f is a harness/evidence slice first. Add one ignored local orchestration script that streams progress, composes the existing A5 WP-CLI probes and Playwriter gates, installs only temporary target MU helpers for mandatory and synthetic-blocker windows, restores the target store to native-owned/inactive state, and records a single rollup. WooCommerce product code changes happen only if the rehearsal exposes a source-backed bug.

**Tech Stack:** WooCommerce Core PHP, WordPress WP-CLI in local Docker/wp-env, Playwriter browser automation, local ignored `tools/woopayments-merge` harness scripts, scratchpad JSON evidence under `.agents/scratchpad/sessions/2026-06-15-core-native-payments/data`.

---

## File Structure

- Create or modify: `tools/woopayments-merge/a5f-cutover-rehearsal.py` as the local ignored A5f orchestrator.
- Reuse: `tools/woopayments-merge/a5-cutover-state.php`, `a5-cutover-browser-gate.playwriter.mjs`, `a5-mandatory-browser-gate.playwriter.mjs`, `a5-mandatory-cutover-mu-plugin.php`, `a5-preflight-blocker-mu-plugin.php`, `a5-user-token-readiness.php`, `a5-transport-continuity.php`, and `a5-local-wpcom-readiness.sh`.
- Modify existing A5 Playwriter gates only if they need parameterized evidence names or setup-neutral behavior; do not weaken assertions.
- Update: `.agents/scratchpad/sessions/2026-06-15-core-native-payments/analysis-a5-next-slice-reground.md`, `implementation-log.md`, `staging-log.md`, `spec-conformance-baseline.md`, and `README.md` with results.
- Modify WooCommerce product files only if A5f fails for a source-backed product defect. Likely files are `plugins/woocommerce/src/Internal/Payments/Providers/WooPayments/WooPaymentsCutoverController.php` and `plugins/woocommerce/tests/php/src/Internal/Payments/Providers/WooPayments/WooPaymentsCutoverControllerTest.php`.

## Task 1: Add the A5f Orchestrator

**Files:**
- Create: `tools/woopayments-merge/a5f-cutover-rehearsal.py`

- [x] **Step 1: Implement command parsing and progress output.**

The script should accept `--target-wp`, `--target-url`, `--store-dir`, `--playwriter-session`, `--out-dir`, and optional `--skip-wpcom-readiness`. It must print one `==> ...` line before every substantive action and flush output so long-running runs are visible.

```python
parser.add_argument( '--target-wp', required=True, help='Local target WP-CLI command, for example: docker exec -i <target-cli> wp --allow-root --user=1' )
parser.add_argument( '--target-url', default='http://store8889.localhost:8889' )
parser.add_argument( '--store-dir', default='/Users/vladolaru/Work/a8c/woocommerce-develop-2' )
parser.add_argument( '--playwriter-session', required=True )
parser.add_argument( '--out-dir', default='.agents/scratchpad/sessions/2026-06-15-core-native-payments/data/a5f-post-a4as-cutover' )
parser.add_argument( '--skip-wpcom-readiness', action='store_true' )
```

- [x] **Step 2: Reject unsafe WP command shapes.**

Reuse the A4aq local-command discipline: reject commands containing shell control syntax, `--http`, `ssh`, `wpcom`, or remote transports. The A5f orchestrator may run only local Docker WP-CLI.

```python
if any( token in target_wp for token in ( ';', '&&', '||', '`', '$(', '\n', '--http=', ' ssh ', 'wpcom ' ) ):
    raise SystemExit( 'Refusing unsafe or remote target WP command.' )
```

- [x] **Step 3: Add helpers for WP-CLI eval-file, plugin activation state, MU helper install/remove, Playwriter runs, and JSON rollup.**

The WP-CLI eval helper must pipe local ignored PHP probe content to `wp eval-file -` because `tools/woopayments-merge` is not mounted inside the target container. MU helpers can be installed through a WP-CLI `eval` that writes exact local helper content into `wp-content/mu-plugins/a5f-*.php`, and cleanup must delete only those exact A5f helper files.

```python
def run_wp_eval_file(label, php_path, extra_args=None):
    command = shlex.split(args.target_wp) + [ 'eval-file', '-' ]
    if extra_args:
        command.extend(extra_args)
    php = pathlib.Path(php_path).read_text()
    return run_command(label, command, input_text=php, parse_json=True)
```

- [x] **Step 4: Write partial evidence after every phase.**

The rollup should include `gate: a5f-post-a4as-cutover-rehearsal`, `status`, `phase_results`, `state_snapshots`, `browser_evidence_paths`, `wpcom_readiness`, `transport_continuity`, `log_window`, `failures`, and `pass`. A failed phase must write the rollup before exiting non-zero.

Implementation note: failed Playwriter gates now copy updated source evidence into the A5f output directory before rethrowing so the rollup never points at stale copied evidence from an earlier run.

## Task 2: Run Current-State and Soft Handoff

**Files:**
- Modify if needed: `tools/woopayments-merge/a5-cutover-browser-gate.playwriter.mjs`
- Output: `.agents/scratchpad/sessions/2026-06-15-core-native-payments/data/a5f-post-a4as-cutover/*`

Implementation note: the soft, mandatory, and blocked browser gates now create and close their own page per run to prevent old target tabs from consuming one-shot cutover notices or producing background debug-log noise.

- [x] **Step 1: Record the native-owned baseline.**

Run `a5-cutover-state.php` via `wp eval-file -` before changing target state. Expected current baseline: `runtime_owner=native`, `plugin_runtime_active=false`, `should_native_register=true`, `preflight_failures=[]`, `ready=true`, and `soft_notice=false`.

- [x] **Step 2: Activate the standalone WooPayments plugin locally and record plugin-owned readiness.**

Run `wp plugin activate woocommerce-payments --allow-root` through the target WP command, then run `a5-cutover-state.php` again. Expected: `runtime_owner=plugin`, `plugin_runtime_active=true`, `should_native_register=false`, `preflight_failures=[]`, `ready=true`, and `soft_notice=true`.

- [x] **Step 3: Run the real soft cutover Playwriter gate.**

Run the existing browser gate against the provided Playwriter session and record a copied A5f-named evidence file in the A5f output directory. Expected: authenticated WP Admin, soft notice visible, real `Disable WooPayments` control clicked, success notice visible, no target failed responses, no unignored console issues, and WooPayments inactive afterward.

```bash
playwriter -s "$SESSION" -f tools/woopayments-merge/a5-cutover-browser-gate.playwriter.mjs --timeout 300000
```

- [x] **Step 4: Record post-soft native ownership.**

Run `a5-cutover-state.php` again. Expected: native-owned, plugin inactive, preflight clean, and no soft notice.

## Task 3: Run Mandatory, Activation Guard, and Blocked Handoff

**Files:**
- Modify if needed: `tools/woopayments-merge/a5-mandatory-browser-gate.playwriter.mjs`
- Reuse: `tools/woopayments-merge/a5-mandatory-cutover-mu-plugin.php`
- Reuse: `tools/woopayments-merge/a5-preflight-blocker-mu-plugin.php`

- [x] **Step 1: Prove mandatory auto-deactivation remains opt-in and succeeds only under the temporary mandatory helper.**

Activate WooPayments, install the A5f mandatory MU helper, load `wp-admin/plugins.php` through the mandatory Playwriter gate, and assert the success notice plus inactive WooPayments state. Expected post-state: native-owned, plugin inactive, preflight clean.

- [x] **Step 2: Prove the mandatory activation guard blocks reactivation only while the temporary mandatory helper is present.**

With the mandatory helper still installed and preflight clean, attempt `wp plugin activate woocommerce-payments`. Expected: non-zero exit and the product activation-block message. Remove the mandatory helper, attempt activation again only if cleanup needs to prove default-off behavior, and restore native-owned state afterward.

Implementation note: the orchestrator now records this as a passed expected-failure phase with `observed_exit_code`, so the rollup does not leave an intentional guard failure as a failing phase.

- [x] **Step 3: Prove a synthetic preflight blocker keeps mandatory cutover fail-closed.**

Install both the mandatory helper and the synthetic blocker helper, activate WooPayments, load a WP Admin page, and assert the plugin stays active and the blocked notice appears. Expected state: `runtime_owner=plugin`, `plugin_runtime_active=true`, `preflight_failures` includes the synthetic blocker, and no automatic deactivation occurs. Remove the blocker and mandatory helpers afterward and deactivate WooPayments to restore native ownership.

Implementation note: the blocked mandatory proof now uses `a5-blocked-mandatory-browser-gate.playwriter.mjs` and a real `wp-admin/plugins.php` request instead of manually firing `admin_init` from WP-CLI.

## Task 4: Re-run A5 Platform and Transport Probes

**Files:**
- Reuse: `tools/woopayments-merge/a5-local-wpcom-readiness.sh`
- Reuse: `tools/woopayments-merge/a5-user-token-readiness.php`
- Reuse: `tools/woopayments-merge/a5-transport-continuity.php`

- [x] **Step 1: Run local WPCOM readiness from the target store directory.**

Run `a5-local-wpcom-readiness.sh` unless `--skip-wpcom-readiness` is explicitly provided. Expected: env, doctor, identity, transact, tracks, and store doctor all report healthy/ok/running/connected/success/warning with zero failed checks. If the tool reports unavailable local env, record it as a gate failure instead of claiming readiness.

- [x] **Step 2: Run the owner user-token readiness probe through `wp eval-file -`.**

Expected JSON: Jetpack options and connection manager available, blog ID present, store connected, connection owner present, owner user-token present, native transport connected, platform service available, no platform readiness failures, and `ready=true`.

- [x] **Step 3: Run the transport continuity probe through `wp eval-file -`.**

Expected JSON: captured WCPay V1 `POST /sites/{id}/wcpay/accounts`, no `/transact` path, `Content-Type`, `User-Agent`, `Idempotency-Key`, `X-Request-Initiated`, no idempotency key leaked into the body, `use_user_token=true`, and `ready=true`.

## Task 5: Logs, Reviews, Documentation, and Product Fixes if Needed

**Files:**
- Update: `.agents/scratchpad/sessions/2026-06-15-core-native-payments/implementation-log.md`
- Update: `.agents/scratchpad/sessions/2026-06-15-core-native-payments/staging-log.md`
- Update: `.agents/scratchpad/sessions/2026-06-15-core-native-payments/spec-conformance-baseline.md`
- Update: `.agents/scratchpad/sessions/2026-06-15-core-native-payments/README.md`
- Modify product files only if a real defect is found.

- [x] **Step 1: Clear and scan fresh target logs around the rehearsal window.**

Clear target `wp-content/debug.log` before browser gates, record the UTC start marker, and scan target WordPress/debug/WC logs plus local WPCOM web/jobs logs for PHP notices, warnings, deprecations, fatals, uncaught exceptions, database errors, stack traces, and actual 5xx markers. Treat source-backed target warnings as failures.

- [x] **Step 2: Run focused PHP coverage if product code changes.**

If A5f exposes a product defect and source/tests change, run `WooPaymentsCutoverControllerTest`, related readiness tests, syntax, changed-file PHPCS, PHPStan over changed production files, changelog validation, `git diff --check -- . ':!.agents'`, and branch lint. If no product code changes, run syntax checks for the new Python script and the relevant ignored harness scripts.

- [x] **Step 3: Dispatch reviews at the right boundary.**

If product code changes, dispatch reliability and WordPress architecture reviewers over the diff. If the slice remains harness/evidence only, dispatch one reliability or decision review over the A5f rollup and plan conformance.

Review note: decision reviewer Socrates the 6th returned `STAND` for the A5f rollup and browser evidence. Residual hygiene caveats are scoped and non-blocking: top-level rollup keys for WPCOM/transport summaries are absent even though phase JSON is present, soft/mandatory source gate labels still say `a5e-*`, and final log evidence is target-debug-log centered.

- [x] **Step 4: Record the A5f result as the current cutover baseline.**

Update the analysis, implementation log, staging log, spec baseline, and README with the exact evidence paths, pass/fail state, remaining A5 blockers, and target restore state. Keep the paragraph lines unwrapped and do not lint scratchpad docs.

- [x] **Step 5: Commit only Git-visible product changes.**

If product files changed, add one WooCommerce changelog entry and commit one logical product change. Do not force-add ignored `tools/woopayments-merge` or scratchpad evidence. If A5f is harness/evidence-only, leave those local changes uncommitted unless the user explicitly asks otherwise.

## Self-Review

This plan covers the post-A4as sequencing gap surfaced in `analysis-a5-next-slice-reground.md`: old A5e evidence is useful but predates the final A4ar/A4as readiness line. The plan does not re-open A4, does not enable mandatory cutover by default, does not access or change WPCOM, does not use remote WP-CLI, and does not weaken the existing A5 blockers. It uses bigger implementation chunks by building one orchestration gate rather than hand-running the same fragmented probes again.
