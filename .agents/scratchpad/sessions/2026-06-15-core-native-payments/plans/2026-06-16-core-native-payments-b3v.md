---
session: 2026-06-15-core-native-payments
type: plan
by: codex
created: 2026-06-16 14:47
last_updated: 2026-06-16 14:59
status: final
---

# B3v Deprecated WooPayments Promotion Engine Removal Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use $executing-plans to implement this plan task-by-task. Steps use checkbox (`- [x]`) syntax for tracking.

**Goal:** Remove the deprecated WooPayments-only promotion engine, fake gateway row, and promotion-specific client assets from WooCommerce Core while keeping shared payment assets that are still used by live settings and suggestion surfaces.

**Architecture:** Treat the deprecated WooPayments promotion subsystem as one surface spanning the inert `Internal\Admin\WCPayPromotion` PHP engine, the fake `pre_install_woocommerce_payments_promotion` gateway, the `payment-method-promotions` wp-admin script entrypoint, the `wc-pay-promotion` feature flag metadata, and the `payment-recommendations.tsx` DOM probe/filter guard built around that fake row. Delete the subsystem end to end, simplify the payments recommendations card to rely only on real suggestion data, and keep shared assets such as `wcpay.svg`, payment logos, and reusable onboarding components when they still have live callers elsewhere.

**Tech Stack:** WooCommerce Core PHP, WC Admin React/TypeScript, PHPUnit, Jest, source-boundary regression scans, PHPStan, ESLint, type declarations, manual changed-line PHPCS when deleted files confuse wrapper scripts.

---

### Task 1: Lock the Deprecated Promotion Surface With RED Tests

**Files:**
- Modify: `plugins/woocommerce/tests/php/src/Admin/Features/WooPaymentsLegacyAdminRuntimeBoundaryTest.php`
- Modify: `plugins/woocommerce/client/admin/client/payments/test/payment-recommendations.test.tsx`

- [x] **Step 1: Extend the PHP boundary test for deprecated promotion removal**

Add a new test that asserts these files no longer exist:

```php
'src/Internal/Admin/WCPayPromotion/Init.php',
'src/Internal/Admin/WCPayPromotion/WCPaymentGatewayPreInstallWCPayPromotion.php',
'src/Internal/Admin/WCPayPromotion/WCPayPromotionDataSourcePoller.php',
'src/Internal/Admin/WCPayPromotion/DefaultPromotions.php',
'client/admin/client/wp-admin-scripts/payment-method-promotions/index.tsx',
'client/admin/client/wp-admin-scripts/payment-method-promotions/payment-promotion-row.tsx',
'client/admin/client/wp-admin-scripts/payment-method-promotions/payment-promotion-row.scss',
```

Also scan production PHP sources for:

```php
'Internal\\Admin\\WCPayPromotion\\Init',
'Admin\\Features\\WcPayPromotion\\Init',
'pre_install_woocommerce_payments_promotion',
'payment-method-promotions',
```

and scan production client/config sources for:

```text
'wc-pay-promotion'
'pre_install_woocommerce_payments_promotion'
'woocommerce_payments_displayed'
```

The client-side scan should target:

```text
client/admin/client/payments/
client/admin/client/wp-admin-scripts/
client/admin/client/typings/
client/admin/config/
includes/react-admin/
```

Expected RED: the files still exist and the feature metadata plus pseudo-gateway symbols are still present in production sources.

- [x] **Step 2: Tighten the payment recommendations Jest expectations**

In `payment-recommendations.test.tsx`:

1. Change the pageview expectation so it no longer includes the fake pseudo-gateway prop:

```ts
expect( recordEvent ).toHaveBeenCalledWith(
	'settings_payments_recommendations_pageview',
	{
		test_displayed: true,
	}
);
```

2. Replace the pseudo-gateway DOM probe test with an assertion that the removed promotion row no longer affects pageview props.

Expected RED: the current component still emits `woocommerce_payments_displayed` from the removed pseudo-gateway path.

- [x] **Step 3: Run RED**

Run:

```bash
pnpm --filter='@woocommerce/plugin-woocommerce' test:php:env -- --filter WooPaymentsLegacyAdminRuntimeBoundaryTest
pnpm --filter='@woocommerce/admin-library' test:js -- client/payments/test/payment-recommendations.test.tsx
```

Expected RED: the PHP boundary test still finds the promotion files/symbols, and the Jest suite still expects or observes the stale pseudo-gateway behavior.

### Task 2: Remove the Deprecated PHP Promotion Engine and Feature Metadata

**Files:**
- Delete: `plugins/woocommerce/src/Internal/Admin/WCPayPromotion/Init.php`
- Delete: `plugins/woocommerce/src/Internal/Admin/WCPayPromotion/WCPaymentGatewayPreInstallWCPayPromotion.php`
- Delete: `plugins/woocommerce/src/Internal/Admin/WCPayPromotion/WCPayPromotionDataSourcePoller.php`
- Delete: `plugins/woocommerce/src/Internal/Admin/WCPayPromotion/DefaultPromotions.php`
- Delete: `plugins/woocommerce/tests/php/src/Internal/Admin/WCPayPromotion/InitTest.php`
- Delete: `plugins/woocommerce/tests/php/src/Internal/Admin/WCPayPromotion/DefaultPromotionsTest.php`
- Modify: `plugins/woocommerce/src/Admin/Features/Features.php`
- Modify: `plugins/woocommerce/src/Admin/Features/Blueprint/Exporters/ExportWCPaymentGateways.php`
- Modify: `plugins/woocommerce/includes/react-admin/feature-config.php`
- Modify: `plugins/woocommerce/client/admin/config/core.json`
- Modify: `plugins/woocommerce/client/admin/client/typings/global.d.ts`

- [x] **Step 1: Delete the deprecated promotion engine classes and their PHP tests**

Delete the entire `src/Internal/Admin/WCPayPromotion/` subsystem and its focused PHPUnit coverage. The engine is deprecated, no longer needed for native payments, and the client row it feeds is being removed in the same chunk.

- [x] **Step 2: Remove the feature-loader and alias metadata**

In `Features.php`, remove the `wc-pay-promotion` compatibility alias entry from `register_internal_class_aliases()`. The feature metadata is also being removed, so Core should no longer try to resolve or instantiate this deprecated subsystem.

- [x] **Step 3: Remove stale promotion-only metadata and exporter exclusions**

1. Remove `wc-pay-promotion` from:

```text
plugins/woocommerce/includes/react-admin/feature-config.php
plugins/woocommerce/client/admin/config/core.json
plugins/woocommerce/client/admin/client/typings/global.d.ts
```

2. Remove the fake-gateway exclusion from `ExportWCPaymentGateways.php`, because the pseudo gateway will no longer be registered at all.

### Task 3: Remove the Promotion-Only Client Surface and Keep Shared Assets Intact

**Files:**
- Delete: `plugins/woocommerce/client/admin/client/wp-admin-scripts/payment-method-promotions/index.tsx`
- Delete: `plugins/woocommerce/client/admin/client/wp-admin-scripts/payment-method-promotions/payment-promotion-row.tsx`
- Delete: `plugins/woocommerce/client/admin/client/wp-admin-scripts/payment-method-promotions/payment-promotion-row.scss`
- Modify: `plugins/woocommerce/client/admin/client/payments/payment-recommendations.tsx`
- Modify: `plugins/woocommerce/client/admin/client/payments/test/payment-recommendations.test.tsx`
- Modify: `plugins/woocommerce/client/admin/client/wp-admin-scripts/README.md`

- [x] **Step 1: Delete the promotion-only wp-admin script entrypoint and row renderer**

Delete the entire `payment-method-promotions/` directory. These files only exist to decorate the fake WooPayments promotion row and should disappear with that row.

- [x] **Step 2: Simplify payment recommendations to use real suggestion data only**

In `payment-recommendations.tsx`:

1. Remove the top-level DOM probe for:

```ts
'[data-gateway_id="pre_install_woocommerce_payments_promotion"]'
```

