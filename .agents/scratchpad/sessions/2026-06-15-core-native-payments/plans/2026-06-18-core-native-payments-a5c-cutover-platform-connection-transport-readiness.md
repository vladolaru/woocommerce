---
session: 2026-06-15-core-native-payments
type: plan
by: codex
created: 2026-06-18 23:23
last_updated: 2026-06-19 01:28
status: draft
reconciles:
  - staging-log.md
  - spec-conformance-baseline.md
  - implementation-log.md
  - supervisor-prompt-2026-06-17-1311.md
---

# A5c Cutover Platform Connection and Transport Readiness Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use $subagent-driven-development (recommended) or $executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Native WooPayments cutover must fail closed when the local WPCOM/Jetpack connection, connected owner, or owner user-token path cannot support post-plugin account/onboarding operations, while native WCPay V1 requests preserve the battle-tested reference transport contract for headers, idempotency, site scoping, and user-token account updates.

**Architecture:** Keep the generic Payments settings layer out of WPCOM details. Add a small provider-level WooPayments platform connection readiness seam beside the existing WooPayments transport seam. The readiness seam reports granular cutover failure codes from the native WPCOM transport without changing the existing `is_connected()` behavior normal callers already rely on. Keep all WCPay API requests behind `WooPaymentsApiClient`, which remains V1 `/wcpay`, site-scoped by default, and user-token scoped only for account owner operations that the platform requires.

**Source Findings:** Read-only subagents and local source inspection verified that native API calls are centralized in `WooPaymentsApiClient::request()` and use `/sites/{blog_id}/wcpay/{api}` or `/wcpay/{api}` with no native `/transact` switch. The gaps are that native POST/DELETE-equivalent requests do not auto-generate `Idempotency-Key` when the caller omits one, native requests do not add `X-Request-Initiated`, native `update_account()` currently uses blog-token auth although the reference `Update_Account` request uses a user token and WPCOM account update checks user permissions, and cutover currently sees WPCOM readiness only indirectly through `WooPaymentsProvider::can_process_payments()`.

**Tech Stack:** WooCommerce Core PHP under `plugins/woocommerce/src/Internal/Payments/Providers/WooPayments`, PHPUnit under `plugins/woocommerce/tests/php/src/Internal/Payments/Providers/WooPayments`, local ignored harness scripts under `tools/woopayments-merge`, read-only local WPCOM clone at `~/Work/a8c/wpcom`.

---

## File Map

- Modify `plugins/woocommerce/src/Internal/Payments/Providers/WooPayments/Api/WooPaymentsApiClient.php`: preserve reference idempotency auto-generation and latency header behavior; make `update_account()` use the connection-owner user token.
- Modify `plugins/woocommerce/tests/php/src/Internal/Payments/Providers/WooPayments/Api/WooPaymentsApiClientTest.php`: add focused RED/GREEN coverage for generated idempotency keys, `X-Request-Initiated`, no `/transact` drift, and `update_account()` user-token auth.
- Modify `plugins/woocommerce/src/Internal/Payments/Providers/WooPayments/Api/WooPaymentsHttpClient.php`: expose granular local WPCOM connection readiness failures without changing normal `is_connected()` behavior.
- Modify `plugins/woocommerce/src/Internal/Payments/Providers/WooPayments/Api/WooPaymentsHttpClientInterface.php`: include the readiness method used by provider-level readiness orchestration.
- Create `plugins/woocommerce/src/Internal/Payments/Providers/WooPayments/WooPaymentsPlatformConnectionService.php`: provider-owned readiness service that translates native transport readiness into cutover preflight failure codes.
- Create `plugins/woocommerce/tests/php/src/Internal/Payments/Providers/WooPayments/WooPaymentsPlatformConnectionServiceTest.php`: focused service tests with a fake transport.
- Modify `plugins/woocommerce/src/Internal/Payments/Providers/WooPayments/WooPaymentsCutoverController.php`: inject the readiness service and append granular platform connection failures before deactivation.
- Modify `plugins/woocommerce/tests/php/src/Internal/Payments/Providers/WooPayments/WooPaymentsCutoverControllerTest.php`: prove cutover blocks on granular platform connection failures and still passes when the readiness seam is clean.
- Add local ignored probes in `tools/woopayments-merge`: `a5-user-token-readiness.php`, `a5-transport-continuity.php`, and a thin `a5-local-wpcom-readiness.sh` wrapper if practical.
- Add a WooCommerce Core changelog entry for the production change.
- Update `.agents/scratchpad/sessions/2026-06-15-core-native-payments/staging-log.md`, `implementation-log.md`, and `spec-conformance-baseline.md` after verification.

