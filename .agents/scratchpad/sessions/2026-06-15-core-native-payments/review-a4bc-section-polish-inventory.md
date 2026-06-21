---
session: 2026-06-15-core-native-payments
type: review
by: subagent:section-polish-explorer
created: 2026-06-21 01:43
target: A4bc settings section polish inventory after A4bb
reconciles:
  - review-a4au-settings-inventory.md
  - review-a4ay-settings-general-explorer.md
  - analysis-a4bc-next-slice-selection.md
status: final
last_updated: 2026-06-21 01:46
---

# A4bc Settings Section Polish Inventory After A4bb

## Scope

This report compares the current native WooPayments settings source under `plugins/woocommerce/client/admin/client/woopayments/settings/` with the read-only WooPayments client reference under `/Users/vladolaru/Work/a8c/woocommerce-payments/client/settings/`.

The focus is limited to remaining source-backed gaps after A4bb in section loading states, docs links, help copy, section descriptions, empty/loading guidance, and small visual/content polish. It intentionally does not reopen control-level parity already closed by A4av-A4bb unless current source proves a regression.

## Working Notes

Initial report created before source comparison. Final source comparison completed read-only against current native Core settings and the read-only WooPayments client reference.

## Verified Closed Bullets

- General settings controls parity should stay closed after A4bb. Native `plugins/woocommerce/client/admin/client/woopayments/settings/settings-page.tsx:580-704` now includes enable/disable help, disable confirmation wiring, test-mode links/help, and test-mode tracking paths. This corresponds to reference General settings in `/Users/vladolaru/Work/a8c/woocommerce-payments/client/settings/general-settings/index.js` and `/Users/vladolaru/Work/a8c/woocommerce-payments/client/settings/general-settings/enable-woopayments-checkbox.js`; no General controls work belongs in A4bc.
- VAT modal settings deep-link should stay closed. Native `plugins/woocommerce/client/admin/client/woopayments/settings/settings-page.tsx:2130-2219` handles `woopayments-vat-details-modal=true`, account-document eligibility, already-submitted notices, modal completion, and query-param cleanup. This matches the reference settings-manager flow in `/Users/vladolaru/Work/a8c/woocommerce-payments/client/settings/settings-manager/index.js:194-230`.
- Payment-method availability guidance should stay closed after A4av. Native `plugins/woocommerce/client/admin/client/woopayments/settings/payment-methods-list.tsx:126-133` defines the additional-method, BNPL, delayed-approval, and contact-support URLs, and `plugins/woocommerce/client/admin/client/woopayments/settings/payment-methods-list.tsx:863-1030` implements inactive BNPL docs, Alipay/WeChat delayed-approval docs, pending-verification Overview links, rejected Contact support links, manual-capture disabled state, and missing-currency warnings. Native tests cover the reopened BNPL/delayed-approval cases in `plugins/woocommerce/client/admin/client/woopayments/settings/test/settings-page.test.tsx:1837-1902`.
- Manual-capture confirmation mechanics should stay closed. Native `plugins/woocommerce/client/admin/client/woopayments/settings/settings-page.tsx:488-528` includes the confirmation modal, manual-capture docs link inside the modal, and card-only info notice; `plugins/woocommerce/client/admin/client/woopayments/settings/settings-page.tsx:1355-1383` gates enabling behind the modal; tests at `plugins/woocommerce/client/admin/client/woopayments/settings/test/settings-page.test.tsx:2411-2463` verify enable confirmation and direct disable.
- Payout schedule and bank-account mechanics should stay closed. Native `plugins/woocommerce/client/admin/client/woopayments/settings/settings-page.tsx:1548-1701` implements schedule restrictions, waiting-period warning, frequency/day/date controls, helper copy, and bank-account block, while `plugins/woocommerce/client/admin/client/woopayments/settings/payout-bank-account.tsx` handles payout-overview loading/failure/account-link display. Tests at `plugins/woocommerce/client/admin/client/woopayments/settings/test/settings-page.test.tsx:2818-3037` cover bank account, failed payout account, waiting-period copy, helper copy, and monthly labels.
- Notifications email validation should stay closed. Native `plugins/woocommerce/client/admin/client/woopayments/settings/settings-page.tsx:1705-1827` includes the reference warning, confirmation field when changed, validation state propagation, client/server errors, and confirm-email mismatch handling. Tests at `plugins/woocommerce/client/admin/client/woopayments/settings/test/settings-page.test.tsx:3039-3065` verify the warning and confirmation entry point.
- Advanced controls behavior should stay closed for the controls already ported. Native `plugins/woocommerce/client/admin/client/woopayments/settings/settings-page.tsx:1856-1964` includes multi-currency, deprecated WooPayments subscriptions, and debug-log behavior, and tests at `plugins/woocommerce/client/admin/client/woopayments/settings/test/settings-page.test.tsx:4122-4160` explicitly keep Stripe Billing migration UI out while asserting advanced copy and dev-mode debug behavior.

