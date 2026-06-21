---
session: 2026-06-15-core-native-payments
type: plan
by: codex
created: 2026-06-19 15:54
target: A4w native WooPayments Reports parity
reconciles:
  - ../analysis-a4w-reports-parity.md
  - ../supervisor-prompt-2026-06-18-2344-N12.md
status: draft
---

# A4w Native WooPayments Reports Parity Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use $subagent-driven-development (recommended) or $executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Restore the WooPayments Reports admin surface in native Core with Balance and Fees parity, Core-owned Settings > Payments routing, source-compatible Reports REST routes, DataViews-based tables where natural, and widened A4 gates that prove the surface is wired and measured.

**Architecture:** Reports stays a WooPayments provider surface under `/woopayments/reports`, with `/payments/reports` only as a legacy compatibility redirect. Backend feature gating lives in `WooPaymentsAccountService` and `WooPaymentsAdminNavigationController`; Reports REST is a bounded provider controller using the existing WooPayments API client and transactions transport. Frontend Reports is a separately bundled admin chunk that ports the reference shell, Balance tab, Fees tab, Tracks events, states, actions, and styles without forcing DataViews to mimic legacy table visuals.

**Tech Stack:** WooCommerce Core PHP `src/Internal`, WordPress REST API, WooPayments native API client, PHPUnit in `wp-env`, React/TypeScript admin client, `@wordpress/dataviews/wp`, `@wordpress/components`, `@wordpress/a11y`, Jest, Playwriter, `tools/woopayments-merge/a4-admin-surface-gate.py`.

---

## File Map

- Modify: `plugins/woocommerce/src/Internal/Payments/Providers/WooPayments/WooPaymentsAccountService.php` for `is_reports_enabled()` reading `_wcpay_feature_reports_area` fail-closed.
- Modify: `plugins/woocommerce/src/Internal/Admin/Settings/PaymentsProviders/WooPayments/WooPaymentsAdminNavigationController.php` for `/woopayments/reports`, gated full-menu item, and `/payments/reports` redirect.
- Modify: `plugins/woocommerce/src/Internal/Payments/Providers/WooPayments/Api/WooPaymentsApiClient.php` for `REPORTING_API` and `get_reporting_balance_summary()`.
- Create: `plugins/woocommerce/src/Internal/Payments/Providers/WooPayments/WooPaymentsReportingBalanceSummaryRequest.php` as the preserved request-object wrapper for `wcpay_get_reporting_balance_summary_request`.
- Create: `plugins/woocommerce/src/Internal/Payments/Providers/WooPayments/WooPaymentsReportsRestController.php` for Balance and Fees REST routes.
- Modify: `plugins/woocommerce/includes/class-woocommerce.php` to register the Reports REST controller.
- Modify tests: `WooPaymentsAccountServiceTest.php`, `WooPaymentsAdminNavigationControllerTest.php`, `WooPaymentsApiClientTest.php`; create `WooPaymentsReportsRestControllerTest.php`.
- Modify: `plugins/woocommerce/client/admin/client/woopayments/admin/routes.tsx` to lazy-load `settings-payments-woopayments-reports`.
- Create: `plugins/woocommerce/client/admin/client/woopayments/admin/reports/*` for the Reports shell, data helpers, query helpers, types, Balance, Fees, shared date filter, export button, and SCSS.
- Modify/add Jest tests under `plugins/woocommerce/client/admin/client/woopayments/admin/test/` for routes, Reports data/query helpers, Reports page states, Balance rows/actions, Fees query/view behavior, and Tracks.
- Modify: `plugins/woocommerce/src/Internal/Admin/WCAdminUser.php` or the narrow existing user-data-field filter location if needed to register `wc_payments_reports_fees_view` for Fees DataViews preference persistence.
- Modify: `tools/woopayments-merge/a4-admin-surface-gate.py` to make Reports expected, not forbidden, and record the Reports chunk measurement.
- Modify session docs: `staging-log.md`, `spec-conformance-baseline.md` or the current A4 gate artifact, and `analysis-a4w-reports-parity.md` with final decisions and evidence.

### Task 1: Backend Reports Gate, Navigation, API, And REST

