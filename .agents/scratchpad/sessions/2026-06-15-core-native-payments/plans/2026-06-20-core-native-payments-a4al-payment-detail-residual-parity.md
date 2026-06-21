---
session: 2026-06-15-core-native-payments
type: plan
by: codex
created: 2026-06-20 08:31
reconciles:
  - analysis-a4al-payment-detail-residual-parity.md
  - analysis-a4al-payment-method-mapping-subagent.md
  - supervisor-prompt-2026-06-18-2344-N12.md
status: complete
last_updated: 2026-06-20 09:11
---

# A4al Payment Detail Residual Parity Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use $subagent-driven-development (recommended) or $executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Close the remaining source-backed WooPayments payment-detail residuals after A4ak: card reader fee detail routing/table parity, richer timeline display parity, and non-card payment-method detail row parity.

**Architecture:** Keep the existing native WooPayments REST/backend detail contracts as the source of truth. Add frontend data helpers and focused renderers that consume the current payload shape without inventing missing platform fields, keeping payment-detail-only work separate from Reports and checkout shopper surfaces.

**Tech Stack:** WooCommerce admin React/TypeScript, WordPress i18n/components, Jest + React Testing Library, Playwriter browser proof against local target/reference stores, ignored WooPayments merge harness gates.

---

## File Map

- Modify: `plugins/woocommerce/client/admin/client/woopayments/admin/test/money-movement-pages.test.tsx` for route/rendering RED coverage and post-implementation regression coverage.
- Modify: `plugins/woocommerce/client/admin/client/woopayments/admin/test/money-movement-data.test.ts` for the reader-charge summary REST helper.
- Modify: `plugins/woocommerce/client/admin/client/woopayments/admin/test/utils.test.ts` for `metadata.charge_type` detail-route construction.
- Modify: `plugins/woocommerce/client/admin/client/woopayments/admin/money-movement/types.ts` to type reader-charge summary rows and keep timeline/payment-method fields safely dynamic.
- Modify: `plugins/woocommerce/client/admin/client/woopayments/admin/money-movement/data.ts` to add `getWooPaymentsReaderChargeSummary()`.
- Modify: `plugins/woocommerce/client/admin/client/woopayments/admin/money-movement/utils.ts` to prefer `metadata.charge_type` for detail route `transaction_type` when present.
- Create: `plugins/woocommerce/client/admin/client/woopayments/admin/money-movement/transaction-card-reader-fee-details.tsx` for the reader-fee detail route branch, summary table, error/loading state, and local CSV export.
- Create: `plugins/woocommerce/client/admin/client/woopayments/admin/money-movement/transaction-timeline.tsx` for bounded timeline event mapping and rendering.
- Modify: `plugins/woocommerce/client/admin/client/woopayments/admin/money-movement/transaction-details-page.tsx` to route `transaction_type=card_reader_fee` to the reader-fee component and delegate normal payment timelines to `transaction-timeline.tsx`.
- Modify: `plugins/woocommerce/client/admin/client/woopayments/admin/money-movement/transaction-detail-sections.tsx` to add Address to generic non-card methods and method-specific rows for fields already present under `payment_method_details[type]`.
- Modify: `tools/woopayments-merge/a4-admin-surface-gate.py` only if the deterministic A4 source gate needs new payment-detail tokens for this slice.
- Add: WooCommerce changelog entry for `@woocommerce/plugin-woocommerce`.

## Task 1: RED Coverage For The Residual Contracts

**Files:**

- Modify: `plugins/woocommerce/client/admin/client/woopayments/admin/test/money-movement-pages.test.tsx`
- Modify: `plugins/woocommerce/client/admin/client/woopayments/admin/test/money-movement-data.test.ts`
- Modify: `plugins/woocommerce/client/admin/client/woopayments/admin/test/utils.test.ts`

- [x] **Step 1: Add the failing data-helper test.**

Add a test that imports `getWooPaymentsReaderChargeSummary()`, calls it with `txn_reader_fee_123`, and expects `apiFetch` to receive:

```typescript
{
	path: '/wc/v3/payments/readers/charges/txn_reader_fee_123',
	method: 'GET',
}
```

Expected RED result before implementation: TypeScript/Jest fails because `getWooPaymentsReaderChargeSummary` is not exported.

- [x] **Step 2: Add the failing route-helper test.**

In `utils.test.ts`, assert that:

```typescript
getTransactionDetailsRoute( {
	id: 'txn_reader_fee_123',
	type: 'charge',
	metadata: { charge_type: 'card_reader_fee' },
} )
```

