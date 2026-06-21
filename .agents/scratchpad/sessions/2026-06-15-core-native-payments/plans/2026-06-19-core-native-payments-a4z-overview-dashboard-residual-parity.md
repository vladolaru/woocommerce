---
session: 2026-06-15-core-native-payments
type: plan
by: codex
created: 2026-06-19 20:37
tool: writing-plans
target: A4z native WooPayments Overview dashboard residual parity
reconciles:
  - analysis-a4z-admin-exit-residuals.md
  - supervisor-prompt-2026-06-18-2344-N12.md
  - staging-log.md
status: draft
last_updated: 2026-06-19 21:08
---

# A4z Overview Dashboard Residual Parity Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use $subagent-driven-development (recommended) or $executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Restore the remaining source-backed WooPayments Overview dashboard sections in native Core without reopening completed A4i-A4y slices or treating the current narrow admin-surface gate as a final N12 exit gate.

**Architecture:** Keep Overview as a native Settings > Payments provider sub-route and add provider-owned WooPayments seams for account-session and dispute-readiness data. Reuse existing native/Core data boundaries where available: Capital active-loan REST routes, WooCommerce notes store, preserved account snapshots, and settings-account-fee projection. Do not fake Stripe embedded notifications, do not introduce generic payment-lifecycle responsibilities, and do not touch WPCOM code or the reference plugin.

**Tech Stack:** WooCommerce Core PHP services/controllers, WordPress REST API, React/TypeScript in `@woocommerce/admin-library`, `@wordpress/data`, `@woocommerce/data` notes store, Stripe embedded Connect packages already declared in the admin package, Jest/React Testing Library, PHPUnit, Playwriter, ignored `tools/woopayments-merge` gates.

---

## Current Status

- 2026-06-19 20:42 EEST: Plan written and ready for execution; no WooCommerce product diff has started yet and `git status --short --untracked-files=all` is clean.
- 2026-06-19 20:42 EEST: Backend work is split across disjoint workers: Fermat the 5th owns the embedded account-session seam, Beauvoir the 5th owns dispute-readiness, and the main agent owns bootstrap wiring plus Overview projection Task 1.
- 2026-06-19 20:42 EEST: Next concrete action after compaction is Task 1 RED coverage in `WooPaymentsOverviewServiceTest.php`, then implement the safe Overview projection. Do not backtrack to A4y; A4y is already committed and closed unless new source evidence invalidates it.
- 2026-06-19 20:44 EEST: Worker-owned backend RED-test diff is present: modified `WooPaymentsApiClientTest.php` plus untracked account-session/dispute-readiness service and controller tests under `plugins/woocommerce/tests/php/src/Internal/Payments/Providers/WooPayments/`. Preserve these files and coordinate before editing the same write sets.
- 2026-06-19 20:46 EEST: Fermat the 5th completed Task 2 source/tests with focused account-session PHPUnit green, plus PHP syntax and targeted PHPCS green. Main-agent integration still needs source review and bootstrap/registration wiring; do not assume the route is live until it is registered from Core.
- 2026-06-19 20:47 EEST: Task 1 is GREEN. RED failed first on missing `account_details`; after implementation, `pnpm --filter=@woocommerce/plugin-woocommerce test:php:env -- --filter WooPaymentsOverviewServiceTest` passed with 12 tests and 70 assertions.
- 2026-06-19 20:47 EEST: Beauvoir the 5th completed Task 3 source/tests with focused dispute-readiness PHPUnit green, plus PHP syntax and targeted PHP lint green. Main-agent integration still needs source review and bootstrap/registration wiring; do not assume the routes are live until registered from Core.
- 2026-06-19 20:49 EEST: Backend Tasks 1-3 are integrated and focused-green. `class-woocommerce.php` now registers the account-session and dispute-readiness controllers. Syntax passed for touched backend files and `pnpm --filter=@woocommerce/plugin-woocommerce test:php:env -- --filter 'WooPaymentsOverviewServiceTest|WooPaymentsApiClientTest|WooPaymentsEmbeddedAccountSessionServiceTest|WooPaymentsAccountSessionRestControllerTest|WooPaymentsDisputeReadinessServiceTest|WooPaymentsDisputeReadinessRestControllerTest'` passed with 116 tests and 695 assertions.
- 2026-06-19 20:58 EEST: Frontend Task 4 is active but incomplete. The worker RED tests are present in `overview-data.test.ts` and `overview-page.test.tsx`; `overview/data.ts` and `overview/types.ts` now have the new helper/type shapes; component files exist for account details, active loan summary, embedded notification fallback, dispute readiness, and inbox notifications. `overview/page.tsx` and `admin/style.scss` are still unmodified, focused frontend Jest has not been rerun green, and `InboxNotifications` is currently only a null fail-closed stub. Resume by finishing page/style wiring and tests, not by redoing backend Tasks 1-3.
- 2026-06-19 21:08 EEST: Frontend Task 4 is now wired but the final focused frontend GREEN gate is still pending. `overview/page.tsx` renders embedded notification fallback, account details, dispute readiness, active loan summary, and inbox notifications in the Overview flow; `admin/style.scss` includes the new card/list styles; `InboxNotifications` now uses `@woocommerce/data` notes store with the reference `woocommerce-payments` query shape plus `is_read`, real `InboxNoteCard`/dismiss modal components, notices, undo, and Tracks events; and Hume the 5th's read-only notes-store findings are incorporated. The focused Overview Jest suite passed cleanly with 28 tests before the real inbox component swap; the next run failed on missing `IntersectionObserver`; a `react-intersection-observer` mock was added; the immediate rerun output was truncated. Resume by rerunning the focused Overview Jest command and inspecting the result, not by assuming green or redoing backend Tasks 1-3.

