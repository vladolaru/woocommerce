---
session: 2026-06-15-core-native-payments
type: analysis
by: subagent:bohr-the-6th
created: 2026-06-20 21:04
last_updated: 2026-06-20 21:11
tool: current-datetime, woocommerce-dev-cycle
target: express checkout settings parity
reconciles:
  - review-a4au-settings-inventory.md
status: final
---

# Express Checkout Settings Parity Analysis

## Scope

Compare the WooPayments client express checkout settings sub-SPA, notices, feature gating, and help links against the native WooCommerce Core settings-payments implementation after A4av. This is read-only source exploration; no source files are edited.

## Findings Log

- Initial scaffold created before source exploration.
- Compared the reference WooPayments client under `/Users/vladolaru/Work/a8c/woocommerce-payments/client/settings/express-checkout*` with the native Core implementation under `plugins/woocommerce/client/admin/client/woopayments/settings`.
- Did not access WPCOM sandbox and did not edit source files.

## Highest-value source-backed gaps

### 1. Feature flag parity is missing from native express checkout settings

Reference WooPayments exposes express checkout flags to the client through `wcpaySettings.featureFlags`:

- `/Users/vladolaru/Work/a8c/woocommerce-payments/includes/admin/class-wc-payments-admin.php:1001` loads JS settings including `featureFlags`.
- `/Users/vladolaru/Work/a8c/woocommerce-payments/includes/admin/class-wc-payments-admin.php:1117` merges fixed flags and `WC_Payments_Features::to_array()`.
- `/Users/vladolaru/Work/a8c/woocommerce-payments/includes/class-wc-payments-features.php:433` includes `woopay`, `woopayExpressCheckout`, `isDynamicCheckoutPlaceOrderButtonEnabled`, and `amazonPay`.

Native Core does not surface equivalent flags in the settings contract. The shared admin preload only exposes `reportsArea` under `woopaymentsSettings.featureFlags`:

- `plugins/woocommerce/src/Internal/Admin/Settings/PaymentsProviders/WooPayments/WooPaymentsAdminNavigationController.php:166`
- `plugins/woocommerce/src/Internal/Admin/Settings/PaymentsProviders/WooPayments/WooPaymentsAdminNavigationController.php:179`

The native settings service returns express checkout support and hard-codes the payment-methods-list support flag:

- `plugins/woocommerce/src/Internal/Payments/Providers/WooPayments/WooPaymentsSettingsService.php:243`
- `plugins/woocommerce/src/Internal/Payments/Providers/WooPayments/WooPaymentsSettingsService.php:284`
- `plugins/woocommerce/src/Internal/Payments/Providers/WooPayments/WooPaymentsSettingsService.php:286`

Concrete behavior gaps caused by that:

- WooPay overview row: reference hides the row when `featureFlags.woopay` is false (`/Users/vladolaru/Work/a8c/woocommerce-payments/client/settings/express-checkout/woopay-item.tsx:36`), while native always pushes the row (`plugins/woocommerce/client/admin/client/woopayments/settings/settings-page.tsx:843`).
- Amazon overview row: reference requires `featureFlags.amazonPay` and availability (`/Users/vladolaru/Work/a8c/woocommerce-payments/client/settings/express-checkout/index.js:21`), while native only checks `available_payment_method_ids` (`plugins/woocommerce/client/admin/client/woopayments/settings/settings-page.tsx:958`).
- WooPay detail "General" section: reference gates it behind `featureFlags.woopayExpressCheckout` (`/Users/vladolaru/Work/a8c/woocommerce-payments/client/settings/express-checkout-settings/index.js:183`), while native always renders it (`plugins/woocommerce/client/admin/client/woopayments/settings/express-checkout/woopay-settings.tsx:373`).
- Payment-methods-list mode: reference gates the checkbox on `featureFlags.isDynamicCheckoutPlaceOrderButtonEnabled` for Apple/Google and Amazon (`/Users/vladolaru/Work/a8c/woocommerce-payments/client/settings/express-checkout-settings/payment-request-settings.js:56`, `/Users/vladolaru/Work/a8c/woocommerce-payments/client/settings/express-checkout-settings/amazon-pay-settings.js:116`), while native renders it whenever settings support is not explicitly false (`plugins/woocommerce/client/admin/client/woopayments/settings/express-checkout/settings-utils.ts:9`, `plugins/woocommerce/client/admin/client/woopayments/settings/express-checkout/payment-request-settings.tsx:90`, `plugins/woocommerce/client/admin/client/woopayments/settings/express-checkout/amazon-pay-settings.tsx:88`).

Likely files to change:

- `plugins/woocommerce/src/Internal/Payments/Providers/WooPayments/WooPaymentsSettingsService.php`
- `plugins/woocommerce/src/Internal/Admin/Settings/PaymentsProviders/WooPayments/WooPaymentsAdminNavigationController.php` if the team prefers shared `woopaymentsSettings` flags over the settings REST contract.
- `plugins/woocommerce/client/admin/client/woopayments/settings/settings-page.tsx`
- `plugins/woocommerce/client/admin/client/woopayments/settings/express-checkout/settings-utils.ts`
- `plugins/woocommerce/client/admin/client/woopayments/settings/express-checkout/payment-request-settings.tsx`
- `plugins/woocommerce/client/admin/client/woopayments/settings/express-checkout/amazon-pay-settings.tsx`
- `plugins/woocommerce/client/admin/client/woopayments/settings/express-checkout/woopay-settings.tsx`