returns `/woopayments/transactions/details?id=txn_reader_fee_123&transaction_type=card_reader_fee`. Expected RED result before implementation: route uses `transaction_type=charge`.

- [x] **Step 3: Add failing reader-fee detail page tests.**

In `money-movement-pages.test.tsx`, mock the new data helper and render the details route with:

```typescript
window.history.pushState( {}, '', '/wp-admin/admin.php?page=wc-settings&tab=checkout&path=%2Fwoopayments%2Ftransactions%2Fdetails&id=ch_reader_fee_123&transaction_id=txn_reader_fee_123&transaction_type=card_reader_fee' );
```

Assert the page calls the reader helper with `txn_reader_fee_123`, renders `Card readers`, `Reader id`, `Status`, `Transactions`, `Fee`, and a representative row such as `tmr_reader_1`, `active`, `3`, `$12.34`. Add a rejection test that shows `Readers details not loaded`. Expected RED result before implementation: the generic transaction detail loader runs and the reader table is absent.

- [x] **Step 4: Add failing timeline mapper/rendering tests.**

Render a normal transaction detail response with timeline events for `captured`, `partial_refund`, `dispute.created`, and `review.allowed`. Assert event-specific user copy appears, including captured amount, fee/net body rows where present, refund reason/ARN where present, dispute amount where present, and manual fraud user copy where present. Expected RED result before implementation: fallback labels such as `Captured` render instead of the richer copy/body rows.

- [x] **Step 5: Add failing non-card payment-method tests.**

Render transactions with representative payment methods:

```typescript
payment_method: { id: 'pm_ideal', type: 'ideal' },
payment_method_details: { ideal: { bank: 'ING', bic: 'INGBNL2A', iban_last4: '6789', verified_name: 'Ada Buyer' } },
billing_details: { name: 'Ada Buyer', email: 'ada@example.test', formatted_address: '123 Canal St\nAmsterdam' }
```

Assert `Bank name`, `BIC`, `IBAN`, `Verified name`, and `Address` are visible. Add one `amazon_pay` or `klarna` fixture to cover an additional dynamic method-specific object. Expected RED result before implementation: only Type/ID/Owner/Owner email render.

- [x] **Step 6: Run the focused RED tests and capture the expected failures.**

Run:

```bash
pnpm --filter=@woocommerce/admin-library test -- --runTestsByPath client/woopayments/admin/test/money-movement-data.test.ts client/woopayments/admin/test/utils.test.ts client/woopayments/admin/test/money-movement-pages.test.tsx
```

Expected: the new tests fail for missing exports/routes/rendered copy, while unrelated existing tests keep their prior behavior.

## Task 2: Reader Fee Detail Route And Data Surface

**Files:**

- Modify: `plugins/woocommerce/client/admin/client/woopayments/admin/money-movement/types.ts`
- Modify: `plugins/woocommerce/client/admin/client/woopayments/admin/money-movement/data.ts`
- Modify: `plugins/woocommerce/client/admin/client/woopayments/admin/money-movement/utils.ts`
- Create: `plugins/woocommerce/client/admin/client/woopayments/admin/money-movement/transaction-card-reader-fee-details.tsx`
- Modify: `plugins/woocommerce/client/admin/client/woopayments/admin/money-movement/transaction-details-page.tsx`

- [x] **Step 1: Add reader-charge summary types.**

Add a response shape that accepts the platform payload without overfitting:

```typescript
export type WooPaymentsReaderChargeSummaryRow = {
	reader_id?: string;
	status?: string;
	transactions?: number;
	fee?: number | { amount?: number; currency?: string };
	amount?: number;
	currency?: string;
	[ key: string ]: unknown;
};

export type WooPaymentsReaderChargeSummaryResponse =
	| WooPaymentsReaderChargeSummaryRow[]
	| { data?: WooPaymentsReaderChargeSummaryRow[]; rows?: WooPaymentsReaderChargeSummaryRow[] };
```

- [x] **Step 2: Add `getWooPaymentsReaderChargeSummary()`.**

Export a `GET` helper in `data.ts` that calls `/wc/v3/payments/readers/charges/{transaction_id}` using `encodeURIComponent()`.

- [x] **Step 3: Update detail route construction.**

Allow `getTransactionDetailsRoute()` input items to include `metadata?: Record<string, unknown>`, derive `transaction_type` from `metadata.charge_type` when it is a string, and fall back to `item.type`.