## Source Baseline

- Native Overview currently renders `OverviewNotices`, `ConnectionSuccessModal`, `OverviewTaskList`, `UpdateBusinessDetailsModal`, `WooPaymentsAccountSettings`, `SpotlightPromotion`, `AccountBalancesCard`, and `PayoutsOverviewCard` in `plugins/woocommerce/client/admin/client/woopayments/admin/overview/page.tsx`.
- Reference Overview additionally renders `EmbeddedConnectNotificationBanner`, WooPayments-source `InboxNotifications`, `DisputeReadinessCard`, embedded `ActiveLoanSummary`, and the rich `AccountDetails` card in `/Users/vladolaru/Work/a8c/woocommerce-payments/client/overview/index.js`.
- Native has Capital routes/data already: `plugins/woocommerce/src/Internal/Payments/Providers/WooPayments/WooPaymentsCapitalRestController.php` and `plugins/woocommerce/client/admin/client/woopayments/admin/capital/data.ts`.
- Native does not currently have `/wc/v3/payments/accounts/session` or `/wc/v3/payments/dispute-readiness` routes. The reference source for those contracts is `/Users/vladolaru/Work/a8c/woocommerce-payments/includes/admin/class-wc-rest-payments-accounts-controller.php`, `/Users/vladolaru/Work/a8c/woocommerce-payments/includes/admin/class-wc-rest-payments-dispute-readiness-controller.php`, and `/Users/vladolaru/Work/a8c/woocommerce-payments/src/Internal/Service/DisputeReadinessService.php`.

## File Structure