### 2. Detail-page notice rendering differs from reference

Reference notice behavior:

- Reads WooPay/payment request/Amazon states and feature flags from context: `/Users/vladolaru/Work/a8c/woocommerce-payments/client/settings/express-checkout-settings/express-checkout-settings-notices.tsx:61`.
- Applies WooPay and Amazon feature gates before counting other enabled buttons: `/Users/vladolaru/Work/a8c/woocommerce-payments/client/settings/express-checkout-settings/express-checkout-settings-notices.tsx:75`.
- Returns `null` if no other buttons are enabled: `/Users/vladolaru/Work/a8c/woocommerce-payments/client/settings/express-checkout-settings/express-checkout-settings-notices.tsx:95`.
- Has tests for "no notices" and feature flag behavior: `/Users/vladolaru/Work/a8c/woocommerce-payments/client/settings/express-checkout-settings/__tests__/express-checkout-settings-notices.test.js:43`, `/Users/vladolaru/Work/a8c/woocommerce-payments/client/settings/express-checkout-settings/__tests__/express-checkout-settings-notices.test.js:106`.

Native notice behavior:

- Counts WooPay/payment request/Amazon states without feature flags: `plugins/woocommerce/client/admin/client/woopayments/settings/express-checkout/notices.tsx:51`.
- Gates Amazon only by available ID: `plugins/woocommerce/client/admin/client/woopayments/settings/express-checkout/notices.tsx:65`.
- Renders the Cart & Checkout blocks override notice unconditionally after the optional shared-settings notice: `plugins/woocommerce/client/admin/client/woopayments/settings/express-checkout/notices.tsx:78`.
- Current native tests only cover the positive "other enabled methods" path: `plugins/woocommerce/client/admin/client/woopayments/settings/test/express-checkout-settings.test.tsx:426`.

Likely files to change:

- `plugins/woocommerce/client/admin/client/woopayments/settings/express-checkout/notices.tsx`
- `plugins/woocommerce/client/admin/client/woopayments/settings/test/express-checkout-settings.test.tsx`

### 3. WooPay detail help links are incomplete versus reference

Source-backed content/link gaps:

- Reference WooPay enable help links the word "WooPay" to merchant documentation (`/Users/vladolaru/Work/a8c/woocommerce-payments/client/settings/express-checkout-settings/woopay-settings.js:100` through `:141`); native has Terms, Privacy, and usage-tracking links but "WooPay" is plain text (`plugins/woocommerce/client/admin/client/woopayments/settings/express-checkout/woopay-settings.tsx:254` through `:295`).
- Reference global theme help includes a "Learn more" link (`/Users/vladolaru/Work/a8c/woocommerce-payments/client/settings/express-checkout-settings/woopay-settings.js:258`); native text has no link (`plugins/woocommerce/client/admin/client/woopayments/settings/express-checkout/woopay-settings.tsx:322`).
- Reference checkout policies help links the store privacy page, terms page, and WooPay custom policies docs (`/Users/vladolaru/Work/a8c/woocommerce-payments/client/settings/express-checkout-settings/woopay-settings.js:281`); native renders plain copy without those links (`plugins/woocommerce/client/admin/client/woopayments/settings/express-checkout/woopay-settings.tsx:336`).

Likely file to change:

- `plugins/woocommerce/client/admin/client/woopayments/settings/express-checkout/woopay-settings.tsx`

### 4. Apple/Google payment-methods-list help text does not mention Amazon when applicable

Reference Apple/Google detail copy includes Amazon Pay in the payment-methods-list help text when `featureFlags.amazonPay` is enabled:

- `/Users/vladolaru/Work/a8c/woocommerce-payments/client/settings/express-checkout-settings/payment-request-settings.js:70`

Native always says only "Apple Pay and Google Pay":

- `plugins/woocommerce/client/admin/client/woopayments/settings/express-checkout/payment-request-settings.tsx:97`

This is lower than the flag plumbing itself, but it is directly source-backed and likely belongs in the same slice if the native settings contract gains Amazon feature-gate awareness.

### 5. Lower-value copy/appearance parity gaps

- Overview section description differs. Reference: `/Users/vladolaru/Work/a8c/woocommerce-payments/client/settings/settings-manager/index.js:41`; native: `plugins/woocommerce/client/admin/client/woopayments/settings/settings-page.tsx:985`.
- Reference theme options include descriptive helper text: `/Users/vladolaru/Work/a8c/woocommerce-payments/client/settings/express-checkout-settings/general-payment-request-button-settings.js:92`. Native options only expose labels/values: `plugins/woocommerce/client/admin/client/woopayments/settings/express-checkout/appearance-settings.tsx:61`.

## Behaviors that look aligned