**Files:**
- Modify: `plugins/woocommerce/tests/php/src/Internal/Payments/Providers/WooPayments/WooPaymentsAccountServiceTest.php`
- Modify: `plugins/woocommerce/tests/php/src/Internal/Admin/Settings/PaymentsProviders/WooPayments/WooPaymentsAdminNavigationControllerTest.php`
- Modify: `plugins/woocommerce/tests/php/src/Internal/Payments/Providers/WooPayments/Api/WooPaymentsApiClientTest.php`
- Create: `plugins/woocommerce/tests/php/src/Internal/Payments/Providers/WooPayments/WooPaymentsReportsRestControllerTest.php`
- Modify: `plugins/woocommerce/src/Internal/Payments/Providers/WooPayments/WooPaymentsAccountService.php`
- Modify: `plugins/woocommerce/src/Internal/Admin/Settings/PaymentsProviders/WooPayments/WooPaymentsAdminNavigationController.php`
- Modify: `plugins/woocommerce/src/Internal/Payments/Providers/WooPayments/Api/WooPaymentsApiClient.php`
- Create: `plugins/woocommerce/src/Internal/Payments/Providers/WooPayments/WooPaymentsReportingBalanceSummaryRequest.php`
- Create: `plugins/woocommerce/src/Internal/Payments/Providers/WooPayments/WooPaymentsReportsRestController.php`
- Modify: `plugins/woocommerce/includes/class-woocommerce.php`

- [ ] **Step 1: Write RED backend tests.**

Add account-service tests that prove `_wcpay_feature_reports_area` controls Reports independently from account payload flags:

```php
public function test_exposes_reports_feature_flag_from_preserved_option(): void {
	update_option( 'wcpay_account_data', array( 'data' => $this->get_valid_live_account_payload() ) );
	update_option( '_wcpay_feature_reports_area', '1' );
	$sut = $this->create_service();
	$this->assertTrue( method_exists( $sut, 'is_reports_enabled' ) );
	$this->assertTrue( $sut->is_reports_enabled() );
	update_option( '_wcpay_feature_reports_area', '0' );
	$sut = $this->create_service();
	$this->assertFalse( $sut->is_reports_enabled() );
}
```

Add navigation tests that replace the current Reports-absent assertion with: Reports hidden by default, Reports inserted after Transactions when `is_reports_enabled` is true and the account is full-menu eligible, Reports omitted from reduced rejected/under-review menu, `/payments/reports` redirects to `/woopayments/reports` with query args when enabled, and `/payments/reports` does not redirect when disabled.

Add API-client tests that assert `get_reporting_balance_summary( array( 'date_start' => '2026-06-01T00:00:00Z', 'date_end' => '2026-06-19T23:59:59Z', 'currency' => 'usd' ) )` sends `GET /sites/123/wcpay/reporting/balance_summary?...`, applies `wcpay_get_reporting_balance_summary_request`, and rejects invalid currency before transport.

Create Reports REST tests that assert route registration is gated by native runtime and `is_reports_enabled()`, all routes require `manage_woocommerce`, Balance forwards `date_start`, `date_end`, and lowercase `currency`, invalid currency returns `rest_invalid_param`, Fees list maps `page` to `page`, `per_page` to `pagesize`, `payment_method_type` to `source_is`, `type` to `type_is_in`, defaults to the reference fee-bearing types, strips `customer`, maps `po_` search to `deposit_id`, maps `txn_` search to `transaction_id_is`, and preserves exception status. Also assert Fees summary/export/export URL call the existing transactions summary/export helpers.

- [ ] **Step 2: Run RED backend tests.**

Run:

```bash
pnpm test:php:env -- --filter 'WooPaymentsAccountServiceTest|WooPaymentsAdminNavigationControllerTest|WooPaymentsApiClientTest|WooPaymentsReportsRestControllerTest'
```

Expected: failures for missing `is_reports_enabled`, missing route constants/redirect, missing `get_reporting_balance_summary`, missing request class, missing Reports REST controller, and missing registration.

- [ ] **Step 3: Implement backend GREEN.**

Implement `WooPaymentsAccountService::is_reports_enabled()` as account-required and option-gated:

```php
public function is_reports_enabled(): bool {
	$enabled = $this->legacy_proxy->call_function( 'get_option', self::REPORTS_AREA_FLAG_OPTION, '0' );
	return $this->has_account() && '1' === (string) $enabled;
}
```

