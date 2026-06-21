---
session: 2026-06-15-core-native-payments
type: plan
by: codex
created: 2026-06-16 13:54
status: final
last_updated: 2026-06-16 14:01
---

# B3s Explicit WooPayments Multi-Currency Account Bootstrap Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use $executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Remove the remaining WooPayments account-adapter type dependency from the generic multi-currency account resolver while preserving connected-provider settings behavior through an explicit WooPayments bootstrap.

**Architecture:** Keep `MultiCurrencyProviderAccountResolver` provider-neutral by making it an unconfigured resolver until a provider bootstrap calls `set_account()`. Add a WooPayments-owned bootstrap class under `Internal\Payments\Providers\WooPayments\MultiCurrency` that injects `WooPaymentsLegacyAccountAdapter` and `MultiCurrencyProviderAccountResolver`, then registers before generic multi-currency controllers in `includes/class-woocommerce.php`.

**Tech Stack:** WooCommerce Core PHP, reflection DI container, `WC_Unit_Test_Case`, legacy proxy mocks for WooPayments runtime boundaries.

---

### Task 1: Lock the Boundary With RED Tests

**Files:**
- Modify: `plugins/woocommerce/tests/php/src/Internal/MultiCurrency/Providers/MultiCurrencyProviderAccountResolverTest.php`
- Modify: `plugins/woocommerce/tests/php/src/Internal/MultiCurrency/MultiCurrencyDomainMapTest.php`
- Create: `plugins/woocommerce/tests/php/src/Internal/Payments/Providers/WooPayments/MultiCurrency/WooPaymentsMultiCurrencyProviderBootstrapTest.php`

- [ ] **Step 1: Add a source-boundary assertion for the generic resolver**

Add a test to `MultiCurrencyDomainMapTest` that reads `src/Internal/MultiCurrency/Providers/MultiCurrencyProviderAccountResolver.php` and asserts it contains `MultiCurrencyAccountInterface` but does not contain `WooPaymentsLegacyAccountAdapter` or `Internal\\Payments\\Providers\\WooPayments`.

- [ ] **Step 2: Add a WooPayments bootstrap behavior test**

Create `WooPaymentsMultiCurrencyProviderBootstrapTest` with a RED test that constructs `MultiCurrencyProviderAccountResolver`, `WooPaymentsLegacyRuntime`, `WooPaymentsLegacyAccountAdapter`, and `WooPaymentsMultiCurrencyProviderBootstrap`, mocks `WC_Payments::get_account_service()`, calls `$bootstrap->register()`, and asserts the resolver delegates `is_provider_connected()` and `get_provider_onboarding_page_url()` to the legacy account adapter.

- [ ] **Step 3: Add a bootstrap registration source test**

Add a second source-boundary test in `MultiCurrencyDomainMapTest` that reads `includes/class-woocommerce.php` and asserts the WooPayments multi-currency bootstrap registration appears before `MultiCurrencySettingsController::class`.

- [ ] **Step 4: Run focused RED**

Run `pnpm --filter='@woocommerce/plugin-woocommerce' test:php:env -- --filter 'MultiCurrencyProviderAccountResolverTest|MultiCurrencyDomainMapTest|WooPaymentsMultiCurrencyProviderBootstrapTest'`. Expected RED: the resolver source still imports `WooPaymentsLegacyAccountAdapter`, the bootstrap class does not exist, and `class-woocommerce.php` does not explicitly register it before settings.

### Task 2: Implement the Explicit Bootstrap

**Files:**
- Modify: `plugins/woocommerce/src/Internal/MultiCurrency/Providers/MultiCurrencyProviderAccountResolver.php`
- Create: `plugins/woocommerce/src/Internal/Payments/Providers/WooPayments/MultiCurrency/WooPaymentsMultiCurrencyProviderBootstrap.php`
- Modify: `plugins/woocommerce/includes/class-woocommerce.php`

- [ ] **Step 1: Remove provider-specific resolver injection**

Delete the `WooPaymentsLegacyAccountAdapter` import and the `init( WooPaymentsLegacyAccountAdapter $woo_payments_account_adapter )` method from `MultiCurrencyProviderAccountResolver`. Leave `set_account()` as the provider-neutral configuration API and keep the fail-closed behavior when no account is configured.

- [ ] **Step 2: Add the WooPayments bootstrap class**

Create `WooPaymentsMultiCurrencyProviderBootstrap` in the WooPayments multi-currency provider namespace. It should inject `MultiCurrencyProviderAccountResolver` and `WooPaymentsLegacyAccountAdapter` via final `init()`, implement `RegisterHooksInterface`, and its no-arg `register()` should call `$this->account_resolver->set_account( $this->account_adapter )`.

- [ ] **Step 3: Register bootstrap before generic multi-currency controllers**

In `plugins/woocommerce/includes/class-woocommerce.php`, add `$container->get( Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\MultiCurrency\WooPaymentsMultiCurrencyProviderBootstrap::class )->register();` after `MultiCurrencyShadowMode` and before `MultiCurrencyStoreCurrencyLifecycleController`.

- [ ] **Step 4: Run focused GREEN**

Run the same focused PHPUnit filter and require it to pass.

### Task 3: Verify, Changelog, Commit, and Log

**Files:**
- Create: `plugins/woocommerce/changelog/add-native-payments-b3s-woopayments-multi-currency-bootstrap`
- Modify: `.agents/scratchpad/sessions/2026-06-15-core-native-payments/implementation-log.md`
- Modify: `/Users/vladolaru/Work/a8c/ai-prompts/goals/woopayments-merge/follow-up/staging-log.md`

- [ ] **Step 1: Add changelog**

Create a WooCommerce changelog entry with `Significance: patch`, `Type: dev`, and `Comment: Decouple the generic multi-currency account resolver from WooPayments provider bootstrap.`

- [ ] **Step 2: Run static and focused gates**

Run `php -l` for touched PHP source/test files, PHPStan for touched production PHP files from `plugins/woocommerce`, scoped PHPCS for touched PHP files, focused PHPUnit for resolver/domain/bootstrap tests plus `CurrencyRateProviderRegistryFactoryTest` and `MultiCurrencySettingsControllerTest`, `pnpm --filter=@woocommerce/plugin-woocommerce lint:php:changes`, and `git diff --check`.

- [ ] **Step 3: Commit the B3s package**

Stage only the B3s code/test/changelog changes, run staged PHP lint checks, then commit with a conventional message such as `refactor(payments): decouple woopayments multi-currency bootstrap`.

- [ ] **Step 4: Update session logs**

Insert B3s evidence above `IMPLEMENTATION_LOG_APPEND_POINT` in the implementation log and add a B3s section above the staging log append marker, including the git range and verification summary.
