---
session: 2026-06-15-core-native-payments
type: review
by: codex
created: 2026-06-21 00:46
tool: woocommerce-code-review
target: A4ba advanced fraud frontend diff
reconciles:
  - analysis-a4ba-advanced-fraud-ui-parity.md
  - review-a4ba-advanced-fraud-ui-source-check.md
status: final
last_updated: 2026-06-21 00:50
---

# A4ba Advanced Fraud Code Review

> **Prompt:** "Review the current uncommitted A4ba diff in /Users/vladolaru/Work/a8c/woocommerce-develop-2 for correctness/reliability/maintainability. Scope: plugins/woocommerce/client/admin/client/woopayments/settings/fraud-protection/advanced/index.tsx, style.scss, tests, and changelog. The goal is frontend parity with the WooPayments reference advanced fraud settings UI without backend changes: loading shell, busy state, linked guidance, allowed countries notice, threshold controls/help/notices, currency prefix styling. Constraints: read-only review, do not edit product files, do not access WPCOM/sandbox, no commits/pushes. Verify source-backed issues only; focus on regressions, state handling, save semantics, route/bundle behavior, and maintainability of new helpers. Write findings to .agents/scratchpad/sessions/2026-06-15-core-native-payments/review-a4ba-code.md with scratchpad frontmatter, then summarize critical/high/medium findings in final response. If none, say so and mention residual low risks."

## Status

Review complete. Scope was read-only product review of the current uncommitted A4ba diff in `plugins/woocommerce/client/admin/client/woopayments/settings/fraud-protection/advanced/index.tsx`, `plugins/woocommerce/client/admin/client/woopayments/settings/fraud-protection/advanced/style.scss`, `plugins/woocommerce/client/admin/client/woopayments/settings/test/fraud-protection-advanced.test.tsx`, and `plugins/woocommerce/changelog/fix-native-payments-a4ba-advanced-fraud-ui-parity`. No product files were edited, no WPCOM sandbox was accessed, and no commits or pushes were made.

## Findings

### Medium: Allowed-country names are rendered without decoding HTML entities

- **File:** `plugins/woocommerce/client/admin/client/woopayments/settings/fraud-protection/advanced/index.tsx:147`
- **Category:** `bug`
- **Confidence:** 0.93

The new allowed-countries notice maps `window.wcSettings.countries` directly to display strings at `index.tsx:143-149` and then joins them into the notice at `index.tsx:270-297`. WooCommerce country labels can contain HTML entities, for example `plugins/woocommerce/i18n/countries.php:74` defines Curaçao as `Cura&ccedil;ao`, and the WooPayments reference explicitly decodes the joined country labels with `decodeEntities` in `/Users/vladolaru/Work/a8c/woocommerce-payments/client/settings/fraud-protection/advanced-settings/allow-countries-notice.tsx:61-70`. The reference test also covers this case by setting `ST` to `S&atilde;o Tom&eacute; and Pr&iacute;ncipe` and expecting `São Tomé and Príncipe` in `/Users/vladolaru/Work/a8c/woocommerce-payments/client/settings/fraud-protection/advanced-settings/__tests__/allow-countries-notice.test.js:111-132`.

Because React renders string values as text, the native notice will show merchants entity text such as `Cura&ccedil;ao` or `S&atilde;o Tom&eacute; and Pr&iacute;ncipe` instead of decoded country names. This is introduced by the new allowed-countries notice and leaves a source-backed UI parity bug in the target A4ba scope.

Recommendation: import `decodeEntities` from `@wordpress/html-entities` and decode the country label before returning it from `getCountryNames`, or decode the joined country list before rendering. Add a focused test with an encoded country label so the parity behavior stays covered.

## Positives

The implementation keeps the slice frontend-only, leaves the backend settings contract untouched, and adds focused coverage for the loading shell, save busy state marker, linked AVS/IP guidance, allowed-countries variants, threshold helper text, and inline threshold notices. The existing safer native error state remains covered by tests.

## Verification

- `pnpm --filter='@woocommerce/admin-library' test:js -- client/woopayments/settings/test/fraud-protection-advanced.test.tsx --runInBand` passed: 21 tests.
- `git diff --check HEAD -- plugins/woocommerce/client/admin/client/woopayments/settings/fraud-protection/advanced/index.tsx plugins/woocommerce/client/admin/client/woopayments/settings/fraud-protection/advanced/style.scss plugins/woocommerce/client/admin/client/woopayments/settings/test/fraud-protection-advanced.test.tsx` passed with no output.

## Residual Low Risks

The page advertises saving with `aria-busy` and disables the Save button but does not yet use the existing native `SettingsBusyState` wrapper, so it lacks the shared visual dimming and polite `Saving…` status used on the main settings page. The reference `FormBusyState` also intentionally keeps child controls navigable, so I did not treat this as a blocking save-semantics regression in this review.

The custom currency-prefix input is a local replacement for the WooPayments `AmountInput`; the current tests cover USD prefix rendering but not unusual currencies or browser-specific number-input behavior. I did not find a source-backed production bug there.
