---
session: 2026-06-15-core-native-payments
type: plan
by: codex
created: 2026-06-18 18:28
tool: writing-plans
target: A4f native WooPayments dispute evidence submission
reconciles:
  - ../analysis-a4f-dispute-evidence.md
  - ../analysis-a4d-money-movement.md
  - ../analysis-a4e-settings-page.md
status: draft
---

# A4f Native WooPayments Dispute Evidence Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use $subagent-driven-development (recommended) or $executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Replace the native WooPayments dispute challenge fail-closed page with a Core-owned evidence upload, draft-save, and submit workflow that preserves the active WooPayments merchant experience and REST/file contracts.

**Architecture:** Keep the existing native dispute and file REST controllers as the server boundary, then add a focused admin evidence module under the native WooPayments money-movement route. The frontend owns evidence field assembly, file-upload limits, read-only/actionable mode, Tracks continuity, and styling; backend work is limited to contract tests or small hardening found by those tests.

**Tech Stack:** WooCommerce Core PHP REST controllers/services, WooPayments API client, React, `@wordpress/data`/`@wordpress/components`, WooCommerce Admin route chunks, SCSS, PHPUnit, Jest/RTL, Playwriter, restored `tools/woopayments-merge` harness.

---

## Files And Responsibilities

- Backend verify/modify: `plugins/woocommerce/src/Internal/Payments/Providers/WooPayments/WooPaymentsDisputesRestController.php` owns native `/wc/v3/payments/disputes/{dispute_id}` update/close routes, audit logging, permission checks, and upstream error propagation.
- Backend verify/modify: `plugins/woocommerce/src/Internal/Payments/Providers/WooPayments/Api/WooPaymentsApiClient.php` owns dispute update transport, noncompliant Visa compliance evidence injection, file upload, file details, and file content transport.
- Backend tests: `plugins/woocommerce/tests/php/src/Internal/Payments/Providers/WooPayments/WooPaymentsMoneyMovementRestControllerTest.php`, `plugins/woocommerce/tests/php/src/Internal/Payments/Providers/WooPayments/Api/WooPaymentsApiClientTest.php`, and `plugins/woocommerce/tests/php/src/Internal/Admin/Settings/PaymentsProviders/WooPayments/WooPaymentsRestControllerTest.php` only if route/file behavior needs a missing regression.
- Frontend modify: `plugins/woocommerce/client/admin/client/woopayments/admin/money-movement/data.ts` adds native file upload/details helpers and keeps dispute update helpers on `/wc/v3/payments/disputes/{id}`.
- Frontend modify: `plugins/woocommerce/client/admin/client/woopayments/admin/money-movement/types.ts` grows the dispute, evidence, evidence-details, metadata, and file types used by the challenge page without leaking plugin-only types.
- Frontend create: `plugins/woocommerce/client/admin/client/woopayments/admin/money-movement/dispute-evidence-fields.ts` owns evidence field lists, product-type metadata key, actionable statuses, accepted mime types, aggregate file limit, field assembly helpers, and saved-file ID extraction.
- Frontend create: `plugins/woocommerce/client/admin/client/woopayments/admin/money-movement/dispute-evidence-file-upload.tsx` owns one evidence file picker row, accepted file validation, size-limit integration, upload progress/error states, existing file display, and remove/replace behavior.
- Frontend create/modify: `plugins/woocommerce/client/admin/client/woopayments/admin/money-movement/dispute-evidence-form.tsx` owns the merchant evidence form, save draft, submit confirmation, read-only mode, notices, and Tracks calls.
- Frontend modify: `plugins/woocommerce/client/admin/client/woopayments/admin/money-movement/dispute-challenge-page.tsx` becomes a thin route page that loads a dispute and renders the form instead of the fail-closed notice.
- Frontend modify: `plugins/woocommerce/client/admin/client/woopayments/admin/money-movement/disputes-page.tsx` exposes the challenge action for actionable disputes while preserving non-actionable transaction/detail navigation.
- Frontend modify: `plugins/woocommerce/client/admin/client/woopayments/admin/style.scss` adds evidence-form styling inside the existing native WooPayments admin chunk.
- Frontend tests: `plugins/woocommerce/client/admin/client/woopayments/admin/test/money-movement-data.test.ts`, `plugins/woocommerce/client/admin/client/woopayments/admin/test/dispute-challenge-page.test.tsx`, and `plugins/woocommerce/client/admin/client/woopayments/admin/test/money-movement-pages.test.tsx`.
- Changelog add: a WooCommerce Core changelog entry for dispute evidence submission parity.
- Scratchpad update: `analysis-a4f-dispute-evidence.md`, `implementation-log.md`, and `staging-log.md` with implementation decisions, gates, browser evidence, subagent review outcomes, and git range.

