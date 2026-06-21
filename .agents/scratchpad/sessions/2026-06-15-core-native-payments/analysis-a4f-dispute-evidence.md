---
session: 2026-06-15-core-native-payments
type: analysis
by: codex
created: 2026-06-18 18:22
tool: subagent-driven-development
target: A4f native WooPayments dispute evidence submission
reconciles:
  - analysis-a4d-money-movement.md
  - analysis-a4e-settings-page.md
  - staging-log.md
status: draft
last_updated: 2026-06-18 19:27
---

# A4f Dispute Evidence Analysis

## Trigger

A4d intentionally left the native dispute challenge page fail-closed: the route loads and tells the merchant that dispute evidence submission is not available yet. The staging log records this as the remaining A4 admin parity blocker before native admin readiness can move toward the A4 exit gate. A4e preserved the shared file routes needed by WooPay logos and dispute evidence, so the next coherent A4 chunk is to replace the fail-closed challenge surface with reference-parity dispute evidence upload/submission, including backend route contracts, frontend form behavior, styling/assets, browser proof, and standing A4 gates.

## Working Constraints

- WPCOM remains read-only/off-limits; no WPCOM sandbox access, WPCOM commits, or WPCOM pushes.
- The reference WooPayments plugin at `/Users/vladolaru/Work/a8c/woocommerce-payments` is read-only.
- Scratchpad artifacts stay under `.agents/scratchpad/sessions/2026-06-15-core-native-payments`.
- The implementation should keep WooPayments-specific admin assets in split WooPayments chunks and preserve the generic Settings > Payments provider orchestration.
- The implementation must use TDD: RED tests before production changes, then focused and standing gates.

## Initial Disposition

The A4f scope should be larger than a narrow endpoint patch. Dispute evidence parity spans current native money-movement routes, the A4e file route work, a merchant-facing admin form, provider API calls, local logging/error handling, and browser/e2e verification. It should not flip native admin readiness by itself unless the A4 exit gate also passes.

## Source-Backed Reference Map

The reference WooPayments plugin registers the same dispute route family A4d added natively: list, summary, export, detail, update, and close under `/wc/v3/payments/disputes`, with evidence save/submit handled by `POST /wc/v3/payments/disputes/{dispute_id}` forwarding `dispute_id`, `evidence`, `submit`, and `metadata` into the API client. The plugin file controller registers `POST /wc/v3/payments/file`, `GET /wc/v3/payments/file/{file_id}/details`, `GET /wc/v3/payments/file/{file_id}/content`, and public `GET /wc/v3/payments/file/{file_id}`. A4e already preserved those file routes in Core.

The reference API client validates dispute IDs, pre-fetches the dispute, posts `{ evidence, submit, metadata }` to `disputes/{id}`, invalidates dispute caches, and enriches the returned dispute with local order/address context. For `reason=noncompliant`, it adds `evidence.enhanced_evidence.visa_compliance.fee_acknowledged = 'true'`. Core already mirrors this transport behavior in `WooPaymentsApiClient::update_dispute()` and adds native audit logging in `WooPaymentsDisputesRestController`.

The reference challenge UI is `client/disputes/new-evidence/index.tsx`, lazy-loaded under `/payments/disputes/challenge`. It initially fetches `/wc/v3/payments/disputes/{id}`, fetches file details for existing file evidence IDs, uploads files by posting `FormData(file, purpose=dispute_evidence)` to `/wc/v3/payments/file`, saves drafts with `submit=false`, submits final evidence with `submit=true` after a browser confirmation, and treats all statuses except `needs_response` and `warning_needs_response` as read-only.

The core evidence payload contract is field-name sensitive. Document fields are `receipt`, `customer_communication`, `customer_signature`, `refund_policy`, `duplicate_charge_documentation`, `cancellation_policy`, `cancellation_rebuttal`, `access_activity_log`, `service_documentation`, `shipping_documentation`, and `uncategorized_file`; text/shipping fields include `product_description`, `uncategorized_text`, `shipping_carrier`, `shipping_date`, `shipping_tracking_number`, `shipping_address`, and `customer_purchase_ip`. Document and shipping fields must be sent even when empty so removed evidence clears upstream; other empty fields are omitted. The UI persists product type in metadata as `__product_type`.

