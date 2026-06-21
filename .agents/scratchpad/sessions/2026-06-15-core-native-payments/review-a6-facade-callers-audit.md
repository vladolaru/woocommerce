---
session: 2026-06-15-core-native-payments
type: review
by: subagent:codex-a6-facade-auditor
created: 2026-06-21 03:01
model: gpt-5-codex
tool: woocommerce-code-review
target: A6 transitional facade/caller audit
reconciles:
  - analysis-a6-replanned-cleanup-readiness.md
status: final
last_updated: 2026-06-21 03:06
---

# A6 Facade Caller Audit

## Verdict

NO_SAFE_CODE_CLEANUP

## Scope

Read-only audit of WooCommerce Core tracked source under `plugins/woocommerce` for A6 cleanup candidates around `LegacyProxy`, `LegacyContainer`, `WC_Payments`-style facades, global functions/classes, native compatibility shims, `wcpay_`, and `woocommerce_payments`.

## Basis

- `implementation-plan.md` says A6 should remove god-class indirection remnants (`LegacyProxy`/`LegacyContainer`) and PRESERVE-AS-FACADE shims only once internal callers have migrated; Bucket D repeats the gate as "no internal caller depends on them".
- `bc-manifest.md` says WooPayments is a leaf, so `WC_Payments::get_*()`, global functions, and public service classes are transitional facades rather than permanent public API. The same manifest keeps stable gateway IDs, persisted data keys, queue hooks/group, Subscriptions integration, and surviving telemetry/hook surfaces as preservation surfaces.
- Fresh tracked-source checks used `git grep` under `plugins/woocommerce`. No product files were edited; `git status --short -- plugins/woocommerce` was clean after the audit.

## Findings

1. `LegacyContainer` is absent from tracked WooCommerce Core source, and there is no tracked production definition of a global `WC_Payments`, `WCPay`, `wcpay_*`, or `woocommerce_payments*` function/class under `plugins/woocommerce`. Search evidence: `git grep -n -E '^class[[:space:]]+WC_Payments|^function[[:space:]]+wcpay_|^function[[:space:]]+woocommerce_payments|^class[[:space:]]+WCPay|^namespace[[:space:]]+WCPay|LegacyContainer' -- plugins/woocommerce` returned no matches. This means there is no first-slice cleanup in Core for a carried `LegacyContainer` or literal `WC_Payments` facade class/function.

2. `LegacyProxy` is not a WooPayments-only A6 shim and cannot be removed as part of the native WooPayments cleanup. It is the general WooCommerce proxy for legacy code outside `src` (`plugins/woocommerce/src/Proxies/LegacyProxy.php:11-18`), has 43 tracked production files referencing it across `includes/`, `src/Internal/*`, PayPal, blocks, utilities, and WooPayments, and has 38 `LegacyProxy::class` uses in production search scope. WooPayments-specific callers remain in `NativePaymentsRuntimeArbiter` (`plugins/woocommerce/src/Internal/Payments/NativePaymentsRuntimeArbiter.php:120`, `:202`), `WooPaymentsAccountService`, `WooPaymentsCutoverController`, `WooPaymentsEventIngestor`, `WooPaymentsLegacyRuntime`, and shadow-mode classes. Deleting or deprecating the core proxy from this A6 slice would be a cross-Core refactor, not a WooPayments facade cleanup.

3. Direct production callers have mostly migrated away from raw `WC_Payments::get_*()`/`wcpay_get_container` access, but they have not migrated away from the transitional runtime boundary. `WooPaymentsLegacyRuntime` is explicitly marked transitional/internal (`plugins/woocommerce/src/Internal/Payments/Providers/WooPayments/WooPaymentsLegacyRuntime.php:13-19`) and centralizes `class_exists( 'WC_Payments' )`, `WC_Payments_Utils::supported_countries`, `WC_Payments::hide_gateways_on_settings_page`, `WC_Payments::get_*`, `WC_Payments_Account::*`, `WCPAY_VERSION_NUMBER`, and `wcpay_account_data` access (`:64-78`, `:95-105`, `:339-347`, `:365-388`, `:396-408`, `:421-430`, `:444-455`, `:568-580`, `:610-613`). Internal production callers still include `PaymentsController` injection (`plugins/woocommerce/src/Internal/Admin/Settings/PaymentsController.php:34-63`), `PaymentsProviders` collaborator wiring (`plugins/woocommerce/src/Internal/Admin/Settings/PaymentsProviders.php:503-514`), `WooPayments` admin provider onboarding URL fallback (`plugins/woocommerce/src/Internal/Admin/Settings/PaymentsProviders/WooPayments.php:460-470`), `WooPaymentsService` onboarding and extension-version fallbacks (`plugins/woocommerce/src/Internal/Admin/Settings/PaymentsProviders/WooPayments/WooPaymentsService.php:172-178`, `:1577`, `:1698`, `:1813`, `:3256-3262`, `:3289-3295`, `:3368-3369`), `WooPaymentsCheckoutBridge` (`plugins/woocommerce/src/Internal/Payments/Providers/WooPayments/WooPaymentsCheckoutBridge.php:160-210`, `:391-394`), `WooPaymentsPaymentMethodDetailsService` (`plugins/woocommerce/src/Internal/Payments/Providers/WooPayments/WooPaymentsPaymentMethodDetailsService.php:21-65`, `:111-113`), `WooPaymentsProviderGatewayAdapter` legacy fallback processing (`plugins/woocommerce/src/Internal/Payments/Providers/WooPayments/WooPaymentsProviderGatewayAdapter.php:95-141`, `:153-190`, `:230-261`, `:347-354`, `:1039-1041`), webhook/dispute/refund handlers, and multi-currency provider adapters. Tests now enforce centralization rather than absence: `WooPaymentsLegacyAdminRuntimeBoundaryTest` forbids raw symbols in admin sources (`plugins/woocommerce/tests/php/src/Admin/Features/WooPaymentsLegacyAdminRuntimeBoundaryTest.php:16-53`), `WooPaymentsTest` forbids raw admin provider symbols and expects access through `WooPaymentsLegacyRuntime` (`plugins/woocommerce/tests/php/src/Internal/Admin/Settings/PaymentsProviders/WooPaymentsTest.php:342-352`), and `WooPaymentsServiceTest` does the same for service internals (`plugins/woocommerce/tests/php/src/Internal/Admin/Settings/PaymentsProviders/WooPayments/WooPaymentsServiceTest.php:1351-1357`). This is not removal-ready.