## Task 1: WCPay V1 Transport Contract RED/GREEN

**Files:**
- Modify: `plugins/woocommerce/src/Internal/Payments/Providers/WooPayments/Api/WooPaymentsApiClient.php`
- Modify: `plugins/woocommerce/tests/php/src/Internal/Payments/Providers/WooPayments/Api/WooPaymentsApiClientTest.php`

- [ ] **Step 1: Write RED tests for generated idempotency and latency headers.**

Add coverage around existing fake HTTP client calls proving that a non-GET request without caller-supplied `idempotency_key` still sends a non-empty `Idempotency-Key`, strips any request-body idempotency value, sends `Content-Type`, sends a `User-Agent` with the preserved `WooCommerce Payments/` product token, and sends `X-Request-Initiated`. Assert GET calls do not receive generated idempotency keys.

- [ ] **Step 2: Write RED tests for account update auth parity.**

Extend `test_update_account_posts_to_accounts_endpoint()` so it asserts `last_use_user_token` is true. This anchors the reference plugin’s `Update_Account::should_use_user_token()` behavior and WPCOM account update user-permission requirement.

- [ ] **Step 3: Write RED drift guard for V1 path shape.**

Add a narrow assertion around representative site-scoped and user-token requests that paths start with `/sites/123/wcpay/` or `/wcpay/` and never contain `/transact/`. Keep this as a focused regression guard rather than duplicating every path assertion already present in the file.

- [ ] **Step 4: Implement the transport parity fix.**

Generate `Idempotency-Key` for every non-GET request after `wcpay_api_request_params` and before `wcpay_api_request_headers`, preserving a caller-provided key when present. Add `X-Request-Initiated` immediately before dispatch so request latency timing reflects the real send moment. Keep the native user-agent product token as `WooCommerce Payments/{core-version}` for Core ownership, and document the deliberate version-source difference in the implementation log rather than trying to spoof the retired plugin version. Update `update_account()` to pass `use_user_token: true`.

- [ ] **Step 5: Run focused API client tests.**

Run:

```bash
pnpm --filter=@woocommerce/plugin-woocommerce test:php:env -- --filter WooPaymentsApiClientTest
```

Expected: PASS with new header/auth tests green and no path drift.

## Task 2: Provider-Level Platform Connection Readiness

**Files:**
- Modify: `plugins/woocommerce/src/Internal/Payments/Providers/WooPayments/Api/WooPaymentsHttpClient.php`
- Modify: `plugins/woocommerce/src/Internal/Payments/Providers/WooPayments/Api/WooPaymentsHttpClientInterface.php`
- Create: `plugins/woocommerce/src/Internal/Payments/Providers/WooPayments/WooPaymentsPlatformConnectionService.php`
- Create: `plugins/woocommerce/tests/php/src/Internal/Payments/Providers/WooPayments/WooPaymentsPlatformConnectionServiceTest.php`

- [ ] **Step 1: Write RED service tests.**

Use a fake transport that returns specific readiness failures. Assert the provider-level service returns no failures when the transport is ready and preserves granular failure codes for unavailable WPCOM connection, missing WPCOM blog ID, missing connection owner, and missing owner user token.

- [ ] **Step 2: Add granular readiness to the transport seam.**