## Reference Contract To Preserve

- Active route family: `GET/POST /wc/v3/payments/disputes/{dispute_id}` for details/update, `POST /wc/v3/payments/file` for uploads, `GET /wc/v3/payments/file/{file_id}/details` for saved file names/sizes, and the A4e public/content file routes without broadening public access.
- Actionable statuses: `needs_response` and `warning_needs_response`. All other dispute statuses render read-only and must not show enabled save/submit/upload controls.
- Evidence document fields: `receipt`, `customer_communication`, `customer_signature`, `refund_policy`, `duplicate_charge_documentation`, `cancellation_policy`, `cancellation_rebuttal`, `access_activity_log`, `service_documentation`, `shipping_documentation`, and `uncategorized_file`.
- Evidence text/shipping fields: `product_description`, `uncategorized_text`, `shipping_carrier`, `shipping_date`, `shipping_tracking_number`, `shipping_address`, and `customer_purchase_ip`.
- Empty document and shipping fields must be sent as empty strings so removed evidence clears upstream. Other empty text fields are omitted.
- Product type persists in `metadata.__product_type`.
- Upload UX accepts PDF, PNG, and JPEG only, sends `purpose=dispute_evidence`, and enforces a 4,500,000 byte aggregate evidence-file limit in the merchant UI.
- Submit is irreversible from the merchant's perspective and must require confirmation; save draft must use `submit=false`, final submit must use `submit=true`.
- Tracks continuity should use reference event names where still applicable: `wcpay_dispute_save_evidence_clicked`, `wcpay_dispute_save_evidence_success`, `wcpay_dispute_save_evidence_failed`, `wcpay_dispute_submit_evidence_clicked`, `wcpay_dispute_submit_evidence_success`, `wcpay_dispute_submit_evidence_failed`, `wcpay_dispute_file_upload_started`, `wcpay_dispute_file_upload_success`, `wcpay_dispute_file_upload_failed`, and the product-selection event if source verification confirms its exact reference name.

## Task 1: Lock Backend Route And File Contracts

**Files:**
- Verify/modify: `plugins/woocommerce/src/Internal/Payments/Providers/WooPayments/WooPaymentsDisputesRestController.php`
- Verify/modify: `plugins/woocommerce/src/Internal/Payments/Providers/WooPayments/Api/WooPaymentsApiClient.php`
- Test: `plugins/woocommerce/tests/php/src/Internal/Payments/Providers/WooPayments/WooPaymentsMoneyMovementRestControllerTest.php`
- Test: `plugins/woocommerce/tests/php/src/Internal/Payments/Providers/WooPayments/Api/WooPaymentsApiClientTest.php`
- Test if needed: `plugins/woocommerce/tests/php/src/Internal/Admin/Settings/PaymentsProviders/WooPayments/WooPaymentsRestControllerTest.php`

- [ ] **Step 1: Add or confirm focused backend characterization coverage**

