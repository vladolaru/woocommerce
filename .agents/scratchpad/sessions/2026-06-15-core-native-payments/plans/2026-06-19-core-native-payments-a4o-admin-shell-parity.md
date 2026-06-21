---
session: 2026-06-15-core-native-payments
type: plan
by: codex
created: 2026-06-19 06:22
last_updated: 2026-06-19 06:59
status: final
reconciles:
  - supervisor-prompt-2026-06-18-2344-N12.md
  - analysis-a4o-admin-shell-parity.md
  - staging-log.md
---

# A4o Admin Shell Parity Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use $subagent-driven-development (recommended) or $executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Restore the merchant-visible WooPayments admin shell reachability and badge behavior for native Payments navigation while explicitly dispositioning Reports/Documents outside this slice and keeping cutover readiness fail-closed.

**Architecture:** Keep WooPayments routes owned by WooCommerce Settings > Payments provider subroutes. Gate submenus with a provider-owned `WooPaymentsAccountService::is_gateway_enabled()` predicate so disabled WooPayments does not add persistent native navigation. Add a provider-owned badge-count service that owns cached count fetching, keep `WooPaymentsAdminNavigationController` focused on menu presentation/reachability, and extend `WooPaymentsApiClient` only with source-backed V1 read endpoints needed by the badge service. Reports/Documents stay absent until their UI/API surfaces are ported in their own A4 slices.

**Tech Stack:** WooCommerce Core PHP, WordPress admin menu globals, WooCommerce DI container, PHPUnit/WC_Unit_Test_Case, ignored Python harness gate, Playwriter browser verification, WooCommerce changelogger.

---

## File Map

- Create `plugins/woocommerce/src/Internal/Payments/Providers/WooPayments/WooPaymentsAdminMenuBadgeService.php`: cached source-backed counts for Disputes awaiting response and manual-capture authorizations.
- Create `plugins/woocommerce/tests/php/src/Internal/Payments/Providers/WooPayments/WooPaymentsAdminMenuBadgeServiceTest.php`: RED/GREEN coverage for cache keys, stale fallback, fetch failures, mode selection, and manual-capture gating.
- Modify `plugins/woocommerce/src/Internal/Payments/Providers/WooPayments/WooPaymentsAccountService.php`: add `is_gateway_enabled()` as the explicit native provider menu predicate.
- Modify `plugins/woocommerce/tests/php/src/Internal/Payments/Providers/WooPayments/WooPaymentsAccountServiceTest.php`: prove `enabled=yes` and default-disabled behavior.
- Modify `plugins/woocommerce/src/Internal/Payments/Providers/WooPayments/Api/WooPaymentsApiClient.php`: add `AUTHORIZATIONS_API`, `get_dispute_status_counts()`, and `get_authorizations_summary()`.
- Modify `plugins/woocommerce/tests/php/src/Internal/Payments/Providers/WooPayments/Api/WooPaymentsApiClientTest.php`: prove the two preserved endpoint paths.
- Modify `plugins/woocommerce/src/Internal/Admin/Settings/PaymentsProviders/WooPayments/WooPaymentsAdminNavigationController.php`: inject badge service, add badge markup, append Disputes/Transactions badges, rewrite Disputes URL with `filter=awaiting_response`, and keep Reports/Documents intentionally absent.
- Modify `plugins/woocommerce/tests/php/src/Internal/Admin/Settings/PaymentsProviders/WooPayments/WooPaymentsAdminNavigationControllerTest.php`: add badge behavior, zero-count behavior, manual-capture gating, and Reports/Documents absence tests.
- Modify `plugins/woocommerce/client/admin/client/settings-payments/test/register-provider-routes.test.tsx`: add the existing `/woopayments/settings/fraud-protection` route to the bootstrap expectation.
- Modify `tools/woopayments-merge/a4-admin-surface-gate.py`: widen the ignored A4 gate to assert native source coverage for badge service, badge tokens, count endpoints, and Reports/Documents disposition.
- Modify `tools/woopayments-merge/tests/a4-admin-surface-fixtures.sh` only if the gate needs fixture assertions beyond source checks.
- Add a WooCommerce Core changelog entry after production code is green.
- Update `implementation-log.md`, `staging-log.md`, and `spec-conformance-baseline.md` with A4o evidence and remaining N12 backlog.

## Task 1: RED Provider Predicate, API, And Badge Service Tests

