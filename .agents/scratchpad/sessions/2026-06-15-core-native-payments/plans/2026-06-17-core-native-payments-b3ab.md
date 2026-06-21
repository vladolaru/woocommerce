---
session: 2026-06-15-core-native-payments
type: plan
by: codex
created: 2026-06-17 02:30
tool: writing-plans
reconciles:
  - implementation-log.md
  - staging-log.md
  - plans/2026-06-17-core-native-payments-b3y.md
  - plans/2026-06-17-core-native-payments-b3z.md
  - plans/2026-06-17-core-native-payments-b3aa.md
status: final
last_updated: 2026-06-17 02:41
---

# Core Native Payments B3ab Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use $executing-plans to implement this plan task-by-task. Use $test-driven-development before production code changes and preserve the restored harness/browser/log gates.

**Goal:** Harden the WooPayments mandatory cutover path so the default-off mandatory phase behaves like WooCommerce's merged-package handoff once explicitly enabled, while keeping the manual one-click disable flow capability-gated and preserving Settings Payments plus checkout parity after handoff.

**Architecture:** The manual button remains an administrator action and continues to require `manage_woocommerce` plus plugin activation capability. The mandatory phase is a Core-owned merged-feature safety mechanism: when the rollout filter is enabled, Core should auto-deactivate the standalone WooPayments plugin if native preflight passes, without depending on the current request's user capability state. This mirrors `Packages::deactivate_merged_packages()` and keeps money safety through the arbiter: plugin owns the overlap request, native owns the next request after deactivation. The activation guard remains mandatory-only, preflight-gated, and bypassable with `WC_ALLOW_MERGED_FEATURE_PLUGINS` for local/developer parallel testing.

**Tech Stack:** WooCommerce Core PHP, wp-env PHPUnit, PHPStan, PHPCS, restored WooPayments merge harness, browser verification against target/reference local stores, Docker log scans.

## Tasks

- [x] **Task 1: Add mandatory cutover RED coverage**

Modify `plugins/woocommerce/tests/php/src/Internal/Payments/Providers/WooPayments/WooPaymentsCutoverControllerTest.php` to prove that mandatory auto-deactivation does not require current-user cutover capabilities when the mandatory rollout filter is enabled, plugin runtime owns the request, native runtime is enabled, and native provider preflight is ready. Drive the behavior through `handle_admin_init()` because `maybe_auto_deactivate_plugin()` is private. Expected RED: no `deactivate_plugins` call because `disable_woopayments_plugin()` currently applies the manual capability gate to the mandatory path.

Also add coverage that `WC_ALLOW_MERGED_FEATURE_PLUGINS` bypasses mandatory auto-deactivation, matching the existing activation-guard bypass and the local merged-feature precedent. Expected RED if the mandatory path ignores the developer bypass.

Run:

```bash
pnpm --filter='@woocommerce/plugin-woocommerce' test:php:env -- --filter 'WooPaymentsCutoverControllerTest'
```

- [x] **Task 2: Split manual and mandatory deactivation guards**

Modify `plugins/woocommerce/src/Internal/Payments/Providers/WooPayments/WooPaymentsCutoverController.php` so `disable_woopayments_plugin()` remains the capability-gated manual path, while mandatory auto-deactivation uses an internal helper that checks plugin ownership, native preflight, and `WC_ALLOW_MERGED_FEATURE_PLUGINS`, but not `current_user_can_cutover()`. Keep a single low-level deactivation helper so network-active handling and success checks stay identical for both paths.

Expected behavior: manual disable without capabilities still fails closed; mandatory auto-deactivation with rollout enabled and ready preflight deactivates; mandatory auto-deactivation with failed preflight stays blocked; developer parallel mode bypasses auto-deactivation and activation guard.

- [x] **Task 3: Verify focused regressions and related cutover behavior**

Run focused and related PHPUnit:

```bash
pnpm --filter='@woocommerce/plugin-woocommerce' test:php:env -- --filter 'WooPaymentsCutoverControllerTest|NativePaymentsRuntimeArbiterTest|NativePaymentsGatewayRegistryTest|WooPaymentsProviderTest'
```

Run PHP syntax, PHPStan, changed PHP lint, staged lint after staging, and diff checks for the touched PHP files:

```bash
php -l plugins/woocommerce/src/Internal/Payments/Providers/WooPayments/WooPaymentsCutoverController.php
php -l plugins/woocommerce/tests/php/src/Internal/Payments/Providers/WooPayments/WooPaymentsCutoverControllerTest.php
composer exec --working-dir=plugins/woocommerce -- phpstan analyse src/Internal/Payments/Providers/WooPayments/WooPaymentsCutoverController.php tests/php/src/Internal/Payments/Providers/WooPayments/WooPaymentsCutoverControllerTest.php --memory-limit=2G
pnpm --filter=@woocommerce/plugin-woocommerce lint:php:changes
git diff --check
```

- [x] **Task 4: Exercise mandatory cutover locally**

Use the target local store only. Temporarily activate WooPayments on target, enable native runtime and mandatory cutover through a local probe/filter or WP-CLI-controlled MU plugin, then trigger an admin request or WP-CLI invocation that runs `handle_admin_init()`. Assert the plugin deactivates and a fresh state probe reports `owner=native`, `native_enabled=yes`, provider ready, and empty preflight. Then remove the temporary probe so the local store returns to the normal native-enabled target state used by the harness.

Also verify the developer bypass by setting `WC_ALLOW_MERGED_FEATURE_PLUGINS` in the probe context and confirming the plugin remains active. Do not change WPCOM code or access any WPCOM sandbox.

- [x] **Task 5: Run harness, browser, logs, and review gates**

Run the restored cross-store harness:

```bash
bash tools/woopayments-merge/verify.sh --ref 'docker exec -i wcpay_wp_default wp --allow-root' --target 'docker exec -i 24860d14de30dc62f7b324ebef10b5fb-cli-1 wp --allow-root --user=1'
```

Browser-check target Settings Payments plus target classic and Blocks checkout after the mandatory cutover exercise. Expected: Settings Payments provider list loads with one Core-owned WooPayments row; classic and Blocks checkout still expose WooPayments test-mode guidance and Stripe iframe/session behavior. Compare the reference store when any UI difference appears.

Scan target/reference logs for fresh PHP notices, warnings, deprecations, fatals, stack traces, database errors, or checkout JS/network failures. Treat fresh notices as failures unless clearly self-caused by probes and timestamp-dispositioned.

Request a read-only code review focused on cutover safety, manual/mandatory separation, developer bypass behavior, network-active handling, and no Settings Payments/checkout regression. Fix all critical/high/medium findings before committing.

- [x] **Task 6: Changelog, session logs, and commit**

Create `plugins/woocommerce/changelog/add-native-payments-b3ab-mandatory-cutover`:

```text
Significance: patch
Type: dev
Comment: Harden native WooPayments mandatory plugin cutover.
```

Update `implementation-log.md`, `staging-log.md`, and `README.md` with final evidence. Commit source/tests and changelog as separate logical commits if both are present. Do not commit scratchpad or harness files, do not push, and do not touch WPCOM.
