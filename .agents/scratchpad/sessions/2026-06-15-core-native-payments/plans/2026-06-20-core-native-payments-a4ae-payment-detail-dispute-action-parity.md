---
session: 2026-06-15-core-native-payments
type: plan
by: codex
created: 2026-06-20 03:16
last_updated: 2026-06-20 04:03
reconciles:
  - analysis-a4ae-payment-detail-dispute-action-parity.md
  - analysis-a4ad-next-parity-slice.md
  - supervisor-prompt-2026-06-18-2344-N12.md
status: final
---

# A4ae Payment Detail and Dispute Action Parity Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use $subagent-driven-development (recommended) or $executing-plans to implement this plan task-by-task. Steps use checkbox (`- [x]`) syntax for tracking.

**Goal:** Restore the native WooPayments transaction-detail route as the dispute decision hub so merchants see dispute context and safe response actions before entering the evidence challenge flow.

**Architecture:** Keep the route ownership in Core Settings > Payments and reuse the existing native REST/data helpers. Add one focused dispute decision component under `money-movement/`, keep `transaction-details-page.tsx` responsible for loading and normalization, and route dispute-list actions into the transaction-detail route instead of bypassing it.

**Tech Stack:** WooCommerce admin React/TypeScript, WordPress Components, `@woocommerce/tracks`, Jest/React Testing Library, existing native `/wc/v3/payments/disputes/:id/close` endpoint, Playwriter for browser proof.

---

## Files

- Modify: `plugins/woocommerce/client/admin/client/woopayments/admin/money-movement/types.ts` to type the raw dispute/charge fields used by the detail decision component.
- Modify: `plugins/woocommerce/client/admin/client/woopayments/admin/money-movement/data.ts` only if the close helper import shape needs adjustment; the endpoint helper already exists.
- Modify: `plugins/woocommerce/client/admin/client/woopayments/admin/money-movement/disputes-page.tsx` so actionable disputes link to transaction details with a respond-oriented accessible label and Tracks action.
- Modify: `plugins/woocommerce/client/admin/client/woopayments/admin/money-movement/transaction-details-page.tsx` to preserve raw charge dispute data during normalization and render the new decision component.
- Create: `plugins/woocommerce/client/admin/client/woopayments/admin/money-movement/transaction-dispute-details.tsx` for dispute status copy, challenge/continue links, accept modal, close endpoint handling, and status-specific resolved footer copy.
- Modify: `plugins/woocommerce/client/admin/client/woopayments/admin/style.scss` only if the new component needs scoped layout classes; avoid broad style churn.
- Modify: `plugins/woocommerce/client/admin/client/woopayments/admin/test/money-movement-pages.test.tsx` for RED/GREEN behavior coverage.
- Modify: `.agents/scratchpad/sessions/2026-06-15-core-native-payments/implementation-log.md`, `staging-log.md`, `README.md`, and `spec-conformance-baseline.md` at closeout.

## Closeout Note

A4ae completed the intended transaction-detail dispute decision hub and is committed locally as `9232b9d5eb` plus changelog `0ca1d25347`: actionable dispute rows route through transaction details, the detail page renders dispute status/actions/guidance, accept-dispute uses the existing close endpoint, and stale dispute caches are invalidated only after platform success. Review-driven focus regressions cover both async modal-dismiss paths. The slice intentionally does not port refund-modal or detail-level capture/cancel/fraud-review money actions; those remain separate reopened-A4 work because they need their own money-safety gates.

### Task 1: RED Tests for the Decision Hub

- [x] **Step 1: Change the existing disputes row test expectation first**

In `money-movement-pages.test.tsx`, update the actionable dispute row expectations to require the transaction-details route instead of `/woopayments/disputes/challenge`. The expected href should be `http://example.com/wp-admin/admin.php?page=wc-settings&tab=checkout&path=%2Fwoopayments%2Ftransactions%2Fdetails&id=ch_test`. Keep the link text `Respond now` and update the accessible name to `Respond to fraudulent dispute dp_test from transaction details`.

- [x] **Step 2: Add transaction-detail tests for an awaiting-response dispute**

Add a test that loads `/woopayments/transactions/details?id=pi_test&transaction_id=txn_test` with a payment intent whose charge includes a dispute `{ id: 'dp_test', status: 'needs_response', reason: 'fraudulent', evidence_details: { due_by: 1781913600 }, amount: 5000, currency: 'usd' }`. Assert that the page renders `Dispute details`, `Response needed`, `Challenge dispute`, `Accept dispute`, and a help link named `Learn more about responding to disputes`. Assert the challenge link points to the native challenge sub-route with `id=dp_test`.

