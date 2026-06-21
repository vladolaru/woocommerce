---
session: 2026-06-15-core-native-payments
type: plan
by: codex
created: 2026-06-16 22:50
tool: writing-plans
reconciles:
  - implementation-log.md
  - staging-log.md
  - plans/2026-06-16-core-native-payments-b3v.md
  - plans/2026-06-16-core-native-payments-b3w.md
status: draft
last_updated: 2026-06-17 00:06
---

# Core Native Payments B3x Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use $subagent-driven-development (recommended) or $executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Remove stale WooPayments frontend assets left behind by deleted deprecated surfaces, keep active WooPayments frontend assets owned by normal WooCommerce build workflows, and preserve merchant/shopper-facing frontend parity for native WooPayments checkout and WooCommerce > Settings > Payments.

**Architecture:** Source files remain the authority: active WooPayments admin/settings, checkout, and Blocks entries stay in their existing WooCommerce source trees and build through the admin, classic-assets, and Blocks package workflows. Deprecated welcome-page/promotion generated outputs are removed, and the boundary test gains generated-asset assertions so stale artifacts cannot silently return. Native WooPayments checkout receives test-mode state and country-specific card guidance from the Core-owned account/checkout bridge, while the WooCommerce > Settings > Payments page continues to use the generic provider-list backend/data-store flow with WooPayments represented as a native provider rather than a special frontend-only bypass.

**Tech Stack:** WooCommerce monorepo, PHP boundary tests via wp-env PHPUnit, admin webpack build, classic assets build, Blocks build, local wp-env reference/target harness.

---

### Task 1: Guard Against Deprecated Generated Assets

**Files:**
- Modify: `plugins/woocommerce/tests/php/src/Admin/Features/WooPaymentsLegacyAdminRuntimeBoundaryTest.php`

- [x] **Step 1: Add failing boundary assertions**

Add deleted generated assets to the existing deprecated welcome-page and promotion surface tests:

```php
'assets/client/admin/chunks/wcpay-payment-welcome-page.js',
'assets/client/admin/wp-admin-scripts/payment-method-promotions.asset.php',
'assets/client/admin/wp-admin-scripts/payment-method-promotions.js',
'assets/client/admin/wp-admin-scripts/payment-method-promotions.js.LICENSE.txt',
'assets/client/admin/payment-method-promotions/style.asset.php',
'assets/client/admin/payment-method-promotions/style.css',
'assets/client/admin/payment-method-promotions/style-rtl.css',
```

- [x] **Step 2: Run the focused PHP boundary test and verify red**

Run: `pnpm --filter='@woocommerce/plugin-woocommerce' test:php:env -- --filter WooPaymentsLegacyAdminRuntimeBoundaryTest`

Expected: FAIL because the generated deprecated assets still exist.

### Task 2: Remove Stale Generated Assets And Normalize Active WooPayments Chunk Naming

**Files:**
- Modify: `plugins/woocommerce/client/admin/client/settings-payments/index.tsx`
- Modify: `plugins/woocommerce/client/admin/client/settings-payments/settings-payments-woopayments.tsx`
- Modify: `plugins/woocommerce/client/admin/client/settings-payments/settings-payments-woopayments.scss`
- Delete: `plugins/woocommerce/assets/client/admin/chunks/wcpay-payment-welcome-page.js`
- Delete: `plugins/woocommerce/assets/client/admin/wp-admin-scripts/payment-method-promotions.asset.php`
- Delete: `plugins/woocommerce/assets/client/admin/wp-admin-scripts/payment-method-promotions.js`
- Delete: `plugins/woocommerce/assets/client/admin/wp-admin-scripts/payment-method-promotions.js.LICENSE.txt`
- Delete: `plugins/woocommerce/assets/client/admin/payment-method-promotions/style.asset.php`
- Delete: `plugins/woocommerce/assets/client/admin/payment-method-promotions/style.css`
- Delete: `plugins/woocommerce/assets/client/admin/payment-method-promotions/style-rtl.css`

- [x] **Step 1: Rename the active lazy chunk**

Change the dynamic import chunk comment from `settings-payments-woocommerce-payments` to `settings-payments-woopayments`.

- [x] **Step 2: Rename the local placeholder class**

Change `settings-payments-woocommerce-payments__container` to `settings-payments-woopayments__container` in the TSX and SCSS pair.

- [x] **Step 3: Delete the stale deprecated generated assets**

Remove the deleted-surface output files listed above. Do not delete live generated assets for active checkout, Blocks, settings, onboarding, or multi-currency paths.

- [x] **Step 4: Run the focused PHP boundary test and verify green**

Run: `pnpm --filter='@woocommerce/plugin-woocommerce' test:php:env -- --filter WooPaymentsLegacyAdminRuntimeBoundaryTest`

Expected: PASS.

### Task 3: Regenerate Build Outputs Through WooCommerce Workflows

**Files:**
- Generated output under `plugins/woocommerce/assets/client/admin/`
- Generated output under `plugins/woocommerce/assets/client/blocks/`
- Generated output under `plugins/woocommerce/assets/js/frontend/`

- [x] **Step 1: Build admin assets**

