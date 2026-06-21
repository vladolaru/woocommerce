---
session: 2026-06-15-core-native-payments
type: plan
by: codex
created: 2026-06-20 06:57
last_updated: 2026-06-20 07:30
reconciles:
  - ../analysis-a4aj-express-checkout-preview-parity.md
  - ../supervisor-prompt-2026-06-18-2344-N12.md
status: final
---

# A4aj Express Checkout Preview Parity Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use $subagent-driven-development (recommended) or $executing-plans to implement this plan task-by-task. Steps use checkbox (`- [x]`) syntax for tracking.

**Goal:** Restore the native WooPayments express checkout settings preview so the Apple Pay / Google Pay settings page uses the same source-backed Stripe preview contract as the reference, with deterministic fallbacks and no global admin asset leakage.

**Architecture:** Add a read-only settings payload for public Stripe preview config, then replace the static preview with settings-local React components that render a WooPay button preview, a live `window.Stripe` Express Checkout Element when the page and config support it, or reference-equivalent notices when they do not. Keep the implementation scoped to the WooPayments settings chunk and use `loader: 'never'` in the Elements options to match native checkout behavior.

**Tech Stack:** WooCommerce Core PHP service/tests, React/TypeScript admin settings components, Jest + React Testing Library, Stripe.js through `window.Stripe`, Playwriter browser verification.

---

## File Structure

- Modify `plugins/woocommerce/src/Internal/Payments/Providers/WooPayments/WooPaymentsSettingsService.php` to expose `express_checkout_preview.stripe.publishableKey`, `express_checkout_preview.stripe.accountId`, and `express_checkout_preview.stripe.locale`.
- Modify `plugins/woocommerce/tests/php/src/Internal/Payments/Providers/WooPayments/WooPaymentsSettingsServiceTest.php` for the settings contract test and required key list.
- Modify `plugins/woocommerce/client/admin/client/woopayments/settings/express-checkout/notices.tsx` to export the real preview wrapper instead of the static fallback.
- Modify `plugins/woocommerce/client/admin/client/woopayments/settings/express-checkout/components.tsx` only if the existing notices need a reusable error variant or class hook.
- Create `plugins/woocommerce/client/admin/client/woopayments/settings/express-checkout/express-checkout-preview.tsx` for the settings-local WooPay preview button and Stripe preview adapter.
- Modify `plugins/woocommerce/client/admin/client/woopayments/settings/test/express-checkout-settings.test.tsx` with RED/GREEN coverage for payload-driven preview behavior.
- Modify `plugins/woocommerce/client/admin/client/woopayments/settings/express-checkout/style.scss` for scoped preview button/container styling only if required by tests/browser proof.
- Add a WooCommerce Core changelog entry after the slice verifies.

## Task 1: Settings Payload Contract

- [x] **Step 1: Write the failing PHP payload test**

Add assertions in `WooPaymentsSettingsServiceTest::test_get_settings_returns_reference_shaped_contract_without_stripe_billing_fields()` that expect `express_checkout_preview` to include:

```php
$this->assertSame(
	array(
			'stripe' => array(
			'publishableKey' => 'pk_test_native',
			'accountId'      => 'acct_native_test',
			'locale'         => strtolower( str_replace( '_', '-', determine_locale() ) ),
		),
	),
	$settings['express_checkout_preview']
);
```

Add `test_publishable_key` and `live_publishable_key` to the existing `wcpay_account_data.data` fixture in that test. Because the fixture has `test_mode => yes`, the AccountService-backed payload should return the test publishable key. Add `express_checkout_preview` to `get_required_settings_keys()`.

- [x] **Step 2: Run RED**

Run:

```bash
pnpm --filter=@woocommerce/plugin-woocommerce test:php:env -- --filter WooPaymentsSettingsServiceTest::test_get_settings_returns_reference_shaped_contract_without_stripe_billing_fields
```

Expected: FAIL because `express_checkout_preview` is missing.

- [x] **Step 3: Implement the settings payload**

In `WooPaymentsSettingsService::get_settings()`, add:

```php
'express_checkout_preview' => $this->get_express_checkout_preview_settings(),
```