Add `get_connection_readiness_failures( bool $require_user_token = false ): array` to `WooPaymentsHttpClientInterface` and `WooPaymentsHttpClient`. The method should inspect the local Jetpack manager and `Jetpack_Options` state, return stable string codes, catch throwable connection-manager failures, and avoid remote network probes. Proposed failure codes: `wpcom_connection_unavailable`, `wpcom_blog_id_unavailable`, `wpcom_connection_owner_unavailable`, and `wpcom_connection_owner_user_token_unavailable`.

- [ ] **Step 3: Implement the provider-level readiness service.**

Create `WooPaymentsPlatformConnectionService` with `init( WooPaymentsHttpClient $http_client )` and `get_cutover_preflight_failures(): array`. It should request connection readiness with `require_user_token = true`, normalize/stringify unique codes, and remain WooPayments-provider scoped.

- [ ] **Step 4: Run focused readiness tests.**

Run:

```bash
pnpm --filter=@woocommerce/plugin-woocommerce test:php:env -- --filter WooPaymentsPlatformConnectionServiceTest
```

Expected: PASS.

## Task 3: Cutover Preflight Integration

**Files:**
- Modify: `plugins/woocommerce/src/Internal/Payments/Providers/WooPayments/WooPaymentsCutoverController.php`
- Modify: `plugins/woocommerce/tests/php/src/Internal/Payments/Providers/WooPayments/WooPaymentsCutoverControllerTest.php`

- [ ] **Step 1: Add RED cutover tests.**

Extend the cutover controller fixture with an injectable platform connection service double. Assert `get_preflight_failures()` includes granular platform connection failures, soft notice stays hidden, and plugin disable stays blocked when those failures exist. Assert the existing ready path is unchanged when the service returns no failures.

- [ ] **Step 2: Implement controller integration.**

Add optional `WooPaymentsPlatformConnectionService` injection defaulted from the container. Merge its failures into the preflight list before provider event and operational queue checks. Preserve the existing `FILTER_NATIVE_TRANSPORT_READY` hook and generic `native_transport_unavailable` behavior so existing emergency overrides keep their meaning.

- [ ] **Step 3: Run focused cutover tests.**

Run:

```bash
pnpm --filter=@woocommerce/plugin-woocommerce test:php:env -- --filter WooPaymentsCutoverControllerTest
```

Expected: PASS, including A5a/A5b existing cutover coverage.

## Task 4: Local Harness Probes

**Files:**
- Add: `tools/woopayments-merge/a5-user-token-readiness.php`
- Add: `tools/woopayments-merge/a5-transport-continuity.php`
- Add if useful: `tools/woopayments-merge/a5-local-wpcom-readiness.sh`

- [ ] **Step 1: Add read-only user-token readiness probe.**

The PHP probe should be runnable through `wp eval-file`, emit JSON, and exit non-zero when local Jetpack/WPCOM readiness is insufficient. Capture only non-secret booleans and IDs: home URL, Jetpack class availability, blog ID presence, connection owner presence, owner user-token presence, native transport readiness failures, active plugin slugs, and current user ID. Do not print token values.

- [ ] **Step 2: Add transport continuity probe.**

The PHP probe should stub outbound HTTP with `pre_http_request` or the native fake transport approach and assert request shape locally: V1 `/wcpay` path, no `/transact`, `User-Agent`, `Content-Type`, generated `Idempotency-Key`, `X-Request-Initiated`, and user-token flag on account update if observable. It must not create remote records.

- [ ] **Step 3: Add optional shell wrapper for wpcom-local readiness.**

If it remains small, add a shell wrapper that prints progress as it runs `wpcom-local --json env status`, `doctor`, `identity status`, `transact status`, `tracks status`, and `store doctor` from the target store directory. Keep it observe-only and fail closed when a command is missing or unhealthy.

- [ ] **Step 4: Run probes on the target local store.**

Use the target WP runner only, no remote WPCOM sandbox. Record command output summary in the staging log. If the local store is not currently running, record that as an environment gate blocker rather than weakening the probe.

## Task 5: Gate Closeout and Reviews

