---
session: 2026-06-15-core-native-payments
type: review
by: subagent:api-contract-reviewer
created: 2026-06-21 00:16
tool: pirategoat-tools:api-contract-reviewer
target: A4az native WooPayments fraud ruleset refresh/defaulting post-fix re-review
reconciles:
  - review-a4az-api-contract-rereview.md
status: final
last_updated: 2026-06-21 00:16
---

# A4az API/settings-contract re-review 2

> **Prompt:** "Follow-up A4az API-contract re-review after fixes. Please inspect the same uncommitted diff in `/Users/vladolaru/Work/a8c/woocommerce-develop-2`.
>
> Fixes made after your high finding:
> - `WooPaymentsSettingsService::get_changed_fraud_settings()` now allows canonical `basic`/`standard`/`high` saves when `advanced_fraud_protection_settings === "error"` and the requested level differs from the stored level, but remains a no-op for same-level round-trips and advanced+error.
> - `WooPaymentsRestController` now validates `advanced_fraud_protection_settings` so only arrays or the exact string `"error"` are accepted.
> - Added regressions for canonical preset save with sentinel, same-level sentinel no-op, no-account no-fetch, high review-enabled matching, and rejecting unknown fraud strings.
>
> Fresh local verification already passed: targeted 5 tests / 20 assertions, full affected `WooPaymentsSettingsServiceTest|WooPaymentsApiClientTest|WooPaymentsRestControllerTest` 211 tests / 1130 assertions, PHP syntax, changed-file PHPCS, production PHPStan, and diff check.
>
> Do not edit files. Update `.agents/scratchpad/sessions/2026-06-15-core-native-payments/review-a4az-api-contract-rereview.md` or create `review-a4az-api-contract-rereview-2.md` with scratchpad frontmatter and status final. Return whether the previous high is closed and any remaining critical/high/medium findings."

## Progress

- Bootstrap status: OK for `api-contract-reviewer`.
- Local time captured with `date`: 2026-06-21 00:16.
- Scope: same uncommitted A4az API/settings-contract diff and post-fix regressions.

## Findings

No critical, high, or medium API/settings-contract findings remain in the inspected A4az diff.

## Prior Finding Status

The previous high finding is closed. `WooPaymentsSettingsService::get_changed_fraud_settings()` now parses the requested `current_protection_level` before handling the `"error"` sentinel, returns no-op only for `advanced` + sentinel and same-level sentinel round-trips, and allows `basic`, `standard`, or `high` to save their canonical rulesets when the requested level differs from the stored option. The relevant production path is `plugins/woocommerce/src/Internal/Payments/Providers/WooPayments/WooPaymentsSettingsService.php:1154-1185`, with saving and option/transient updates at `WooPaymentsSettingsService.php:1134-1139`.

The new regression at `plugins/woocommerce/tests/php/src/Internal/Payments/Providers/WooPayments/WooPaymentsSettingsServiceTest.php:1392-1410` covers the exact broken scenario: stored `advanced`, submitted `standard`, and `advanced_fraud_protection_settings => "error"` now saves the standard ruleset, updates `current_protection_level`, and updates the fraud transient. The same-level no-op remains covered at `WooPaymentsSettingsServiceTest.php:1364-1390`.

## Contract Checks

- The route schema no longer accepts arbitrary strings for `advanced_fraud_protection_settings`: `plugins/woocommerce/src/Internal/Admin/Settings/PaymentsProviders/WooPayments/WooPaymentsRestController.php:835-863` validates the union as string or array, then rejects any string other than exact `"error"`. Controller tests cover accepting `"error"` at `WooPaymentsRestControllerTest.php:397-429` and rejecting an unknown string before persistence at `WooPaymentsRestControllerTest.php:431-450`.
- Canonical preset matching remains aligned with the WooPayments reference. Native standard/high builders at `WooPaymentsSettingsService.php:1272-1307` mirror the reference review-feature split at `/Users/vladolaru/Work/a8c/woocommerce-payments/includes/fraud-prevention/class-fraud-risk-tools.php:166-267`, including the high-preset distinction where international IP and purchase price stay `block` while item count, address mismatch, and IP mismatch become reviewable. Tests now cover both review-enabled standard and high matches at `WooPaymentsSettingsServiceTest.php:1456-1490`.
- `get_latest_fraud_ruleset()` remains additive and uses the reference endpoint/shape: native `plugins/woocommerce/src/Internal/Payments/Providers/WooPayments/Api/WooPaymentsApiClient.php:946-947` calls `GET fraud_ruleset`, matching `/Users/vladolaru/Work/a8c/woocommerce-payments/includes/wc-payment-api/class-wc-payments-api-client.php:2199-2206`, and the service consumes `ruleset_config` at `WooPaymentsSettingsService.php:1579-1585`.
- The no-account path avoids unnecessary platform fetches and preserves an array response for disconnected settings consumers at `WooPaymentsSettingsService.php:1544-1547`, covered by `WooPaymentsSettingsServiceTest.php:1412-1427`.

## Residual Risks

- I did not rerun the verification commands because the prompt supplied fresh local verification results. This review is source and diff based.
- The service still treats same-level `"error"` round-trips as no-op by design, so a merchant must change to a different canonical level or wait for platform refresh recovery to clear the sentinel. That matches the stated fix intent and is not a remaining contract finding.

## Files Reviewed

- `plugins/woocommerce/src/Internal/Admin/Settings/PaymentsProviders/WooPayments/WooPaymentsRestController.php`
- `plugins/woocommerce/src/Internal/Payments/Providers/WooPayments/WooPaymentsSettingsService.php`
- `plugins/woocommerce/src/Internal/Payments/Providers/WooPayments/Api/WooPaymentsApiClient.php`
- `plugins/woocommerce/tests/php/src/Internal/Admin/Settings/PaymentsProviders/WooPayments/WooPaymentsRestControllerTest.php`
- `plugins/woocommerce/tests/php/src/Internal/Payments/Providers/WooPayments/Api/WooPaymentsApiClientTest.php`
- `plugins/woocommerce/tests/php/src/Internal/Payments/Providers/WooPayments/RecordingSettingsApiClient.php`
- `plugins/woocommerce/tests/php/src/Internal/Payments/Providers/WooPayments/WooPaymentsSettingsServiceTest.php`
