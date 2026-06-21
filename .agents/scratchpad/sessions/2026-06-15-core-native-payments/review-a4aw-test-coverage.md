---
session: 2026-06-15-core-native-payments
type: review
by: codex
created: 2026-06-20 21:31
last_updated: 2026-06-20 21:31
tool: pirategoat-tools:js-tests-reviewer
target: A4aw VAT modal deep-link parity test coverage
reconciles:
  - analysis-a4aw-vat-modal-deep-link-parity.md
status: final
---

# A4aw Test Coverage Review

> **Prompt:** "Read-only focused JS/PHP test coverage review for A4aw VAT modal deep-link parity in /Users/vladolaru/Work/a8c/woocommerce-develop-2. Do not modify files, do not access any WPCOM sandbox, do not push/commit. Review current uncommitted tests and implementation for whether tests actually catch the required behavior: settings-page VAT deep-link states, account fetch failure/disabled/submitted/missing VAT, query cleanup preserving unrelated params, modal completion POST path, lazy chunk/style guard, shared modal focus regression, PHP template_redirect registration and URL helper. Files: plugins/woocommerce/client/admin/client/woopayments/settings/test/settings-page.test.tsx, plugins/woocommerce/tests/php/src/Internal/Admin/Settings/PaymentsProviders/WooPayments/WooPaymentsAdminNavigationControllerTest.php, plus touched implementation files as needed. Write your report to .agents/scratchpad/sessions/2026-06-15-core-native-payments/review-a4aw-test-coverage.md with valid scratchpad frontmatter and no hard-wrapped prose. Final answer should list only critical/high/medium findings with file:line, or say none; include low-risk notes separately."

## Scope

Reviewed the current uncommitted A4aw changes only: `plugins/woocommerce/client/admin/client/woopayments/settings/test/settings-page.test.tsx`, `plugins/woocommerce/tests/php/src/Internal/Admin/Settings/PaymentsProviders/WooPayments/WooPaymentsAdminNavigationControllerTest.php`, `plugins/woocommerce/client/admin/client/woopayments/settings/settings-page.tsx`, `plugins/woocommerce/client/admin/client/woopayments/admin/documents/vat-modal.tsx`, `plugins/woocommerce/client/admin/client/woopayments/admin/documents/style.scss`, `plugins/woocommerce/client/admin/client/woopayments/admin/documents/vat-modal.scss`, and `plugins/woocommerce/src/Internal/Admin/Settings/PaymentsProviders/WooPayments/WooPaymentsAdminNavigationController.php`. This was a read-only coverage review of tests versus required behavior; I did not run tests.

## Findings

### Medium: Account-fetch failure branch is untested

`plugins/woocommerce/client/admin/client/woopayments/settings/settings-page.tsx:2043`

The new VAT deep-link effect handles a rejected `getWooPaymentsAccountSettings()` call by showing the unavailable tax details notice, but `settings-page.test.tsx` only covers the successful account-response variants: missing VAT opens the modal, documents disabled shows an error notice, and already submitted shows an info notice. A regression that removes or changes the `.catch()` behavior at `settings-page.tsx:2043` would not be caught, even though account fetch failure is one of the required deep-link states.

Add a focused deep-link test that makes `/wc-admin/settings/payments/woopayments/account` reject, then asserts `createErrorNotice( 'Tax details collection is not available for your account.' )` is called and the VAT dialog is not rendered. If URL cleanup on this failure is expected, assert that too.

### Medium: Shared VAT modal focus regression is not specified by tests

`plugins/woocommerce/client/admin/client/woopayments/admin/documents/vat-modal.tsx:93`

The modal now guards the details-field autofocus so it runs once per details step instead of stealing focus on every `details` state update. Existing document/settings tests assert the initial focus lands on `Business name`, and the settings-page completion test can still pass without proving that focus stays on `Address` while the address field updates. Reverting the `hasFocusedDetailsFieldsRef` guard would leave the intended focus regression mostly uncovered.

Add a shared modal behavior test, preferably near the existing documents VAT-modal flow, that advances to the details step, moves focus to `Address`, changes/types into it, and asserts `Address` still has focus and the confirm payload retains the edited name/address. That directly protects the regression this implementation change is meant to prevent.

## Low-Risk Notes

- The close-path URL cleanup test preserves an unrelated `source` query arg, and the completion path calls the same cleanup helper, but the completion test itself starts without unrelated params. This is acceptable through shared helper coverage, though a future test with `source=platform-email` on completion would make the requirement explicit.
- The lazy chunk/style guard is a source-string assertion using `fs.readFileSync`. It is pragmatic for this performance/style invariant, but it remains a brittle source-level guard rather than proof from built asset stats.
- The PHP tests cover `template_redirect` registration and `get_vat_details_redirect_url()` output. They do not exercise `redirect_vat_details_request()` directly for REST/AJAX skip behavior or intercepted `wp_safe_redirect()`, which is a reasonable next hardening point if that runtime method changes again.
