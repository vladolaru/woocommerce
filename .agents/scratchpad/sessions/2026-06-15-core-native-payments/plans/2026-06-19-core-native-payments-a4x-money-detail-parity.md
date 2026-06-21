---
session: 2026-06-15-core-native-payments
type: plan
by: codex
created: 2026-06-19 17:31
target: A4x native WooPayments money-detail parity
reconciles:
  - analysis-a4x-money-detail-parity.md
  - supervisor-prompt-2026-06-18-2344-N12.md
  - analysis-a4d-money-movement.md
status: draft
---

# A4x Money Detail Parity Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use $subagent-driven-development (recommended) or $executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Restore native WooPayments money-detail parity for payout details and payment/transaction details without regressing the already-routed disputes/challenge surfaces.

**Architecture:** Keep native WooPayments admin pages as Settings > Payments provider sub-routes and keep WooPayments-specific JS/CSS in the existing lazy admin chunks. Use the existing native REST/API contracts where they already match the reference, expose the missing timeline read through the native payment-detail REST controller, and keep detail-summary UI purpose-built instead of forcing list components into non-list surfaces. Consider WordPress DataViews only where the surface is actually a table/list, especially the embedded payout transaction history; do not force DataViews to mimic the reference when a semantic detail panel is the cleaner fit.

**Tech Stack:** PHP REST controllers and focused PHPUnit, React/TypeScript admin components, `@wordpress/components`, existing `@wordpress/dataviews/wp` wrapper, Jest/RTL, SCSS, Playwriter, `tools/woopayments-merge` A4 harness.

---

## File Structure

- Modify `plugins/woocommerce/client/admin/client/woopayments/admin/payout-details.tsx` for payout/withdrawal detail composition, instant-payout no-history behavior, bank reference copy affordance, and embedded transaction list handoff.
- Modify `plugins/woocommerce/client/admin/client/woopayments/admin/style.scss` only for WooPayments admin-detail styling that belongs to the lazy WooPayments admin bundle.
- Modify `plugins/woocommerce/client/admin/client/woopayments/admin/test/payout-details-page.test.tsx` for payout detail RED/GREEN coverage.
- Modify `plugins/woocommerce/src/Internal/Payments/Providers/WooPayments/WooPaymentsPaymentDetailsRestController.php` to register and proxy `/wc/v3/payments/timeline/{id}`.
- Modify `plugins/woocommerce/tests/php/src/Internal/Payments/Providers/WooPayments/RecordingMoneyMovementApiClient.php` and `plugins/woocommerce/tests/php/src/Internal/Payments/Providers/WooPayments/WooPaymentsMoneyMovementRestControllerTest.php` for timeline REST contract coverage.
- Modify `plugins/woocommerce/client/admin/client/woopayments/admin/money-movement/data.ts` and `plugins/woocommerce/client/admin/client/woopayments/admin/money-movement/types.ts` for timeline and richer payment-detail contracts.
- Modify `plugins/woocommerce/client/admin/client/woopayments/admin/money-movement/transaction-details-page.tsx` for the payment-detail foundation: summary rows, fee/net breakdown, payment method, risk, order/customer metadata, and timeline.
- Modify `plugins/woocommerce/client/admin/client/woopayments/admin/test/money-movement-pages.test.tsx` for transaction detail RED/GREEN coverage.
- Modify `tools/woopayments-merge/harness/a4-admin-surface-gate.py` only if source/static coverage gaps remain after implementation; keep harness changes honest and do not mask product bugs.
- Add one WooCommerce changelog entry under `plugins/woocommerce/changelog/` after implementation verification.
- Update `.agents/scratchpad/sessions/2026-06-15-core-native-payments/staging-log.md`, `implementation-log.md`, and `analysis-a4x-money-detail-parity.md` as evidence is produced.

## Task 1: Payout Details Parity

**Files:**
- Modify: `plugins/woocommerce/client/admin/client/woopayments/admin/payout-details.tsx`
- Modify: `plugins/woocommerce/client/admin/client/woopayments/admin/style.scss`
- Test: `plugins/woocommerce/client/admin/client/woopayments/admin/test/payout-details-page.test.tsx`

- [ ] **Step 1: Write failing payout detail tests**

Add focused expectations to `payout-details-page.test.tsx` that fail against the current page:

