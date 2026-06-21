---
session: 2026-06-15-core-native-payments
type: analysis
by: subagent:sartre-token-audit
created: 2026-06-19 23:37
last_updated: 2026-06-19 23:44
tool: woocommerce-code-review
target: tools/woopayments-merge/a4-admin-browser-gate.playwriter.mjs
reconciles:
  - analysis-a4ab-admin-exit-gate.md
  - data/a4ab-admin-browser-gate.json
status: final
---

# Browser Gate Token Audit

## Running Notes

Gate script: `tools/woopayments-merge/a4-admin-browser-gate.playwriter.mjs` defines literal `body.innerText.includes(...)` checks at lines 161-184, with scoped route/token definitions at lines 34-108.

Observed evidence source: `.agents/scratchpad/sessions/2026-06-15-core-native-payments/data/a4ab-admin-browser-gate.json`.

## Findings

### Express Checkout Detail Pages

`WooPay` title and `Save changes` are valid deterministic contracts. Native source defines method title `WooPay` in `plugins/woocommerce/client/admin/client/woopayments/settings/express-checkout/express-checkout-settings.tsx:19-23`, renders the page heading at `:127`, and renders `Save changes` in `components.tsx:141-151`. Reference source defines the same title in `/Users/vladolaru/Work/a8c/woocommerce-payments/client/settings/express-checkout-settings/index.js:25-28` and the shared save section at `index.js:212`.

`Appearance` for WooPay is brittle/wrongly broad. Native renders `Checkout appearance`, not a standalone `Appearance`, at `plugins/woocommerce/client/admin/client/woopayments/settings/express-checkout/woopay-settings.tsx:302-307`. Reference renders `Checkout appearance` at `/Users/vladolaru/Work/a8c/woocommerce-payments/client/settings/express-checkout-settings/index.js:45-59`. The desktop pass comes from substring matching; mobile failure likely reflects responsive hidden/omitted body text. Gate action: use `Checkout appearance` if this section must be asserted, or remove the token for mobile.

`Preview` for WooPay is wrong copy/substring mismatch. Native renders `Preview of checkout` at `plugins/woocommerce/client/admin/client/woopayments/settings/express-checkout/woopay-settings.tsx:346-356`; reference renders `Preview of checkout` at `/Users/vladolaru/Work/a8c/woocommerce-payments/client/settings/express-checkout-settings/woopay-settings.js:315-320`. Because `Preview of checkout` contains `Preview`, a missing `Preview` means the preview section did not render or is hidden, not that the expected label is exact. Gate action: assert `Preview of checkout` only where visible, or treat preview as responsive/feature-state dependent.

`Apple Pay / Google Pay` and `Amazon Pay` titles are valid deterministic contracts. Native source has the title map at `express-checkout-settings.tsx:19-23`, payment request section heading at `payment-request-settings.tsx:57-65`, and Amazon heading at `amazon-pay-settings.tsx:57-64`. Reference source has titles at `/Users/vladolaru/Work/a8c/woocommerce-payments/client/settings/express-checkout-settings/index.js:77-79` and `:125-127`.

`Button appearance` is wrong copy. Neither native nor reference source uses this exact label for these detail pages. Native payment request renders `Settings` and `ExpressCheckoutAppearanceSettings` (`payment-request-settings.tsx:116-132`) with controls like `Button size` and `Preview` (`appearance-settings.tsx:119-190`). Reference uses `Settings` plus `GeneralPaymentRequestButtonSettings` (`/Users/vladolaru/Work/a8c/woocommerce-payments/client/settings/express-checkout-settings/index.js:108-123`, `general-payment-request-button-settings.js:119-235`). Gate action: replace `Button appearance` with stable actual labels such as `Settings` and `Button size`.

`Preview` on Amazon Pay is wrong for native/reference parity. Native explicitly passes `includeCta={ false }` to `ExpressCheckoutAppearanceSettings` for Amazon Pay at `plugins/woocommerce/client/admin/client/woopayments/settings/express-checkout/amazon-pay-settings.tsx:129-132`, and `Preview` only renders when `includeCta` is true at `appearance-settings.tsx:177-190`. Reference Amazon Pay general settings only renders `Button size` at `/Users/vladolaru/Work/a8c/woocommerce-payments/client/settings/express-checkout-settings/amazon-pay-settings.js:66-80`. Gate action: remove `Preview` from Amazon Pay tokens.

### Fraud Protection

`Advanced fraud protection`, `Filter configuration`, `CVC Verification`, and `Save changes` are valid deterministic native contracts after load. Native source renders them at `plugins/woocommerce/client/admin/client/woopayments/settings/fraud-protection/advanced/index.tsx:497`, `:353-363`, `:766-769`, and `:801-809`. Reference source renders matching labels at `/Users/vladolaru/Work/a8c/woocommerce-payments/client/settings/fraud-protection/advanced-settings/index.tsx:63-72`, `:98-108`, `:399-416`, and `cards/cvc-verification.tsx:21-23`.

`Address mismatch` is wrong copy/case. Native and reference both use title case `Address Mismatch`: native `advanced/index.tsx:649-660`, reference `/Users/vladolaru/Work/a8c/woocommerce-payments/client/settings/fraud-protection/advanced-settings/cards/address-mismatch.tsx:14-23`. The current gate token uses lowercase `m`, and `includes` is case-sensitive. Gate action: change to `Address Mismatch`.

