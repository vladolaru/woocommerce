---
session: 2026-06-15-core-native-payments
type: analysis
by: codex
created: 2026-06-20 23:45
target: A4az fraud ruleset refresh/defaulting parity
reconciles:
  - review-a4ay-fraud-explorer.md
  - analysis-a4ay-fraud-onboarding-tracking-parity.md
status: draft
---

# A4az Fraud Ruleset Refresh And Defaulting Parity

## Trigger

After A4ay restored the fraud onboarding tour and telemetry, the remaining fraud residuals need to be split into coherent slices. This analysis selects the backend read-contract slice before the larger advanced fraud card UI parity work.

## Source-Backed Gap

Native `plugins/woocommerce/src/Internal/Payments/Providers/WooPayments/WooPaymentsSettingsService.php` currently exposes `current_protection_level` from the local `current_protection_level` option and `advanced_fraud_protection_settings` from the `wcpay_fraud_protection_settings` transient, falling back to the local gateway `advanced_fraud_protection_settings` option when the transient is missing. Native `plugins/woocommerce/src/Internal/Payments/Providers/WooPayments/Api/WooPaymentsApiClient.php` only has `save_fraud_ruleset()` for `POST /wcpay/fraud_ruleset`; it has no read path for the latest platform ruleset.

The reference WooPayments client does more on reads. `/Users/vladolaru/Work/a8c/woocommerce-payments/includes/class-wc-payment-gateway-wcpay.php` calls `maybe_refresh_fraud_protection_settings()` before reading the fraud level or advanced settings. On transient miss, it calls `/Users/vladolaru/Work/a8c/woocommerce-payments/includes/wc-payment-api/class-wc-payments-api-client.php::get_latest_fraud_ruleset()`, caches `ruleset_config`, and updates `current_protection_level` to the matching Basic/Standard/High/Advanced preset. If the server returns `wcpay_fraud_ruleset_not_found`, the reference initializes the account to Basic by saving the Basic ruleset, caches it, and updates the local option to `basic`. On other failures or malformed responses, the reference leaves the transient empty so the UI receives the `"error"` sentinel and disables the advanced form rather than silently using stale local settings.

This is a native merchant-facing parity and stability gap: a store whose platform ruleset changes outside the local option path, or whose transient expires, can display stale settings or editable local-only state in native Core.

## Scope

A4az should restore the read-side fraud ruleset contract only: add native `get_latest_fraud_ruleset()`, refresh the ruleset on transient miss, map the fetched ruleset to the local protection level, initialize Basic on not-found, and return `"error"` when the platform refresh fails without a usable ruleset. The implementation should keep the existing save-side canonicalization and cached account fraud flag sync untouched.

The run-once guard should be native-owned and per service instance, not a method-static flag. That preserves the reference performance property that one settings request does not duplicate platform reads, while avoiding process-static test coupling in PHPUnit.

## Parked Residuals

The advanced fraud UI still has source-backed gaps: reference-style loading skeletons, per-card detail text, allowed-country notices, currency-aware amount controls, blank-limit helper copy, and inline threshold warnings/errors. Those should be a later A4 fraud UI slice because they touch TS/React/SCSS and browser visual parity, while A4az is a backend settings contract slice.

## Verification

Use TDD. Add focused PHPUnit coverage for platform fetch/cache/level matching, Basic initialization on `wcpay_fraud_ruleset_not_found`, fail-closed `"error"` sentinel on unexpected refresh failures, no duplicate fetch on one settings call, and no platform fetch when the transient already exists. Add API client coverage for `GET /sites/{blog}/wcpay/fraud_ruleset`. Then run focused settings/API tests, PHP syntax, changed-file PHPCS, production PHPStan, changelog validation, `git diff --check -- . ':!.agents'`, and branch `lint:changes:branch`. Browser proof can be limited to loading the fraud settings page without PHP/browser-log regressions if the code path has deterministic PHPUnit coverage and no frontend source changes.