- Modify `plugins/woocommerce/src/Internal/Admin/Settings/PaymentsProviders/WooPayments/WooPaymentsOverviewService.php` to expose safe `account_details`, active `account_fees`, feature flags, account loan presence, and connection-success/test-mode fields needed by the Overview UI.
- Modify `plugins/woocommerce/src/Internal/Admin/Settings/PaymentsProviders/WooPayments/WooPaymentsRestController.php` only for wc-admin projection route shape if needed; do not put `/wc/v3` platform-style routes here unless the existing controller already owns the equivalent namespace.
- Modify `plugins/woocommerce/src/Internal/Payments/Providers/WooPayments/Api/WooPaymentsApiClient.php` to add `create_embedded_account_session()` preserving the reference `accounts/embedded/session` WCPay V1 contract.
- Create `plugins/woocommerce/src/Internal/Payments/Providers/WooPayments/WooPaymentsEmbeddedAccountSessionService.php` to map platform session payloads into the frontend `clientSecret`, `expiresAt`, `accountId`, `isLive`, `publishableKey`, and `locale` shape and fail closed on missing connection or malformed responses.
- Create `plugins/woocommerce/src/Internal/Payments/Providers/WooPayments/WooPaymentsAccountSessionRestController.php` to register `GET /wc/v3/payments/accounts/session` under native runtime ownership and `manage_woocommerce`.
- Create `plugins/woocommerce/src/Internal/Payments/Providers/WooPayments/WooPaymentsDisputeReadinessService.php` to port the reference dispute-readiness payload logic using native account snapshots and Core URLs.
- Create `plugins/woocommerce/src/Internal/Payments/Providers/WooPayments/WooPaymentsDisputeReadinessRestController.php` to register `GET /wc/v3/payments/dispute-readiness`, `POST /dismiss`, and `POST /statement-descriptor/confirm`.
- Modify or create PHP tests under `plugins/woocommerce/tests/php/src/Internal/Payments/Providers/WooPayments/` and `plugins/woocommerce/tests/php/src/Internal/Admin/Settings/PaymentsProviders/WooPayments/` for each backend contract.
- Create frontend Overview components under `plugins/woocommerce/client/admin/client/woopayments/admin/overview/components/`: `account-details-card.tsx`, `active-loan-summary-card.tsx`, `embedded-connect-notification-banner.tsx`, `inbox-notifications.tsx`, and `dispute-readiness-card.tsx`.
- Modify `plugins/woocommerce/client/admin/client/woopayments/admin/overview/data.ts`, `types.ts`, `page.tsx`, and `style.scss` to load/render those components and preserve reference test-mode/modal/copy gating.
- Modify frontend tests under `plugins/woocommerce/client/admin/client/woopayments/admin/test/`: `overview-data.test.ts`, `overview-page.test.tsx`, and new component-focused tests as needed.
- Modify `tools/woopayments-merge/a4-admin-surface-gate.py` only if the route/chunk/source gate needs an Overview-specific assertion; do not confuse that with the final N12 browser gate.

## Task 1: Backend Overview Projection Expansion

**Files:**

- Modify: `plugins/woocommerce/src/Internal/Admin/Settings/PaymentsProviders/WooPayments/WooPaymentsOverviewService.php`
- Test: `plugins/woocommerce/tests/php/src/Internal/Admin/Settings/PaymentsProviders/WooPayments/WooPaymentsOverviewServiceTest.php`

- [x] **Step 1: Write RED projection tests**

Add assertions that a preserved account snapshot containing `account_details`, `account_fees`, `account_loans`, and feature flags returns safe Overview projection keys without publishable keys or raw account data. Include a test-mode onboarding case asserting `account.test_mode_onboarding` remains true and the frontend has enough data to suppress the connection-success modal.

```php
$this->assertArrayHasKey( 'account_details', $overview );
$this->assertSame( 'Restricted soon', $overview['account_details']['account_status']['text'] );
$this->assertArrayHasKey( 'account_fees', $overview );
$this->assertSame( 'card', $overview['account_fees'][0]['payment_method'] );
$this->assertSame( true, $overview['feature_flags']['dispute_readiness_overview'] );
$this->assertSame( true, $overview['account_loans']['has_active_loan'] );
$this->assertArrayNotHasKey( 'test_publishable_key', $overview );
```

- [x] **Step 2: Run the focused RED test**

