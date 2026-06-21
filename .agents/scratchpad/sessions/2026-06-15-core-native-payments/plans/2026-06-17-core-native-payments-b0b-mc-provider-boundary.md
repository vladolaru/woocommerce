---
session: 2026-06-15-core-native-payments
type: plan
by: codex
created: 2026-06-17 18:43
status: final
last_updated: 2026-06-17 18:51
---

# Multi-Currency Provider Boundary Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use $subagent-driven-development (recommended) or $executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Remove concrete WooPayments imports from generic `Internal\MultiCurrency` code so the B0/B1 payments-independent domain gate can be treated as source-clean for these known blockers.

**Architecture:** Keep provider-specific WooPayments automatic-FX and account readiness wiring inside `Internal\Payments\Providers\WooPayments\MultiCurrency\WooPaymentsMultiCurrencyProviderBootstrap`. Generic multi-currency classes depend only on `MultiCurrencyProviderAccountResolver` and `CurrencyRateProviderRegistrarInterface[]`; the WooPayments bootstrap supplies the concrete account adapter and rate-provider registrar at runtime.

**Tech Stack:** WooCommerce Core PHP in `plugins/woocommerce/src/Internal`, Core DI `init()` methods, PHPUnit integration tests, PHPStan, PHPCS.

---

## Files

- Modify: `plugins/woocommerce/src/Internal/MultiCurrency/MultiCurrencyAdminNoteController.php` to inject `MultiCurrencyProviderAccountResolver` instead of `WooPaymentsProvider`.
- Modify: `plugins/woocommerce/src/Internal/MultiCurrency/Providers/CurrencyRateProviderRegistryFactory.php` to depend only on `CurrencyRateProviderRegistrarInterface[]` configured through `set_provider_registrars()`.
- Modify: `plugins/woocommerce/src/Internal/Payments/Providers/WooPayments/MultiCurrency/WooPaymentsMultiCurrencyProviderBootstrap.php` to inject `CurrencyRateProviderRegistryFactory` and `WooPaymentsCurrencyRateProviderRegistrar`, then register the WooPayments registrar there.
- Modify: `plugins/woocommerce/tests/php/src/Internal/MultiCurrency/MultiCurrencyDomainMapTest.php` to assert the two source files no longer import `Internal\Payments\Providers\WooPayments`.
- Modify: `plugins/woocommerce/tests/php/src/Internal/MultiCurrency/MultiCurrencyAdminNoteControllerTest.php` to use `MultiCurrencyProviderAccountResolver` plus a fake `MultiCurrencyAccountInterface`.
- Modify: `plugins/woocommerce/tests/php/src/Internal/MultiCurrency/Providers/CurrencyRateProviderRegistryFactoryTest.php` to prove a standalone generic factory is empty by default and populated after the WooPayments bootstrap registers the registrar.
- Modify session docs: `implementation-log.md`, `staging-log.md`, and `review-agent-findings.md` as evidence warrants.

## Tasks

### Task 1: Domain-Boundary Regression

- [x] Add failing `MultiCurrencyDomainMapTest` assertions reading `MultiCurrencyAdminNoteController.php` and `CurrencyRateProviderRegistryFactory.php`, asserting neither file contains `Internal\\Payments\\Providers\\WooPayments`.
- [x] Run `pnpm --filter='@woocommerce/plugin-woocommerce' test:php:env -- --filter 'MultiCurrencyDomainMapTest'`.
- [x] Expected RED: the new assertions fail on the two current concrete imports.

### Task 2: Provider-Neutral Admin Note Boundary

- [x] Update `MultiCurrencyAdminNoteControllerTest` so `create_controller()` builds a `MultiCurrencyProviderAccountResolver`, optionally sets a fake account object, and calls `init( $arbiter, $account_resolver )`.
- [x] Update `MultiCurrencyAdminNoteController` to import and store `MultiCurrencyProviderAccountResolver`, and make `is_provider_connected()` call `$this->account_resolver->is_provider_connected()` when no explicit test resolver is set.
- [x] Cover the admin-note boundary in the combined focused run after Task 3 rather than stopping for a partial green run.
- [x] Expected GREEN for the admin-note boundary.

### Task 3: Provider-Owned Rate Registrar Wiring

- [x] Update `CurrencyRateProviderRegistryFactory` to remove the WooPayments registrar typehint from `init()`; keep `set_provider_registrars( array $provider_registrars )` as the only generic configuration seam.
- [x] Update `WooPaymentsMultiCurrencyProviderBootstrap::init()` to accept `MultiCurrencyProviderAccountResolver`, `WooPaymentsLegacyAccountAdapter`, `CurrencyRateProviderRegistryFactory`, and `WooPaymentsCurrencyRateProviderRegistrar`.
- [x] Update `WooPaymentsMultiCurrencyProviderBootstrap::register()` to call both `$account_resolver->set_account( $account_adapter )` and `$provider_registry_factory->set_provider_registrars( array( $woo_payments_provider_registrar ) )`.
- [x] Update `CurrencyRateProviderRegistryFactoryTest` so a standalone generic factory creates an empty/fail-closed registry by default, and a bootstrap-registered factory exposes `WooPaymentsCurrencyRateProvider` when the legacy boundaries are usable.
- [x] Run `pnpm --filter='@woocommerce/plugin-woocommerce' test:php:env -- --filter 'MultiCurrencyAdminNoteControllerTest|CurrencyRateProviderRegistryFactoryTest|MultiCurrencyDomainMapTest|MultiCurrencyStateBuilderFactoryTest'`.
- [x] Expected GREEN: the generic factory is provider-neutral, while WooPayments automatic FX still appears after the provider bootstrap registers its concrete registrar.

### Task 4: Focused Gates and Evidence

- [x] Run focused multi-currency architecture/runtime tests: `pnpm --filter='@woocommerce/plugin-woocommerce' test:php:env -- --filter 'MultiCurrencyDomainMapTest|MultiCurrencyAdminNoteControllerTest|CurrencyRateProviderRegistryFactoryTest|MultiCurrencyStateBuilderFactoryTest|MultiCurrencySettingsControllerTest|MultiCurrencyRuntimeRegistryTest'`.
- [x] Run PHP syntax for the touched source/test files.
- [x] Run PHPStan for the touched production classes.
- [x] Run `pnpm --filter=@woocommerce/plugin-woocommerce lint:php:changes` and `git diff --check`.
- [x] Update `implementation-log.md`, `staging-log.md`, and `review-agent-findings.md` with the blocker closure and any residual limitations.
- [x] Decide commit boundary after seeing the final diff, because the current worktree already contains the prior N7a/N7b package; do not mix unrelated work into one commit without an explicit logical boundary.

## Self-Review

- Spec coverage: closes the source-verified B0/B1 blocker named in `spec-conformance-baseline.md` for the two known concrete WooPayments imports in generic multi-currency code. It does not claim B0/B1 fully complete beyond this source-backed blocker.
- Placeholder scan: no TODO/TBD/open implementation placeholders.
- Type consistency: generic code depends on `MultiCurrencyProviderAccountResolver` and `CurrencyRateProviderRegistrarInterface`; WooPayments concrete classes remain under the WooPayments provider namespace and are wired only from `WooPaymentsMultiCurrencyProviderBootstrap`.