2. Remove the extra `woocommerce_payments_displayed` pageview property.
3. Remove the `wc-pay-promotion` feature-flag guard that suppresses WooPayments suggestions.
4. Keep the existing install, dismiss, redirect, and marketplace-link behavior unchanged.

- [x] **Step 3: Update the Jest coverage to match the simplified behavior**

Adjust `payment-recommendations.test.tsx` so it verifies:

1. pageview props come only from real suggestions
2. WooPayments suggestions are not filtered by a stale feature flag
3. the install and dismiss flows still behave as before

- [x] **Step 4: Keep shared assets and docs accurate**

Update `wp-admin-scripts/README.md` so it no longer points to the removed `payment-method-promotions` example. Do not remove shared WooPayments assets like `assets/images/onboarding/wcpay.svg` or the payment-method SVG icons, because live settings/suggestion surfaces still reference them elsewhere.

### Task 4: Verify, Log, Changelog, and Commit

**Files:**
- Create: `plugins/woocommerce/changelog/add-native-payments-b3v-remove-wcpay-promotion-surface`
- Modify: `.agents/scratchpad/sessions/2026-06-15-core-native-payments/implementation-log.md`
- Modify: `.agents/scratchpad/sessions/2026-06-15-core-native-payments/plans/2026-06-16-core-native-payments-b3v.md`
- Modify: `.agents/scratchpad/sessions/2026-06-15-core-native-payments/README.md`
- Modify: `/Users/vladolaru/Work/a8c/ai-prompts/goals/woopayments-merge/follow-up/staging-log.md`

- [x] **Step 1: Add changelog**

Create:

```text
plugins/woocommerce/changelog/add-native-payments-b3v-remove-wcpay-promotion-surface
```

with:

```text
Significance: patch
Type: dev
Comment: Remove the deprecated WooPayments promotion engine and pseudo-gateway surface from WooCommerce Core.
```

- [x] **Step 2: Run focused behavior gates**

Run:

```bash
pnpm --filter='@woocommerce/plugin-woocommerce' test:php:env -- --filter WooPaymentsLegacyAdminRuntimeBoundaryTest
pnpm --filter='@woocommerce/admin-library' test:js -- client/payments/test/payment-recommendations.test.tsx
```

Require both to pass.

- [x] **Step 3: Run static, type, and source-boundary gates**

Run:

```bash
php -l plugins/woocommerce/src/Admin/Features/Features.php
php -l plugins/woocommerce/src/Admin/Features/Blueprint/Exporters/ExportWCPaymentGateways.php
php -l plugins/woocommerce/tests/php/src/Admin/Features/WooPaymentsLegacyAdminRuntimeBoundaryTest.php
composer exec -- phpstan analyse src/Admin/Features/Features.php src/Admin/Features/Blueprint/Exporters/ExportWCPaymentGateways.php --memory-limit=2G
pnpm --filter='@woocommerce/admin-library' exec eslint client/payments/payment-recommendations.tsx client/payments/test/payment-recommendations.test.tsx --ext=js,ts,tsx
pnpm --filter='@woocommerce/admin-library' lint:lang:types
git diff --check
git diff --cached --check
```

Also rerun removal scans for:

```text
Internal\\Admin\\WCPayPromotion\\Init
Admin\\Features\\WcPayPromotion\\Init
wc-pay-promotion
pre_install_woocommerce_payments_promotion
payment-method-promotions
```

If `lint:php:changes` or its staged variant fails because deleted PHP files are still in the diff, rerun the equivalent changed-line PHPCS check manually with `./vendor/bin/phpcs-changed -s --git` and record that explicitly.

- [x] **Step 4: Update logs, commit, and run branch lint**

1. Update the scratchpad README latest progress, this plan, and the implementation log with the final B3v evidence.
2. Append the B3v block to the external staging log.
3. Commit the code and changelog as one logical B3v change.
4. Run:

```bash
pnpm --filter='@woocommerce/plugin-woocommerce' lint:changes:branch
```

5. Report the commit hash and the git range using:

```text
7699deb62f...<new-commit>
```
