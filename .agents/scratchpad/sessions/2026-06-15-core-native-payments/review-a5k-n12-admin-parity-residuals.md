---
session: 2026-06-15-core-native-payments
type: review
by: subagent:codex-a5k-admin-parity-auditor
created: 2026-06-21 03:29
tool: woocommerce-code-review
target: N12/A5k admin parity residual audit
reconciles:
  - supervisor-prompt-2026-06-18-2344-N12.md
  - staging-log.md
  - spec-conformance-baseline.md
  - analysis-a4bd-post-a4bc-accumulated-gate-refresh.md
  - analysis-a4as-admin-readiness-decision.md
  - analysis-a5j-release-default-on-readiness.md
last_updated: 2026-06-21 03:32
status: final
---

# A5k N12 Admin Parity Residual Audit

> **Prompt:** "Read-only A5k N12/admin parity residual audit for WooCommerce Core native WooPayments merge.
>
> Workspace: /Users/vladolaru/Work/a8c/woocommerce-develop-2 on branch exp/core-native-payments.
> Session docs: .agents/scratchpad/sessions/2026-06-15-core-native-payments.
> Reference plugin source is /Users/vladolaru/Work/a8c/woocommerce-payments for source comparison only.
>
> Constraints:
> - Do not edit product code or harness code.
> - Do not access WPCOM sandbox or modify WPCOM. Local source/docs reads only.
> - Do not push or commit.
> - Do not lint .agents.
> - Keep scratchpad prose unwrapped.
>
> Task:
> Check whether N12 still exposes any source-backed local A4 admin parity work after the recorded A4av-A4bd/A5j evidence. Focus on current source and recorded gates, not speculation. Read at least:
> - .agents/scratchpad/sessions/2026-06-15-core-native-payments/supervisor-prompt-2026-06-18-2344-N12.md
> - .agents/scratchpad/sessions/2026-06-15-core-native-payments/staging-log.md A4av through A4bd plus A5h/A5j entries
> - .agents/scratchpad/sessions/2026-06-15-core-native-payments/spec-conformance-baseline.md A4bd/A4as/A5j addenda
> - current native admin/settings source under plugins/woocommerce/src/Internal/Admin/WooPayments, plugins/woocommerce/src/Internal/Payments/Providers/WooPayments, plugins/woocommerce/client/admin/client/woopayments, plugins/woocommerce/client/admin/client/settings-payments, and plugins/woocommerce/client/woopayments/settings.
>
> Questions:
> - Are native WooPayments admin surfaces reachable through persistent admin navigation with provider/account gating, or is there still an orphaned-route gap?
> - Does current evidence prove N12's functional/visual/copy parity enough for local A4 exit, or is there a concrete untracked local parity slice?
> - Is `FILTER_NATIVE_ADMIN_SURFACES_READY` defaulting still defensible against current A4bd/A5j evidence, or should it be fail-closed again?
>
> Output:
> Write .agents/scratchpad/sessions/2026-06-15-core-native-payments/review-a5k-n12-admin-parity-residuals.md with scratchpad frontmatter (session exact, type: review, by: subagent:<your name>, created current local time, status: final). Include verdict: NO_LOCAL_A4_BLOCKER / LOCAL_A4_BLOCKER_FOUND / EVIDENCE_TOO_WEAK. Cite exact source/artifact evidence and avoid broad visual claims unless backed by a recorded browser artifact."

## Verdict

NO_LOCAL_A4_BLOCKER.

I do not see a source-backed N12/A4 admin parity residual that should reopen local product work after A4av-A4bd and A5j. Current source provides persistent native WooPayments admin navigation under the Core Payments parent, with `manage_woocommerce`, native runtime ownership, account-state route availability, optional route predicates, legacy `/payments/*` redirects, and React protected routes for the canonical Settings > Payments provider paths. The recorded gates are sufficient for local A4 exit under their stated limitations. They are not unlimited visual parity proof, but I found no concrete untracked local parity slice stronger than the limitations already recorded. `WooPaymentsCutoverController::FILTER_NATIVE_ADMIN_SURFACES_READY` defaulting to ready remains defensible after A4as/A4bd/A5j; failing it closed again would contradict the current A4as product decision and the later green accumulated/local packets. Runtime rollout and mandatory cutover defaults remain separately fail-closed.

## Required Path Notes