Run: `pnpm --filter=@woocommerce/plugin-woocommerce test:php:env -- --filter WooPaymentsOverviewServiceTest`

Expected: FAIL on missing `account_details`, `account_fees`, `feature_flags`, and `account_loans`.

- [x] **Step 3: Implement projection helpers**

Read account details from `account_data['account_details']` only if it has `account_status`, `payout_status`, and `banner` keys, matching the reference `WC_Payments_Account::get_account_details()` validity guard. Derive active account fees from existing sanitized fee shapes already used by native settings if available; otherwise return an empty array rather than raw fee payloads. Add `feature_flags.dispute_readiness_overview` from an option-backed helper for `_wcpay_feature_dispute_readiness_overview`, defaulting fail-closed to false. Add `account_loans.has_active_loan` from `account_data['account_loans']['has_active_loan']` or `account_data['account_loans']['hasActiveLoan']` only when truthy.

- [x] **Step 4: Run GREEN projection tests**

Run: `pnpm --filter=@woocommerce/plugin-woocommerce test:php:env -- --filter WooPaymentsOverviewServiceTest`

Expected: PASS.

## Task 2: Native Embedded Account Session Seam

**Files:**

- Modify: `plugins/woocommerce/src/Internal/Payments/Providers/WooPayments/Api/WooPaymentsApiClient.php`
- Create: `plugins/woocommerce/src/Internal/Payments/Providers/WooPayments/WooPaymentsEmbeddedAccountSessionService.php`
- Create: `plugins/woocommerce/src/Internal/Payments/Providers/WooPayments/WooPaymentsAccountSessionRestController.php`
- Test: `plugins/woocommerce/tests/php/src/Internal/Payments/Providers/WooPayments/Api/WooPaymentsApiClientTest.php`
- Test: `plugins/woocommerce/tests/php/src/Internal/Payments/Providers/WooPayments/WooPaymentsEmbeddedAccountSessionServiceTest.php`
- Test: `plugins/woocommerce/tests/php/src/Internal/Payments/Providers/WooPayments/WooPaymentsAccountSessionRestControllerTest.php`

- [x] **Step 1: Write RED API client and service tests**

Assert `WooPaymentsApiClient::create_embedded_account_session()` posts to `accounts/embedded/session` with user-token auth and returns an array. Assert the service maps `client_secret`, `expires_at`, `account_id`, `is_live`, and `publishable_key` into `clientSecret`, `expiresAt`, `accountId`, `isLive`, and `publishableKey`, adds `locale`, and returns an empty response on malformed API payloads.

```php
$this->assertSame( 'cs_test', $session['clientSecret'] );
$this->assertSame( 1781740800, $session['expiresAt'] );
$this->assertSame( 'acct_native', $session['accountId'] );
$this->assertFalse( $session['isLive'] );
$this->assertSame( 'pk_test_native', $session['publishableKey'] );
$this->assertSame( get_user_locale(), $session['locale'] );
```

- [x] **Step 2: Write RED REST controller tests**

Assert `GET /wc/v3/payments/accounts/session` returns 200 for `manage_woocommerce`, denies unauthorized users, registers only when `NativePaymentsRuntimeArbiter::should_native_register()` is true, and returns a safe empty object or 500 error on service exception without exposing secrets.

- [x] **Step 3: Run the focused RED tests**

Run: `pnpm --filter=@woocommerce/plugin-woocommerce test:php:env -- --filter 'WooPaymentsApiClientTest|WooPaymentsEmbeddedAccountSessionServiceTest|WooPaymentsAccountSessionRestControllerTest'`

Expected: FAIL because classes/methods/routes do not exist.

- [x] **Step 4: Implement the seam**

Add the API client method using the existing `request()` helper with path `self::ACCOUNTS_API . '/embedded/session'`, method `POST`, account context true, and user token true. Keep the response as an array. Add service and controller classes under the provider namespace. Register the controller from the same bootstrap path used by existing WooPayments provider REST controllers; do not register from WPCOM or plugin code.