Add `PATH_REPORTS = '/woopayments/reports'`, add `/payments/reports => self::PATH_REPORTS`, block that redirect when `! $this->account_service->is_reports_enabled()`, and insert the Reports menu item after Transactions only in `get_full_menu_items()` when Reports is enabled.

Add `WooPaymentsReportingBalanceSummaryRequest` extending `WooPaymentsPaginatedListRequest`, with default no pagination params, API `reporting/balance_summary`, method `GET`, legacy alias `WCPay\Core\Server\Request\Get_Reporting_Balance_Summary`, `set_date_start()`, `set_date_end()`, `set_currency()`, and ISO-only currency validation matching the reference.

Add `WooPaymentsApiClient::REPORTING_API = 'reporting'` and `get_reporting_balance_summary()` using `request_with_legacy_request_filter( WooPaymentsReportingBalanceSummaryRequest::from_params( $query ), 'wcpay_get_reporting_balance_summary_request' )`.

Add `WooPaymentsReportsRestController` with `register()` gated on `should_native_register()` and `is_reports_enabled()`, routes `/payments/reports/balance`, `/payments/reports/fees`, `/payments/reports/fees/summary`, `/payments/reports/fees/download`, `/payments/reports/fees/download/(?P<export_id>[^/\\\\%]+)`, `manage_woocommerce`, reference-compatible Fees filter mapping, sanitized export params, and `WooPaymentsApiException` to `WP_Error` with 400-599 status preservation.

Register the controller in `plugins/woocommerce/includes/class-woocommerce.php` next to the adjacent WooPayments REST controllers.

- [ ] **Step 4: Run backend GREEN tests.**

Run:

```bash
pnpm test:php:env -- --filter 'WooPaymentsAccountServiceTest|WooPaymentsAdminNavigationControllerTest|WooPaymentsApiClientTest|WooPaymentsReportsRestControllerTest'
```

Expected: PASS with no PHP warnings or notices.

### Task 2: Frontend Reports Route, Data Helpers, And DataViews UI

**Files:**
- Modify: `plugins/woocommerce/client/admin/client/woopayments/admin/routes.tsx`
- Modify: `plugins/woocommerce/client/admin/client/woopayments/admin/test/routes.test.tsx`
- Create: `plugins/woocommerce/client/admin/client/woopayments/admin/reports/index.tsx`
- Create: `plugins/woocommerce/client/admin/client/woopayments/admin/reports/page.tsx`
- Create: `plugins/woocommerce/client/admin/client/woopayments/admin/reports/data.ts`
- Create: `plugins/woocommerce/client/admin/client/woopayments/admin/reports/query.ts`
- Create: `plugins/woocommerce/client/admin/client/woopayments/admin/reports/types.ts`
- Create: `plugins/woocommerce/client/admin/client/woopayments/admin/reports/header.tsx`
- Create: `plugins/woocommerce/client/admin/client/woopayments/admin/reports/report-state.tsx`
- Create: `plugins/woocommerce/client/admin/client/woopayments/admin/reports/balance/*`
- Create: `plugins/woocommerce/client/admin/client/woopayments/admin/reports/fees/*`
- Create: `plugins/woocommerce/client/admin/client/woopayments/admin/reports/date-filter/*`
- Create: `plugins/woocommerce/client/admin/client/woopayments/admin/reports/fees-export-button.tsx`
- Create: `plugins/woocommerce/client/admin/client/woopayments/admin/reports/style.scss`
- Modify/add tests under `plugins/woocommerce/client/admin/client/woopayments/admin/test/`

- [ ] **Step 1: Write RED route and data tests.**

Update `routes.test.tsx` to expect the new route:

```ts
{
	id: 'woopayments-reports',
	path: '/woopayments/reports',
	order: 122,
}
```

Add route source assertions for `webpackChunkName: "settings-payments-woopayments-reports"`. Add `reports-data.test.ts` for `getWooPaymentsReportsBalanceSummary`, `getWooPaymentsReportsFees`, `getWooPaymentsReportsFeesSummary`, `requestWooPaymentsReportsFeesExport`, and `getWooPaymentsReportsFeesExportUrl`. Add `reports-query.test.ts` proving lowercased Balance currency, Fees DataViews view-to-query mapping, default pagination/sort, array normalization for `type`, search serialization, and export-only `user_email`/`locale` behavior.

- [ ] **Step 2: Run RED frontend data tests.**

Run:

```bash
pnpm --filter=@woocommerce/plugin-woocommerce test:js -- --runTestsByPath plugins/woocommerce/client/admin/client/woopayments/admin/test/routes.test.tsx plugins/woocommerce/client/admin/client/woopayments/admin/test/reports-data.test.ts plugins/woocommerce/client/admin/client/woopayments/admin/test/reports-query.test.ts
```

Expected: failures for missing Reports route and missing data/query modules.

- [ ] **Step 3: Implement route and data GREEN.**

Add `WooPaymentsReportsChunk` in `routes.tsx` with its own chunk name and register `/woopayments/reports`. Add `data.ts` using `apiFetch` paths under `/wc/v3/payments/reports`. Add `query.ts` with explicit serializers rather than ad hoc string concatenation. Keep Reports in its own chunk and do not import it from the always-loaded settings bundle.

- [ ] **Step 4: Write RED UI tests.**

Add tests for the Reports shell showing the hidden `Reports` heading, intro copy, Balance and Fees tabs, Balance default tab URL sync, `page_view` and tab-change Tracks calls, DataViews usage through accessible table/list controls, Balance loading/error/empty states, Fees loading/error/empty states, export button notices, and `speak()` announcements for load success/error.

- [ ] **Step 5: Implement Reports UI GREEN.**

Port the reference Reports structure into native Core with `woocommerce` text domain, Core imports, and existing native helper reuse. Use `@wordpress/dataviews/wp` for Balance and Fees where it naturally fits: Balance uses DataViews layout/filter/table composition, Fees uses DataViews search/filter/pagination/table. Keep custom date filter, print/export actions, CSV export flow, and scoped SCSS. Preserve the reference merchant copy and Tracks event names/props. Register `wc_payments_reports_fees_view` as a WooCommerce admin user data field if the DataViews preference hook is retained; if persistence is not retained, remove the preference write path and record the parity caveat as a blocker before closing A4w.

- [ ] **Step 6: Run frontend GREEN tests.**

Run:

```bash
pnpm --filter=@woocommerce/plugin-woocommerce test:js -- --runTestsByPath plugins/woocommerce/client/admin/client/woopayments/admin/test/routes.test.tsx plugins/woocommerce/client/admin/client/woopayments/admin/test/reports-data.test.ts plugins/woocommerce/client/admin/client/woopayments/admin/test/reports-query.test.ts plugins/woocommerce/client/admin/client/woopayments/admin/test/reports-page.test.tsx
```

Expected: PASS with no React act warnings, console errors, or accessibility test warnings.

### Task 3: Harness, Bundle, Static Contract, And Docs

**Files:**
- Modify: `tools/woopayments-merge/a4-admin-surface-gate.py`
- Modify: `.agents/scratchpad/sessions/2026-06-15-core-native-payments/analysis-a4w-reports-parity.md`
- Modify: `.agents/scratchpad/sessions/2026-06-15-core-native-payments/staging-log.md`
- Modify: `.agents/scratchpad/sessions/2026-06-15-core-native-payments/spec-conformance-baseline.md` or the current baseline artifact if that exact file has been superseded.

- [ ] **Step 1: Write RED harness expectations.**

Update `a4-admin-surface-gate.py` to add `"reports": "settings-payments-woopayments-reports"` to `EXPECTED_CHUNKS`, add `"reports": "/woopayments/reports"` to `EXPECTED_ADMIN_NAVIGATION_ROUTES`, add `"/payments/reports"` to `EXPECTED_LEGACY_REDIRECT_ROUTES`, remove `"/payments/reports"` from `FORBIDDEN_LEGACY_REDIRECT_ROUTES`, add `"is_reports_enabled"` to `ACCOUNT_SERVICE_ADMIN_STATE_METHODS`, and replace the old `"reports-disposition"` required token with a Reports menu/redirect token that proves Reports is present.

- [ ] **Step 2: Run harness RED before build if implementation is not yet complete, then GREEN after implementation.**

Run:

```bash
python3 tools/woopayments-merge/a4-admin-surface-gate.py --repo "$PWD" --out .agents/scratchpad/sessions/2026-06-15-core-native-payments/data/a4w-admin-surface-gate.json --plugin-repo /Users/vladolaru/Work/a8c/woocommerce-payments
```

Expected after implementation and build: PASS, JSON contains Reports route/chunk and raw/gzip measurements.

- [ ] **Step 3: Run build and static checks.**

