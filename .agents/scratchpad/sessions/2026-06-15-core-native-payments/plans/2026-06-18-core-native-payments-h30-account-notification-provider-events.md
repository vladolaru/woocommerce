---
session: 2026-06-15-core-native-payments
type: plan
by: codex
created: 2026-06-18 08:40
target: H30 account and notification provider event migration
reconciles:
  - analysis-h30-account-notification-provider-events.md
  - spec-conformance-baseline.md
  - review-agent-findings.md
  - staging-log.md
status: draft
---

# H30 Account and Notification Provider Events Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use $subagent-driven-development (recommended) or $executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Native WooPayments should process `account.updated`, `account.deleted`, and `wcpay.notification` with reference-compatible account-cache, saved-payment-cache, onboarding-reset, NOX, and Woo Admin inbox note side effects, so these non-money provider events no longer block cutover. Subscription invoice events must remain fail-closed until the Bucket-C invoice/renewal slice handles them.

**Architecture:** Keep provider-specific account lifecycle and remote-note payload handling under `Internal\Payments\Providers\WooPayments`. Add account and notification event handlers beside dispute/refund handlers. Dispatch `wcpay.notification` before the generic `data.object` extractor because the reference payload is stored directly under `data`.

**Tech Stack:** WooCommerce Core PHP, Woo Admin notes API, native WooPayments account/token services, PHPUnit via `pnpm --filter=@woocommerce/plugin-woocommerce test:php:env`, WooCommerce PHP lint/PHPStan gates.

## Files

- Modify: `plugins/woocommerce/src/Internal/Payments/Providers/WooPayments/WooPaymentsEventIngestor.php` to dispatch account and notification events and shrink `KNOWN_UNHANDLED_EVENT_TYPES`.
- Create: `plugins/woocommerce/src/Internal/Payments/Providers/WooPayments/WooPaymentsAccountEventHandler.php`.
- Create: `plugins/woocommerce/src/Internal/Payments/Providers/WooPayments/WooPaymentsNotificationEventHandler.php`.
- Create: `plugins/woocommerce/src/Internal/Payments/Providers/WooPayments/WooPaymentsRemoteNoteService.php`.
- Modify: `plugins/woocommerce/src/Internal/Payments/Providers/WooPayments/WooPaymentsAccountService.php` with account-reset cleanup owned near the account cache/settings logic.
- Modify: `plugins/woocommerce/src/Internal/Payments/Providers/WooPayments/WooPaymentsTokenService.php` with preserved payment-method cache clearing.
- Modify: `plugins/woocommerce/tests/php/src/Internal/Payments/Providers/WooPayments/WooPaymentsEventIngestorTest.php`.
- Modify: `plugins/woocommerce/tests/php/src/Internal/Payments/Providers/WooPayments/WooPaymentsAccountServiceTest.php`.
- Modify: `plugins/woocommerce/tests/php/src/Internal/Payments/Providers/WooPayments/WooPaymentsTokenServiceTest.php`.
- Create: `plugins/woocommerce/tests/php/src/Internal/Payments/Providers/WooPayments/WooPaymentsRemoteNoteServiceTest.php`.
- Create: `plugins/woocommerce/changelog/fix-native-payments-h30-account-notification-webhooks` after verification.
- Update scratchpad: `analysis-h30-account-notification-provider-events.md`, `staging-log.md`, `implementation-log.md`, `spec-conformance-baseline.md`, and `review-agent-findings.md`.

## Task 1: RED Account, Token Cache, And Remote Note Coverage

- [ ] Add account-service cleanup tests that seed gateway settings, onboarding options/transients, NOX options, and the account cache, then assert account-reset cleanup resets only the intended preserved WooPayments state.
- [ ] Add token-service cache clearing tests that seed `_wcpay_payment_methods` user meta and `wcpay_pm_%` options, then assert they are removed without touching unrelated user meta/options.
- [ ] Add remote-note service tests for note creation, dedupe, action URL mapping, generated names, and invalid payload fail-closed behavior.
- [ ] Add ingestor tests for `account.updated`, `account.deleted`, and `wcpay.notification`; update the known-unhandled test so invoice events remain fail-closed and the three migrated events are absent.
- [ ] Run the focused PHPUnit targets and confirm new tests fail before production code where practical.

