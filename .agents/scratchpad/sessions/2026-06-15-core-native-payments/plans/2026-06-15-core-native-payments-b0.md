# Core Native Payments B0 Multi-Currency Boundary Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use $subagent-driven-development (recommended) or $executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add the B0 multi-currency extraction boundary: core domain home, rate-provider seam, and runtime ownership arbiter that keeps plugin multi-currency and core multi-currency mutually exclusive.

**Architecture:** B0 is a non-mutating scaffold under `plugins/woocommerce/src/Internal/MultiCurrency/`. It defines the interface boundary copied from the plugin's five `MultiCurrency*Interface` contracts, introduces a provider-neutral `CurrencyRateProvider` seam for automatic FX, and adds `MultiCurrencyRuntimeArbiter` that reuses `NativePaymentsRuntimeArbiter` so payments ownership and multi-currency ownership cannot split. No pricing hooks, REST routes, frontend assets, or conversion engine are registered in B0.

**Tech Stack:** PHP internal classes, WooCommerce DI, PHPUnit via `wp-env`, PHPStan, PHPCS changed-line lint.

---

## Tasks

### Task 1: Add B0 Red Tests

**Files:**

- Create: `plugins/woocommerce/tests/php/src/Internal/MultiCurrency/MultiCurrencyRuntimeArbiterTest.php`
- Create: `plugins/woocommerce/tests/php/src/Internal/MultiCurrency/MultiCurrencyDomainMapTest.php`

- [ ] **Step 1: Write runtime arbiter tests**

Create tests proving:

- Plugin-mode payments ownership makes multi-currency owner `plugin`.
- Native-mode payments ownership makes multi-currency owner `core`.
- No payments owner makes multi-currency owner `none`.
- Core multi-currency cannot register while the plugin owns payments, even when the native payments flag is enabled.

- [ ] **Step 2: Write domain map tests**

Create tests proving:

- The source module is `includes/multi-currency`.
- The core domain namespace is `Automattic\WooCommerce\Internal\MultiCurrency`.
- The supply strategy has three core-default interfaces (`cache`, `localization`, `settings`) and two rate-provider-supplied interfaces (`account`, `api_client`).
- The map records the hard-preserved multi-currency order meta keys and the main price/currency pipeline hooks.

- [ ] **Step 3: Run red tests**

Run:

```bash
pnpm --filter='@woocommerce/plugin-woocommerce' test:php:env -- --filter 'MultiCurrencyRuntimeArbiterTest|MultiCurrencyDomainMapTest'
```

Expected: FAIL because the B0 classes do not exist yet.

### Task 2: Add B0 Boundary Classes

**Files:**

- Create: `plugins/woocommerce/src/Internal/MultiCurrency/MultiCurrencyRuntimeArbiter.php`
- Create: `plugins/woocommerce/src/Internal/MultiCurrency/MultiCurrencyDomainMap.php`
- Create: `plugins/woocommerce/src/Internal/MultiCurrency/Interfaces/MultiCurrencyAccountInterface.php`
- Create: `plugins/woocommerce/src/Internal/MultiCurrency/Interfaces/MultiCurrencyApiClientInterface.php`
- Create: `plugins/woocommerce/src/Internal/MultiCurrency/Interfaces/MultiCurrencyCacheInterface.php`
- Create: `plugins/woocommerce/src/Internal/MultiCurrency/Interfaces/MultiCurrencyLocalizationInterface.php`
- Create: `plugins/woocommerce/src/Internal/MultiCurrency/Interfaces/MultiCurrencySettingsInterface.php`
- Create: `plugins/woocommerce/src/Internal/MultiCurrency/Interfaces/CurrencyRateProvider.php`

- [ ] **Step 1: Implement `MultiCurrencyRuntimeArbiter`**

Implement an internal class with constants `OWNER_PLUGIN`, `OWNER_CORE`, and `OWNER_NONE`, injected with `NativePaymentsRuntimeArbiter`. The owner is:

- `plugin` when the payments arbiter reports plugin ownership.
- `core` when the payments arbiter reports native ownership.
- `none` otherwise.

Expose `should_core_register()` and `should_plugin_register()` helpers for B1/B2 hook registration.

- [ ] **Step 2: Implement `MultiCurrencyDomainMap`**

Implement an internal immutable map class with constants/methods for:

- Source module and target namespace.
- Interface supply strategy.
- Preserved order meta keys: `_wcpay_multi_currency_stripe_exchange_rate`, `_wcpay_multi_currency_order_exchange_rate`, `_wcpay_multi_currency_order_default_currency`.
- Preserved option/session/user key families from the BC extraction.
- Main price/currency pipeline hooks.
- Main compatibility integrations from the plugin `Compatibility/` directory.

- [ ] **Step 3: Implement interfaces**

Add the five copied boundary interfaces and the `CurrencyRateProvider` seam. Keep them dependency-free and internal.

- [ ] **Step 4: Run green tests**

Run:

```bash
pnpm --filter='@woocommerce/plugin-woocommerce' test:php:env -- --filter 'MultiCurrencyRuntimeArbiterTest|MultiCurrencyDomainMapTest'
```

Expected: PASS.

### Task 3: Verify and Commit B0

**Files:**

- Create: `plugins/woocommerce/changelog/add-native-payments-b0-multi-currency-boundary`
- Test: focused B0 PHPUnit
- Test: focused payments arbiter regression

- [ ] **Step 1: Add changelog**

Create:

```text
Significance: minor
Type: add

Add the B0 multi-currency core-domain boundary and runtime ownership arbiter.
```

- [ ] **Step 2: Run static checks**

Run:

```bash
composer exec -- phpstan analyse src/Internal/MultiCurrency --memory-limit=2G
pnpm --filter='@woocommerce/plugin-woocommerce' lint:php:changes
git diff --check
```

Expected: all commands pass.

- [ ] **Step 3: Run regression tests**

Run:

```bash
pnpm --filter='@woocommerce/plugin-woocommerce' test:php:env -- --filter 'MultiCurrencyRuntimeArbiterTest|MultiCurrencyDomainMapTest|NativePaymentsRuntimeArbiterTest'
```

Expected: all tests pass.

- [ ] **Step 4: Adversarial review**

Dispatch a review agent focused on whether B0 accidentally allows split-brain multi-currency ownership or over-couples core multi-currency to WooPayments provider code. Reconcile any confirmed finding before commit.

- [ ] **Step 5: Commit B0**

Run:

```bash
git add plugins/woocommerce/src/Internal/MultiCurrency plugins/woocommerce/tests/php/src/Internal/MultiCurrency plugins/woocommerce/changelog/add-native-payments-b0-multi-currency-boundary
git commit -m "feat(payments): add B0 multi-currency boundary"
```

Expected: B0 commit is created without staging local plan docs or scratchpad logs.

### Self-Review

- Spec coverage: The plan covers B0 domain home, rate-provider seam, interface boundary, and arbiter ownership invariant. It intentionally leaves engine porting, shadow conversion, REST, client assets, and cleanup to B1-B3.
- Placeholder scan: No placeholder tasks remain.
- Type consistency: Class and interface names are consistent across files and commands.