The requested `plugins/woocommerce/src/Internal/Admin/WooPayments` path does not exist in the current checkout. The native admin PHP source lives under `plugins/woocommerce/src/Internal/Admin/Settings/PaymentsProviders/WooPayments/`, especially `WooPaymentsAdminNavigationController.php`. The requested `plugins/woocommerce/client/woopayments/settings` path also does not exist; the native settings source lives under `plugins/woocommerce/client/admin/client/woopayments/settings/` and provider routing under `plugins/woocommerce/client/admin/client/settings-payments/`.

## Reachability And Gating

Native WooPayments surfaces are not orphaned now. `plugins/woocommerce/includes/class-woocommerce.php:400-402` registers the Core Payments controller, native WooPayments controller, and `WooPaymentsAdminNavigationController`. `WooPaymentsAdminNavigationController::register()` returns unless `NativePaymentsRuntimeArbiter::should_native_register()` is true, then attaches `admin_menu`, legacy redirects, VAT redirect bridge, and shared-settings preloading (`plugins/woocommerce/src/Internal/Admin/Settings/PaymentsProviders/WooPayments/WooPaymentsAdminNavigationController.php:146-166`). `add_menu_items()` requires `manage_woocommerce` and native ownership (`:468-474`) before appending submenus under the Core Payments parent slug (`:641-680`).

The persistent submenu covers the same core merchant surface set in the native route model. Full valid-account navigation includes Overview, Payouts, Transactions, optional Reports, Disputes, optional Card Readers, optional Capital Loans, optional Documents, and Settings (`WooPaymentsAdminNavigationController.php:526-574`). Rejected/under-review accounts get reduced Overview, Transactions, and Disputes (`:582-590`). Not-ready accounts get Onboarding or Continue onboarding (`:491-518`). The route availability payload gates direct route loading by account state and feature/account predicates: settings and fraud settings are always allowed, onboarding is setup-state-only, overview/transactions/disputes are protected-account routes, payouts/details/reports/card-readers/loans/documents require full account plus their feature predicates (`:407-435`).

Provider-enabled gating is deliberately represented as data, not as blanket route suppression. `WooPaymentsAccountService::is_gateway_enabled()` reads the persisted gateway `enabled` setting (`plugins/woocommerce/src/Internal/Payments/Providers/WooPayments/WooPaymentsAccountService.php:944-946`), and `get_admin_route_availability()` exposes `gatewayEnabled` (`WooPaymentsAdminNavigationController.php:407-418`). Current tests explicitly assert that a disabled gateway with a valid account still gets account-state route availability and menu entries (`plugins/woocommerce/tests/php/src/Internal/Admin/Settings/PaymentsProviders/WooPayments/WooPaymentsAdminNavigationControllerTest.php:271-308`, `:756-784`, `:827-856`). That is not a hidden orphan gap; it is the recorded source behavior after A4at/A4bc, preserving merchant access instead of stranding the admin surfaces when processing is disabled.

Legacy plugin routes are compatibility aliases, not native ownership paths. `WooPaymentsAdminNavigationController` maps `/payments/connect`, `/payments/overview`, `/payments/payouts`, `/payments/transactions`, `/payments/disputes`, `/payments/reports`, `/payments/card-readers`, `/payments/loans`, `/payments/documents`, `/payments/settings`, `/payments/fraud-protection`, and related details paths to `/woopayments/*` Settings > Payments provider routes (`WooPaymentsAdminNavigationController.php:79-101`, `:269-320`). The React route registry registers `/woopayments/settings`, express detail routes, fraud protection, overview, payouts, payout details, transactions, transaction details, reports, disputes, dispute details, dispute challenge, card readers, loans, and documents through `registerSettingsPaymentsProviderRoute()` (`plugins/woocommerce/client/admin/client/woopayments/admin/routes.tsx:214-373`), and protected dashboard routes use the preloaded `adminRouteAvailability.allowedRoutes` before lazy-loading chunks (`:154-196`). The registry itself sorts and uniqueness-checks provider routes (`plugins/woocommerce/client/admin/client/settings-payments/provider-routes.tsx:49-66`), and `register-provider-routes.ts` imports the WooPayments admin routes (`plugins/woocommerce/client/admin/client/settings-payments/register-provider-routes.ts:1-4`).

The reference plugin still uses a different ownership model: `WC_Payments_Admin::add_payments_menu()` registers a WooPayments-owned top-level `Payments` WC Admin category with `/payments/*` child pages and `manage_woocommerce` (`/Users/vladolaru/Work/a8c/woocommerce-payments/includes/admin/class-wc-payments-admin.php:320-411`, `:413-480`, `:483-628`). Native intentionally does not port that page tree as-is; it keeps Core's Payments parent and deep-links WooPayments submenus to canonical `/woopayments/*` provider routes. That matches the A4bc navigation/badging review disposition, which found Surface 1 persistent navigation closed for A4bc under the Core-native route model and parked only the plugin-era setup badge as a separate product/navigation decision (`review-a4bc-navigation-badging-check.md`).

