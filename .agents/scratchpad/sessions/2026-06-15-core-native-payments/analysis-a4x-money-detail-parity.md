---
session: 2026-06-15-core-native-payments
type: analysis
by: codex
created: 2026-06-19 17:25
target: A4x native WooPayments money-detail parity
reconciles:
  - staging-log.md
  - supervisor-prompt-2026-06-18-2344-N12.md
  - analysis-a4d-money-movement.md
status: draft
last_updated: 2026-06-19 18:44
---

# A4x Money Detail Parity Source Map

> **Prompt:** "Continue working toward the active thread goal."

## Starting Point

A4s restored DataViews-backed Transactions, Disputes, Payouts, Payout Details embedded list mechanics, and exports. A4u added authorizations/uncaptured action contracts. A4v/A4w closed Documents and Reports. The recurring A4 caveat after those slices is now narrower: richer transaction/dispute/payout detail parity, broader copy/content parity, and the aggregate A4 exit gate remain open. The A4x boundary should therefore harden the existing detail pages instead of redoing list/report/table work.

## Reference Detail Surface

The reference plugin registers detail routes in `client/index.js`: `/payments/payouts/details`, `/payments/transactions/details`, `/payments/disputes/details`, and `/payments/disputes/challenge`, all under the Payments menu and `manage_woocommerce`. The dispute-details route is already a compatibility redirect through `client/disputes/redirect-to-transaction-details/index.tsx`, so native's redirect-only `WooPaymentsDisputeDetailsRedirect` is architecturally consistent as long as the target transaction detail page is parity-complete.

Reference payout details live in `client/deposits/details/index.tsx` and `style.scss`. It renders `TestModeNotice`, a reference `Page`, `SummaryListPlaceholder` while loading, a payout/withdrawal overview with `OrderStatus`, explicit currency formatting, failed-payout `BannerNotice`, a `Payout details` card, bank account and bank reference ID blocks, and a `CopyButton` for the bank reference ID. For instant payouts it does not render a transactions table; it shows the explanatory copy `We're unable to show transaction history on instant payouts. Learn more` with the documented instant-payout URL. For normal payouts it renders the reference `TransactionsList depositId={depositId}` rather than a reduced local table.

Reference payment details are much richer than native's current `dl`. `client/payment-details/index.tsx` chooses card-reader fee, order, or charge/payment-intent detail. `client/payment-details/payment-details/index.tsx` renders `TestModeNotice`, `PaymentDetailsSummary`, optional `PaymentDetailsTimeline`, `PaymentTransactionBreakdown`, and `PaymentDetailsPaymentMethod`. The summary includes Date, Sales channel, Customer, Order, Subscription when applicable, Payment method, Risk evaluation, status chips, dispute details/recommendations/outcomes, capture/cancel authorization actions, refund modal/actions, missing-order notice, and detailed merchant copy around disputes/refunds/authorizations. Timeline data comes from `/wc/v3/payments/timeline/{id}` through `client/data/timeline/resolvers.js`. Charge and PaymentIntent reads come from `/wc/v3/payments/charges/{id}` and `/wc/v3/payments/payment_intents/{id}`.

Reference dispute challenge uses the newer `client/disputes/new-evidence/**` flow. It includes product/customer/shipping/refund/recommendation sections, evidence matrix logic, recommended document fields, confirmation screen, duplicate/refund status cards, cover-letter generation, file upload controls, and extensive reason/product-type-specific copy. Native already has an actionable evidence form, but it is a reduced direct form with product details, shipping details, document uploads, save draft, and submit evidence. This looks like a separate large parity slice unless A4x is intentionally limited to detail display and payout parity.

## Native Detail Surface

Native route registration in `client/admin/client/woopayments/admin/routes.tsx` already includes `/woopayments/payouts/details`, `/woopayments/transactions/details`, `/woopayments/disputes/details`, and `/woopayments/disputes/challenge`, bundled through the existing payouts and money-movement chunks.

Native payout details in `client/admin/client/woopayments/admin/payout-details.tsx` fetches the payout, transaction summary, and transactions filtered by `deposit_id`. It renders a back link, `Payout details` heading, live status/error messages, a definition list for Payout ID, Dispatch date, Status, Amount, Bank account, and Bank reference ID, a simple failure reason notice, a summary definition list, and a simple three-column table. It does not currently match the reference card/summary composition, bank-reference copy button, withdrawal/instant-payout labels, instant-payout no-history explanatory copy, or reference transaction-list composition.

