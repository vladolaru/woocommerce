---
session: 2026-06-15-core-native-payments
type: analysis
by: codex
created: 2026-06-19 22:26
target: A4aa native WooPayments settings contact and advanced-copy parity
reconciles:
  - supervisor-prompt-2026-06-18-2344-N12.md
  - analysis-a4z-admin-exit-residuals.md
  - staging-log.md
status: draft
last_updated: 2026-06-19 22:33
---

# A4aa Settings Contact Validation Parity

## Trigger

A4z is committed and closed. The next A4/N12 slice should not reopen stale missing-surface findings; it should close source-backed residual merchant-facing parity gaps and keep native admin readiness fail-closed.

## Source-Backed Selection

The current native settings page is a rich but still hand-built monolith in `plugins/woocommerce/client/admin/client/woopayments/settings/settings-page.tsx`. A4j-A4p/A4n closed the larger missing sections: payment methods, BNPL, express checkout subpages, fraud protection, sandbox switch-to-live, payout bank account, save busy state, admin badges, and PM promotions. The remaining highest-leverage settings gaps are now concentrated in copy, validation, and helper behavior for the Transactions, Account notifications, and Advanced settings sections.

Reference `client/settings/notification-settings/notifications-email-input.tsx` requires a dedicated "Notifications email" subsection, warning notice, email format validation, and a "Confirm email address" input when the notification email changes. Native `NotificationsSettingsSection` currently renders one plain `TextControl` labeled "Notification email" with no warning, no confirmation field, and no validation gating.

Reference `client/settings/transactions/index.js`, `support-email-input/index.js`, and `support-phone-input/index.js` preserve saved-card platform-storage copy, customer-statement helper copy, support email validation/errors, support phone validation/errors, and test-account phone help copy. Native `TransactionsSettingsSection` currently renders simplified saved-card help, no customer-statement paragraph, plain support email/phone `TextControl`s, no client validation, and no phone input parity.

Reference `client/settings/advanced-settings/multi-currency-toggle.js`, `wcpay-subscriptions-toggle.js`, and `debug-mode.js` preserve multi-currency Learn more copy, a deprecated subscriptions toggle that cannot be newly enabled, and dev-mode debug logging behavior. Native `AdvancedSettingsSection` currently renders simplified labels/help, allows enabling WooPayments subscriptions when eligible, and does not force/disable debug logging when dev mode is enabled.

This is a coherent next slice because all gaps live in one frontend surface and one Jest suite, they are directly merchant-facing, they do not require WPCOM or platform writes, and they give the widened A4/N12 exit gate stable DOM/copy assertions.

## Scope Boundary

A4aa should not hoist the full settings manager or rewrite layout. It should adapt the native settings page to preserve the reference behaviors above using small local helpers where that makes the monolith easier to test. Stripe Billing remains excluded per N9. Express checkout live preview, transaction detail depth, dispute challenge polish, and the final browser-driven A4 exit gate remain follow-up slices unless the current subagent reports show a stronger blocker.

## Verification Shape

Use TDD in `settings-page.test.tsx`: first assert the missing notification confirmation/warning/validation behavior, transaction support validation/helper copy, and advanced dev/subscription copy; confirm the focused Jest suite fails; implement the native behavior; re-run the focused Jest suite. Then run targeted ESLint/Stylelint, admin typecheck/build where affected, `a4-admin-surface-gate.py` for the existing source/chunk baseline, Playwriter target/reference settings checks, and fresh debug/log scans. Keep `WooPaymentsCutoverController::FILTER_NATIVE_ADMIN_SURFACES_READY` fail-closed.

## Agent Finding: A4 Gate Coverage

Newton the 5th ran the current A4 gate read-only. It passed and continues to prove chunk existence, source chunk names, absence of plugin-era route literals in native admin source, absence of private-registry tokens, native route registration through the Settings > Payments provider seam, source-level persistent menu existence, and mocked PHP menu variants. It also confirmed `WooPaymentsCutoverController::FILTER_NATIVE_ADMIN_SURFACES_READY` is fail-closed through `apply_filters( self::FILTER_NATIVE_ADMIN_SURFACES_READY, false )`.

Newton also confirmed the current gate still does not prove real browser reachability through every visible menu item, real account-state parity against reference account variants, copy/layout/browser parity, or the full settings-section parity matrix. Recommended high-signal extensions are a runtime menu/account-state matrix gate, browser reachability clicks, legacy redirect HTTP checks, and reference-vs-target DOM/copy/layout manifests before screenshot diffs. A source-backed cleanup is also available: `routes.tsx` includes Reports/Documents, while `register-provider-routes.test.tsx` still has an expected route list that stops at `/woopayments/loans`.

Decision for A4aa: keep the active product slice on settings contact/advanced parity because it is a coherent merchant-facing residual already verified against source. Add the route-registration expectation cleanup if it is still stale, and keep the broader runtime reachability/account-state gate as a fail-closed A4 exit-gate blocker rather than treating the existing token/chunk gate as sufficient.

## Agent Finding: Browser Gate Matrix

Pascal the 5th produced a no-edit browser/CLI verification matrix for the widened A4/N12 gate. It reinforces that `tools/woopayments-merge/a4-admin-surface-gate.py` is a prerequisite source/chunk/registry guardrail, not the N12 parity gate. The proposed runtime/browser matrix covers provider list, persistent Payments menu, WCPay Dev Tools state preflight, settings main route, express/fraud settings subroutes, Overview, payouts/transactions/disputes lists, fixture-backed payout/payment/dispute details, Reports/Documents, and Card Readers/Capital. Stable assertions should use roles, names, headings, links, control states, loaded route-specific native chunks, no target plugin `dist` scripts, no failed network responses, and no unexpected console errors.

The log gate should snapshot start UTC and debug-log offsets, then scan target/reference debug logs and store/WPCOM container logs only for new entries. Fail on PHP fatals, warnings, notices, deprecations, parse errors, `_doing_it_wrong`, database errors, uncaught exceptions, stack traces, headers errors, and real HTTP 4xx/5xx. Allow only narrowly documented environment noise such as Chrome `unload` permissions-policy and `JQMIGRATE`.

Manual or screenshot-assisted judgments should remain honest for visual grouping, spacing, responsive layout, icons/tooltips, modal body copy, DataViews column UI, WooPay preview rendering, feature/account-state variants, and exact badge counts unless fixtures deliberately seed counts. The old `a4h-browser-probe.js` sentinel allowlist is risky because it allowed fake-ID 500s; the next browser gate must use real fixture IDs or mark routes incomplete, never convert a 500 into pass evidence.

## Agent Finding: Settings Residuals

James the 5th independently confirmed the same high-leverage settings residuals: cross-section save/validation/inline error handling, Transactions support email/phone validation plus manual-capture confirmation/copy, Notifications owner-warning and confirmation field, Advanced subscriptions/debug/multi-currency copy and behavior, Express preview richness, General disable confirmation, and Fraud welcome tour. James marked the following older N12 findings stale or closed: switch-to-live/test-drive notice, rich payment-method rows including fees/badges/duplicate notices, BNPL rich list, express overview/subpages, payout bank account, fraud Basic/Advanced and advanced rule page, and save busy state.

Decision for A4aa remains: close Notifications, Transactions support validation/copy, and Advanced copy/dev/subscriptions behavior now. The broader save-footer scroll/focus, backend inline `details` errors, manual-capture confirmation, General disable confirmation, Express live preview/copy richness, and Fraud welcome tour remain real follow-up candidates for reopened A4 and the widened A4 exit gate.
