---
session: 2026-06-15-core-native-payments
type: plan
by: codex
created: 2026-06-19 04:19
last_updated: 2026-06-19 04:19
status: draft
reconciles:
  - supervisor-prompt-2026-06-18-2344-N12.md
  - analysis-a4m-fraud-protection-settings-parity.md
  - staging-log.md
---

# A4m Fraud Protection Settings Parity Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use $subagent-driven-development (recommended) or $executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Restore WooPayments fraud-protection settings parity inside the native WooCommerce Settings > Payments provider route, including the Basic/Advanced selector and the advanced fraud-protection rule page.

**Architecture:** Keep route ownership under the Core Settings > Payments provider route seam. Hoist the reference fraud-protection component shape and ruleset helpers as a WooPayments settings subtree adapted to the existing native `wc/payments/settings` store, Core i18n domain, Core route URL helper, and Core bootstrap payload. Do not reintroduce plugin-era `/payments/*` route ownership or plugin globals as required runtime dependencies.

**Tech Stack:** React/TypeScript, `@wordpress/components`, `@wordpress/data`, WooCommerce admin client routing, Jest/React Testing Library, WooCommerce Core PHP settings REST service where bootstrap payload changes are needed.

---

## File Map

- Modify `plugins/woocommerce/client/admin/client/woopayments/admin/routes.tsx`: register a lazy route for `/woopayments/settings/fraud-protection` using a dedicated fraud-protection chunk.
- Modify `plugins/woocommerce/client/admin/client/woopayments/admin/test/routes.test.tsx`: assert route registration count/order and dedicated chunk name.
- Modify `plugins/woocommerce/client/admin/client/woopayments/admin/test/utils.test.ts`: assert native fraud route URL encoding.
- Modify `plugins/woocommerce/client/admin/client/woopayments/settings/settings-page.tsx`: replace the reduced `SelectControl` fraud section with a native wrapper around the fraud-protection Basic/Advanced component and link to the native advanced route.
- Modify `plugins/woocommerce/client/admin/client/woopayments/settings/test/settings-page.test.tsx`: add RED tests for reference fraud section copy, radio controls, modal behavior, and native Configure/Edit link.
- Create `plugins/woocommerce/client/admin/client/woopayments/settings/fraud-protection/index.tsx`: main fraud-protection section components for Basic/Advanced radio selection, Basic modal, error notice, and native Configure/Edit action.
- Create `plugins/woocommerce/client/admin/client/woopayments/settings/fraud-protection/advanced/index.tsx`: advanced fraud settings page.
- Create `plugins/woocommerce/client/admin/client/woopayments/settings/fraud-protection/advanced/constants.ts`: rule keys, check keys, outcomes, supported AVS countries, and `ProtectionLevel`.
- Create `plugins/woocommerce/client/admin/client/woopayments/settings/fraud-protection/advanced/types.ts`: `ProtectionSettingsUI`, ruleset, checks, and rule-specific UI types.
- Create `plugins/woocommerce/client/admin/client/woopayments/settings/fraud-protection/advanced/utils.ts`: `readRuleset()`, `writeRuleset()`, selling-location helpers, and threshold conversion helpers.
- Create `plugins/woocommerce/client/admin/client/woopayments/settings/fraud-protection/advanced/context.ts`: local React context for advanced rule cards.
- Create `plugins/woocommerce/client/admin/client/woopayments/settings/fraud-protection/advanced/rule-card.tsx`: shared accessible rule card wrapper.
- Create `plugins/woocommerce/client/admin/client/woopayments/settings/fraud-protection/advanced/rule-toggle.tsx`: shared enable/block-review controls.
- Create `plugins/woocommerce/client/admin/client/woopayments/settings/fraud-protection/advanced/rule-description.tsx`: expandable help text.
- Create `plugins/woocommerce/client/admin/client/woopayments/settings/fraud-protection/advanced/allow-countries-notice.tsx`: selling-location notice used by international IP rules.
- Create `plugins/woocommerce/client/admin/client/woopayments/settings/fraud-protection/advanced/cards/*.tsx`: seven cards for AVS mismatch, CVC verification, international IP address, IP address mismatch, address mismatch, purchase price threshold, and order items threshold.
- Create `plugins/woocommerce/client/admin/client/woopayments/settings/fraud-protection/advanced/style.scss` and companion SCSS files if needed: WooPayments-scoped styles only.
- Create `plugins/woocommerce/client/admin/client/woopayments/settings/test/fraud-protection.test.tsx`: focused tests for the main fraud component.
- Create `plugins/woocommerce/client/admin/client/woopayments/settings/test/fraud-protection-advanced.test.tsx`: focused tests for advanced page rendering, validation, and save behavior.
- Modify `plugins/woocommerce/client/admin/client/woopayments/settings/data/selectors.ts`, `hooks.ts`, and tests only if additional bootstrap selectors are required for fraud feature flags, account fraud status, store currency, or allowed countries.
- Modify `plugins/woocommerce/src/Internal/Payments/Providers/WooPayments/WooPaymentsSettingsService.php` and its PHP tests only if frontend bootstrap fields cannot be sourced from already-returned settings.
- Add a WooCommerce Core changelog entry after production changes.
- Update `implementation-log.md`, `staging-log.md`, and `spec-conformance-baseline.md` with scope, evidence, and N12 status.

