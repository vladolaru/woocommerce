---
session: 2026-06-15-core-native-payments
type: plan
by: codex
created: 2026-06-20 17:48
last_updated: 2026-06-20 17:59
status: final
---

# A4as Admin Readiness Decision Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use $subagent-driven-development (recommended) or $executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Make the native admin-surfaces cutover preflight default ready after the A4ar/N12 accumulated gate passed, while preserving the explicit fail-closed filter override and recording any remaining non-admin cutover blockers honestly.

**Architecture:** Keep the existing `woocommerce_woopayments_native_admin_surfaces_ready` filter as the safety valve. Change only the default readiness decision now that the stage gate evidence is green. Do not weaken platform, provider-event, queue, fee-remediation, or legacy Stripe Billing blockers.

**Tech Stack:** WooCommerce PHP backend, PHPUnit through `pnpm --filter=@woocommerce/plugin-woocommerce test:php:env`, local WP-CLI preflight probe through `tools/woopayments-merge/a5-cutover-state.php`, scratchpad evidence under `.agents/scratchpad/sessions/2026-06-15-core-native-payments`.

---

### Task 1: Update Cutover Readiness Tests And Default

**Files:**
- Modify: `plugins/woocommerce/tests/php/src/Internal/Payments/Providers/WooPayments/WooPaymentsCutoverControllerTest.php`
- Modify: `plugins/woocommerce/src/Internal/Payments/Providers/WooPayments/WooPaymentsCutoverController.php`

- [x] Write the RED test by changing `test_preflight_defaults_admin_surfaces_to_unavailable_until_n12_parity_gate_passes()` so it asserts the post-A4ar default no longer includes `native_admin_surfaces_unavailable` when other deterministic blockers are clear.

- [x] Run the focused test to verify RED.

Run:

```bash
pnpm --filter=@woocommerce/plugin-woocommerce test:php:env -- --filter WooPaymentsCutoverControllerTest::test_preflight_defaults_admin_surfaces_ready_after_n12_parity_gate_passes
```

Expected: fail because `native_admin_surfaces_unavailable` is still present.

Result: failed as expected with one assertion failure because `native_admin_surfaces_unavailable` was still present.

- [x] Implement the minimal production change in `WooPaymentsCutoverController::get_preflight_failures()` by changing the filter default from `false` to `true`.

- [x] Verify the focused test passes and the explicit false override test still blocks.

Run:

```bash
pnpm --filter=@woocommerce/plugin-woocommerce test:php:env -- --filter "WooPaymentsCutoverControllerTest::test_preflight_defaults_admin_surfaces_ready_after_n12_parity_gate_passes|WooPaymentsCutoverControllerTest::test_preflight_blocks_when_native_admin_surfaces_are_unavailable"
```

Expected: both tests pass.

Result: passed with 2 tests and 4 assertions.

### Task 2: Verify Product And Local Preflight Behavior

**Files:**
- Evidence: `.agents/scratchpad/sessions/2026-06-15-core-native-payments/data/a4as-cutover-state.json`

- [x] Run the focused cutover controller suite.

Run:

```bash
pnpm --filter=@woocommerce/plugin-woocommerce test:php:env -- --filter WooPaymentsCutoverControllerTest
```

Expected: pass.

Result: passed with 37 tests and 91 assertions.

- [x] Run static checks for touched PHP.

Run:

```bash
php -l plugins/woocommerce/src/Internal/Payments/Providers/WooPayments/WooPaymentsCutoverController.php
php -l plugins/woocommerce/tests/php/src/Internal/Payments/Providers/WooPayments/WooPaymentsCutoverControllerTest.php
pnpm --filter=@woocommerce/plugin-woocommerce lint:php:changes
cd plugins/woocommerce && composer exec -- phpstan analyse src/Internal/Payments/Providers/WooPayments/WooPaymentsCutoverController.php --memory-limit=2G
```

Expected: pass or only existing unrelated tool noise with exit 0.

Result: PHP syntax, changed-file PHPCS, and production PHPStan passed. Composer/PHP 8.4 vendor deprecation output was non-blocking with exit 0.

- [x] Run the local target preflight state probe.

Run:

```bash
docker exec -i 24860d14de30dc62f7b324ebef10b5fb-cli-1 wp --allow-root --user=1 eval-file /var/www/html/wp-content/plugins/woocommerce/tools/woopayments-merge/a5-cutover-state.php > .agents/scratchpad/sessions/2026-06-15-core-native-payments/data/a4as-cutover-state.json
```

Expected: `native_admin_surfaces_unavailable` is absent. If other blockers remain, record them by name rather than masking them.

Result: `data/a4as-cutover-state.json` reports `ready: true`, empty `preflight_failures`, and empty `failures`. The first path-based WP-CLI command failed because ignored harness files are not mounted inside the target container; reran by piping the local probe into `wp eval-file -`.

### Task 3: Review, Logs, Docs, Changelog, Commit

**Files:**
- Create: `plugins/woocommerce/changelog/fix-native-woopayments-admin-readiness`
- Modify: `.agents/scratchpad/sessions/2026-06-15-core-native-payments/analysis-a4as-admin-readiness-decision.md`
- Modify: `.agents/scratchpad/sessions/2026-06-15-core-native-payments/implementation-log.md`
- Modify: `.agents/scratchpad/sessions/2026-06-15-core-native-payments/staging-log.md`
- Modify: `.agents/scratchpad/sessions/2026-06-15-core-native-payments/spec-conformance-baseline.md`
- Modify: `.agents/scratchpad/sessions/2026-06-15-core-native-payments/README.md`

- [x] Reconcile the decision-review subagent result. If it requests changes, verify against source before acting.

- [x] Add a WooCommerce changelog entry for the readiness default change.

- [x] Run final hygiene gates.

Run:

```bash
pnpm --filter=@woocommerce/plugin-woocommerce changelog validate
git diff --check -- . ':!.agents'
pnpm --filter=@woocommerce/plugin-woocommerce lint:changes:branch
```

Expected: pass, allowing only known broad branch ignored-file warnings with exit 0.

Result: changelog validation, `git diff --check -- . ':!.agents'`, and branch lint passed. Branch JS lint emitted known broad ignored-file warnings and PHP branch lint completed with `Done`.

- [x] Update session docs with the exact evidence paths, review disposition, remaining blockers if any, and no WPCOM/push/trunk activity.

- [x] Commit source/tests and changelog as separate logical commits if all product gates pass. Do not commit scratchpad or ignored harness files.

Result: the changelog entry was already committed before this continuation as `2555f71968`. The source/test change was committed as `e500c60f96` with git range `6cbb4314c2...e500c60f96`.