```tsx
expect( await screen.findByRole( 'heading', { name: 'Payout details' } ) ).toBeInTheDocument();
expect( screen.getByText( 'STRIPE TEST BANK **** 6789' ) ).toBeInTheDocument();
expect( screen.getByRole( 'button', { name: 'Copy bank reference ID to clipboard' } ) ).toBeInTheDocument();
expect( screen.getByText( 'REF123' ) ).toBeInTheDocument();
expect( screen.getByRole( 'link', { name: 'View all transactions in this payout' } ) ).toHaveAttribute( 'href', expect.stringContaining( 'deposit_id=po_test' ) );
```

Add a separate instant-payout test using a deposit fixture with `automatic: false`, `type: 'instant'`, or whichever native payload field the source exposes after verification. The expected behavior is no embedded transaction table/DataViews and the visible reference copy:

```tsx
expect( await screen.findByText( "We're unable to show transaction history on instant payouts. Learn more" ) ).toBeInTheDocument();
expect( screen.queryByRole( 'table' ) ).not.toBeInTheDocument();
expect( screen.queryByTestId( 'money-movement-dataviews' ) ).not.toBeInTheDocument();
```

Add a withdrawal fixture and assert the heading/labels use `Withdrawal details` and withdrawal wording when the reference source does so.

- [ ] **Step 2: Run the focused payout tests and confirm RED**

Run:

```bash
pnpm --filter=@woocommerce/plugin-woocommerce test:js -- --runTestsByPath plugins/woocommerce/client/admin/client/woopayments/admin/test/payout-details-page.test.tsx
```

Expected: the new copy button, instant-payout, and withdrawal assertions fail before implementation.

- [ ] **Step 3: Implement payout details**

Keep factual details in semantic headings, definition lists, and status/notice regions. Add a native button for bank-reference copy using a `<button>`/`Button` with a stable accessible name, `navigator.clipboard?.writeText`, and a polite live message such as `Bank reference ID copied.`; if Clipboard API is unavailable, keep the reference ID visible and report `Bank reference ID is available to copy.` rather than hiding data.

For the embedded transaction history, first try the existing `WooPaymentsMoneyMovementDataViews` wrapper because this is a true list/table surface. Use a compact view with fields `date`, `type`, and `amount`, `getItemId={ getResourceId }`, `search={false}` only if the wrapper supports it cleanly; if it does not, keep a semantic table rather than forcing DataViews into an awkward state. In either case, add a full-history link to `/woopayments/transactions?deposit_id={payout.id}` using `getSettingsPaymentsProviderRouteUrl()` so merchants can move into the normal DataViews list.

For instant payouts, do not fetch/render the transaction list if the payload clearly identifies an instant payout; render the explanatory copy and a `Learn more` link to the same reference target after source verification. For failed payouts, render the failure reason in a visible notice region while keeping the screen-reader status region stable.

- [ ] **Step 4: Run payout tests and a11y/source checks**

Run the focused payout test again. Confirm:

- The loading/status region remains stable.
- Copy button is keyboard-operable and has a clear accessible name.
- No icon-only or pseudo-content-only status is introduced.
- WooPayments-specific CSS remains scoped under existing WooPayments admin classnames.

## Task 2: Timeline REST Contract

**Files:**
- Modify: `plugins/woocommerce/src/Internal/Payments/Providers/WooPayments/WooPaymentsPaymentDetailsRestController.php`
- Modify: `plugins/woocommerce/tests/php/src/Internal/Payments/Providers/WooPayments/RecordingMoneyMovementApiClient.php`
- Test: `plugins/woocommerce/tests/php/src/Internal/Payments/Providers/WooPayments/WooPaymentsMoneyMovementRestControllerTest.php`

- [ ] **Step 1: Write failing PHP tests for timeline registration and proxying**

Extend `test_payment_detail_routes_register_only_when_native_owns_runtime()` to assert:

```php
$this->assertArrayHasKey( '/wc/v3/payments/timeline/(?P<timeline_id>\\w+)', $routes );
```

Add a new test:

