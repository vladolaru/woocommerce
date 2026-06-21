---
session: 2026-06-15-core-native-payments
type: review
by: codex
created: 2026-06-21 00:46
tool: js-tests-reviewer
target: plugins/woocommerce/client/admin/client/woopayments/settings/test/fraud-protection-advanced.test.tsx
reconciles:
  - analysis-a4ba-advanced-fraud-ui-parity.md
  - review-a4ba-advanced-fraud-ui-source-check.md
status: final
last_updated: 2026-06-21 00:49
---

# A4ba JavaScript Test Quality Review

> **Prompt:** "Review the current uncommitted A4ba frontend test diff in /Users/vladolaru/Work/a8c/woocommerce-develop-2. Scope: plugins/woocommerce/client/admin/client/woopayments/settings/test/fraud-protection-advanced.test.tsx plus the component/style it covers. The implementation restores native WooPayments Advanced Fraud settings UI parity: loading placeholders, aria-busy while saving, linked rule guidance, allowed-countries notice, purchase/order threshold controls, inline notices, and currency prefix styling. Constraints: read-only review, do not edit product files, do not access WPCOM/sandbox, no commits/pushes. Assess test quality, false positives/over-mocking, missing assertions for load/busy/threshold/link behavior, and whether tests would catch plausible regressions. Write findings to .agents/scratchpad/sessions/2026-06-15-core-native-payments/review-a4ba-js-tests.md with scratchpad frontmatter, then summarize critical/high/medium findings in your final response. If none, say so and note residual low risks."

## Method

Read the required `js-tests-reviewer` bootstrap, JavaScript testing-pattern references, and the scratchpad contract before reviewing. Scope is the uncommitted diff for `plugins/woocommerce/client/admin/client/woopayments/settings/test/fraud-protection-advanced.test.tsx`, with component/style reads used only to verify the behavior the tests are meant to protect. Product files remain read-only.

## Findings

### Medium: Loading-state test does not protect the restored page shell

File: `plugins/woocommerce/client/admin/client/woopayments/settings/test/fraud-protection-advanced.test.tsx:269`

The new loading test asserts the status text, counts `.woopayments-fraud-protection-rule--loading` nodes, and verifies a real rule checkbox is absent. That covers the placeholder count, but it does not assert the behavior that A4ba restored from the reference: the advanced page shell remains visible while loading. The component now renders the back link, `Advanced fraud protection` heading, `Filter configuration` description, and `aria-busy="true"` loading section in `fraud-protection/advanced/index.tsx:797-826`; a regression that drops the shell while keeping a loading status plus hidden placeholder cards could still pass the test.

Recommendation: extend this test with behavior-facing assertions for the back link, main heading, filter-configuration heading, and loading container `aria-busy="true"`. Keep the placeholder count assertion if the visual skeleton count is intentional, but do not let it be the only shell-parity proof.

### Medium: Threshold-control tests cover invalid notices but miss valid one-sided and save-path behavior

File: `plugins/woocommerce/client/admin/client/woopayments/settings/test/fraud-protection-advanced.test.tsx:476`

The purchase and order threshold tests added at lines 476 and 520 verify labels/help, the empty-range warning, and the min-greater-than-max error. They do not verify that a minimum-only or maximum-only threshold is accepted without the inline warning, even though the component save validation allows one bound at `fraud-protection/advanced/index.tsx:193-231` and the reference tests cover those cases. They also do not save a valid purchase/order threshold and assert the resulting ruleset contains the entered values, so a regression that renders the controls but drops or misserializes threshold values on save would not be caught by this component test.

Recommendation: add cases for min-only and max-only values for both threshold cards with the range warning absent, then add one valid save-path test that enters threshold values and asserts `setAdvancedFraudProtectionSettings` receives the expected purchase/order threshold rules. While touching the order-items case, assert `min`, `step`, and placeholder on both item inputs and cover the invalid key guard if that keyboard filtering is intentional parity.

### Medium: Allowed-countries tests miss decoded country names and the all-except screened branch

File: `plugins/woocommerce/client/admin/client/woopayments/settings/test/fraud-protection-advanced.test.tsx:417`

The new allowed-countries tests cover specific-country blocked, specific-country screened, and all-except blocked copy with plain country names. They do not cover encoded country names from `wcSettings.countries`, which the WooPayments reference tests explicitly protect with `S&atilde;o Tom&eacute; and Pr&iacute;ncipe` rendering as `São Tomé and Príncipe`. They also do not cover the all-except plus review/screened branch. Because the test setup at lines 259-265 seeds only plain names, escaped country output or a wrong all-except screened message could pass this suite.

Recommendation: seed at least one encoded country name in `window.wcSettings.countries` for an allowed-countries notice test and assert the decoded visible text. Add an all-except screened case by setting `is_fraud_protection_review_feature_active: true` and verifying `Orders from the following countries will be screened by the filter`.

## Positive Observations

The tests render the real `FraudProtectionAdvancedSettingsPage` and mock the data hooks at a reasonable boundary. I did not find snapshot abuse, missing `await` on the new `userEvent` interactions, or tautological assertions against mock return values in the A4ba additions. The link tests use accessible roles, and the threshold tests exercise actual user clicks/typing rather than direct state mutation.

## Verification

Ran `pnpm --dir plugins/woocommerce/client/admin test:js -- fraud-protection-advanced.test.tsx --runInBand`. Result: PASS, 21 tests passed.

## Summary

No critical or high test-quality findings. The remaining concerns are medium missing-coverage gaps around loading shell parity, threshold valid/save behavior, and allowed-country edge cases that the current assertions would not catch.