Add focused tests only for source-backed contract gaps. The minimum coverage target is: dispute update forwards `evidence`, `metadata`, and `submit` unchanged; `submit=false` and `submit=true` both reach the API client; `reason=noncompliant` still injects `evidence.enhanced_evidence.visa_compliance.fee_acknowledged = 'true'`; upstream `WooPaymentsApiException` status/code are preserved as `WP_Error`; file upload sends `file`, `file_name`, `file_type`, `purpose`, and `as_account`; file details are available through the A4e route with manager permissions.

Run: `pnpm --filter=@woocommerce/plugin-woocommerce test:php:env -- --filter 'WooPaymentsMoneyMovementRestControllerTest|WooPaymentsApiClientTest|WooPaymentsRestControllerTest'`

Expected: existing behavior may already pass because A4d/A4e implemented most of this boundary. If a characterization fails, keep the production change narrow and do not rewrite the route family.

- [ ] **Step 2: Patch only real backend gaps**

If the characterization reveals missing behavior, patch the exact owner: route forwarding/logging in `WooPaymentsDisputesRestController`, transport payload/enrichment in `WooPaymentsApiClient`, or route permissions/file argument handling in `WooPaymentsRestController`. Do not change WPCOM, the reference WooPayments plugin, or the local Transact Platform source. Do not add a backend file-size cap unless source verification proves the reference enforces one server-side for this route; the merchant UI cap belongs in the admin frontend.

- [ ] **Step 3: Prove backend green**

Run: `php -l` on every touched PHP production/test file.

Run: `pnpm --filter=@woocommerce/plugin-woocommerce test:php:env -- --filter 'WooPaymentsMoneyMovementRestControllerTest|WooPaymentsApiClientTest|WooPaymentsRestControllerTest'`

Run from `plugins/woocommerce` if production PHP changed: `composer exec -- phpstan analyse src/Internal/Payments/Providers/WooPayments/WooPaymentsDisputesRestController.php src/Internal/Payments/Providers/WooPayments/Api/WooPaymentsApiClient.php src/Internal/Admin/Settings/PaymentsProviders/WooPayments/WooPaymentsRestController.php --memory-limit=2G`

Expected: no new PHP notices/warnings, no PHPStan baseline growth, and no behavioral widening outside the dispute/file contract.

## Task 2: Frontend Data, Types, And Evidence Assembly

**Files:**
- Modify: `plugins/woocommerce/client/admin/client/woopayments/admin/money-movement/data.ts`
- Modify: `plugins/woocommerce/client/admin/client/woopayments/admin/money-movement/types.ts`
- Create: `plugins/woocommerce/client/admin/client/woopayments/admin/money-movement/dispute-evidence-fields.ts`
- Test: `plugins/woocommerce/client/admin/client/woopayments/admin/test/money-movement-data.test.ts`
- Test: `plugins/woocommerce/client/admin/client/woopayments/admin/test/dispute-challenge-page.test.tsx`

- [ ] **Step 1: Write failing Jest tests for data helpers and evidence assembly**

Extend `money-movement-data.test.ts` so `uploadWooPaymentsDisputeFile(formData)` posts to `/wc/v3/payments/file`, preserves `FormData`, and returns the uploaded file payload. Extend or add challenge-page tests for an exported assembly helper that receives draft form state and returns `{ evidence, metadata, submit }` with all document/shipping fields present even when empty, non-empty optional text fields included, empty optional text fields omitted, and `metadata.__product_type` preserved.

Run: `pnpm --filter=@woocommerce/plugin-woocommerce test:js -- --runTestsByPath plugins/woocommerce/client/admin/client/woopayments/admin/test/money-movement-data.test.ts plugins/woocommerce/client/admin/client/woopayments/admin/test/dispute-challenge-page.test.tsx`

Expected: fail because the upload helper and evidence assembly module do not exist yet.

- [ ] **Step 2: Add file upload/details helpers**

In `data.ts`, add `uploadWooPaymentsDisputeFile( body: FormData )` that uses the same request helper style as `getWooPaymentsDispute` and posts to `/wc/v3/payments/file`. Add `getWooPaymentsDisputeFileDetails( fileId: string )` if the challenge page needs saved file names/sizes and the helper is not already present. Keep these helpers native-admin scoped; do not create a global `wcpay/*` data alias.

