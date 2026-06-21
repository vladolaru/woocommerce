---
session: 2026-06-15-core-native-payments
type: plan
by: codex
created: 2026-06-19 18:51
target: A4y native WooPayments dispute challenge parity
reconciles:
  - ../analysis-a4y-dispute-challenge-parity.md
  - ../supervisor-prompt-2026-06-18-2344-N12.md
  - ../staging-log.md
status: draft
last_updated: 2026-06-19 20:19
---

# A4y Native Dispute Challenge Parity Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use $subagent-driven-development (recommended) or $executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Restore the native WooPayments dispute challenge flow to reference-shaped multi-step evidence parity while preserving the existing native REST/file-upload contracts and Settings > Payments provider route ownership.

**Architecture:** Keep `/woopayments/disputes/challenge` in the existing lazy `settings-payments-woopayments-money-movement` chunk. Reuse native dispute/file/update data helpers and backend routes; port the reference wizard behavior into Core-owned adjacent components/helpers rather than importing plugin code. Preserve reference copy/content where it remains correct, adapt plugin-era route targets to native `/woopayments/*`, keep read-only/file-size/evidence-clearing guardrails, and keep native admin readiness fail-closed until the final A4/N12 exit gate.

**Tech Stack:** React/TypeScript admin components, `@wordpress/components`, `@wordpress/a11y`, `@wordpress/icons`, Jest/RTL, SCSS, WooPayments native REST helpers, focused PHPUnit only if response enrichment changes, Playwriter, `tools/woopayments-merge/a4-admin-surface-gate.py`.

## Current Status Checkpoint

As of 2026-06-19 20:19 EEST, A4y is committed locally as `20f407ccf3` (`feat(payments): add native dispute challenge parity`) plus changelog `966cd9a723` (`chore(payments): add dispute challenge parity changelog`). The implementation fixed the a11y/API-contract findings, the initial source-backed reference-integrity blockers, two late reference-integrity issues around return-tracking recommendation/payload preservation, and one Playwriter-discovered fraudulent cover-letter status leak. Post-fix focused Jest, targeted ESLint, typecheck, stylelint, build, harness, Playwriter review-step proof, current-window log scans, changelog validation, `git diff --check`, and branch lint passed before commit. Post-commit status and diff-check sanity are clean. Do not restart helper/model, wizard implementation, or the reference corrections after compaction; continue with the next reopened-A4 parity slice. Git range: `43d5ced7e5...966cd9a723`.