Reference upload UX accepts PDF/JPEG/PNG and enforces a total evidence-file limit of 4,500,000 bytes client-side. The newer agent-facing upload ability also validates PDF/JPEG/PNG and the same 4.5 MB decoded-byte limit, but that ability is separate from the merchant UI and should not be confused with the admin challenge route.

## Native Gap Map

Current Core already has native disputes REST routes, update/close forwarding, error status preservation, native audit logging, file upload/details/content/public routes, and the noncompliant Visa compliance payload injection. The missing A4f behavior is primarily frontend/admin workflow parity plus a few route/data hardening edges.

The current native `WooPaymentsDisputeChallengePage` loads the dispute and then intentionally renders a fail-closed notice. The admin data module has `getWooPaymentsDispute`, `updateWooPaymentsDispute`, and `closeWooPaymentsDispute`, but no file upload helper. The `WooPaymentsDispute` TypeScript type lacks many fields the evidence form needs: `evidence`, `evidence_details`, `metadata`, `enhanced_eligibility_types`, charge/order fields, due dates, file evidence IDs, and suggested product type.

Current dispute rows link to transaction details. A4f should expose the challenge route for actionable disputes (`needs_response` and `warning_needs_response`) without removing transaction detail links for non-actionable disputes. The route should remain under the native Settings Payments provider sub-route namespace, not a plugin-style `/payments/*` route.

Reusing the shared `/wc/v3/payments/file` route is acceptable for A4f because it is the reference route and A4e restored it specifically for surviving WooPay/dispute surfaces. The plan should still verify permission semantics and purpose handling so private dispute evidence is not exposed through the public file URL. Public file serving must remain limited by file purpose, as implemented in A4e.

## A4f Contract

A4f must preserve the native REST/file contracts already in place, remove the fail-closed challenge UI, and deliver a usable evidence workflow. Minimum contract:

- Load dispute details and render an evidence form for actionable disputes.
- Render read-only evidence/status for non-actionable disputes.
- Upload evidence files to `/wc/v3/payments/file` with `purpose=dispute_evidence`, PDF/JPEG/PNG accept filters, user-facing progress/error states, and the 4.5 MB aggregate cap.
- Fetch file details for already-saved evidence file IDs.
- Save drafts with `submit=false`; submit final evidence with `submit=true` after an irreversible-action confirmation.
- Assemble evidence with exact reference field names, always including empty document/shipping fields for clearing, and omit other empty fields.
- Persist `metadata.__product_type`.
- Preserve native audit logging, upstream error status/code propagation, and Visa compliance `enhanced_evidence` injection.
- Preserve Tracks continuity for save/submit/upload/product-selection events where feasible; any event names that remain unmapped must be explicitly dispositioned.
- Keep the WooPayments admin money-movement bundle split from generic Settings Payments bundles and do not load evidence UI assets unless the native WooPayments admin route is loaded.

## Implementation Plan

The A4f implementation plan is now recorded at `plans/2026-06-18-core-native-payments-a4f-dispute-evidence.md`. It splits the work into backend contract characterization, frontend data/types/evidence assembly, evidence form/upload UX, disputes list navigation/chunk boundaries, and browser/harness/review/commit gates. The intended implementation lane is frontend-heavy because source verification shows the native backend dispute/file contracts mostly exist already; backend changes should stay narrow and source-backed.

## Backend Contract Check

The local backend lane added a characterization regression for the native dispute update route forwarding draft evidence clearing fields unchanged. The new test covers `submit=false`, empty document/shipping clearing fields, non-empty product description, and `metadata.__product_type`. Focused verification passed: `php -l plugins/woocommerce/tests/php/src/Internal/Payments/Providers/WooPayments/WooPaymentsMoneyMovementRestControllerTest.php`, the single new PHPUnit test, the widened `WooPaymentsMoneyMovementRestControllerTest|WooPaymentsApiClientTest|WooPaymentsRestControllerTest` run with 147 tests and 752 assertions, and `pnpm --filter='@woocommerce/plugin-woocommerce' lint:php:changes`. No backend production change was needed.