```php
public function test_payment_detail_timeline_route_proxies_timeline_id(): void {
	$this->api_client->response = array(
		'data' => array(
			array(
				'type'    => 'captured',
				'message' => 'Payment captured.',
			),
		),
	);
	$this->create_payment_details_controller( true )->register_routes();

	$request  = new WP_REST_Request( 'GET', '/wc/v3/payments/timeline/pi_test' );
	$response = $this->server->dispatch( $request );
	$data     = $response->get_data();

	$this->assertSame( 200, $response->get_status() );
	$this->assertSame( 'get_timeline', $this->api_client->last_call['method'] );
	$this->assertSame( 'pi_test', $this->api_client->last_call['timeline_id'] );
	$this->assertSame( 'captured', $data['data'][0]['type'] );
}
```

- [ ] **Step 2: Run the focused PHP test and confirm RED**

Run:

```bash
pnpm --filter=@woocommerce/plugin-woocommerce test:php -- --filter WooPaymentsMoneyMovementRestControllerTest::test_payment_detail
```

Expected: timeline route assertion fails before implementation.

- [ ] **Step 3: Implement the route**

Register `/payments/timeline/(?P<timeline_id>\w+)` with the same `get_readable_route()` helper and add a `get_timeline( WP_REST_Request $request )` callback that calls `$this->api_client->get_timeline( (string) $request->get_param( 'timeline_id' ) )`. Reuse `api_exception_to_wp_error()` unchanged unless tests expose invalid HTTP status propagation; if that happens, clamp only impossible statuses to 400 and preserve legitimate API statuses.

- [ ] **Step 4: Run the focused PHP test and PHP static checks**

Run the focused PHP test again. After it passes, run PHP lint/static checks for touched PHP:

```bash
pnpm --filter=@woocommerce/plugin-woocommerce lint:php:changes
cd plugins/woocommerce && composer exec -- phpstan analyse src/Internal/Payments/Providers/WooPayments/WooPaymentsPaymentDetailsRestController.php tests/php/src/Internal/Payments/Providers/WooPayments/RecordingMoneyMovementApiClient.php tests/php/src/Internal/Payments/Providers/WooPayments/WooPaymentsMoneyMovementRestControllerTest.php --memory-limit=2G
```

## Task 3: Payment/Transaction Detail Foundation

**Files:**
- Modify: `plugins/woocommerce/client/admin/client/woopayments/admin/money-movement/data.ts`
- Modify: `plugins/woocommerce/client/admin/client/woopayments/admin/money-movement/types.ts`
- Modify: `plugins/woocommerce/client/admin/client/woopayments/admin/money-movement/transaction-details-page.tsx`
- Modify: `plugins/woocommerce/client/admin/client/woopayments/admin/style.scss`
- Test: `plugins/woocommerce/client/admin/client/woopayments/admin/test/money-movement-pages.test.tsx`

- [ ] **Step 1: Write failing frontend tests for richer detail rendering**

Extend the payment-intent detail test with a fixture that includes the fields native already receives from the payment intent/charge endpoints:

```tsx
mockGetPaymentIntent.mockResolvedValue( {
	id: 'pi_test',
	status: 'succeeded',
	amount: 5000,
	currency: 'usd',
	created: 1781712000,
	charge: {
		id: 'ch_test',
		balance_transaction: {
			id: 'txn_test',
			fee: 180,
			net: 4820,
		},
		type: 'charge',
		amount: 5000,
		currency: 'usd',
		created: 1781712000,
		billing_details: {
			email: 'ada@example.com',
			name: 'Ada Lovelace',
		},
		payment_method_details: {
			type: 'card',
			card: {
				brand: 'visa',
				last4: '4242',
			},
		},
		outcome: {
			risk_level: 'normal',
		},
	},
	order: {
		id: 123,
		number: '123',
	},
} );
mockGetTimeline.mockResolvedValue( {
	data: [
		{
			type: 'captured',
			message: 'Payment captured.',
			created: 1781712060,
		},
	],
} );
```

Assert the target composition:

```tsx
expect( await screen.findByRole( 'heading', { name: 'Payment details' } ) ).toBeInTheDocument();
expect( screen.getByText( 'pi_test' ) ).toBeInTheDocument();
expect( screen.getByText( 'ch_test' ) ).toBeInTheDocument();
expect( screen.getByText( 'Ada Lovelace' ) ).toBeInTheDocument();
expect( screen.getByText( 'ada@example.com' ) ).toBeInTheDocument();
expect( screen.getByText( 'Visa ending in 4242' ) ).toBeInTheDocument();
expect( screen.getByText( 'Normal' ) ).toBeInTheDocument();
expect( screen.getByText( '$50.00' ) ).toBeInTheDocument();
expect( screen.getByText( '$1.80' ) ).toBeInTheDocument();
expect( screen.getByText( '$48.20' ) ).toBeInTheDocument();
expect( screen.getByText( 'Payment captured.' ) ).toBeInTheDocument();
```