- Completed: helper/model RED/GREEN; cover-letter helper; staged basics/shipping/review form; recommended document rendering; draft autosave on Continue; upload-busy blocking copy; generated/manual cover-letter review; final-submit confirmation copy; post-submit confirmation links; Visa compliance single-panel flow; duplicate and credit-not-processed status controls; source-backed product-type labels/options; reference recommended-documents copy and docs link; a11y fixes for step focus, duplicate fieldset headings, and upload-focus retention; API-contract fixes for stale shipping evidence, cover-letter shipping gating, and autosave Tracks suppression; reference-integrity fixes for the recommended-document matrix, formal bank-facing cover letter, separated shipping upload documents, Visa compliance upload copy, Visa-specific confirmation copy, return-tracking recommendation, and return-tracking payload preservation; cover-letter reason gating so fraudulent disputes do not render unrelated refund/duplicate status lines.
- Source-backed correction now applied: the non-Stripe `refund_receipt_documentation` field was removed from the native evidence model; duplicate and credit-not-processed refund-receipt evidence now use Stripe key `duplicate_charge_documentation` with context-specific labels; product options now match the reference feature-enabled set: `Physical products`, `Digital products`, `Offline service`, `Booking/Reservation`, `Event`, and `Other`.
- Changed product files: `dispute-evidence-fields.ts`; `dispute-evidence-cover-letter.ts`; `dispute-evidence-form.tsx`; `dispute-evidence-file-upload.tsx`; `dispute-evidence.scss`; `dispute-evidence-fields.test.ts`; `dispute-challenge-page.test.tsx`.
- Changed ignored harness file: `tools/woopayments-merge/a4-admin-surface-gate.py`, with evidence in `data/a4y-admin-surface-gate-prebuild.json` and `data/a4y-admin-surface-gate-green.json`.
- Gates reliable after the browser-found cover-letter fix: focused A4y Jest passed with 2 suites and 45 tests; targeted ESLint passed with no output; source grep shows `Refund status:` and `Duplicate status:` only in the reason-gated generator and negative test assertions; admin-library `ts:check` passed; targeted Stylelint passed; admin-library `build:project:bundle` passed with known unrelated email-editor/tour-kit webpack cache serialization warnings; postbuild `tools/woopayments-merge/a4-admin-surface-gate.py` passed and wrote `data/a4y-admin-surface-gate-post-cover-letter-fix.json`; changelog validation passed with existing PHP 8.4 vendor deprecation noise; `git diff --check -- . ':!.agents'` passed; branch lint exited 0 with known branch-wide JS ignored-file warnings and PHP clean.
- Review blockers remaining: no known source-backed blocker remains in the current worktree. A11y and API-contract reviews approved. Final reference-integrity re-review approved after the return-tracking fixes. Browser proof found and source-fixed the cover-letter status leak, and the rebuilt regenerated cover letter now passes browser proof.
- Browser/log evidence: Playwriter proof against target challenge route with actionable dispute `du_1TjifiJd67Ti1EoIyvnBCdrw` reached basics, shipping, and review with no failed HTTP responses. After clearing the stale local draft textarea without saving, the regenerated fraudulent review cover letter contains `Subject: Chargeback Dispute` and `Dear Dispute Resolution Team` and does not contain `Refund status:` or `Duplicate status:`. Screenshots were saved at `data/a4y-native-dispute-challenge-review-generated-desktop-top.png` and `data/a4y-native-dispute-challenge-review-generated-mobile-top.png`; target/current-window log scans found no fresh HTTP 4xx/5xx or PHP/WP notices/warnings/errors.
- Remaining before closeout: none for A4y. Continue with the next reopened-A4 parity slice and keep native admin readiness fail-closed until the widened A4/N12 exit gate passes.
- Current bundle checkpoint after the cover-letter fix: the lazy money-movement chunk is `61502` raw / `14233` gzip bytes in `a4y-admin-surface-gate-post-cover-letter-fix.json`, compared with the prior A4x money-movement chunk around `32222` raw / `8828` gzip bytes. Treat this as slice-local evidence, not the final A4 performance exit verdict.

---

## File Map

- Modify: `plugins/woocommerce/client/admin/client/woopayments/admin/money-movement/dispute-evidence-fields.ts` to add reference-shaped product types, evidence field metadata, recommended document selection, `needsShipping()`, and helper types.
- Create: `plugins/woocommerce/client/admin/client/woopayments/admin/money-movement/dispute-evidence-cover-letter.ts` for deterministic cover-letter generation from dispute/order/evidence/settings-like fields available in native.
- Create: `plugins/woocommerce/client/admin/client/woopayments/admin/money-movement/dispute-evidence-recommended-documents.tsx` for the recommended documents section.
- Create: `plugins/woocommerce/client/admin/client/woopayments/admin/money-movement/dispute-evidence-confirmation.tsx` for post-submit confirmation.
- Modify: `plugins/woocommerce/client/admin/client/woopayments/admin/money-movement/dispute-evidence-file-upload.tsx` to support reference-style file chips/descriptions and keep current upload guards.
- Modify: `plugins/woocommerce/client/admin/client/woopayments/admin/money-movement/dispute-evidence-form.tsx` to become the wizard shell: summary accordion, stepper/back/next, autosave-on-step-change, conditional shipping, generated cover letter, save/submit, Visa compliance path, read-only mode, and confirmation screen.
- Modify: `plugins/woocommerce/client/admin/client/woopayments/admin/money-movement/dispute-challenge-page.tsx` only for data/status wiring needed by the wizard.
- Modify: `plugins/woocommerce/client/admin/client/woopayments/admin/money-movement/dispute-evidence.scss` and possibly `plugins/woocommerce/client/admin/client/woopayments/admin/style.scss` for scoped wizard styling.
- Modify: `plugins/woocommerce/client/admin/client/woopayments/admin/money-movement/types.ts` only if native dispute/order/charge typing needs fields already returned by the backend.
- Test: `plugins/woocommerce/client/admin/client/woopayments/admin/test/dispute-challenge-page.test.tsx`.
- Optional test: create `plugins/woocommerce/client/admin/client/woopayments/admin/test/dispute-evidence-fields.test.ts` if helper behavior becomes too dense for page tests.
- Optional backend: `WooPaymentsMoneyMovementOrderService.php` and `WooPaymentsMoneyMovementRestControllerTest.php` only if browser/source verification proves the current native dispute response lacks order IP or other data required by the reference-equivalent wizard.
- Harness: `tools/woopayments-merge/a4-admin-surface-gate.py` only for honest source/static-token coverage of the dispute challenge wizard.
- Docs/evidence: update `analysis-a4y-dispute-challenge-parity.md`, `implementation-log.md`, and `staging-log.md`; add a WooCommerce changelog entry after verification.

