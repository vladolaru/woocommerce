# Task 5: Historical Transition AVAILABLE State

Last updated: 2026-09-21 17:15 EEST

## Scope

Changed only the transition provisioner, its executable shell suite, and its fake `wp-env` adapter. Historical pay-for-order scenarios now seed `NativePaymentsState::AVAILABLE` after the exact reference-account cache seed; cutover reconciliation remains `ACTIVE` only, and unrelated scenarios write neither state.

## RED evidence

After adding the independent fake-state markers and historical AVAILABLE assertions, but before changing the provisioner, the full shell suite failed at `historical-pay-for-order-ctp-false` with `Historical scenario did not persist native AVAILABLE state after the account seed: historical-pay-for-order-ctp-false.` The existing provisioner seeded only the account cache for historical scenarios, so the required AVAILABLE marker was absent.

## GREEN evidence

- `bash plugins/woocommerce/tests/e2e/envs/woopayments-native/provision-transition-store-real.test.sh` reached `provision-transition-store-real.sh reference fixture tests passed.`
- `bash -n plugins/woocommerce/tests/e2e/envs/woopayments-native/provision-transition-store-real.sh` exited 0.
- `bash -n plugins/woocommerce/tests/e2e/envs/woopayments-native/provision-transition-store-real.test.sh` exited 0.
- `bash -n plugins/woocommerce/tests/e2e/envs/woopayments-native/test-fixtures/fake-transition-pnpm.sh` exited 0.
- `git diff --check` exited 0.

The stdin JSON now carries exact AVAILABLE and ACTIVE booleans. The in-store code requires both fields to be booleans, rejects a request for both states, writes only after account cache and test mode setup, and fails creation when either requested write returns false. The fake service emits distinct `native-available-seeded` and `native-active-seeded` markers; the suite proves historical AVAILABLE-only behavior, reconciliation ACTIVE-only behavior, unrelated neither-state behavior, and an AVAILABLE write failure.

## Self-review

The historical branches are the only branches that request AVAILABLE. `cutover-reconciliation` remains the only ACTIVE branch. The state request crosses only the established stdin JSON boundary, writes no extra state field, preserves account-before-state ordering, and selects a single state with an `elseif` after mutual-exclusion validation.
