---
session: 2026-06-15-core-native-payments
type: plan
by: codex
created: 2026-06-20 07:38
reconciles:
  - ../analysis-a4ak-payment-detail-summary-composition-parity.md
  - ../analysis-a4x-money-detail-parity.md
  - ../analysis-a4ae-payment-detail-dispute-action-parity.md
  - ../analysis-a4ah-detail-authorization-actions.md
  - ../analysis-a4ai-refund-modal-parity.md
  - ../analysis-a4aj-express-checkout-preview-parity.md
  - ../supervisor-prompt-2026-06-18-2344-N12.md
status: final
last_updated: 2026-06-20 08:19
---

# A4ak Payment Detail Summary Composition Parity Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use $subagent-driven-development (recommended) or $executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Restore the native WooPayments transaction detail read-only summary composition so payment details expose the same merchant-facing structure, linked records, test-mode context, and payment-method detail cues as the reference detail surface while preserving existing refund, authorization, dispute, route, and timeline behavior.

**Architecture:** Keep the existing native detail routes and money-moving controls intact. Add a presentational detail-section layer beside `transaction-details-page.tsx`, widen native TypeScript types to the already-returned backend detail enrichment, extend the existing native test-mode notice to support payment detail pages, and wire the transaction details page to render summary, identifiers, related records, missing-order, payment-method, dispute, authorization, and timeline sections in a stable order. Do not add backend money-moving contracts in this slice.

**Tech Stack:** WooCommerce Core admin React/TypeScript, WordPress components, WooCommerce admin routing, Jest + React Testing Library, targeted SCSS, ignored local A4 harness, Playwriter browser verification.

---

## File Structure

- Modify `plugins/woocommerce/client/admin/client/woopayments/admin/money-movement/types.ts` to type detail payload fields already supplied by `WooPaymentsMoneyMovementOrderService`: order customer URLs, customer name/email, subscriptions, billing address, payment method IDs, card expiry/funding/checks/country/network, and optional metadata.
- Modify `plugins/woocommerce/client/admin/client/woopayments/admin/money-movement/utils.ts` to add detail-safe helpers: `formatDateTime()`, sales-channel labels, and maybe card/check labels if the helpers are shared by sections and tests.
- Create `plugins/woocommerce/client/admin/client/woopayments/admin/money-movement/transaction-detail-sections.tsx` for read-only presentational sections: summary, identifiers, related records, missing-order notice, and payment-method card.
- Modify `plugins/woocommerce/client/admin/client/woopayments/admin/money-movement/transaction-details-page.tsx` to import those sections, pass existing action nodes where needed, render the payment detail test-mode notice, and remove the old single flat `<dl>` from the main page.
- Modify `plugins/woocommerce/client/admin/client/woopayments/admin/test-mode-notice.tsx` to support `payments` and a detail-view copy path using the existing account settings endpoint and `getSettingsPaymentsProviderRouteUrl( '/woopayments/settings' )`.
- Modify `plugins/woocommerce/client/admin/client/woopayments/admin/style.scss` with scoped responsive styles for the new detail sections only.
- Modify `plugins/woocommerce/client/admin/client/woopayments/admin/test/money-movement-pages.test.tsx` for RED/GREEN coverage of the new composition and unchanged action behavior.
- Modify `plugins/woocommerce/client/admin/client/woopayments/admin/test/capital-page.test.tsx` only if the test-mode notice prop/type change requires a small assertion update for existing Capital behavior.
- Modify `tools/woopayments-merge/a4-admin-surface-gate.py` to add source tokens for the new detail composition without treating source tokens as the only proof.
- Add a WooCommerce Core changelog entry after the slice verifies.

## Task 1: Frontend RED Tests

- [x] **Step 1: Mock account settings for detail test-mode notice**

In `money-movement-pages.test.tsx`, import `getWooPaymentsAccountSettings` from `../../settings/api` and add a Jest mock near the existing money-movement data mock:

```ts
jest.mock( '../../settings/api', () => ( {
	getWooPaymentsAccountSettings: jest.fn(),
} ) );
```

Add a typed mock:

```ts
const mockGetAccountSettings =
	getWooPaymentsAccountSettings as jest.MockedFunction<
		typeof getWooPaymentsAccountSettings
	>;
```

In `beforeEach()`, set `window.wcSettings` to include both `adminUrl` and a minimal country map, reset the mock, and default it to a live account:

```ts
window.wcSettings = {
	adminUrl: 'http://example.com/wp-admin',
	countries: {
		US: 'United States',
	},
};
mockGetAccountSettings.mockReset();
mockGetAccountSettings.mockResolvedValue( {
	account: {
		id: 'acct_live',
		mode: 'live',
		default_currency: 'usd',
		connected: true,
		working: true,
		can_process_payments: true,
		test_mode: false,
		test_drive: false,
		sandbox: false,
		live: true,
	},
	urls: {},
} );
```

- [x] **Step 2: Add the summary composition failing test**

Replace or extend the existing `loads payment intent details when the route id is a payment intent` test with assertions that fail against the current flat `<dl>`. The fixture should include `order.customer_url`, `order.customer_name`, `order.customer_email`, one subscription, `payment_method: 'pm_card_visa'`, card expiry/funding/network/country/checks, `billing_details.formatted_address`, `amount_refunded`, `fee`, and `net`.

Expected assertions:

```ts
expect( await screen.findByRole( 'heading', { name: 'Payment details' } ) ).toBeInTheDocument();
expect( screen.getByRole( 'heading', { name: 'Summary' } ) ).toBeInTheDocument();
expect( screen.getByText( '$50.00' ) ).toBeInTheDocument();
expect( screen.getByText( 'Succeeded' ) ).toHaveClass( 'woocommerce-woopayments-money-movement__status-chip' );
expect( screen.getByText( 'Sales channel' ) ).toBeInTheDocument();
expect( screen.getByText( 'Online store' ) ).toBeInTheDocument();
expect( screen.getByRole( 'link', { name: 'Ada Lovelace' } ) ).toHaveAttribute( 'href', 'http://example.com/wp-admin/admin.php?page=wc-admin&path=/customers&filter=single_customer&customers=99' );
expect( screen.getByRole( 'link', { name: 'Order #123' } ) ).toHaveAttribute( 'href', 'http://example.com/wp-admin/admin.php?page=wc-orders&action=edit&id=123' );
expect( screen.getByRole( 'link', { name: 'Subscription #456' } ) ).toHaveAttribute( 'href', 'http://example.com/wp-admin/admin.php?page=wc-orders&action=edit&id=456' );
expect( screen.getByRole( 'heading', { name: 'Payment method' } ) ).toBeInTheDocument();
expect( screen.getByText( '•••• 4242' ) ).toBeInTheDocument();
expect( screen.getByText( '12 / 2030' ) ).toBeInTheDocument();
expect( screen.getByText( 'Visa credit card' ) ).toBeInTheDocument();
expect( screen.getByText( 'pm_card_visa' ) ).toBeInTheDocument();
expect( screen.getByText( 'United States' ) ).toBeInTheDocument();
expect( screen.getByText( 'Passed' ) ).toBeInTheDocument();
expect( screen.getByText( 'Identifiers' ) ).toBeInTheDocument();
expect( screen.getByText( 'pi_test' ) ).toBeInTheDocument();
expect( screen.getByText( 'ch_test' ) ).toBeInTheDocument();
expect( screen.getByText( 'txn_test' ) ).toBeInTheDocument();
expect( screen.getByText( 'Payment captured.' ) ).toBeInTheDocument();
```

- [x] **Step 3: Add missing-order failing test**

Extend `does not offer transaction detail refunds when the charge is not order-backed` or add a sibling test. Assert the page explains the missing order:

```ts
expect( await screen.findByText( 'This payment is not linked to a WooCommerce order.' ) ).toBeInTheDocument();
expect( screen.queryByRole( 'button', { name: 'Transaction actions' } ) ).not.toBeInTheDocument();
```

- [x] **Step 4: Add detail-view test-mode failing test**

Add a test where `mockGetAccountSettings` resolves a connected test account and assert the notice copy and settings link:

```ts
expect( await screen.findByText( 'Viewing test payments.' ) ).toBeInTheDocument();
expect( screen.getByText( /Your WooPayments account is currently in test mode./ ) ).toBeInTheDocument();
expect( screen.getByRole( 'link', { name: 'WooPayments settings' } ) ).toHaveAttribute( 'href', 'http://example.com/wp-admin/admin.php?page=wc-settings&tab=checkout&path=%2Fwoopayments%2Fsettings' );
```

- [x] **Step 5: Run RED**

Run:

```bash
pnpm --filter=@woocommerce/admin-library test:js -- money-movement-pages.test.tsx --runInBand
```

Expected: FAIL because `Summary`, linked related records, the payment-method detail card, missing-order copy, and payment detail test-mode notice are not implemented.

## Task 2: Types, Utilities, and Presentational Sections

- [x] **Step 1: Widen detail types without changing runtime contracts**

In `types.ts`, update the existing interfaces so TypeScript matches the current backend payload:

