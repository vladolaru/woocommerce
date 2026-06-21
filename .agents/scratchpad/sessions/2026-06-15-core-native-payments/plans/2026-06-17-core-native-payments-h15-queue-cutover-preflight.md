---
session: 2026-06-15-core-native-payments
type: plan
by: codex
created: 2026-06-17 19:09
last_updated: 2026-06-17 19:19
status: final
---

# Queue Cutover Preflight Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use $subagent-driven-development (recommended) or $executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Make WooPayments native plugin cutover fail closed while legacy operational queue hooks still need native disposition.

**Architecture:** Keep `WooPaymentsOperationalQueueService` honest: do not register empty handlers for merchant-facing reminder/email hooks. Add a cutover preflight seam in `WooPaymentsCutoverController` that defaults to the two known pending queue hooks, can be explicitly cleared by a future port, and contributes a deterministic `operational_queue_hooks_undispositioned` failure code.

**Tech Stack:** WooCommerce Core PHP in `plugins/woocommerce/src/Internal`, Core DI `init()` methods, PHPUnit cutover tests, PHPStan, PHPCS.

---

## Files

- Modify: `plugins/woocommerce/src/Internal/Payments/Providers/WooPayments/WooPaymentsCutoverController.php` to add a pending operational queue hook preflight filter and failure.
- Modify: `plugins/woocommerce/tests/php/src/Internal/Payments/Providers/WooPayments/WooPaymentsCutoverControllerTest.php` to assert the default queue blocker and the explicit ready override.
- Modify session docs: `implementation-log.md`, `staging-log.md`, and `review-agent-findings.md` as evidence warrants.
- Add changelog before commit if the source/test package lands cleanly.

## Tasks

### Task 1: RED Queue Preflight Coverage

- [x] Add `WooPaymentsCutoverController::FILTER_OPERATIONAL_QUEUE_HOOKS_PENDING_CUTOVER` to test teardown cleanup even before production defines it.
- [x] Add `test_preflight_blocks_when_operational_queue_hooks_are_undispositioned()` that makes native runtime, transport, admin surfaces, and provider events ready, does not clear queue hooks, then asserts `get_preflight_failures()` contains `operational_queue_hooks_undispositioned` and the soft notice stays hidden.
- [x] Update `enable_ready_cutover()` so fully ready tests explicitly clear the pending operational queue hook list through the new filter.
- [x] Run `pnpm --filter='@woocommerce/plugin-woocommerce' test:php:env -- --filter 'WooPaymentsCutoverControllerTest'`.
- [x] Expected RED: the queue preflight filter/failure does not exist yet or the new assertion does not fail closed.

### Task 2: GREEN Queue Preflight Implementation

- [x] Add `FILTER_OPERATIONAL_QUEUE_HOOKS_PENDING_CUTOVER = 'woocommerce_woopayments_native_cutover_pending_operational_queue_hooks'`.
- [x] Add default pending hooks `wcpay_instant_deposit_reminder` and `wcpay_post_kyc_activation_email_send` to a private helper.
- [x] In `get_preflight_failures()`, append `operational_queue_hooks_undispositioned` when pending queue hooks are non-empty.
- [x] Implement `get_pending_operational_queue_hooks()` like `get_pending_provider_event_types()`: apply the filter, fail closed with `operational_queue_hooks_filter_invalid` when the filter returns a non-array, normalize values to unique non-empty strings.
- [x] Run `pnpm --filter='@woocommerce/plugin-woocommerce' test:php:env -- --filter 'WooPaymentsCutoverControllerTest'`.
- [x] Expected GREEN: existing ready-cutover helpers now pass only after explicitly clearing the pending queue hook list, and the new default-blocking test passes.

### Task 3: Focused Gates, Review, and Commit

- [x] Run related cutover/queue tests: `pnpm --filter='@woocommerce/plugin-woocommerce' test:php:env -- --filter 'WooPaymentsCutoverControllerTest|WooPaymentsOperationalQueueServiceTest|NativePaymentsRuntimeArbiterTest|NativePaymentsGatewayRegistryTest'`.
- [x] Run PHP syntax for touched source/test files.
- [x] Run PHPStan for `WooPaymentsCutoverController.php`.
- [x] Run `pnpm --filter=@woocommerce/plugin-woocommerce lint:php:changes` and `git diff --check`.
- [x] Dispatch a focused reliability/architecture review if a subagent slot is available.
- [x] Add a changelog file, commit source/tests, commit changelog, run branch lint, and report the git range.
- [x] Update `implementation-log.md`, `staging-log.md`, and `review-agent-findings.md` with the blocker closure, review result, gates, commit range, and residual limitations.

## Self-Review

- Spec coverage: closes the cutover-preflight part of Fermat's queue handoff blocker by making pending queue hooks deterministic preflight failures. It deliberately does not port the instant deposit reminder or post-KYC email behavior; those remain merchant-facing A4/A5 surface slices.
- Placeholder scan: no TODO/TBD/open implementation placeholders.
- Type consistency: failure code and filter names use "operational queue hooks" consistently across tests and production.