## Task 1: Route and URL Contract

**Files:**
- Modify `plugins/woocommerce/client/admin/client/woopayments/admin/routes.tsx`
- Modify `plugins/woocommerce/client/admin/client/woopayments/admin/test/routes.test.tsx`
- Modify `plugins/woocommerce/client/admin/client/woopayments/admin/test/utils.test.ts`

- [ ] **Step 1: Write RED route registration tests.**

Update `routes.test.tsx` so `getSettingsPaymentsProviderRoutes()` expects 13 routes and includes:

```ts
{
	id: 'woopayments-fraud-protection-settings',
	path: '/woopayments/settings/fraud-protection',
	order: 92,
}
```

Add a chunk assertion:

```ts
expect( source ).toContain(
	'webpackChunkName: "settings-payments-woopayments-fraud-protection-settings"'
);
```

Run:

```bash
pnpm --filter=@woocommerce/admin-library test:js -- client/woopayments/admin/test/routes.test.tsx
```

Expected: FAIL because the route and chunk are not registered.

- [ ] **Step 2: Write RED URL helper coverage.**

Add to `utils.test.ts`:

```ts
expect(
	getSettingsPaymentsProviderRouteUrl(
		'/woopayments/settings/fraud-protection?from=woopayments-settings'
	)
).toBe(
	'https://example.com/wp-admin/admin.php?page=wc-settings&tab=checkout&path=%2Fwoopayments%2Fsettings%2Ffraud-protection&from=woopayments-settings'
);
```

Run:

```bash
pnpm --filter=@woocommerce/admin-library test:js -- client/woopayments/admin/test/utils.test.ts
```

Expected: PASS if the existing helper is already generic. If it fails, fix only the helper behavior required by this route.

- [ ] **Step 3: Implement the route.**

Add the lazy chunk import in `routes.tsx`:

```tsx
const WooPaymentsFraudProtectionSettingsChunk = lazy(
	() =>
		import(
			/* webpackChunkName: "settings-payments-woopayments-fraud-protection-settings" */ '../settings/fraud-protection/advanced'
		)
);
```

Register the route after express checkout:

```tsx
registerSettingsPaymentsProviderRoute( {
	id: 'woopayments-fraud-protection-settings',
	path: '/woopayments/settings/fraud-protection',
	order: 92,
	element: (
		<Suspense fallback={ <LoadingFallback /> }>
			<WooPaymentsFraudProtectionSettingsChunk />
		</Suspense>
	),
} );
```

- [ ] **Step 4: Run route tests green.**

Run:

```bash
pnpm --filter=@woocommerce/admin-library test:js -- client/woopayments/admin/test/routes.test.tsx client/woopayments/admin/test/utils.test.ts
```

Expected: PASS.

## Task 2: Main Fraud Protection Section Parity

**Files:**
- Modify `plugins/woocommerce/client/admin/client/woopayments/settings/settings-page.tsx`
- Modify `plugins/woocommerce/client/admin/client/woopayments/settings/test/settings-page.test.tsx`
- Create `plugins/woocommerce/client/admin/client/woopayments/settings/fraud-protection/index.tsx`
- Create `plugins/woocommerce/client/admin/client/woopayments/settings/fraud-protection/style.scss`