## Remaining Source-Backed Gaps

1. Section loading skeletons are still missing in native settings. Current native `plugins/woocommerce/client/admin/client/woopayments/settings/settings-page.tsx:2244-2251` shows a single page-level spinner while `hasSettings` is empty, then renders the whole form at `plugins/woocommerce/client/admin/client/woopayments/settings/settings-page.tsx:2253-2267`; native styles only define the spinner row at `plugins/woocommerce/client/admin/client/woopayments/settings/style.scss:128-134`, and there is no `LoadableSettingsSection`, `LoadableBlock`, or `is-loadable-placeholder` equivalent under the native WooPayments settings tree. The reference client wraps each section body in `/Users/vladolaru/Work/a8c/woocommerce-payments/client/settings/loadable-settings-section.js:13-19`, backed by `/Users/vladolaru/Work/a8c/woocommerce-payments/client/components/loadable/index.tsx:83-98`; `SettingsManager` applies those wrappers to General, Express, Transactions, Payouts, Notifications, Fraud, and Advanced in `/Users/vladolaru/Work/a8c/woocommerce-payments/client/settings/settings-manager/index.js:235-325`, and BNPL applies its own 30-line skeleton in `/Users/vladolaru/Work/a8c/woocommerce-payments/client/settings/buy-now-pay-later-section/index.js:49-65`.

2. BNPL section-level description/docs are thinner than reference. Native `plugins/woocommerce/client/admin/client/woopayments/settings/settings-page.tsx:865-903` renders the BNPL section with description `Offer flexible payment options when they are available for your account.` and no section-level Learn more link. Reference `/Users/vladolaru/Work/a8c/woocommerce-payments/client/settings/buy-now-pay-later-section/index.js:20-32` uses stronger merchant-facing copy, `Boost sales by offering customers additional buying power and flexible payment options.`, plus a section-level docs link to `https://woocommerce.com/document/woopayments/payment-methods/buy-now-pay-later/`. This is separate from the already-closed row-level BNPL inactive guidance.

3. Transactions section-level docs and manual-capture inline help are still short of reference. Native `plugins/woocommerce/client/admin/client/woopayments/settings/settings-page.tsx:1324-1336` describes Transactions but has no section-level docs link. Reference `/Users/vladolaru/Work/a8c/woocommerce-payments/client/settings/settings-manager/index.js:73-85` includes `View our documentation` linking to `https://woocommerce.com/document/woopayments/`. Native manual-capture checkbox help at `plugins/woocommerce/client/admin/client/woopayments/settings/settings-page.tsx:1355-1364` says only `Authorize charges first and capture funds later.`, while reference `/Users/vladolaru/Work/a8c/woocommerce-payments/client/settings/transactions/manual-capture-control.tsx:53-92` includes the authorize-and-capture docs link in the checkbox help and appends an In-Person Payments note when card-present is eligible. Native has the docs link inside the confirmation modal at `plugins/woocommerce/client/admin/client/woopayments/settings/settings-page.tsx:497-511`, so the remaining gap is inline help/content polish, not the confirmation flow.

