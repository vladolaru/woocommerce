# Core Native Payments B2ag Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use $subagent-driven-development (recommended) or $executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add a Core-native multi-currency settings wp-admin script so the connected `Multi-currency` WooCommerce settings tab is no longer blank.

**Architecture:** Keep PHP ownership and REST contracts in the existing native multi-currency controllers. Replace the legacy WooPayments `dist/multi-currency` asset projection with a WooCommerce `wp-admin-scripts/multi-currency-settings` entry, and build a small React UI that reads and updates enabled currencies through the already-migrated `/wc/v3/payments/multi-currency/*` routes.

**Tech Stack:** WooCommerce Core PHP, WooCommerce Admin wp-admin-scripts webpack entrypoints, React/TypeScript, WordPress components, `@wordpress/api-fetch`, `@wordpress/data` notices, Jest/React Testing Library, PHPUnit.

---

## File Structure

- Modify: `plugins/woocommerce/src/Internal/MultiCurrency/Services/MultiCurrencySettingsProjectionService.php`
    - Owns asset identity projection. Change from legacy `dist/multi-currency` to native `multi-currency-settings` wp-admin script metadata.
- Modify: `plugins/woocommerce/src/Internal/MultiCurrency/MultiCurrencySettingsController.php`
    - Uses `WCAdminAssets::register_script()` for the native wp-admin script and checks the generated `.asset.php` file for availability.
- Modify: `plugins/woocommerce/tests/php/src/Internal/MultiCurrency/Services/MultiCurrencySettingsProjectionServiceTest.php`
    - Updates asset projection expectations.
- Modify: `plugins/woocommerce/tests/php/src/Internal/MultiCurrency/MultiCurrencySettingsControllerTest.php`
    - Updates enqueue expectations for the native entry and no-style behavior.
- Create: `plugins/woocommerce/client/admin/client/wp-admin-scripts/multi-currency-settings/index.tsx`
    - Mounts the settings app into `#wcpay_multi_currency_settings_container`.
- Create: `plugins/woocommerce/client/admin/client/wp-admin-scripts/multi-currency-settings/app.tsx`
    - Renders the enabled-currencies settings UI and owns API interactions.
- Create: `plugins/woocommerce/client/admin/client/wp-admin-scripts/multi-currency-settings/types.ts`
    - Declares the REST response and currency types.
- Create: `plugins/woocommerce/client/admin/client/wp-admin-scripts/multi-currency-settings/test/app.test.tsx`
    - Tests loading, remove, modal update, and error notice behavior.
- Create: `plugins/woocommerce/client/admin/client/wp-admin-scripts/multi-currency-settings/test/index.test.tsx`
    - Tests entrypoint mounting into the PHP container.
- Create: `plugins/woocommerce/changelog/add-native-payments-b2ag-multi-currency-settings`
    - Patch changelog entry for the Core-native settings bundle.

## Task 1: PHP Asset Projection And Loader

**Files:**

- Modify: `plugins/woocommerce/src/Internal/MultiCurrency/Services/MultiCurrencySettingsProjectionService.php`
- Modify: `plugins/woocommerce/src/Internal/MultiCurrency/MultiCurrencySettingsController.php`
- Test: `plugins/woocommerce/tests/php/src/Internal/MultiCurrency/Services/MultiCurrencySettingsProjectionServiceTest.php`
- Test: `plugins/woocommerce/tests/php/src/Internal/MultiCurrency/MultiCurrencySettingsControllerTest.php`

- [ ] **Step 1: Write failing PHPUnit expectations**

Update `MultiCurrencySettingsProjectionServiceTest::test_projects_admin_assets_and_js_config_flag()` to expect:

```php
array(
    'script' => array(
        'entry'  => 'multi-currency-settings',
        'handle' => 'wc-admin-multi-currency-settings',
    ),
)
```

Update `MultiCurrencySettingsControllerTest::test_enqueues_admin_assets_when_bundle_is_available()` to expect one registered asset with `script.entry === 'multi-currency-settings'`, and one enqueued handle `wc-admin-multi-currency-settings`.

- [ ] **Step 2: Run PHPUnit red**

Run:

```bash
pnpm --filter='@woocommerce/plugin-woocommerce' test:php:env -- --filter 'MultiCurrencySettingsControllerTest|MultiCurrencySettingsProjectionServiceTest'
```

Expected: FAIL because the projection still points at `dist/multi-currency` and the controller still enqueues legacy script/style handles.