Add a private helper:

```php
/**
 * Get public Stripe configuration used by the express checkout settings preview.
 *
 * @return array<string,array<string,string>>
 */
private function get_express_checkout_preview_settings(): array {
	return array(
		'stripe' => array(
			'publishableKey' => $this->account_service->get_publishable_key(),
			'accountId'      => $this->account_service->get_account_id(),
			'locale'         => $this->get_stripe_locale(),
		),
	);
}
```

Add the same `get_stripe_locale()` helper shape already used by the native express checkout service:

```php
/**
 * Get a Stripe-compatible locale.
 *
 * @return string
 */
private function get_stripe_locale(): string {
	$locale = function_exists( 'determine_locale' ) ? determine_locale() : get_locale();
	$locale = strtolower( str_replace( '_', '-', (string) $locale ) );

	return '' !== $locale ? $locale : 'auto';
}
```

- [x] **Step 4: Run GREEN**

Run the same focused PHPUnit command. Expected: PASS.

## Task 2: Frontend Preview RED

- [x] **Step 1: Add test helpers for `window.Stripe` and protocol**

In `express-checkout-settings.test.tsx`, add helpers near `setHookDefaults()`:

```ts
const originalLocation = window.location;
const mockExpressCheckoutMount = jest.fn();
const mockExpressCheckoutUnmount = jest.fn();
const mockExpressCheckoutOn = jest.fn();
const mockElementsCreate = jest.fn();
const mockStripeElements = jest.fn();
const mockStripe = jest.fn();

const setWindowProtocol = ( protocol: 'http:' | 'https:' ) => {
	Object.defineProperty( window, 'location', {
		configurable: true,
		value: {
			...originalLocation,
			protocol,
		},
	} );
};

const installStripeMock = () => {
	mockExpressCheckoutMount.mockClear();
	mockExpressCheckoutUnmount.mockClear();
	mockExpressCheckoutOn.mockClear();
	mockElementsCreate.mockClear();
	mockStripeElements.mockClear();
	mockStripe.mockClear();
	mockExpressCheckoutOn.mockImplementation( ( eventName, callback ) => {
		if ( eventName === 'ready' ) {
			callback( { availablePaymentMethods: { applePay: true, googlePay: true } } );
		}
	} );
	mockElementsCreate.mockReturnValue( {
		mount: mockExpressCheckoutMount,
		unmount: mockExpressCheckoutUnmount,
		on: mockExpressCheckoutOn,
	} );
	mockStripeElements.mockReturnValue( { create: mockElementsCreate } );
	mockStripe.mockReturnValue( { elements: mockStripeElements } );
	( window as typeof window & { Stripe?: typeof mockStripe } ).Stripe = mockStripe;
};
```

Restore `window.location` and delete `window.Stripe` in `afterEach`.

- [x] **Step 2: Add RED tests**

Add these tests to the same `describe`:

```ts
it( 'shows the activate-express-checkout notice when no previewable express checkouts are enabled', async () => {
	mockUseWooPayEnabledSettings.mockReturnValue( [ false, noop ] );
	mockUsePaymentRequestEnabledSettings.mockReturnValue( [ false, noop ] );

	render( <WooPaymentsExpressCheckoutSettings methodId="payment_request" /> );

	expect(
		await screen.findByText( 'To preview the express checkout buttons, activate at least one express checkout.' )
	).toBeInTheDocument();
} );

it( 'renders a WooPay button preview and HTTP requirements notice without initializing Stripe', async () => {
	setWindowProtocol( 'http:' );
	installStripeMock();

	render( <WooPaymentsExpressCheckoutSettings methodId="payment_request" /> );

	expect( await screen.findByRole( 'button', { name: /WooPay express checkout preview/i } ) ).toBeInTheDocument();
	expect(
		screen.getByText( /To preview the express checkout buttons, ensure your store uses HTTPS/ )
	).toBeInTheDocument();
	expect( mockStripe ).not.toHaveBeenCalled();
} );

it( 'mounts the live Stripe Express Checkout preview with native checkout loader behavior on HTTPS', async () => {
	setWindowProtocol( 'https:' );
	installStripeMock();

	render( <WooPaymentsExpressCheckoutSettings methodId="payment_request" /> );

	await waitFor( () => expect( mockExpressCheckoutMount ).toHaveBeenCalled() );
	expect( mockStripe ).toHaveBeenCalledWith( 'pk_test_native', {
		locale: 'en-us',
		stripeAccount: 'acct_native_test',
	} );
	expect( mockStripeElements ).toHaveBeenCalledWith(
		expect.objectContaining( {
			mode: 'payment',
			amount: 1000,
			currency: 'usd',
			loader: 'never',
			appearance: {
				variables: {
					borderRadius: '4px',
					spacingUnit: '6px',
				},
			},
		} )
	);
	expect( mockElementsCreate ).toHaveBeenCalledWith(
		'expressCheckout',
		expect.objectContaining( {
			buttonHeight: 40,
			buttonTheme: {
				applePay: 'black',
				googlePay: 'black',
			},
			buttonType: {
				applePay: 'plain',
				googlePay: 'plain',
			},
			layout: { overflow: 'never' },
			paymentMethods: expect.objectContaining( {
				applePay: 'always',
				googlePay: 'always',
				amazonPay: 'never',
				link: 'never',
				paypal: 'never',
				klarna: 'never',
			} ),
		} )
	);
} );

it( 'shows the failed-preview notice when Stripe reports no available wallets', async () => {
	setWindowProtocol( 'https:' );
	installStripeMock();
	mockExpressCheckoutOn.mockImplementation( ( eventName, callback ) => {
		if ( eventName === 'ready' ) {
			callback( { availablePaymentMethods: null } );
		}
	} );

	render( <WooPaymentsExpressCheckoutSettings methodId="payment_request" /> );

	expect(
		await screen.findByText( /Failed to preview the Apple Pay or Google Pay button/ )
	).toBeInTheDocument();
} );
```

- [x] **Step 3: Run RED**

Run:

```bash
cd plugins/woocommerce/client/admin && pnpm test:js -- express-checkout-settings.test.tsx --runInBand
```

Expected: FAIL because the preview is static and there is no `express_checkout_preview` bootstrap fixture.

## Task 3: Frontend Preview Implementation

- [x] **Step 1: Add settings preview config to test defaults**

In `setHookDefaults()`, add `express_checkout_preview` to `mockUseGetSettings.mockReturnValue()`:

```ts
express_checkout_preview: {
	stripe: {
		publishableKey: 'pk_test_native',
		accountId: 'acct_native_test',
		locale: 'en-us',
	},
},
```

- [x] **Step 2: Create `express-checkout-preview.tsx`**

Implement a focused component with these exported pieces:

```ts
export const ExpressCheckoutPreview = () => { ... };
```

The component should read `useGetSettings()`, `usePaymentRequestButtonType()`, `usePaymentRequestButtonSize()`, `usePaymentRequestButtonTheme()`, `usePaymentRequestButtonBorderRadius()`, `useWooPayEnabledSettings()`, and `usePaymentRequestEnabledSettings()`.

It should:

- Render the activate-express-checkout info notice when both enabled flags are false.
- Render a settings-local WooPay button preview when WooPay is enabled.
- Render the requirements notice for Apple Pay / Google Pay when payment request is enabled but the page is not HTTPS, the config is incomplete, or `window.Stripe` is unavailable.
- On HTTPS with config and `window.Stripe`, create `stripe.elements()` with `mode`, `amount`, `currency`, `loader`, and `appearance` options, then mount an `expressCheckout` element into a ref container.
- Listen to `ready` and `loaderror`; if ready has no `availablePaymentMethods`, render the failed-preview error notice.
- Clean up with `unmount()` when settings change or the component unmounts.

- [x] **Step 3: Switch `notices.tsx` to the new preview**

Remove the static `ExpressCheckoutPreview` export from `notices.tsx`, import `ExpressCheckoutPreview` from `./express-checkout-preview` in `appearance-settings.tsx`, and keep `ExpressCheckoutSettingsNotices` in `notices.tsx`.

