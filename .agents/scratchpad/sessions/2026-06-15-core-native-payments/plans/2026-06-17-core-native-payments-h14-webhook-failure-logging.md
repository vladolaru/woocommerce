---
session: 2026-06-15-core-native-payments
type: plan
by: codex
created: 2026-06-17 18:59
status: final
last_updated: 2026-06-17 19:08
---

# Webhook Failure Logging Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use $subagent-driven-development (recommended) or $executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Preserve reference WooPayments local logging on native webhook failure paths without changing the webhook response contract.

**Architecture:** Keep webhook handling owned by `WooPaymentsWebhookRestController`, but inject the existing `WooPaymentsLegacyRuntime` logger seam instead of calling `wc_get_logger()` directly. The REST controller catches invalid payload and processing exceptions, logs through the runtime logger with source `native-payments-webhook`, then returns the same `bad_request` or `error` envelope and status it returns today.

**Tech Stack:** WooCommerce Core PHP in `plugins/woocommerce/src/Internal`, Core DI `init()` methods, PHPUnit REST controller tests, PHPStan, PHPCS.

---

## Files

- Modify: `plugins/woocommerce/src/Internal/Payments/Providers/WooPayments/WooPaymentsWebhookRestController.php` to inject `WooPaymentsLegacyRuntime` and log caught exceptions.
- Modify: `plugins/woocommerce/tests/php/src/Internal/Payments/Providers/WooPayments/WooPaymentsWebhookRestControllerTest.php` to assert bad-request and generic exception paths log locally while preserving response status/body.
- Modify session docs: `implementation-log.md`, `staging-log.md`, and `review-agent-findings.md` as evidence warrants.
- Add changelog before commit if the source/test package lands cleanly.

## Tasks

### Task 1: RED Webhook Failure Logging Tests

- [x] Add a recording logger test double to `WooPaymentsWebhookRestControllerTest`.
- [x] Add a helper that creates `WooPaymentsLegacyRuntime`, initializes it with `LegacyRuntimeProxy( true, null, null, null, $logger )`, and passes it to the controller `init()` call.
- [x] Update `test_bad_payload_returns_bad_request_envelope()` so the ingestor throws `InvalidArgumentException( 'bad payload' )`, the response remains `400` with `{ result: 'bad_request' }`, and the logger receives one `error()` entry with source `native-payments-webhook` and message containing `bad payload`.
- [x] Update `test_processing_exception_returns_error_envelope()` so the ingestor throws `RuntimeException( 'server failed' )`, the response remains `500` with `{ result: 'error' }`, and the logger receives one `error()` entry with source `native-payments-webhook` and message containing `server failed`.
- [x] Run `pnpm --filter='@woocommerce/plugin-woocommerce' test:php:env -- --filter 'WooPaymentsWebhookRestControllerTest'`.
- [x] Expected RED: the controller does not log the exception paths.

### Task 2: GREEN Native Logging Implementation

- [x] Modify `WooPaymentsWebhookRestController::init()` to accept `WooPaymentsLegacyRuntime $legacy_runtime` and store it.
- [x] In both catch blocks in `handle_webhook()`, call a new private `log_webhook_exception( Throwable $exception )` method before returning the existing response envelope.
- [x] Implement `log_webhook_exception()` by calling `$this->legacy_runtime->get_logger()`, bailing if the logger is missing or has no `error()` method, and otherwise calling `$logger->error( $exception->getMessage(), array( 'source' => 'native-payments-webhook' ) )`.
- [x] Add and RED/GREEN verify `test_logger_failures_do_not_replace_webhook_error_envelope()` after review found logger write failures could escape the webhook failure handler.
- [x] Run `pnpm --filter='@woocommerce/plugin-woocommerce' test:php:env -- --filter 'WooPaymentsWebhookRestControllerTest'`.
- [x] Expected GREEN: existing response contracts still pass and both failure paths produce a local error log entry.

### Task 3: Focused Gates, Review, and Commit

- [x] Run related webhook/event tests: `pnpm --filter='@woocommerce/plugin-woocommerce' test:php:env -- --filter 'WooPaymentsWebhookRestControllerTest|WooPaymentsEventIngestorTest|WooPaymentsWebhookReliabilityServiceTest'`.
- [x] Run PHP syntax for the touched source and test files.
- [x] Run PHPStan for `WooPaymentsWebhookRestController.php`.
- [x] Run `pnpm --filter=@woocommerce/plugin-woocommerce lint:php:changes` and `git diff --check`.
- [x] Dispatch a focused reliability review if a subagent slot is available.
- [x] Add a changelog file, commit source/tests, commit changelog, and run/report the git range.
- [x] Update `implementation-log.md`, `staging-log.md`, and `review-agent-findings.md` with the blocker closure, review result, gates, commit range, and any residual limitations.

## Self-Review

- Spec coverage: closes Fermat's source-backed webhook failure logging blocker for native invalid-payload and unexpected-exception paths. It does not claim provider-event readiness or failed-event replay completeness.
- Placeholder scan: no TODO/TBD/open implementation placeholders.
- Type consistency: production uses `WooPaymentsLegacyRuntime`; tests use the existing `LegacyRuntimeProxy` logger seam already used by other WooPayments tests.