4. Payouts section-level docs link is missing outside warning states. Native `plugins/woocommerce/client/admin/client/woopayments/settings/settings-page.tsx:1578-1593` renders the Payouts description with the payout delay but no always-visible section-level link; Learn more appears only in restricted/waiting-period notices at `plugins/woocommerce/client/admin/client/woopayments/settings/settings-page.tsx:1599-1623`. Reference `/Users/vladolaru/Work/a8c/woocommerce-payments/client/settings/settings-manager/index.js:88-110` always includes `Learn more about pending schedules` linking to `https://woocommerce.com/document/woopayments/payouts/payout-schedule/`.

5. Notifications section-level docs link is missing. Native `plugins/woocommerce/client/admin/client/woopayments/settings/settings-page.tsx:1742-1754` renders the Account notifications description with no Learn more link. Reference `/Users/vladolaru/Work/a8c/woocommerce-payments/client/settings/notification-settings/index.tsx:21-32` includes the same description plus a Learn more link to `https://woocommerce.com/document/woopayments/settings-guide/#account-notifications`.

6. Advanced section-level docs link is missing. Native `plugins/woocommerce/client/admin/client/woopayments/settings/settings-page.tsx:1868-1879` renders the section description and later includes the multi-currency docs link at `plugins/woocommerce/client/admin/client/woopayments/settings/settings-page.tsx:1881-1894`, but it has no section-level docs link. Reference `/Users/vladolaru/Work/a8c/woocommerce-payments/client/settings/settings-manager/index.js:133-147` includes `View our documentation` linking to `https://woocommerce.com/document/woopayments/settings-guide/#advanced-settings`.

## Recommended A4bc Scope

Pick one coherent bounded slice: "settings section loading and docs/copy polish." Implement native section-level skeleton/loading treatment for the settings page and restore section-level docs/copy parity for BNPL, Transactions/manual capture inline help, Payouts, Notifications, and Advanced. This is bigger than a tiny single-link task, but still bounded because it avoids reopening control behavior, API contracts, routing, onboarding, fraud rules, express subpages, or WPCOM/server work.

For loading, prefer a native lightweight section-body skeleton helper colocated with `plugins/woocommerce/client/admin/client/woopayments/settings/settings-page.tsx` or a small sibling component, using the current native section/card markup and avoiding a wholesale copy of the WooPayments client's `Loadable` abstraction. The key parity target is that the page can render stable section frames with placeholder bodies while settings load, instead of replacing the entire settings manager with one spinner.

For copy/docs, keep the edits surgical: add the missing section-level docs links and adjust only the short descriptions/help text identified above. Existing tests already assert many control-level behaviors; A4bc should add focused tests for skeleton rendering during `isLoading`, BNPL section docs, Transactions section docs plus inline manual-capture docs/help, always-visible Payouts docs, Notifications docs, and Advanced section docs.

## Explicit Non-Goals

- Do not modify WPCOM, Transact Platform, WPCOM sandbox state, or any server-side WooPayments API.
- Do not reopen General settings controls, account-mode notice/modal telemetry, disable confirmation, test-mode help/tracking, or payment-request save telemetry; those belonged to A4bb and current source shows them closed.
- Do not reopen payment-method row guidance from A4av, including inactive BNPL links, delayed approval, pending verification, rejected support links, duplicate notices, missing-currency warnings, or manual-capture-disabled row states.
- Do not hoist the full reference settings manager, `SettingsLayout`, `CardBody`, `Loadable`, or reference SCSS wholesale into native Core.
- Do not include Stripe Billing migration notices, WCPay server billing flows, or reference-only settings surfaces that native has intentionally excluded.
- Do not use browser, network, WPCOM, or product-code edits for this explorer report.