4. Native request-object aliases are compatibility shims that remain wired to production filters and tests. Request classes register aliases for `WCPay\Core\Server\Request*` when the extension is absent (`plugins/woocommerce/src/Internal/Payments/Providers/WooPayments/Api/WooPaymentsApiRequest.php:57-65`, `plugins/woocommerce/src/Internal/Payments/Providers/WooPayments/WooPaymentsPaginatedListRequest.php:41-53`, `plugins/woocommerce/src/Internal/Payments/Providers/WooPayments/WooPaymentsDepositsListRequest.php:38-48`, plus authorizations/disputes/documents/reports/transactions request classes). `WooPaymentsApiClient` applies `wcpay_api_request_params`, `wcpay_api_request_headers`, and `wcpay_api_request_response` (`plugins/woocommerce/src/Internal/Payments/Providers/WooPayments/Api/WooPaymentsApiClient.php:1793-1802`, `:1819-1826`, `:1864-1874`) and explicitly describes request-object hooks as preserving legacy public filters (`:1918-1932`, `:1935-1970`). Tests type-hint the legacy aliases in filters (`plugins/woocommerce/tests/php/src/Internal/Payments/Providers/WooPayments/Api/WooPaymentsApiClientTest.php:2201-2233`, `:2256-2268`, `:2728-2740`). These could become a release deprecation target, but deleting them now would break current source-backed hook compatibility.

5. The `wcpay_` and `woocommerce_payments` hits are mostly preserved external or data-compatibility surfaces, not dead facade scaffolding. Examples: gateway ID and split prefix are preserved in `OrderPaymentStore` (`plugins/woocommerce/src/Internal/Payments/OrderPaymentStore.php:22-34`); the canonical Action Scheduler group is `woocommerce_payments` (`plugins/woocommerce/src/Internal/Payments/Providers/WooPayments/WooPaymentsActionSchedulerService.php:18-23`); operational hooks such as `wcpay_store_setup_sync`, `wcpay_update_saved_payment_method`, `wcpay_add_fee_breakdown_to_order_notes`, `wcpay_instant_deposit_reminder`, and `wcpay_post_kyc_activation_email_send` are constants and registered handlers (`plugins/woocommerce/src/Internal/Payments/Providers/WooPayments/WooPaymentsOperationalQueueService.php:28-68`, `:217-234`); webhook delivery hooks are preserved (`plugins/woocommerce/src/Internal/Payments/Providers/WooPayments/WooPaymentsEventIngestor.php:198-213`, `:797-800`); settings/options are read under existing keys (`plugins/woocommerce/src/Internal/Payments/Providers/WooPayments/WooPaymentsAccountService.php:24-56`, `plugins/woocommerce/src/Internal/Payments/Providers/WooPayments/WooPaymentsSettingsService.php:25-31`, `:273-281`); and checkout/payment filters remain (`plugins/woocommerce/src/Internal/Payments/NativeWooPaymentsGateway.php:236-245`, `plugins/woocommerce/src/Internal/Payments/Providers/WooPayments/WooPaymentsCheckoutBridge.php:273-280`, `plugins/woocommerce/src/Internal/Payments/Providers/WooPayments/WooPaymentsExpressCheckoutService.php:363-473`). These should stay until there is an explicit public deprecation/removal release decision and gate coverage for stored data, queues, checkout, and telemetry.