**Files:**
- Modify `plugins/woocommerce/tests/php/src/Internal/Payments/Providers/WooPayments/WooPaymentsAccountServiceTest.php`
- Modify `plugins/woocommerce/tests/php/src/Internal/Payments/Providers/WooPayments/Api/WooPaymentsApiClientTest.php`
- Create `plugins/woocommerce/tests/php/src/Internal/Payments/Providers/WooPayments/WooPaymentsAdminMenuBadgeServiceTest.php`

- [x] **Step 1: Add provider-enabled RED coverage.**

Add focused `WooPaymentsAccountServiceTest` coverage proving `is_gateway_enabled()` returns true only when the stored native WooPayments gateway setting `enabled` is `yes`, and false when the setting is absent/defaulted.

- [x] **Step 2: Add API client RED coverage for preserved endpoints.**

Add a focused test to `WooPaymentsApiClientTest` that uses `FakeWooPaymentsHttpClient`, calls the future `get_dispute_status_counts()` and `get_authorizations_summary()` methods, and expects these paths:

```php
$sut->get_dispute_status_counts();
$this->assertSame( '/sites/123/wcpay/disputes/status_counts?test_mode=0', $http_client->last_path );
$this->assertSame( 'GET', $http_client->last_method );

$sut->get_authorizations_summary();
$this->assertSame( '/sites/123/wcpay/authorizations/summary?test_mode=0', $http_client->last_path );
$this->assertSame( 'GET', $http_client->last_method );
```

- [x] **Step 3: Add badge service RED coverage.**

Create `WooPaymentsAdminMenuBadgeServiceTest` with a recording API client subclass and a mocked `WooPaymentsAccountService`. Cover these behaviors first:

```php
public function test_get_disputes_awaiting_response_count_sums_only_actionable_statuses(): void;
public function test_get_disputes_awaiting_response_count_uses_stale_valid_cache_when_fetch_fails(): void;
public function test_get_disputes_awaiting_response_count_returns_zero_without_valid_cache_on_fetch_failure(): void;
public function test_get_uncaptured_transactions_count_returns_zero_when_manual_capture_is_disabled(): void;
public function test_get_uncaptured_transactions_count_reads_authorization_summary_when_manual_capture_is_enabled(): void;
public function test_get_uncaptured_transactions_count_uses_test_mode_cache_key(): void;
```

Use preserved cache wrapper shapes:

```php
update_option(
	'wcpay_dispute_status_counts_cache',
	array(
		'data'    => array( 'needs_response' => 2, 'warning_needs_response' => 1 ),
		'fetched' => time(),
		'errored' => false,
	),
	false
);
```

- [x] **Step 4: Run RED tests and verify the failure is real.**

Run:

```bash
pnpm --filter='@woocommerce/plugin-woocommerce' test:php:env -- --filter 'WooPaymentsAccountServiceTest|WooPaymentsApiClientTest|WooPaymentsAdminMenuBadgeServiceTest'
```

Expected: failures for missing `is_gateway_enabled()`, missing `WooPaymentsAdminMenuBadgeService`, missing API client methods, or missing count behavior. Do not write production code until this RED state is observed.

## Task 2: GREEN Provider Predicate, API, And Badge Count Service

**Files:**
- Modify `plugins/woocommerce/src/Internal/Payments/Providers/WooPayments/WooPaymentsAccountService.php`
- Modify `plugins/woocommerce/src/Internal/Payments/Providers/WooPayments/Api/WooPaymentsApiClient.php`
- Create `plugins/woocommerce/src/Internal/Payments/Providers/WooPayments/WooPaymentsAdminMenuBadgeService.php`

- [x] **Step 1: Add the provider-enabled predicate.**

In `WooPaymentsAccountService`, add:

```php
public function is_gateway_enabled(): bool {
	return 'yes' === (string) $this->get_gateway_setting( 'enabled', 'no' );
}
```

- [x] **Step 2: Add the preserved API endpoints.**

In `WooPaymentsApiClient`, add:

```php
private const AUTHORIZATIONS_API = 'authorizations';

public function get_dispute_status_counts(): array {
	return $this->request( array(), self::DISPUTES_API . '/status_counts', 'GET' );
}

public function get_authorizations_summary(): array {
	return $this->request( array(), self::AUTHORIZATIONS_API . '/summary', 'GET' );
}
```

- [x] **Step 3: Add the badge count service.**

Create `WooPaymentsAdminMenuBadgeService` with:

```php
final public function init( WooPaymentsAccountService $account_service, WooPaymentsApiClient $api_client ): void;
public function get_disputes_awaiting_response_count(): int;
public function get_uncaptured_transactions_count(): int;
public function is_manual_capture_enabled(): bool;
```

Use these preserved cache keys:

```php
private const DISPUTE_STATUS_COUNTS_KEY = 'wcpay_dispute_status_counts_cache';
private const DISPUTE_STATUS_COUNTS_KEY_TEST_MODE = 'wcpay_test_dispute_status_counts_cache';
private const AUTHORIZATION_SUMMARY_KEY = 'wcpay_authorization_summary_cache';
private const AUTHORIZATION_SUMMARY_KEY_TEST_MODE = 'wcpay_test_authorization_summary_cache';
```

Implement a narrow `get_or_add_cached_array( string $key, callable $generator ): array` helper that reads wrappers with `data`, `fetched`, and `errored`, refreshes when missing/expired/corrupt, writes refreshed wrappers with `autoload=false`, catches `Throwable`, and returns stale valid data when refresh fails. Use the existing `wcpay_database_cache_ttl` filter with the option key to preserve the reference cache tuning seam.

- [x] **Step 4: Run GREEN tests.**

Run:

```bash
pnpm --filter='@woocommerce/plugin-woocommerce' test:php:env -- --filter 'WooPaymentsAccountServiceTest|WooPaymentsApiClientTest|WooPaymentsAdminMenuBadgeServiceTest'
```

Expected: all focused API/service tests pass.

## Task 3: RED/GREEN Navigation Badge Integration

**Files:**
- Modify `plugins/woocommerce/src/Internal/Admin/Settings/PaymentsProviders/WooPayments/WooPaymentsAdminNavigationController.php`
- Modify `plugins/woocommerce/tests/php/src/Internal/Admin/Settings/PaymentsProviders/WooPayments/WooPaymentsAdminNavigationControllerTest.php`

- [x] **Step 1: Add navigation RED tests.**

Extend the controller test double setup to inject `WooPaymentsAdminMenuBadgeService`. Add tests proving:

```php
public function test_does_not_add_menu_items_when_native_gateway_is_disabled(): void;
public function test_adds_disputes_badge_and_awaiting_response_filter(): void;
public function test_omits_disputes_badge_for_zero_count(): void;
public function test_adds_transactions_badge_only_when_manual_capture_has_uncaptured_transactions(): void;
public function test_omits_transactions_badge_when_manual_capture_is_disabled(): void;
public function test_reports_and_documents_menu_items_remain_absent_until_surfaces_are_ported(): void;
```

Expected Disputes URL should be the native settings URL for `/woopayments/disputes` with `from=PAYMENTS_MENU_ITEM` and `filter=awaiting_response`. Expected badge markup should preserve the `wcpay-menu-badge awaiting-mod count-N` and nested `plugin-count` shape.

- [x] **Step 2: Run RED navigation tests.**

Run:

```bash
pnpm --filter='@woocommerce/plugin-woocommerce' test:php:env -- --filter 'WooPaymentsAdminNavigationControllerTest'
```

Expected: failures because native does not yet gate by provider enabled state, append badge markup, or add the filter query args.

- [x] **Step 3: Implement badge wiring in the controller.**

Update `init()` to accept `WooPaymentsAdminMenuBadgeService`. Make `add_menu_items()` return early when `! $this->account_service->is_gateway_enabled()`. Make `get_full_menu_items()` return item data that can carry optional query args and badge HTML, or post-process items before `append_menu_item()`. Keep `append_menu_item()` escaping/sanitization deliberate: titles are translated text plus controlled badge HTML, so escape the title and concatenate generated badge markup instead of letting arbitrary HTML through.

Add helpers:

```php
private function get_notification_badge( int $count ): string;
private function get_disputes_query_args(): array;
```

- [x] **Step 4: Run GREEN navigation tests.**

Run:

```bash
pnpm --filter='@woocommerce/plugin-woocommerce' test:php:env -- --filter 'WooPaymentsAdminNavigationControllerTest'
```

Expected: all focused navigation tests pass.

## Task 4: Gate And Route-Test Widening

**Files:**
- Modify `plugins/woocommerce/client/admin/client/settings-payments/test/register-provider-routes.test.tsx`
- Modify `tools/woopayments-merge/a4-admin-surface-gate.py`
- Modify `tools/woopayments-merge/tests/a4-admin-surface-fixtures.sh` only if needed

