---
session: 2026-06-15-core-native-payments
type: review
by: codex
created: 2026-06-20 21:33
tool: pirategoat-tools:reliability-reviewer
target: A4aw VAT modal deep-link parity
reconciles:
  - analysis-a4aw-vat-modal-deep-link-parity.md
status: final
---

# A4aw Reliability Review

## Scope

Reviewed the current uncommitted diff only for the A4aw VAT modal deep-link parity slice, focused on operational reliability and source-backed regressions in `plugins/woocommerce/src/Internal/Admin/Settings/PaymentsProviders/WooPayments/WooPaymentsAdminNavigationController.php`, `plugins/woocommerce/tests/php/src/Internal/Admin/Settings/PaymentsProviders/WooPayments/WooPaymentsAdminNavigationControllerTest.php`, `plugins/woocommerce/client/admin/client/woopayments/settings/settings-page.tsx`, `plugins/woocommerce/client/admin/client/woopayments/settings/test/settings-page.test.tsx`, `plugins/woocommerce/client/admin/client/woopayments/admin/documents/vat-modal.tsx`, and the untracked `plugins/woocommerce/client/admin/client/woopayments/admin/documents/vat-modal.scss`.

## Findings

No critical, high, or medium operational reliability findings.

## Source-Backed Checks

The PHP redirect bridge is gated by native runtime registration and targets `/woopayments/settings` with only `woopayments-vat-details-modal=true`, so the target URL does not retain `woopayments-vat-details-redirect` and does not create a redirect loop. The handler skips REST and AJAX before reading `$_GET` at `WooPaymentsAdminNavigationController.php:225-235`, and the URL construction at `WooPaymentsAdminNavigationController.php:250-260` is based on query-arg presence only rather than trusting a user-supplied redirect target.

The reference WooPayments plugin registers the equivalent redirect on `template_redirect` and also skips REST and AJAX before redirecting to the settings modal URL. Native currently registers the bridge at the default priority at `WooPaymentsAdminNavigationController.php:159-160`; I did not find a source-backed failure from that difference in this review, so I did not raise it as a finding.

The settings-page deep-link effect only runs when `woopayments-vat-details-modal=true` is present, guards against post-unmount state updates, shows merchant-visible notices for unavailable/already-submitted/fetch-failure cases, and opens the modal only when documents are enabled and VAT data is missing at `settings-page.tsx:1999-2054`. The close and completion paths both clear only the VAT modal query parameter while preserving unrelated query state at `settings-page.tsx:2061-2079`.

The VAT modal remains lazy-loaded from the settings page with a dedicated chunk, and the modal stylesheet is imported inside `vat-modal.tsx:25`, keeping the new modal styles with the modal module rather than forcing the normal settings page path to import the modal synchronously. The focus guard added at `vat-modal.tsx:78-104` prevents repeated focus jumps after the details step is already active.

The added Jest coverage exercises the modal-open path, disabled-documents notice, already-submitted notice, close query cleanup, save query cleanup, and lazy chunk/style split. I treated the test source reads for chunk/style assertions as coverage-only support, not production behavior.

## Low-Risk Notes

`plugins/woocommerce/client/admin/client/woopayments/admin/documents/vat-modal.scss` is currently untracked. Because `vat-modal.tsx` imports it, the slice should include that file when staging/committing, otherwise the lazy modal chunk will reference a missing local stylesheet change.

The fetch-failure path intentionally surfaces the same user-facing notice as the documents-disabled path. That is acceptable for this focused parity bridge, but it means transient `/wc-admin/settings/payments/woopayments/account` failures are not distinguishable to the merchant from account capability unavailability.

## Outputs

Pirategoat reliability outputs were written to `/tmp/reliability-review.json` and `/tmp/reliability-review.md`.
