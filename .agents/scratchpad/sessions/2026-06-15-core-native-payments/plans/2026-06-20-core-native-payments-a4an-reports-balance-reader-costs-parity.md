---
session: 2026-06-15-core-native-payments
type: plan
by: codex
created: 2026-06-20 10:07
last_updated: 2026-06-20 10:23
target: A4an Reports Balance reader-costs parity
reconciles:
  - ../analysis-a4an-reports-balance-reader-costs-parity.md
  - ../staging-log.md
  - ../supervisor-prompt-2026-06-18-2344-N12.md
status: final
---

# A4an Reports Balance Reader-Costs Parity Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use $subagent-driven-development (recommended) or $executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Restore the native WooPayments Reports Balance row contract so reader fees surface as `Reader costs` alongside the full reference reconciliation row set.

**Architecture:** Keep Reports as the A4w native WooPayments provider sub-route and keep the current DataViews table mechanics. Repair the Balance row adapter in the Reports chunk so it maps the already-preserved backend summary keys into the reference label/order/visibility contract, without changing backend routes or generic Analytics code.

**Tech Stack:** React/TypeScript WooCommerce admin client, `@wordpress/dataviews/wp`, Jest/React Testing Library, Playwriter, WooCommerce admin bundle and A4 admin-surface harness.

---

## File Map

- Modify: `plugins/woocommerce/client/admin/client/woopayments/admin/reports/page.tsx` for the full Balance row contract.
- Modify: `plugins/woocommerce/client/admin/client/woopayments/admin/reports/types.ts` only if the current balance row types are missing a needed key.
- Modify: `plugins/woocommerce/client/admin/client/woopayments/admin/test/reports-page.test.tsx` for RED/GREEN Balance row assertions.
- Modify: `.agents/scratchpad/sessions/2026-06-15-core-native-payments/analysis-a4an-reports-balance-reader-costs-parity.md`, `implementation-log.md`, `staging-log.md`, and README for status/evidence.
- Add: WooCommerce changelog entry after product gates pass.

## Task 1: Reports Balance Row Contract

**Files:**
- Modify: `plugins/woocommerce/client/admin/client/woopayments/admin/test/reports-page.test.tsx`
- Modify: `plugins/woocommerce/client/admin/client/woopayments/admin/reports/page.tsx`

- [x] **Step 1: Write RED test coverage.**

Expand the `balanceSummary` fixture to include the canonical row keys:

```ts
const balanceSummary = {
	currency: 'usd',
	period: {
		start: '2026-06-01T00:00:00Z',
		end: '2026-06-19T23:59:59Z',
	},
	starting_balance: { amount: 1000 },
	total_charges_captured: { amount: 162672, count: 8 },
	fees: { amount: -6064 },
	charge_fees: { amount: -5958 },
	payout_fees: { amount: -100 },
	reader_fees: { amount: -150 },
	dispute_fees: { amount: -1500 },
	fee_refunds: { amount: 1644 },
	refunds: { amount: -21500, count: 3 },
	refund_failure: { amount: -2000, count: 1 },
	disputes: { amount: -4000, count: 1 },
	financing_payout: { amount: 5000, count: 1 },
	financing_paydown: { amount: -500, count: 1 },
	network_costs: { amount: -250, count: 1 },
	other_adjustments: { amount: 750, count: 1 },
	net_balance_change_in_the_period: { amount: 132008 },
	payouts: { amount: 1102608, count: 2 },
	ending_balance: { amount: 877 },
};
```

In `renders the Reports shell, Balance tab, DataViews, and page view tracking`, assert the canonical labels:

```ts
for ( const label of [
	'Starting balance',
	'Total charges captured',
	'Fees',
	'Charge fees',
	'Payout fees',
	'Reader costs',
	'Dispute fees',
	'Fee refunds',
	'Refunds',
	'Refund failures',
	'Disputes',
	'Financing payout',
	'Financing paydown',
	'Network costs',
	'Other adjustments',
	'Net balance change in the period',
	'Payouts',
	'Ending balance',
] ) {
	expect( screen.getByText( label ) ).toBeInTheDocument();
}
expect( screen.queryByText( 'Charges' ) ).not.toBeInTheDocument();
expect( speak ).toHaveBeenCalledWith( '18 balance report rows loaded.', 'polite' );
```