## Task 2: Account Lifecycle Handler

- [ ] Add `WooPaymentsAccountEventHandler` with `is_supported_event()` and `process()` for `account.updated` and `account.deleted`.
- [ ] On `account.updated`, call `WooPaymentsAccountService::refresh_account_data()` and `WooPaymentsTokenService::clear_all_cached_payment_methods()`.
- [ ] On `account.deleted`, call `WooPaymentsAccountService::cleanup_after_account_reset()`, then `refresh_account_data()`, then clear all cached payment methods.
- [ ] Keep failures fail-closed rather than acknowledging side effects that did not run.

## Task 3: Account Reset And Saved-Payment Cache Services

- [ ] Add account-reset cleanup to `WooPaymentsAccountService`: disable native WooPayments, reset `test_mode` to `no`, reset `upe_enabled_payment_method_ids` to `card`, clear onboarding connected/test-mode state, delete onboarding/NOX transients/options, and clear the in-request account cache.
- [ ] Preserve existing account refresh guardrails: no new eager refresh loops, no autoloaded `wcpay_account_data`, and no refresh during Action Scheduler because this cleanup method should not call live platform APIs.
- [ ] Add `WooPaymentsTokenService::clear_all_cached_payment_methods()` to delete `_wcpay_payment_methods` user meta and legacy `wcpay_pm_%` option rows through prepared database operations.

## Task 4: Remote Notification Handler

- [ ] Add `WooPaymentsRemoteNoteService::put_note()` with WooPayments-compatible note naming, source, type, content data, dedupe, and action URL validation/mapping.
- [ ] Add `WooPaymentsNotificationEventHandler` for `wcpay.notification` that reads the webhook `data` payload directly and delegates to the remote note service.
- [ ] In `WooPaymentsEventIngestor`, dispatch notification events after the delivery-before hook and before `get_event_object()`, then dispatch account events after `get_event_object()` and before generic lifecycle handling.
- [ ] Remove `account.deleted`, `account.updated`, and `wcpay.notification` from `KNOWN_UNHANDLED_EVENT_TYPES`; keep `invoice.paid`, `invoice.payment_failed`, and `invoice.upcoming` as known-unhandled.

## Task 5: Verification, Review, Docs, Commit

- [ ] Run focused PHPUnit for `WooPaymentsEventIngestorTest|WooPaymentsAccountServiceTest|WooPaymentsTokenServiceTest|WooPaymentsRemoteNoteServiceTest`.
- [ ] Run broader native payment event gate including `WooPaymentsEventIngestorTest|WooPaymentsWebhookRestControllerTest|WooPaymentsCutoverControllerTest|WooPaymentsAccountServiceTest|WooPaymentsTokenServiceTest`.
- [ ] Run PHP syntax, explicit PHPCS for touched source/tests, production PHPStan for touched source, `pnpm --filter=@woocommerce/plugin-woocommerce lint:php:changes`, changelog validation after adding the changelog, and `git diff --check`.
- [ ] Dispatch review agents after implementation: one provider-event parity review against the reference extension and one reliability/architecture review focused on fail-closed behavior, account refresh guardrails, and notification validation.
- [ ] Update scratchpad logs and baseline with the H30 result, then commit source/tests and changelog in logical commits.

## Guardrails

Do not touch WPCOM code, WPCOM sandbox state, or the WooPayments plugin repo. Do not clear invoice events from the known-unhandled list. Do not move WooPayments account-reset or remote-note payload shapes into generic payments lifecycle services. Do not make frontend, bundle, or precise local performance claims for this backend slice.
