---
session: 2026-06-15-core-native-payments
type: analysis
by: codex
created: 2026-06-19 01:52
last_updated: 2026-06-19 02:53
target: exp/core-native-payments — A4k settings row and payload parity
reconciles:
  - supervisor-prompt-2026-06-18-2344-N12.md
  - analysis-a4j-settings-payment-methods-parity.md
  - analysis-a4k-express-checkout-settings-parity.md
status: draft
---

# A4k Settings Row and Payload Parity

## Decision

A4k will close the next settings parity chunk by widening the native settings payload and finishing the merchant-visible extras around the existing native payment-method/BNPL rows. This is the right next chunk because A4j created the shared row/list foundation but explicitly left fee pills/tooltips, duplicate-payment-method notices, PM promotion/discount badges, and row SCSS fidelity open due to missing payload data. Both read-only explorers agreed that express checkout customize subpages/live preview and fraud advanced rule editing are deeper workflows that should remain separate follow-up slices.

The core implementation scope is:

- Expose account fee structures from cached account data through `/wc/v3/payments/settings` and render fee pills/tooltips on payment-method/BNPL rows.
- Expose duplicate payment-method notices and dismissed duplicate notice state through the existing settings contract and allowlisted option save path, then render dismissible duplicate notices on rows.
- Expose or derive PM promotion metadata if a local native source exists; if no source-backed native data exists, record the gap honestly instead of inventing fake promotions.
- Preserve row visual grouping and styling from the reference row component as closely as possible inside the lazy WooPayments settings bundle.
- Add the small adjacent settings-shell parity pieces if payload work stays bounded: payout bank-account block, sandbox/test account switch-to-live notice, and save busy-state polish.

## Source Anchors

Reference row and shared sections:

- `/Users/vladolaru/Work/a8c/woocommerce-payments/client/settings/payment-methods-list/payment-method.tsx`
- `/Users/vladolaru/Work/a8c/woocommerce-payments/client/settings/payment-methods-section/index.js`
- `/Users/vladolaru/Work/a8c/woocommerce-payments/client/settings/buy-now-pay-later-section/index.js`
- `/Users/vladolaru/Work/a8c/woocommerce-payments/client/utils/account-fees.tsx`
- `/Users/vladolaru/Work/a8c/woocommerce-payments/client/components/duplicate-notice/index.tsx`
- `/Users/vladolaru/Work/a8c/woocommerce-payments/client/data/pm-promotions/hooks.ts`

Native seams:

- `plugins/woocommerce/src/Internal/Payments/Providers/WooPayments/WooPaymentsSettingsService.php`
- `plugins/woocommerce/src/Internal/Admin/Settings/PaymentsProviders/WooPayments/WooPaymentsRestController.php`
- `plugins/woocommerce/client/admin/client/woopayments/settings/data/selectors.ts`
- `plugins/woocommerce/client/admin/client/woopayments/settings/data/actions.ts`
- `plugins/woocommerce/client/admin/client/woopayments/settings/payment-methods-list.tsx`
- `plugins/woocommerce/client/admin/client/woopayments/settings/settings-page.tsx`
- `plugins/woocommerce/client/admin/client/woopayments/settings/style.scss`

Small adjacent settings-shell references:

- `/Users/vladolaru/Work/a8c/woocommerce-payments/client/settings/deposits/index.js`
- `/Users/vladolaru/Work/a8c/woocommerce-payments/client/components/sandbox-mode-switch-to-live-notice/index.tsx`
- `/Users/vladolaru/Work/a8c/woocommerce-payments/client/settings/save-settings-section/index.js`

## Guardrails

Keep one `wc/payments/settings` store and one `@wordpress/data` registry path. Do not import or resurrect standalone `wcpay/data/settings`, `wcpay/data/pm-promotions`, `window.wcpaySettings`, or plugin-era `/payments/*` routes as native dependencies. Do not broaden generic Settings > Payments provider-list abstractions beyond the seams needed by the native provider. Do not reintroduce Stripe Billing UI. Keep WooPayments-specific settings code and styles in lazy WooPayments settings/admin chunks so stores that do not use WooPayments are not forced to pay for these assets.