## Verification Requirements

TDD should start with failing PHP tests for update payload/empty-field clearing/logging where backend behavior is touched and failing Jest tests for the challenge page replacing the fail-closed assertion. A4f needs focused PHP and JS gates, admin build/bundle proof, Playwriter browser proof on the target store, reference comparison where practical, restored `verify.sh`, the provider-created dispute gate, Docker/PHP log scans, and an honest note that full real provider evidence submission may be constrained by available local dispute fixtures. If deterministic provider-side final submission is not safe or available locally, the source/Jest/REST/browser proof must be explicit about what is and is not claimed.

## Implementation Closeout

A4f is implemented locally. Native now renders an actionable dispute evidence form under the Settings Payments provider sub-route, uses the shared native dispute/file routes, assembles source-backed evidence payloads, keeps existing provider evidence keys, clears document and shipping fields explicitly, persists `metadata.__product_type`, supports PDF/PNG/JPEG uploads through `purpose=dispute_evidence`, blocks save/submit during uploads, keeps non-actionable disputes read-only, and routes actionable dispute rows to the challenge page.

The main review-driven reliability fix is the saved-file hydration guard. The first implementation rendered fallback saved-file details immediately and hydrated metadata asynchronously, but a late hydration response could overwrite a merchant’s replacement upload in local form state. The form now tracks the previous `fileDetails` prop and only applies hydrated metadata when the current local file ID still matches the previous saved file ID. A regression replaces `file_receipt` with `file_replacement`, resolves old `file_receipt` metadata late, and proves the UI and draft payload stay on `file_replacement`.

The main review-driven a11y fix is the saved-file-detail warning announcement. The warning is now both visible and fed into `LiveStatusMessage` with assertive error semantics, so screen-reader users get the same state change. The focused Jest assertion requires both the live alert and visible status text.

The main review-driven operations fix is failure telemetry for saved-file detail hydration. Rejected file-detail requests now emit `wcpay_dispute_file_details_failed` with dispute ID, status, reason, evidence field, file ID, and error message. This is client-side Tracks visibility, not server-side log persistence.

## Verified Evidence

Focused JS passed with 3 suites and 22 tests for money-movement data, pages, and dispute challenge. Focused PHP passed with 147 tests and 752 assertions across `WooPaymentsMoneyMovementRestControllerTest|WooPaymentsApiClientTest|WooPaymentsRestControllerTest`. Focused ESLint, admin-library `ts:check`, targeted Stylelint, PHP syntax, `lint:php:changes`, admin build, branch `lint:changes:branch`, and `git diff --check` all passed. PHPStan on the touched PHPUnit test file still fails because isolated analysis does not know `WC_REST_Unit_Test_Case`, cascading into unknown `$this->server`, assertion methods, and anonymous logger signatures; no baseline was added.

Playwriter verified the rebuilt target route for dispute `du_1TjifiJd67Ti1EoIyvnBCdrw`: the form loaded, draft save returned 200 from `/wp-json/wc/v3/payments/disputes/{id}`, the visible success notice rendered, and the product description remained in the form. The only captured browser console error was Chrome’s permissions-policy `unload` warning. Target `debug.log` had no entries from the current browser pass.

The restored cross-store harness passed 7/7 with reference order 553 and target order 252. The restored dispute e2e gate passed with reference dispute order 554 and target dispute order 253, including provider-created dispute side-effect parity and widened Stripe raw-source reconciliation. A live file upload attempt still returns a local platform `wcpay_evidence_file_upload_error` on both target and reference stores, so A4f does not claim deterministic live provider file-upload success in this local TP state; the route contract and frontend upload success/error behavior are covered by source and Jest.

The admin bundle proof confirmed scoped CSS: `woocommerce-woopayments-dispute-evidence` selectors appear only in the money-movement CSS chunk (`7478.style.css`), not overview or payouts. The rebuilt money-movement JS measured `23169` raw / `6403` gzip bytes, and the money-movement CSS measured `6619` raw / `1242` gzip bytes.

A4f was committed as source/tests `24c75adaa1` and changelog `3391508a67`, with git range `c304f2003a...3391508a67`.
