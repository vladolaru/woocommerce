---
session: 2026-06-15-core-native-payments
type: plan
by: codex
created: 2026-06-21 01:06
target: A4bb General settings controls parity
reconciles:
  - analysis-a4bb-general-controls-parity.md
  - review-a4bb-general-controls-source-check.md
  - review-a4ay-settings-general-explorer.md
  - review-a4au-settings-inventory.md
  - supervisor-prompt-2026-06-18-2344-N12.md
status: complete
---

# A4bb General Settings Controls Parity Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use $subagent-driven-development (recommended) or $executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Restore native WooPayments General/account-mode controls parity with the reference store for provider enablement, test mode, switch-to-live flows, and adjacent payment-request save telemetry.

**Architecture:** Keep the fix local to the native WooPayments settings surface. `settings-page.tsx` owns the General controls and save telemetry; `account-mode-notice.tsx` owns test/sandbox account notices and setup-live modal behavior. Do not refactor the broader WooCommerce Payments settings providers list or import the extension-only modal illustration stack.

**Tech Stack:** React/TypeScript, WordPress components, WooCommerce Tracks, Jest/React Testing Library, Playwriter, WooCommerce admin bundle.

---

## Files

- Modify `plugins/woocommerce/client/admin/client/woopayments/settings/settings-page.tsx` for `recordEvent`, the disable confirmation modal, reference test-mode help/Tracks behavior, and optional payment-request save telemetry.
- Modify `plugins/woocommerce/client/admin/client/woopayments/settings/account-mode-notice.tsx` for setup-live Tracks events and busy navigation behavior.
- Modify `plugins/woocommerce/client/admin/client/woopayments/settings/style.scss` only if the provider-disable modal needs native layout support for the affected payment-method list.
- Modify `plugins/woocommerce/client/admin/client/woopayments/settings/test/settings-page.test.tsx` for RED/GREEN coverage of all parity contracts.
- Add `plugins/woocommerce/changelog/fix-native-payments-a4bb-general-controls-parity` after focused GREEN.

## Task 1: General Controls RED Coverage

- [ ] **Step 1: Add WooPayments gateway toggle tests**

Add focused tests near the existing General-section tests in `settings-page.test.tsx`:

```tsx
it( 'records Tracks when enabling WooPayments', async () => {
	const setIsWCPayEnabled = jest.fn();
	mockUseIsWCPayEnabled.mockReturnValue( [ false, setIsWCPayEnabled ] );

	render( <WooPaymentsSettingsPage /> );

	await userEvent.click(
		screen.getByRole( 'checkbox', { name: 'Enable WooPayments' } )
	);

	expect( setIsWCPayEnabled ).toHaveBeenCalledWith( true );
	expect( mockRecordEvent ).toHaveBeenCalledWith( 'wcpay_gateway_toggle', {
		action: 'enable',
		context: 'wcpay-settings',
	} );
} );

it( 'requires confirmation before disabling WooPayments and records Tracks after confirm', async () => {
	const setIsWCPayEnabled = jest.fn();
	mockUseIsWCPayEnabled.mockReturnValue( [ true, setIsWCPayEnabled ] );

	render( <WooPaymentsSettingsPage /> );

	await userEvent.click(
		screen.getByRole( 'checkbox', { name: 'Enable WooPayments' } )
	);

	expect( setIsWCPayEnabled ).not.toHaveBeenCalled();
	expect(
		screen.getByRole( 'dialog', { name: 'Disable WooPayments' } )
	).toBeInTheDocument();

	await userEvent.click( screen.getByRole( 'button', { name: 'Disable' } ) );

	expect( setIsWCPayEnabled ).toHaveBeenCalledWith( false );
	expect( mockRecordEvent ).toHaveBeenCalledWith( 'wcpay_gateway_toggle', {
		action: 'disable',
		context: 'wcpay-settings',
	} );
} );
```

- [ ] **Step 2: Add test-mode Tracks/help tests**

Add tests proving non-dev help links, dev-mode help links, enable confirm event, modal-exit event, and disable event:

```tsx
it( 'renders reference test-mode help links outside development mode', () => {
	render( <WooPaymentsSettingsPage /> );

	expect(
		screen.getByRole( 'link', { name: 'test card numbers' } )
	).toHaveAttribute(
		'href',
		'https://woocommerce.com/document/woopayments/testing-and-troubleshooting/testing/#test-cards'
	);
	expect( screen.getByRole( 'link', { name: 'Learn more' } ) ).toHaveAttribute(
		'href',
		'https://woocommerce.com/document/woopayments/testing-and-troubleshooting/testing/'
	);
} );

it( 'records Tracks when enabling, canceling, and disabling test mode', async () => {
	const setTestMode = jest.fn();
	mockUseTestMode.mockReturnValue( [ true, setTestMode ] );

	render( <WooPaymentsSettingsPage /> );

	await userEvent.click(
		screen.getByRole( 'checkbox', { name: 'Enable test mode' } )
	);

	expect( setTestMode ).toHaveBeenCalledWith( false );
	expect( mockRecordEvent ).toHaveBeenCalledWith( 'wcpay_test_mode_disabled', {
		source: 'wcadmin-settings-page',
	} );
} );
```