```ts
export interface WooPaymentsPaymentOrder {
	id?: number | string;
	number?: number | string;
	url?: string;
	customer_url?: string | null;
	customer_name?: string;
	customer_email?: string;
	fraud_meta_box_type?: string;
	ip_address?: string;
	suggested_product_type?: string;
	subscriptions?: WooPaymentsPaymentOrder[];
	[ key: string ]: unknown;
}

export interface WooPaymentsBillingDetails {
	email?: string;
	name?: string;
	formatted_address?: string;
	address?: {
		line1?: string;
		line2?: string;
		city?: string;
		state?: string;
		postal_code?: string;
		country?: string;
		[ key: string ]: unknown;
	};
	[ key: string ]: unknown;
}

export interface WooPaymentsPaymentMethodDetails {
	type?: string;
	card?: {
		brand?: string;
		last4?: string;
		exp_month?: number | string;
		exp_year?: number | string;
		funding?: string;
		network?: string;
		country?: string;
		checks?: {
			cvc_check?: string;
			address_line1_check?: string;
			address_postal_code_check?: string;
			[ key: string ]: unknown;
		};
		[ key: string ]: unknown;
	};
	card_present?: WooPaymentsPaymentMethodDetails[ 'card' ];
	interac_present?: WooPaymentsPaymentMethodDetails[ 'card' ];
	[ key: string ]: unknown;
}
```

Add `payment_method?: string` and `metadata?: Record< string, unknown >` to `WooPaymentsCharge` and `WooPaymentsTransaction`.

- [x] **Step 2: Add detail-safe formatting helpers**

In `utils.ts`, add helpers that do not pull in Moment or reference plugin dependencies:

```ts
export const formatDateTime = ( value?: string | number ) => {
	if ( ! value ) {
		return '-';
	}

	const timestamp =
		typeof value === 'number' && value < 10000000000 ? value * 1000 : value;
	const date = new Date( timestamp );

	if ( Number.isNaN( date.getTime() ) ) {
		return '-';
	}

	return date.toLocaleString( undefined, {
		year: 'numeric',
		month: 'short',
		day: 'numeric',
		hour: 'numeric',
		minute: '2-digit',
	} );
};

export const getChargeChannelLabel = (
	type?: string,
	metadata: Record< string, unknown > = {}
) => {
	if ( type === 'card_present' || type === 'interac_present' ) {
		return metadata.ipp_channel === 'mobile_pos'
			? __( 'In-person (POS)', 'woocommerce' )
			: __( 'In-person', 'woocommerce' );
	}

	return __( 'Online store', 'woocommerce' );
};
```

- [x] **Step 3: Create `transaction-detail-sections.tsx`**

Create presentational sections with no data fetching and no mutation logic. Use native `<a href>` for links, `<section aria-labelledby>` for cards, `<dl>` for label/value data, and dashes for unavailable values. Keep components pure and small:

```tsx
export const WooPaymentsPaymentSummarySection = ( { transaction }: { transaction: WooPaymentsTransaction } ) => { /* amount/status/fee/net/date/channel/customer/order/subscription/payment method/risk */ };
export const WooPaymentsPaymentIdentifiersSection = ( { paymentIntentId, chargeId, transactionResourceId, type }: { paymentIntentId: string; chargeId: string; transactionResourceId: string; type?: string } ) => { /* Payment ID, Charge ID, Transaction ID, Type */ };
export const WooPaymentsMissingOrderNotice = ( { transaction }: { transaction: WooPaymentsTransaction } ) => { /* null when order exists, short notice when absent */ };
export const WooPaymentsPaymentMethodDetailsSection = ( { transaction }: { transaction: WooPaymentsTransaction } ) => { /* card/card_present/interac_present details only for now */ };
```

Implementation details:

- Use `transaction.order?.customer_url` for the customer link when present; otherwise render the customer name/email text.
- Use `transaction.order?.url` for the order link when present; render `transaction.order.number || transaction.order.id`.
- Render `transaction.order.subscriptions` as comma-separated links when present.
- Use `formatAmount( -fee, currency )` for the summary `Fees` line to match the reference sign convention, but keep the secondary details card labels explicit enough that existing tests looking for absolute fee/net values remain meaningful.
- Derive refunded display from `amount_refunded > 0` as `Refunded: -$10.00`.
- Build card details from `payment_method_details[ payment_method_details.type ] || payment_method_details.card`, so `card`, `card_present`, and `interac_present` work with the same code path.
- Format card number as `•••• 4242`, expiry as `12 / 2030`, type as `Visa credit card`, origin through `window.wcSettings.countries`, and checks as `Passed`, `Failed`, or `Unavailable`.
- Render formatted billing address as escaped text split into lines; do not inject `formatted_address` HTML into the DOM.

