# Core Native Payments A6 Cleanup Removal Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use $subagent-driven-development (recommended) or $executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Complete A6 by removing the transition-only WooPayments merge harness from the committed core branch after recording the final cleanup and parity evidence.

**Architecture:** The A0-A5 harness under `tools/woopayments-merge/` is root-level transition scaffolding. Its README explicitly says it must not ride into WooCommerce trunk and that Stage A6 should remove it if it stayed on the branch. A6 therefore records the cleanup decision and pre-removal gate evidence in durable logs, then commits a mechanical deletion of `tools/woopayments-merge/` without changing shipped WooCommerce runtime code.

**Tech Stack:** Git, Bash, WooCommerce PHP/Jest regression suites, WooPayments merge harness pre-removal self-check.

---

## Tasks

### Task 1: Record Pre-Removal Evidence

**Files:**

- Modify: `.agents/scratchpad/sessions/2026-06-15-core-native-payments/implementation-log.md`
- Modify: `/Users/vladolaru/Work/a8c/ai-prompts/goals/woopayments-merge/follow-up/staging-log.md`

- [ ] **Step 1: Run the cleanup audit before deleting the harness**

Run:

```bash
bash -n tools/woopayments-merge/a6-cleanup-audit.sh
bash tools/woopayments-merge/a6-cleanup-audit.sh
```

Expected: the temporary audit passes, proving no unsafe Bucket-D cleanup deletion is hidden by the current core-native payments source.

- [ ] **Step 2: Run the final harness self-check before deleting the harness**

Run:

```bash
tools/woopayments-merge/verify.sh --self-check "docker exec -i wcpay_wp_default wp --allow-root"
```

Expected: 5/5 deterministic harness gates pass, including BC/tracks drift, Bucket-E parity, perf RULE-1, and financial reconciliation.

- [ ] **Step 3: Update the logs**

Record the timestamp, audit result, harness self-check result, and the reason for deleting `tools/woopayments-merge/` at A6.

### Task 2: Remove the Transition Harness

**Files:**

- Delete: `tools/woopayments-merge/`

- [ ] **Step 1: Delete the harness directory**

Run:

```bash
git rm -r tools/woopayments-merge
```

Expected: the tracked transition harness files are staged for deletion. No product PHP/JS files are changed by this step.

- [ ] **Step 2: Confirm no untracked harness files remain**

Run:

```bash
find tools/woopayments-merge -maxdepth 1 -type f -print
```

Expected: either the directory is absent or no files are printed.

### Task 3: Verify and Commit A6

**Files:**

- Test: focused native payments PHPUnit regression
- Test: focused admin Jest regression
- Commit: deletion of `tools/woopayments-merge/`

- [ ] **Step 1: Run post-removal static checks**

Run:

```bash
git diff --check
pnpm --filter='@woocommerce/plugin-woocommerce' lint:php:changes
markdownlint .agents/scratchpad/sessions/2026-06-15-core-native-payments/plans/2026-06-15-core-native-payments-a6.md
```

Expected: all commands pass.

- [ ] **Step 2: Run focused PHP regression**

Run:

```bash
pnpm --filter='@woocommerce/plugin-woocommerce' test:php:env -- --filter 'NativePaymentsRuntimeArbiterTest|OrderPaymentStoreTest|PaymentContextTest|PaymentOutcomeTest|CapabilityManifestTest|PaymentSurfaceDifferTest|NativePaymentsShadowModeTest|PaymentLifecycleEventTest|OrderPaymentLifecycleServiceTest|WooPaymentsEventIngestorTest|WooPaymentsWebhookRestControllerTest|WooPaymentsActionSchedulerServiceTest|WooPaymentsFailedEventStoreTest|WooPaymentsWebhookReliabilityServiceTest|WooPaymentsPaymentMethodDetailsServiceTest|PaymentOperationIdempotencyTest|PaymentProcessingServiceTest|PaymentExceptionPolicyTest|NativeWooPaymentsGatewayTest|NativePaymentsGatewayRegistryTest|WooPaymentsProviderGatewayAdapterTest|WooPaymentsProviderTest|WooPaymentsOnboardingAdapterTest|WooPaymentsServiceTest|WooPaymentsCutoverControllerTest'
```

Expected: focused native payments regression passes.

- [ ] **Step 3: Run focused JS regression**

Run:

```bash
pnpm --filter='@woocommerce/admin-library' test:js -- webpack-externals.test.js settings-payments-main.test.tsx payments-sidebar.test.tsx
```

Expected: three suites pass; pre-existing `PaymentsSidebar` React `act()` warnings may still appear.

- [ ] **Step 4: Commit A6**

Run:

```bash
git add -u tools/woopayments-merge
git commit -m "chore(payments): remove native payments transition harness"
```

Expected: A6 commit is created without staging the untracked `.agents/scratchpad/sessions/2026-06-15-core-native-payments/plans/` files.

### Self-Review

- Spec coverage: The plan implements A6 scaffolding removal by deleting the transition harness after running and recording its final gates. Product-code Bucket-D deletion remains blocked where current A3/A5 fail-closed bridge seams still depend on the transitional references.
- Placeholder scan: No placeholder tasks remain.
- Type consistency: The directory and command names are consistent across all tasks.