6. WCPay native subscriptions/Stripe-Billing remnants are guarded, not ready for blind deletion. The manifest marks the engine/drop path as data-safety-gated. Current Core still checks legacy Stripe-Billing markers via `WooPaymentsLegacySubscriptionsGuard` (`plugins/woocommerce/src/Internal/Payments/Providers/WooPayments/Subscriptions/WooPaymentsLegacySubscriptionsGuard.php:31-45`, `:54-75`, `:83-109`, `:117-143`) and makes that blocker non-removable by preflight filters (`plugins/woocommerce/src/Internal/Payments/Providers/WooPayments/WooPaymentsCutoverController.php:451-456`, `:723-729`; tests at `plugins/woocommerce/tests/php/src/Internal/Payments/Providers/WooPayments/WooPaymentsCutoverControllerTest.php:676-798`). Other remnants include `WC_Payments_Subscription_Service`/`WC_Payments_Subscriptions` compatibility in multi-currency subscriptions (`plugins/woocommerce/src/Internal/MultiCurrency/MultiCurrencySubscriptionsCompatibilityController.php:43-45`, `:478-485`) and `_wcpay_feature_subscriptions` read/disable behavior in native settings (`plugins/woocommerce/src/Internal/Payments/Providers/WooPayments/WooPaymentsSettingsService.php:25-31`, `:273-281`, `:587-592`). These are Bucket D-adjacent, but this audit did not prove the live-data migration condition needed to remove them.

## Classification

| Surface | Current tracked-source state | Cleanup decision |
|---|---|---|
| `LegacyContainer` | No matches under `plugins/woocommerce`. | No code to delete. |
| Global `WC_Payments`/`WCPay`/`wcpay_*` function/class facades | No production definitions under `plugins/woocommerce`; direct static production references are centralized in `WooPaymentsLegacyRuntime`. | No literal facade file to remove; keep central runtime until callers and plugin-overlap flow are gone. |
| `LegacyProxy` | Core-wide proxy with non-WooPayments callers plus WooPayments callers. | Not an A6 WooPayments cleanup target. |
| `WooPaymentsLegacyRuntime` and adapters | Still have many production callers and tests asserting the boundary. | Not removal-ready; future slice must replace callers with native services first. |
| `WCPay\Core\Server\Request*` aliases | Registered by native request classes; request filters/tests rely on aliases. | Keep until explicit hook/request-filter deprecation plan. |
| `wcpay_` hooks, filters, options, meta, queue hooks, `woocommerce_payments` IDs/groups | Source marks many as preserved and BC manifest treats several as hard external/data contracts. | Keep until release/public deprecation or data/queue migration gates say otherwise. |
| WCPay subscriptions/Stripe-Billing guards | Cutover blocker and settings/multi-currency compatibility remain. | Need separate data-safety evidence before removal; not a safe facade cleanup. |

## Minimum Follow-Up

- The first real cleanup slice should not delete code yet. It should produce a caller-retirement plan for `WooPaymentsLegacyRuntime` grouped by current caller family: admin onboarding/settings, checkout bridge, processing fallback, payment-method details, webhook/dispute/refund logging, multi-currency provider adapters, and runtime/cutover detection. Each group needs a native replacement and focused tests proving the plugin-overlap behavior remains safe or is no longer needed.
- A separate deprecation proposal is needed for legacy request-object filters and aliases. Source currently documents them as preserved public hooks, and tests type-hint legacy `WCPay\Core\Server\Request*` aliases.
- The subscriptions/Stripe-Billing remnants need the manifest-required live-data/migration proof before deleting any guard, setting, or `WC_Payments_Subscriptions` compatibility branch.

## Running Notes

- 2026-06-21 03:01: Created the review artifact before presenting findings, per scratchpad rules.
- 2026-06-21 03:01: Read the implementation plan A0/A2/A6 and Bucket D sections plus the BC manifest leaf/preserve-as-facade sections. A6 cleanup is gated by no internal callers for `LegacyProxy`/`LegacyContainer`/facade shims; the manifest says `WC_Payments::get_*()`, global functions, and public service classes are transitional facades only, while stable gateway IDs, settings/options/meta, queue hooks/groups, Subscriptions integration, and surviving Tracks/hook surfaces are preservation surfaces.
- 2026-06-21 03:01: Tracked-source searches under `plugins/woocommerce` found no `LegacyContainer` references and no production definitions for global `WC_Payments`, `WCPay`, `wcpay_*`, or `woocommerce_payments*` functions/classes. `LegacyProxy` is still a broad WooCommerce Core infrastructure class, not WooPayments-only scaffolding.
- 2026-06-21 03:01: Focused WooPayments searches found production callers still depending on `WooPaymentsLegacyRuntime`, legacy request aliases, preserved `wcpay_` filters/actions, preserved `woocommerce_payments` IDs/options/groups, and WCPay-subscriptions retirement guards. These need final classification before the verdict.
- 2026-06-21 03:06: Re-ran the no-definition/no-`LegacyContainer` grep and product status check before finalizing. Both returned no output.