Run:

```bash
pnpm --filter=@woocommerce/plugin-woocommerce build:client
pnpm --filter=@woocommerce/plugin-woocommerce lint:php:changes
pnpm --filter=@woocommerce/plugin-woocommerce lint:js -- plugins/woocommerce/client/admin/client/woopayments/admin/reports plugins/woocommerce/client/admin/client/woopayments/admin/routes.tsx
cd plugins/woocommerce && composer exec -- phpstan analyse src/Internal/Payments/Providers/WooPayments/WooPaymentsReportsRestController.php src/Internal/Payments/Providers/WooPayments/WooPaymentsReportingBalanceSummaryRequest.php src/Internal/Payments/Providers/WooPayments/Api/WooPaymentsApiClient.php src/Internal/Payments/Providers/WooPayments/WooPaymentsAccountService.php src/Internal/Admin/Settings/PaymentsProviders/WooPayments/WooPaymentsAdminNavigationController.php --memory-limit=2G
```

Expected: PASS. Do not lint `.agents/scratchpad`.

- [ ] **Step 4: Record evidence.**

Append A4w source, test, harness, bundle, Tracks, BC, browser, and caveat evidence to `staging-log.md`. Update `analysis-a4w-reports-parity.md` with final implementation decisions. Update the baseline artifact to mark Reports as dispositioned if the gates pass; if any Reports parity gap remains, record it as a blocking A4 follow-up before any A4 closeout claim.

### Task 4: Browser Parity, Review Gates, And Slice Closeout

**Files:**
- No additional production files by default.
- Review outputs go under `.agents/scratchpad/sessions/2026-06-15-core-native-payments/`.

- [ ] **Step 1: Run Playwriter parity checks.**

Use the reference store at `http://localhost:8082/wp-admin/admin.php?page=wc-admin&path=/payments/reports` and native store at `http://store8889.localhost:8889/wp-admin/admin.php?page=wc-settings&tab=checkout&path=/woopayments/reports`. Enable `_wcpay_feature_reports_area` in both local stores if needed through WP-CLI/local test fixtures only. Verify menu reachability, legacy redirect, Balance default tab, Fees tab, date filter, search/filter controls, export buttons, loading/empty/error behavior where inducible, console errors, failed network responses, and visible style parity at desktop and a narrow viewport.

- [ ] **Step 2: Run focused review subagents.**

Dispatch at minimum: API contract reviewer for Reports REST/hooks/routes, a11y reviewer for DataViews/date filter/tabs/export controls, performance reviewer for bundle/query behavior, and code reviewer for the full A4w diff. Fix source-backed findings and rerun focused tests for every fix.

- [ ] **Step 3: Run final branch checks.**

Run:

```bash
pnpm --filter=@woocommerce/plugin-woocommerce lint:changes:branch
python3 tools/woopayments-merge/verify.sh --repo "$PWD"
```

If `verify.sh` has long-running local-env gates, use the harness output as it proceeds and do not skip gates that depend on `tools/woopayments-merge`.

- [ ] **Step 4: Close A4w.**

Only close the slice when backend tests, frontend tests, build, lint, phpstan, harness, Playwriter, and review gates have all passed or have explicit source-backed caveats recorded as blockers. Record the git range for the completed A4w commits. Do not push.

### Task 5: Deferred Queue Item After A5c

**Files:**
- Later work only; no A4w edits.

- [ ] **Step 1: Preserve the requested next task.**

After A5c is fully done, reopen A4 for the N12 feature-parity follow-up in `.agents/scratchpad/sessions/2026-06-15-core-native-payments/supervisor-prompt-2026-06-18-2344-N12.md`. Do not pull that work into A4w unless Reports implementation directly exposes one of those gaps.

## Self-Review

- Spec coverage: Reports route/menu, Balance, Fees, REST, feature gating, Tracks, DataViews, bundle split, harness, Playwriter, BC, and staging evidence are each covered by a task.
- Placeholder scan: no unresolved placeholders remain; open caveats are expressed as fail-closed blockers.
- Type consistency: backend method names use `is_reports_enabled`, `get_reporting_balance_summary`, `WooPaymentsReportingBalanceSummaryRequest`, and `WooPaymentsReportsRestController`; frontend route id/path/chunk consistently use `woopayments-reports`, `/woopayments/reports`, and `settings-payments-woopayments-reports`.
