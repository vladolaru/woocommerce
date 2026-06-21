---
session: 2026-06-15-core-native-payments
type: analysis
by: codex
created: 2026-06-20 21:10
target: A4aw VAT modal deep-link parity
reconciles:
  - review-a4au-settings-inventory.md
status: draft
---

# A4aw VAT Modal Deep-Link Parity

## Source Findings

The reference WooPayments plugin registers `WC_Payments_VAT_Redirect_Service` during plugin init and handles the frontend `woopayments-vat-details-redirect` query on `template_redirect`. It skips REST/AJAX and redirects to `admin.php?page=wc-settings&tab=checkout&section=woocommerce_payments&woopayments-vat-details-modal=true`. The reference settings manager then checks `woopayments-vat-details-modal=true`, opens `VatFormModal` when documents are enabled and VAT data is missing, shows an error notice when tax details collection is unavailable, shows an info notice when tax details are already submitted, removes the query arg on modal close, and shows `Tax details updated` after completion.

Native Core already has the reusable `WooPaymentsVatModal` at `plugins/woocommerce/client/admin/client/woopayments/admin/documents/vat-modal.tsx`, plus native `/wc/v3/payments/vat` validate/save helpers in `admin/documents/data.ts`. The Documents page uses the same modal only when a VAT invoice download is blocked by missing VAT data. Native settings page currently renders the provider settings sections but has no `woopayments-vat-details-modal` query handling and does not mount the modal.

The native account summary route already exposes the state needed for the reference decision: `documents.enabled`, `documents.has_submitted_vat_data`, and `documents.country` from `WooPaymentsService::get_account_summary()`, available through the existing frontend helper `getWooPaymentsAccountSettings()` at `plugins/woocommerce/client/admin/client/woopayments/settings/api.ts`. The settings payload intentionally does not include these document fields, so the least invasive frontend implementation should fetch the account summary only when the VAT modal query parameter is present, avoiding extra account fetch work on normal settings loads.

The plugin-era frontend redirect source search found no current generator outside the plugin, but the redirect service is globally registered by the plugin. Once the plugin is deactivated by native cutover, `?woopayments-vat-details-redirect` would stop working unless Core preserves it. Because the redirect is temporary compatibility glue that funnels into the native Core-owned Settings > Payments provider route, A4aw should include a small native redirect bridge in `WooPaymentsAdminNavigationController` alongside the existing legacy WooPayments route compatibility methods.

## Implementation Shape

Frontend settings should read the URL search params on mount. If `woopayments-vat-details-modal` is not `true`, it should do nothing and avoid the account summary request. If present, it should call `getWooPaymentsAccountSettings()`, then apply the reference decision tree: documents disabled creates the existing reference error notice, documents enabled with submitted VAT creates the existing reference info notice, and documents enabled without submitted VAT opens `WooPaymentsVatModal` with the account country. Closing the modal removes only `woopayments-vat-details-modal` from `window.location.search` while preserving the provider route and unrelated query args. Completing the modal should show `Tax details updated`, close, clear the query arg, and update local document state so repeated effect runs do not reopen.

Backend redirect compatibility should be a small method that returns the native settings URL with `woopayments-vat-details-modal=true` when the request has `woopayments-vat-details-redirect`, and returns empty for REST/AJAX. `register()` should hook a redirect method on `template_redirect` only when the native runtime owns payments. This keeps the actual modal decision in the settings route while preserving the plugin-era entry point.

## Verification Plan

Start with RED Jest coverage in `settings-page.test.tsx` for modal open, documents-disabled notice, already-submitted notice, completion notice plus query cleanup, and no account-summary fetch without the query. Start with RED PHPUnit coverage in `WooPaymentsAdminNavigationControllerTest` for hook registration and URL construction for the frontend VAT redirect. Then implement the minimal frontend/PHP changes, run the focused tests, exact-file lint/static gates, browser proof on the target settings URL, clean target log scans, reviews, changelog validation, diff checks, branch lint, and local commits only after all gates pass.