- [ ] **Step 3: Implement native wp-admin script projection**

Change `MultiCurrencySettingsProjectionService` constants and manifest so `get_admin_asset_manifest()` returns only:

```php
array(
    'script' => array(
        'entry'  => 'multi-currency-settings',
        'handle' => 'wc-admin-multi-currency-settings',
    ),
)
```

Remove legacy `ADMIN_STYLE_PATH`, `ADMIN_SCRIPT_DEPENDENCY`, and unused style URL/version parameters from the manifest.

- [ ] **Step 4: Implement native asset registration**

In `MultiCurrencySettingsController`:

- Import `Automattic\WooCommerce\Internal\Admin\WCAdminAssets`.
- Make `is_settings_asset_available()` check `WC_ADMIN_ABSPATH . WC_ADMIN_DIST_JS_FOLDER . 'wp-admin-scripts/multi-currency-settings.asset.php'` when no resolver is injected.
- Make `register_admin_assets()` call the injected registrar for tests, otherwise call:

```php
WCAdminAssets::register_script( 'wp-admin-scripts', 'multi-currency-settings', true );
```

- Make `handle_admin_enqueue_scripts()` enqueue only the projected script handle through the existing injected enqueuer test seam. The production path does not need `wp_enqueue_script()` after `WCAdminAssets::register_script()` because that helper registers and enqueues.

- [ ] **Step 5: Run PHPUnit green**

Run:

```bash
pnpm --filter='@woocommerce/plugin-woocommerce' test:php:env -- --filter 'MultiCurrencySettingsControllerTest|MultiCurrencySettingsProjectionServiceTest'
```

Expected: PASS.

## Task 2: React Settings App

**Files:**

- Create: `plugins/woocommerce/client/admin/client/wp-admin-scripts/multi-currency-settings/types.ts`
- Create: `plugins/woocommerce/client/admin/client/wp-admin-scripts/multi-currency-settings/app.tsx`
- Create: `plugins/woocommerce/client/admin/client/wp-admin-scripts/multi-currency-settings/index.tsx`
- Test: `plugins/woocommerce/client/admin/client/wp-admin-scripts/multi-currency-settings/test/app.test.tsx`
- Test: `plugins/woocommerce/client/admin/client/wp-admin-scripts/multi-currency-settings/test/index.test.tsx`

- [ ] **Step 1: Write failing Jest tests**

Add tests that mock `@wordpress/api-fetch` and `@wordpress/data` notices:

```ts
jest.mock( '@wordpress/api-fetch', () => jest.fn() );
jest.mock( '@wordpress/data', () => ( {
    useDispatch: jest.fn( () => ( {
        createSuccessNotice: jest.fn(),
        createErrorNotice: jest.fn(),
    } ) ),
} ) );
```

Cover these behaviors:

- Loading calls `/wc/v3/payments/multi-currency/currencies` and renders enabled currencies by accessible text.
- Clicking a non-default currency remove button posts the default plus remaining enabled currency codes to `/wc/v3/payments/multi-currency/update-enabled-currencies`.
- Clicking `Add/remove currencies` opens a modal titled `Add enabled currencies`; checking a currency and clicking `Update selected` posts the selected codes and closes the modal.
- A failed update calls `createErrorNotice( 'Error updating enabled currencies.' )`.
- The entrypoint mounts only when `#wcpay_multi_currency_settings_container` exists.

- [ ] **Step 2: Run Jest red**

Run from `plugins/woocommerce/client/admin`:

```bash
pnpm test:js -- multi-currency-settings
```

Expected: FAIL because the new entrypoint and app files do not exist yet.

- [ ] **Step 3: Implement types**

Create `types.ts`:

```ts
export interface MultiCurrencyCurrency {
    id: string;
    code: string;
    name: string;
    rate: number;
    symbol: string;
    symbol_position: string;
    is_zero_decimal: boolean;
    is_default: boolean;
    charm: number;
    rounding: string;
    last_updated: number | null;
}

export interface StoreCurrenciesResponse {
    available: Record< string, MultiCurrencyCurrency >;
    enabled: Record< string, MultiCurrencyCurrency >;
    default: MultiCurrencyCurrency;
}
```

- [ ] **Step 4: Implement the app**

Create `app.tsx` with:

- `apiFetch<StoreCurrenciesResponse>( { path: '/wc/v3/payments/multi-currency/currencies' } )` on mount.
- `apiFetch<StoreCurrenciesResponse>( { path: '/wc/v3/payments/multi-currency/update-enabled-currencies', method: 'POST', data: { enabled: nextCodes } } )` on save.
- `createSuccessNotice( 'Enabled currencies updated.' )` on success.
- `createErrorNotice( 'Error updating enabled currencies.' )` on failure.
- A standard table with columns `Name`, `Code`, `Exchange rate`, and `Actions`.
- Remove buttons with labels like `Remove Euro as an enabled currency`; default currency is not removable and displays `Default currency`.
- A WordPress `Modal` opened by `Add/remove currencies`, containing a `SearchControl`, `CheckboxControl` rows for non-default available currencies, `Cancel`, and `Update selected`.

- [ ] **Step 5: Implement the entrypoint**

Create `index.tsx`:

```tsx
import { createRoot } from '@wordpress/element';
import { MultiCurrencySettingsApp } from './app';

const container = document.getElementById(
    'wcpay_multi_currency_settings_container'
);

if ( container ) {
    createRoot( container ).render( <MultiCurrencySettingsApp /> );
}
```

- [ ] **Step 6: Run Jest green**

Run:

```bash
cd plugins/woocommerce/client/admin
pnpm test:js -- multi-currency-settings
```

Expected: PASS.

## Task 3: Build, Changelog, And Gates

**Files:**

- Create: `plugins/woocommerce/changelog/add-native-payments-b2ag-multi-currency-settings`
- Verify ignored generated asset: `plugins/woocommerce/assets/client/admin/wp-admin-scripts/multi-currency-settings.asset.php`
- Verify ignored generated asset: `plugins/woocommerce/assets/client/admin/wp-admin-scripts/multi-currency-settings.js`

- [ ] **Step 1: Add changelog**

Create:

```text
Significance: patch
Type: fix

Add the native multi-currency settings bundle for the WooCommerce settings tab.
```

- [ ] **Step 2: Run local build for the new entrypoint**

Run from `plugins/woocommerce/client/admin`:

```bash
pnpm build:project:bundle
```

Expected: PASS and produces ignored files under `plugins/woocommerce/assets/client/admin/wp-admin-scripts/multi-currency-settings.*`.

- [ ] **Step 3: Run JS lint and PHP gates**

Run:

```bash
cd plugins/woocommerce/client/admin
pnpm lint:lang:js -- --ext=js,ts,tsx client/wp-admin-scripts/multi-currency-settings
pnpm test:js -- multi-currency-settings
```

Run from repository root:

```bash
pnpm --filter=@woocommerce/plugin-woocommerce lint:php:changes
```

Run from `plugins/woocommerce`:

```bash
composer exec -- phpstan analyse src/Internal/MultiCurrency/MultiCurrencySettingsController.php src/Internal/MultiCurrency/Services/MultiCurrencySettingsProjectionService.php --memory-limit=2G
```

Run from repository root:

```bash
git diff --check
```

Expected: PASS.

- [ ] **Step 4: Commit source/test work**

Stage only source, tests, and changelog. Do not stage ignored build output, `.agents/`, external staging logs, or plan docs.

```bash
git add \
  plugins/woocommerce/src/Internal/MultiCurrency/MultiCurrencySettingsController.php \
  plugins/woocommerce/src/Internal/MultiCurrency/Services/MultiCurrencySettingsProjectionService.php \
  plugins/woocommerce/tests/php/src/Internal/MultiCurrency/MultiCurrencySettingsControllerTest.php \
  plugins/woocommerce/tests/php/src/Internal/MultiCurrency/Services/MultiCurrencySettingsProjectionServiceTest.php \
  plugins/woocommerce/client/admin/client/wp-admin-scripts/multi-currency-settings \
  plugins/woocommerce/changelog/add-native-payments-b2ag-multi-currency-settings
git commit -m "fix(payments): add multi-currency settings bundle"
```

## Self-Review

- Spec coverage: the plan covers the blank connected settings tab, native asset registration, enabled-currency management, tests, build proof, and changelog. It does not implement per-currency exchange-rate editing or store-level settings because those are separate REST/UI surfaces and would broaden the slice.
- Placeholder scan: no TBD/TODO placeholders remain.
- Type consistency: REST response types match `MultiCurrencyCurrency::jsonSerialize()` and `MultiCurrencyRestController::get_store_currencies()`.
