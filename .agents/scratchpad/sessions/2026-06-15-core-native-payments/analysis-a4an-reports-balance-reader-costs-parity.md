---
session: 2026-06-15-core-native-payments
type: analysis
by: codex
created: 2026-06-20 10:07
last_updated: 2026-06-20 10:23
target: A4an Reports Balance reader-costs parity
reconciles:
  - staging-log.md
  - implementation-log.md
  - supervisor-prompt-2026-06-18-2344-N12.md
  - analysis-a4w-reports-parity.md
status: final
---

# A4an Reports Balance Reader-Costs Parity Analysis

> **Prompt:** "ok. continue"

## Current Finding

A4w restored the native Reports surface and backend `reader_fees` schema, but the current native Balance UI still collapses the reference reconciliation row contract. The staging log correctly kept "Reports reader-fee aggregation / `Reader costs` parity" open after A4al/A4am.

Source-backed gap:

- Native `getBalanceRows()` in `plugins/woocommerce/client/admin/client/woopayments/admin/reports/page.tsx:587` renders only `starting_balance`, `total_charges_captured` labeled as `Charges`, `fees`, `refunds`, `disputes`, `payouts`, and `ending_balance`.
- Reference `client/reports/balance/rows.ts:16` defines the full row key set: `starting_balance`, `total_charges_captured`, `fees`, `charge_fees`, `payout_fees`, `reader_fees`, `dispute_fees`, `fee_refunds`, `refunds`, `refund_failure`, `disputes`, `financing_payout`, `financing_paydown`, `network_costs`, `other_adjustments`, `net_balance_change_in_the_period`, `payouts`, and `ending_balance`.
- Reference `client/reports/balance/rows.ts:117` labels the charges row `Total charges captured`, not `Charges`.
- Reference `client/reports/balance/rows.ts:190` maps `reader_fees` to the merchant-facing label `Reader costs`.
- Reference REST schema `includes/reports/class-wc-rest-payments-reports-balance-controller.php:155` already includes all relevant balance keys, including `reader_fees` at line 160. Native Core already mirrors `reader_fees` in `plugins/woocommerce/client/admin/client/woopayments/admin/reports/types.ts:61`, so this slice should be frontend row-contract parity unless source-map review finds a backend omission.
- Existing native test fixture `plugins/woocommerce/client/admin/client/woopayments/admin/test/reports-page.test.tsx:187` only includes `starting_balance`, `fees`, and `ending_balance`, so the current tests cannot catch the omitted rows or the `Charges` label drift.

## Slice Boundary

A4an should restore the Reports Balance row contract and evidence for `Reader costs`, not rework Reports routing, Fees, backend registration, or all N12 admin parity. The Reports route/chunk/backend exist from A4w; this slice repairs the bounded Balance table/export/readout parity that remained after the reader-fee detail work.

In scope:

- Add the full reference Balance row key set to the native Reports Balance UI in the reference order.
- Use the correct merchant labels, including `Total charges captured` and `Reader costs`.
- Preserve anchor rows (`Starting balance`, `Total charges captured`, `Fees`, `Net balance change in the period`, `Payouts`, `Ending balance`) according to reference visibility.
- Show non-anchor rows when their amount or count is non-zero, including `reader_fees`.
- Keep the current DataViews-based native table mechanics; do not force a reference CSS clone where DataViews naturally differs.
- Add regression tests that fail on the current collapsed row set and verify `Reader costs` plus adjacent fee/adjustment rows.
- Browser-proof a target Reports Balance route that actually renders `Reader costs`; if local live account data lacks that row, use a local browser probe/mock only if it does not mask product behavior and record the caveat.

Out of scope:

- No WPCOM changes or sandbox access.
- No Reports backend route redesign.
- No Fees table parity changes unless the source-map subagent finds a direct row-contract coupling.
- No generic WooCommerce Analytics changes.
- No A4 readiness flip.

## Verification Expectations

- RED focused Jest should fail before implementation because `Reader costs` and `Total charges captured` are absent.
- GREEN focused Jest should cover canonical Balance rows, visibility of non-zero `reader_fees`, and activity row count/speak behavior.
- Static gates: exact-file ESLint for Reports page/tests, admin type lint, targeted Stylelint if SCSS changes, admin bundle build, A4 admin surface harness for Reports chunk measurement, `git diff --check -- . ':!.agents'`, and branch lint before commit.
- Browser/log gates: Playwriter target Reports Balance proof with visible `Reader costs` where possible, failed-response/console capture, target `debug.log` and WC logs checked after proof. Compare the reference route source/behavior enough to verify labels/order, while avoiding overclaiming full Reports visual parity.

## Result

Implemented. The source-map subagent confirmed the native row adapter was the bounded gap: reference Balance renders the full reconciliation row set and labels `reader_fees` as `Reader costs`, while native collapsed the row set and labeled `total_charges_captured` as `Charges`. The native Reports Balance row adapter now preserves the reference order/labels/anchor-row visibility over the existing backend schema, including `Reader costs`.

Verification covered RED/GREEN Reports page Jest, PHP REST pass-through for `reader_fees`/`net_balance_change_in_the_period`, focused Reports data/query/page Jest, focused Reports REST PHPUnit, exact-file ESLint, admin type lint, changed-file PHPCS, production controller PHPStan, admin bundle build, A4 admin surface gate, Playwriter live and controlled Reports Balance browser proof, target debug/WC/Docker log scans, changelog validation, diff check excluding `.agents`, and branch lint. The only recorded non-blocking noise is existing Composer/PHP 8.4 vendor deprecations, webpack cache-serialization warnings, Changelogger `E_STRICT` deprecations, and branch-wide JS ignored-file warnings.