## Task 1: Evidence Model, Matrix, Shipping, And Cover Letter

**Files:**
- Modify: `plugins/woocommerce/client/admin/client/woopayments/admin/money-movement/dispute-evidence-fields.ts`
- Create: `plugins/woocommerce/client/admin/client/woopayments/admin/money-movement/dispute-evidence-cover-letter.ts`
- Test: `plugins/woocommerce/client/admin/client/woopayments/admin/test/dispute-challenge-page.test.tsx`
- Optional test: `plugins/woocommerce/client/admin/client/woopayments/admin/test/dispute-evidence-fields.test.ts`

- [ ] **Step 1: Write RED helper/page tests.**

Add tests proving behavior missing from the current flat form:

```ts
expect( needsShipping( 'fraudulent', 'physical_product' ) ).toBe( true );
expect( needsShipping( 'duplicate', 'physical_product' ) ).toBe( false );
expect( getRecommendedDocumentFields( {
	reason: 'fraudulent',
	productType: 'physical_product',
	evidence: {},
} ).map( ( field ) => field.key ) ).toEqual(
	expect.arrayContaining( [ 'customer_communication', 'receipt', 'shipping_documentation' ] )
);
expect( generateDisputeCoverLetter( fixture ) ).toContain( fixture.dispute.id );
expect( generateDisputeCoverLetter( fixture ) ).toContain( fixture.evidence.product_description );
```

In the existing page test, assert that a physical-product fraudulent dispute renders recommended document descriptions and that a duplicate dispute does not render shipping fields.

- [ ] **Step 2: Run RED tests.**

Run:

```bash
pnpm --filter=@woocommerce/admin-library test:js --runTestsByPath client/woopayments/admin/test/dispute-challenge-page.test.tsx --runInBand
```

Expected: missing helper exports, missing recommended document copy, and shipping-gating assertions fail.

- [ ] **Step 3: Implement helper GREEN.**

Add Core-owned helper exports:

```ts
export const PRODUCT_TYPE_OPTIONS = [
	{ label: __( 'Physical product', 'woocommerce' ), value: 'physical_product' },
	{ label: __( 'Digital product or service', 'woocommerce' ), value: 'digital_product_or_service' },
	{ label: __( 'Offline service', 'woocommerce' ), value: 'offline_service' },
	{ label: __( 'Event', 'woocommerce' ), value: 'event' },
	{ label: __( 'Booking or reservation', 'woocommerce' ), value: 'booking_reservation' },
	{ label: __( 'Subscription', 'woocommerce' ), value: 'subscription' },
	{ label: __( 'Multiple products', 'woocommerce' ), value: 'multiple' },
	{ label: __( 'Other', 'woocommerce' ), value: 'other' },
] as const;
```