Keep the existing confirmation test and extend it to assert `wcpay_test_mode_enabled` on primary confirm and `wcpay_test_mode_modal_exit` on Cancel or modal close.

- [ ] **Step 3: Add switch-to-live Tracks/busy tests**

Extend the existing switch-to-live tests:

```tsx
expect( mockRecordEvent ).toHaveBeenCalledWith(
	'wcpay_setup_live_payments_modal_open',
	{
		from: 'WCPAY_SETTINGS',
		source: 'wcadmin-settings-page',
	}
);

await userEvent.click(
	within( dialog ).getByRole( 'button', { name: 'Activate payments' } )
);

expect( mockRecordEvent ).toHaveBeenCalledWith(
	'wcpay_onboarding_flow_setup_live_payments',
	{
		from: 'WCPAY_SETTINGS',
		source: 'wcadmin-settings-page',
	}
);
expect(
	within( dialog ).getByRole( 'button', { name: 'Activate payments' } )
).toBeDisabled();
```

Add an event-bridge assertion for `wcpay_settings_setup_live_payments_click` and a close assertion for `wcpay_setup_live_payments_modal_exit`.

- [ ] **Step 4: Add payment-request save telemetry test if scoped implementation remains local**

Add one save-bar test if the implementation only touches `SaveSettingsSection`:

```tsx
it( 'records payment-request settings changes after a successful save', async () => {
	mockUseGetSettings.mockReturnValue( {
		...mockUseGetSettings(),
		is_payment_request_enabled: false,
	} );
	mockSaveSettings.mockResolvedValue( true );

	render( <WooPaymentsSettingsPage /> );

	await userEvent.click( screen.getByRole( 'button', { name: 'Save changes' } ) );

	expect( mockRecordEvent ).toHaveBeenCalledWith(
		'wcpay_payment_request_settings_change',
		{ enabled: 'no' }
	);
} );
```

If this test needs broad store mocking to become meaningful, skip the telemetry in A4bb and record it as a follow-up rather than weakening the test.

- [ ] **Step 5: Run RED**

Run:

```bash
pnpm --filter='@woocommerce/plugin-woocommerce' exec jest --config client/admin/client/jest.config.js --runTestsByPath client/woopayments/settings/test/settings-page.test.tsx --runInBand
```

Expected: new tests fail on missing Tracks events, missing disable confirmation, missing help links, and setup-live button behavior.

## Task 2: Native General Controls Implementation

- [ ] **Step 1: Import Tracks and add constants**

In `settings-page.tsx`, import `recordEvent` and add constants for reference documentation URLs near the existing `MANUAL_CAPTURE_DOC_URL`:

```tsx
import { recordEvent } from '@woocommerce/tracks';

const TESTING_DOC_URL =
	'https://woocommerce.com/document/woopayments/testing-and-troubleshooting/testing/';
const TEST_CARDS_DOC_URL =
	'https://woocommerce.com/document/woopayments/testing-and-troubleshooting/testing/#test-cards';
const WORDPRESS_ENVIRONMENT_URL =
	'https://make.wordpress.org/core/2020/08/27/wordpress-environment-types/';
const WOOPAYMENTS_DOC_URL = 'https://woocommerce.com/document/woopayments/';
const WOOCOMMERCE_SUPPORT_URL =
	'https://woocommerce.com/my-account/create-a-ticket/?select=5278104';
```

- [ ] **Step 2: Add native disable confirmation modal**

Add a local `WooPaymentsDisableConfirmationModal` above `GeneralSettingsSection`. It should title the modal `Disable WooPayments`, render the reference warning/help copy, list affected methods from the native hooks, confirm with a destructive `Disable` button, and close with `Cancel`.

Use native data only:

```tsx
const enabledAffectedMethods = enabledMethodIds
	.filter( ( methodId ) => methodId !== 'link' )
	.map( ( methodId ) => getPaymentMethodDefinition( methodId, accountCountry ) )
	.filter( Boolean );
```

Also include `Apple Pay / Google Pay` when payment request is enabled, `Amazon Pay` when enabled and feature-available, `Link by Stripe` when selected, and `WooPay` when enabled. Keep fallback initials/icons acceptable; do not import extension-only illustration assets.

- [ ] **Step 3: Wire gateway toggle behavior**

In `GeneralSettingsSection`, replace direct `setIsWCPayEnabled( Boolean( value ) )` with:

```tsx
const handleWooPaymentsEnabledChange = ( value: boolean ) => {
	if ( ! value ) {
		setDisableConfirmationVisible( true );
		return;
	}

	setIsWCPayEnabled( true );
	recordEvent( 'wcpay_gateway_toggle', {
		action: 'enable',
		context: 'wcpay-settings',
	} );
};
```

On confirmation, call `setIsWCPayEnabled( false )`, record the same event with `action: 'disable'`, and close the modal.

