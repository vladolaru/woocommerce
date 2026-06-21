---
session: 2026-06-15-core-native-payments
type: analysis
by: codex
created: 2026-06-19 18:48
target: A4y native WooPayments dispute challenge parity
reconciles:
  - staging-log.md
  - supervisor-prompt-2026-06-18-2344-N12.md
  - analysis-a4x-money-detail-parity.md
status: draft
last_updated: 2026-06-19 18:55
---

# A4y Dispute Challenge Parity Source Map

> **Prompt:** "Continue working toward the active thread goal."

## Starting Point

A4x closed payout details and the payment/transaction detail foundation, but explicitly left the larger dispute challenge/new-evidence wizard as reopened-A4 follow-up work. N12 makes native admin functional, visual, and copy parity an A4 exit requirement and A5 cutover precondition. Since dispute details in the reference redirects into payment details, the remaining adjacent money-detail gap is the `/woopayments/disputes/challenge` challenge-evidence flow.

## Reference Surface

The reference route is registered from `/Users/vladolaru/Work/a8c/woocommerce-payments/client/index.js` as `/payments/disputes/challenge`, lazy-loading `wcpay/disputes/new-evidence` in the shared money-movement chunk. The reference dispute details route at `/payments/disputes/details` lazy-loads `disputes/redirect-to-transaction-details`, which fetches a dispute and redirects to `/payments/transactions/details` with the PaymentIntent, balance transaction, and `type=dispute`; it is not a standalone dispute detail page.

The reference challenge composition root is `/Users/vladolaru/Work/a8c/woocommerce-payments/client/disputes/new-evidence/index.tsx`. It renders `TestModeNotice`, a `Page`, `DisputeNotice`, `DisputeDueByDate`, summary/meta rows, a `StepperPanel`, `Accordion`, reason/product-type-specific product details, optional shipping details, recommended documents, auto-generated cover letter, and a confirmation screen after submit. It uses `apiFetch` for `/wc/v3/payments/disputes/{id}` and file details, `useDisputeEvidence().updateDispute`, `useGetSettings()`, payment-intent invalidation, and Tracks events for product selection, file upload, save, submit, success, and failure.

The reference wizard steps are source-defined in `new-evidence/index.tsx`: `Let's gather the basics`, optional `Add your shipping details`, and `Review your cover letter`. Visa compliance disputes with the additional evidence feature flag collapse to a special `Dispute information` panel and force the cover letter to be manually managed. Shipping step inclusion is controlled by `needsShipping()` from `new-evidence/shipping-utils.ts` using dispute reason and product type.

The reference recommended document system is data-driven. `new-evidence/evidence-matrix.ts`, `recommended-document-fields.ts`, `document-field-keys.ts`, and `recommended-documents.tsx` map dispute reason, product type, refund status, duplicate status, enhanced eligibility, and shipping needs into ordered document fields. The supported evidence file fields include receipt, customer communication, customer signature, refund policy, duplicate charge documentation, shipping documentation, service documentation, cancellation policy, cancellation rebuttal, access activity log, uncategorized file, and refund receipt documentation in the matrix. The UI copy explicitly says recommended documents are optional but strongly recommended and links to the WooPayments dispute docs.

The reference file upload control is `new-evidence/file-upload-control.tsx`. It uses `FormFileUpload`, cloud-upload and close icons, file chips with formatted filename/size, accepts PDF/PNG/JPEG, has separate mobile chip placement via `useViewport()`, and labels the icon actions with accessible names. `new-evidence/index.tsx` tracks per-field upload busy state, file sizes, uploaded filenames, and prevents save/submit while uploads are active.

The reference cover-letter path is central, not optional garnish. `cover-letter-generator.ts`, `cover-letter.tsx`, `customer-details.tsx`, `product-details.tsx`, `shipping-details.tsx`, `refund-status.tsx`, and `duplicate-status.tsx` gather merchant, customer, product, refund, duplicate, and shipping facts to generate `uncategorized_text`. The generated letter is auto-updated while not manually edited; saved `uncategorized_text` is preserved and compared against the generated content to decide whether it was manually edited.

The reference submit path shows `confirmation-screen.tsx` after successful submission. It includes the success illustration `assets/images/dispute-evidence-submitted.svg`, `Thanks for sharing your response!`, `What’s next?`, useful resources, an informational notice that the bank/Visa determines the outcome, and `Return to disputes` plus `View submitted dispute` actions. Links target the plugin-era routes in the reference and must be adapted to the native `/woopayments/*` provider sub-routes.

