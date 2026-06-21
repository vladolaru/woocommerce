---
session: 2026-06-15-core-native-payments
type: plan
by: codex
created: 2026-06-16 10:18
status: draft
---

# Core Native Payments B3b WooPayments Rate Source Namespace Cleanup Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use $subagent-driven-development (recommended) or $executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Move the WooPayments-specific multi-currency automatic-rate implementation out of the generic multi-currency namespace and into the WooPayments provider namespace.

**Architecture:** `Automattic\WooCommerce\Internal\MultiCurrency` keeps the provider-neutral interfaces, registry, and registry factory. The first-party WooPayments rate provider, registrar, and legacy account/API adapters move under `Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\MultiCurrency`, where provider-specific implementation belongs, while the registry factory still consumes the registrar seam created in B3a.

**Tech Stack:** WooCommerce Core PHP, PSR-4 classes under `plugins/woocommerce/src/Internal`, PHPUnit via `pnpm --filter='@woocommerce/plugin-woocommerce' test:php:env`, PHPStan, PHPCS, plugin changelog file.

---

## Files

- Modify: `plugins/woocommerce/tests/php/src/Internal/MultiCurrency/MultiCurrencyDomainMapTest.php` to add RED coverage that WooPayments rate-source implementation lives in the payments provider namespace and old multi-currency class names are gone.
- Move: `plugins/woocommerce/src/Internal/MultiCurrency/Providers/WooPaymentsCurrencyRateProvider.php` to `plugins/woocommerce/src/Internal/Payments/Providers/WooPayments/MultiCurrency/WooPaymentsCurrencyRateProvider.php`.
- Move: `plugins/woocommerce/src/Internal/MultiCurrency/Providers/WooPaymentsCurrencyRateProviderRegistrar.php` to `plugins/woocommerce/src/Internal/Payments/Providers/WooPayments/MultiCurrency/WooPaymentsCurrencyRateProviderRegistrar.php`.
- Move: `plugins/woocommerce/src/Internal/MultiCurrency/Providers/WooPaymentsLegacyAccountAdapter.php` to `plugins/woocommerce/src/Internal/Payments/Providers/WooPayments/MultiCurrency/WooPaymentsLegacyAccountAdapter.php`.
- Move: `plugins/woocommerce/src/Internal/MultiCurrency/Providers/WooPaymentsLegacyApiClientAdapter.php` to `plugins/woocommerce/src/Internal/Payments/Providers/WooPayments/MultiCurrency/WooPaymentsLegacyApiClientAdapter.php`.
- Modify: `plugins/woocommerce/src/Internal/MultiCurrency/Providers/CurrencyRateProviderRegistryFactory.php` to import the moved registrar.
- Move/update tests for the moved provider classes under `plugins/woocommerce/tests/php/src/Internal/Payments/Providers/WooPayments/MultiCurrency/`.
- Modify: `plugins/woocommerce/tests/php/src/Internal/MultiCurrency/Providers/CurrencyRateProviderRegistryFactoryTest.php` to import moved WooPayments classes.
- Modify: `plugins/woocommerce/tests/php/src/Internal/MultiCurrency/MultiCurrencySettingsControllerTest.php` only if the moved adapter import needs updating.
- Modify: `plugins/woocommerce/src/Internal/MultiCurrency/MultiCurrencySettingsController.php` only if the moved adapter import needs updating; a later slice should replace this direct dependency with a resolver because Core DI cannot inject interfaces directly.
- Create: `plugins/woocommerce/changelog/add-native-payments-b3b-move-woopayments-rate-source`.

### Task 1: Add RED Namespace Boundary Coverage

**Files:**

- Modify: `plugins/woocommerce/tests/php/src/Internal/MultiCurrency/MultiCurrencyDomainMapTest.php`

- [ ] **Step 1: Add a test that proves WooPayments rate-source implementation belongs to the payments provider namespace**

```php
/**
 * @testdox Should keep WooPayments rate source implementation in the payments provider namespace.
 */
public function test_woopayments_rate_source_implementation_lives_in_payments_provider_namespace(): void {
	$this->assertTrue( class_exists( 'Automattic\\WooCommerce\\Internal\\Payments\\Providers\\WooPayments\\MultiCurrency\\WooPaymentsCurrencyRateProvider' ) );
	$this->assertTrue( class_exists( 'Automattic\\WooCommerce\\Internal\\Payments\\Providers\\WooPayments\\MultiCurrency\\WooPaymentsCurrencyRateProviderRegistrar' ) );
	$this->assertTrue( class_exists( 'Automattic\\WooCommerce\\Internal\\Payments\\Providers\\WooPayments\\MultiCurrency\\WooPaymentsLegacyAccountAdapter' ) );
	$this->assertTrue( class_exists( 'Automattic\\WooCommerce\\Internal\\Payments\\Providers\\WooPayments\\MultiCurrency\\WooPaymentsLegacyApiClientAdapter' ) );
}
```

- [ ] **Step 2: Add a test that proves the generic multi-currency namespace no longer owns those provider-specific implementations**

