---
session: 2026-06-15-core-native-payments
type: review
by: subagent:reliability-reviewer
created: 2026-06-21 00:10
tool: pirategoat-tools:reliability-reviewer
target: exp/core-native-payments uncommitted A4az WooPayments fraud ruleset refresh/defaulting diff
status: final
---

# A4az Reliability Re-review

## Findings

No critical, high, or medium reliability issues found in the current uncommitted A4az diff.

## Scope Reviewed

Reviewed the uncommitted diff only on branch `exp/core-native-payments` for `plugins/woocommerce/src/Internal/Payments/Providers/WooPayments/WooPaymentsSettingsService.php`, `plugins/woocommerce/src/Internal/Payments/Providers/WooPayments/Api/WooPaymentsApiClient.php`, `plugins/woocommerce/src/Internal/Admin/Settings/PaymentsProviders/WooPayments/WooPaymentsRestController.php`, `plugins/woocommerce/tests/php/src/Internal/Payments/Providers/WooPayments/WooPaymentsSettingsServiceTest.php`, `plugins/woocommerce/tests/php/src/Internal/Payments/Providers/WooPayments/Api/WooPaymentsApiClientTest.php`, `plugins/woocommerce/tests/php/src/Internal/Payments/Providers/WooPayments/RecordingSettingsApiClient.php`, and `plugins/woocommerce/tests/php/src/Internal/Admin/Settings/PaymentsProviders/WooPayments/WooPaymentsRestControllerTest.php`.

## Verification Notes

The previous reliability issue is addressed for newly fetched platform responses: `maybe_refresh_fraud_protection_settings()` now reads `ruleset_config`, validates it with `is_valid_fraud_ruleset()`, only caches valid arrays, and logs invalid successful responses through `log_fraud_ruleset_refresh_warning()` without setting the transient.

Unexpected platform refresh failures leave `wcpay_fraud_protection_settings` unset, log `error_code` and `http_status`, and allow the settings response to surface the reference-style `"error"` sentinel through `get_advanced_fraud_protection_settings()`.

The not-found path is recoverable: `wcpay_fraud_ruleset_not_found` triggers a Basic ruleset save, stores an empty ruleset transient, and updates `current_protection_level` to `basic`; failure to save Basic is caught and logged without interrupting the settings response.

The logging helper is defensive: it returns when `wc_get_logger()` is unavailable and catches `Throwable` around logger access and warning emission, so logging failures cannot interrupt settings responses.

Refresh frequency is bounded per service instance: the new `$fraud_protection_settings_refreshed` flag is set before any platform call after a transient miss, so repeated settings field reads in the same service instance cannot repeatedly hit the fraud ruleset endpoint.

The no-account and cached-transient paths avoid the new fraud ruleset platform call: `get_advanced_fraud_protection_settings()` returns an empty array before refresh for no connected account, and `maybe_refresh_fraud_protection_settings()` returns immediately when `wcpay_fraud_protection_settings` is already an array.

The REST schema change is consistent with the sentinel flow: `advanced_fraud_protection_settings` now accepts `string|array`, and `get_changed_fraud_settings()` ignores the exact `"error"` sentinel so unrelated settings saves do not persist stale or malformed fraud rules.

The API client addition uses the existing shared request wrapper. That wrapper has an explicit `REQUEST_TIMEOUT_SECONDS` value and transport timeout propagation, so the new `get_latest_fraud_ruleset()` method does not bypass existing timeout handling.

## Residual Risks

Existing cached fraud transients are still trusted when they are arrays. That is consistent with the explicit cached-transient-avoids-platform-calls requirement, but it means an already-corrupt transient from an earlier build or manual cache mutation would not be revalidated by this slice.

The added tests cover the main refresh/defaulting scenarios, but there is no direct assertion that a no-account settings response leaves `latest_fraud_ruleset_requests` at zero. The source path has the early `has_account()` guard before the new fraud ruleset fetch, so this is a test coverage residual rather than a production finding.

I did not run the PHP test suite for this re-review; this artifact is based on source and diff inspection only.