- [x] **Step 4: Run focused type check on the new file if desired before wiring**

Run:

```bash
pnpm --filter=@woocommerce/admin-library lint:lang:types
```

Expected at this point may still fail because the new components are not yet wired or all imports are not used. Fix only compile blockers that affect the implementation.

## Task 3: Wire the Detail Page and Test-Mode Notice

- [x] **Step 1: Extend `WooPaymentsTestModeNotice` for payment detail pages**

Change `TestModeNoticePage` from only `'loans'` to `'loans' | 'payments'`, add noun/verb maps, and add an optional `isDetailsView?: boolean` prop:

```ts
type TestModeNoticePage = 'loans' | 'payments';

const pageLabels: Record< TestModeNoticePage, string > = {
	loans: __( 'loans', 'woocommerce' ),
	payments: __( 'payments', 'woocommerce' ),
};

const singularPageLabels: Record< TestModeNoticePage, string > = {
	loans: __( 'loan', 'woocommerce' ),
	payments: __( 'payment', 'woocommerce' ),
};
```

For `isDetailsView && currentPage === 'payments'`, render sentence-case copy:

```tsx
<strong>{ __( 'Viewing test payments.', 'woocommerce' ) }</strong>{ ' ' }
{ createInterpolateElement(
	__( 'Your %1$s account is currently in test mode. To view live payments, disable test mode in <settingsLink>WooPayments settings</settingsLink>.', 'woocommerce' ),
	{
		settingsLink: <a href={ getSettingsPaymentsProviderRouteUrl( '/woopayments/settings' ) } />,
	}
) }
```

Keep the existing Capital `loans` behavior and tests green.

- [x] **Step 2: Wire the new sections into `transaction-details-page.tsx`**

Import the notice and section components:

```ts
import { WooPaymentsTestModeNotice } from '../test-mode-notice';
import {
	WooPaymentsMissingOrderNotice,
	WooPaymentsPaymentIdentifiersSection,
	WooPaymentsPaymentMethodDetailsSection,
	WooPaymentsPaymentSummarySection,
} from './transaction-detail-sections';
```

Add `payment_method`, `metadata`, and `billing_details` preservation to `normalizeCharge()` and `normalizePaymentIntent()`:

```ts
payment_method: charge.payment_method,
metadata: charge.metadata,
billing_details: charge.billing_details,
```

Replace the old flat `<dl>` block with:

```tsx
<WooPaymentsTestModeNotice currentPage="payments" isDetailsView />
<WooPaymentsPaymentSummarySection transaction={ transaction } />
<WooPaymentsMissingOrderNotice transaction={ transaction } />
<WooPaymentsPaymentIdentifiersSection
	paymentIntentId={ paymentIntentId }
	chargeId={ chargeId }
	transactionResourceId={ transactionResourceId }
	type={ transaction.type }
/>
{ transaction.dispute && (
	<WooPaymentsTransactionDisputeDetails transaction={ transaction } />
) }
{ showCaptureNotice && ( /* existing authorization notice unchanged */ ) }
<WooPaymentsPaymentMethodDetailsSection transaction={ transaction } />
```

Keep `WooPaymentsTransactionDisputeDetails`, authorization actions, refund actions, refund modal, and timeline code in the same behavioral positions unless tests prove focus/order regressions.

- [x] **Step 3: Add scoped styles**

In `style.scss`, keep existing money-movement card styling and add detail-section classes:

```scss
.woocommerce-woopayments-money-movement__detail-sections {
	display: grid;
	gap: 16px;
}

.woocommerce-woopayments-money-movement__summary-card {
	background: #fff;
	border: 1px solid #dcdcde;
	border-radius: 4px;
	padding: 20px;
}

.woocommerce-woopayments-money-movement__summary-header {
	align-items: center;
	display: flex;
	flex-wrap: wrap;
	gap: 12px;
	justify-content: space-between;
}

.woocommerce-woopayments-money-movement__summary-amount {
	font-size: 32px;
	font-weight: 300;
	margin: 0;
}

.woocommerce-woopayments-money-movement__summary-list,
.woocommerce-woopayments-money-movement__payment-method-grid {
	display: grid;
	gap: 12px;
	grid-template-columns: repeat(2, minmax(0, 1fr));
}
```

Use the existing mobile media query to collapse the grids to one column. Avoid nested cards and keep all new selectors scoped to `woocommerce-woopayments-money-movement`.

