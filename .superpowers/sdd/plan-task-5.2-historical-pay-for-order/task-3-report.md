# Task 3 report — historical pay-for-order browser cases

Last updated: 2026-09-21 11:21 +0300

> **Prompt:** Implement Task 3 of the historical pay-for-order transition family, adding only the planned browser specification, disposition rows, and this report.

## Scope

Created `transitions/historical-money-records.spec.ts`, added its two exact `RULE 5` disposition rows, and wrote this report. No production code, browser/provider scenario, listener, store lifecycle, wp-env lifecycle, WPCOM action, or action on port 8889 was performed.

## RED

Command: `E2E_TRANSITION_SEED_PROFILE=11.1.0 pnpm --dir plugins/woocommerce exec playwright test --config=tests/e2e/envs/woopayments-native/playwright.config.ts --project=woopayments-native-transition tests/e2e/tests/woopayments-native/transitions/historical-money-records.spec.ts --list`

Before implementation, it exited 1 with `Error: No tests found` because the planned specification did not exist. This was collection-only; no case, browser, or provider operation executed.

## Implementation

Added the disabled and enabled card-testing-protection cases with their exact client-contract annotations and the transition-family tags. The browser adapter calls `collectHistoricalPayForOrderRecovery()` in its staged form: the pre-cutover adapter acquires the real transition provider lock, creates the live plugin-era generic-decline fixture, re-reads the fixture and complete decline graph, and makes one real soft cutover that returns native ownership. Only after that lock completes does the post-cutover adapter enter `withCapturedCardTestingProtectionState()` directly, letting that helper own its sole provider lock while it captures session protection, reads rendered protection, makes the single native pay-for-order submission, waits for settled provider state, and cold-reads the recovery graph.

The two durable submission journals are named `client-decline` and `native-pay-for-order`. The adapter preserves the required CTP false/true wire-evidence branches and supplies the collector's cleanup manifest contract. It neither nests the CTP helper inside a transition provider lock nor uses a no-op cutover.

## Collection and metadata

Command: `E2E_TRANSITION_SEED_PROFILE=11.1.0 pnpm --dir plugins/woocommerce exec playwright test --config=tests/e2e/envs/woopayments-native/playwright.config.ts --project=woopayments-native-transition tests/e2e/tests/woopayments-native/transitions/historical-money-records.spec.ts --list`

Output: exit 0; exactly the two required titles collected once, `Total: 2 tests in 1 file`. The reporter's optional environment-info probe reported `ECONNREFUSED` for localhost:8086 after collection; it did not run a case.

Command: `node plugins/woocommerce/tests/e2e/bin/validate-woopayments-client-contract-map.mjs && node --test plugins/woocommerce/tests/e2e/bin/validate-woopayments-client-contract-map.test.mjs`

Output: exit 0; the map validated 181 contracts and all 61 validator tests passed.

The stricter `--require-migrated` mode exits 1 on the pre-existing unrelated `merchant-orders-multi-currency.spec.ts:70` unmigrated contract. No client-contract-map change is in this task.

## Static verification

Command: `/Users/vladolaru/Work/a8c/general/.verification-tool-overlays/bin/verify oxlint --new-vs=6c48e892c3 plugins/woocommerce/tests/e2e/tests/woopayments-native/transitions/historical-money-records.spec.ts`

Output: exit 0; `oxlint: 0 new findings from this change (0 pre-existing in these files, not attributable)`.

Command: `git diff --check`

Output: exit 0; no whitespace errors.

## Concerns

Only collection and non-executing metadata/static checks were authorized for Task 3. The live provider/browser path, external listener behavior, and cleanup effects remain deliberately unexecuted. The strict migration metadata gate has an unrelated existing inventory gap described above.