- [ ] **Step 4: Wire test-mode help and Tracks behavior**

Replace the simplified help copy with `createInterpolateElement` link content. On non-dev enable, only show the confirmation modal. On disable, record `wcpay_test_mode_disabled` before setting false. On modal close, record `wcpay_test_mode_modal_exit`. On confirm, record `wcpay_test_mode_enabled`, set true, and close.

- [ ] **Step 5: Add local payment-request save telemetry only if meaningful**

In `SaveSettingsSection`, track the first observed `is_payment_request_enabled` value in state, record `wcpay_payment_request_settings_change` only after a successful save and only when the current value differs from the tracked initial value, then update the tracked value.

## Task 3: Account-Mode Notice Implementation

- [ ] **Step 1: Import Tracks and add constants**

In `account-mode-notice.tsx`, import `recordEvent` from `@woocommerce/tracks` and define:

```tsx
const SETUP_LIVE_FROM = 'WCPAY_SETTINGS';
const SETUP_LIVE_SOURCE = 'wcadmin-settings-page';
```

- [ ] **Step 2: Track modal opens**

When handling `wcpay:activate_payments`, record `wcpay_settings_setup_live_payments_click` with `{ source: SETUP_LIVE_SOURCE }` before opening the modal. When the visible notice CTA is clicked, record `wcpay_setup_live_payments_modal_open` with `{ from: SETUP_LIVE_FROM, source: SETUP_LIVE_SOURCE }` and then dispatch/open the same modal path.

- [ ] **Step 3: Add modal busy/submit/exit behavior**

Change `SetupLivePaymentsModal` primary action from an `href` link to a button handler. On click, set submitted state, record `wcpay_onboarding_flow_setup_live_payments` with `{ from, source }`, and navigate to the existing setup URL. On close, clear submitted state, record `wcpay_setup_live_payments_modal_exit`, and call `onClose`.

## Task 4: Focused GREEN And Static Gates

- [ ] **Step 1: Run focused Jest**

Run:

```bash
pnpm --filter='@woocommerce/plugin-woocommerce' exec jest --config client/admin/client/jest.config.js --runTestsByPath client/woopayments/settings/test/settings-page.test.tsx --runInBand
```

Expected: the focused settings-page suite passes.

- [ ] **Step 2: Run exact-file frontend static checks**

Run:

```bash
pnpm --filter='@woocommerce/plugin-woocommerce' exec eslint client/admin/client/woopayments/settings/settings-page.tsx client/admin/client/woopayments/settings/account-mode-notice.tsx client/admin/client/woopayments/settings/test/settings-page.test.tsx
pnpm --filter='@woocommerce/plugin-woocommerce' exec stylelint client/admin/client/woopayments/settings/style.scss
pnpm --filter='@woocommerce/admin-library' lint:lang:types
```

Expected: all pass. If `style.scss` is untouched, skip stylelint and record that explicitly.

- [ ] **Step 3: Build the admin bundle**

Run from `plugins/woocommerce/client/admin`:

```bash
pnpm build:project:bundle
```

Expected: build passes with only known webpack cache serialization warnings.

## Task 5: Browser, Review, Commit

- [ ] **Step 1: Add changelog**

Create `plugins/woocommerce/changelog/fix-native-payments-a4bb-general-controls-parity` with a user-facing fix summary for restored native WooPayments settings controls parity.

- [ ] **Step 2: Run Playwriter target proof**

Use Playwriter against `http://store8889.localhost:8889/wp-admin/admin.php?page=wc-settings&tab=checkout&path=/woopayments/settings` to verify the General section renders, test-mode help links are present, disabling WooPayments opens the confirmation modal without changing state before confirm, the test-account switch-to-live modal opens, and browser console/network output has no unexpected errors. Record evidence under `data/a4bb-general-controls-parity/`.

- [ ] **Step 3: Scan target logs**

Clear or time-bound logs before the browser run when possible, then verify target `debug.log` and recent Docker logs have no PHP/WP notices, warnings, deprecations, fatals, uncaught exceptions, database errors, stack traces, or actual 5xx markers.

- [ ] **Step 4: Run review agents**

Dispatch focused reviewers after the product diff is stable: JS-test reviewer for `settings-page.test.tsx`, a11y reviewer for modal/help/control behavior, and code reviewer or architecture reviewer for the settings/control implementation. Record findings in the scratchpad before fixing.

- [ ] **Step 5: Run final gates and commit**

Run changelog validation, `git diff --check -- . ':!.agents'`, branch lint, and any reruns required by reviewer fixes. Commit product code and changelog as separate logical commits, report the git range, and do not push.

## Carry-Forward Tasks

- [ ] After fully completing the appropriate A5c-level checkpoints, reopen A4 per N12 until feature parity and gate coverage are genuinely complete.
- [ ] After the appropriate closeout, re-check the N8 supervisor advisory in `.agents/scratchpad/sessions/2026-06-15-core-native-payments/supervisor-prompt-2026-06-17-1311.md`.