Native transaction details in `client/admin/client/woopayments/admin/money-movement/transaction-details-page.tsx` resolves `pi_*`, `ch_*`/`py_*`, or `txn_*` IDs through native REST endpoints and normalizes them into a `WooPaymentsTransaction`. It renders only Transaction ID, Type, Date, and Amount. Native REST has `/wc/v3/payments/transactions/{transaction_id}`, `/wc/v3/payments/charges/{charge_id}`, and `/wc/v3/payments/payment_intents/{payment_intent_id}` via `WooPaymentsTransactionsRestController` and `WooPaymentsPaymentDetailsRestController`, and the API client already has `get_timeline()`, but there is no native `/wc/v3/payments/timeline/{id}` route and no frontend timeline/payment-method/fee-breakdown/detail summary composition.

Native dispute details in `client/admin/client/woopayments/admin/money-movement/dispute-details.tsx` redirects disputes to transaction details, which matches the reference route role. Native dispute challenge in `dispute-challenge-page.tsx` and `dispute-evidence-form.tsx` has a real evidence form, file details loading, save/submit actions, live announcements, upload-size enforcement, and Tracks events, but it does not yet mirror the reference new-evidence wizard and recommendation/confirmation composition.

## Proposed A4x Boundary

The next high-throughput chunk should focus on money-detail surfaces that are already routed and have clear reference equivalents: payout details and the payment-details foundation. Payout details is bounded enough to complete fully: card/summary composition, copy button, instant-payout no-history state, failed-payout notice copy, status styling, and reference transaction-list table behavior where DataViews/list mechanics already exist.

DataViews should be considered where it naturally matches the surface. The embedded payout transaction history is a true tabular list and can use the existing WooPayments DataViews wrapper if that keeps the UI clean and accessible. Payment identifiers, customer/order/payment-method facts, amount breakdowns, and timelines are detail-summary surfaces, so they should remain semantic purpose-built panels rather than forcing DataViews to visually approximate the reference.

Payment details is bigger. The practical A4x implementation should add the missing backend/frontend foundation needed for parity without pretending to finish every reference action: a native timeline REST route, richer payment detail types, summary sections for date/channel/customer/order/payment method/risk/status, transaction breakdown from available charge/timeline data, and graceful fail-closed rendering for destructive actions that are not yet implemented in the detail page. Refund modal and full dispute recommendation/outcome composition can be included only if source and browser checks show it remains manageable; otherwise they must be recorded as A4y follow-up, not hidden.

Dispute challenge's new-evidence wizard should be tracked as a distinct A4y slice unless subagent/source review finds the current native form is already informationally equivalent. The current evidence-form route is functionally safer than a placeholder, but it is visibly and copy-wise reduced compared with the reference.

## Gate Shape

A4x gates should include focused Jest for payout details, transaction details, and any new payment-detail subcomponents; focused PHP for new timeline/payment-detail REST route coverage; targeted ESLint/Stylelint/TypeScript; PHPStan/PHPCS for touched PHP; admin build and A4 admin-surface harness; Playwriter reference-vs-target checks for payout details and a transaction detail with real local IDs; and log scans for fresh PHP notices/warnings. Browser evidence should be honest about fixture limits: if the local stores do not have disputes/instant payouts/card-reader fees with realistic data, component tests and REST-contract tests cover those branches, and the final A4 exit gate must re-exercise them when fixtures exist.

## Harness Coverage Finding

Anscombe the 4th reviewed the current A4 harness and confirmed it is not a detail parity proof. `a4-admin-surface-gate.py` measures the money-movement chunk and asserts route/source/admin-shell contracts, but it does not inspect transaction-detail, payout-detail, dispute-detail, or dispute-challenge behavior/copy/visual parity. Existing component tests cover transaction detail loading/error and ID source routing, payout detail loading/error/bank-reference/embedded transactions, and dispute challenge save/submit/upload/read-only behaviors, but the tests do not assert full reference composition. A4x should add a bounded money-detail source section to the harness for route files, native route URLs, native REST helper usage, lazy chunk ownership, and key static tokens, while keeping Playwriter detail checks labeled as route/browser smoke unless they perform an actual reference-vs-target parity comparison.

## Native Source-Map Reconciliation