- [ ] **Step 1: Write RED settings-page tests for main fraud parity.**

Add tests asserting:

```ts
mockUseCurrentProtectionLevel.mockReturnValue( [ 'basic', noop ] );
mockUseAdvancedFraudProtectionSettings.mockReturnValue( [ [], noop ] );

render( <WooPaymentsSettingsPage /> );

const section = screen
	.getByRole( 'heading', { name: 'Fraud protection' } )
	.closest( '.woopayments-settings-section' ) as HTMLElement;

expect(
	within( section ).getByRole( 'heading', {
		name: 'Set your payment risk level',
	} )
).toBeInTheDocument();
expect(
	within( section ).getByRole( 'radio', { name: 'Basic' } )
).toBeChecked();
expect(
	within( section ).getByRole( 'radio', { name: 'Advanced' } )
).toBeInTheDocument();
expect(
	within( section ).queryByRole( 'combobox', {
		name: 'Protection level',
	} )
).not.toBeInTheDocument();
expect(
	within( section ).queryByText( 'Standard' )
).not.toBeInTheDocument();
expect(
	within( section ).getByText(
		'Provides the base level of platform protection.'
	)
).toBeInTheDocument();
expect(
	within( section ).getByText(
		'Allows you to fine-tune the level of filtering according to your business needs.'
	)
).toBeInTheDocument();
expect(
	within( section ).getByRole( 'link', { name: 'Configure' } )
).toHaveAttribute(
	'href',
	expect.stringContaining(
		'path=%2Fwoopayments%2Fsettings%2Ffraud-protection'
	)
);
```

Add a modal test that clicks the Basic help button and expects `Basic filter level`, AVS/CVC guidance text, and `Got it`.

Run:

```bash
pnpm --filter=@woocommerce/admin-library test:js -- client/woopayments/settings/test/settings-page.test.tsx
```

Expected: FAIL because native still renders a select.

- [ ] **Step 2: Create the main fraud component.**

Implement `FraudProtectionSettings` as a focused component that consumes `currentProtectionLevel`, `advancedFraudProtectionSettings`, `isDirty`, and `settings` from the existing native hooks. Use native `<fieldset>`, radio inputs, labels, and a `Button` with `href` for navigation. Use `aria-haspopup="dialog"` and `aria-expanded` on the Basic help trigger, and rely on `Modal` for focus trapping/return behavior.

Core behavior:

```tsx
const isAdvancedSettingsConfigured =
	Array.isArray( advancedFraudProtectionSettings ) &&
	advancedFraudProtectionSettings.length > 0;
const advancedSettingsUrl = getSettingsPaymentsProviderRouteUrl(
	'/woopayments/settings/fraud-protection?from=woopayments-settings'
);
```

Expose only Basic and Advanced as merchant-facing choices. Treat any stored non-advanced value, including `standard` or `high`, as Basic for radio selection while preserving backend acceptance for existing data.

- [ ] **Step 3: Replace the settings page fraud select.**

In `settings-page.tsx`, remove `SelectControl` from `FraudProtectionSettingsSection` and render:

```tsx
<FraudProtectionSettings />
```

Keep the outer `SettingsSection` title/description so the layout remains consistent with the native page shell.

- [ ] **Step 4: Add scoped styles.**

Add WooPayments-scoped classes such as `.woopayments-fraud-protection-levels`, `.woopayments-fraud-protection-levels__option`, and `.woopayments-fraud-protection-levels__copy`. Use borders, spacing, and button alignment to preserve grouping. Do not add global selectors.

- [ ] **Step 5: Run main fraud tests green.**

Run:

```bash
pnpm --filter=@woocommerce/admin-library test:js -- client/woopayments/settings/test/settings-page.test.tsx
```

Expected: PASS.

## Task 3: Advanced Fraud Ruleset Helpers and Cards