- [x] **Step 2: Run the RED Jest check.**

Run:

```bash
pnpm --dir plugins/woocommerce/client/admin test:js -- woopayments/admin/test/reports-page.test.tsx --runInBand
```

Expected: fail because native currently renders `Charges` and omits `Reader costs` plus the adjacent reconciliation rows.

- [x] **Step 3: Implement the row contract.**

Replace the collapsed `getBalanceRows()` array with the reference row order and labels, using the `woocommerce` text domain:

```ts
const getBalanceRows = ( summary: ReportsBalanceSummary ): BalanceRow[] =>
	[
		{ id: 'starting_balance', label: __( 'Starting balance', 'woocommerce' ), amount: getBalanceAmount( summary.starting_balance ), count: summary.starting_balance?.count, alwaysVisible: true },
		{ id: 'total_charges_captured', label: __( 'Total charges captured', 'woocommerce' ), amount: getBalanceAmount( summary.total_charges_captured ), count: summary.total_charges_captured?.count, alwaysVisible: true },
		{ id: 'fees', label: __( 'Fees', 'woocommerce' ), amount: getBalanceAmount( summary.fees ), count: summary.fees?.count, alwaysVisible: true },
		{ id: 'charge_fees', label: __( 'Charge fees', 'woocommerce' ), amount: getBalanceAmount( summary.charge_fees ), count: summary.charge_fees?.count },
		{ id: 'dispute_fees', label: __( 'Dispute fees', 'woocommerce' ), amount: getBalanceAmount( summary.dispute_fees ), count: summary.dispute_fees?.count },
		{ id: 'fee_refunds', label: __( 'Fee refunds', 'woocommerce' ), amount: getBalanceAmount( summary.fee_refunds ), count: summary.fee_refunds?.count },
		{ id: 'refunds', label: __( 'Refunds', 'woocommerce' ), amount: getBalanceAmount( summary.refunds ), count: summary.refunds?.count },
		{ id: 'refund_failure', label: __( 'Refund failures', 'woocommerce' ), amount: getBalanceAmount( summary.refund_failure ), count: summary.refund_failure?.count },
		{ id: 'disputes', label: __( 'Disputes', 'woocommerce' ), amount: getBalanceAmount( summary.disputes ), count: summary.disputes?.count },
		{ id: 'financing_payout', label: __( 'Financing payout', 'woocommerce' ), amount: getBalanceAmount( summary.financing_payout ), count: summary.financing_payout?.count },
		{ id: 'financing_paydown', label: __( 'Financing paydown', 'woocommerce' ), amount: getBalanceAmount( summary.financing_paydown ), count: summary.financing_paydown?.count },
		{ id: 'payout_fees', label: __( 'Payout fees', 'woocommerce' ), amount: getBalanceAmount( summary.payout_fees ), count: summary.payout_fees?.count },
		{ id: 'reader_fees', label: __( 'Reader costs', 'woocommerce' ), amount: getBalanceAmount( summary.reader_fees ), count: summary.reader_fees?.count },
		{ id: 'network_costs', label: __( 'Network costs', 'woocommerce' ), amount: getBalanceAmount( summary.network_costs ), count: summary.network_costs?.count },
		{ id: 'other_adjustments', label: __( 'Other adjustments', 'woocommerce' ), amount: getBalanceAmount( summary.other_adjustments ), count: summary.other_adjustments?.count },
		{ id: 'net_balance_change_in_the_period', label: __( 'Net balance change in the period', 'woocommerce' ), amount: getBalanceAmount( summary.net_balance_change_in_the_period ), count: summary.net_balance_change_in_the_period?.count, alwaysVisible: true },
		{ id: 'payouts', label: __( 'Payouts', 'woocommerce' ), amount: getBalanceAmount( summary.payouts ), count: summary.payouts?.count, alwaysVisible: true },
		{ id: 'ending_balance', label: __( 'Ending balance', 'woocommerce' ), amount: getBalanceAmount( summary.ending_balance ), count: summary.ending_balance?.count, alwaysVisible: true },
	].filter( ( row ) => row.alwaysVisible || row.amount !== 0 || Number( row.count ?? 0 ) > 0 );
```

