---
session: 2026-06-15-core-native-payments
type: plan
by: codex
created: 2026-06-20 00:52
target: A5d native admin readiness flip after A4ab
reconciles:
  - ../analysis-a4ac-a5-readiness-selection.md
  - ../analysis-a4ab-admin-exit-gate.md
  - ../spec-conformance-baseline.md
  - ../staging-log.md
status: final
last_updated: 2026-06-20 01:09
---

# A5d Native Admin Readiness Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use $subagent-driven-development (recommended) or $executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Let native WooPayments cutover preflight stop failing solely on `native_admin_surfaces_unavailable` now that A4ab/N10 has passed, while preserving every other hard cutover blocker and keeping mandatory cutover default-off.

**Architecture:** Keep readiness owned by `WooPaymentsCutoverController`. Change only the default value of `FILTER_NATIVE_ADMIN_SURFACES_READY` from fail-closed false to verified true, with tests proving an explicit filter can still block admin readiness and all platform, event, queue, financial, and Stripe Billing blockers remain authoritative. Treat the A4ab source/browser/log gate plus A5 local WPCOM/user-token/transport probes as the stage-boundary evidence for this readiness flip.

**Tech Stack:** WooCommerce PHP unit tests, `WooPaymentsCutoverController`, ignored local `tools/woopayments-merge` A4/A5 probes, Playwriter browser gate, WooCommerce changelog tooling.

---

## File Map

- Modify `plugins/woocommerce/src/Internal/Payments/Providers/WooPayments/WooPaymentsCutoverController.php`: default the native admin readiness filter to true.
- Modify `plugins/woocommerce/tests/php/src/Internal/Payments/Providers/WooPayments/WooPaymentsCutoverControllerTest.php`: replace the default-blocking admin readiness assertion with a default-ready assertion and keep the explicit false-filter blocker test.
- Add a WooCommerce changelog entry under `plugins/woocommerce/changelog/`.
- Update `.agents/scratchpad/sessions/2026-06-15-core-native-payments/implementation-log.md`, `staging-log.md`, `spec-conformance-baseline.md`, `README.md`, and this plan with final evidence. Do not lint scratchpad markdown.

## Task 1: TDD The Admin Readiness Default

- [x] **Step 1: Write the RED test.**

In `WooPaymentsCutoverControllerTest.php`, replace `test_preflight_defaults_to_blocking_until_native_admin_surfaces_are_verified()` with a test named `test_preflight_defaults_to_ready_after_native_admin_surfaces_are_verified()`. The test should enable native runtime, keep provider/event/queue/financial/platform/legacy state clean, avoid adding `FILTER_NATIVE_ADMIN_SURFACES_READY`, and assert `native_admin_surfaces_unavailable` is not present and the soft notice is shown.

Expected test body:

```php
/**
 * @testdox Cutover preflight defaults admin surfaces to ready after the A4 exit gate passes.
 */
public function test_preflight_defaults_to_ready_after_native_admin_surfaces_are_verified(): void {
	$this->fake_plugin_active();
	$this->fake_current_user_caps( true );
	add_filter( NativePaymentsRuntimeArbiter::FILTER_NATIVE_ENABLED, '__return_true' );
	add_filter( WooPaymentsCutoverController::FILTER_PROVIDER_EVENT_TYPES_PENDING_CUTOVER, '__return_empty_array' );
	add_filter( WooPaymentsCutoverController::FILTER_OPERATIONAL_QUEUE_HOOKS_PENDING_CUTOVER, '__return_empty_array' );
	$this->native_provider_ready = true;

	$failures = $this->sut->get_preflight_failures();

	$this->assertNotContains( 'native_admin_surfaces_unavailable', $failures );
	$this->assertTrue( $this->sut->should_show_soft_cutover_notice() );
}
```

- [x] **Step 2: Verify RED.**

Run:

```bash
pnpm --filter=@woocommerce/plugin-woocommerce test:php:env -- --filter WooPaymentsCutoverControllerTest::test_preflight_defaults_to_ready_after_native_admin_surfaces_are_verified
```

Expected: FAIL because `native_admin_surfaces_unavailable` is still present.

- [x] **Step 3: Write the minimal GREEN implementation.**

In `WooPaymentsCutoverController::get_preflight_failures()`, change the admin readiness filter default from `false` to `true`:

```php
if ( ! (bool) apply_filters( self::FILTER_NATIVE_ADMIN_SURFACES_READY, true ) ) {
	$failures[] = 'native_admin_surfaces_unavailable';
}
```

- [x] **Step 4: Verify GREEN and guard regressions.**

Run:

```bash
pnpm --filter=@woocommerce/plugin-woocommerce test:php:env -- --filter WooPaymentsCutoverControllerTest
```

Expected: PASS. This must still include `test_preflight_blocks_when_native_admin_surfaces_are_unavailable()` proving an explicit false filter can block readiness, and the platform/legacy/financial blocker tests must still pass.

## Task 2: Run A5 Readiness And A4 Exit Evidence

- [x] **Step 1: Run focused PHP/code-quality gates.**

Run:

```bash
php -l plugins/woocommerce/src/Internal/Payments/Providers/WooPayments/WooPaymentsCutoverController.php
php -l plugins/woocommerce/tests/php/src/Internal/Payments/Providers/WooPayments/WooPaymentsCutoverControllerTest.php
pnpm --filter=@woocommerce/plugin-woocommerce test:php:env -- --filter 'WooPaymentsCutoverControllerTest|WooPaymentsPlatformConnectionServiceTest|WooPaymentsOperationalQueueServiceTest|WooPaymentsEventIngestorTest|NativeWooPaymentsGatewayTest'
pnpm --filter=@woocommerce/plugin-woocommerce lint:php:changes
cd plugins/woocommerce && composer exec -- phpstan analyse src/Internal/Payments/Providers/WooPayments/WooPaymentsCutoverController.php --memory-limit=2G
```

Expected: all pass. If PHPStan or lint reports unrelated branch-wide noise, verify it is unrelated before recording it.

- [x] **Step 2: Rerun A4 source/chunk gate.**

Run:

```bash
python3 tools/woopayments-merge/a4-admin-surface-gate.py --repo /Users/vladolaru/Work/a8c/woocommerce-develop-2 --plugin-repo /Users/vladolaru/Work/a8c/woocommerce-payments --out .agents/scratchpad/sessions/2026-06-15-core-native-payments/data/a5d-admin-surface-gate.json
```

Expected: PASS. Record native and reference byte totals.

- [x] **Step 3: Rerun A4 browser/log gate.**

Run:

```bash
SESSION_ID="$(npx --yes playwriter@latest session new | tail -n 1)"
npx --yes playwriter@latest -s "$SESSION_ID" -f tools/woopayments-merge/a4-admin-browser-gate.playwriter.mjs --timeout 300000
```

Expected: PASS with zero target failures. Copy or rename the resulting evidence to `data/a5d-admin-browser-gate.json` if the harness still writes the A4ab filename, so A5d has its own immutable evidence file.

After the browser run, scan target/reference/local-WPCOM logs for fresh PHP/WP notices, warnings, deprecations, fatals, uncaught errors, stack traces, database errors, and fresh HTTP 4xx/5xx markers. Fresh target diagnostics are blockers.

- [x] **Step 4: Rerun A5 local readiness probes.**

Find the current target CLI container with `docker ps --format '{{.Names}}' | rg 'cli'`, then run:

