---
session: 2026-06-15-core-native-payments
type: review
by: subagent:api-contract-reviewer
created: 2026-06-20 23:52
tool: pirategoat-tools:api-contract-reviewer
target: A4az native WooPayments fraud ruleset refresh/defaulting parity
reconciles:
  - analysis-a4az-fraud-ruleset-refresh-defaulting-parity.md
  - review-a4az-fraud-residual-source-check.md
status: final
---

# A4az API Contract Review

## Findings

### High: `advanced_fraud_protection_settings: "error"` cannot be round-tripped through the native settings save route

Existing consumers of the native WooPayments settings contract at `plugins/woocommerce/src/Internal/Payments/Providers/WooPayments/WooPaymentsSettingsService.php:1523` will break because the GET response can now contain the reference `"error"` sentinel, while the native POST route still validates `advanced_fraud_protection_settings` as an array before `WooPaymentsSettingsService::update_settings()` can decide whether to ignore or handle it. The native settings client posts the whole settings object on save, so after a platform refresh failure a merchant editing any unrelated setting round-trips the GET payload with `advanced_fraud_protection_settings: "error"` and the REST schema rejects the request. The reference WooPayments settings route does not declare a REST arg for `advanced_fraud_protection_settings`; it reads the value inside `update_fraud_protection_settings()`, which is why the sentinel can exist in the read contract without being rejected at route validation time.

Recommendation: keep the GET `"error"` sentinel, but make the native update contract accept the GET shape it emits, for example by widening the native route arg to `array|string` for this field and letting the service ignore invalid advanced rulesets unless the request is actually saving a valid advanced ruleset. Add a REST/controller-level regression test that performs a GET-like POST payload with `advanced_fraud_protection_settings => 'error'` and an unrelated settings change.

### Medium: Review-enabled standard/high presets are classified as `advanced` after refresh

Existing consumers of `current_protection_level` at `plugins/woocommerce/src/Internal/Payments/Providers/WooPayments/WooPaymentsSettingsService.php:1585` will get the wrong level when the fetched platform ruleset is a reference standard or high preset generated while `wcpay_frt_review_feature_active` is enabled. The new matcher compares the server ruleset against native preset builders that hard-code `block` outcomes, but the WooPayments reference `Fraud_Risk_Tools::get_standard_protection_settings()` and parts of `get_high_protection_settings()` switch those outcomes to `review` when the review feature is active. In that scenario the fetched ruleset is a real standard/high preset, but native falls through to `advanced`, changing the settings response and causing the UI/API consumer to treat a preset configuration as custom advanced rules.

Recommendation: make the native preset builders used for matching mirror the reference review-feature outcome rules, or make `get_matching_fraud_protection_level()` compare against both block and review-enabled canonical variants. Add a refresh/defaulting test with `wcpay_frt_review_feature_active` set and a fetched standard/high ruleset using `review` outcomes.

## Notes

The API client addition itself is additive and matches the reference endpoint shape: GET `/wcpay/fraud_ruleset` and return the decoded response. The connected-account cache miss path, Basic initialization on `wcpay_fraud_ruleset_not_found`, no fallback to stale local gateway fraud settings, and the unexpected-refresh `"error"` sentinel are otherwise aligned with the requested reference behavior.
