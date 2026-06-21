---
session: 2026-06-15-core-native-payments
type: review
by: subagent:codex-decision-critic
created: 2026-06-21 03:37
tool: decision-critic
target: A5l blocker audit
reconciles:
  - analysis-a5j-release-default-on-readiness.md
  - analysis-a5k-next-local-slice-after-a5j.md
  - review-a5k-release-sequencing-next-move.md
  - review-a5k-n12-admin-parity-residuals.md
  - review-a5k-a6-cleanup-boundary.md
  - staging-log.md
  - spec-conformance-baseline.md
status: final
last_updated: 2026-06-21 03:39
---

# A5l Blocker Audit

## Verdict

BLOCKED_THRESHOLD_MET.

The controller may legitimately mark the active long-running goal blocked. The current evidence shows the same material blocker repeating across the A5i -> A6 -> A5j -> A5k continuation sequence: local A4/A5 readiness is green under recorded limitations, but the objective cannot materially advance until release-owner/production evidence exists for canary parity, production error-rate, production WPCOM readiness, live queue/financial/Stripe Billing data safety, exact production perf, release sequencing, and the actual native runtime/default/mandatory flips. This is a blocked-state verdict only. It is not a goal-complete verdict and it does not claim production/default-on readiness.

## Scope

This read-only audit stress-tests whether the controller may legitimately mark the active long-running goal blocked after repeated A5j/A5k production-only stop lines, or whether meaningful local product, harness, docs, or evidence work remains that can advance the full objective without production/default-on evidence.

## Decision-Critic Result

Internal decision-critic verdict: STAND for the decision that the controller can mark the goal blocked, mapped to the requested taxonomy as `BLOCKED_THRESHOLD_MET`.

Verified claims: the same production/release-owner blocker repeats; local readiness is evidenced under limitations; A5k found no local A4/A5/A6 product or harness slice; A6a is release-sequenced, not abandoned; current source remains fail-closed for rollout and mandatory cutover.

Failed claims: none found.

Uncertainty retained: the exact blocked-threshold counter is a controller-policy fact, not visible in these artifacts. The artifact-level evidence still satisfies the requested standard because at least three consecutive substantive continuations reached the same external-evidence stop line.

## Repetition Test

Yes, current evidence shows the same blocker repeated across at least three consecutive goal continuations at the artifact/staging-log level.

A5i records a documentation/decision slice, not product code, and says the read-only scan found "no concrete source-backed local product or gate slice remains before a production/default-on release decision"; it then says the branch remains fail-closed until a separate release-owner decision supplies production-only canary/error-rate, WPCOM production readiness, exact production perf, and release-sequencing evidence (`staging-log.md:1427-1434`). The spec baseline repeats the same A5i state: no concrete local slice before production/default-on, green local A4/A5 readiness under limitations, and deferred production rollout/default-on, mandatory rollout, canary/error-rate, WPCOM production readiness, exact production perf, and release sequencing (`spec-conformance-baseline.md:526-532`).

A6 is the next boundary continuation, and it does not break the repetition. It records a planning/boundary decision checkpoint, not product cleanup, and says A6 cleanup is held for release/default-on sequencing; it explicitly says not to begin A6 product-code cleanup until production/default-on, mandatory rollout, canary/error-rate, WPCOM production readiness, exact production perf, and release sequencing are settled (`staging-log.md:1438-1444`). This is the same release/default-on blocker applied to the cleanup branch of the goal.

A5j then refreshes the release/default-on packet and again keeps production/default-on blocked. The A5j staging entry says `review-a5j-release-default-on-requirements.md` returned `BLOCKED_ON_PRODUCTION_DECISION`, with local A4/A5 readiness and cutover mechanics proven locally while default-on/release remains blocked on canary parity/error-rate/perf, WPCOM production readiness, live queue/financial/Stripe Billing data safety, exact production perf, release sequencing, and the actual default/mandatory flip (`staging-log.md:1447-1454`). The spec baseline repeats this at `spec-conformance-baseline.md:534-546`.

A5k directly stress-tests whether A5j missed a local slice. It closes as `PRODUCTION_DECISION_ONLY` / `NO_LOCAL_A4_BLOCKER` / `HOLD_A6_FOR_RELEASE`, finds no source-backed local product, harness, or cleanup slice before production/default-on, and says the next unblocker is production/release-owner evidence for canary parity/error-rate/perf, production WPCOM readiness, live queue/financial/Stripe Billing data safety, exact production perf, release sequencing, and the actual native runtime/default/mandatory flips (`staging-log.md:1458-1465`). The spec baseline repeats the same A5k conclusion (`spec-conformance-baseline.md:548-558`).

## Impasse Test

This is a true impasse under the current constraints, not merely hard or slow local work.

The decisive requirements are environment/release facts that cannot be generated by local source reads, product edits, or harness reruns: canary parity, production error-rate, production WPCOM readiness, live queue and financial data safety, live Stripe Billing data safety, exact production perf, release sequencing, and the actual default/mandatory flips. A5j explicitly says the local packet is green only under recorded limitations and does not claim production/default-on readiness, production WPCOM readiness, canary/error-rate readiness, exact production perf, live queue/financial/Stripe Billing data safety, release sequencing, or A6 cleanup readiness (`analysis-a5j-release-default-on-readiness.md:84-86`; `staging-log.md:1454`).