- [x] **Step 4: Add scoped styles**

If needed, add classes in `style.scss`:

```scss
&__preview-stack {
	display: flex;
	flex-direction: column;
	gap: 12px;
}

&__woopay-button-preview {
	display: inline-flex;
	align-items: center;
	justify-content: center;
	width: 100%;
	min-height: 40px;
	border: 0;
	font-weight: 600;
}

&__stripe-preview {
	width: 100%;
}
```

- [x] **Step 5: Run GREEN**

Run the focused Jest command again. Expected: PASS.

## Task 4: Verification, Browser Proof, Review, Changelog

- [x] **Step 1: Run focused PHP**

Run:

```bash
pnpm --filter=@woocommerce/plugin-woocommerce test:php:env -- --filter WooPaymentsSettingsServiceTest
```

- [x] **Step 2: Run focused admin Jest**

Run:

```bash
cd plugins/woocommerce/client/admin && pnpm test:js -- express-checkout-settings.test.tsx --runInBand
```

- [x] **Step 3: Run targeted static checks**

Run exact-file checks for changed PHP/TS/SCSS, including PHP syntax, changed-file PHPCS, admin ESLint/TypeScript, and targeted Stylelint. Exclude `.agents`.

- [x] **Step 4: Run browser/log proof**

Use Playwriter against `http://store8889.localhost:8889/wp-admin/admin.php?page=wc-settings&tab=checkout&path=%2Fwoopayments%2Fsettings%2Fexpress-checkout%2Fpayment_request`. Capture evidence under `.agents/scratchpad/sessions/2026-06-15-core-native-payments/data/`, verify the Preview section renders deterministic HTTP fallback behavior without console errors, and check target `debug.log`/WooCommerce logs for new warnings.

- [x] **Step 5: Dispatch review agents**

Use focused architecture/reliability/a11y or code review subagents on the final diff. Fix source-backed findings before closeout.

- [x] **Step 6: Add changelog and branch gates**

Add a WooCommerce Core changelog entry, then run changelog validation, `git diff --check -- . ':!.agents'`, and branch lint gates that exclude scratchpad docs.

- [x] **Step 7: Commit only this slice**

Commit one logical product change and one changelog commit if all gates pass. Do not push. Update `staging-log.md`, `implementation-log.md`, `spec-conformance-baseline.md`, and the session README with status, evidence, and the git range.

## Closeout

A4aj is implemented, review-fixed, browser/log verified, and committed locally as product/tests `240b80c7dd` (`fix(payments): restore express checkout preview`) plus changelog `becafbb2cd` (`chore(payments): add express checkout preview changelog`). Git range: `761690b187...becafbb2cd`.

Final gates passed: focused PHP `WooPaymentsSettingsServiceTest` with 28 tests and 240 assertions, focused admin Jest `express-checkout-settings.test.tsx` with 22 tests, exact-file ESLint, admin `lint:lang:types`, targeted Stylelint, PHP syntax, production PHPStan for `WooPaymentsSettingsService.php`, changed-file PHPCS, admin bundle build, filtered A4 Playwriter browser gate for target `settings-express-payment-request` desktop/mobile, focused target preview captures, clean target PHP/debug-log scan aside from background WooCommerce Subscriptions debug entries, changelog validation with existing PHP 8.4 vendor deprecation noise, `git diff --check -- . ':!.agents'`, and branch `lint:changes:branch` with the known broad ignored-file JS warnings plus PHP clean.

Review fixes closed the stale failed Stripe.js script retry path and the WooPay preview accessible-name mismatch. The live HTTPS Stripe preview remains source/Jest-covered rather than wallet-availability browser-proven because the target local store is HTTP and wallet availability is not deterministic.

## Deferred

- Payment-detail timeline/payment-method detail parity from Sagan's explorer report.
- Legacy checkout card Elements appearance/fonts parity.
- True wallet-available live preview browser assertion, because the local target is HTTP and wallet availability is not deterministic.
- Final A4/N12 accumulated exit gate.
- After A5c is fully done, reopen A4 per the N12 advisory.