- [x] **Step 1: Fix the stale provider-route expectation.**

Add `/woopayments/settings/fraud-protection` to the provider-route registration expectation so existing A4m route coverage remains wired through the Settings Payments router.

- [x] **Step 2: Widen the A4 admin gate.**

Extend `a4-admin-surface-gate.py` to assert the native source contains:

```python
"provider-enabled-menu-gate": "is_gateway_enabled",
"badge-service": "WooPaymentsAdminMenuBadgeService",
"dispute-status-counts": "get_dispute_status_counts",
"authorizations-summary": "get_authorizations_summary",
"disputes-filter": "filter' => 'awaiting_response",
"reports-documents-disposition": "Reports/Documents"
```

Also assert `LEGACY_ROUTE_REDIRECTS` still omits `/payments/reports` and `/payments/documents` unless real native routes exist.

- [x] **Step 3: Run focused JS and gate checks.**

Run:

```bash
pnpm --filter='@woocommerce/admin-library' test:js -- client/settings-payments/test/register-provider-routes.test.tsx
tools/woopayments-merge/a4-admin-surface-gate.py --repo . --plugin-repo /Users/vladolaru/Work/a8c/woocommerce-payments --out .agents/scratchpad/sessions/2026-06-15-core-native-payments/data/a4o-admin-surface-gate.json
```

Expected: both pass. The JSON evidence stays in the scratchpad session and is not committed.

## Task 5: Verification, Browser Proof, Reviews, Docs, Commit

**Files:**
- Add `plugins/woocommerce/changelog/fix-native-payments-a4o-admin-shell-parity`
- Update `implementation-log.md`, `staging-log.md`, and `spec-conformance-baseline.md`

- [x] **Step 1: Run focused static gates.**

Run PHP syntax, PHPStan for touched production PHP, focused PHPCS/changed-line lint, targeted admin-library JS lint for the touched JS test if needed, `git diff --check`, and changelog validation.

- [x] **Step 2: Run browser proof with seeded local options.**

Use WP-CLI on the target store to seed the relevant cache options and `manual_capture=yes`, load `http://store8889.localhost:8889/wp-admin/admin.php?page=wc-settings&tab=checkout&path=%2Fwoopayments%2Foverview`, hover/click the Payments menu via Playwriter, confirm the Disputes badge and Transactions badge are visible and the Disputes link carries `filter=awaiting_response`, then restore the original options/settings. Do not leave seeded options behind.

- [x] **Step 3: Run harness and log scans.**

Run `tools/woopayments-merge/verify.sh` with the reference and target WP-CLI commands used by A4n if both stores are stable. Scan target, reference, and local WPCOM logs for PHP notices/warnings/fatals since the start timestamp for this slice. Treat notices as bugs unless source-verified unrelated.

- [x] **Step 4: Dispatch review agents.**

Use `wp-architecture-reviewer` for admin menu/global mutations and cache/service boundaries, `api-contract-reviewer` or `ecosystem-integration-reviewer` for endpoint/cache parity against the reference, and `performance-reviewer` for admin-load query/API/cache behavior. Validate every finding against source before acting.

- [x] **Step 5: Commit locally only after gates pass.**

Create the changelog entry, update session docs with evidence and remaining N12 backlog, then commit the source/tests/changelog as one logical change or split changelog according to branch convention. Do not push. Do not modify WPCOM.

## Task 6: Keep A4 Reopened After A5c For N12 Feature Parity

- [x] **Step 1: Reopen A4 after A5c instead of treating A5 readiness as complete.**

A5c is complete, and A4 has been reopened under N12. Continue carrying the remaining feature-parity backlog as A4 work before reconsidering A5 cutover readiness: Overview/dashboard parity, Payouts/Transactions/Disputes list/detail parity, Card Readers/Capital framing/styling, Reports/Documents native UI/API slices, source-backed PM promotions, broader copy/content parity, and the widened final A4 exit gate. `FILTER_NATIVE_ADMIN_SURFACES_READY` must remain fail-closed until those pass.

## Closeout Criteria

A4o is complete only when native admin navigation preserves Disputes awaiting-response and manual-capture Transactions badge behavior with source-backed counts and cache fallback; Reports/Documents are explicitly dispositioned as separate product-surface work; the stale provider-route test is corrected; the A4 admin gate asserts the new shell coverage; browser proof confirms merchant-visible badge behavior; focused PHP/JS/static/harness gates pass; review findings are closed; and native admin readiness remains fail-closed.