**Files:**
- Create `plugins/woocommerce/client/admin/client/woopayments/settings/fraud-protection/advanced/constants.ts`
- Create `plugins/woocommerce/client/admin/client/woopayments/settings/fraud-protection/advanced/types.ts`
- Create `plugins/woocommerce/client/admin/client/woopayments/settings/fraud-protection/advanced/utils.ts`
- Create `plugins/woocommerce/client/admin/client/woopayments/settings/test/fraud-protection-advanced.test.tsx`

- [ ] **Step 1: Write RED utility tests.**

Create tests that verify:

- `readRuleset()` marks AVS mismatch enabled when a rule key `avs_verification` exists.
- `writeRuleset()` converts an enabled purchase price threshold with `min_amount: '10'` and `storeCurrency: 'USD'` into a check value of `1000|USD`.
- `writeRuleset()` writes `review` outcomes when the review feature is active and `block: false`.
- Selling-location helpers return `true` for `all`, respect `specific` allowed countries, and respect `all_except` exclusions.

Run:

```bash
pnpm --filter=@woocommerce/admin-library test:js -- client/woopayments/settings/test/fraud-protection-advanced.test.tsx
```

Expected: FAIL because the helpers do not exist.

- [ ] **Step 2: Implement constants and types.**

Create constants for the preserved provider contract:

```ts
export const ProtectionLevel = {
	BASIC: 'basic',
	ADVANCED: 'advanced',
	STANDARD: 'standard',
	HIGH: 'high',
} as const;
export const Rules = {
	RULE_AVS_VERIFICATION: 'avs_verification',
	RULE_ADDRESS_MISMATCH: 'address_mismatch',
	RULE_INTERNATIONAL_IP_ADDRESS: 'international_ip_address',
	RULE_IP_ADDRESS_MISMATCH: 'ip_address_mismatch',
	RULE_ORDER_ITEMS_THRESHOLD: 'order_items_threshold',
	RULE_PURCHASE_PRICE_THRESHOLD: 'purchase_price_threshold',
} as const;
export const Outcomes = {
	BLOCK: 'block',
	REVIEW: 'review',
} as const;
```

Add check constants matching the reference: `avs_mismatch`, `billing_shipping_address_same`, `ip_country`, `ip_billing_country_same`, `item_count`, and `order_total`.

- [ ] **Step 3: Implement pure ruleset utilities.**

Make the utilities accept explicit environment input instead of reading plugin globals:

```ts
export type FraudProtectionEnvironment = {
	storeCurrency: string;
	isReviewFeatureActive: boolean;
	allowedCountriesType: 'all' | 'all_except' | 'specific' | string;
	settingCountries: string[];
};
```

Export `readRuleset( ruleset )`, `writeRuleset( config, environment )`, and `isSellingToAvsSupportedLocations( environment )`. Default currency to `USD` and uppercase it before writing threshold values.

- [ ] **Step 4: Run utility tests green.**

Run:

```bash
pnpm --filter=@woocommerce/admin-library test:js -- client/woopayments/settings/test/fraud-protection-advanced.test.tsx
```

Expected: PASS for utility tests.

## Task 4: Advanced Fraud Settings Page

**Files:**
- Create `plugins/woocommerce/client/admin/client/woopayments/settings/fraud-protection/advanced/index.tsx`
- Create `plugins/woocommerce/client/admin/client/woopayments/settings/fraud-protection/advanced/context.ts`
- Create `plugins/woocommerce/client/admin/client/woopayments/settings/fraud-protection/advanced/rule-card.tsx`
- Create `plugins/woocommerce/client/admin/client/woopayments/settings/fraud-protection/advanced/rule-toggle.tsx`
- Create `plugins/woocommerce/client/admin/client/woopayments/settings/fraud-protection/advanced/rule-description.tsx`
- Create `plugins/woocommerce/client/admin/client/woopayments/settings/fraud-protection/advanced/allow-countries-notice.tsx`
- Create `plugins/woocommerce/client/admin/client/woopayments/settings/fraud-protection/advanced/cards/*.tsx`
- Create `plugins/woocommerce/client/admin/client/woopayments/settings/fraud-protection/advanced/style.scss`
- Modify `plugins/woocommerce/client/admin/client/woopayments/settings/test/fraud-protection-advanced.test.tsx`

- [ ] **Step 1: Write RED page rendering tests.**

