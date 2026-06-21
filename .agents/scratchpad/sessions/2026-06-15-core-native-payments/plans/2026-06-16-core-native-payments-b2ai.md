# Core Native Payments B2ai Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use $subagent-driven-development (recommended) or $executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add Core-native per-currency multi-currency settings for enabled non-default currencies.

**Architecture:** Keep Core's migrated single-currency REST routes unchanged. Add a focused modal component inside the native multi-currency settings bundle that loads `/currencies/{code}`, edits exchange-rate and formatting fields, saves the preserved payload shape, and returns focus to the triggering `Manage` button.

**Tech Stack:** WooCommerce Admin wp-admin-scripts, React/TypeScript, WordPress components, `@wordpress/api-fetch`, `@wordpress/data` notices, Jest/React Testing Library, WooCommerce markdown and JS linting.

---

## File Structure

- Modify: `plugins/woocommerce/client/admin/client/wp-admin-scripts/multi-currency-settings/types.ts`
    - Adds single-currency settings response/state types.
- Create: `plugins/woocommerce/client/admin/client/wp-admin-scripts/multi-currency-settings/currency-settings-modal.tsx`
    - Owns per-currency REST load/save, exchange-rate controls, formatting controls, notices, and focus-safe saving states.
- Create: `plugins/woocommerce/client/admin/client/wp-admin-scripts/multi-currency-settings/test/currency-settings-modal.test.tsx`
    - Covers load, manual-rate save payload, conditional manual field, error notices, and focused save state.
- Modify: `plugins/woocommerce/client/admin/client/wp-admin-scripts/multi-currency-settings/app.tsx`
    - Adds `Manage` actions for non-default enabled currencies, opens the modal, closes it, and merges a manual saved rate into the local row display.
- Modify: `plugins/woocommerce/client/admin/client/wp-admin-scripts/multi-currency-settings/test/app.test.tsx`
    - Mocks the modal for existing table tests and adds a focused test that verifies the `Manage` action opens it with the selected currency.
- Create: `plugins/woocommerce/changelog/add-native-payments-b2ai-currency-settings`
    - Patch changelog entry for the native per-currency settings UI.

## Task 1: Modal Tests

**Files:**

- Modify: `plugins/woocommerce/client/admin/client/wp-admin-scripts/multi-currency-settings/types.ts`
- Create: `plugins/woocommerce/client/admin/client/wp-admin-scripts/multi-currency-settings/currency-settings-modal.tsx`
- Test: `plugins/woocommerce/client/admin/client/wp-admin-scripts/multi-currency-settings/test/currency-settings-modal.test.tsx`

- [ ] **Step 1: Add single-currency types for tests**

Add these interfaces to `types.ts`:

```ts
export type ExchangeRateType = 'automatic' | 'manual';

export interface CurrencySettingsResponse {
    exchange_rate_type: ExchangeRateType;
    manual_rate: number | string | null;
    price_rounding: number | string | null;
    price_charm: number | string | null;
}

export interface CurrencySettingsState {
    exchangeRateType: ExchangeRateType;
    manualRate: string;
    priceRounding: string;
    priceCharm: string;
}
```

- [ ] **Step 2: Write failing modal tests**

Create `test/currency-settings-modal.test.tsx` with mocked `@wordpress/api-fetch` and notices. Use a Euro fixture and assert these behaviors:

```ts
it( 'loads and renders currency settings', async () => {
    render(
        <CurrencySettingsModal
            currency={ euroCurrency }
            defaultCurrency={ usdCurrency }
            onClose={ jest.fn() }
            onSaved={ jest.fn() }
        />
    );

    expect( mockApiFetch ).toHaveBeenCalledWith( {
        path: '/wc/v3/payments/multi-currency/currencies/EUR',
    } );
    expect(
        await screen.findByRole( 'heading', {
            name: 'Manage Euro settings',
        } )
    ).toBeInTheDocument();
    expect(
        screen.getByRole( 'radio', { name: 'Fetch rates automatically' } )
    ).toBeChecked();
} );

it( 'saves manual currency settings with preserved REST keys', async () => {
    render( <CurrencySettingsModal ... /> );

    fireEvent.click(
        await screen.findByRole( 'radio', { name: 'Manual' } )
    );
    fireEvent.change( screen.getByLabelText( 'Manual rate' ), {
        target: { value: '0.95' },
    } );
    fireEvent.change( screen.getByLabelText( 'Price rounding' ), {
        target: { value: '1.00' },
    } );
    fireEvent.change( screen.getByLabelText( 'Charm pricing' ), {
        target: { value: '-0.01' },
    } );
    fireEvent.click( screen.getByRole( 'button', { name: 'Save changes' } ) );

    await waitFor( () => {
        expect( mockApiFetch ).toHaveBeenLastCalledWith( {
            path: '/wc/v3/payments/multi-currency/currencies/EUR',
            method: 'POST',
            data: {
                exchange_rate_type: 'manual',
                manual_rate: 0.95,
                price_rounding: 1,
                price_charm: -0.01,
            },
        } );
    } );
} );
```

Also test:

- The manual-rate input is hidden for automatic rates and visible for manual rates.
- A failed save calls `createErrorNotice( 'Error saving currency settings.' )`.
- The focused save button stays focused and exposes `aria-disabled="true"` while saving.

- [ ] **Step 3: Run Jest red**

Run from `plugins/woocommerce/client/admin`:

```bash
pnpm test:js -- multi-currency-settings
```

Expected: FAIL because `CurrencySettingsModal` does not exist yet.

## Task 2: Modal Implementation

**Files:**

- Create: `plugins/woocommerce/client/admin/client/wp-admin-scripts/multi-currency-settings/currency-settings-modal.tsx`

- [ ] **Step 1: Implement formatting option constants**

Use WooPayments-compatible options:

```ts
const decimalCurrencyRoundingOptions = {
    '0': __( 'None', 'woocommerce' ),
    '0.25': '0.25',
    '0.50': '0.50',
    '1.00': '1.00',
    '5.00': '5.00',
    '10.00': '10.00',
};

const zeroDecimalCurrencyRoundingOptions = {
    '1': '1',
    '10': '10',
    '25': '25',
    '50': '50',
    '100': '100',
    '500': '500',
    '1000': '1000',
};

const decimalCurrencyCharmOptions = {
    '0.00': __( 'None', 'woocommerce' ),
    '-0.01': '-0.01',
    '-0.05': '-0.05',
};

const zeroDecimalCurrencyCharmOptions = {
    '0.00': __( 'None', 'woocommerce' ),
    '-1': '-1',
    '-5': '-5',
    '-10': '-10',
    '-20': '-20',
    '-25': '-25',
    '-50': '-50',
    '-100': '-100',
};
```

- [ ] **Step 2: Implement load and normalize**

Load settings on mount with:

```ts
apiFetch< CurrencySettingsResponse >( {
    path: `${ REST_BASE }/currencies/${ currency.code }`,
} )
```

Normalize missing values to:

- `exchangeRateType`: `automatic`
- `manualRate`: existing manual rate or the current currency rate
- `priceRounding`: `100` for zero-decimal currencies, otherwise `1.00`
- `priceCharm`: `0.00`

- [ ] **Step 3: Implement modal form**

Render a WordPress `Modal` titled `Manage {currency.name} settings` with:

- `RadioControl` labelled `Exchange rate`.
- `TextControl` labelled `Manual rate` only when exchange-rate type is `manual`.
- `SelectControl` labelled `Price rounding`.
- `SelectControl` labelled `Charm pricing`.
- `Cancel` and `Save changes` buttons.

Use `accessibleWhenDisabled` on the saving button and guard the save handler.

- [ ] **Step 4: Implement save**

Save with:

```ts
apiFetch< CurrencySettingsResponse >( {
    path: `${ REST_BASE }/currencies/${ currency.code }`,
    method: 'POST',
    data: {
        exchange_rate_type: draft.exchangeRateType,
        manual_rate: Number( draft.manualRate || currency.rate ),
        price_rounding: Number( draft.priceRounding ),
        price_charm: Number( draft.priceCharm ),
    },
} )
```