- [ ] **Step 3: Expand typed dispute/file contracts**

In `types.ts`, add typed evidence, evidence details, metadata, and file shapes that are permissive enough for provider responses but explicit for the fields the native form reads. Include dispute fields used by the form: `id`, `amount`, `currency`, `reason`, `status`, `created`, `evidence_due_by`, `evidence`, `evidence_details`, `metadata`, `enhanced_eligibility_types`, `order`, `charge`, `payment_intent`, `transaction_id`, and customer/order display fields already present in A4d.

- [ ] **Step 4: Implement evidence constants and assembly helpers**

Create `dispute-evidence-fields.ts` with `ACTIONABLE_DISPUTE_STATUSES`, `DOCUMENT_EVIDENCE_FIELDS`, `SHIPPING_EVIDENCE_FIELDS`, `OPTIONAL_TEXT_EVIDENCE_FIELDS`, `PRODUCT_TYPE_METADATA_KEY`, `MAX_EVIDENCE_FILE_BYTES`, accepted mime/extension constants, `isDisputeActionable( dispute )`, `buildEvidencePayload( formState, submit )`, `extractSavedEvidenceFileIds( dispute )`, and `getEvidenceFileByteTotal( filesByField )`. Keep the helpers free of React so tests can assert the contract directly.

- [ ] **Step 5: Prove data/assembly green**

Run the focused Jest command from Step 1.

Run: `pnpm --filter=@woocommerce/plugin-woocommerce lint:js -- plugins/woocommerce/client/admin/client/woopayments/admin/money-movement/data.ts plugins/woocommerce/client/admin/client/woopayments/admin/money-movement/types.ts plugins/woocommerce/client/admin/client/woopayments/admin/money-movement/dispute-evidence-fields.ts`

Expected: data tests pass, evidence field names match the reference contract, and no plugin-runtime imports are introduced.

## Task 3: Evidence Form, Upload UX, And Challenge Route

**Files:**
- Create: `plugins/woocommerce/client/admin/client/woopayments/admin/money-movement/dispute-evidence-file-upload.tsx`
- Create: `plugins/woocommerce/client/admin/client/woopayments/admin/money-movement/dispute-evidence-form.tsx`
- Modify: `plugins/woocommerce/client/admin/client/woopayments/admin/money-movement/dispute-challenge-page.tsx`
- Modify: `plugins/woocommerce/client/admin/client/woopayments/admin/style.scss`
- Test: `plugins/woocommerce/client/admin/client/woopayments/admin/test/dispute-challenge-page.test.tsx`

- [ ] **Step 1: Replace the fail-closed test with RED workflow tests**

Rewrite the challenge-page test so it no longer expects the “not available yet” notice. Test these states: actionable dispute renders enabled product type, product description, additional evidence text, document upload controls, save draft, and submit controls; non-actionable dispute renders read-only status and disabled/absent upload/save/submit controls; saved file IDs trigger details fetch and render the returned file name; selecting a file above the aggregate limit shows an error and does not upload; save draft posts `submit=false`; final submit confirms and posts `submit=true`; failed update/upload surfaces an error notice.

Run: `pnpm --filter=@woocommerce/plugin-woocommerce test:js -- --runTestsByPath plugins/woocommerce/client/admin/client/woopayments/admin/test/dispute-challenge-page.test.tsx`

Expected: fail because the current page still renders the fail-closed notice.

- [ ] **Step 2: Build the file-upload row**

Implement `dispute-evidence-file-upload.tsx` using Core/WP components already available in the admin bundle. The component should show an existing saved file if present, accept `.pdf,image/png,image/jpeg` or equivalent mime filtering, validate type and aggregate byte limit before upload, call `uploadWooPaymentsDisputeFile` with `FormData(file, purpose=dispute_evidence)`, expose progress/error state, allow remove/replace while actionable, and be keyboard accessible with clear button labels.

