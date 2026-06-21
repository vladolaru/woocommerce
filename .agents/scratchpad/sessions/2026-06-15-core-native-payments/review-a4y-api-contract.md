---
session: 2026-06-15-core-native-payments
type: review
by: codex
created: 2026-06-19 19:32
tool: pirategoat-tools:api-contract-reviewer
target: A4y native WooPayments dispute challenge API/payload contract
reconciles:
  - analysis-a4y-dispute-challenge-parity.md
last_updated: 2026-06-19 19:37
status: final
---

# A4y API Contract Review

> **Prompt:** "You are reviewing an uncommitted WooCommerce Core A4y slice in /Users/vladolaru/Work/a8c/woocommerce-develop-2 on branch exp/core-native-payments. Read-only review only: do not edit files, do not access or modify WPCOM, do not push or commit.
>
> User constraints: WPCOM sandbox and WPCOM repo code changes are off limits. Local WooPayments client clone at /Users/vladolaru/Work/a8c/woocommerce-payments may be read-only reference source if needed. Scratchpad docs live under .agents/scratchpad/sessions/2026-06-15-core-native-payments.
>
> Scope to review:
> - plugins/woocommerce/client/admin/client/woopayments/admin/money-movement/dispute-evidence-fields.ts
> - plugins/woocommerce/client/admin/client/woopayments/admin/money-movement/dispute-evidence-cover-letter.ts
> - plugins/woocommerce/client/admin/client/woopayments/admin/money-movement/dispute-evidence-form.tsx
> - plugins/woocommerce/client/admin/client/woopayments/admin/money-movement/dispute-evidence-file-upload.tsx only as used by the form
> - plugins/woocommerce/client/admin/client/woopayments/admin/test/dispute-evidence-fields.test.ts
> - plugins/woocommerce/client/admin/client/woopayments/admin/test/dispute-challenge-page.test.tsx
>
> Review goal: API/payload contract review for the native WooPayments dispute challenge/new-evidence wizard. Check that buildEvidencePayload still preserves clearing behavior, metadata writes, evidence keys accepted by Stripe/WooPayments, submit vs draft payloads, upload field keys, duplicate/refund status behavior, Visa compliance payload behavior, Tracks event names/properties, and failure handling do not break existing native REST contracts or reference-compatible behavior. Pay special attention to the recent correction: there must be no unsupported `refund_receipt_documentation` evidence key in submitted payloads; duplicate/refund receipt branches should use `duplicate_charge_documentation`.
>
> Return findings first, ordered by severity. Include exact file:line references and source-backed reasoning. If no Critical/Important findings, say so and list residual runtime/browser risks. Do not claim gates passed unless you ran them."

## Method

- Bootstrap ran successfully with `api-contract-reviewer`; output target is `/tmp/api-contract-review.json` and `/tmp/api-contract-review.md`.
- Review is limited to the user-scoped A4y files and API/payload-contract behavior.
- Reference source may be read from `/Users/vladolaru/Work/a8c/woocommerce-payments` only.

## Running Notes

- Initial artifact created before presenting findings.

## Findings

### High: Non-shipping flows can resubmit stale shipping evidence

- File: `plugins/woocommerce/client/admin/client/woopayments/admin/money-movement/dispute-evidence-form.tsx:467`
- Category: `default-behavior-change`
- Confidence: 0.88

The new wizard computes shipping applicability with `needsShipping()` and hides/skips the shipping step for digital products and for duplicate, subscription-canceled, or credit-not-processed disputes, but `handleSave()` builds `nextEvidence` by spreading the current `evidence` state unchanged before calling `buildEvidencePayload()`. `buildEvidencePayload()` serializes every `SHIPPING_EVIDENCE_FIELDS` key, so any saved or previously-entered `shipping_carrier`, `shipping_date`, `shipping_tracking_number`, `shipping_address`, or `shipping_documentation` is sent back to `/wc/v3/payments/disputes/{id}` even when shipping no longer applies. The reference flow clears shipping state whenever `hasShipping` is false and sends empty shipping fields in the update payload, preserving the Stripe/WooPayments clearing contract. Existing platform consumers of the dispute update payload can therefore receive stale shipping evidence instead of the expected clears when a merchant switches away from a shipping-applicable product type or re-saves a non-shipping dispute that already has shipping evidence.

Source trail: native `handleSave()` spreads unchanged evidence at `dispute-evidence-form.tsx:467-479`; native `buildEvidencePayload()` always writes shipping fields at `dispute-evidence-fields.ts:376-378`; reference clears shipping state at `/Users/vladolaru/Work/a8c/woocommerce-payments/client/disputes/new-evidence/index.tsx:485-498` and sends empty shipping payload values at `index.tsx:587-602`.

Recommendation: before calling `buildEvidencePayload`, normalize evidence for `!includeShippingStep` by setting `shipping_documentation`, `shipping_carrier`, `shipping_date`, `shipping_tracking_number`, and `shipping_address` to empty strings, or pass shipping applicability into the payload builder and clear there. Add a regression test that enters/saves shipping evidence, changes to a non-shipping product type, and verifies the next draft/submit payload clears all shipping fields.

### Medium: Step autosaves emit save-success Tracks events that the reference suppresses

- File: `plugins/woocommerce/client/admin/client/woopayments/admin/money-movement/dispute-evidence-form.tsx:485`
- Category: `default-behavior-change`
- Confidence: 0.84

`handleContinue()` calls `handleSave(false, { notify: false, refreshDispute: false })` for step navigation, but `handleSave()` unconditionally records `wcpay_dispute_save_evidence_success` before checking `notify`. In the reference flow, step changes call `doSave(false, false)`, but the success event is recorded inside `handleSaveSuccess()`, which only runs when `notify` is true. Native therefore emits extra `wcpay_dispute_save_evidence_success` events during ordinary Continue navigation, inflating save-success counts for Tracks consumers even though the event name/properties otherwise match.

Source trail: native success event is unconditional at `dispute-evidence-form.tsx:485`; native step autosave passes `notify: false` at `dispute-evidence-form.tsx:534-537`; reference step autosave uses `doSave(false, false)` at `/Users/vladolaru/Work/a8c/woocommerce-payments/client/disputes/new-evidence/index.tsx:812-816`; reference success recording is gated by `if ( notify )` at `index.tsx:664-666`.

Recommendation: mirror the reference semantics: keep clicked/failed events for autosaves, but suppress `wcpay_dispute_save_evidence_success` when `notify` is false. Add a test around Continue/autosave that expects no save-success event.

## Verified Non-Findings

- No production native code path includes the unsupported `refund_receipt_documentation` evidence key. The source uses `duplicate_charge_documentation` for both duplicate and refund-receipt branches.
- `buildEvidencePayload()` still preserves metadata merging and writes `metadata.__product_type`.
- Native and reference backend update paths both accept the same top-level POST shape: `evidence`, `metadata`, and `submit`.
- Native `WooPaymentsApiClient::update_dispute()` preserves the server-side Visa compliance merge for `reason === 'noncompliant'` by adding `enhanced_evidence.visa_compliance.fee_acknowledged = 'true'`.

## Review Output

- `/tmp/api-contract-review.json`
- `/tmp/api-contract-review.md`

No tests or gates were run for this review.
