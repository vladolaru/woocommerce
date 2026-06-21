# A4at Provider Route Reachability Parity Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use $subagent-driven-development (recommended) or $executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Close the reopened A4/N12 route-reachability gaps so native WooPayments provider routes and legacy plugin-era deep links funnel cleanly through WooCommerce Settings > Payments.

**Architecture:** Keep WooPayments-specific routes as provider subroutes owned by Core Payments settings. Extend the existing `WooPaymentsAdminNavigationController` alias/availability layer instead of introducing a second router. Treat Stripe Billing UI as intentionally retired per N9/H31; do not port deprecated settings.

**Tech Stack:** WooCommerce Core PHP, PHPUnit, WordPress admin routing, WooCommerce Settings > Payments provider URLs, Playwriter browser verification.

---

## Files

- Modify: `plugins/woocommerce/src/Internal/Admin/Settings/PaymentsProviders/WooPayments/WooPaymentsAdminNavigationController.php`
- Modify: `plugins/woocommerce/tests/php/src/Internal/Admin/Settings/PaymentsProviders/WooPayments/WooPaymentsAdminNavigationControllerTest.php`
- Modify: `plugins/woocommerce/src/Internal/MultiCurrency/Services/MultiCurrencyAdminNoteProjectionService.php`
- Modify: `plugins/woocommerce/tests/php/src/Internal/MultiCurrency/Services/MultiCurrencyAdminNoteProjectionServiceTest.php`
- Add: `plugins/woocommerce/changelog/fix-native-woopayments-route-reachability`
- Update: `.agents/scratchpad/sessions/2026-06-15-core-native-payments/analysis-a4at-next-slice-selection.md`
- Update: `.agents/scratchpad/sessions/2026-06-15-core-native-payments/implementation-log.md`
- Update: `.agents/scratchpad/sessions/2026-06-15-core-native-payments/staging-log.md`

## Task 1: Add RED Coverage For Missing Legacy Aliases

- [ ] **Step 1: Add data-driven alias tests**

In `WooPaymentsAdminNavigationControllerTest.php`, add a provider and test near the existing legacy redirect tests:

```php
/**
 * @testdox Should map legacy WooPayments setup and settings URLs to native provider routes.
 *
 * @dataProvider provide_legacy_setup_and_settings_redirects
 */
public function test_maps_legacy_setup_and_settings_urls_to_native_routes( string $legacy_path, string $native_path, array $query = array() ): void {
	$sut = $this->create_controller( true );

	$url = $sut->get_legacy_payment_path_redirect_url(
		array_merge(
			array(
				'page' => 'wc-admin',
				'path' => rawurlencode( $legacy_path ),
			),
			$query
		)
	);

	$this->assertStringContainsString( 'admin.php?page=wc-settings&tab=checkout', $url );
	$this->assertStringContainsString( 'path=' . $native_path, $url );
	$this->assertStringNotContainsString( 'page=wc-admin', $url );
}

/**
 * @return array<string,array{legacy_path:string,native_path:string,query?:array<string,string>}>
 */
public function provide_legacy_setup_and_settings_redirects(): array {
	return array(
		'connect'                    => array( 'legacy_path' => '/payments/connect', 'native_path' => '/woopayments/onboarding' ),
		'onboarding'                 => array( 'legacy_path' => '/payments/onboarding', 'native_path' => '/woopayments/onboarding' ),
		'onboarding kyc'             => array( 'legacy_path' => '/payments/onboarding/kyc', 'native_path' => '/woopayments/onboarding' ),
		'fraud protection'           => array( 'legacy_path' => '/payments/fraud-protection', 'native_path' => '/woopayments/settings/fraud-protection' ),
		'multi currency setup'       => array( 'legacy_path' => '/payments/multi-currency-setup', 'native_path' => '/woopayments/settings', 'query' => array( 'section' => 'multi-currency' ) ),
		'additional payment methods' => array( 'legacy_path' => '/payments/additional-payment-methods', 'native_path' => '/woopayments/settings', 'query' => array( 'section' => 'payment-methods' ) ),
	);
}
```

- [ ] **Step 2: Run the focused RED test**

Run:

```bash
pnpm test:php:env -- --filter WooPaymentsAdminNavigationControllerTest::test_maps_legacy_setup_and_settings_urls_to_native_routes
```

Expected: FAIL because the new legacy paths are not yet in `LEGACY_ROUTE_REDIRECTS`.

## Task 2: Implement Alias Mapping And Query Preservation

- [ ] **Step 1: Add native route constants**

In `WooPaymentsAdminNavigationController.php`, add constants:

```php
private const PATH_FRAUD_PROTECTION_SETTINGS = '/woopayments/settings/fraud-protection';
private const SETTINGS_SECTION_MULTI_CURRENCY = 'multi-currency';
private const SETTINGS_SECTION_PAYMENT_METHODS = 'payment-methods';
```

- [ ] **Step 2: Extend `LEGACY_ROUTE_REDIRECTS`**

Add entries:

```php
'/payments/connect'                    => self::PATH_ONBOARDING,
'/payments/onboarding'                 => self::PATH_ONBOARDING,
'/payments/onboarding/kyc'             => self::PATH_ONBOARDING,
'/payments/fraud-protection'           => self::PATH_FRAUD_PROTECTION_SETTINGS,
'/payments/multi-currency-setup'       => self::PATH_SETTINGS,
'/payments/additional-payment-methods' => self::PATH_SETTINGS,
```

- [ ] **Step 3: Add canonical section defaults**

After the report-tab rewrite in `get_legacy_payment_path_redirect_url()`, add:

```php
if ( '/payments/multi-currency-setup' === $legacy_path ) {
	$query['section'] = self::SETTINGS_SECTION_MULTI_CURRENCY;
}

if ( '/payments/additional-payment-methods' === $legacy_path ) {
	$query['section'] = self::SETTINGS_SECTION_PAYMENT_METHODS;
}
```

- [ ] **Step 4: Allow fraud settings route availability**

Add `self::PATH_FRAUD_PROTECTION_SETTINGS => true` to the `allowedRoutes` array. Keep settings route availability consistent with the main settings page.

- [ ] **Step 5: Run the focused GREEN test**

Run:

```bash
pnpm test:php:env -- --filter WooPaymentsAdminNavigationControllerTest::test_maps_legacy_setup_and_settings_urls_to_native_routes
```

Expected: PASS.

## Task 3: Fix Multi-Currency Admin Note URL

- [ ] **Step 1: Add RED coverage**

In `MultiCurrencyAdminNoteProjectionServiceTest.php`, assert the note action query points to Settings > Payments with the WooPayments settings route and multi-currency section:

```php
public function test_projects_native_multi_currency_setup_url(): void {
	$manifest = MultiCurrencyAdminNoteProjectionService::get_note_manifest();
	$action   = $manifest['actions'][0];

	$this->assertStringContainsString( 'admin.php?page=wc-settings&tab=checkout', $action['query'] );
	$this->assertStringContainsString( 'path=/woopayments/settings', $action['query'] );
	$this->assertStringContainsString( 'section=multi-currency', $action['query'] );
}
```

- [ ] **Step 2: Run the focused RED test**

Run:

```bash
pnpm test:php:env -- --filter MultiCurrencyAdminNoteProjectionServiceTest::test_projects_native_multi_currency_setup_url
```

Expected: FAIL because the note still points at `admin.php?page=wc-admin&path=/payments/multi-currency-setup`.

- [ ] **Step 3: Implement the native URL**

In `MultiCurrencyAdminNoteProjectionService.php`, import `Automattic\WooCommerce\Internal\Admin\Settings\Utils` and replace `NOTE_SETUP_URL` with a route/section projection:

```php
private const NOTE_SETUP_PATH = '/woopayments/settings';
private const NOTE_SETUP_SECTION = 'multi-currency';
```

Then set the action query to:

```php
'query' => Utils::wc_payments_settings_url(
	self::NOTE_SETUP_PATH,
	array( 'section' => self::NOTE_SETUP_SECTION )
),
```

- [ ] **Step 4: Run the focused GREEN test**

Run:

```bash
pnpm test:php:env -- --filter MultiCurrencyAdminNoteProjectionServiceTest::test_projects_native_multi_currency_setup_url
```

Expected: PASS.

## Task 4: Decide Gateway-Disabled Reachability And Setup Signal

- [ ] **Step 1: Verify desired contract against source**

Read the current native gate and reference plugin menu gate:

```bash
sed -n '190,230p' plugins/woocommerce/src/Internal/Admin/Settings/PaymentsProviders/WooPayments/WooPaymentsAdminNavigationController.php
sed -n '360,405p' /Users/vladolaru/Work/a8c/woocommerce-payments/includes/admin/class-wc-payments-admin.php
```

Expected conclusion to record: Settings and onboarding routes should remain reachable when native owns the provider and the merchant has `manage_woocommerce`, even if the gateway payment method is disabled; protected money/account routes stay account-state gated.

- [ ] **Step 2: Add tests for disabled-gateway settings reachability**

