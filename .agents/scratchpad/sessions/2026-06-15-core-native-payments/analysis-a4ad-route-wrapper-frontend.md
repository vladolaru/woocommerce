---
session: 2026-06-15-core-native-payments
type: analysis
by: codex
created: 2026-06-20 02:24
tool: test-driven-development
target: plugins/woocommerce/client/admin/client/woopayments/admin/routes.tsx
reconciles:
  - analysis-a4ad-next-parity-slice.md
  - plans/2026-06-20-core-native-payments-a4ad-account-state-admin-availability.md
status: final
last_updated: 2026-06-20 02:28
---

# A4ad Frontend Route Wrapper

> **Prompt:** "You are working in /Users/vladolaru/Work/a8c/woocommerce-develop-2 on branch exp/core-native-payments. Do not access any WPCOM sandbox, do not edit ~/Work/a8c/wpcom, do not push. WPCOM code changes are off limits. You are not alone in the codebase: do not revert edits made by others, and keep to your assigned write scope.
>
> Task: Implement the FRONTEND route-wrapper part of A4ad only, following TDD. Read these files first:
> - .agents/scratchpad/sessions/2026-06-15-core-native-payments/plans/2026-06-20-core-native-payments-a4ad-account-state-admin-availability.md
> - .agents/scratchpad/sessions/2026-06-15-core-native-payments/analysis-a4ad-next-parity-slice.md
> - plugins/woocommerce/client/admin/client/woopayments/admin/routes.tsx
> - plugins/woocommerce/client/admin/client/woopayments/admin/test/routes.test.tsx
>
> Write scope only:
> - plugins/woocommerce/client/admin/client/woopayments/admin/routes.tsx
> - plugins/woocommerce/client/admin/client/woopayments/admin/test/routes.test.tsx
>
> Required behavior:
> 1. Add RED Jest tests first for unavailable protected routes: Capital, Documents, Card Readers, and Reports should render an accessible status message and must not show the lazy loading fallback or call apiFetch when wcSettings.admin.woopaymentsSettings.adminRouteAvailability.allowedRoutes marks the route false.
> 2. Add a restricted-account RED test: Overview/Transactions/Disputes remain available, Payouts/Reports/Card Readers/Capital/Documents are unavailable according to allowedRoutes. Do not wrap the provider settings route or express/fraud settings routes in this slice.
> 3. Implement route availability helpers in routes.tsx. If adminRouteAvailability is absent, preserve current behavior for compatibility. Protected route wrappers must check availability before Suspense so unavailable routes do not import the lazy chunk.
> 4. Keep unavailable UI accessible: role=\"status\", aria-live=\"polite\", concise text such as \"This WooPayments admin area is unavailable.\".
> 5. Do not change PHP/harness/changelog/docs.
> 6. Run focused Jest: pnpm --filter=@woocommerce/plugin-woocommerce test:js -- plugins/woocommerce/client/admin/client/woopayments/admin/test/routes.test.tsx
> 7. Report status DONE/DONE_WITH_CONCERNS/BLOCKED, changed files, RED command/failure summary, GREEN command/pass summary, and any concerns. Do not commit."

## Running Notes

Created before code investigation so the TDD and route-wrapper findings survive context compaction.

Read the A4ad plan, the next-slice analysis, `routes.tsx`, and `routes.test.tsx`. Existing `routes.tsx` preserves compatibility through default-on Reports feature flags and has a Reports-only unavailable wrapper, but other protected routes are direct `Suspense` wrappers. Added RED route-wrapper tests in `routes.test.tsx`.

RED command requested by the prompt failed before Jest because `@woocommerce/plugin-woocommerce` has no `test:js` script in this checkout:

```text
pnpm --filter=@woocommerce/plugin-woocommerce test:js -- plugins/woocommerce/client/admin/client/woopayments/admin/test/routes.test.tsx
ERR_PNPM_RECURSIVE_RUN_NO_SCRIPT
```

RED command used for actual Jest signal:

```text
pnpm --filter=@woocommerce/admin-library test:js -- client/woopayments/admin/test/routes.test.tsx --runInBand
```

Expected failures observed: denied Capital, Documents, Card Readers, Reports, and restricted-account denied Payouts still rendered `Loading WooPayments…` instead of `This WooPayments admin area is unavailable.`. This confirms the route availability wrapper is missing. The run also produced the existing lazy Capital test failure after the new failing tests imported chunks, which should disappear once denied routes stop entering `Suspense`.

Implemented `isRouteAvailable()`, `WooPaymentsAdminAreaUnavailable`, and `WooPaymentsProtectedRoute` in `routes.tsx`. The helper preserves compatibility by returning available when `adminRouteAvailability.allowedRoutes` is absent or when a route key is missing. Protected dashboard/money/optional routes now check availability before `Suspense`; the provider settings route and express/fraud settings routes remain direct `Suspense` routes. Reports preserves the legacy feature-flag unavailable message when route availability is absent or allows the route, but route availability denial wins with the generic admin-area unavailable status.

GREEN command used for actual Jest signal:

```text
pnpm --filter=@woocommerce/admin-library test:js -- client/woopayments/admin/test/routes.test.tsx --runInBand
PASS: 14 tests, 14 passed.
```

The prompt's exact command still cannot run Jest in this checkout:

```text
pnpm --filter=@woocommerce/plugin-woocommerce test:js -- plugins/woocommerce/client/admin/client/woopayments/admin/test/routes.test.tsx
ERR_PNPM_RECURSIVE_RUN_NO_SCRIPT
```

Additional check:

```text
git diff --check -- plugins/woocommerce/client/admin/client/woopayments/admin/routes.tsx plugins/woocommerce/client/admin/client/woopayments/admin/test/routes.test.tsx
PASS: no whitespace errors.
```
