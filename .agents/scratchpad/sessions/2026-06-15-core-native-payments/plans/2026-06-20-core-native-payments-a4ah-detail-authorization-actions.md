---
session: 2026-06-15-core-native-payments
type: plan
by: codex
created: 2026-06-20 05:28
reconciles:
  - ../analysis-a4ah-detail-authorization-actions.md
  - ../supervisor-prompt-2026-06-18-2344-N12.md
status: draft
---

# A4ah Detail Authorization Actions Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use $subagent-driven-development (recommended) or $executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Restore native WooPayments payment-detail capture and fraud-review approve/block actions using the existing guarded native authorization endpoints.

**Architecture:** Keep the money-moving safety boundary in `WooPaymentsAuthorizationsRestController` and make the admin detail page a thin consumer of the existing order-scoped authorization APIs. The transaction detail component will load authorization state only for eligible uncaptured details, render reference-shaped actions, and reload details/timeline/authorization after successful actions. Refund modal and express preview remain separate slices because they require different backend/browser contracts.

**Tech Stack:** React/TypeScript, WordPress components/notices, WooCommerce admin Jest/RTL tests, existing native WooPayments REST helpers.

---

## File Structure

- Modify `plugins/woocommerce/client/admin/client/woopayments/admin/test/money-movement-pages.test.tsx`: add RED coverage for detail capture, fraud approve, fraud block, pending action state, error notices, and post-action reload.
- Modify `plugins/woocommerce/client/admin/client/woopayments/admin/money-movement/transaction-details-page.tsx`: import the existing authorization helpers, load authorization state for eligible details, render actions, and refresh on success.
- Modify `plugins/woocommerce/client/admin/client/woopayments/admin/style.scss`: add a small action/notice layout for detail authorization controls if existing dispute/action classes do not fit cleanly.
- No PHP product changes are expected. Existing backend authorization tests are regression gates.

## Task 1: RED Detail Authorization Tests

**Files:**
- Modify: `plugins/woocommerce/client/admin/client/woopayments/admin/test/money-movement-pages.test.tsx`

- [ ] **Step 1: Add the mocked authorization read helper**

Extend the data import, mock module, typed mock constants, and `beforeEach()` reset:

```tsx
import {
	getWooPaymentsAuthorizations,
	getWooPaymentsAuthorization,
	getWooPaymentsAuthorizationsSummary,
	// existing imports...
} from '../money-movement/data';

jest.mock( '../money-movement/data', () => ( {
	// existing mocks...
	getWooPaymentsAuthorization: jest.fn(),
} ) );

const mockGetAuthorization =
	getWooPaymentsAuthorization as jest.MockedFunction<
		typeof getWooPaymentsAuthorization
	>;

beforeEach( () => {
	// existing resets...
	mockGetAuthorization.mockReset();
} );
```

- [ ] **Step 2: Add RED tests for normal capture and fraud approve/block**

Append focused tests near the existing transaction-detail tests:

```tsx
it( 'captures an uncaptured authorization from transaction details and reloads the detail data', async () => {
	mockGetPaymentIntent
		.mockResolvedValueOnce( {
			id: 'pi_auth',
			status: 'requires_capture',
			amount: 5000,
			currency: 'usd',
			created: 1781712000,
			charge: {
				id: 'ch_auth',
				balance_transaction: 'txn_auth',
				type: 'charge',
				amount: 5000,
				currency: 'usd',
				created: 1781712000,
				payment_intent: 'pi_auth',
				captured: false,
				amount_refunded: 0,
				order: { id: 123, number: '123' },
			},
		} )
		.mockResolvedValueOnce( {
			id: 'pi_auth',
			status: 'succeeded',
			charge: {
				id: 'ch_auth',
				balance_transaction: 'txn_auth',
				type: 'charge',
				amount: 5000,
				currency: 'usd',
				created: 1781712000,
				payment_intent: 'pi_auth',
				captured: true,
				amount_refunded: 0,
				order: { id: 123, number: '123' },
			},
		} );
	mockGetAuthorization.mockResolvedValue( {
		payment_intent_id: 'pi_auth',
		order_id: 123,
		captured: false,
		created: '2026-06-12T10:30:00Z',
	} );
	mockGetTimeline.mockResolvedValue( { data: [] } );
	mockCaptureAuthorization.mockResolvedValue( {
		id: 'pi_auth',
		status: 'succeeded',
	} );

	render(
		<MemoryRouter
			initialEntries={ [
				'/woopayments/transactions/details?id=pi_auth&transaction_id=txn_auth',
			] }
		>
			<WooPaymentsTransactionDetailsPage />
		</MemoryRouter>
	);

	await userEvent.click(
		await screen.findByRole( 'button', {
			name: 'Capture authorization for order #123',
		} )
	);

	await waitFor( () =>
		expect( mockCaptureAuthorization ).toHaveBeenCalledWith(
			123,
			'pi_auth'
		)
	);
	expect( mockCreateSuccessNotice ).toHaveBeenCalledWith(
		'Payment for order #123 captured successfully.'
	);
	expect( mockGetPaymentIntent ).toHaveBeenCalledTimes( 2 );
	expect(
		screen.queryByRole( 'button', {
			name: 'Capture authorization for order #123',
		} )
	).not.toBeInTheDocument();
} );

it( 'approves a fraud-review transaction from transaction details', async () => {
	mockGetPaymentIntent.mockResolvedValue( {
		id: 'pi_review',
		status: 'requires_capture',
		amount: 5000,
		currency: 'usd',
		created: 1781712000,
		charge: {
			id: 'ch_review',
			balance_transaction: 'txn_review',
			type: 'charge',
			amount: 5000,
			currency: 'usd',
			created: 1781712000,
			payment_intent: 'pi_review',
			captured: false,
			amount_refunded: 0,
			order: {
				id: 123,
				number: '123',
				fraud_meta_box_type: 'review',
			},
		},
	} );
	mockGetAuthorization.mockResolvedValue( {
		payment_intent_id: 'pi_review',
		order_id: 123,
		captured: false,
	} );
	mockGetTimeline.mockResolvedValue( { data: [] } );
	mockCaptureAuthorization.mockResolvedValue( {
		id: 'pi_review',
		status: 'succeeded',
	} );

	render(
		<MemoryRouter
			initialEntries={ [
				'/woopayments/transactions/details?id=pi_review&transaction_id=txn_review',
			] }
		>
			<WooPaymentsTransactionDetailsPage />
		</MemoryRouter>
	);

	expect(
		await screen.findByRole( 'button', { name: 'Block transaction' } )
	).toBeInTheDocument();
	await userEvent.click(
		screen.getByRole( 'button', { name: 'Approve transaction' } )
	);

	await waitFor( () =>
		expect( mockCaptureAuthorization ).toHaveBeenCalledWith(
			123,
			'pi_review'
		)
	);
	expect( mockCreateSuccessNotice ).toHaveBeenCalledWith(
		'Payment for order #123 captured successfully.'
	);
} );

it( 'blocks a fraud-review transaction from transaction details', async () => {
	mockGetPaymentIntent.mockResolvedValue( {
		id: 'pi_review',
		status: 'requires_capture',
		charge: {
			id: 'ch_review',
			balance_transaction: 'txn_review',
			type: 'charge',
			amount: 5000,
			currency: 'usd',
			created: 1781712000,
			payment_intent: 'pi_review',
			captured: false,
			amount_refunded: 0,
			order: {
				id: 123,
				number: '123',
				fraud_meta_box_type: 'review',
			},
		},
	} );
	mockGetAuthorization.mockResolvedValue( {
		payment_intent_id: 'pi_review',
		order_id: 123,
		captured: false,
	} );
	mockGetTimeline.mockResolvedValue( { data: [] } );
	mockCancelAuthorization.mockResolvedValue( {
		id: 'pi_review',
		status: 'canceled',
	} );

	render(
		<MemoryRouter
			initialEntries={ [
				'/woopayments/transactions/details?id=pi_review&transaction_id=txn_review',
			] }
		>
			<WooPaymentsTransactionDetailsPage />
		</MemoryRouter>
	);

	await userEvent.click(
		await screen.findByRole( 'button', { name: 'Block transaction' } )
	);

	await waitFor( () =>
		expect( mockCancelAuthorization ).toHaveBeenCalledWith(
			123,
			'pi_review'
		)
	);
	expect( mockCreateSuccessNotice ).toHaveBeenCalledWith(
		'Payment for order #123 canceled successfully.'
	);
} );
```

