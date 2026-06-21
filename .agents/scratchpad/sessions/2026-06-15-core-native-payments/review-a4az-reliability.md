---
session: 2026-06-15-core-native-payments
type: review
by: subagent:reliability-reviewer
created: 2026-06-20 23:54
tool: pirategoat-tools:reliability-reviewer
target: A4az native WooPayments fraud ruleset refresh/defaulting parity
status: final
---

# A4az Reliability Review

## Findings

### Medium: Malformed platform rulesets are cached as valid advanced settings

Location: `plugins/woocommerce/src/Internal/Payments/Providers/WooPayments/WooPaymentsSettingsService.php:1550`

If `get_latest_fraud_ruleset()` succeeds but returns a malformed `ruleset_config` array, `maybe_refresh_fraud_protection_settings()` only checks `is_array( $ruleset )` before writing it into `wcpay_fraud_protection_settings` for `DAY_IN_SECONDS` and updating `current_protection_level`. That bypasses the intended `"error"` UI sentinel because the frontend only treats the literal string `"error"` as the retrieval failure state, while any array is treated as normal advanced-rules data.

Failure mode: a platform schema drift or partial response such as `ruleset_config => array( array( 'key' => 'custom_rule' ) )` becomes a persisted local transient and is exposed to the normal advanced fraud UI. Detection is weak because this path does not log or metric the invalid response, and the added tests cover valid platform arrays plus thrown refresh exceptions but not malformed successful responses. Recovery requires waiting for the transient to expire or manually clearing it after the platform response is fixed.

Recommendation: validate refreshed platform rulesets with the existing `is_valid_fraud_ruleset()` helper before caching or updating `current_protection_level`. Treat invalid successful responses the same as unexpected refresh failures: leave the transient unset, return the `"error"` sentinel through `get_advanced_fraud_protection_settings()`, and log a warning with the settings source plus enough non-sensitive context to diagnose the bad payload shape. Add a unit test that provides a malformed `ruleset_config` array and asserts one platform request, no transient write, no protection-level mutation, and `"error"` in `advanced_fraud_protection_settings`.

Confidence: 0.86

## No Critical Or High Findings

I did not find critical or high reliability issues in the scoped diff.

## Residual Risks

- Unexpected refresh failures and Basic-initialization save failures are swallowed to keep the admin response stable. That matches the UI fail-closed goal, but there is still no server-side log or metric, so on-call would only learn about it indirectly from merchant reports or client-side symptoms.
- The new tests cover one fetch per `get_settings()` response and cached-transient bypasses, but they do not explicitly assert disconnected stores make zero platform calls.
- There is no cross-request backoff/error transient for repeated platform outages, so every settings page load with a missing fraud transient can retry the platform refresh once.

## Positive Coverage

- `get_latest_fraud_ruleset()` uses the shared API request path, so the new platform fetch inherits the existing timeout and retry behavior.
- The added tests cover valid refresh from platform, `wcpay_fraud_ruleset_not_found` defaulting to Basic, unexpected refresh failures returning the `"error"` sentinel, avoiding duplicate fetches in one settings response, and avoiding platform fetches when the fraud transient is already present.