Port the bounded reference matrix for the dispute reasons native can exercise locally first: `fraudulent`, `product_not_received`, `subscription_canceled`, `product_unacceptable`, `duplicate`, and `credit_not_processed`. Include field labels/descriptions that are merchant guidance, not just keys. Add `needsShipping( reason, productType )` with the reference rule: physical products need shipping except duplicate, subscription-canceled, and credit-not-processed reasons. Preserve the existing `buildEvidencePayload()` clearing contract exactly.

Create `generateDisputeCoverLetter()` that produces stable, merchant-facing text from native data: dispute id, reason, order/customer facts, product description, shipping facts when applicable, refund/duplicate status when applicable, and current evidence file presence. Do not depend on WPCOM or plugin globals.

- [ ] **Step 4: Run helper tests GREEN.**

Run the focused Jest command again and confirm the helper/page tests pass.

## Task 2: Recommended Documents And File Upload Presentation

**Files:**
- Create: `plugins/woocommerce/client/admin/client/woopayments/admin/money-movement/dispute-evidence-recommended-documents.tsx`
- Modify: `plugins/woocommerce/client/admin/client/woopayments/admin/money-movement/dispute-evidence-file-upload.tsx`
- Modify: `plugins/woocommerce/client/admin/client/woopayments/admin/money-movement/dispute-evidence.scss`
- Test: `plugins/woocommerce/client/admin/client/woopayments/admin/test/dispute-challenge-page.test.tsx`

- [ ] **Step 1: Write RED UI tests.**

Add tests for:

```tsx
expect( screen.getByRole( 'heading', { name: 'Recommended documents' } ) ).toBeInTheDocument();
expect( screen.getByText( 'While optional, we strongly recommend providing as many of these documents as possible. The following file types are supported: PDF, JPEG, and PNG.' ) ).toBeInTheDocument();
expect( screen.getByRole( 'link', { name: 'Learn more about documents' } ) ).toHaveAttribute( 'href', expect.stringContaining( 'managing-disputes/#challenge-or-accept' ) );
expect( screen.getByRole( 'button', { name: /Upload .*Order receipt/ } ) ).toBeEnabled();
```

Assert uploaded file chips show filename/size and a remove action with an accessible name.

- [ ] **Step 2: Implement recommended document component.**

Render a section with reference copy, docs link, ordered field rows, labels/descriptions from the matrix, and `DisputeEvidenceFileUpload` for each field. Keep real `<button>` controls, visible focus, and `aria-describedby` links from upload buttons to field descriptions when possible.

Update `DisputeEvidenceFileUpload` so it can render the current file chip and remove button in the reference shape while preserving native upload behavior, aggregate file-size checks, accepted file types, Tracks events, and live error handoff.

- [ ] **Step 3: Run focused tests and stylelint.**

Run:

```bash
pnpm --filter=@woocommerce/admin-library test:js --runTestsByPath client/woopayments/admin/test/dispute-challenge-page.test.tsx --runInBand
pnpm --filter=@woocommerce/admin-library lint:lang:css -- client/woopayments/admin/money-movement/dispute-evidence.scss
```

## Task 3: Multi-Step Wizard, Autosave, Conditional Shipping, And Cover Letter

**Files:**
- Modify: `plugins/woocommerce/client/admin/client/woopayments/admin/money-movement/dispute-evidence-form.tsx`
- Modify: `plugins/woocommerce/client/admin/client/woopayments/admin/money-movement/dispute-challenge-page.tsx`
- Modify: `plugins/woocommerce/client/admin/client/woopayments/admin/money-movement/dispute-evidence.scss`
- Test: `plugins/woocommerce/client/admin/client/woopayments/admin/test/dispute-challenge-page.test.tsx`

- [ ] **Step 1: Write RED wizard tests.**

Add tests for:

```tsx
expect( screen.getByRole( 'heading', { name: "Let's gather the basics" } ) ).toBeInTheDocument();
await userEvent.click( screen.getByRole( 'button', { name: 'Continue' } ) );
expect( updateWooPaymentsDispute ).toHaveBeenCalledWith(
	'dp_test',
	expect.objectContaining( { submit: false } )
);
expect( await screen.findByRole( 'heading', { name: 'Add your shipping details' } ) ).toBeInTheDocument();
await userEvent.click( screen.getByRole( 'button', { name: 'Continue' } ) );
expect( await screen.findByRole( 'heading', { name: 'Review your cover letter' } ) ).toBeInTheDocument();
expect( screen.getByRole( 'textbox', { name: 'Cover letter' } ) ).toHaveValue( expect.stringContaining( 'dp_test' ) );
```

Add a duplicate-dispute test proving shipping step is skipped and the review step appears after the basics step. Add a read-only test proving navigation remains available but save/submit/upload actions are not.

- [ ] **Step 2: Implement wizard shell.**

Keep the current `DisputeEvidenceForm` public API, but internally split rendering into basics, shipping, and review panes. Implement a simple Core-owned stepper/list rather than importing plugin stepper code. Requirements:

- Stable heading focus after step changes.
- `Back` and `Continue` buttons with native buttons.
- Autosave draft on forward step changes unless read-only, preserving upload-block behavior.
- Conditional shipping based on `needsShipping()`.
- Product type selection records `wcpay_dispute_product_selected`.
- Cover letter auto-generates while not manually edited, and saved `uncategorized_text` initializes as manually edited if it differs from generated text.
- The visible save/submit actions stay available from the review step.

- [ ] **Step 3: Run wizard tests GREEN.**

Run focused Jest and fix any a11y regressions from focus/live-region behavior.

## Task 4: Visa Compliance And Confirmation Screen

**Files:**
- Create: `plugins/woocommerce/client/admin/client/woopayments/admin/money-movement/dispute-evidence-confirmation.tsx`
- Modify: `plugins/woocommerce/client/admin/client/woopayments/admin/money-movement/dispute-evidence-form.tsx`
- Modify: `plugins/woocommerce/client/admin/client/woopayments/admin/money-movement/dispute-evidence-fields.ts`
- Modify: `plugins/woocommerce/client/admin/client/woopayments/admin/money-movement/dispute-evidence.scss`
- Test: `plugins/woocommerce/client/admin/client/woopayments/admin/test/dispute-challenge-page.test.tsx`

- [ ] **Step 1: Write RED confirmation and Visa tests.**

Add tests for final submit:

```tsx
window.confirm = jest.fn( () => true );
await userEvent.click( screen.getByRole( 'button', { name: 'Submit evidence' } ) );
expect( window.confirm ).toHaveBeenCalledWith( 'Are you sure you’re ready to submit this evidence? Evidence submissions are final.' );
expect( await screen.findByRole( 'heading', { name: 'Thanks for sharing your response!' } ) ).toBeInTheDocument();
expect( screen.getByRole( 'link', { name: 'Return to disputes' } ) ).toHaveAttribute( 'href', expect.stringContaining( 'path=%2Fwoopayments%2Fdisputes' ) );
```

Add a `reason: 'noncompliant'` or source-confirmed Visa compliance fixture asserting the special `Dispute information` path, `Tell us about the dispute`, and `Why do you disagree with this dispute?` textarea.

- [ ] **Step 2: Implement confirmation and Visa path.**

Create a confirmation component with the reference informational content and native route links. Use a local Core-owned success illustration only if an existing allowed asset is already available; otherwise render the confirmation without adding a decorative asset in this slice and record that visual asset as a follow-up. Use native route helpers for `/woopayments/disputes` and `/woopayments/disputes/challenge&id=...`.

Implement the Visa compliance path with the special heading/copy/textarea/20,000-character limit and ensure submit payload still allows the backend `enhanced_evidence.visa_compliance.fee_acknowledged` merge to run. Do not implement accept-dispute or refund modal flows here unless they are already part of the current native route; those belong to transaction-detail dispute panels.