- [x] **Step 5: Run GREEN backend account-session tests**

Run: `pnpm --filter=@woocommerce/plugin-woocommerce test:php:env -- --filter 'WooPaymentsApiClientTest|WooPaymentsEmbeddedAccountSessionServiceTest|WooPaymentsAccountSessionRestControllerTest'`

Expected: PASS.

## Task 3: Native Dispute Readiness Service and Routes

**Files:**

- Create: `plugins/woocommerce/src/Internal/Payments/Providers/WooPayments/WooPaymentsDisputeReadinessService.php`
- Create: `plugins/woocommerce/src/Internal/Payments/Providers/WooPayments/WooPaymentsDisputeReadinessRestController.php`
- Test: `plugins/woocommerce/tests/php/src/Internal/Payments/Providers/WooPayments/WooPaymentsDisputeReadinessServiceTest.php`
- Test: `plugins/woocommerce/tests/php/src/Internal/Payments/Providers/WooPayments/WooPaymentsDisputeReadinessRestControllerTest.php`

- [x] **Step 1: Write RED service tests from the reference contract**

Cover disabled payload, enabled payload, complete/incomplete signals for refund policy, terms and conditions, statement descriptor, and support contact, dismissal persistence, dismissal reappearance when incomplete signal set changes, and statement descriptor confirmation tied to the normalized descriptor.

```php
$this->assertSame( true, $payload['overview']['enabled'] );
$this->assertSame( 4, $payload['overview']['total'] );
$this->assertContains( 'statement_descriptor', $payload['overview']['incompleteSignalIds'] );
$this->assertSame( 'Recognizable statement descriptor', $payload['overview']['signals'][0]['label'] );
```

- [x] **Step 2: Write RED route tests**

Assert `GET /wc/v3/payments/dispute-readiness`, `POST /dismiss`, and `POST /statement-descriptor/confirm` preserve the reference response shape, deny unauthorized users, honor the `_wcpay_feature_dispute_readiness_overview` feature option, and fail closed when native runtime does not own WooPayments.

- [x] **Step 3: Run the focused RED tests**

Run: `pnpm --filter=@woocommerce/plugin-woocommerce test:php:env -- --filter 'WooPaymentsDisputeReadinessServiceTest|WooPaymentsDisputeReadinessRestControllerTest'`

Expected: FAIL because service/controller classes do not exist.

- [x] **Step 4: Implement the service and controller**

Port the reference logic from `DisputeReadinessService` but replace plugin-specific settings URLs with native Core Settings > Payments provider URLs where appropriate: statement descriptor and support contact should point to `/woopayments/settings`, while WooCommerce pages still point to Core Advanced settings/edit-page URLs. Preserve option names `wcpay_dispute_readiness_card_dismissed` and `wcpay_dispute_readiness_statement_descriptor_confirmed`; do not delete merchant data.

- [x] **Step 5: Run GREEN dispute-readiness backend tests**

Run: `pnpm --filter=@woocommerce/plugin-woocommerce test:php:env -- --filter 'WooPaymentsDisputeReadinessServiceTest|WooPaymentsDisputeReadinessRestControllerTest'`

Expected: PASS.

## Task 4: Frontend Overview Components and Data

**Files:**