On success:

- Call `createSuccessNotice( __( 'Currency settings saved.', 'woocommerce' ) )`.
- Call `onSaved( currency.code, savedRateOrNull )`, where `savedRateOrNull` is the numeric manual rate only when the saved exchange-rate type is `manual`.
- Call `onClose()`.

On failure:

- Keep the modal open.
- Call `createErrorNotice( __( 'Error saving currency settings.', 'woocommerce' ) )`.

- [ ] **Step 5: Run modal Jest green**

Run from `plugins/woocommerce/client/admin`:

```bash
pnpm test:js -- multi-currency-settings
```

Expected: PASS for the modal tests before app integration.

## Task 3: App Integration

**Files:**

- Modify: `plugins/woocommerce/client/admin/client/wp-admin-scripts/multi-currency-settings/app.tsx`
- Modify: `plugins/woocommerce/client/admin/client/wp-admin-scripts/multi-currency-settings/test/app.test.tsx`

- [ ] **Step 1: Add failing app integration test**

In `app.test.tsx`, mock `../currency-settings-modal` and assert that clicking `Manage Euro settings` renders the mock modal for `EUR`.

- [ ] **Step 2: Wire table actions**

Add app state for the managed currency:

```ts
const [ managedCurrencyCode, setManagedCurrencyCode ] =
    useState< string | null >( null );
```

Render `Manage` before `Remove` for non-default currencies with an accessible label:

```tsx
<Button
    variant="link"
    aria-label={ sprintf(
        __( 'Manage %s settings', 'woocommerce' ),
        currency.name
    ) }
    onClick={ () => setManagedCurrencyCode( currency.code ) }
>
    { __( 'Manage', 'woocommerce' ) }
</Button>
```

When `managedCurrencyCode` resolves to an enabled currency, render:

```tsx
<CurrencySettingsModal
    currency={ managedCurrency }
    defaultCurrency={ currencies.default }
    onClose={ () => setManagedCurrencyCode( null ) }
    onSaved={ updateManagedCurrencyRate }
/>
```

- [ ] **Step 3: Update row rate after manual save**

When `onSaved` provides a numeric manual rate, merge that rate into the matching enabled and available currency objects in local state so the table updates immediately.

- [ ] **Step 4: Run Jest green**

Run from `plugins/woocommerce/client/admin`:

```bash
pnpm test:js -- multi-currency-settings
```

Expected: PASS.

## Task 4: Changelog And Gates

**Files:**

- Create: `plugins/woocommerce/changelog/add-native-payments-b2ai-currency-settings`

- [ ] **Step 1: Add changelog**

Create:

```text
Significance: patch
Type: fix

Add native per-currency multi-currency settings to the WooCommerce settings tab.
```

- [ ] **Step 2: Run focused JS checks**

Run from `plugins/woocommerce/client/admin`:

```bash
pnpm test:js -- multi-currency-settings
pnpm exec eslint client/wp-admin-scripts/multi-currency-settings --ext=js,ts,tsx --cache --cache-location=node_modules/.cache/eslint
pnpm lint:lang:types
```

Expected: PASS.

- [ ] **Step 3: Run bundle and repo checks**

Run from `plugins/woocommerce/client/admin`:

```bash
pnpm build:project:bundle
```

Run from the repository root:

```bash
pnpm --filter=@woocommerce/plugin-woocommerce lint:php:changes
git diff --check
```

Expected: PASS.

- [ ] **Step 4: Commit source and changelog separately**

Commit source/tests first:

```bash
git add plugins/woocommerce/client/admin/client/wp-admin-scripts/multi-currency-settings
git reset -- plugins/woocommerce/changelog/add-native-payments-b2ai-currency-settings
git commit -m "fix(payments): add multi-currency currency settings"
```

Then commit the changelog:

```bash
git add plugins/woocommerce/changelog/add-native-payments-b2ai-currency-settings
git commit -m "chore(payments): add currency settings changelog"
```

If signing fails, leave files staged as instructed by the repository guidance and report the intended commits.