Reference fraud-protection missing all main tokens plus console issues is likely not a token-contract problem. The reference route is old settings with `view=advanced_fraud_protection`; source has the strings, so missing all of them suggests the reference store did not render the advanced view or hit a JS/runtime failure. Gate action: keep native fraud tokens, but investigate reference runtime/route separately before using it as parity evidence.

### List Surfaces

`Payout history`, `Transactions`, and `Disputes` headings are valid deterministic contracts. Native renders them in `plugins/woocommerce/client/admin/client/woopayments/admin/payouts.tsx:275-283`, `money-movement/transactions-page.tsx:696-704`, and `money-movement/disputes-page.tsx:309-313`.

`Status`, `Amount`, and `Date`/`Dispatch date` on payouts/disputes/transactions are brittle DataViews or responsive assertions. Native defines these as field labels (`payouts.tsx:127-169`, `transactions-page.tsx:244-295`, `disputes-page.tsx:111-140`), then passes them into DataViews (`money-movement/dataviews.tsx:74-91`). Missing tokens on mobile or empty data means DataViews can hide table headers, compact fields, or render empty states. Gate action: do not require table-column labels on mobile or empty lists. Assert stable page heading plus search label or empty text instead.

`Search` on transactions is too generic and reference-brittle. Native uses `Search transactions` at `transactions-page.tsx:764-775`; reference may not expose a bare `Search` string in the current responsive/table state. Gate action: use route-specific search labels if checking native, and avoid bare `Search` for parity.

`Export` for native transactions is wrong copy. Native transaction export button says `Download transactions`, not `Export`, at `plugins/woocommerce/client/admin/client/woopayments/admin/money-movement/transactions-page.tsx:777-785`. Disputes similarly uses `Download disputes` at `disputes-page.tsx:354-362`; payouts export is a toolbar action passed into DataViews and may be hidden responsively. Gate action: replace transaction token `Export` with `Download transactions` for desktop only, or drop it for mobile.

`Uncaptured transactions` is a valid native heading (`transactions-page.tsx:696-704`), but `Capture` and `Cancel` are row-action tokens only rendered when authorizations exist (`transactions-page.tsx:523-654`). Reference missing these is a brittle empty-state assertion, not a copy mismatch. Gate action: for uncaptured list surfaces, assert heading/tab plus `No uncaptured transactions found.` when empty; only assert `Capture`/`Cancel` in seeded authorization scenarios.

`Reports`, `Fees`, and `Export` are valid deterministic contracts when the Fees report table branch renders. Native renders the screen-reader `Reports` heading at `reports/page.tsx:1496-1500`, the `Fees` header at `:1377-1399`, and `Export` at `:1390-1399`. `Date` is brittle because the fees report returns an early empty state before rendering DataViews when no rows and no filters (`:1328-1375`); when it renders fields, `Date` is at `:1141-1156`. Gate action: remove `Date` unless fixtures guarantee fee rows or filtered empty table rendering.

### Overview Mobile

Native overview has no explicit visible `Overview` heading in the page body. The overview route renders cards directly at `plugins/woocommerce/client/admin/client/woopayments/admin/overview/page.tsx:250-311`; `Balance`, `Payouts`, `Account details`, `Dispute readiness`, `Inbox`, and `WooPayments settings` are card/section labels that render conditionally. The reference plugin registers `Overview` as a wc-admin breadcrumb at `/Users/vladolaru/Work/a8c/woocommerce-payments/client/index.js:182-187`.

`Overview` missing only on native mobile is a likely native product/accessibility bug or route-shell gap. The native route should provide a page-level heading/breadcrumb equivalent for the overview surface, not rely on desktop admin chrome. Product action: add a stable page title or ensure the settings-payments route shell exposes `Overview` consistently.

`Inbox` is a brittle account/data-state assertion. Native renders Inbox only for a working connected account (`overview/page.tsx:299-303`) and then returns null when there are no visible notes (`inbox-notifications.tsx:258-260`). Reference also conditionally renders inbox content. Gate action: remove `Inbox` from generic overview tokens or assert it only in a seeded account with notes/loading.

`Dispute readiness` is also feature/account-state dependent. Native only loads it when the connected account is working and `dispute_readiness_overview` is enabled (`overview/page.tsx:244-249`), and the card renders only when the payload says it is visible (`dispute-readiness-card.tsx:21-24`, `:115-123`). Gate action: do not require it in a generic overview smoke test unless the fixture guarantees the feature flag and visible payload.

`Balance`, `Payouts`, and `Account details` are closer to deterministic but still data-state dependent. `Balance` renders while loading or with overview data (`account-balances-card.tsx:223-232`), `Payouts` renders in loading/data/error variants but can return null for new waiting-period accounts (`payouts-overview-card.tsx:228-253`, `:334-341`, `:343-351`), and `Account details` returns null without shell account details (`account-details-card.tsx:20-33`). Gate action: keep `Balance`; use `Payouts` and `Account details` only after confirming the local fixture/account shell.
