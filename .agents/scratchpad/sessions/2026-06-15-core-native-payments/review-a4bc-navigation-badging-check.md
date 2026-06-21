---
session: 2026-06-15-core-native-payments
type: review
by: subagent:navigation-badging-explorer
created: 2026-06-21 01:43
target: A4bc navigation and badging check after A4at/A4bb
reconciles:
  - supervisor-prompt-2026-06-18-2344-N12.md
  - staging-log.md
  - analysis-a4bc-next-slice-selection.md
last_updated: 2026-06-21 02:16
status: final
---

# A4bc Navigation And Badging Check

> **Prompt:** "Read-only explorer task for the WooCommerce Core native WooPayments merge.
>
> Workspace: /Users/vladolaru/Work/a8c/woocommerce-develop-2 on branch exp/core-native-payments.
> Reference WooPayments client path: /Users/vladolaru/Work/a8c/woocommerce-payments. Treat it read-only.
> Do not access any WPCOM sandbox, do not edit WPCOM, do not use network. Do not edit WooCommerce product code. You may write exactly one scratchpad report in this repo: .agents/scratchpad/sessions/2026-06-15-core-native-payments/review-a4bc-navigation-badging-check.md
>
> Context: A4at closed provider-route reachability by making WooPayments native routes live under Core Settings > Payments provider subroutes and preserving plugin-era /payments/* compatibility redirects. A4bb just closed General settings control parity. N12 still contains broad language about persistent admin navigation and menu/badging, but A4at also recorded an explicit setup-required badge disposition. We need a fresh source-backed check before treating navigation/badging as closed, parked, or the next slice.
>
> Your task:
> 1. Inspect current native admin/menu/route code in WooCommerce Core and relevant reference WooPayments admin menu code in /Users/vladolaru/Work/a8c/woocommerce-payments.
> 2. Verify whether N12 persistent navigation and menu badge requirements remain source-backed blockers after A4at/A4bb, or whether current native route ownership and A4at disposition cover them sufficiently under the user's architectural direction that WooPayments-specific routes should live as Core Settings > Payments provider subroutes.
> 3. Pay attention to provider-enabled gating, account-state gating, direct admin route reachability, persistent menu/submenu behavior, disputes/transactions badge behavior, and setup/action badges. Do not assume the reference plugin top-level Payments menu must be mechanically ported if that conflicts with the native provider route architecture; analyze and recommend the clean Core-native disposition.
> 4. Write your report with YAML frontmatter:
> ---
> session: 2026-06-15-core-native-payments
> type: review
> by: subagent:navigation-badging-explorer
> created: 2026-06-21 01:43
> target: A4bc navigation and badging check after A4at/A4bb
> reconciles:
>   - supervisor-prompt-2026-06-18-2344-N12.md
>   - staging-log.md
>   - analysis-a4bc-next-slice-selection.md
> status: final
> ---
> 5. In the report include: current native behavior, reference behavior, source-backed gaps if any, recommended disposition, and whether this should block A4bc or become a separate later slice. Keep paragraphs unwrapped and do not lint .agents.
> 6. Return a concise summary and the report path."

## Current Native Behavior

Core now has a dedicated native WooPayments admin navigation controller: `plugins/woocommerce/src/Internal/Admin/Settings/PaymentsProviders/WooPayments/WooPaymentsAdminNavigationController.php`. It registers only when `NativePaymentsRuntimeArbiter::should_native_register()` is true, hooks `admin_menu` at priority 70 after the Core Payments menu, registers legacy redirects on `admin_init`, and preloads `woopaymentsSettings.adminRouteAvailability` only on `page=wc-settings&tab=checkout` requests. This means native navigation is gated by native runtime ownership and `manage_woocommerce`, not by the standalone WooPayments plugin runtime.

The persistent native submenu lives under the Core Payments parent slug, `admin.php?page=wc-settings&tab=checkout&from=payments_menu_item`, and every submenu URL uses `Utils::wc_payments_settings_url()` with canonical Settings > Payments `path=/woopayments/...` provider routes plus `from=payments_menu_item`. Full valid-account navigation includes Overview, Payouts, Transactions, optional Reports, Disputes, optional Card Readers, optional Capital Loans, optional Documents, and Settings. Rejected and under-review accounts get a reduced persistent menu of Overview, Transactions, and Disputes. Not-connected accounts get Onboarding; connected accounts with missing details get Continue onboarding. The tests assert the full menu shape and canonical URLs, including the explicit absence of `wc-admin&path=/payments` menu targets.

Provider-enabled gating is now intentionally split from account-state reachability. `get_admin_route_availability()` still exposes `gatewayEnabled`, but allowed route decisions come from account state: settings/fraud routes are always allowed, onboarding is allowed for setup-needed accounts, overview/transactions/disputes are allowed for full or restricted accounts, payouts/details/reports/card-readers/loans/documents are full-account only and feature/account-predicate gated. The regression tests assert that a disabled gateway with a valid account still gets full route availability and menu entries, so A4at closed the earlier "gateway disabled strands the merchant" problem.

Direct route reachability is covered in two layers. PHP redirects legacy plugin-era `wc-admin&path=/payments/*` URLs into Settings > Payments provider URLs, dynamically mapping setup-era aliases to onboarding, overview, or settings based on current route availability. The React registry in `plugins/woocommerce/client/admin/client/woopayments/admin/routes.tsx` registers 15 `/woopayments/*` provider routes through `registerSettingsPaymentsProviderRoute()`: settings, express settings, fraud settings, overview, payouts, payout details, transactions, transaction details, reports, disputes, dispute details, dispute challenge, card readers, loans, and documents. Protected dashboard routes check `window.wcSettings.admin.woopaymentsSettings.adminRouteAvailability.allowedRoutes` before loading their chunks and render "This WooPayments admin area is unavailable." when denied or missing. Tests cover the full registered route list, absence of `/payments/*`, denied direct routes, missing route availability, and restricted-account route availability.

Onboarding is the one non-obvious path. `/woopayments/onboarding` is not one of the 15 explicit provider route registrations in `woopayments/admin/routes.tsx`, but it is still source-backed reachable under the same Settings > Payments URL. The WooPayments provider returns a native in-context onboarding link to `Utils::wc_payments_settings_url( '/woopayments/onboarding', ... )`, the main Settings Payments router falls through unknown provider paths to `SettingsPaymentsMain`, and the WooPayments onboarding modal opens itself whenever the query `path` includes `/woopayments/onboarding`. This preserves direct onboarding URL reachability without resurrecting a plugin-era WC Admin page.

Disputes and Transactions menu badges are implemented natively. `WooPaymentsAdminNavigationController::get_disputes_menu_item()` appends a count badge and `filter=awaiting_response` when `WooPaymentsAdminMenuBadgeService::get_disputes_awaiting_response_count()` is positive; `get_transactions_menu_item()` appends a count badge when `get_uncaptured_transactions_count()` is positive. The badge service reads the same preserved database cache keys as the plugin-era behavior for dispute status counts and authorization summary, uses test-mode cache keys when test mode is enabled, sums only `needs_response` plus `warning_needs_response`, gates transaction counts behind manual capture, preserves stale valid data on refresh failures, and suppresses repeated cold refresh failures for a short TTL. Native PHPUnit covers actionable dispute summing, stale-cache fallback, failure behavior, test-mode authorization cache keys, dispute submenu filter insertion, zero-count badge omission, and uncaptured transaction badge rendering.

The plugin-era setup-required top-level "1" badge is not ported to the native WooPayments submenu. A4at explicitly recorded that disposition, and current Core source supports it: native WooPayments does not own a separate plugin top-level Payments menu, the provider details expose native in-context onboarding URLs, and the native account settings UI shows account readiness/action state such as "Payments need attention" plus a setup CTA when account setup is missing. Core's generic `PaymentsController` still owns the top-level Core Payments menu and can add a generic provider-incentive badge, but that is separate from the WooPayments plugin-era setup badge.

## Reference WooPayments Behavior

The reference plugin's `WC_Payments_Admin::add_payments_menu()` registers a WooPayments-owned top-level Payments WC Admin category with `wc_admin_register_page()`, using `/payments/overview` for full accounts and `/payments/connect` otherwise. It defines persistent child pages for Overview, Payouts, Transactions, Disputes, optional Reports, optional Card Readers, optional Capital Loans, optional Documents, and a Settings submenu that directly links to the WooPayments settings URL. Rejected and under-review accounts get only Overview, Transactions, and Disputes plus hidden detail pages; not-connected accounts get Onboarding; connected-but-details-missing accounts get Continue onboarding. All of this is `manage_woocommerce` gated.

The reference plugin mutates global admin menu arrays for three badge families after menu registration. `add_menu_notification_badge()` appends a hardcoded count-1 badge to the top-level `/payments/connect` menu after three days from activation unless `wcpay_menu_badge_hidden=yes`, with force-show behavior for broken Jetpack/connected-account or connected-invalid-account states and force-hide when the account is valid. `add_disputes_notification_badge()` appends an unresolved count to the Disputes submenu and rewrites its URL to add `filter=awaiting_response`, using status counts for `needs_response` and `warning_needs_response`. `add_transactions_notification_badge()` appends an unresolved count to Transactions when manual capture is enabled and uncaptured authorizations exist.

The reference owns `/payments/*` as WC Admin routes and page IDs. That is deliberately not the native ownership model after A4at; native preserves `/payments/*` as compatibility aliases that redirect into Settings > Payments provider routes.

## Source-Backed Gaps

I do not see a remaining source-backed A4bc blocker for N12's persistent navigation requirement after A4at. Native now has persistent WP admin submenus for the same merchant-facing surface set that is currently available in Core, uses `manage_woocommerce`, uses native runtime ownership, avoids gateway-enabled reachability suppression, and uses account-state variants aligned with the reference reduced/full/onboarding menu concepts. The clean native interpretation is "Core Payments top-level parent, WooPayments provider submenus pointing into `/woopayments/*` Settings > Payments routes", not "mechanically port the plugin-owned top-level `/payments/*` hierarchy."

I do not see a remaining source-backed A4bc blocker for Disputes and Transactions badge behavior. The native implementation preserves the merchant-facing badge semantics that matter for those two submenus: awaiting-response dispute count, awaiting-response filter link, manual-capture uncaptured transaction count, zero-count omission, test-mode cache separation, and cache failure tolerance.

The setup-required top-level badge remains intentionally absent. That is a difference from the reference plugin, but it is not a route reachability or submenu parity blocker under the current architecture. Porting the plugin badge literally would require badging a Core-owned Payments top-level parent or inventing a provider-level attention badge under Core Settings > Payments. That should be treated as a separate Core product/navigation decision, not as required cleanup for A4bc. A future slice could add a Core-native provider attention signal if browser/product review proves the existing provider row/account settings "needs attention" signals are insufficient, but the present source does not justify blocking the next slice on it.

The only nuance worth recording is onboarding route ownership. `/woopayments/onboarding` is reachable through the Settings Payments catch-all plus onboarding modal synchronization, not as an explicit provider route entry in `woopayments/admin/routes.tsx`. Because both the provider details and persistent menu point to the Settings > Payments URL and the modal listens for that path, this is source-backed reachable. If the architectural rule is interpreted literally as "every WooPayments path must be in the provider-route registry", then onboarding registration could be a later cleanup; I would not treat it as a navigation blocker because the current code path is deliberate and tested indirectly through provider/onboarding URLs.

There is also a deliberate menu-vs-direct split for restricted accounts: the persistent menu hides Settings and Fraud Protection for rejected/under-review accounts, while route availability leaves settings/fraud routes loadable. That is acceptable in the native model because the provider settings surface is the account/control hub and direct settings links can remain safe, while persistent merchant navigation stays reduced like the reference.

## Recommended Disposition

Treat N12 Surface 1 persistent navigation as closed for A4bc purposes. The native Core disposition is: keep Core's top-level Payments parent, keep WooPayments-specific dashboard surfaces under Settings > Payments provider subroutes, keep plugin-era `/payments/*` as redirects only, and keep persistent submenus as deep links into those canonical provider routes.

Treat Disputes and Transactions badging as closed. Current native source and tests cover the two reference badge behaviors with equivalent merchant-visible outcomes.

Treat the setup-required top-level badge as parked, not open A4bc work. A4at already recorded the correct architectural decision: do not resurrect the plugin-era badge mechanically. If desired, track a later product slice for a Core Payments/provider-row attention badge, informed by browser evidence of not-ready accounts, but do not couple that to provider-route reachability.

Do not port the reference plugin top-level Payments WC Admin category as-is. That would conflict with the user's direction that WooPayments-specific native routes live under Core Settings > Payments provider subroutes and would reintroduce plugin-era route ownership that A4at intentionally converted into compatibility redirects.

## A4bc Blocking Assessment

This should not block A4bc. The next A4bc slice should proceed with the remaining source-backed merchant-facing settings polish/copy/loading work identified by the other explorer, while navigation/badging remains closed except for an explicitly separate later product decision around Core-native setup/action attention signals.

No product code was edited and no network/WPCOM access was used. This report is based on local source inspection only.
