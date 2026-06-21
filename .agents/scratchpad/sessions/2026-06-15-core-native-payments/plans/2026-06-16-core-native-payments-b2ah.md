# Core Native Payments B2ah Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use $subagent-driven-development (recommended) or $executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add Core-native store-level multi-currency settings to the WooCommerce settings tab.

**Architecture:** Keep the existing native REST controller and option schema unchanged. Add a focused React component inside the B2ag wp-admin script that adapts preserved `wcpay_multi_currency_*` REST keys into readable UI state, then serializes the same keys back on save.

**Tech Stack:** WooCommerce Admin wp-admin-scripts, React/TypeScript, WordPress components, `@wordpress/api-fetch`, `@wordpress/data` notices, Jest/React Testing Library, WooCommerce markdown and JS linting.

---

## File Structure

- Modify: `plugins/woocommerce/client/admin/client/wp-admin-scripts/multi-currency-settings/types.ts`
    - Adds REST response and UI state types for store-level settings.
- Create: `plugins/woocommerce/client/admin/client/wp-admin-scripts/multi-currency-settings/store-settings.tsx`
    - Owns loading, dirty state, conditional controls, REST save, and notices for store-level settings.
- Modify: `plugins/woocommerce/client/admin/client/wp-admin-scripts/multi-currency-settings/app.tsx`
    - Renders `StoreLevelSettings` above the enabled-currencies table and adds a small section heading for the existing table.
- Create: `plugins/woocommerce/client/admin/client/wp-admin-scripts/multi-currency-settings/test/store-settings.test.tsx`
    - Covers loading, conditional controls, REST payload serialization, success and error notices, and accessible saving states.
- Modify: `plugins/woocommerce/client/admin/client/wp-admin-scripts/multi-currency-settings/test/app.test.tsx`
    - Mocks the new child component so existing enabled-currency tests stay focused on their current behavior.
- Create: `plugins/woocommerce/changelog/add-native-payments-b2ah-store-settings`
    - Patch changelog entry for the native store-level settings UI.

## Task 1: Store Settings Tests

**Files:**

- Modify: `plugins/woocommerce/client/admin/client/wp-admin-scripts/multi-currency-settings/types.ts`
- Create: `plugins/woocommerce/client/admin/client/wp-admin-scripts/multi-currency-settings/store-settings.tsx`
- Test: `plugins/woocommerce/client/admin/client/wp-admin-scripts/multi-currency-settings/test/store-settings.test.tsx`

- [ ] **Step 1: Add store settings types for test imports**

Add these interfaces to `types.ts` before writing the component:

```ts
export type StoreSettingsBoolean = boolean | 'yes' | 'no';
export type RenderingMode = 'speed' | 'cache';

export interface StoreSettingsResponse {
    wcpay_multi_currency_enable_auto_currency: StoreSettingsBoolean;
    wcpay_multi_currency_enable_storefront_switcher: StoreSettingsBoolean;
    wcpay_multi_currency_rendering_mode: RenderingMode;
    is_cache_optimized_feature_enabled: boolean;
    site_theme: string;
    date_format: string;
    time_format: string;
    store_url: string;
}

export interface StoreSettingsState {
    enableAutoCurrency: boolean;
    enableStorefrontSwitcher: boolean;
    renderingMode: RenderingMode;
    isCacheOptimizedFeatureEnabled: boolean;
    siteTheme: string;
}
```

- [ ] **Step 2: Write failing Jest tests**

Create `test/store-settings.test.tsx` with `@wordpress/api-fetch` and notices mocked like the existing app tests. Cover these behaviors:

```ts
it( 'loads and renders store-level settings', async () => {
    render( <StoreLevelSettings /> );

    expect( mockApiFetch ).toHaveBeenCalledWith( {
        path: '/wc/v3/payments/multi-currency/get-settings',
    } );
    expect(
        await screen.findByRole( 'heading', { name: 'Store settings' } )
    ).toBeInTheDocument();
    expect(
        screen.getByRole( 'checkbox', {
            name: /Automatically switch customers to their local currency/i,
        } )
    ).toBeChecked();
} );

it( 'saves store settings with preserved REST option keys', async () => {
    render( <StoreLevelSettings /> );

    await user.click(
        await screen.findByRole( 'checkbox', {
            name: /Automatically switch customers to their local currency/i,
        } )
    );
    await user.click( screen.getByRole( 'radio', { name: 'Optimized for caching' } ) );
    await user.click( screen.getByRole( 'button', { name: 'Save changes' } ) );

    await waitFor( () => {
        expect( mockApiFetch ).toHaveBeenLastCalledWith( {
            path: '/wc/v3/payments/multi-currency/update-settings',
            method: 'POST',
            data: {
                wcpay_multi_currency_enable_auto_currency: 'no',
                wcpay_multi_currency_enable_storefront_switcher: 'no',
                wcpay_multi_currency_rendering_mode: 'cache',
            },
        } );
    } );
} );
```

Also test:

- Storefront switcher is hidden unless `site_theme` is `Storefront`.
- Rendering mode radios are hidden unless `is_cache_optimized_feature_enabled` is true.
- A failed save calls `createErrorNotice( 'Error saving store settings.' )`.
- The focused save button stays focused and exposes `aria-disabled="true"` while saving.

- [ ] **Step 3: Run Jest red**

Run from `plugins/woocommerce/client/admin`:

```bash
pnpm test:js -- multi-currency-settings
```

Expected: FAIL because `StoreLevelSettings` and the store settings behavior do not exist yet.

## Task 2: Store Settings Component

**Files:**