- [ ] **Step 3: Build the evidence form**

Implement `dispute-evidence-form.tsx` with a compact merchant-facing layout: dispute summary, actionable/read-only status, product type select, product description textarea, additional evidence textarea, document uploads for the reference evidence fields, shipping fields when the dispute reason or evidence fields require shipping evidence, save draft, and submit. Use the assembly helpers from Task 2, dispatch Tracks events around save/submit/upload/product selection, and use `window.confirm` or the existing WooCommerce confirmation modal pattern for final submit.

- [ ] **Step 4: Wire the challenge route**

Update `dispute-challenge-page.tsx` to load the dispute by query param, render loading/error/empty states, fetch file details for saved evidence IDs through the form helpers, and render `DisputeEvidenceForm`. Keep the route under the native WooPayments admin namespace; do not introduce a plugin-style `/payments/disputes/challenge` route.

- [ ] **Step 5: Add scoped styling**

Add styles under the existing native WooPayments admin root in `style.scss` for the evidence summary, form sections, file rows, status badges, error/success notices, button row, and responsive layout. Keep the CSS in the existing WooPayments admin chunk, do not load dispute evidence styles on checkout or generic Settings Payments provider-list pages, and do not use one-off decorative palettes.

- [ ] **Step 6: Prove form green**

Run the focused challenge-page Jest command from Step 1.

Run direct ESLint for the touched TS/TSX files and Stylelint for `plugins/woocommerce/client/admin/client/woopayments/admin/style.scss` using the package’s existing commands or the same direct lint pattern used in A4c/A4e.

Expected: workflow tests pass, the fail-closed copy is gone, controls are accessible by role/name, and styling stays scoped to the WooPayments admin chunk.

## Task 4: Disputes List Navigation And Chunk Boundaries

**Files:**
- Modify: `plugins/woocommerce/client/admin/client/woopayments/admin/money-movement/disputes-page.tsx`
- Test: `plugins/woocommerce/client/admin/client/woopayments/admin/test/money-movement-pages.test.tsx`
- Build inspect: WooCommerce Admin emitted asset/chunk files after `build:admin`

- [ ] **Step 1: Write RED navigation tests**

Extend `money-movement-pages.test.tsx` so an actionable dispute exposes a challenge action linking to the native challenge route and a non-actionable dispute keeps the existing transaction/details action. Use accessible names such as “Challenge Fraudulent dispute dp_test” and “View transaction details for Fraudulent dispute dp_test” so the test protects screen-reader output.

Run: `pnpm --filter=@woocommerce/plugin-woocommerce test:js -- --runTestsByPath plugins/woocommerce/client/admin/client/woopayments/admin/test/money-movement-pages.test.tsx`

Expected: fail because actionable disputes currently point only at transaction details.

- [ ] **Step 2: Wire actionable disputes to challenge**

Use `isDisputeActionable()` from `dispute-evidence-fields.ts` to select the primary action. Link actionable disputes to the native `/woopayments/disputes/challenge` route with the dispute ID query parameter used by `dispute-challenge-page.tsx`. Preserve the transaction/detail link for non-actionable rows and avoid breaking A4d money-movement list sorting/filtering.

- [ ] **Step 3: Prove route and bundle boundaries**

Run the focused pages Jest command from Step 1 plus the challenge/data tests from Tasks 2 and 3.

Run: `pnpm --filter=@woocommerce/plugin-woocommerce build:admin`

Inspect emitted admin assets enough to confirm the evidence code stays in the native WooPayments admin/settings-embed chunk family and does not add checkout/frontend assets or generic Settings Payments base inflation. Record the asset observation in `analysis-a4f-dispute-evidence.md`.

Expected: route tests pass, build succeeds, and no WooPayments dispute evidence code is loaded outside the native admin route bundle family.

## Task 5: Browser, Harness, Review, And Commit