- Modify: `plugins/woocommerce/client/admin/client/woopayments/admin/overview/data.ts`
- Modify: `plugins/woocommerce/client/admin/client/woopayments/admin/overview/types.ts`
- Modify: `plugins/woocommerce/client/admin/client/woopayments/admin/overview/page.tsx`
- Create: `plugins/woocommerce/client/admin/client/woopayments/admin/overview/components/account-details-card.tsx`
- Create: `plugins/woocommerce/client/admin/client/woopayments/admin/overview/components/active-loan-summary-card.tsx`
- Create: `plugins/woocommerce/client/admin/client/woopayments/admin/overview/components/embedded-connect-notification-banner.tsx`
- Create: `plugins/woocommerce/client/admin/client/woopayments/admin/overview/components/inbox-notifications.tsx`
- Create: `plugins/woocommerce/client/admin/client/woopayments/admin/overview/components/dispute-readiness-card.tsx`
- Modify: `plugins/woocommerce/client/admin/client/woopayments/admin/style.scss`
- Test: `plugins/woocommerce/client/admin/client/woopayments/admin/test/overview-data.test.ts`
- Test: `plugins/woocommerce/client/admin/client/woopayments/admin/test/overview-page.test.tsx`
- Create component tests as needed under `plugins/woocommerce/client/admin/client/woopayments/admin/test/`

- [x] **Step 1: Write RED data tests**

Add tests for `createWooPaymentsAccountSession()`, `getWooPaymentsDisputeReadiness()`, `dismissWooPaymentsDisputeReadinessCard()`, and `confirmWooPaymentsDisputeReadinessStatementDescriptor()` using exact preserved endpoint paths.

```typescript
expect( mockApiFetch ).toHaveBeenCalledWith( {
	path: '/wc/v3/payments/accounts/session',
	method: 'GET',
} );
expect( mockApiFetch ).toHaveBeenCalledWith( {
	path: '/wc/v3/payments/dispute-readiness',
	method: 'GET',
} );
```

- [x] **Step 2: Write RED page/component tests**

Assert Overview suppresses the connection-success modal during test-mode onboarding; renders account details status/payout status/banner/fee rows from the shell; fetches/render active loan summary only when `account_loans.has_active_loan` is true; queries notes with `source=woocommerce-payments`; renders dispute readiness when the feature flag is on and hides it when disabled/dismissed; and renders embedded notification fallback warning when Stripe reports `invalid_request_error`.

- [x] **Step 3: Run focused RED frontend tests**

Run: `pnpm --filter=@woocommerce/admin-library test:js --runTestsByPath client/woopayments/admin/test/overview-data.test.ts client/woopayments/admin/test/overview-page.test.tsx --runInBand`

Expected: FAIL on missing data helpers/components and missing test-mode onboarding modal suppression.

Observed: RED failed as expected with 2 failed suites and 11 failed tests out of 27 total. The failures covered missing data helpers, test-mode onboarding modal suppression, account details, active loan summary, dispute readiness, statement descriptor confirmation, dismissal/focus behavior, and embedded notification fallback.

- [x] **Step 4: Implement components**

Keep the page composition close to the reference order: notices, embedded notification banner/fallback, task list, balance/payout cards, rich account details, dispute readiness, active loan summary, inbox notifications, connection success modal, spotlight. Use Core/WooCommerce components where available, preserve merchant-facing informational copy, and keep Stripe embedded Connect rendering behind its own component that initializes only when the shell says the account is connected and not rejected/under review.

Implemented state: helper exports and types are added; component files are created; `overview/page.tsx` and `admin/style.scss` are wired; dispute-readiness dismissal now moves focus to the Balance heading instead of the document body; and Inbox uses the WooCommerce notes store/components rather than a fail-closed stub. The real Inbox component introduced an `IntersectionObserver` dependency in Jest; the test now mocks `react-intersection-observer`, but the post-mock rerun output was truncated and must be rerun before Step 5 can be marked complete.

- [ ] **Step 5: Run GREEN frontend tests**

Run: `pnpm --filter=@woocommerce/admin-library test:js --runTestsByPath client/woopayments/admin/test/overview-data.test.ts client/woopayments/admin/test/overview-page.test.tsx --runInBand`

Expected: PASS.

## Task 5: Integration Gates and Browser Evidence

**Files:**