Turing the 4th confirmed the current native route and backend inventory at commit `64cbc2b10a`: provider routes exist for payouts, payout details, transactions, transaction details, disputes, dispute details, dispute challenge, card readers, reports, and related settings subpages; native REST routes are gated by `NativePaymentsRuntimeArbiter::should_native_register()` and `manage_woocommerce`. Transaction detail accepts `txn_`, `ch_`/`py_`, and `pi_` IDs, backed by transactions, charges, and payment-intent REST routes. Dispute challenge has real save/submit/upload/file-detail behavior, while dispute details is redirect-only. Payout details fetches payout, summary, and the first page of deposit-scoped transactions. Turing's suggested bounded slice was to expand transaction details using existing endpoint fields, improve payout-detail pagination/handoff, avoid new WPCOM-dependent fields, and tighten card-reader activity/copy. The reference source read still shows dispute details as a redirect, so the plan should not introduce a standalone dispute detail page unless the reference explorer returns contrary evidence.

## Reference Source-Map Reconciliation

Raman the 4th confirmed the reference transaction detail route is the composition root for payment and dispute detail work. It supports PaymentIntent IDs, charge IDs, numeric order IDs, and `transaction_type=card_reader_fee`; renders a `Page maxWidth={1032}`, `TestModeNotice`, summary, optional timeline, transaction breakdown, and payment-method details; and carries refund, capture/cancel authorization, fraud-review, dispute-detail, dispute-outcome, and dispute-recommendation actions/copy. Reference dispute details is a redirect to transaction details, but the resulting payment detail embeds active dispute panels with `Contact your customer`, `Challenge dispute`, `Accept dispute`, inquiry refund behavior, Klarna challenge disablement, Visa compliance acknowledgement, resolution footer variants, and outcome/submitted-evidence links. Reference dispute challenge is a large new-evidence wizard with stepper, recommendations, file chips, confirmation screen, and reason/product-type-specific evidence matrix logic. Reference payout details uses deposits endpoints with payout/withdrawal copy, `SummaryListPlaceholder`, automatic/instant/withdrawal layouts, failure banner, bank-reference copy button, instant-payout no-history card, and embedded `TransactionsList depositId={depositId}` for normal payouts.

## Open Inputs

Three read-only explorer agents were dispatched: reference detail source map, native detail source map, and A4 harness coverage. All three returned and are reconciled above. The implementation plan should use this document as its source of truth, while still verifying any load-bearing claim against source before changing code.

## Plan Handoff

The A4x implementation plan is saved at `plans/2026-06-19-core-native-payments-a4x-money-detail-parity.md`. It keeps DataViews scoped to list/table mechanics, adds the missing timeline REST seam, expands native payment details without adding unguarded destructive actions, and keeps dispute challenge wizard parity tracked as a separate A4 follow-up unless it is completed in this slice.

## Implementation Review Reconciliation

A4x review agents surfaced one source-backed API/detail mismatch that must be fixed in this slice. The reference timeline route registers `/payments/timeline/(?P<intention_id>\w+)` in `includes/admin/class-wc-rest-payments-timeline-controller.php`, while the native route initially used `timeline_id`. The path remains compatible either way, but route discovery should keep the reference parameter name. The reference `WC_Payments_API_Client::get_timeline()` also enriches fraud-review timelines by appending `_wcpay_fraud_outcome_manual_entry` order meta when platform events contain `fraud_outcome_review` or `fraud_outcome_block`, then sorts events by `datetime` descending and the reference event-order tie breaker. Native already writes this same meta in `WooPaymentsAuthorizationsRestController`, so the native timeline proxy should preserve the behavior instead of dropping the local manual decision.

The reference frontend timeline payload shape is `type` plus `datetime`; the native payment detail foundation initially rendered `event.message` and `event.created`, which would hide timestamps for real reference-shaped responses and degrade labels to raw formatted event types. A4x should support `datetime`, keep `message` as an optional local/provider extension, and render manual fraud outcome events with merchant-facing labels at least for the manual approve/block cases added through the enrichment path. The full reference Timeline component mapping, payment method cards, and transaction breakdown remain larger A4 parity follow-ups unless pulled into this slice by the gate.

The reference-integrity reviewer found no current payout-detail regressions against the reference for instant payout detection, withdrawal wording, bank-reference copy, no-history docs URL, and transaction-history handoff. It also confirmed dispute details is redirect-only in the reference. Remaining known gaps include numeric order-ID payment detail handoff, the full dispute challenge/new-evidence wizard, and the richer payment-detail composition.

## 2026-06-19 18:11 Checkpoint

Current A4x implementation state: payout details now preserve payout/withdrawal wording, bank-reference copy with `speak()` announcement, instant-payout no-history copy/docs link, all-transactions handoff, and a DataViews-backed embedded transaction collection for non-instant payouts. The DataViews decision was revised after source verification: detail facts and timelines remain semantic `dl`/ordered-list panels, while the non-instant payout transaction history is a real collection and now uses the shared `WooPaymentsMoneyMovementDataViews` wrapper instead of the old bespoke three-column table.