- [x] **Step 3: Add accept-dispute modal and success/error tests**

Extend the data mock to include `closeWooPaymentsDispute: jest.fn()`. Add one test where clicking `Accept dispute` opens a modal titled `Accept the dispute?`, clicking the modal primary button calls `closeWooPaymentsDispute( 'dp_test' )`, shows a success notice, and updates the visible status to `Lost` when the endpoint resolves with `{ id: 'dp_test', status: 'lost', reason: 'fraudulent' }`. Add a second test where the endpoint rejects with `new Error( 'Close failed' )` and asserts an error notice with `Close failed`.

- [x] **Step 4: Add inquiry and resolved-status tests**

Add a warning inquiry test with status `warning_needs_response` that renders `Submit evidence`, `Issue refund`, and explanatory copy that refunding inquiries must be completed through the full refund flow. Add resolved tests for `under_review`, `won`, and `lost` that assert status-specific copy plus the `View submitted evidence` or `View dispute details` link when `metadata.__evidence_submitted_at` exists.

- [x] **Step 5: Run RED**

Run `pnpm --filter=@woocommerce/admin-library test:js --runTestsByPath client/woopayments/admin/test/money-movement-pages.test.tsx --runInBand`. Expected: failure because dispute rows still route directly to challenge, the transaction detail component does not render dispute actions, and `closeWooPaymentsDispute` is not used by the page.

### Task 2: Implement Route and Normalization

- [x] **Step 1: Extend types**

Add permissive fields to `WooPaymentsCharge`, `WooPaymentsPaymentIntent`, `WooPaymentsBalanceTransaction`, and `WooPaymentsDispute` so TypeScript accepts `charge.dispute`, dispute metadata/balance transactions, captured/refunded fields, and balance transaction currency. Keep `[ key: string ]: unknown` where platform shape may vary.

- [x] **Step 2: Preserve dispute fields during normalization**

Update `normalizeCharge()` so `WooPaymentsTransaction` carries `dispute`, `amount_refunded`, `refunded`, `captured`, `balance_transaction`, and `application_fee_amount` when present. Update `normalizePaymentIntent()` so an intent-level dispute is preserved if platform returns one there, with charge-level dispute taking precedence.

- [x] **Step 3: Route disputes list actions through transaction details**

Remove the direct `getDisputeChallengeRoute()` use from actionable dispute rows. Use `getTransactionDetailsRoute( item )` for both actionable and non-actionable disputes, change the Tracks `action` value for actionable rows to `respond_from_transaction_details`, and update the accessible label to clarify the transaction-detail decision point.

- [x] **Step 4: Run focused tests**

Run the focused Jest command. Expected: row-route tests should pass after this task; transaction-detail dispute tests should still fail until Task 3.

### Task 3: Add the Transaction Dispute Decision Component

- [x] **Step 1: Create `transaction-dispute-details.tsx`**

Implement helpers `isAwaitingResponseStatus()`, `isInquiryStatus()`, `isVisaComplianceDispute()`, `getDisputeStatusLabel()`, `getHelpLink()`, and `getResolvedDisputeMessage()` in the new file. Keep strings in `woocommerce` textdomain and use `createInterpolateElement` only where links/strong text are needed.

- [x] **Step 2: Render awaiting-response actions**

For awaiting response statuses (`needs_response`, `warning_needs_response`), render a `section` with heading `Dispute details`, a status paragraph, a summary list for dispute ID/reason/status/due date/amount/customer/payment method where data exists, a documentation `ExternalLink`, a primary challenge link to `getSettingsPaymentsProviderRouteUrl( '/woopayments/disputes/challenge?id=...' )`, and a secondary accept/refund action. For normal disputes, the secondary action opens the accept modal; for warning inquiries, render `Issue refund` with explanatory text and no money-moving side effect in this slice.

- [x] **Step 3: Implement accept modal and close handling**

Use WordPress `Modal` and `Button` so focus trapping/return are handled by the component library. The modal title is `Accept the dispute?`, the body states that accepting marks the dispute as lost and cannot be undone, and the primary action calls `closeWooPaymentsDispute()`. On success, update local dispute state, call `createSuccessNotice( 'Dispute accepted.' )`, and record `wcpay_dispute_accept_click` with `dispute_id`, `dispute_status`, `dispute_reason`, and `on_page: 'transaction_details'`. On failure, call `createErrorNotice( getErrorMessage( error, 'Unable to accept dispute.' ) )`.

