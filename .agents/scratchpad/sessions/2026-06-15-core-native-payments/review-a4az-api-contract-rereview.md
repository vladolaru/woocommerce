---
session: 2026-06-15-core-native-payments
type: review
by: subagent:api-contract-reviewer
created: 2026-06-21 00:08
tool: pirategoat-tools:api-contract-reviewer
target: A4az native WooPayments fraud ruleset refresh/defaulting uncommitted diff
reconciles:
  - review-a4az-api-contract.md
status: final
last_updated: 2026-06-21 00:11
---

# A4az API/settings-contract re-review

> **Prompt:** "Focused A4az API/settings-contract re-review in `/Users/vladolaru/Work/a8c/woocommerce-develop-2` on branch `exp/core-native-payments`.
>
> Scope: review the current uncommitted A4az diff only, especially the post-review fixes for native WooPayments fraud ruleset refresh/defaulting parity.
>
> Files to inspect:
> - `plugins/woocommerce/src/Internal/Admin/Settings/PaymentsProviders/WooPayments/WooPaymentsRestController.php`
> - `plugins/woocommerce/src/Internal/Payments/Providers/WooPayments/WooPaymentsSettingsService.php`
> - `plugins/woocommerce/src/Internal/Payments/Providers/WooPayments/Api/WooPaymentsApiClient.php`
> - Related tests under `plugins/woocommerce/tests/php/src/Internal/Admin/Settings/PaymentsProviders/WooPayments/` and `plugins/woocommerce/tests/php/src/Internal/Payments/Providers/WooPayments/`
>
> Previously found API-contract issues were: the reference-style `advanced_fraud_protection_settings: "error"` sentinel could not round-trip through the native settings POST route, unrelated saves might persist that sentinel as fraud settings, and review-enabled standard/high platform presets could be misclassified as `advanced`. Verify those are fixed without weakening the route schema or settings contract. Also check that `get_latest_fraud_ruleset()` uses the right endpoint and response shape without changing existing API methods.
>
> Do not edit files. Do not access WPCOM sandbox. Do not push. Write findings to `.agents/scratchpad/sessions/2026-06-15-core-native-payments/review-a4az-api-contract-rereview.md` with scratchpad frontmatter (`session: 2026-06-15-core-native-payments`, `type: review`, `by: subagent:api-contract-reviewer`, current local time from `date`, `status: final`). No hard-wrapped prose. Return severity-ranked findings; if no critical/high/medium issues, say so and list residual risks."

## Progress

- Bootstrap status: OK for `api-contract-reviewer`.
- Local time captured with `date`: 2026-06-21 00:08.
- Review scope: uncommitted diff only for the requested A4az implementation and tests.

## Findings

### High: `"error"` sentinel blocks preset fraud-level saves through the settings route

Existing consumers of `POST /wc/v3/payments/settings` at `plugins/woocommerce/src/Internal/Payments/Providers/WooPayments/WooPaymentsSettingsService.php:1159` will break when they round-trip a GET payload containing `advanced_fraud_protection_settings: "error"` and change `current_protection_level` to `basic`, `standard`, or `high`. The new guard returns `null` before the requested protection level is interpreted, so the service silently skips `save_fraud_ruleset()`, leaves `current_protection_level` unchanged, and still returns a 200 settings response via `update_settings()` at `WooPaymentsSettingsService.php:609`. That fixes unrelated saves, but it also prevents a consumer from recovering from a platform refresh error by selecting a canonical preset that does not need an advanced rules array. The reference controller only requires the advanced ruleset to be an array in the `advanced` branch; `basic`, `standard`, and `high` build canonical rulesets without reading `advanced_fraud_protection_settings` at `/Users/vladolaru/Work/a8c/woocommerce-payments/includes/admin/class-wc-rest-payments-settings-controller.php:1261-1274`.

Recommendation: move the sentinel handling after the requested `current_protection_level` is parsed. Treat `"error"` as a no-op only when the request is not changing the fraud level, or when `advanced` is requested and there is no valid advanced ruleset to save. For `basic`, `standard`, and `high`, ignore the advanced payload and save the canonical ruleset when the requested level differs from the stored level. Add a regression test for `current_protection_level => 'basic'` or `'standard'` with `advanced_fraud_protection_settings => 'error'` and an existing different stored level, asserting that `save_fraud_ruleset()` is called with the canonical preset and `current_protection_level` updates.

Confidence: 0.88.

## Verified Fixes

- The native route now accepts the reference read sentinel enough for a whole-settings POST round-trip: `WooPaymentsRestController.php:762` widens `advanced_fraud_protection_settings`, and `WooPaymentsRestControllerTest.php:399-429` covers posting `"error"`.
- The unrelated-save regression is covered for the same-level sentinel round-trip: `WooPaymentsSettingsServiceTest.php:1364-1390` verifies a debug-log save with `current_protection_level => 'standard'` and sentinel advanced settings does not persist a fraud ruleset.
- The review-enabled preset classification gap is fixed in production code: `WooPaymentsSettingsService.php:1256-1300` mirrors the reference standard/high split where standard uses reviewable outcomes for all four rules and high keeps international IP and purchase price blocking while making item/address/IP mismatch reviewable. The reference source is `/Users/vladolaru/Work/a8c/woocommerce-payments/includes/fraud-prevention/class-fraud-risk-tools.php:166-267`.
- `get_latest_fraud_ruleset()` is additive and uses the same endpoint/shape as the reference: native `WooPaymentsApiClient.php:946-947` calls `GET fraud_ruleset`, matching `/Users/vladolaru/Work/a8c/woocommerce-payments/includes/wc-payment-api/class-wc-payments-api-client.php:2199-2206`, and the settings service reads `ruleset_config` at `WooPaymentsSettingsService.php:1564-1569`.

## Residual Risks

- The route schema now accepts any string for `advanced_fraud_protection_settings`, not only the `"error"` sentinel. I am not reporting that as a separate contract break because it broadens accepted input rather than breaking existing valid clients, but it is weaker than necessary for the route contract. A custom validator or `enum: [ "error" ]` on the string branch would preserve stricter rejection for arbitrary strings.
- The tests cover review-enabled standard classification but not review-enabled high classification. The production code matches the reference high preset, so this is a coverage gap rather than a source-backed contract break.

## Files Reviewed

- `plugins/woocommerce/src/Internal/Admin/Settings/PaymentsProviders/WooPayments/WooPaymentsRestController.php`
- `plugins/woocommerce/src/Internal/Payments/Providers/WooPayments/WooPaymentsSettingsService.php`
- `plugins/woocommerce/src/Internal/Payments/Providers/WooPayments/Api/WooPaymentsApiClient.php`
- `plugins/woocommerce/tests/php/src/Internal/Admin/Settings/PaymentsProviders/WooPayments/WooPaymentsRestControllerTest.php`
- `plugins/woocommerce/tests/php/src/Internal/Payments/Providers/WooPayments/Api/WooPaymentsApiClientTest.php`
- `plugins/woocommerce/tests/php/src/Internal/Payments/Providers/WooPayments/RecordingSettingsApiClient.php`
- `plugins/woocommerce/tests/php/src/Internal/Payments/Providers/WooPayments/WooPaymentsSettingsServiceTest.php`