## Functional, Visual, And Copy Evidence

A4av through A4bc closed the concrete settings residuals from the N12 supervisor prompt in focused slices with tests, browser/log evidence, and reviews: payment-method guidance (`staging-log.md:1312-1321`), VAT modal/deep link (`:1323-1332`), express checkout flags/notices/detail pages (`:1334-1344`), fraud onboarding/tracking (`:1346-1354`), fraud ruleset refresh/defaulting (`:1356-1364`), advanced fraud UI (`:1366-1376`), general settings controls (`:1378-1388`), and loading/docs/copy parity (`:1390-1400`). Current source reflects those closures: rich payment-method rows include fee descriptions/tooltips, promotions, duplicate notices, activation modal, pending/missing-currency guidance, and Overview links (`plugins/woocommerce/client/admin/client/woopayments/settings/payment-methods-list.tsx` hits around `DuplicatePaymentMethodNotice`, `PaymentMethodActivationModal`, fee tooltip code, and `getPaymentMethodAvailability`); settings lazy-loads the VAT modal and handles VAT unavailable/already-submitted/updated notices (`plugins/woocommerce/client/admin/client/woopayments/settings/settings-page.tsx:105-109`, `:2402-2563`); express checkout has detail routes plus Apple Pay / Google Pay, WooPay, Amazon Pay settings and preview assets (`plugins/woocommerce/client/admin/client/woopayments/settings/settings-page.tsx:1181-1389`, `plugins/woocommerce/client/admin/client/woopayments/settings/express-checkout/`); fraud settings provide Basic/Advanced radio-style control, Basic help modal, Configure link, tour, advanced loading cards, rules, threshold controls, and save busy state (`plugins/woocommerce/client/admin/client/woopayments/settings/fraud-protection/index.tsx:97-316`, `plugins/woocommerce/client/admin/client/woopayments/settings/fraud-protection/advanced/index.tsx:810-1227`); payouts settings include the payout bank-account block and Manage in Stripe copy (`plugins/woocommerce/client/admin/client/woopayments/settings/settings-page.tsx:1960-1961`, `plugins/woocommerce/client/admin/client/woopayments/settings/payout-bank-account.tsx:40-123`); and the settings root uses section placeholders, screen-reader status, `aria-busy`, and `SettingsBusyState` (`plugins/woocommerce/client/admin/client/woopayments/settings/settings-page.tsx:456-627`, `:2516-2563`, `plugins/woocommerce/client/admin/client/woopayments/settings/settings-busy-state.tsx:8-29`).

The accumulated A4bd gate is the current local browser/gate baseline after those focused slices. `data/a4bd-post-a4bc-accumulated-gate/a4aq-accumulated-gate.json` reports `status=pass`, 13 checks, `failures=[]`, and `incomplete=[]`, with limitations that six target protected-route checks were unavailable-guard passes and reference log scan had five diagnostic lines. The base admin browser gate recorded 57 results and no failures; settings, express detail routes, fraud protection, overview, payouts, transactions, uncaptured, disputes, and reports had `available-route-pass` records with empty `missingTokens` on desktop and mobile, while Documents/Card Readers/Capital were explicitly `unavailable-guard-pass` for the current target account. The optional-admin gate then recorded seven target-only checks with Documents/Card Readers/Capital as `available-route-pass` after synthetic target account flags, also with no failures. This is reachability/token proof plus focused-slice backing, not exhaustive reference visual proof.

A4bd's own review correctly constrained the claim: `review-a4bd-accumulated-gate.md` returned `PASS_WITH_LIMITATIONS`, found no source-backed product blocker, and warned not to overclaim full optional route content parity, exact perf parity, exhaustive checkout parity, or deep re-verification of every A4av-A4bc copy/control from A4bd alone. `staging-log.md:1402-1412` and `spec-conformance-baseline.md:502-510` record the same limitations. I am preserving that boundary here: current evidence is sufficient for local A4 exit because no concrete untracked local parity slice is source-backed, not because the recorded artifacts prove every pixel of every optional surface in every account state.

## Cutover Readiness Default