## Native Surface

Native route registration lives in `plugins/woocommerce/client/admin/client/woopayments/admin/routes.tsx` with `/woopayments/disputes/challenge` lazy-loading `./money-movement/dispute-challenge` into the `settings-payments-woopayments-money-movement` chunk. `dispute-challenge.tsx` mounts `WooPaymentsDisputeChallengePage`.

Native `dispute-challenge-page.tsx` fetches `getWooPaymentsDispute(id)`, extracts saved evidence file IDs, loads file details via `getWooPaymentsDisputeFileDetails`, and renders one heading, a live status message, loading/error messages, and `DisputeEvidenceForm`. It already handles missing IDs, file-detail failures, and live-region announcement through `LiveStatusMessage`.

Native `dispute-evidence-form.tsx` is functional but much flatter than the reference. It renders a summary `dl`, optional read-only notice, one focusable notice, a Product details fieldset, a Shipping details fieldset, a Documents fieldset with all document uploads, and Save draft / Submit evidence buttons. It supports upload blocking, aggregate 4.5 MB file limit through `dispute-evidence-file-upload.tsx`, draft/submit calls via `updateWooPaymentsDispute`, evidence clearing through `buildEvidencePayload`, native Tracks events, and a browser confirmation before final submit.

Native `dispute-evidence-fields.ts` currently defines document fields, shipping fields, optional text fields, actionability statuses, accepted file types, the product-type metadata key, evidence payload construction, saved-file extraction, byte totals, file-name resolution, and accepted-file checks. It does not implement the reference reason/product-type matrix, refund/duplicate branching, recommended-document ordering/descriptions, shipping-needed predicate, auto-generated cover letter, confirmation screen, or the reference stepper/accordion composition.

Native backend support already exists for the major transport seams. `WooPaymentsDisputesRestController::update_dispute()` accepts evidence, submit, and metadata and forwards through `WooPaymentsApiClient::update_dispute()`. `WooPaymentsApiClient::update_dispute()` preserves `enhanced_evidence` metadata merging and provider requests. File upload helpers and dispute file detail routes are already used by native tests. The likely A4y slice is therefore primarily frontend parity, with backend work only if source verification finds a missing field/route contract.

## A4y Boundary Proposal

The next coherent slice should be A4y Native Dispute Challenge Wizard Parity. It should not rework the already-closed dispute list or transaction detail pages. It should replace the reduced native evidence form with a reference-shaped wizard while reusing native data helpers, routes, and upload/update REST contracts.

The slice should implement the following minimum parity set: reference stepper headings and optional shipping step; dispute summary/due-date/test-mode/notices; reason/product-type-driven recommended document fields with descriptions and docs link; file upload chip styling and busy states; product/customer/refund/duplicate/shipping inputs needed to build the cover letter; generated cover letter with manual-edit preservation; save draft and final submit paths preserving evidence-clearing semantics and Tracks events; post-submit confirmation screen with native-route links; read-only rendering for non-actionable disputes; and scoped SCSS in the existing WooPayments money-movement chunk.

The slice should deliberately adapt plugin-era route links and text domains to Core/native context, keep WPCOM off limits, avoid Stripe Billing, and keep all WooPayments-specific assets/styles in the existing lazy money-movement/admin bundle. If a reference dependency is too plugin-specific to hoist cleanly, port the behavior and copy into Core-owned components with clear file boundaries rather than flattening it into the old form.

## Initial File Map

- Native modify: `plugins/woocommerce/client/admin/client/woopayments/admin/money-movement/dispute-challenge-page.tsx`.
- Native modify or replace: `plugins/woocommerce/client/admin/client/woopayments/admin/money-movement/dispute-evidence-form.tsx`.
- Native extend: `plugins/woocommerce/client/admin/client/woopayments/admin/money-movement/dispute-evidence-fields.ts`.
- Native extend: `plugins/woocommerce/client/admin/client/woopayments/admin/money-movement/dispute-evidence-file-upload.tsx`.
- Native extend: `plugins/woocommerce/client/admin/client/woopayments/admin/money-movement/dispute-evidence.scss`.
- Native possibly create: `dispute-evidence-wizard.tsx`, `dispute-evidence-matrix.ts`, `dispute-evidence-cover-letter.ts`, `dispute-evidence-confirmation.tsx`, `dispute-evidence-recommended-documents.tsx`, `dispute-evidence-shipping.tsx`, and focused helpers under the same `money-movement` directory.
- Native tests: `plugins/woocommerce/client/admin/client/woopayments/admin/test/dispute-challenge-page.test.tsx` plus helper tests if matrix/cover-letter utilities are split.
- Harness: widen `tools/woopayments-merge/a4-admin-surface-gate.py` only for source/route/static-token assertions if the current gate lacks dispute-challenge coverage.