- Create: `plugins/woocommerce/client/admin/client/wp-admin-scripts/multi-currency-settings/store-settings.tsx`
- Modify: `plugins/woocommerce/client/admin/client/wp-admin-scripts/multi-currency-settings/app.tsx`
- Modify: `plugins/woocommerce/client/admin/client/wp-admin-scripts/multi-currency-settings/test/app.test.tsx`

- [ ] **Step 1: Implement REST adapters**

Create small helpers in `store-settings.tsx`:

```ts
const isEnabled = ( value: StoreSettingsBoolean ): boolean =>
    value === true || value === 'yes';

const normalizeStoreSettings = (
    response: StoreSettingsResponse
): StoreSettingsState => ( {
    enableAutoCurrency: isEnabled(
        response.wcpay_multi_currency_enable_auto_currency
    ),
    enableStorefrontSwitcher: isEnabled(
        response.wcpay_multi_currency_enable_storefront_switcher
    ),
    renderingMode: response.wcpay_multi_currency_rendering_mode || 'speed',
    isCacheOptimizedFeatureEnabled:
        response.is_cache_optimized_feature_enabled,
    siteTheme: response.site_theme,
} );

const serializeStoreSettings = ( settings: StoreSettingsState ) => ( {
    wcpay_multi_currency_enable_auto_currency:
        settings.enableAutoCurrency ? 'yes' : 'no',
    wcpay_multi_currency_enable_storefront_switcher:
        settings.enableStorefrontSwitcher ? 'yes' : 'no',
    wcpay_multi_currency_rendering_mode: settings.renderingMode,
} );
```

- [ ] **Step 2: Implement loading and error states**

`StoreLevelSettings` should load on mount with:

```ts
apiFetch< StoreSettingsResponse >( {
    path: `${ REST_BASE }/get-settings`,
} )
```

Use a polite loading paragraph with `Spinner` and `Loading store settings...`. On load failure, show a role `alert` fallback and call:

```ts
createErrorNotice( __( 'Error loading store settings.', 'woocommerce' ) );
```

- [ ] **Step 3: Implement the form controls**

Render:

- `h2` with `Store settings`.
- `CheckboxControl` for `Automatically switch customers to their local currency if it has been enabled`.
- `CheckboxControl` for `Add a currency switcher to the Storefront theme on breadcrumb section.` only when `siteTheme === 'Storefront'`.
- `RadioControl` for `Price rendering mode` only when `isCacheOptimizedFeatureEnabled` is true, with options `Optimized for speed (default)` and `Optimized for caching`.
- A primary `Button` labelled `Save changes`.

Mark the draft dirty on every control change. Keep the save button discoverable with `disabled={ isSaving || ! isDirty }` and `accessibleWhenDisabled`; guard `onClick` so disabled states cannot save.

- [ ] **Step 4: Implement save behavior**

On save, call:

```ts
apiFetch< StoreSettingsResponse >( {
    path: `${ REST_BASE }/update-settings`,
    method: 'POST',
    data: serializeStoreSettings( draftSettings ),
} )
```

On success, normalize the response back into both committed and draft state, clear dirty state, and call:

```ts
createSuccessNotice( __( 'Store settings saved.', 'woocommerce' ) );
```

On failure, keep the current draft and call:

```ts
createErrorNotice( __( 'Error saving store settings.', 'woocommerce' ) );
```

- [ ] **Step 5: Mount the component in the existing app**

Import and render the component above the enabled-currencies controls:

```tsx
<StoreLevelSettings />
<h2>{ __( 'Enabled currencies', 'woocommerce' ) }</h2>
```

In `test/app.test.tsx`, mock `../store-settings` to render a simple marker so enabled-currencies tests do not depend on the new component's independent fetch.

- [ ] **Step 6: Run Jest green**

Run from `plugins/woocommerce/client/admin`:

```bash
pnpm test:js -- multi-currency-settings
```

Expected: PASS.

## Task 3: Changelog And Gates

**Files:**

- Create: `plugins/woocommerce/changelog/add-native-payments-b2ah-store-settings`
- Verify: `plugins/woocommerce/client/admin/client/wp-admin-scripts/multi-currency-settings/*`

- [ ] **Step 1: Add changelog**

Create:

```text
Significance: patch
Type: fix

Add native store-level multi-currency settings to the WooCommerce settings tab.
```

- [ ] **Step 2: Run focused JS checks**

Run from `plugins/woocommerce/client/admin`:

```bash
pnpm test:js -- multi-currency-settings
pnpm exec eslint client/wp-admin-scripts/multi-currency-settings --ext=js,ts,tsx --cache --cache-location=node_modules/.cache/eslint
pnpm lint:lang:types
```

Expected: PASS.

- [ ] **Step 3: Run bundle build**

Run from `plugins/woocommerce/client/admin`:

```bash
pnpm build:project:bundle
```

Expected: PASS and regenerate ignored
`assets/client/admin/wp-admin-scripts/multi-currency-settings.*` files.

- [ ] **Step 4: Run repository gates**

Run from the repository root:

```bash
pnpm --filter=@woocommerce/plugin-woocommerce lint:php:changes
git diff --check
```

Expected: PASS.

- [ ] **Step 5: Commit source and changelog separately**

Commit source/tests first:

```bash
git add plugins/woocommerce/client/admin/client/wp-admin-scripts/multi-currency-settings plugins/woocommerce/changelog/add-native-payments-b2ah-store-settings
git reset -- plugins/woocommerce/changelog/add-native-payments-b2ah-store-settings
git commit -m "fix(payments): add multi-currency store settings"
```

Then commit the changelog:

```bash
git add plugins/woocommerce/changelog/add-native-payments-b2ah-store-settings
git commit -m "chore(payments): add store settings changelog"
```

If signing fails, leave files staged as instructed by the repository guidance and report the intended commits.