Payment detail foundation is implemented in the worktree: native resolves `pi_*`, `ch_*`/`py_*`, and `txn_*` routes; renders Payment ID, Charge ID, Transaction ID, status, amount, customer/order/payment method/risk/fee/net fields where present; calls the new native `/wc/v3/payments/timeline/(?P<intention_id>\w+)` route; supports reference-shaped `datetime` timeline events; and preserves manual fraud decision enrichment from `_wcpay_fraud_outcome_manual_entry`.

Fresh gates green at this checkpoint: focused payout RED/GREEN proved the DataViews conversion; focused admin Jest for payout/money-movement data/pages passed with 37 tests; admin `ts:check` passed; targeted admin ESLint passed; targeted Stylelint passed; focused PHP `WooPaymentsMoneyMovementRestControllerTest` passed with 32 tests and 213 assertions; changed-file PHPCS passed; PHPStan passed for `WooPaymentsPaymentDetailsRestController.php`; normal `build:admin` passed with existing unrelated webpack cache serialization warnings; and `a4-admin-surface-gate.py` passed with current chunk measurements including payouts `11396` raw / `3650` gzip and money-movement `32222` raw / `8828` gzip.

Browser status at this checkpoint: Playwriter session `53` verified the target Payments settings provider list still loads, found only instant payouts in the live target account, opened the native instant payout detail, confirmed Payout details, bank reference ID, instant-payout no-history copy, no failed network responses, and saved `.agents/scratchpad/sessions/2026-06-15-core-native-payments/data/a4x-native-payout-detail-loaded-after-dataviews.png`. The copy action did update the screen-reader status to `Bank reference ID copied.`, but the Playwriter script then failed on a strict locator because both the page status and WordPress a11y live region contained the same text. This is an automation-script issue, not a product failure, and the browser gate still needs a clean rerun for copy observation, payment detail observation, target log scan, and final evidence recording before A4x can be closed.

## 2026-06-19 18:16 Compaction-Survival Checkpoint

A4x remains implemented in the worktree and not committed. The current uncommitted product scope is payout detail parity plus the payment/transaction detail foundation: frontend payout details, money-movement data/types/pages/tests/styles, the payment-details timeline REST controller coverage, and PHP test helpers.

The clean Playwriter rerun has now covered the target instant payout detail, scoped bank-reference copy action, and a real target payment detail route. Evidence screenshots are `.agents/scratchpad/sessions/2026-06-15-core-native-payments/data/a4x-native-payout-detail-loaded-final.png` and `.agents/scratchpad/sessions/2026-06-15-core-native-payments/data/a4x-native-transaction-detail-loaded-final.png`. Both browser checks had `failedResponses: []`; console output was limited to `JQMIGRATE` and the known broader Chrome `unload` permissions-policy warning. The target account currently exposes only instant payouts, so the non-instant payout DataViews branch remains covered by focused component tests, source checks, and the A4 admin-surface harness rather than live browser data.

Goodall the 4th completed the API contract review with critical 0, high 0, medium 0, verdict approve. It confirmed the timeline route path matches the reference `/wc/v3/payments/timeline/(?P<intention_id>\w+)`, timeline data is proxied through the native API client, fraud enrichment is source-compatible, and charge/PaymentIntent detail routes still return API client responses without broad reshaping.

Parfit the 4th completed the accessibility review with critical 0, high 0, medium 1, verdict request changes. The required fix is in `transaction-details-page.tsx`: timeline errors are currently rendered as a conditional live `StatusMessage`, so the live region may be created with text already present and missed by screen readers. Fix by routing timeline errors through an already-mounted live status or by keeping a dedicated mounted timeline live region and rendering the visible timeline error as non-live.

Remaining before A4x closeout: fix the timeline-error live-region issue, rerun the affected focused Jest/a11y tests plus the relevant green gates, inspect the saved browser screenshots at a usable size for overlap/layout issues, scan target logs for fresh PHP/WP notices and warnings from the browser window, add the WooCommerce changelog entry if still needed, update this log/staging-log with final evidence, and commit only after all gates remain green.

## 2026-06-19 18:34 Compaction-Survival Update

The Parfit accessibility finding is now fixed in the worktree. A RED test first proved the previous behavior by showing a timeline error created a new `alert` while the already-mounted page status stayed at `Transaction details loaded.` The implementation now routes `timelineErrorMessage` through the stable `LiveStatusMessage` page status region and renders the visible timeline error as non-live, avoiding a late-mounted live region and duplicate announcements. Carver the 4th re-reviewed the fix and approved with critical 0, high 0, medium 0.