- [ ] **Step 3: Add RED tests for pending and error states**

Use a deferred capture promise to assert the button accessible name changes to `Capturing authorization for order #123` and the action is disabled. Add one rejected cancel/capture case expecting `mockCreateErrorNotice` with `Unable to capture authorization for order #123. Authorization already captured.`.

- [ ] **Step 4: Run RED Jest**

Run:

```bash
pnpm --filter=@woocommerce/admin-library test:js --runTestsByPath client/woopayments/admin/test/money-movement-pages.test.tsx --runInBand
```

Expected: FAIL because the detail page does not call `getWooPaymentsAuthorization()` and does not render detail authorization action buttons.

## Task 2: Implement Detail Authorization Actions

**Files:**
- Modify: `plugins/woocommerce/client/admin/client/woopayments/admin/money-movement/transaction-details-page.tsx`
- Modify if needed: `plugins/woocommerce/client/admin/client/woopayments/admin/style.scss`

- [ ] **Step 1: Add imports and local action types**

Import `Button` from `@wordpress/components`, `dispatch` from `@wordpress/data`, and `useCallback` from `@wordpress/element`. Import `getWooPaymentsAuthorization`, `captureWooPaymentsAuthorization`, and `cancelWooPaymentsAuthorization` from `./data`, and `WooPaymentsAuthorization` from `./types`. Add local `AuthorizationAction`, `PendingAuthorizationAction`, and `NoticeDispatch` types matching the list page.

- [ ] **Step 2: Extract reloadable detail loading**

Move the current `loadTransaction` body into a `useCallback` that accepts `{ setLoading?: boolean }`. Keep the current `isMounted` guard in `useEffect`, but make action handlers call the same loader after success with `setLoading: false` so the detail UI refreshes without returning to the full loading state.

- [ ] **Step 3: Load authorization state only for eligible details**

After loading the normalized transaction and timeline, if `paymentIntentId` exists, `captured !== true`, `amount_refunded` is missing or zero, and the status is compatible with `requires_capture` or the transaction is uncaptured, call `getWooPaymentsAuthorization( paymentIntentId )`. Store it in `authorization` state only when it is not captured. Clear it otherwise.

- [ ] **Step 4: Add fraud-review and order helpers**

Add helpers:

```tsx
const isFraudReviewTransaction = ( transaction: WooPaymentsTransaction ) =>
	transaction.status === 'requires_capture' &&
	transaction.order?.fraud_meta_box_type === 'review';

const getTransactionOrderId = ( transaction: WooPaymentsTransaction ) => {
	const orderId = transaction.order?.id;
	return Number.isFinite( Number( orderId ) ) ? Number( orderId ) : 0;
};
```

Use the loaded authorization `order_id` as a fallback. If either order ID or PaymentIntent ID is missing, render no action buttons and create an error notice if an already-rendered button path somehow reaches the handler.

- [ ] **Step 5: Add action handler**