Run: `pnpm --filter='@woocommerce/admin-library' build`

Expected: PASS, `plugins/woocommerce/assets/client/admin/chunks/settings-payments-woopayments.js` exists, and the deleted deprecated generated assets stay absent.

- [x] **Step 2: Build classic checkout assets**

Run: `pnpm --filter='@woocommerce/classic-assets' build`

Expected: PASS and `plugins/woocommerce/assets/js/frontend/woopayments-checkout.js` remains produced by the normal classic assets workflow.

- [x] **Step 3: Build Blocks payment assets**

Run: `pnpm --filter='@woocommerce/block-library' build`

Expected: PASS and `plugins/woocommerce/assets/client/blocks/wc-payment-method-woopayments.js` remains produced by the normal Blocks workflow.

### Task 4: Preserve Checkout Test-Mode Parity And Settings Payments Provider-List Parity

**Files:**
- Modify as needed: `plugins/woocommerce/src/Internal/Payments/Providers/WooPayments/WooPaymentsCheckoutBridge.php`
- Modify as needed: `plugins/woocommerce/client/blocks/assets/js/extensions/payment-methods/woopayments/index.js`
- Modify as needed: `plugins/woocommerce/client/blocks/assets/js/extensions/payment-methods/woopayments/test/index.js`
- Modify as needed: `plugins/woocommerce/client/admin/client/settings-payments/`
- Modify as needed: `plugins/woocommerce/src/Internal/Admin/Settings/PaymentsProviders/`
- Modify as needed: provider/list tests covering the affected PHP and JS seams.

- [x] **Step 1: Reproduce target/reference checkout and settings differences**

Compare reference and target Blocks/classic checkout test-mode UI plus `WooCommerce > Settings > Payments` provider-list rendering with browser/REST/WP-CLI evidence. Treat fresh console errors, network failures, and WP/PHP notices as failures to investigate.

- [x] **Step 2: Add failing regression coverage for confirmed product gaps**

For checkout, cover test-mode data in PHP config and Blocks/classic rendering. For settings, cover the backend/provider-list or client data-store seam that omits native WooPayments or prevents the generic provider list from rendering.

- [x] **Step 3: Implement the proper wiring**

Preserve WooPayments checkout's test badge and test-card guidance when the connected account is in test mode. Preserve the generic Settings Payments provider-list UX by wiring native WooPayments into the existing provider metadata flow used by other payment providers.

- [x] **Step 4: Verify red/green and browser parity**

Run focused tests, rebuild normal WooCommerce assets, and verify target/reference browser parity for checkout test-mode UI and Settings Payments provider list.

### Task 5: Run Tests, Lints, Harness, Browser/Log Gates, And Commit

**Files:**
- Modify: `plugins/woocommerce/changelog/add-native-payments-b3x-frontend-parity`
- Modify: `.agents/scratchpad/sessions/2026-06-15-core-native-payments/implementation-log.md`
- Modify: `.agents/scratchpad/sessions/2026-06-15-core-native-payments/staging-log.md`
- Modify: `.agents/scratchpad/sessions/2026-06-15-core-native-payments/README.md`

- [x] **Step 1: Run JS/PHP focused tests**

Run:

```bash
pnpm --filter='@woocommerce/admin-library' test:js -- settings-payments --runInBand
pnpm --filter='@woocommerce/classic-assets' test:js -- woopayments-checkout --runInBand
pnpm --filter='@woocommerce/block-library' test:js -- woopayments --runInBand
pnpm --filter='@woocommerce/plugin-woocommerce' test:php:env -- --filter WooPaymentsLegacyAdminRuntimeBoundaryTest
```

Expected: PASS or documented existing unrelated warning-only output.

- [x] **Step 2: Run lint and diff checks**

Run:

```bash
pnpm --filter='@woocommerce/plugin-woocommerce' lint:php:changes
pnpm --filter='@woocommerce/plugin-woocommerce' lint:changes:branch:js
git diff --check
```

Expected: PASS. Do not run markdown lint against `.agents/scratchpad/sessions/2026-06-15-core-native-payments`.

- [x] **Step 3: Run restored harness with reference and target stores**

Run:

```bash
bash tools/woopayments-merge/verify.sh --ref 'docker exec -i wcpay_wp_default wp --allow-root' --target 'docker exec -i 24860d14de30dc62f7b324ebef10b5fb-cli-1 wp --allow-root --user=1'
```

Expected: PASS all gates. Treat fresh WP/PHP warnings/notices as failures to investigate.

- [x] **Step 4: Run browser/UI smoke against reference and target**

Use local browser automation or direct HTTP probes to compare the reference store and target checkout/settings surfaces enough to prove no stale deprecated asset is needed and active WooPayments assets load on target. Record fresh log scans for target and reference containers.

- [x] **Step 5: Add changelog and commit locally**

Create `plugins/woocommerce/changelog/add-native-payments-b3x-frontend-parity` with:

```text
Significance: patch
Type: dev
Comment: Preserve native WooPayments frontend parity and remove stale generated assets.
```

Commit only WooCommerce repo changes, never WPCOM. Do not push.