- [x] **Step 4: Render resolved/under-review statuses**

For `under_review`, `won`, `lost`, `warning_under_review`, and `warning_closed`, render concise reference-aligned status guidance and a submitted-evidence/detail link to the native challenge route when evidence metadata exists. Record `wcpay_view_submitted_evidence_clicked` when that link is used.

- [x] **Step 5: Mount from transaction details**

Import the component into `transaction-details-page.tsx` and render it after the details list and before the timeline when `transaction.dispute` is present. Pass the normalized dispute, charge/payment context, and transaction identifiers.

- [x] **Step 6: Run focused tests**

Run `pnpm --filter=@woocommerce/admin-library test:js --runTestsByPath client/woopayments/admin/test/money-movement-pages.test.tsx --runInBand`. Expected: all A4ae-focused page tests pass.

### Task 4: Styling and Static Verification

- [x] **Step 1: Add scoped styles only if needed**

If the component renders cramped or unclear, add scoped classes under `.woocommerce-woopayments-money-movement__dispute-details` in `admin/style.scss`. Use existing card/detail/timeline spacing tokens where available and do not alter unrelated routes.

- [x] **Step 2: Run static checks**

Run `pnpm --filter=@woocommerce/admin-library lint:js -- client/woopayments/admin/money-movement/transaction-details-page.tsx client/woopayments/admin/money-movement/transaction-dispute-details.tsx client/woopayments/admin/money-movement/disputes-page.tsx client/woopayments/admin/test/money-movement-pages.test.tsx`. Run `pnpm --filter=@woocommerce/admin-library ts:check`. Run Stylelint only if `style.scss` changes.

- [x] **Step 3: Run diff hygiene**

Run `git diff --check -- . ':!.agents'`. Do not lint `.agents/scratchpad`.

### Task 5: Browser and Log Gate

- [x] **Step 1: Build the admin bundle if required**

If the local target store does not have a watch build serving current assets, run the normal admin build command used by prior A4 slices: `pnpm --filter=@woocommerce/admin-library build:project:bundle`.

- [x] **Step 2: Use Playwriter for target proof**

Load the target transaction-detail route on `http://store8889.localhost:8889/wp-admin/admin.php?page=wc-settings&tab=checkout&path=%2Fwoopayments%2Ftransactions%2Fdetails` with a real local transaction/dispute identifier discovered through WP-CLI or the existing REST helpers. Record visible heading/status/actions when available, failed responses, console errors, page errors, and screenshot path. If no suitable actionable dispute exists, record the absence honestly, verify a real payment detail route still loads cleanly, and rely on Jest for action rendering.

- [x] **Step 3: Compare reference path when data exists**

If the reference store has a comparable dispute/payment detail route, load it and compare the decision-layer elements against target: dispute status, challenge/submit-evidence label, accept/refund affordance, documentation link, and submitted-evidence link.

- [x] **Step 4: Scan logs**

Clear or snapshot target debug logs before browser proof, then confirm target `wp-content/debug.log` and relevant Docker logs have no fresh PHP notices, warnings, deprecations, fatals, uncaught exceptions, database errors, or actual HTTP 5xx lines for the proof window.

### Task 6: Review, Docs, and Commit

- [x] **Step 1: Run review agents**

Dispatch focused frontend/a11y, API-contract, and reliability reviewers over the A4ae diff. Fix source-backed findings before closeout.

- [x] **Step 2: Update session docs**

Update `analysis-a4ae-payment-detail-dispute-action-parity.md` to `status: final`, append A4ae closeout to `implementation-log.md`, add an A4ae package entry above the staging append marker, update `README.md` latest progress, and add a spec-conformance-baseline addendum noting which payment-detail/dispute-action gaps are closed and which remain open.

- [x] **Step 3: Add changelog and run branch gates**

Add the WooCommerce changelog entry for the product changes. Run changelog validation, `git diff --check -- . ':!.agents'`, and `pnpm --filter=@woocommerce/plugin-woocommerce lint:changes:branch`. Do not lint scratchpad files.

- [x] **Step 4: Commit**

Commit the product/test/changelog changes in one logical source commit plus a changelog commit if that remains the branch convention. Do not push. Report the git range.