- [x] **Step 4: Run GREEN focused Jest**

Run:

```bash
pnpm --filter=@woocommerce/admin-library test:js -- money-movement-pages.test.tsx --runInBand
```

Expected: PASS with the new summary/card/link/test-mode assertions and existing refund/authorization/dispute tests still green.

## Task 4: Source Gate, Reviews, and Runtime Verification

- [x] **Step 1: Extend the ignored A4 source gate**

In `tools/woopayments-merge/a4-admin-surface-gate.py`, add source tokens under `check_money_detail_source()` for the new detail contract:

```py
"summary-heading": "Summary",
"sales-channel": "Sales channel",
"missing-order-notice": "This payment is not linked to a WooCommerce order.",
"identifiers-heading": "Identifiers",
"payment-method-card": "WooPaymentsPaymentMethodDetailsSection",
"detail-test-mode-notice": "isDetailsView",
```

Run:

```bash
python3 tools/woopayments-merge/a4-admin-surface-gate.py --out .agents/scratchpad/sessions/2026-06-15-core-native-payments/data/a4ak-admin-surface-gate.json
```

Expected: PASS and record the money-movement chunk size.

- [x] **Step 2: Run static/build gates**

Run the focused frontend gates:

```bash
pnpm --filter=@woocommerce/admin-library lint:js -- plugins/woocommerce/client/admin/client/woopayments/admin/money-movement/transaction-details-page.tsx plugins/woocommerce/client/admin/client/woopayments/admin/money-movement/transaction-detail-sections.tsx plugins/woocommerce/client/admin/client/woopayments/admin/test-mode-notice.tsx plugins/woocommerce/client/admin/client/woopayments/admin/test/money-movement-pages.test.tsx
pnpm --filter=@woocommerce/admin-library lint:lang:types
pnpm --filter=@woocommerce/admin-library lint:css -- plugins/woocommerce/client/admin/client/woopayments/admin/style.scss
pnpm --filter=@woocommerce/admin-library build:project:bundle
git diff --check -- . ':!.agents'
```

Expected: PASS. Existing unrelated webpack cache serialization warnings may remain non-blocking.

- [x] **Step 3: Dispatch review agents**

After the local green line, dispatch at least accessibility, architecture, and JS test-quality review agents against the A4ak diff. Ask reviewers to focus on: focus/order regressions around existing action controls, linked-record semantics, unsafe HTML/address rendering, over-scoped SCSS, type drift from backend contracts, and brittle tests.

- [x] **Step 4: Run Playwriter proof**

Use the existing local target store. Load a current target transaction detail URL that has order-backed payment data, capture desktop and mobile screenshots, and assert visible markers: `Payment details`, `Viewing test payments` when the account is test mode, `Summary`, `Sales channel`, `Customer`, `Order`, `Payment method`, `Identifiers`, `Timeline`, and existing `Transaction actions` when eligible. Save evidence under `.agents/scratchpad/sessions/2026-06-15-core-native-payments/data/`.

Do not submit refund/capture/dispute mutations during this proof unless the local fixture was explicitly prepared for that mutation. Non-mutating browser proof is sufficient for A4ak because action behavior is already covered by focused A4ah/A4ai tests.

- [x] **Step 5: Scan logs**

Clear or time-bound the target logs before the browser proof, then scan target `wp-content/debug.log`, target Docker logs, and relevant local simulator logs for PHP notices, warnings, deprecations, fatals, uncaught exceptions, stack traces, database errors, or actual 4xx/5xx responses caused by this route. Do not ignore new WP notices.

- [x] **Step 6: Changelog and branch gates**

Add a WooCommerce Core changelog entry. Then run:

```bash
pnpm --filter=@woocommerce/plugin-woocommerce changelog validate
pnpm --filter=@woocommerce/plugin-woocommerce lint:changes:branch
git diff --check -- . ':!.agents'
git status --short --branch --untracked-files=all
```

Expected: PASS or only previously documented unrelated warnings. Scratchpad files stay unlinted and uncommitted unless explicitly requested.

- [x] **Step 7: Commit locally only when all gates pass**

Use two logical commits if needed: product/tests first, changelog second. Do not push. Do not commit or push to trunk. Record the git range in staging-log and implementation-log.

## Follow-Up Tracking

- [x] After A4ak closes, keep A4 reopened for the next source-backed residual instead of moving to readiness: `card_reader_fee` route/table parity, full timeline mapper parity, payment-method detail support beyond card/card-present, checkout/card visual parity, and the final accumulated A4/N12 exit gate remain open. This is recorded in `staging-log.md`; A4/N12 readiness remains fail-closed.