If duplicate detection or PM promotions require a larger cross-provider or remote source that cannot be proven from local native code, A4k should fail that sub-item closed in the analysis and staging log rather than masking it with fake data.

## Closeout

A4k implemented the source-backed subset of the decision above. The native settings payload now exposes account fees and dismissed duplicate notice state, computes conservative duplicate payment-method clusters from enabled WooCommerce gateways, and includes `pm_promotions` as an empty shaped payload because no native source-backed promotions provider exists today. The frontend settings store and row UI consume those payloads to render reference-style fee pills, fee details, discount badges, and dismissible duplicate notices. The fee disclosure is a real button with focus, click, Escape close, and accessible description behavior; discount details no longer rely on `title`; duplicate notice dismissal restores focus to the row control. The final browser pass corrected a real drift where locale-default `Intl` produced `US$0.30` while the reference rendered `$0.30`; native now requests the narrow currency symbol and keeps the runtime locale rather than hard-coding `en-US`.

The bounded shell fix was limited to preserving the existing `book` express checkout button value. Larger adjacent N12 settings parity items remain separate reopened-A4 work: express checkout customize subpages/live preview, payout bank-account display, fraud Basic/Advanced main and advanced subpage parity, sandbox switch-to-live notice, save busy-state overlay, source-backed PM promotions, broader copy/content parity, and SCSS fidelity beyond the row extras.

## Read-only Explorer Reports

### Native seam explorer

Beauvoir checked the native settings store, REST service, and current A4j components. Source-backed findings:

- Fee payload is missing. `WooPaymentsSettingsService::get_available_payment_method_ids()` reads cached `account_data['fees']` to infer available methods, but `/wc/v3/payments/settings` does not return the fee structure for row rendering.
- Duplicate-payment support is half-present. The frontend selector exists and the save path already allowlists `wcpay_duplicate_payment_method_notices_dismissed`, but the backend currently returns `duplicated_payment_method_ids => array()` and no dismissed-state payload.
- Payment-method promotions are not source-backed in native settings today. The reference has `wcpay/data/pm-promotions`, while native has no equivalent source. A4k may add a clean optional payload shape, but must not invent fake active promotions.
- Express checkout settings are deeper than A4k. Native has the flat settings values, but not the reference customize subpages/live preview. One bounded bug belongs with settings polish: native offers the `book` WooPay button type option while its normalizer drops `book`.
- Payout bank-account parity needs more account-link/external-account payload discovery before implementation.
- Fraud protection parity is a larger workflow slice because native currently has a simple select and reference has Basic/Advanced controls plus an advanced rules subpage.
- Save state has functional disabling but not the reference busy overlay behavior.

Recommendation: make A4k the native settings payload plus payment-method row extras slice, then follow with express customize and fraud advanced as separate A4 slices.

### Reference parity explorer

Heisenberg checked the standalone WooPayments reference components for the same settings area. Source-backed findings:

- The next highest-value parity chunk is payment-method/BNPL row completion: fee pills/tooltips, discount badges, PM promotion badges if source-backed, duplicate notices, availability chips/notices, and row SCSS fidelity.
- Reference fee rendering lives in `client/utils/account-fees.tsx` and `client/settings/payment-methods-list/payment-method.tsx`. The row shows `From %1$f%% + %2$s`, wraps fee details in a hover tooltip, and uses discount badge copy such as `%s%% off fees`.
- Reference duplicate notices live in `client/components/duplicate-notice/index.tsx` and persist dismissals through `wcpay_duplicate_payment_method_notices_dismissed`.
- Reference duplicate detection lives in `includes/class-duplicates-detection-service.php`; it detects active duplicate gateway clusters only when at least one gateway is a WooPayments method.
- The payout bank-account block, sandbox switch-to-live notice, express customize subpages, and fraud advanced subpage are real parity gaps but should be chunked separately unless their data source and UI are small enough to close safely inside A4k.

Recommendation: do not jump straight to express/fraud. First finish the row payload and merchant-visible row extras created by A4j, because those are already partially implemented and directly visible on the settings landing page.