Also assert existing `txn_`, `ch_`, and `pi_` routing behavior still calls the correct helper and that a charge with `payment_intent` still redirects to the intent URL before final rendering.

- [ ] **Step 2: Add `getWooPaymentsTimeline()` and richer types**

Add to `data.ts`:

```ts
export const getWooPaymentsTimeline = (
	timelineId: string
): Promise< WooPaymentsTimelineResponse > =>
	apiFetch< WooPaymentsTimelineResponse >( {
		path: `${ PAYMENTS_PATH }/timeline/${ encodeURIComponent( timelineId ) }`,
		method: 'GET',
	} );
```

Widen `WooPaymentsCharge`, `WooPaymentsPaymentIntent`, and `WooPaymentsTransaction` with optional fields only. Add `WooPaymentsTimelineEvent` and `WooPaymentsTimelineResponse`. Keep unknown provider payload fields typed as `unknown` or narrow nested optional objects; do not invent required fields the platform does not guarantee.

- [ ] **Step 3: Implement the payment detail summary and timeline**

Rename the visible heading to `Payment details` for charge/payment-intent detail, while preserving transaction detail fallback behavior for raw `txn_` records when no richer object is available. Render semantic sections:

- Summary: status, amount, Date, Sales channel if available, Customer, Order, Payment method, Risk evaluation.
- Identifiers: Payment ID, Charge ID, Transaction ID.
- Breakdown: Amount, Fee, Net amount when values are present.
- Timeline: list of timeline messages/events when `/timeline/{id}` succeeds; if it fails, keep the primary detail page usable and show a non-blocking status message rather than replacing all detail content.

Do not add refund, capture, cancel, close-dispute, or destructive controls unless the implementation can preserve the reference guardrails and tests in this same slice. If omitted, record them as an A4 follow-up in the scratchpad instead of silently implying parity.

- [ ] **Step 4: Run focused frontend tests**

Run:

```bash
pnpm --filter=@woocommerce/plugin-woocommerce test:js -- --runTestsByPath plugins/woocommerce/client/admin/client/woopayments/admin/test/money-movement-pages.test.tsx
```

Expected: all money movement tests pass, including the richer payment-detail assertions and existing list/DataViews behavior.

## Task 4: Dispute Detail/Challenge Disposition and Harness Coverage

**Files:**
- Modify: `plugins/woocommerce/client/admin/client/woopayments/admin/money-movement/dispute-details.tsx` only if accessibility or redirect copy is missing.
- Modify: `plugins/woocommerce/client/admin/client/woopayments/admin/test/money-movement-pages.test.tsx` if redirect/challenge coverage is widened.
- Modify: `tools/woopayments-merge/harness/a4-admin-surface-gate.py` if source coverage remains narrower than the A4x claims.
- Modify: `.agents/scratchpad/sessions/2026-06-15-core-native-payments/analysis-a4x-money-detail-parity.md`

- [ ] **Step 1: Verify reference dispute detail remains a redirect**

Source-check the reference plugin files before changing native:

```bash
rg -n "redirect-to-transaction-details|disputes/details|disputes/challenge" /Users/vladolaru/Work/a8c/woocommerce-payments/client
```

Expected: dispute detail routes redirect into payment details; native should not add a standalone dispute detail page unless source disproves the current finding.

- [ ] **Step 2: Keep challenge wizard gap explicit**

If A4x does not complete the full reference new-evidence wizard, update `analysis-a4x-money-detail-parity.md` and `staging-log.md` with a follow-up A4 item for dispute challenge wizard parity. This is a tracked A4 gap, not a hidden pass.

- [ ] **Step 3: Widen the harness honestly**

If product/source changes justify it, extend `a4-admin-surface-gate.py` with static checks only:

- Native money detail routes exist under `/woopayments/*`.
- Transaction detail uses `/wc/v3/payments/timeline/{id}` and keeps `pi_`, `ch_`, and `txn_` ID paths.
- Payout detail contains instant-payout no-history copy, bank-reference copy affordance, and deposit-scoped transaction handoff.
- Dispute detail stays redirect-only and challenge route remains registered.