Current green gates after the a11y fix: focused transaction-detail Jest passed with 20 tests; focused A4x admin Jest passed with 3 suites and 37 tests; admin `ts:check` passed; targeted admin ESLint passed after formatter cleanup; targeted Stylelint passed; focused PHP `WooPaymentsMoneyMovementRestControllerTest` passed with 32 tests and 213 assertions; changed-file PHPCS passed; PHPStan passed for the touched WooPayments detail/report production PHP; `build:admin` passed with only existing unrelated webpack cache serialization warnings; `a4-admin-surface-gate.py` passed and wrote `data/a4x-admin-surface-gate-green.json`; changelog validation passed; and `git diff --check -- . ':!.agents'` passed.

Current browser/log evidence after the a11y fix: Playwriter verified the target instant payout detail copy action and a real target payment detail route again, with post-fix screenshots at `data/a4x-native-payout-detail-loaded-post-a11y.png` and `data/a4x-native-transaction-detail-loaded-post-a11y.png`. Visual inspection found no layout overlap or clipping. Browser failed responses were empty for the actual route checks; console output was limited to `JQMIGRATE` and the known Chrome `unload` permissions-policy warning. A direct authenticated probe for payout IDs generated one `/payments/deposits?per_page=5` 500/400 because the raw probe omitted the required sort shape; that is recorded as automation noise, not product-route evidence. A narrowed target/local-WPCOM log scan after the actual route checks produced no PHP notices, warnings, deprecations, fatals, uncaught exceptions, stack traces, database errors, or fresh 4xx/5xx markers.

Current committed cleanup: branch-level lint had exposed earlier committed A4w Reports PHPCS issues, so those were fixed and committed separately as `34da4a790f` (`chore(payments): fix reports branch lint`). That commit is not the A4x product commit.

Current uncommitted A4x tracked files: `plugins/woocommerce/client/admin/client/woopayments/admin/money-movement/data.ts`; `plugins/woocommerce/client/admin/client/woopayments/admin/money-movement/transaction-details-page.tsx`; `plugins/woocommerce/client/admin/client/woopayments/admin/money-movement/types.ts`; `plugins/woocommerce/client/admin/client/woopayments/admin/payout-details.tsx`; `plugins/woocommerce/client/admin/client/woopayments/admin/style.scss`; `plugins/woocommerce/client/admin/client/woopayments/admin/test/money-movement-data.test.ts`; `plugins/woocommerce/client/admin/client/woopayments/admin/test/money-movement-pages.test.tsx`; `plugins/woocommerce/client/admin/client/woopayments/admin/test/payout-details-page.test.tsx`; `plugins/woocommerce/src/Internal/Payments/Providers/WooPayments/WooPaymentsPaymentDetailsRestController.php`; `plugins/woocommerce/tests/php/src/Internal/Payments/Providers/WooPayments/RecordingMoneyMovementApiClient.php`; `plugins/woocommerce/tests/php/src/Internal/Payments/Providers/WooPayments/WooPaymentsMoneyMovementRestControllerTest.php`; and `plugins/woocommerce/changelog/add-native-payments-a4x-money-detail-parity`.

The pending branch-level lint gate has now been rerun with output captured to `$TMPDIR/a4x-branch-lint-rerun.log`. It exited `0`; the output is the existing broad branch JS ignored-file warning pattern, `0 errors`, and PHP branch lint clean. No new code fix was required from this gate.

## 2026-06-19 18:44 Closeout

A4x is now committed locally as source/tests commit `a979c7ead5` (`feat(payments): add native money detail parity`) and changelog commit `43d5ced7e5` (`chore(payments): add money detail parity changelog`). The A4x product/changelog range is `34da4a790f...43d5ced7e5`; the preceding `34da4a790f` commit is the separate Reports branch-lint cleanup and not part of the A4x product diff.

Post-commit checks passed: `pnpm --filter=@woocommerce/plugin-woocommerce lint:changes:branch` exited `0` with the existing broad JS ignored-file warnings and PHP branch lint clean; `pnpm --filter=@woocommerce/plugin-woocommerce changelog validate` exited `0` with existing PHP 8.4 vendor deprecation noise; `git diff --check -- . ':!.agents'` returned clean; and `git status --short` returned no product changes. No push was attempted.

A4x remains a bounded reopened-A4 parity slice, not the final A4/N12 exit gate. Remaining wider A4 work includes the tracked dispute challenge/new-evidence wizard parity and any broader detail/action/copy/content parity the final A4 gate still surfaces.