- [x] **Step 4: Add the reader-fee detail component.**

Implement `transaction-card-reader-fee-details.tsx` with `useEffect()` fetching by `transactionId`, a polite loading status, an error state using the reference copy `Readers details not loaded`, a semantic table with `Reader id`, `Status`, `Transactions`, `Fee`, and a secondary `Download` button that builds a CSV from visible rows. Use semantic `<table>` here rather than DataViews because this is a fixed detail table with no sorting, filters, pagination, row selection, or bulk actions.

- [x] **Step 5: Route `transaction_type=card_reader_fee` before the generic detail loader.**

In `transaction-details-page.tsx`, read `transaction_type` from `URLSearchParams`. When it is `card_reader_fee`, render the normal page frame and test-mode notice plus `WooPaymentsCardReaderFeeDetails` with `transactionId || transactionIdFromId`, and do not call the charge/payment-intent detail loader.

- [x] **Step 6: Run GREEN tests for Task 2.**

Run the focused tests from Task 1. Expected: data helper, route helper, and reader-fee page tests pass; timeline and non-card tests may still fail until Tasks 3 and 4.

## Task 3: Bounded Timeline Mapping

**Files:**

- Create: `plugins/woocommerce/client/admin/client/woopayments/admin/money-movement/transaction-timeline.tsx`
- Modify: `plugins/woocommerce/client/admin/client/woopayments/admin/money-movement/types.ts`
- Modify: `plugins/woocommerce/client/admin/client/woopayments/admin/money-movement/transaction-details-page.tsx`

- [x] **Step 1: Keep event payload typing dynamic and safe.**

Extend `WooPaymentsTimelineEvent` with `[ key: string ]: unknown` and optional fields used by the mapper such as `amount`, `currency`, `fee`, `tax`, `net`, `deposit`, `reason`, `acquirer_reference_number`, `dispute`, `user`, `ruleset_results`, and `loan_id`. Do not require these fields.

- [x] **Step 2: Implement `transaction-timeline.tsx`.**

Export `WooPaymentsTransactionTimeline` that accepts `events`, maps each raw event into one or more display rows, and renders the existing `Timeline` heading in an ordered list. Implement bounded mapping for:

- `started`: payment status changed to Started.
- `authorized`, `authorization_voided`, `authorization_expired`: authorization-specific status and amount copy.
- `captured`: status Paid plus charged amount and optional fee/tax/net/deposit/body rows.
- `partial_refund`, `full_refund`, `refund_failed`: refund amount plus optional reason and ARN rows.
- `failed`: failure amount and failure reason if present.
- `dispute.created`, `dispute.closed`, `dispute.funds_withdrawn`, `dispute.funds_reinstated`: dispute-specific amount/status copy where fields exist.
- `financing_paydown`: loan/paydown copy where fields exist.
- manual/automatic fraud outcomes: preserve current manual user copy and add automatic review/block copy from existing event fields.
- unknown events: preserve `event.message` or `formatLabel(event.type)`.

- [x] **Step 3: Render only present fields.**

Every optional body row must be guarded by a value check so the UI never displays `undefined`, `null`, `[object Object]`, or invented values. Use the existing WooPayments amount formatter for monetary fields and `formatDateTime()` for dates.

- [x] **Step 4: Replace inline timeline rendering.**

Remove the inline `getTimelineMessage()`/timeline list from `transaction-details-page.tsx` and render `WooPaymentsTransactionTimeline` with the fetched timeline events.

- [x] **Step 5: Run GREEN tests for Task 3.**

Run the focused page tests. Expected: timeline tests pass without regressing existing summary/action/refund tests.

## Task 4: Non-Card Payment Method Detail Rows

**Files:**

- Modify: `plugins/woocommerce/client/admin/client/woopayments/admin/money-movement/transaction-detail-sections.tsx`

- [x] **Step 1: Add safe dynamic field helpers.**

Add helpers to read `payment_method_details[method.type]` as a record, coerce strings/numbers safely, build masked account labels from `last4`/`iban_last4`, and format origin country codes using the existing countries map where available.

- [x] **Step 2: Add Address to the generic non-card fallback.**

Reuse the existing address renderer so base methods (`affirm`, `alipay`, `afterpay_clearpay`, `grabpay`, `multibanco`, `wechat_pay`, unknown non-card methods) show Type, ID, Owner, Owner email, and Address.

- [x] **Step 3: Add method-specific row specs.**

Add rows for present payload fields from:

- `amazon_pay`: Amazon transaction ID.
- `au_becs_debit`: BSB, Account.
- `bancontact`: Bank name, BIC, Verified name.
- `eps`: Bank name, Verified name.
- `giropay`: Bank name, BIC.
- `ideal`: Bank name, BIC, IBAN, Verified name.
- `klarna`: Category, Preferred locale.
- `p24`: Bank name, Reference, Verified name.
- `sepa_debit`: IBAN, Origin.
- `sofort`: Bank code, Bank name, BIC, IBAN, Verified name, Origin.

- [x] **Step 4: Preserve graceful degradation.**

Known methods should show their expected rows with `Dash` for absent values. Unknown methods should retain the generic non-card fallback plus Address. Do not add wallet icon parity in this slice.

- [x] **Step 5: Run GREEN tests for Task 4.**

Run the focused page tests. Expected: non-card payment-method detail tests pass and existing card/card_present/interac_present tests still pass.

## Task 5: Verification, Review, Documentation, And Commit

**Files:**

- Modify: `tools/woopayments-merge/a4-admin-surface-gate.py` if needed.
- Add: `plugins/woocommerce/changelog/*` entry.
- Modify: `.agents/scratchpad/sessions/2026-06-15-core-native-payments/staging-log.md`
- Modify: `.agents/scratchpad/sessions/2026-06-15-core-native-payments/implementation-log.md`
- Modify: `.agents/scratchpad/sessions/2026-06-15-core-native-payments/README.md`
- Modify: this plan status and checkboxes.

- [x] **Step 1: Run the focused deterministic test gate.**

Run:

```bash
pnpm --filter=@woocommerce/admin-library test -- --runTestsByPath client/woopayments/admin/test/money-movement-data.test.ts client/woopayments/admin/test/utils.test.ts client/woopayments/admin/test/money-movement-pages.test.tsx
```

Expected: all focused tests pass.

- [x] **Step 2: Run exact-file lint/type/build gates.**

Run exact-file ESLint over touched TypeScript/TSX files, admin `lint:lang:types`, any relevant Stylelint if CSS changes are made, and the admin bundle build. Do not lint `.agents/scratchpad/`.

- [x] **Step 3: Run the A4 admin source/browser surface gate.**

Update `tools/woopayments-merge/a4-admin-surface-gate.py` only if new payment-detail tokens are needed, then run the A4 admin gate and record raw/gzip bundle numbers. Treat the gate as an admin-surface smoke gate, not as final A4/N12 completion.

- [x] **Step 4: Run Playwriter proof.**

Use Playwriter against the target store at `http://store8889.localhost:8889/wp-admin/admin.php?page=wc-settings&tab=checkout&path=%2Fwoopayments%2Ftransactions%2Fdetails...`. Prefer a real local reader-fee transaction and real non-card/timeline detail if available through local stores or safe local probes. If fixtures are absent, document the absence honestly, prove the normal detail route still loads cleanly, and rely on Jest for deterministic branch coverage. Compare ambiguous copy/shape against the reference store at `http://localhost:8082` where the reference has equivalent data.

- [x] **Step 5: Clear and inspect fresh logs.**

Before browser proof, clear or snapshot target/reference `wp-content/debug.log` and relevant Docker/WooCommerce logs if present. After proof, confirm no fresh PHP fatal, warning, notice, deprecated, database error, uncaught exception, or real 5xx line was introduced by the slice.

- [x] **Step 6: Dispatch review agents.**

Use focused review agents after implementation: `a11y-reviewer` for table/status semantics, `architecture-reviewer` or `wp-architecture-reviewer` for route/data boundaries, `js-tests-reviewer` for test quality, and `reliability-reviewer` for loading/error/log behavior. Fix source-backed findings before closeout.

- [x] **Step 7: Add changelog and final hygiene.**

Add a WooCommerce changelog entry, run `git diff --check -- . ':!.agents'`, run the applicable branch lint gate without including `.agents/scratchpad`, and ensure no unintended WPCOM/dev-tools changes are present.

- [x] **Step 8: Record closeout and commit.**

Update `staging-log.md`, `implementation-log.md`, `README.md`, and this plan with final evidence and remaining residuals. Commit product changes only on the current feature branch with one logical Conventional Commit. Do not push.

## Standing Follow-Up

- [ ] After A5c is fully done, re-open A4 for the feature-parity work described in `supervisor-prompt-2026-06-18-2344-N12.md`; do not treat A4al as final A4/N12 completion.
