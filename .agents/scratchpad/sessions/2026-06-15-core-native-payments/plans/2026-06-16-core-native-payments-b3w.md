---
session: 2026-06-15-core-native-payments
type: plan
by: codex
created: 2026-06-16 15:07
last_updated: 2026-06-16 15:13
status: final
---

# B3w Orphaned WooPayments Admin Client Residue Removal Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use $executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Remove the remaining dead admin-client WooPayments residue left behind by the deleted welcome-page and onboarding surfaces without touching the live settings/onboarding compatibility paths.

**Architecture:** Treat this as a frontend-only cleanup package. Remove the stale `launch-your-store` icon alias for the deleted `woocommerce-payments` task id and delete the unused WooPayments modal SCSS selectors that no longer have any consumer. Keep the negative regression tests that prove the deprecated welcome-page and onboarding task surfaces stay gone, and leave live identifiers such as `wcpay_welcome_page_connect_nonce` alone.

**Tech Stack:** WC Admin React/TypeScript, SCSS, Jest, direct source-boundary scans, ESLint, TypeScript declarations.

---

### Task 1: Lock the Dead Client Residue With a Focused RED Test and Source Scans

**Files:**
- Create: `plugins/woocommerce/client/admin/client/launch-your-store/hub/sidebar/components/icons.test.tsx`

- [x] **Step 1: Add a focused task-icons RED test**

Create `icons.test.tsx` beside `icons.tsx` and assert:

```tsx
import { taskIcons } from './icons';

describe( 'taskIcons', () => {
	it( 'does not keep the deprecated WooPayments task alias', () => {
		expect( taskIcons ).toHaveProperty( 'payments' );
		expect( taskIcons ).not.toHaveProperty( 'woocommerce-payments' );
	} );
} );
```

Expected RED: the current icon map still exports the deprecated `woocommerce-payments` alias.

- [x] **Step 2: Prove the orphaned SCSS selectors still exist**

Run:

```bash
rg -n "woocommerce-payments__usage-modal|woocommerce-payments__usage-modal-message|woocommerce-payments__usage-footer" plugins/woocommerce/client/admin/client/layout/style.scss
```

Expected RED: all three selectors are still present in `layout/style.scss`.

- [x] **Step 3: Run the focused RED test**

Run:

```bash
pnpm --filter='@woocommerce/admin-library' test:js -- client/launch-your-store/hub/sidebar/components/icons.test.tsx
```

Expected RED: the new test fails because `taskIcons` still contains `woocommerce-payments`.

### Task 2: Remove the Orphaned Frontend Residue

**Files:**
- Modify: `plugins/woocommerce/client/admin/client/launch-your-store/hub/sidebar/components/icons.tsx`
- Modify: `plugins/woocommerce/client/admin/client/layout/style.scss`

- [x] **Step 1: Remove the stale WooPayments task icon alias**

Delete the `'woocommerce-payments': payment` entry from `taskIcons` and keep the live `payments` icon mapping intact.

- [x] **Step 2: Remove the unused WooPayments modal SCSS block**

Delete the orphaned selectors:

```scss
.woocommerce-payments__usage-modal
.woocommerce-payments__usage-modal-message
.woocommerce-payments__usage-footer
```

from `layout/style.scss`. Do not touch the generic `.woocommerce-usage-modal__*` selectors that are still used by live inbox and task-list dismiss modals.

### Task 3: Verify, Log, Changelog, and Commit

**Files:**
- Create: `plugins/woocommerce/changelog/add-native-payments-b3w-remove-orphaned-admin-client-wcpay-residue`
- Modify: `.agents/scratchpad/sessions/2026-06-15-core-native-payments/implementation-log.md`
- Modify: `.agents/scratchpad/sessions/2026-06-15-core-native-payments/plans/2026-06-16-core-native-payments-b3w.md`
- Modify: `.agents/scratchpad/sessions/2026-06-15-core-native-payments/README.md`
- Modify: `/Users/vladolaru/Work/a8c/ai-prompts/goals/woopayments-merge/follow-up/staging-log.md`

- [x] **Step 1: Add changelog**

Create:

```text
plugins/woocommerce/changelog/add-native-payments-b3w-remove-orphaned-admin-client-wcpay-residue
```

with:

```text
Significance: patch
Type: dev
Comment: Remove orphaned WooPayments admin client residue left behind by deprecated onboarding surface removals.
```

- [x] **Step 2: Run focused client verification**

Run:

```bash
pnpm --filter='@woocommerce/admin-library' test:js -- client/launch-your-store/hub/sidebar/components/icons.test.tsx
pnpm --filter='@woocommerce/admin-library' exec eslint client/launch-your-store/hub/sidebar/components/icons.tsx client/launch-your-store/hub/sidebar/components/icons.test.tsx --ext=ts,tsx
pnpm --filter='@woocommerce/admin-library' lint:lang:types
git diff --check
git diff --cached --check
```

Require all commands to pass.

- [x] **Step 3: Run source-boundary scans for the removed residue**

Run:

```bash
rg -n "'woocommerce-payments': payment|woocommerce-payments__usage-modal|woocommerce-payments__usage-modal-message|woocommerce-payments__usage-footer" plugins/woocommerce/client/admin/client --glob '!**/test/**' --glob '!**/tests/**'
```

Expected GREEN: no matches.

- [x] **Step 4: Update logs, commit, and run branch lint**

Append B3w RED and GREEN evidence to the implementation log, update the session README latest progress, append the completed package to the external staging log, mark this plan `status: final` with all checkboxes completed, commit the package with a conventional commit, and run:

```bash
pnpm --filter='@woocommerce/plugin-woocommerce' lint:changes:branch
```

Record the final git range in the logs and final handoff.
