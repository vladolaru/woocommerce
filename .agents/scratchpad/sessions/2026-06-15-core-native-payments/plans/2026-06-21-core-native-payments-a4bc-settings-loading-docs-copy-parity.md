---
session: 2026-06-15-core-native-payments
type: plan
by: codex
created: 2026-06-21 01:49
target: A4bc settings loading and docs/copy parity
reconciles:
  - analysis-a4bc-next-slice-selection.md
  - review-a4bc-section-polish-inventory.md
  - review-a4bc-navigation-badging-check.md
status: complete
---

# A4bc Settings Loading And Docs Copy Parity Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use $subagent-driven-development (recommended) or $executing-plans to implement this plan task-by-task. Steps use checkbox (`- [x]`) syntax for tracking.

**Goal:** Restore remaining source-backed WooPayments settings section loading and section-level docs/copy parity without reopening already closed control, route, badge, or Stripe Billing work.

**Architecture:** Keep WooPayments settings as native Core-owned Settings > Payments provider UI. Add a small native-scoped initial-loading skeleton that renders stable section frames while settings are absent, and make surgical section description/link updates in the existing settings page. Avoid hoisting reference WooPayments abstractions or global placeholder styles.

**Tech Stack:** React/TypeScript, WordPress components, WooCommerce admin Jest, SCSS, Playwriter browser proof.

---

## Files

- Modify: `plugins/woocommerce/client/admin/client/woopayments/settings/settings-page.tsx`
- Modify: `plugins/woocommerce/client/admin/client/woopayments/settings/style.scss`
- Modify: `plugins/woocommerce/client/admin/client/woopayments/settings/test/settings-page.test.tsx`
- Add: `plugins/woocommerce/changelog/fix-native-payments-a4bc-settings-loading-docs-copy`
- Update: `.agents/scratchpad/sessions/2026-06-15-core-native-payments/implementation-log.md`
- Update after verification: `.agents/scratchpad/sessions/2026-06-15-core-native-payments/staging-log.md`

## Task 1: RED Tests For Loading And Section Docs Copy

- [x] Add a loading-state Jest test in `settings-page.test.tsx` that sets `mockUseSettings.mockReturnValue({ isLoading: true, isSaving: false, isDirty: false, saveSettings: mockSaveSettings })` and `mockUseGetSettings.mockReturnValue({})`, renders `<WooPaymentsSettingsPage />`, asserts the page-level `Loading WooPayments settings…` row is absent, asserts section headings remain visible, and asserts `container.querySelectorAll('.woopayments-settings-loadable-placeholder[aria-hidden="true"]').length` is greater than 8.
- [x] Add focused docs/copy assertions in `settings-page.test.tsx` for these source-backed gaps: Payment methods description `Based on their device type, location, and purchase history...`; BNPL description `Boost sales...` plus docs URL `https://woocommerce.com/document/woopayments/payment-methods/buy-now-pay-later/`; Transactions description `Update your store's configuration to ensure smooth transactions.` plus docs URL `https://woocommerce.com/document/woopayments/`; manual capture inline help link to `MANUAL_CAPTURE_DOC_URL`; Payouts `Learn more about pending schedules` docs URL; Notifications `Learn more` docs URL `https://woocommerce.com/document/woopayments/settings-guide/#account-notifications`; Advanced description `More options for specific payment needs.` plus docs URL `https://woocommerce.com/document/woopayments/settings-guide/#advanced-settings`.
- [x] Run the focused Jest command and confirm RED:

```bash
pnpm --dir plugins/woocommerce/client/admin test:js settings/test/settings-page.test.tsx -- --runInBand
```

Expected: fail because native still shows only the page-level loading row and the new section docs/copy assertions are not yet implemented.

## Task 2: Implement Native Initial Loading Skeleton

- [x] In `settings-page.tsx`, add section loading config and helpers after `SettingsSection`/`FieldGroup`, for example `SettingsSectionLoadingPlaceholder` and `SettingsLoadingSections`. The placeholder markup must be non-interactive, `aria-hidden="true"`, scoped with `woopayments-settings-loadable-placeholder`, and rendered through existing `SettingsSection` so headings/descriptions are stable.
- [x] Replace the current `isLoading && ! hasSettings` page-level loading paragraph with `<SettingsLoadingSections />`. Do not render `SaveSettingsSection` or real controls during initial empty-settings loading.
- [x] In `style.scss`, remove the now-unused `woopayments-settings-page__loading` spinner styling and add scoped placeholder block styles under `.woopayments-settings-page`, using existing global `loading-fade` keyframes and a `prefers-reduced-motion: reduce` override.
- [x] Run the focused Jest command and confirm the loading test turns GREEN or identify the next failing assertion.

## Task 3: Implement Section Docs And Copy Polish

- [x] In `settings-page.tsx`, add constants for the missing docs URLs: BNPL, WooPayments docs root, payout schedule, notifications, advanced settings, and in-person payments.
- [x] Update Payment methods, BNPL, Transactions, Payouts, Notifications, and Advanced section descriptions to match the source-backed reference copy and links. Keep labels sentence case and translate every new string with the WooCommerce text domain.
- [x] Update manual-capture checkbox help to include the inline authorize-and-capture docs link and append the In-Person Payments note only when `useCardPresentEligible()` is true.
- [x] Run the focused Jest command and confirm the docs/copy tests turn GREEN or identify the next failing assertion.

## Task 4: Verification And Review Gates

- [x] Run focused Jest:

```bash
pnpm --dir plugins/woocommerce/client/admin test:js settings/test/settings-page.test.tsx -- --runInBand
```

- [x] Run exact-file ESLint for touched TSX files:

```bash
pnpm --dir plugins/woocommerce/client/admin exec eslint client/woopayments/settings/settings-page.tsx client/woopayments/settings/test/settings-page.test.tsx
```

- [x] Run Stylelint for the touched SCSS:

```bash
pnpm --dir plugins/woocommerce/client/admin exec stylelint client/woopayments/settings/style.scss
```

- [x] Run admin type declarations and the targeted admin build:

```bash
pnpm --filter='@woocommerce/admin' build:types
pnpm --filter='@woocommerce/admin' build
```

- [x] Use Playwriter against `http://store8889.localhost:8889/wp-admin/admin.php?page=wc-settings&tab=checkout&path=/woopayments/settings` to capture visual/log proof that the settings page loads without console errors and the added docs links are present in the native UI. Store proof under `.agents/scratchpad/sessions/2026-06-15-core-native-payments/data/a4bc-settings-loading-docs-copy-parity/`.
- [x] Scan recent target store logs for new PHP notices/warnings/errors and record the result.
- [x] Dispatch at least one subagent code review focused on A4bc requirements, accessibility, and bundle/style risk. Fix any source-backed findings.

## Task 5: Package The Slice

- [x] Add a WooCommerce changelog entry at `plugins/woocommerce/changelog/fix-native-payments-a4bc-settings-loading-docs-copy`.
- [x] Run `pnpm --filter='@woocommerce/plugin-woocommerce' changelog validate`.
- [x] Run `git diff --check -- . ':!.agents'` and the branch lint gate:

```bash
pnpm --filter='@woocommerce/plugin-woocommerce' lint:changes:branch
```

- [x] Update `implementation-log.md` and `staging-log.md` with the exact gates, findings, and git range. Mark this plan `status: complete` only after the package is verified and committed locally.
