---
session: 2026-06-15-core-native-payments
type: analysis
by: codex
created: 2026-06-17 07:16
last_updated: 2026-06-17 07:28
status: draft
---

# B3ag Selection Analysis

## Prompt Context

> **Prompt:** "Continue"

> **Prompt:** "Make sure you also take into account frontend code and assets that were used on removed deprecated surfaces and are no longer used anywhere else"

> **Prompt:** "Make sure the WooPayments specific frontend assets are build through the same workflows as the rest of WooCommerce codebase are, since these are now core owned surfaces/UI. They should just be handled naturally,m like everything else."

> **Prompt:** "Backtrack to all the gates you skipped because they relied on the harness, re-run those gates and fix everything you find in each. Then proceed with the plan until completion and don't skip verification gates anymore"

> **Prompt:** "Also, why isn't http://store8889.localhost:8889/wp-admin/admin.php?page=wc-settings&tab=checkout loading with the providers list (including WooPayments) like http://localhost:8082/wp-admin/admin.php?page=wc-settings&tab=checkout is? Are you absolutely sure it is a limitation of the http://store8889.localhost:8889/ local env or is it something we broke? The frontend UX of the WooCommerce > Settings > Payments page should not be affected by our changes - it needs to work as it used to work because it is not WooPayments specific (WooPayments is a provider just like any other, even if now it is a WC native provider). Think deeply about how we wire that in in a proper way."

## Current State

- B3af is committed as `ee5ef0841a0432e239f0449bac89f29006297d09` and logged in both the implementation log and staging log.
- The tracked worktree is clean.
- WPCOM remains off limits; selection must use local source, the reference local store, and the preserved BC manifest only.

## Investigation Notes

- Two read-only explorers split the remaining work into two high-value gaps. One identified missing WooPay runtime continuity: native Core still lacks `payments/woopay/session`, WooPay AJAX/session endpoints, WooPay persisted-data cleanup, and WooPay cron hooks. That is a hard external contract and should be the next runtime-facing preserve chunk after B3ag.
- The second explorer identified a more immediate admin-provider wiring problem that lines up with the latest user concern about the generic WooCommerce > Settings > Payments UX: task-list and Launch Your Store entrypoints still treat WooPayments as an installable legacy plugin instead of a native Core-owned provider.
- Local inspection confirms the admin-entrypoint gap. `plugins/woocommerce/client/admin/client/task-lists/fills/PaymentGatewaySuggestions/components/WCPay/utils.js` still posts to `/wc-admin/plugins/connect-wcpay` and calls `installAndActivatePlugins( [ 'woocommerce-payments' ] )`.
- Local inspection confirms Launch Your Store still derives `isWooPaymentsActive` and `isWooPaymentsInstalled` from the plugins store in `plugins/woocommerce/client/admin/client/launch-your-store/data/setup-payments-context.tsx`, then renders an install/enable step from `plugins/woocommerce/client/admin/client/launch-your-store/hub/main-content/pages/payments-content.tsx` until the plugin is active.
- Backend inspection confirms `/wc-admin/plugins/connect-wcpay` still requires `WooPaymentsLegacyRuntime::is_loaded()` in `plugins/woocommerce/src/Admin/API/Plugins.php`. That makes the endpoint unsuitable as the native Core-owned onboarding bridge.
- Payment recommendation metadata in `plugins/woocommerce/src/Admin/Features/PaymentGatewaySuggestions/DefaultPaymentGateways.php` still lists `plugins => [ 'woocommerce-payments' ]` for WooPayments suggestions and provides helper rules that only check the external plugin activation state.
- Settings Payments itself already has native provider/onboarding support under `plugins/woocommerce/client/admin/client/settings-payments/` and a native onboarding REST controller under `plugins/woocommerce/src/Internal/Admin/Settings/PaymentsProviders/WooPayments/`. The next admin chunk should route the older Core admin entrypoints to that native flow, not rebuild Settings Payments.
- The WooPayments frontend assets currently under Core are source assets that flow through WooCommerce build outputs. The issue is not that these files need a separate WooPayments build; it is that several Core-owned UI entrypoints still point at plugin-install semantics.

## Decision

B3ag should be the native admin onboarding/provider entrypoint pass. The scope is bigger than a single button but still bounded: task-list payment suggestions, Launch Your Store payments onboarding, backend `connect-wcpay` fallback behavior, and provider recommendation metadata/tests.

This directly addresses the user-facing Settings Payments concern: WooPayments must be just another provider in generic Core-owned payment surfaces, even though the provider implementation is native. The local-env failure was not a limitation to paper over; it exposed a real wiring class where Core surfaces could depend on the old plugin runtime.

WooPay runtime continuity remains a hard preserve gap and should be promoted as the following chunk, because it is external/runtime-facing and needs its own REST/AJAX/session/cron tests.