The source corroborates the fail-closed boundary. Native runtime default remains false and plugin-wins while the standalone plugin is active (`plugins/woocommerce/src/Internal/Payments/NativePaymentsRuntimeArbiter.php:97-105`, `:131-150`, `:177-185`). Mandatory cutover default remains false and is read through `FILTER_MANDATORY_CUTOVER_ENABLED` (`plugins/woocommerce/src/Internal/Payments/Providers/WooPayments/WooPaymentsCutoverController.php:47-54`, `:622-630`). Provider event cutover blockers are empty in current source (`plugins/woocommerce/src/Internal/Payments/Providers/WooPayments/WooPaymentsEventIngestor.php:34-39`). Admin readiness is not a production default-on flip: the preflight admin-surface filter defaults ready only for the already-gated admin surface decision, while explicit false still adds `native_admin_surfaces_unavailable` (`plugins/woocommerce/src/Internal/Payments/Providers/WooPayments/WooPaymentsCutoverController.php:410-419`).

The possible local rerun argument does not defeat the impasse. `review-a5j-local-gate-opportunities.md` recommended only A5f/A5g if the release packet needed one more local freshness stamp, and explicitly says that still does not prove production/default-on rollout, canary/error-rate behavior, WPCOM production readiness, exact production perf, or release sequencing (`review-a5j-local-gate-opportunities.md:22-27`, `:40-80`). A5j then actually ran those A5f/A5g freshness gates and recorded them as passing (`staging-log.md:1452-1453`; `spec-conformance-baseline.md:542-544`). Repeating them again without a source/environment change or release-owner request would add timestamp recency, not material progress.

## Local Slice Audit

I do not find a concrete local product, harness, docs, or evidence slice that would materially advance the full objective without production/default-on evidence.

Product code: no. A5k's release-sequencing audit returned `PRODUCTION_DECISION_ONLY` and found no source-backed local product, harness, or cleanup slice that should run before the production/default-on release decision (`review-a5k-release-sequencing-next-move.md:24-28`, `:54-56`).

Admin/N12 parity: no. The N12 residual audit returned `NO_LOCAL_A4_BLOCKER`; it found native WooPayments admin surfaces reachable through persistent Core Payments submenus and canonical Settings > Payments `/woopayments/*` provider routes, with runtime, capability, account-state, and optional-surface gating, and found no concrete untracked local parity slice (`review-a5k-n12-admin-parity-residuals.md:49-53`, `:87-93`). Current source supports that: native admin hooks register only when the native runtime owns the site (`WooPaymentsAdminNavigationController.php:146-166`), route availability is account-state and feature gated (`:407-437`), menu registration requires `manage_woocommerce` plus native ownership (`:468-474`), and full-account menu items include Overview, Payouts, Transactions, optional Reports/Card Readers/Capital/Documents, and Settings under the Core Payments parent (`:526-574`, `:641-680`).

Harness/evidence: no material slice. A4aq/A4bd, A5f, A5g, and focused guard tests are either already green or useful only if a release owner explicitly requests a fresh local timestamp. The A5j local gate audit explicitly says the broad A4aq accumulated gate should not be rerun by default after no tracked product/admin/checkout change, and focused guard PHPUnit was already covered by A5j (`review-a5j-local-gate-opportunities.md:100-120`, `:201-215`).

Docs: no material slice beyond this blocker write-back. A5j and A5k already package the decision evidence and classify local-vs-release-only requirements. Another docs-only pass could restate the same blocker, but would not unlock the full objective without production/default-on evidence.

A6 cleanup: no pre-release slice. A6a remains a valid future cleanup candidate, but it is currently held. Core still exposes `_wcpay_feature_subscriptions` in the settings service, returns `is_wcpay_subscriptions_enabled`, `is_wcpay_subscriptions_eligible`, and `is_subscriptions_plugin_active`, and writes the option to `0` on disable-only updates (`plugins/woocommerce/src/Internal/Payments/Providers/WooPayments/WooPaymentsSettingsService.php:29`, `:265-281`, `:591-593`). The REST controller still accepts `is_wcpay_subscriptions_enabled` (`plugins/woocommerce/src/Internal/Admin/Settings/PaymentsProviders/WooPayments/WooPaymentsRestController.php:766-781`), and the settings UI still renders the deprecated `Enable Subscriptions with WooPayments` disable/signpost control (`plugins/woocommerce/client/admin/client/woopayments/settings/settings-page.tsx:2123-2214`). The standalone WooPayments extension still defines the same option, reads it through `is_wcpay_subscriptions_enabled()`, treats it as a Stripe Billing/bundled subscriptions signal when WooCommerce Subscriptions is absent, and loads `subscriptions-core` when enabled (`/Users/vladolaru/Work/a8c/woocommerce-payments/includes/class-wc-payments-features.php:24`, `:76-93`, `:306-310`; `/Users/vladolaru/Work/a8c/woocommerce-payments/woocommerce-payments.php:221-304`). That makes A6a release-sequenced while plugin rollback/coexistence remains part of the fail-closed model; starting A6a now would be product-change work against the recorded boundary, not an unblocker.

## Exact Blocker

Exact blocker: production/release-owner evidence and decisioning are missing for canary parity/error-rate/perf, production WPCOM readiness, live queue/financial/Stripe Billing data safety, exact production perf, release sequencing, and the actual native runtime/default/mandatory flips. A6a cleanup is also blocked until release/default-on sequencing clears the plugin-active rollback/coexistence window or an explicit release-owner decision changes that boundary.

## Non-Claims

This audit does not claim the merge goal is complete. It does not claim production/default-on readiness. It does not claim exhaustive visual parity beyond the recorded A4bd limitations. It only concludes that the active controller has reached a legitimate blocked threshold under the current evidence and constraints.
