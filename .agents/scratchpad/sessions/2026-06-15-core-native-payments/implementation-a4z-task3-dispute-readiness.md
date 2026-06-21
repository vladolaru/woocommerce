---
session: 2026-06-15-core-native-payments
type: report
by: codex
created: 2026-06-19 20:40
tool: woocommerce-backend-dev,test-driven-development,woocommerce-dev-cycle,php-testing-patterns
target: A4z Task 3 native dispute readiness backend seam
reconciles:
  - plans/2026-06-19-core-native-payments-a4z-overview-dashboard-residual-parity.md
status: final
last_updated: 2026-06-19 20:47
---

# A4z Task 3 Dispute Readiness Backend Seam

> **Prompt:** "You are working in /Users/vladolaru/Work/a8c/woocommerce-develop-2 on branch exp/core-native-payments. You are not alone in the codebase: do not revert or overwrite unrelated edits, and adapt to concurrent changes. Do not access or modify WPCOM, remote sandboxes, or the standalone WooPayments reference repo except read-only source inspection under /Users/vladolaru/Work/a8c/woocommerce-payments. Do not push. Do not commit.
>
> Task: Implement the A4z native dispute-readiness backend seam with TDD. Ownership is limited to:
> - Create: plugins/woocommerce/src/Internal/Payments/Providers/WooPayments/WooPaymentsDisputeReadinessService.php
> - Create: plugins/woocommerce/src/Internal/Payments/Providers/WooPayments/WooPaymentsDisputeReadinessRestController.php
> - Create/modify tests only under plugins/woocommerce/tests/php/src/Internal/Payments/Providers/WooPayments/ for this seam.
>
> Do NOT edit plugins/woocommerce/includes/class-woocommerce.php or any bootstrap/registration file; the main agent will wire controllers after integrating both backend seams. Your controller tests should instantiate/register the controller manually like nearby REST controller tests.
>
> Source context:
> - Plan: .agents/scratchpad/sessions/2026-06-15-core-native-payments/plans/2026-06-19-core-native-payments-a4z-overview-dashboard-residual-parity.md Task 3.
> - Reference service: /Users/vladolaru/Work/a8c/woocommerce-payments/src/Internal/Service/DisputeReadinessService.php
> - Reference controller: /Users/vladolaru/Work/a8c/woocommerce-payments/includes/admin/class-wc-rest-payments-dispute-readiness-controller.php
> - Existing native controller patterns: plugins/woocommerce/src/Internal/Payments/Providers/WooPayments/WooPaymentsReportsRestController.php and WooPaymentsCapitalRestController.php.
>
> Requirements:
> 1. Write RED tests first and run them to verify failure before implementation.
> 2. Add a native service that preserves the reference payload shape for disabled and enabled overview payloads: overview.enabled, hidden, score, total, state, isDismissed, completeSignalIds, incompleteSignalIds, signals, dismissal.
> 3. Preserve option names: wcpay_dispute_readiness_card_dismissed and wcpay_dispute_readiness_statement_descriptor_confirmed. Do not delete merchant data.
> 4. Signals: refund policy page published, terms & conditions linked at checkout, recognizable statement descriptor, customer support contact linked in order emails. Use native account data via WooPaymentsAccountService preserved/cached account data; do not refresh platform state.
> 5. Use native/Core URLs: statement descriptor/support contact actions should go to Settings > Payments /woopayments/settings; WooCommerce policy/page actions can use Core Advanced settings or edit-page URLs.
> 6. Add a native runtime-gated REST controller for GET /wc/v3/payments/dispute-readiness, POST /dismiss, and POST /statement-descriptor/confirm with manage_woocommerce permission. Disabled feature should return disabled payload for GET and 403 for mutating routes.
> 7. Feature flag option should be _wcpay_feature_dispute_readiness_overview and default fail-closed false.
> 8. Run focused PHPUnit for new tests and PHP syntax on new files.
>
> Return: status, RED observed, files changed, tests run with outcomes, and integration notes. Do not claim A4z complete."

## Notes

- Reference payload shape is owned by `DisputeReadinessService`, with disabled payload under `overview` and enabled signals ordered as statement descriptor, refund policy, support contact, terms and conditions.
- Native controller should follow existing `wc/v3` provider REST controller patterns and be manually instantiated in tests because bootstrap wiring is explicitly out of scope.

## Result

- Added RED tests first, then ran `pnpm --filter=@woocommerce/plugin-woocommerce test:php:env -- --filter 'WooPaymentsDisputeReadinessServiceTest|WooPaymentsDisputeReadinessRestControllerTest'`. RED observed: 15 errors, all missing `WooPaymentsDisputeReadinessService`, matching the absent seam.
- Implemented `WooPaymentsDisputeReadinessService` using `WooPaymentsAccountService::get_preserved_account_data_snapshot()` so the service reads preserved native account data without forcing platform refresh.
- Implemented `WooPaymentsDisputeReadinessRestController` with native runtime registration gating, `manage_woocommerce` permission checks, fail-closed `_wcpay_feature_dispute_readiness_overview` option handling, disabled GET payload, and 403 mutating routes when disabled.
- Preserved option names `wcpay_dispute_readiness_card_dismissed` and `wcpay_dispute_readiness_statement_descriptor_confirmed`; tests assert dismissal does not delete gateway merchant data.
- Native action URLs use Core/WooCommerce settings/edit URLs: statement descriptor and support contact route to `/woopayments/settings`, policy page uses edit-page or Core Advanced settings, and terms uses Core Advanced settings.

## Verification

- GREEN focused PHPUnit: `pnpm --filter=@woocommerce/plugin-woocommerce test:php:env -- --filter 'WooPaymentsDisputeReadinessServiceTest|WooPaymentsDisputeReadinessRestControllerTest'` passed with 15 tests and 71 assertions.
- Syntax: `php -l` passed for the two new production files and two new test files.
- Targeted PHPCS: `pnpm --filter=@woocommerce/plugin-woocommerce lint:php -- src/Internal/Payments/Providers/WooPayments/WooPaymentsDisputeReadinessService.php src/Internal/Payments/Providers/WooPayments/WooPaymentsDisputeReadinessRestController.php tests/php/src/Internal/Payments/Providers/WooPayments/WooPaymentsDisputeReadinessServiceTest.php tests/php/src/Internal/Payments/Providers/WooPayments/WooPaymentsDisputeReadinessRestControllerTest.php` passed. Composer/PHPCS emitted existing deprecation warnings, but no sniff violations remained.

## Integration Notes

- No bootstrap, registration, or `plugins/woocommerce/includes/class-woocommerce.php` changes were made. The controller can be wired later by the integrating agent.
- No WPCOM, remote sandbox, or write access to the standalone WooPayments reference repo was used.
- This completes only the bounded Task 3 backend seam; it does not claim A4z completion.