Implement `handleAuthorizationAction( action )` equivalent to the list page: set pending state, call capture/cancel helper, reload data and timeline, clear pending state, and create success notices `Payment for order #%s captured successfully.` or `Payment for order #%s canceled successfully.`. On error, clear pending state and create `Unable to %1$s authorization for order #%2$s. %3$s`.

- [ ] **Step 6: Render reference-shaped actions**

Render fraud-review actions immediately above the details `<dl>`:

```tsx
<div className="woocommerce-woopayments-money-movement__authorization-actions">
	<Button variant="secondary" isDestructive ...>Block transaction</Button>
	<Button variant="primary" ...>Approve transaction</Button>
</div>
```

Render the normal authorization notice below disputes and before timeline:

```tsx
<section className="woocommerce-woopayments-overview-card woocommerce-woopayments-money-movement__authorization-notice">
	<p>You must capture this charge within the next 7 days.</p>
	<Button variant="primary" ...>Capture</Button>
</section>
```

Use `aria-label` values that include the order number, matching list-level button semantics. Use sentence case for visible copy: `Approve transaction`, not the reference plugin's title-case `Approve Transaction`.

- [ ] **Step 7: Add minimal SCSS if needed**

Use existing card/action patterns:

```scss
.woocommerce-woopayments-money-movement__authorization-actions,
.woocommerce-woopayments-money-movement__authorization-notice {
	align-items: center;
	display: flex;
	flex-wrap: wrap;
	gap: 12px;
	margin-bottom: 16px;
}

.woocommerce-woopayments-money-movement__authorization-notice {
	justify-content: space-between;
}
```

Keep mobile behavior aligned with existing action stacks if needed.

- [ ] **Step 8: Run GREEN Jest**

Run the same focused Jest command. Expected: PASS including the new RED tests and existing transaction/dispute detail tests.

## Task 3: Verification and Review Gates

**Files:**
- No planned product edits unless gates find issues.

- [ ] **Step 1: Run backend regression gate**

Run the existing authorization/money movement backend suite:

```bash
pnpm --filter=@woocommerce/plugin-woocommerce test:php:env -- --filter WooPaymentsMoneyMovementRestControllerTest
```

Expected: PASS. This is the money-safety backend proof for the existing capture/cancel endpoints.

- [ ] **Step 2: Run static/frontend gates**

Run:

```bash
pnpm --filter=@woocommerce/admin-library lint:lang:js -- client/woopayments/admin/money-movement/transaction-details-page.tsx client/woopayments/admin/test/money-movement-pages.test.tsx
pnpm --filter=@woocommerce/admin-library lint:lang:types
pnpm --filter=@woocommerce/admin-library lint:lang:css -- client/woopayments/admin/style.scss
pnpm --filter=@woocommerce/admin-library build:project:bundle
git diff --check -- . ':!.agents'
```

Expected: PASS, with only already-known unrelated build cache warnings if they reappear.

- [ ] **Step 3: Run review agents**

Dispatch focused code/a11y/API/reliability review over the changed detail-page files and test coverage. Fix source-backed findings before browser proof.

- [ ] **Step 4: Browser and log proof**

Use Playwriter on the target store to load a native transaction detail route and verify no detail-page regressions, no failed browser responses, and no relevant console errors. If a deterministic uncaptured local authorization is available, exercise open/read-only observation of the actions without mutating irreversible state; otherwise record why mutation proof is covered by Jest/backend tests only. Clear and inspect `wp-content/debug.log` and relevant Docker logs for fresh PHP notices/warnings/fatals.

- [ ] **Step 5: Changelog, docs, and commit**

Add a WooCommerce changelog entry for the merchant-facing admin parity fix. Update `analysis-a4ah-detail-authorization-actions.md`, `implementation-log.md`, `staging-log.md`, `spec-conformance-baseline.md`, and `README.md` with evidence and residuals. Run changelog validation, branch lint excluding scratchpad docs, commit source/tests and changelog as separate logical commits if all gates are green, and do not push.