- Modify if needed: `tools/woopayments-merge/a4-admin-surface-gate.py`
- Modify: `.agents/scratchpad/sessions/2026-06-15-core-native-payments/analysis-a4z-admin-exit-residuals.md`
- Modify: `.agents/scratchpad/sessions/2026-06-15-core-native-payments/staging-log.md`
- Modify: `.agents/scratchpad/sessions/2026-06-15-core-native-payments/implementation-log.md`
- Add: `plugins/woocommerce/changelog/*`

- [ ] **Step 1: Run static/backend/frontend gates**

Run focused PHPUnit for new and touched backend tests, PHP syntax for new PHP files, PHPCS for touched PHP, PHPStan for production PHP, focused frontend Jest, targeted ESLint, targeted Stylelint, `pnpm --filter=@woocommerce/admin-library ts:check`, and `pnpm --filter=@woocommerce/admin-library build:project:bundle`.

- [ ] **Step 2: Run A4 admin surface gate**

Run: `python3 tools/woopayments-merge/a4-admin-surface-gate.py --repo . --reference /Users/vladolaru/Work/a8c/woocommerce-payments --output .agents/scratchpad/sessions/2026-06-15-core-native-payments/data/a4z-admin-surface-gate.json`

Expected: PASS, with Overview chunk byte deltas recorded honestly. This still does not equal the final N12 gate.

- [ ] **Step 3: Run Playwriter target/reference browser proof**

Use Playwriter to load the native target Overview at `http://store8889.localhost:8889/wp-admin/admin.php?page=wc-settings&tab=checkout&path=%2Fwoopayments%2Foverview` and the reference Overview at `http://localhost:8082/wp-admin/admin.php?page=wc-admin&path=%2Fpayments%2Foverview`. Verify no failed network responses, no visible PHP notices, and presence or intentional account-state absence of Account details, Dispute readiness, active loan summary, Inbox, embedded notification banner/fallback, Balance, Payouts, and task list. Capture screenshots to the session `data/` folder.

- [ ] **Step 4: Scan logs**

Scan target store and local WPCOM logs for fresh PHP notices, warnings, deprecations, fatals, uncaught exceptions, stack traces, database errors, or fresh 4xx/5xx markers from the browser window. Treat new notices as implementation bugs unless source evidence proves they predate the slice.

- [ ] **Step 5: Run branch gates and commit**

Run `pnpm --filter=@woocommerce/plugin-woocommerce changelog validate`, `git diff --check -- . ':!.agents'`, and `pnpm --filter=@woocommerce/plugin-woocommerce lint:changes:branch`. Commit product/source/test work and changelog as separate logical commits if all gates pass. Do not push.

## Review Plan

- Use implementation workers only on disjoint write sets: backend account-session seam, backend dispute-readiness seam, and frontend Overview components.
- After integration, dispatch at least API-contract, accessibility, WP architecture, performance/bundle, and reference-integrity reviewers. Use their findings only after source verification and record material findings in `analysis-a4z-admin-exit-residuals.md`.
- Keep `FILTER_NATIVE_ADMIN_SURFACES_READY` fail-closed. This slice narrows the Overview residual gap but does not by itself flip native admin readiness.

## Plan Self-Review

- Spec coverage: This plan addresses the source-backed Overview residuals from `analysis-a4z-admin-exit-residuals.md`: embedded Connect notifications, inbox notifications, dispute readiness, active loan summary, rich AccountDetails, connection-success test-mode suppression, and the route/gate evidence for this surface. Reconnect URL generation is not implemented here unless an explicit native WPCOM reconnect seam is found during Task 1; if none exists, it remains a recorded residual rather than an invented URL.
- Placeholder scan: No task says TBD/TODO or asks for generic error handling without a concrete contract.
- Type consistency: Frontend session keys use the reference `clientSecret`/`publishableKey` shape, backend platform keys use `client_secret`/`publishable_key`, and dispute readiness uses the reference `overview` payload shape.
- Scope: This is one coherent dashboard surface slice. Settings/detail residuals and the final full N12 exit-gate widening remain follow-up A4 work after A4z unless implementation reveals a direct dependency.