**Files:**
- Add: `plugins/woocommerce/changelog/*`
- Update: `.agents/scratchpad/sessions/2026-06-15-core-native-payments/staging-log.md`
- Update: `.agents/scratchpad/sessions/2026-06-15-core-native-payments/implementation-log.md`
- Update: `.agents/scratchpad/sessions/2026-06-15-core-native-payments/spec-conformance-baseline.md`
- Update if review agents report findings: `.agents/scratchpad/sessions/2026-06-15-core-native-payments/review-agent-findings.md`

- [ ] **Step 1: Add changelog entry.**

Use a `fix` changelog entry such as `Preserve WooPayments cutover platform connection and WCPay transport readiness.`

- [ ] **Step 2: Run combined focused tests.**

Run:

```bash
pnpm --filter=@woocommerce/plugin-woocommerce test:php:env -- --filter 'WooPaymentsApiClientTest|WooPaymentsPlatformConnectionServiceTest|WooPaymentsCutoverControllerTest'
```

- [ ] **Step 3: Run source quality gates.**

Run PHP syntax checks for modified production/test PHP files, `pnpm --filter=@woocommerce/plugin-woocommerce lint:php:changes`, PHPStan over modified production classes, `pnpm --filter=@woocommerce/plugin-woocommerce changelog validate`, `pnpm --filter=@woocommerce/plugin-woocommerce lint:changes:branch`, and `git diff --check`.

- [ ] **Step 4: Dispatch adversarial reviews.**

Use `architecture-reviewer` for seam placement and over-abstraction risk, `reliability-reviewer` for fail-closed cutover and local-token assumptions, and `api-contract-reviewer` for WCPay request/header/hook contract parity. Validate every finding against source before acting.

- [ ] **Step 5: Update session docs and commit.**

Record A5c implementation and gate evidence in the implementation log, staging log, and spec-conformance baseline. Commit production changes and changelog as one logical WooCommerce Core commit. Keep ignored harness probes local unless the user explicitly wants them force-added.

## Task 6: Reopen A4 for N12 Admin Feature Parity

**Files:**
- Read: `.agents/scratchpad/sessions/2026-06-15-core-native-payments/supervisor-prompt-2026-06-18-2344-N12.md`
- Update: `.agents/scratchpad/sessions/2026-06-15-core-native-payments/staging-log.md`
- Update: `.agents/scratchpad/sessions/2026-06-15-core-native-payments/implementation-log.md`
- Update: `.agents/scratchpad/sessions/2026-06-15-core-native-payments/spec-conformance-baseline.md`
- Add as needed: follow-up A4 analysis and plan artifacts under `.agents/scratchpad/sessions/2026-06-15-core-native-payments/`

- [x] **Step 1: Re-read N12 after A5c closeout.**

Re-read `.agents/scratchpad/sessions/2026-06-15-core-native-payments/supervisor-prompt-2026-06-18-2344-N12.md` and treat it as reopening A4 before any A5 readiness claim. Confirm `FILTER_NATIVE_ADMIN_SURFACES_READY` remains fail-closed by default.

- [x] **Step 2: Reopen A4 and choose the next coherent parity slice.**

Record that A4 remains open for merchant reachability, functional/visual parity, copy/content parity, SCSS fidelity, and the widened A4 exit gate. Select the next broad enough A4 slice from the N12 checklist rather than treating A4h route/bundle evidence as sufficient.

- [x] **Step 3: Close Surface 1 reachability first.**

Implement and verify persistent native WooPayments admin navigation for the already-native routes, keeping every target under the canonical Settings > Payments provider sub-route namespace.

- [ ] **Step 4: Continue reopened A4 settings/dashboard feature parity.**

Continue through the N12 parity backlog after A5c/A4i/A4j. The active next settings slice is A4k settings row and payload parity: fee pills/tooltips, duplicate-payment-method notices, payment-method promotion disposition, and bounded adjacent shell polish. Remaining A4 settings blockers include express checkout customize subpages/live preview, payout bank-account display, fraud Basic/Advanced main and advanced subpage parity, sandbox switch-to-live notice, save busy-state behavior, copy/content parity, and SCSS fidelity. A5 cutover readiness must not be reconsidered until the widened A4 exit gate passes.
