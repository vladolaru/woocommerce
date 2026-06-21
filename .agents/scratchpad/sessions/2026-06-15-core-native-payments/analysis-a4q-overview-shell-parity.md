---
session: 2026-06-15-core-native-payments
type: analysis
by: codex
created: 2026-06-19 08:35
target: A4q native WooPayments Overview shell parity
reconciles:
  - supervisor-prompt-2026-06-18-2344-N12.md
  - analysis-a4p-pm-promotions-spotlight-parity.md
  - spec-conformance-baseline.md
last_updated: 2026-06-19 08:44
status: draft
---

# A4q Overview Shell Parity

> **Prompt:** "Continue working toward the active thread goal."

N12 remains active after A4p. A4p closed source-backed PM promotions and spotlight, but the native Overview route still has major merchant-facing parity gaps. Native admin readiness remains fail-closed.

## Source-Backed Gap

Native Overview currently renders a simplified account card plus two simplified payout cards in `plugins/woocommerce/client/admin/client/woopayments/admin/overview/page.tsx`, `components/account-balances-card.tsx`, and `components/payouts-overview-card.tsx`. It consumes only `/wc/v3/payments/deposits/overview-all`, recent deposits, and the narrow `/wc-admin-settings-payments-woopayments/account` summary through `WooPaymentsAccountSettings`.

The reference Overview in `/Users/vladolaru/Work/a8c/woocommerce-payments/client/overview/index.js` renders a broader shell: error/query notices, Jetpack IDC notice, sandbox/test-mode notice, Welcome, embedded Stripe notification banner, dismissible task list, rich account balances, deposits overview with schedule/notices/history/footer actions, account details with status/payout chips and account fees, dispute readiness, active loan summary, inbox notifications, connection success modal, and spotlight. The reference bootstrap data is assembled in `/Users/vladolaru/Work/a8c/woocommerce-payments/includes/admin/class-wc-payments-admin.php` around the account status/details/tasks payload.

Native already has source-backed pieces for a safe first Overview parity slice:

- Account cache/status fields are available through `WooPaymentsAccountService` and mirror the reference `get_account_status_data()` fields (`status`, `payments_enabled`, `details_submitted`, `deposits`, deadlines, account link, requirements, account details, fees, and capital).
- Existing native REST routes already provide deposits overview/list data and PM promotions.
- A4n already adapted the sandbox/test account notice as `AccountModeNotice`, and A4p mounts `SpotlightPromotion`.
- A4o already made the Overview route reachable from persistent admin navigation.

## Slice Decision

A4q should be the native Overview shell parity slice, not a full port of every Overview sub-feature. The coherent boundary is: add a Core-owned Overview projection and render the reference-level top notices, test/sandbox notice, task list, account details card, and richer balance/payout card composition around the existing native deposits APIs.

Keep out of A4q:

- Embedded Stripe notification banner, because it requires a separate native embedded-component session/loading/failure slice and should not be faked.
- Dispute readiness card, Inbox notifications, and connection-success modal, because each has its own data source/side effects and needs a separate source-backed slice.
- Active loan summary beyond preserving the already-existing Capital route. If a simple active-loan summary endpoint is already native and the explorer confirms parity risk is low, it can be folded into A4q only if it does not destabilize the Overview shell.
- Reports/Documents surfaces, which remain a separate N12 admin UI/API slice.

This slice is broad enough to move Overview materially closer to the reference without overreaching into unrelated data domains.

## Architecture Notes

Do not reintroduce `window.wcpaySettings` or plugin stores. Add a native `WooPaymentsOverviewService` that projects the read-only account/status/task/account-details data needed by the Overview shell from native services/options. Expose it through the existing native WooPayments settings REST controller under the Core-owned provider route namespace, for example `GET /wc-admin-settings-payments-woopayments/overview`, while preserving the existing `GET /account` summary for settings.

The frontend should consume this projection from `client/admin/client/woopayments/admin/overview/data.ts` and render focused native components:

- `OverviewNoticeStack`: source-backed query/error notices and account mode notice.
- `OverviewTaskList`: update-business-details, WPCOM reconnect, dispute response, and go-live tasks when projection data says they are visible. Persist dismiss/delete/remind-later through the existing settings option endpoint rather than local-only state.
- `OverviewAccountDetails`: account status/payout status/banner/account fees/actions from `account_details`, with safe fallback when unavailable.
- Updated balance/payout cards: use reference copy for balance labels/tooltips, schedule text, deposit notices, footer actions, and recent payout history while keeping native API routes and scoped styles.

Keep WooPayments-specific CSS imported only by the WooPayments admin chunk. Do not add global admin styles.

## Gates

TDD targets:

- PHP service/controller tests for the Overview projection: account status shape, account details passthrough validation, account fees filtering, task visibility/dismissal option shape, go-live/test-account flags, WPCOM reconnect URL gating, and permission/native-runtime gates.
- JS data tests for the new overview endpoint path and response validation.
- Overview component tests for error notices, account mode notice mount, task list filtering/dismissal behavior, account details fallback/content, richer payout schedule/notices/footer, and no plugin-global dependency.

Browser/runtime gates:

- Use Playwriter on target `:8889` and reference `:8082` Overview routes with the same local account state. Verify top notices, task/account details shell, balance card, payouts card, and absence of layout overflow.
- Run the ignored A4 admin-surface gate after widening it for the Overview projection/shell signals.
- Run focused PHPUnit/Jest, targeted ESLint/Stylelint, `ts:check`, admin build, changed-file PHPCS, PHPStan, changelog validation, `git diff --check -- . ':!.agents'`, branch lint, and target/reference log scans.

## Boundaries

No WPCOM code changes, no WPCOM sandbox access, no standalone WooPayments plugin edits, no push. Do not reintroduce Stripe Billing. Do not claim full N12/A4 readiness from A4q; native admin readiness remains fail-closed until all Overview, money-movement, Card Readers/Capital, Reports/Documents, copy/content, styling, browser, and widened A4 exit gates pass.

## Course Correction

Read-only Overview exploration confirmed the broader shell plan is too likely to mix unrelated account/task/notification data seams with the already source-backed financial cards. The active A4q implementation is now the narrower financial-summary parity slice in `plans/2026-06-19-core-native-payments-a4q-overview-financial-summary-parity.md`. This keeps the reference-level account/task/notice shell as explicit follow-up N12 work while letting this slice close a coherent, testable Overview parity unit: multi-currency balance selection, Total balance / Available funds copy, instant-payout notice, payout schedule copy/help, reference payout notices, recent payout row links/status chips, footer actions, and selected-currency recent-payout loading.