Do not mark visual parity passed from static checks. Browser evidence remains the visual/UX gate.

## Task 5: Browser and Runtime Verification

**Files:**
- Update: `.agents/scratchpad/sessions/2026-06-15-core-native-payments/staging-log.md`
- Update: `.agents/scratchpad/sessions/2026-06-15-core-native-payments/implementation-log.md`

- [ ] **Step 1: Build admin assets**

Run:

```bash
pnpm --filter=@woocommerce/plugin-woocommerce build:admin
```

Record chunk deltas for WooPayments admin/money-movement bundles. Treat size measurements as big-delta indicators, not as false precision.

- [ ] **Step 2: Run the A4 harness**

Run:

```bash
python3 tools/woopayments-merge/harness/a4-admin-surface-gate.py --target-root /Users/vladolaru/Work/a8c/woocommerce-develop-2 --out .agents/scratchpad/sessions/2026-06-15-core-native-payments/data/a4x-admin-surface-gate.json
```

Expected: PASS. If it fails, fix product code or harness truthfully; do not loosen checks to hide an implementation bug.

- [ ] **Step 3: Use Playwriter for native/reference checks**

Use Playwriter against native `http://store8889.localhost:8889` and reference `http://localhost:8082`:

- Payout details route with a real local `po_*` ID.
- Transaction/payment details route with a real local `pi_*` or `ch_*` ID.
- Instant payout branch if local fixtures exist; otherwise record component-test-only coverage and leave a fixture-dependent A4 exit item.

Capture what was checked, the IDs used, and any reference-vs-target differences in `staging-log.md`.

- [ ] **Step 4: Scan local logs for notices/warnings**

Check target store logs after browser verification. Do not ignore PHP notices or warnings; investigate any new ones before closing A4x.

## Task 6: Review, Full Verification, and Commit

**Files:**
- Add: `plugins/woocommerce/changelog/add-native-payments-a4x-money-detail-parity`
- Update: `.agents/scratchpad/sessions/2026-06-15-core-native-payments/staging-log.md`
- Update: `.agents/scratchpad/sessions/2026-06-15-core-native-payments/implementation-log.md`

- [ ] **Step 1: Dispatch review subagents**

Dispatch at least:

- Spec/parity reviewer: compare A4x implementation against this plan, N12, and reference source.
- Code-quality reviewer: focus on frontend accessibility, PHP REST contracts, bundle scoping, and no product bugs hidden by harness changes.
- Adversarial reviewer if the DataViews-vs-purpose-built choice is load-bearing after implementation.

- [ ] **Step 2: Run verification**

Run the focused tests from earlier tasks plus:

```bash
pnpm --filter=@woocommerce/plugin-woocommerce lint:js plugins/woocommerce/client/admin/client/woopayments/admin
pnpm --filter=@woocommerce/plugin-woocommerce typecheck
pnpm --filter=@woocommerce/plugin-woocommerce stylelint "plugins/woocommerce/client/admin/client/woopayments/admin/**/*.scss"
pnpm --filter=@woocommerce/plugin-woocommerce lint:php:changes
pnpm --filter=@woocommerce/plugin-woocommerce lint:changes:branch
git diff --check -- . ':!.agents'
```

Do not lint `.agents/scratchpad`.

- [ ] **Step 3: Add changelog and commit**

Add one changelog entry for WooCommerce Core after verification. Commit one logical A4x change on the current branch, not trunk:

```bash
git add plugins/woocommerce/client/admin/client/woopayments/admin plugins/woocommerce/src/Internal/Payments/Providers/WooPayments plugins/woocommerce/tests/php/src/Internal/Payments/Providers/WooPayments plugins/woocommerce/changelog/add-native-payments-a4x-money-detail-parity
git commit
```

Use a conventional commit such as `feat(payments): improve native WooPayments money details`. Do not push.

## Self-Review Notes

- DataViews is considered only for embedded payout transaction history because it is a list/table surface; detail summary sections stay semantic and purpose-built.
- Stripe Billing remains retired; no invoice handlers or legacy subscription engine work belongs in this slice.
- Dispute details remain redirect-only unless reference source disproves that; the bigger dispute challenge wizard is tracked if not completed.
- Native admin readiness remains fail-closed until the widened A4 exit gate passes.