```bash
tools/woopayments-merge/a5-local-wpcom-readiness.sh plugins/woocommerce
docker exec -i <target-cli-container> wp --allow-root --user=1 eval-file /var/www/html/wp-content/plugins/woocommerce/tools/woopayments-merge/a5-user-token-readiness.php
docker exec -i <target-cli-container> wp --allow-root --user=1 eval-file /var/www/html/wp-content/plugins/woocommerce/tools/woopayments-merge/a5-transport-continuity.php
docker exec -i <target-cli-container> wp --allow-root --user=1 eval 'echo wp_json_encode( array( "preflight_failures" => wc_get_container()->get( Automattic\\WooCommerce\\Internal\\Payments\\Providers\\WooPayments\\WooPaymentsCutoverController::class )->get_preflight_failures() ) );'
```

Expected: local WPCOM readiness passes, user-token probe reports `ready=true`, transport probe reports `ready=true`, and preflight failures are empty on the target connected native store. If the target store contains intentional legacy Stripe Billing fixture data, do not clear it silently; record the blocker instead.

## Task 3: Review, Record, And Commit

- [x] **Step 1: Request focused reviews.**

Dispatch at least one reliability reviewer and one architecture/API-contract reviewer over the A5d diff and evidence. Required review focus: the default readiness flip must not weaken platform, event, queue, financial, or legacy Stripe Billing blockers; A4/N10 evidence must be tied to the readiness flip; mandatory cutover must remain default-off.

- [x] **Step 2: Add changelog and final checks.**

Add a WooCommerce changelog entry, then run:

```bash
pnpm --filter=@woocommerce/plugin-woocommerce changelog validate
git diff --check -- . ':!.agents'
pnpm --filter=@woocommerce/plugin-woocommerce lint:changes:branch
```

Expected: PASS, with only already-recorded branch-wide ignored-file warnings or PHP 8.4 vendor deprecation noise if present.

- [x] **Step 3: Update session docs.**

Update `implementation-log.md`, `staging-log.md`, `spec-conformance-baseline.md`, `README.md`, and this plan with exact test/gate outputs, evidence file names, reviewer verdicts, git range, and residual non-claims. Scratchpad markdown must not be hard-wrapped and must not be linted.

- [x] **Step 4: Commit local changes.**

Commit product source/tests/changelog only. Do not commit `.agents` or ignored harness files. Do not push. Use a single logical source/test commit plus changelog commit if that matches the branch convention.

## Exit Criteria

A5d is complete when the admin readiness default is TDD-backed, A4 source/browser/log gates and A5 local readiness probes pass on the target store, focused reviewers approve, changelog/quality gates pass, and the docs record that mandatory cutover remains default-off and all hard blockers remain fail-closed.

## Closeout Evidence

A5d closed on 2026-06-20 01:09 EEST. Source/tests committed as `e7612bf9e7` (`fix(payments): allow native admin readiness after gate`) and changelog committed as `2555f71968` (`chore(payments): add admin readiness changelog`), with git range `c293d2f1cc...2555f71968`.

Verification passed: RED cutover controller test failed before the implementation, GREEN focused `WooPaymentsCutoverControllerTest` passed with 32 tests and 78 assertions, the related readiness suite passed with 158 tests and 542 assertions, PHP syntax passed for the controller and test, changed-file PHPCS passed, PHPStan passed for `WooPaymentsCutoverController.php`, A4 source/chunk gate passed with `data/a5d-admin-surface-gate.json`, strict Playwriter browser gate passed with `data/a5d-admin-browser-gate.json` and 57 checks / 0 failures, A5 local WPCOM readiness passed, user-token readiness passed, transport continuity passed, target native preflight returned empty failures, target `debug.log` stayed empty, focused target/local-WPCOM log scans were clean, reliability and WordPress architecture reviews approved, changelog validation passed, `git diff --check -- . ':!.agents'` passed, branch lint exited 0 with known ignored-file JS warnings and PHP branch lint clean, and post-commit status was clean.

Mandatory cutover remains default-off. A5d does not claim new full pixel/copy/account-state parity beyond the A4ab/A5d route/chunk/runtime/log gate evidence.
