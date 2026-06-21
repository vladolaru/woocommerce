---
session: 2026-06-15-core-native-payments
type: review
by: codex
created: 2026-06-20 23:35
tool: a11y-reviewer
target: A4ay fraud onboarding/tracking accessibility re-review
status: final
---

# A4ay A11y Re-review

> **Prompt:** "Please re-review the A4ay fraud onboarding/tracking diff after the latest fix. Focus specifically on the prior high finding: the fraud welcome tour must not instantiate when advanced fraud settings failed to load and the Basic/Advanced radio fieldset is disabled.
>
> Relevant files:
> - plugins/woocommerce/client/admin/client/woopayments/settings/fraud-protection/index.tsx
> - plugins/woocommerce/client/admin/client/woopayments/settings/fraud-protection/tour.tsx
> - plugins/woocommerce/client/admin/client/woopayments/settings/test/settings-page.test.tsx
> - plugins/woocommerce/client/admin/client/woopayments/settings/fraud-protection/advanced/index.tsx
> - plugins/woocommerce/client/admin/client/woopayments/settings/test/fraud-protection-advanced.test.tsx
>
> Current fresh evidence from main agent:
> - RED: `pnpm exec jest --config client/jest.config.js --runTestsByPath client/woopayments/settings/test/settings-page.test.tsx --runInBand -t "does not start the fraud tour while fraud settings failed to load"` failed because `window.IntersectionObserver` was called once while the fraud fieldset was disabled.
> - GREEN: same single regression passed after gating `shouldLoadFraudProtectionTour` on `! hasFraudProtectionSettingsError`.
> - Focused Jest pair passed with 95 tests.
> - Exact-file ESLint, `pnpm lint:lang:types`, and `pnpm build:project:bundle` exited 0.
>
> Write any findings to `.agents/scratchpad/sessions/2026-06-15-core-native-payments/review-a4ay-a11y-rereview.md` with scratchpad frontmatter if you need to update the review artifact. Return severity-ranked findings. If the prior high is closed and there are no critical/high/medium issues, say so clearly. Do not touch WPCOM, do not push, and do not modify unrelated files."

## Result

Approved for the reviewed accessibility scope. No critical, high, or medium findings remain for the prior high issue.

## Evidence

- `plugins/woocommerce/client/admin/client/woopayments/settings/fraud-protection/index.tsx:166` computes `hasFraudProtectionSettingsError` before `shouldLoadFraudProtectionTour`.
- `plugins/woocommerce/client/admin/client/woopayments/settings/fraud-protection/index.tsx:168` now includes `! hasFraudProtectionSettingsError` in `shouldLoadFraudProtectionTour`, so the lazy `FraudProtectionTour` branch at line 307 is not instantiated when advanced fraud settings failed to load.
- `plugins/woocommerce/client/admin/client/woopayments/settings/fraud-protection/index.tsx:209` still disables the `#fraud-protection-card-options` fieldset from the same `hasFraudProtectionSettingsError` state, but the tour is gated off before it can observe that disabled group.
- `plugins/woocommerce/client/admin/client/woopayments/settings/test/settings-page.test.tsx:3483` adds the regression case `does not start the fraud tour while fraud settings failed to load`; it sets `useAdvancedFraudProtectionSettings()` to `[ 'error', noop ]`, verifies the group is disabled, then asserts `window.IntersectionObserver` was not called, no TourKit configs were created, and the tour is absent.

## Residual Risk

I did not rerun the Jest/ESLint/type/build commands during this re-review; I relied on the current source plus the fresh command evidence provided by the main agent. The reviewed source and test coverage close the prior high focus-management finding.
