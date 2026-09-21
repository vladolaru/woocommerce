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

## Fix round 1/5

### RED

Command: `pnpm --dir plugins/woocommerce exec playwright test --config=tests/e2e/envs/woopayments-native/unit.playwright.config.ts tests/e2e/utils/woopayments-native/drivers/card-testing-protection.test.ts --grep "fresh authenticated order-pay"`

Output: exit 1. The requested authenticated-session capture method did not exist and the helper quarantined during teardown after the missing capture path. This demonstrates the guest-only interface could not collect the logged-in order-pay session.

Command: `pnpm --dir plugins/woocommerce exec playwright test --config=tests/e2e/envs/woopayments-native/unit.playwright.config.ts tests/e2e/utils/woopayments-native/drivers/historical-pay-for-order.test.ts --grep "pre-teardown cleanup"`

Output: exit 1; the validator accepted the fabricated `cleaned` receipt. This establishes that the browser-stage cleanup boundary was too broad before the correction.

### Implementation

Added an additive `captureAuthenticatedSessionProtection()` scope API. It preserves guest capture and guest cleanup, requires the registered zero-cookie context, parses and binds the numeric WooCommerce session customer to the expected positive order customer ID, and obtains public-safe token evidence through a new authenticated-session runner operation without deleting the customer's session.

`requireEphemeralTransitionAllocation()` now returns its already validated allocation identity, which the historical fixture copies exactly. The scenario uses $10.01 with the plan's exact `0345` / `525` card tuple, treats rendered and submitted CTP evidence as separate boundaries, parses the one submitted checkout request for the wire token, and binds the order-pay URL, rendered order number/total, and receipt ID/key to the immutable failed order before cold provider reads.

The historical recovery evidence now accepts only the exact run-owned cleanup manifest at browser stage; any `cleaned` property is rejected as a pre-teardown receipt. Actual deletion/allocation-destruction proof is reserved for Task 4. Native success also carries observed provider occurrence and capture-occurrence counts, and the unit suite includes a 1001-USD mutation for the reused generic-decline oracle.

### GREEN

Command: `pnpm --dir plugins/woocommerce exec playwright test --config=tests/e2e/envs/woopayments-native/unit.playwright.config.ts tests/e2e/utils/woopayments-native/drivers/card-testing-protection.test.ts tests/e2e/utils/woopayments-native/drivers/historical-pay-for-order.test.ts`

Output: exit 0; `84 passed (1.1s)`.

Command: `E2E_TRANSITION_SEED_PROFILE=11.1.0 pnpm --dir plugins/woocommerce exec playwright test --config=tests/e2e/envs/woopayments-native/playwright.config.ts --project=woopayments-native-transition tests/e2e/tests/woopayments-native/transitions/historical-money-records.spec.ts --list`

Output: exit 0; the two exact planned titles collected once, `Total: 2 tests in 1 file`. The optional reporter environment probe reported localhost:8086 unavailable after collection; no browser case ran.

Command: `node plugins/woocommerce/tests/e2e/bin/validate-woopayments-client-contract-map.mjs`

Output: exit 0; `Validated 181 WooPayments client contracts.`

Command: `/Users/vladolaru/Work/a8c/general/.verification-tool-overlays/bin/verify oxlint --new-vs=afd32983b4 <six changed TypeScript files>` and `git diff --check`

Output: exit 0; Oxlint reported zero new findings (three pre-existing non-attributable findings) and diff check found no whitespace errors.

## Fix round 2/5

### RED

The round-one review established the page-evidence RED: `form-pay.php` has no visible order number and contains multiple occurrences of the same amount, so the prior unscoped locators could not prove the required identity. The initial targeted TypeScript diagnostic also reported the changed-file errors for the missing `withClassicCheckoutPage()` run ID, missing bounded rejection timeout, widened literal capture count, and a `void` allocation override.

### Implementation

The browser case now uses Core's rendered document-title order identity and scopes the amount to the `form-pay.php` semantic Total row (`#order_review tfoot` row header plus `.product-total` cell). The exact URL/key remains the route identity, the authenticated session binds customer ownership, and the receipt still must match the original order ID/key.

The staged collector now returns `HistoricalPayForOrderBrowserEvidence`, which deliberately excludes cardinality, listener, and submission-journal claims. It retains browser-observable order, provider success, CTP, and cleanup-manifest facts, while the existing full `HistoricalPayForOrderEvidence` validator remains the strict Task 4 receipt consumer. The added unit proves runner-only fields supplied by a cold-read adapter cannot leak into browser evidence.

Updated every affected transition-allocation test implementation for the new validated-allocation return type and corrected the exact Classic page and decline-notice calls.

### GREEN

Command: `pnpm --dir plugins/woocommerce exec playwright test --config=tests/e2e/envs/woopayments-native/unit.playwright.config.ts tests/e2e/utils/woopayments-native/drivers/historical-pay-for-order.test.ts tests/e2e/utils/woopayments-native/drivers/card-testing-protection.test.ts`

Output: exit 0; `85 passed (1.1s)`.

Command: `pnpm --dir plugins/woocommerce exec tsc --project tests/e2e/tsconfig.json --noEmit 2>&1 | rg "historical-money-records|historical-pay-for-order|card-testing-protection|woopayments-native\\.ts|pilot-runtime\\.test|store-transition\\.test"`

Output: no remaining historical-pay-for-order, CTP, fixture, or allocation-override diagnostic. The full project still reports unrelated existing errors in `store-transition.test.ts:397` and `pilot-runtime.test.ts:3272`, which are outside this change's attributable interface paths.

Command: exact collection command; `node plugins/woocommerce/tests/e2e/bin/validate-woopayments-client-contract-map.mjs`; `node --test plugins/woocommerce/tests/e2e/bin/validate-woopayments-client-contract-map.test.mjs`; changed-file Oxlint against `1ac94a2190`; and `git diff --check`.

Output: all exit 0. Collection lists each retained title once, map validation reports 181 contracts, all 61 map tests pass, Oxlint reports zero new findings, and no whitespace errors exist. The reporter's post-collection localhost:8086 environment probe remains non-executing and unavailable.