**Files:**
- Add: `plugins/woocommerce/changelog/*`
- Modify: `.agents/scratchpad/sessions/2026-06-15-core-native-payments/analysis-a4f-dispute-evidence.md`
- Modify: `.agents/scratchpad/sessions/2026-06-15-core-native-payments/implementation-log.md`
- Modify: `.agents/scratchpad/sessions/2026-06-15-core-native-payments/staging-log.md`

- [ ] **Step 1: Add changelog and run focused source gates**

Add a WooCommerce Core changelog entry for the native WooPayments dispute evidence admin workflow. Run all focused PHP/Jest gates from Tasks 1-4, direct ESLint/Stylelint for touched frontend files, `pnpm --filter=@woocommerce/plugin-woocommerce lint:php:changes`, `git diff --check`, and `git diff --cached --check` before commit. Do not run markdownlint on `.agents/scratchpad/sessions/2026-06-15-core-native-payments`.

- [ ] **Step 2: Run Playwriter browser proof on the target store**

Using Playwriter, open `http://store8889.localhost:8889/wp-admin/admin.php?page=wc-settings&tab=checkout&path=/woopayments/disputes` and the challenge route for an actionable dispute fixture. If no actionable dispute exists, create one through WCPay Dev Tools/Test Lab or the local harness without WPCOM changes. Verify that the list exposes challenge for actionable disputes, the challenge form loads, saved/uploaded file state is visible, a draft save sends `submit=false`, and final submit either succeeds safely against a disposable test dispute or is explicitly not run if the local fixture cannot be deterministically resubmitted. Capture screenshot/JSON evidence under `.agents/scratchpad/sessions/2026-06-15-core-native-payments/data/`.

- [ ] **Step 3: Compare the reference store where practical**

Open the reference store at `http://localhost:8082/wp-admin/admin.php?page=wc-admin&path=/payments/disputes/challenge&id={id}` or the reference route shape found by source verification. Compare actionable/read-only status, field names, upload affordances, confirmation behavior, and error states. If reference has no matching fixture, compare against source-backed reference behavior and record the limitation honestly.

- [ ] **Step 4: Run restored harness and log gates**

Run `tools/woopayments-merge/verify.sh` with the reference and target WP-CLI commands used in the latest staging log. Run the provider-created dispute e2e gate if present in `tools/woopayments-merge`. Scan target/reference Docker logs and WordPress debug logs for new PHP notices, warnings, deprecations, fatals, uncaught exceptions, stack traces, and database errors during the verification window. Treat new notices as blockers, not noise.

- [ ] **Step 5: Run review subagents**

Dispatch read-only reviewers after the implementation diff is present: API contract reviewer for dispute/file payload parity, frontend/accessibility reviewer for the evidence workflow, performance/bundle reviewer for admin chunk boundaries, and adversarial reliability reviewer for data-loss or money-safety risks around final submission. Fix source-backed findings before committing.

- [ ] **Step 6: Commit A4f and update session docs**

Commit source/tests and changelog as one logical A4f change unless the changelog needs a separate conventional commit to match local branch convention. Update `analysis-a4f-dispute-evidence.md`, `implementation-log.md`, and `staging-log.md` with the route decision, verification evidence, review findings, residual limits, commit hash, and git range. Do not push. Do not touch WPCOM.

---

## Self-Review

Spec coverage: The plan closes the A4d fail-closed dispute challenge blocker, reuses the A4e file routes, preserves the source-backed reference field/payload contract, keeps WooPayments admin code in native Core-owned route chunks, requires browser and harness proof, and preserves the user’s no-WPCOM and no-regression constraints. It does not claim A4 exit until the broader A4 stage gate passes.

Placeholder scan: No placeholder markers remain. The only conditional paths are explicit fail-closed implementation decisions for characterization tests and local provider fixture availability.

Type and path consistency: Frontend paths stay under `woopayments/admin/money-movement`, backend paths stay in the native WooPayments provider namespace, and route placement remains under native WooCommerce Settings > Payments provider routes.