Add tests that a disabled gateway still maps `/payments/settings`, `/payments/connect`, and `/payments/fraud-protection`, while protected routes such as `/payments/transactions` remain unavailable:

```php
public function test_maps_legacy_settings_paths_when_gateway_is_disabled(): void {
	$sut = $this->create_controller(
		true,
		array(
			'is_gateway_enabled' => false,
		)
	);

	$settings_url = $sut->get_legacy_payment_path_redirect_url(
		array(
			'page' => 'wc-admin',
			'path' => '%2Fpayments%2Fsettings',
		)
	);

	$connect_url = $sut->get_legacy_payment_path_redirect_url(
		array(
			'page' => 'wc-admin',
			'path' => '%2Fpayments%2Fconnect',
		)
	);

	$this->assertStringContainsString( 'path=/woopayments/settings', $settings_url );
	$this->assertStringContainsString( 'path=/woopayments/onboarding', $connect_url );
	$this->assertSame(
		'',
		$sut->get_legacy_payment_path_redirect_url(
			array(
				'page' => 'wc-admin',
				'path' => '%2Fpayments%2Ftransactions',
			)
		)
	);
}
```

- [ ] **Step 3: Implement the minimal gate change**

Remove the early `is_gateway_enabled()` return from `get_legacy_payment_path_redirect_url()` and move route-level availability to `get_admin_route_availability()`: keep `PATH_SETTINGS`, `PATH_FRAUD_PROTECTION_SETTINGS`, and onboarding aliases reachable; keep overview/payouts/transactions/disputes/reports/card readers/loans/documents gated through full/reduced/protected access.

- [ ] **Step 4: Disposition the setup-required badge**

Do not add a plugin-era top-level badge under the Core Payments parent unless source and browser checks show no Core provider/onboarding attention signal exists. Record the disposition in `analysis-a4at-next-slice-selection.md` and `staging-log.md`. If a real missing attention signal remains, add a tested provider-submenu badge in this slice before browser verification.

## Task 5: Verification, Browser Gate, Docs, And Commit

- [ ] **Step 1: Run focused PHPUnit**

Run:

```bash
pnpm test:php:env -- --filter 'WooPaymentsAdminNavigationControllerTest|MultiCurrencyAdminNoteProjectionServiceTest'
```

Expected: PASS.

- [ ] **Step 2: Run PHP syntax and focused static checks**

Run:

```bash
php -l plugins/woocommerce/src/Internal/Admin/Settings/PaymentsProviders/WooPayments/WooPaymentsAdminNavigationController.php
php -l plugins/woocommerce/src/Internal/MultiCurrency/Services/MultiCurrencyAdminNoteProjectionService.php
pnpm --filter=@woocommerce/plugin-woocommerce lint:php:changes
```

Expected: PASS. Do not lint `.agents/scratchpad`.

- [ ] **Step 3: Run Playwriter browser reachability**

Use the target store only. Visit representative legacy URLs and assert the browser lands on Settings > Payments native paths:

```text
http://store8889.localhost:8889/wp-admin/admin.php?page=wc-admin&path=/payments/connect
http://store8889.localhost:8889/wp-admin/admin.php?page=wc-admin&path=/payments/fraud-protection
http://store8889.localhost:8889/wp-admin/admin.php?page=wc-admin&path=/payments/multi-currency-setup
```

Expected: each redirects to `page=wc-settings&tab=checkout` with the corresponding `/woopayments/...` path and no new console/PHP notices.

- [ ] **Step 4: Add changelog and update scratchpad**

Create `plugins/woocommerce/changelog/fix-native-woopayments-route-reachability` with a patch/fix description. Update `analysis-a4at-next-slice-selection.md`, `implementation-log.md`, and `staging-log.md` with tests, browser evidence, and any setup-badge disposition.

- [ ] **Step 5: Commit**

Stage only product files and changelog, then commit:

```bash
git add plugins/woocommerce/src/Internal/Admin/Settings/PaymentsProviders/WooPayments/WooPaymentsAdminNavigationController.php plugins/woocommerce/tests/php/src/Internal/Admin/Settings/PaymentsProviders/WooPayments/WooPaymentsAdminNavigationControllerTest.php plugins/woocommerce/src/Internal/MultiCurrency/Services/MultiCurrencyAdminNoteProjectionService.php plugins/woocommerce/tests/php/src/Internal/MultiCurrency/Services/MultiCurrencyAdminNoteProjectionServiceTest.php plugins/woocommerce/changelog/fix-native-woopayments-route-reachability
git commit -m "fix(payments): route legacy WooPayments admin links to native settings"
```

If signing fails, leave the changes staged and continue recording the pending commit for the user.