```php
/**
 * @testdox Should not keep WooPayments rate source implementation in the generic multi-currency namespace.
 */
public function test_woopayments_rate_source_implementation_is_not_in_generic_multi_currency_namespace(): void {
	$this->assertFalse( class_exists( 'Automattic\\WooCommerce\\Internal\\MultiCurrency\\Providers\\WooPaymentsCurrencyRateProvider' ) );
	$this->assertFalse( class_exists( 'Automattic\\WooCommerce\\Internal\\MultiCurrency\\Providers\\WooPaymentsCurrencyRateProviderRegistrar' ) );
	$this->assertFalse( class_exists( 'Automattic\\WooCommerce\\Internal\\MultiCurrency\\Providers\\WooPaymentsLegacyAccountAdapter' ) );
	$this->assertFalse( class_exists( 'Automattic\\WooCommerce\\Internal\\MultiCurrency\\Providers\\WooPaymentsLegacyApiClientAdapter' ) );
}
```

- [ ] **Step 3: Run RED PHPUnit**

Run: `pnpm --filter='@woocommerce/plugin-woocommerce' test:php:env -- --filter 'MultiCurrencyDomainMapTest'`

Expected: FAIL because the new provider namespace classes do not exist yet and the old generic multi-currency namespace classes still exist.

### Task 2: Move WooPayments Rate Source Classes

**Files:**

- Move/update the four production classes listed above.
- Modify: `plugins/woocommerce/src/Internal/MultiCurrency/Providers/CurrencyRateProviderRegistryFactory.php`
- Modify/update moved provider tests and factory/settings imports.

- [ ] **Step 1: Move `WooPaymentsCurrencyRateProvider` and update its namespace/imports**

```php
namespace Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\MultiCurrency;

use Automattic\WooCommerce\Internal\MultiCurrency\Interfaces\CurrencyRateProvider;
use Automattic\WooCommerce\Internal\MultiCurrency\Interfaces\MultiCurrencyAccountInterface;
use Automattic\WooCommerce\Internal\MultiCurrency\Interfaces\MultiCurrencyApiClientInterface;
```

- [ ] **Step 2: Move `WooPaymentsCurrencyRateProviderRegistrar` and update its namespace/imports**

```php
namespace Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\MultiCurrency;

use Automattic\WooCommerce\Internal\MultiCurrency\Providers\CurrencyRateProviderRegistrarInterface;
use Automattic\WooCommerce\Internal\MultiCurrency\Providers\CurrencyRateProviderRegistry;
```

- [ ] **Step 3: Move the legacy account/API adapters and update their namespace/imports**

```php
namespace Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\MultiCurrency;

use Automattic\WooCommerce\Internal\MultiCurrency\Interfaces\MultiCurrencyAccountInterface;
use Automattic\WooCommerce\Proxies\LegacyProxy;
```

```php
namespace Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\MultiCurrency;

use Automattic\WooCommerce\Internal\MultiCurrency\Interfaces\MultiCurrencyApiClientInterface;
use Automattic\WooCommerce\Proxies\LegacyProxy;
```

- [ ] **Step 4: Update `CurrencyRateProviderRegistryFactory` to import the moved registrar**

```php
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\MultiCurrency\WooPaymentsCurrencyRateProviderRegistrar;
```

- [ ] **Step 5: Move/update the provider-specific tests into the payments provider namespace**

Move tests to:

```text
plugins/woocommerce/tests/php/src/Internal/Payments/Providers/WooPayments/MultiCurrency/WooPaymentsCurrencyRateProviderTest.php
plugins/woocommerce/tests/php/src/Internal/Payments/Providers/WooPayments/MultiCurrency/WooPaymentsLegacyAccountAdapterTest.php
plugins/woocommerce/tests/php/src/Internal/Payments/Providers/WooPayments/MultiCurrency/WooPaymentsLegacyApiClientAdapterTest.php
```

Update their namespace to:

```php
namespace Automattic\WooCommerce\Tests\Internal\Payments\Providers\WooPayments\MultiCurrency;
```

- [ ] **Step 6: Update any remaining imports from the old namespace**

Run: `rg -n "Internal\\\\MultiCurrency\\\\Providers\\\\WooPayments|MultiCurrency\\\\Providers\\\\WooPayments|WooPaymentsLegacyAccountAdapter|WooPaymentsLegacyApiClientAdapter|WooPaymentsCurrencyRateProviderRegistrar|WooPaymentsCurrencyRateProvider" plugins/woocommerce/src plugins/woocommerce/tests/php/src`

Expected: Remaining references either point to `Internal\Payments\Providers\WooPayments\MultiCurrency\...` or are class-name strings in the new namespace boundary tests.

- [ ] **Step 7: Run focused GREEN PHPUnit**

Run: `pnpm --filter='@woocommerce/plugin-woocommerce' test:php:env -- --filter 'MultiCurrencyDomainMapTest|CurrencyRateProviderRegistryFactoryTest|WooPaymentsCurrencyRateProviderTest|WooPaymentsLegacyAccountAdapterTest|WooPaymentsLegacyApiClientAdapterTest|MultiCurrencySettingsControllerTest|MultiCurrencyRateServiceTest|MultiCurrencyStateBuilderFactoryTest'`