Assert the page renders:

- `Advanced fraud protection`
- a back link with `href` containing `path=%2Fwoopayments%2Fsettings`
- `Filter configuration`
- `Set up advanced fraud filters. Enable at least one filter to activate advanced protection.`
- seven rule headings: `AVS Mismatch`, `CVC Verification`, `International IP Address`, `IP Address Mismatch`, `Address Mismatch`, `Purchase Price Threshold`, and `Order Items Threshold`
- `Save changes`

Run:

```bash
pnpm --filter=@woocommerce/admin-library test:js -- client/woopayments/settings/test/fraud-protection-advanced.test.tsx
```

Expected: FAIL because no page exists yet.

- [ ] **Step 2: Write RED validation and save tests.**

Add tests that enable Advanced with no rules while current level is Basic, click `Save changes`, and expect the error `At least one risk filter needs to be enabled for advanced protection.` without calling `saveSettings()`. Add a second test enabling AVS mismatch, clicking `Save changes`, and expecting `updateAdvancedFraudProtectionSettings()` with an `avs_verification` rule plus `updateProtectionLevel( 'advanced' )`.

- [ ] **Step 3: Implement the page shell.**

Use the existing native `useSettings`, `useCurrentProtectionLevel`, `useAdvancedFraudProtectionSettings`, and `useGetSettings` hooks. Render loading with a polite status when `isLoading` is true. Render a non-dismissible error notice when `advancedFraudProtectionSettings === 'error'`.

- [ ] **Step 4: Implement cards and shared controls.**

Use semantic checkbox controls for enable/disable and radio controls for `Authorize and hold for review` vs `Block payment` when the review feature flag is active. Keep controls inside each card so grouping matches the reference. Use text inputs for min/max threshold fields with clear labels: `Minimum order amount`, `Maximum order amount`, `Minimum items`, and `Maximum items`.

- [ ] **Step 5: Implement save behavior.**

On save:

```ts
if ( ! validateSettings( protectionSettingsUI ) ) {
	window.scrollTo( { top: 0 } );
	return;
}
if ( noRulesEnabled && currentProtectionLevel === ProtectionLevel.BASIC ) {
	createErrorNotice(
		'At least one risk filter needs to be enabled for advanced protection.'
	);
	return;
}
if ( noRulesEnabled ) {
	updateProtectionLevel( ProtectionLevel.BASIC );
} else if ( currentProtectionLevel !== ProtectionLevel.ADVANCED ) {
	updateProtectionLevel( ProtectionLevel.ADVANCED );
}
updateAdvancedFraudProtectionSettings(
	writeRuleset( protectionSettingsUI, environment )
);
saveSettings();
```

Set local dirty state when any card changes and add `window.onbeforeunload` only while dirty, cleaning it up on unmount or successful save.

- [ ] **Step 6: Run advanced page tests green.**

Run:

```bash
pnpm --filter=@woocommerce/admin-library test:js -- client/woopayments/settings/test/fraud-protection-advanced.test.tsx
```

Expected: PASS.

## Task 5: Bootstrap and Backend Contract Check

**Files:**
- Modify `plugins/woocommerce/client/admin/client/woopayments/settings/data/selectors.ts`
- Modify `plugins/woocommerce/client/admin/client/woopayments/settings/data/hooks.ts`
- Modify `plugins/woocommerce/client/admin/client/woopayments/settings/test/settings-data.test.ts`
- Modify `plugins/woocommerce/src/Internal/Payments/Providers/WooPayments/WooPaymentsSettingsService.php` only if needed
- Modify `plugins/woocommerce/tests/php/src/Internal/Payments/Providers/WooPayments/WooPaymentsSettingsServiceTest.php` only if needed

- [ ] **Step 1: Verify existing settings payload coverage.**

Inspect the native settings payload and browser bootstrap to confirm whether the following values already exist: `store_currency` or account domestic currency, `fraud_protection.decline_on_avs_failure`, `fraud_protection.decline_on_cvc_failure`, review-feature flag, allowed countries type, and allowed country lists.

- [ ] **Step 2: Add RED selector/backend tests only for missing required fields.**

If missing, add selectors with stable defaults:

```ts
getFraudProtectionAccountStatus() => { decline_on_avs_failure: true, decline_on_cvc_failure: true }
getFraudProtectionAllowedCountries() => { type: 'all', countries: [] }
getIsFraudProtectionReviewFeatureActive() => false
```

If backend fields are needed, add PHP tests proving `WooPaymentsSettingsService` returns sanitized settings based on WooCommerce selling-location options and cached account status, without network calls.

- [ ] **Step 3: Implement the smallest required payload additions.**

Keep the new fields under WooPayments settings data. Do not introduce plugin globals or a generic payments-level dependency on WooPayments fraud internals.

- [ ] **Step 4: Run data/backend tests.**

Run the relevant subset:

```bash
pnpm --filter=@woocommerce/admin-library test:js -- client/woopayments/settings/test/settings-data.test.ts
pnpm --filter=@woocommerce/plugin-woocommerce test:php:env -- --filter WooPaymentsSettingsServiceTest
```

Expected: PASS for tests relevant to changed files.

## Task 6: Verification, Browser Parity, Reviews, and Commit

**Files:**
- Add `plugins/woocommerce/changelog/*`
- Update `.agents/scratchpad/sessions/2026-06-15-core-native-payments/implementation-log.md`
- Update `.agents/scratchpad/sessions/2026-06-15-core-native-payments/staging-log.md`
- Update `.agents/scratchpad/sessions/2026-06-15-core-native-payments/spec-conformance-baseline.md`

- [ ] **Step 1: Run focused JS tests.**

Run:

```bash
pnpm --filter=@woocommerce/admin-library test:js -- client/woopayments/settings/test/settings-page.test.tsx client/woopayments/settings/test/fraud-protection-advanced.test.tsx client/woopayments/admin/test/routes.test.tsx client/woopayments/admin/test/utils.test.ts
```

- [ ] **Step 2: Run static frontend gates.**

Run changed-file ESLint/stylelint for touched `client/woopayments` files, `pnpm --filter=@woocommerce/admin-library lint:lang:types`, and `pnpm --filter=@woocommerce/plugin-woocommerce build:admin`.

- [ ] **Step 3: Run PHP gates if backend changed.**

Run PHP syntax, focused PHPUnit, PHPStan, `pnpm --filter=@woocommerce/plugin-woocommerce lint:php:changes`, and `pnpm --filter=@woocommerce/plugin-woocommerce changelog validate`.

- [ ] **Step 4: Run browser parity checks with Playwriter.**

Use target `http://store8889.localhost:8889/wp-admin/admin.php?page=wc-settings&tab=checkout&path=%2Fwoopayments%2Fsettings` and reference `http://localhost:8082/wp-admin/admin.php?page=wc-settings&tab=checkout&section=woocommerce_payments`. Capture screenshots and DOM summaries for the main fraud section. Navigate target to `path=%2Fwoopayments%2Fsettings%2Ffraud-protection` and verify advanced rule cards render and save validation works. If the reference advanced route differs, record the route adaptation explicitly rather than claiming identical URL ownership.

- [ ] **Step 5: Run restored harness and log scans.**

Run `tools/woopayments-merge/verify.sh` with the current reference and target WP commands. Scan target/reference logs for fresh PHP notices, warnings, deprecations, fatals, 5xx, uncaught errors, and stack traces created during the browser/harness window.

- [ ] **Step 6: Dispatch review agents.**

Use `a11y-reviewer` for radio/modal/card controls, `code-reviewer` for implementation correctness, and `wp-architecture-reviewer` or `architecture-reviewer` for route/data seam placement. Validate every finding against source before acting.

- [ ] **Step 7: Add changelog and update session docs.**

Add a `fix` changelog such as `Restore native WooPayments fraud protection settings parity.` Update the implementation log, staging log, and baseline with exact commands, evidence, caveats, and the status of N12. Record that A4 remains open for the other N12 surfaces until the widened A4 exit gate passes.

- [ ] **Step 8: Commit locally without pushing.**

Commit source/tests and changelog as one logical WooCommerce Core commit if the diff is cohesive. If backend bootstrap changes are substantial, split into one source commit and one changelog commit only if needed by logical-change boundaries. Never push.