- Link/WooPay conflict behavior is present natively. Reference disables Link when WooPay is enabled and shows the conflict notice (`/Users/vladolaru/Work/a8c/woocommerce-payments/client/settings/express-checkout/link-item.tsx:57`); native mirrors this in the overview and hooks (`plugins/woocommerce/client/admin/client/woopayments/settings/settings-page.tsx:826`, `plugins/woocommerce/client/admin/client/woopayments/settings/data/hooks.ts:332`).
- Amazon status/notice handling is present natively. Reference uses payment method availability (`/Users/vladolaru/Work/a8c/woocommerce-payments/client/settings/express-checkout/amazon-pay-item.tsx:29`); native uses payment method statuses and has rejected/inactive tests (`plugins/woocommerce/client/admin/client/woopayments/settings/settings-page.tsx:958`, `plugins/woocommerce/client/admin/client/woopayments/settings/test/settings-page.test.tsx:876`).
- Apple/Google duplicate notices are present natively and tested (`plugins/woocommerce/client/admin/client/woopayments/settings/settings-page.tsx:1046`, `plugins/woocommerce/client/admin/client/woopayments/settings/test/settings-page.test.tsx:943`).
- Native express checkout routes exist for the sub-SPA (`plugins/woocommerce/client/admin/client/woopayments/admin/routes.tsx:214`) and detail route (`plugins/woocommerce/client/admin/client/woopayments/settings/express-checkout/index.tsx:11`).

## Test and browser proof strategy

### Unit/Jest coverage

Focus on existing native test files:

- `plugins/woocommerce/client/admin/client/woopayments/settings/test/express-checkout-settings.test.tsx`
  - Add a "no other methods enabled" assertion for `ExpressCheckoutNotices` parity with reference.
  - Add feature-gate tests for WooPay/Amazon notice counting.
  - Add payment-methods-list checkbox tests for the dynamic checkout flag, replacing the current assumption that support alone is enough (`:308`, `:348`, `:670`).
  - Add WooPay help-link assertions for merchant docs, global theme "Learn more", privacy/terms policy links, and custom policies docs (`:717` already covers broad WooPay detail rendering).
- `plugins/woocommerce/client/admin/client/woopayments/settings/test/settings-page.test.tsx`
  - Add overview row visibility tests for `woopay` and `amazonPay` feature gates.
  - Existing useful coverage: customize routes at `:804`, legal overview links at `:836`, Amazon status notices at `:876`, duplicate notices at `:943`.
- `plugins/woocommerce/tests/php/src/Internal/Payments/Providers/WooPayments/WooPaymentsSettingsServiceTest.php`
  - Extend the settings contract assertions around `:276` and required keys around `:1540` if feature gate data is added to the REST contract.
- `plugins/woocommerce/tests/php/src/Internal/Admin/Settings/PaymentsProviders/WooPayments/WooPaymentsRestControllerTest.php`
  - Existing GET/POST contract pass-through starts at `:354`; add coverage only if REST schema/args change.

Suggested commands after implementation:

```sh
cd plugins/woocommerce/client/admin
pnpm test:js -- client/woopayments/settings/test/express-checkout-settings.test.tsx client/woopayments/settings/test/settings-page.test.tsx
pnpm run ts:check
```

For PHP contract changes:

```sh
pnpm test:php:env -- --filter WooPaymentsSettingsServiceTest
pnpm test:php:env -- --filter WooPaymentsRestControllerTest
pnpm --filter=@woocommerce/plugin-woocommerce lint:php:changes
```

### Browser proof

Use the native settings routes, not WPCOM sandbox:

- Overview: `wp-admin/admin.php?page=wc-settings&tab=checkout&path=%2Fwoopayments%2Fsettings`
- Apple/Google detail: `wp-admin/admin.php?page=wc-settings&tab=checkout&path=%2Fwoopayments%2Fsettings%2Fexpress-checkout%2Fpayment_request`
- WooPay detail: `wp-admin/admin.php?page=wc-settings&tab=checkout&path=%2Fwoopayments%2Fsettings%2Fexpress-checkout%2Fwoopay`
- Amazon detail: `wp-admin/admin.php?page=wc-settings&tab=checkout&path=%2Fwoopayments%2Fsettings%2Fexpress-checkout%2Famazon_pay`

Proof matrix:

- With WooPay/Amazon/dynamic checkout flags enabled: rows and checkboxes are visible, shared notices mention only enabled other methods, Apple/Google help includes Amazon when Amazon is available, and WooPay help links resolve to the same targets as reference.
- With WooPay flag disabled: WooPay row, WooPay notice participation, and WooPay detail sections gated by WooPay flags are absent.
- With Amazon flag disabled or Amazon not available: Amazon row and Amazon notice participation are absent; Apple/Google payment-methods-list help omits Amazon.
- With no other express checkout methods enabled: the detail appearance section does not show either shared-settings or Cart & Checkout override notices, matching reference behavior.
- Existing parity smoke checks: Link/WooPay conflict, Amazon inactive/rejected notices, Apple/Google duplicate notices, and customize links still work.