Expected: PASS, preserving rate provider behavior while enforcing the namespace boundary.

### Task 3: Verification, Changelog, and Commit

**Files:**

- Create: `plugins/woocommerce/changelog/add-native-payments-b3b-move-woopayments-rate-source`

- [ ] **Step 1: Add the changelog entry**

```text
Significance: patch
Type: dev
Comment: Move WooPayments multi-currency rate source implementation under the WooPayments provider namespace.
```

- [ ] **Step 2: Run PHPStan for touched production files**

Run: `cd plugins/woocommerce && composer exec -- phpstan analyse src/Internal/MultiCurrency/Providers/CurrencyRateProviderRegistryFactory.php src/Internal/MultiCurrency/Providers/CurrencyRateProviderRegistry.php src/Internal/MultiCurrency/Providers/CurrencyRateProviderRegistrarInterface.php src/Internal/Payments/Providers/WooPayments/MultiCurrency/WooPaymentsCurrencyRateProvider.php src/Internal/Payments/Providers/WooPayments/MultiCurrency/WooPaymentsCurrencyRateProviderRegistrar.php src/Internal/Payments/Providers/WooPayments/MultiCurrency/WooPaymentsLegacyAccountAdapter.php src/Internal/Payments/Providers/WooPayments/MultiCurrency/WooPaymentsLegacyApiClientAdapter.php src/Internal/MultiCurrency/MultiCurrencySettingsController.php --memory-limit=2G`

Expected: No errors.

- [ ] **Step 3: Run scoped PHPCS for touched PHP files**

Run: `cd plugins/woocommerce && vendor/bin/phpcs -s src/Internal/MultiCurrency/Providers/CurrencyRateProviderRegistryFactory.php src/Internal/Payments/Providers/WooPayments/MultiCurrency/WooPaymentsCurrencyRateProvider.php src/Internal/Payments/Providers/WooPayments/MultiCurrency/WooPaymentsCurrencyRateProviderRegistrar.php src/Internal/Payments/Providers/WooPayments/MultiCurrency/WooPaymentsLegacyAccountAdapter.php src/Internal/Payments/Providers/WooPayments/MultiCurrency/WooPaymentsLegacyApiClientAdapter.php tests/php/src/Internal/MultiCurrency/MultiCurrencyDomainMapTest.php tests/php/src/Internal/MultiCurrency/Providers/CurrencyRateProviderRegistryFactoryTest.php tests/php/src/Internal/Payments/Providers/WooPayments/MultiCurrency/WooPaymentsCurrencyRateProviderTest.php tests/php/src/Internal/Payments/Providers/WooPayments/MultiCurrency/WooPaymentsLegacyAccountAdapterTest.php tests/php/src/Internal/Payments/Providers/WooPayments/MultiCurrency/WooPaymentsLegacyApiClientAdapterTest.php`

Expected: No errors for touched files.

- [ ] **Step 4: Run changed-file lint and whitespace checks**

Run: `pnpm --filter=@woocommerce/plugin-woocommerce lint:php:changes && git diff --check`

Expected: PASS. Do not run markdownlint against `.agents/scratchpad/sessions/2026-06-15-core-native-payments`.

- [ ] **Step 5: Stage source/test files and run staged checks**

Run: `git add plugins/woocommerce/src/Internal/MultiCurrency/Providers/CurrencyRateProviderRegistryFactory.php plugins/woocommerce/src/Internal/Payments/Providers/WooPayments/MultiCurrency plugins/woocommerce/tests/php/src/Internal/MultiCurrency/MultiCurrencyDomainMapTest.php plugins/woocommerce/tests/php/src/Internal/MultiCurrency/Providers/CurrencyRateProviderRegistryFactoryTest.php plugins/woocommerce/tests/php/src/Internal/Payments/Providers/WooPayments/MultiCurrency && git add -u plugins/woocommerce/src/Internal/MultiCurrency/Providers plugins/woocommerce/tests/php/src/Internal/MultiCurrency/Providers && pnpm --filter=@woocommerce/plugin-woocommerce lint:php:changes:staged && git diff --cached --check`

Expected: Both staged checks pass.

- [ ] **Step 6: Commit source/tests**

Run: `git commit -m "refactor(payments): move WooPayments rate source provider"`

Expected: Commit succeeds. If signing/authentication fails, leave files staged and record the pending commit instead of retrying with altered signing flags.

- [ ] **Step 7: Stage and commit changelog**

Run: `git add plugins/woocommerce/changelog/add-native-payments-b3b-move-woopayments-rate-source && git commit -m "chore(payments): add rate source cleanup changelog"`

Expected: Commit succeeds. If signing/authentication fails, leave the changelog staged and record the pending commit instead of retrying with altered signing flags.

## Self-Review

- Spec coverage: B3 cleanup removes WooPayments-specific rate source implementation from the generic multi-currency namespace while keeping the provider-neutral rate seam in MultiCurrency.
- Placeholder scan: No `TBD`, `TODO`, `implement later`, or unnamed follow-up placeholders are present.
- Type consistency: The new namespace `Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\MultiCurrency` is used consistently across source, tests, and verification commands.