Add `alwaysVisible?: boolean` to the local `BalanceRow` type. Keep display signs as the current native implementation already renders raw minor-unit values through `formatAmount`; do not change sign semantics in this slice unless tests prove reference-visible behavior is wrong.

- [x] **Step 4: Run GREEN Jest.**

Run:

```bash
pnpm --dir plugins/woocommerce/client/admin test:js -- woopayments/admin/test/reports-page.test.tsx --runInBand
```

Expected: PASS with no React act warnings or console errors.

## Task 2: Source/Static Gates And Review

**Files:**
- Modify only if findings require it: `plugins/woocommerce/client/admin/client/woopayments/admin/reports/page.tsx`, `plugins/woocommerce/client/admin/client/woopayments/admin/test/reports-page.test.tsx`

- [x] **Step 1: Reconcile the source-map subagent.**

Compare subagent findings against the implementation. Any confirmed backend/schema/test gap becomes part of this slice before browser proof. Any broader Reports parity gap is recorded as an A4/N12 residual, not silently ignored.

- [x] **Step 2: Run focused static gates.**

Run:

```bash
pnpm --dir plugins/woocommerce/client/admin exec eslint client/woopayments/admin/reports/page.tsx client/woopayments/admin/test/reports-page.test.tsx
pnpm --filter=@woocommerce/admin-library lint:lang:types
pnpm --filter=@woocommerce/admin-library build:project:bundle
python3 tools/woopayments-merge/a4-admin-surface-gate.py --repo "$PWD" --out .agents/scratchpad/sessions/2026-06-15-core-native-payments/data/a4an-admin-surface-gate.json --plugin-repo /Users/vladolaru/Work/a8c/woocommerce-payments
```

Expected: PASS. Record bundle deltas honestly and do not treat this as full A4 exit coverage.

- [x] **Step 3: Run review agents.**

Dispatch at least an accessibility reviewer for the Balance table/status behavior and a code reviewer or performance reviewer for the row adapter and bundle impact. Fix confirmed findings with RED/GREEN coverage.

## Task 3: Browser/Logs, Docs, Changelog, And Commit

**Files:**
- Add: `plugins/woocommerce/changelog/fix-native-payments-a4an-reports-balance-reader-costs-parity`
- Update: session scratchpad docs listed in the File Map.

- [x] **Step 1: Browser-proof the target Reports Balance row.**

Use Playwriter on:

```text
http://store8889.localhost:8889/wp-admin/admin.php?page=wc-settings&tab=checkout&path=%2Fwoopayments%2Freports
```

If live target data includes `reader_fees`, verify visible `Reader costs`. If not, use a local non-product probe or REST interception only after documenting why the live local account cannot deterministically produce the row; never change the product to satisfy the harness. Capture failed responses, console messages, and a screenshot/evidence JSON.

- [x] **Step 2: Check logs after browser proof.**

Inspect target `debug.log`, target WC logs, and relevant target Docker logs for PHP notices/warnings/deprecations/fatals/database errors/actual 5xx lines. Record exact findings.

- [x] **Step 3: Final hygiene gates.**

Run:

```bash
pnpm --filter=@woocommerce/plugin-woocommerce changelog validate
git diff --check -- . ':!.agents'
pnpm --filter=@woocommerce/plugin-woocommerce lint:changes:branch
git status --short --untracked-files=all
```

Expected: PASS, with only known existing tooling noise explicitly recorded.

- [x] **Step 4: Commit locally only.**

Create one product/test commit and one changelog commit if the scope matches existing conventions. Do not push. Record the git range in `staging-log.md`, `implementation-log.md`, and README.

## Closeout Status

All implementation, review, browser, log, hygiene, and local-commit gates are complete. Source/tests landed as `983ee15254` and changelog landed as `bdc86d6ea1`, with git range `d101c1d251...bdc86d6ea1`.