## Open Inputs

Galileo the 4th completed the reference source map and confirmed the route/data/wizard contracts. Reference `/payments/disputes/details` is redirect-only through `RedirectToTransactionDetails`; reference `/payments/disputes/challenge` lazy-loads `DisputeNewEvidencePage`; reference REST includes disputes read/update/close and files upload/details/content; and the API client injects `enhanced_evidence.visa_compliance.fee_acknowledged = 'true'` for `reason === 'noncompliant'`. Native already preserves an `enhanced_evidence` merge path in `WooPaymentsApiClient::update_dispute()`, so A4y should not remove that backend guard.

Galileo also surfaced reference behaviors that must be in A4y rather than deferred accidentally: step changes autosave a draft unless read-only; save/submit is blocked while uploads are active with the reference copy `Please wait until file upload is finished`; final submit confirmation copy is `Are you sure you’re ready to submit this evidence? Evidence submissions are final.`; success notices are `Evidence saved!` and `Evidence submitted!`; and the Visa compliance path uses `Tell us about the dispute`, `Why do you disagree with this dispute?`, a 20,000-character cap, `Dispute information` heading, and the special fee-acknowledgement backend metadata. These copy details should be deliberately preserved or explicitly adapted to native text-domain/Core route context.

Dalton the 4th completed the native source map. Native disputes list/summary/export/detail/update/close/file upload/file details are present; challenge rows already deep-link actionable disputes to `/woopayments/disputes/challenge?id=...`; `WooPaymentsDisputeDetailsRedirect` is correctly redirect-only; `WooPaymentsDisputeChallengePage` and `DisputeEvidenceForm` are the primary frontend touch points; and backend changes should be limited unless the richer wizard proves it needs more data from `WooPaymentsMoneyMovementOrderService::enrich_dispute_response()`. Existing native tests already cover draft save, final submit, read-only disputes, file details, accepted upload types, aggregate file size, evidence clearing, upload blocking, and Tracks events.

The A4y plan should therefore keep the native route/REST/data foundation and port the reference wizard behavior around it. The implementation should introduce focused helper/components beside the current `money-movement` files rather than turning `dispute-evidence-form.tsx` into a single very large component.

## Current Implementation Slice Status

As of 2026-06-19 18:55 EEST, A4y is the active reopened-A4/N12 implementation slice and has not yet landed product code in the main workspace. The plan and source map are complete enough to proceed, and the next implementation work should continue from helper/model parity into the wizard UI without restarting source analysis.

Newton the 4th (`019ee096-33d4-7222-8ded-1cb4247e9628`) is still running as a bounded helper/model worker. That worker owns `plugins/woocommerce/client/admin/client/woopayments/admin/money-movement/dispute-evidence-fields.ts`, optional `plugins/woocommerce/client/admin/client/woopayments/admin/money-movement/dispute-evidence-cover-letter.ts`, and optional `plugins/woocommerce/client/admin/client/woopayments/admin/test/dispute-evidence-fields.test.ts`. A 10-second `wait_agent` poll timed out without a final result, so the next resume must poll that worker before modifying the same helper files or decide explicitly to abandon and reimplement the worker scope locally.

The active task order is: integrate helper/model parity; run `pnpm --filter=@woocommerce/admin-library test:js --runTestsByPath client/woopayments/admin/test/dispute-evidence-fields.test.ts --runInBand`; implement recommended document and upload presentation; implement multi-step wizard/autosave/conditional shipping/cover-letter behavior; implement confirmation and Visa compliance path; widen the A4 admin surface harness for honest dispute-challenge wizard coverage; then run focused JS/TypeScript/ESLint/Stylelint/build/harness/Playwriter/review/changelog/branch gates before committing. Keep native admin readiness fail-closed throughout.