`FILTER_NATIVE_ADMIN_SURFACES_READY` defaulting to ready remains defensible. The original N12 prompt argued for fail-closed because A5a/A5d had relied on route/registry/bundle gates without merchant reachability or functional/copy/visual parity. That was true at the time (`supervisor-prompt-2026-06-18-2344-N12.md`). The later baseline supersedes that state: A4as records the product decision that, after the accumulated A4/N12 gate had no failures and no incomplete checks, `WooPaymentsCutoverController::FILTER_NATIVE_ADMIN_SURFACES_READY` could default true as a decision scoped only to native admin-surface readiness (`spec-conformance-baseline.md:472-480`). A4bd refreshed the accumulated A4/N12 gate after A4av-A4bc without changing product code and kept it green under limitations (`spec-conformance-baseline.md:502-510`). A5j then refreshed the local release/default-on packet and recorded local A4/A5 readiness and cutover mechanics as locally proven while keeping production/default-on blocked on release-only evidence (`spec-conformance-baseline.md:534-546`, `review-a5j-release-default-on-requirements.md`).

Current source matches that posture. `WooPaymentsCutoverController::get_preflight_failures()` now calls `apply_filters( self::FILTER_NATIVE_ADMIN_SURFACES_READY, true )` and only adds `native_admin_surfaces_unavailable` when the filter returns false (`plugins/woocommerce/src/Internal/Payments/Providers/WooPayments/WooPaymentsCutoverController.php:410-419`). Tests preserve both behaviors: explicit false still blocks preflight and suppresses the notice (`plugins/woocommerce/tests/php/src/Internal/Payments/Providers/WooPayments/WooPaymentsCutoverControllerTest.php:580-593`), while the default no longer reports `native_admin_surfaces_unavailable` after the N12 parity gate passes (`:595-610`).

This admin-surface default is separate from production rollout defaults. `NativePaymentsRuntimeArbiter::DEFAULT_NATIVE_RUNTIME_ENABLED` remains false until release/default-on gates approve the flip (`plugins/woocommerce/src/Internal/Payments/NativePaymentsRuntimeArbiter.php:86-104`, `:177-185`), and `WooPaymentsCutoverController::DEFAULT_MANDATORY_CUTOVER_ENABLED` remains false (`plugins/woocommerce/src/Internal/Payments/Providers/WooPayments/WooPaymentsCutoverController.php:47-54`). A5j target proof records native ownership, standalone WooPayments inactive, empty preflight failures, and `ready: true` in `data/a5j-target-cutover-state.json`, while A5f rerun records `status=pass`, 27 phase results, `failures=[]`, native-owned baseline/post-soft/restored states, standalone plugin inactive, empty preflight failures, and final target debug log at 0 bytes. A5g rerun records `status=pass`, `runtime_mode=existing-tests`, 28 phase results, and `failures=[]` for multisite ownership transitions. These support local cutover readiness recency; they do not authorize production default-on.

## Answered Questions

Are native WooPayments admin surfaces reachable through persistent admin navigation with provider/account gating, or is there still an orphaned-route gap? They are reachable through persistent Core Payments submenus and canonical Settings > Payments `/woopayments/*` provider routes when native owns the runtime and the user has `manage_woocommerce`. Account-state and optional-surface predicates gate route availability. Provider enabled state is exposed as `gatewayEnabled` but intentionally does not suppress all account-state admin access; tests lock that behavior. I do not see an orphaned-route gap.

Does current evidence prove N12's functional/visual/copy parity enough for local A4 exit, or is there a concrete untracked local parity slice? It proves enough for local A4 exit under the recorded limitations. A4av-A4bc provide focused source/test/browser/review backing for the specific settings residuals, A4bd passed the accumulated gate with zero failures/incomplete checks, and later A5i/A5j found no source-backed local product/gate slice remaining before release decision. I found no concrete untracked local A4 parity slice. The honest limitation remains that A4bd is not exhaustive visual proof for every optional/account-state surface.

Is `FILTER_NATIVE_ADMIN_SURFACES_READY` defaulting still defensible against current A4bd/A5j evidence, or should it be fail-closed again? It is still defensible. Fail-closing it again is not source-backed by the current A4bd/A5j evidence. The explicit false override remains available and tested, while runtime rollout and mandatory cutover defaults remain separately fail-closed for production/default-on.

## Hygiene

No product code, harness code, WPCOM sandbox, WPCOM source, push, commit, or `.agents` linting was used for this audit. `git status --short --branch --untracked-files=all` showed only `exp/core-native-payments...trunk [ahead 341]` plus ignored scratchpad state, and `git diff -- . ':!.agents' --stat` produced no output.