- [ ] **Step 3: Run focused tests GREEN.**

Run:

```bash
pnpm --filter=@woocommerce/admin-library test:js --runTestsByPath client/woopayments/admin/test/dispute-challenge-page.test.tsx --runInBand
```

## Task 5: Harness, Browser, Reviews, And Commit

**Files:**
- Modify: `tools/woopayments-merge/a4-admin-surface-gate.py` only if needed.
- Add: `plugins/woocommerce/changelog/add-native-payments-a4y-dispute-challenge-parity`.
- Update: session analysis/log/staging files.

- [ ] **Step 1: Run focused frontend gates.**

Run:

```bash
pnpm --filter=@woocommerce/admin-library test:js --runTestsByPath client/woopayments/admin/test/dispute-challenge-page.test.tsx client/woopayments/admin/test/money-movement-pages.test.tsx client/woopayments/admin/test/money-movement-data.test.ts --runInBand
pnpm --filter=@woocommerce/admin-library ts:check
pnpm exec eslint client/woopayments/admin/money-movement/dispute-challenge-page.tsx client/woopayments/admin/money-movement/dispute-evidence-form.tsx client/woopayments/admin/money-movement/dispute-evidence-file-upload.tsx client/woopayments/admin/money-movement/dispute-evidence-fields.ts client/woopayments/admin/test/dispute-challenge-page.test.tsx --ext=js,ts,tsx --cache --cache-location=node_modules/.cache/eslint
pnpm --filter=@woocommerce/admin-library lint:lang:css -- client/woopayments/admin/money-movement/dispute-evidence.scss
```

- [ ] **Step 2: Run build/harness gates.**

Run:

```bash
pnpm --filter=@woocommerce/plugin-woocommerce build:admin
python3 tools/woopayments-merge/a4-admin-surface-gate.py --repo /Users/vladolaru/Work/a8c/woocommerce-develop-2 --plugin-repo /Users/vladolaru/Work/a8c/woocommerce-payments --out .agents/scratchpad/sessions/2026-06-15-core-native-payments/data/a4y-admin-surface-gate-green.json
```

Record bundle deltas honestly. Do not claim full A4 exit readiness from this slice.

- [ ] **Step 3: Run Playwriter browser proof.**

Use the target store at `http://store8889.localhost:8889` and, when possible, an actionable dispute fixture. Verify:

- Native challenge route loads from Settings > Payments provider route.
- Basics step, recommended documents, upload controls, conditional shipping, review cover letter, save draft, and submit confirmation render without layout overlap.
- No failed route requests.
- Console/log output has no fresh WooCommerce/WooPayments PHP notices or warnings. Known browser `unload` permission-policy noise can be recorded as broader environment noise.

If local data lacks an actionable dispute, create a local fixture through safe WP-CLI/test-lab/harness means or record the browser gap honestly and keep behavioral branches covered by component tests.

- [ ] **Step 4: Dispatch reviews and fix findings.**

Run at minimum:

- `a11y-reviewer` for wizard focus, step navigation, live announcements, file upload controls, and confirmation screen.
- `api-contract-reviewer` for evidence payload clearing, submit/draft, file upload/details routes, and Visa compliance metadata.
- `reference-integrity-reviewer` for reference copy, docs URLs, route links, and field labels/descriptions.

Verify each finding against source before acting. Fix real blockers and rerun relevant gates.

- [ ] **Step 5: Changelog, branch gates, and commit.**

Add changelog:

```text
Significance: patch
Type: add

Add native WooPayments dispute challenge parity.
```

Run:

```bash
pnpm --filter=@woocommerce/plugin-woocommerce changelog validate
git diff --check -- . ':!.agents'
pnpm --filter=@woocommerce/plugin-woocommerce lint:changes:branch
```

Commit source/tests and changelog as separate logical commits unless the final diff shows a stronger local convention reason to keep them together. Do not push. Update `staging-log.md`, `implementation-log.md`, and this analysis with commit hashes and final gate evidence.
