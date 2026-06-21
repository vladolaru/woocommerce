---
session: 2026-06-15-core-native-payments
type: plan
by: codex
created: 2026-06-16 10:28
status: draft
---

# Core Native Payments B3c Frontend Price Hook Manifest Cleanup Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use $subagent-driven-development (recommended) or $executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Reconcile the native multi-currency frontend price runtime manifest with the live frontend price controller registrations by adding the missing refund action metadata.

**Architecture:** `MultiCurrencyFrontendPricesController` already registers `woocommerce_order_refunded` with `add_refund_meta` when Core owns multi-currency. `MultiCurrencyRuntimeRegistry::get_frontend_price_hook_group()` should describe the same hook surface, keeping filters in `filters` and refund metadata persistence in `actions`.

**Tech Stack:** WooCommerce Core PHP, PHPUnit via `pnpm --filter='@woocommerce/plugin-woocommerce' test:php:env`, PHPStan, PHPCS, plugin changelog file.

---

## Files

- Modify: `plugins/woocommerce/tests/php/src/Internal/MultiCurrency/MultiCurrencyRuntimeRegistryTest.php` to assert that the frontend price hook group includes `woocommerce_order_refunded` as an action with callback `add_refund_meta`, priority `99`, and `2` accepted args.
- Modify: `plugins/woocommerce/src/Internal/MultiCurrency/MultiCurrencyRuntimeRegistry.php` to add the missing action entry to the `frontend_prices` hook group.
- Create: `plugins/woocommerce/changelog/add-native-payments-b3c-frontend-price-manifest-action`.

### Task 1: Add RED Manifest Coverage

**Files:**

- Modify: `plugins/woocommerce/tests/php/src/Internal/MultiCurrency/MultiCurrencyRuntimeRegistryTest.php`

- [ ] **Step 1: Update the frontend price manifest test to assert the refund action**

```php
$this->assertSame(
	array(
		array(
			'hook'          => 'woocommerce_order_refunded',
			'callback'      => 'add_refund_meta',
			'priority'      => 99,
			'accepted_args' => 2,
		),
	),
	$hook_groups['frontend_prices']['actions']
);
```

- [ ] **Step 2: Keep the no-duplicate assertion across filters and actions**

```php
$price_hooks = array_merge(
	array_column( $hook_groups['frontend_prices']['filters'], 'hook' ),
	array_column( $hook_groups['frontend_prices']['actions'], 'hook' )
);

$this->assertSame( $price_hooks, array_values( array_unique( $price_hooks ) ), 'The frontend price manifest must not declare duplicate price hooks.' );
```

- [ ] **Step 3: Run RED PHPUnit**

Run: `pnpm --filter='@woocommerce/plugin-woocommerce' test:php:env -- --filter 'MultiCurrencyRuntimeRegistryTest'`

Expected: FAIL because `frontend_prices.actions` is still empty and does not include `woocommerce_order_refunded`.

### Task 2: Add the Missing Manifest Action

**Files:**

- Modify: `plugins/woocommerce/src/Internal/MultiCurrency/MultiCurrencyRuntimeRegistry.php`

- [ ] **Step 1: Add the refund action entry to the frontend price hook group**

```php
'actions' => array(
	self::hook_entry( 'woocommerce_order_refunded', 'add_refund_meta', 99, 2 ),
),
```

- [ ] **Step 2: Run focused GREEN PHPUnit**

Run: `pnpm --filter='@woocommerce/plugin-woocommerce' test:php:env -- --filter 'MultiCurrencyRuntimeRegistryTest|MultiCurrencyFrontendPricesControllerTest'`

Expected: PASS, proving the manifest and live controller registration coverage agree for the frontend price group.

### Task 3: Verification, Changelog, and Commit

**Files:**

- Create: `plugins/woocommerce/changelog/add-native-payments-b3c-frontend-price-manifest-action`

- [ ] **Step 1: Add the changelog entry**

```text
Significance: patch
Type: dev
Comment: Add the missing refund action to the native multi-currency frontend price hook manifest.
```

- [ ] **Step 2: Run PHPStan for touched production file**

Run: `cd plugins/woocommerce && composer exec -- phpstan analyse src/Internal/MultiCurrency/MultiCurrencyRuntimeRegistry.php --memory-limit=2G`

Expected: No errors.

- [ ] **Step 3: Run scoped PHPCS for touched PHP files**

Run: `cd plugins/woocommerce && vendor/bin/phpcs -s src/Internal/MultiCurrency/MultiCurrencyRuntimeRegistry.php tests/php/src/Internal/MultiCurrency/MultiCurrencyRuntimeRegistryTest.php`

Expected: No errors for touched files.

- [ ] **Step 4: Run changed-file lint and whitespace checks**

Run: `pnpm --filter=@woocommerce/plugin-woocommerce lint:php:changes && git diff --check`

Expected: PASS. Do not run markdownlint against `.agents/scratchpad/sessions/2026-06-15-core-native-payments`.

- [ ] **Step 5: Stage source/test files and run staged checks**

Run: `git add plugins/woocommerce/src/Internal/MultiCurrency/MultiCurrencyRuntimeRegistry.php plugins/woocommerce/tests/php/src/Internal/MultiCurrency/MultiCurrencyRuntimeRegistryTest.php && pnpm --filter=@woocommerce/plugin-woocommerce lint:php:changes:staged && git diff --cached --check`

Expected: Both staged checks pass.

- [ ] **Step 6: Commit source/tests**

Run: `git commit -m "fix(payments): add refund action to price manifest"`

Expected: Commit succeeds. If signing/authentication fails, leave files staged and record the pending commit instead of retrying with altered signing flags.

- [ ] **Step 7: Stage and commit changelog**

Run: `git add plugins/woocommerce/changelog/add-native-payments-b3c-frontend-price-manifest-action && git commit -m "chore(payments): add price manifest cleanup changelog"`

Expected: Commit succeeds. If signing/authentication fails, leave the changelog staged and record the pending commit instead of retrying with altered signing flags.

## Self-Review

- Spec coverage: The plan addresses the known live-registration versus manifest drift for `woocommerce_order_refunded`.
- Placeholder scan: No `TBD`, `TODO`, `implement later`, or unnamed follow-up placeholders are present.
- Type consistency: The hook entry uses the same callback, priority, and accepted args as `MultiCurrencyFrontendPricesController::register()`.
