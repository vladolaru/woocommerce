---
session: 2026-06-15-core-native-payments
type: analysis
by: codex
created: 2026-06-19 14:12
status: draft
last_updated: 2026-06-19 14:12
---

# A4v Admin Surface Selection

## Trigger

The current reopened A4 backlog after A4u needs the next coherent native admin parity slice. The latest guidance adds that DataViews may be used for tables when it naturally fits, but should not be forced to mimic the reference visually.

## Working Questions

1. Which remaining A4 surface is source-backed as missing or incomplete in native after A4u?
2. Is the surface bounded enough for one high-throughput slice without mixing unrelated parity work?
3. Does the surface naturally use a tabular/list component where DataViews is appropriate?
4. What reference contracts, REST endpoints, route architecture, feature gates, and browser checks need to be carried into the JIT plan?

## Initial Evidence

- `staging-log.md` records A4u as closed and leaves Reports/Documents native UI/API, richer details, broader copy/content parity, final A4 gate, and A5 readiness open.
- The user wants native provider settings/routes to live under Core Settings > Payments orchestration, with WooPayments-specific sub-routes owned by core rather than plugin-era top-level routes.
- DataViews is acceptable for table mechanics when it fits the surface, but visual parity should not be reduced to forcing DataViews to look exactly like the reference.

## Source Findings

- Native route registration in `plugins/woocommerce/client/admin/client/woopayments/admin/routes.tsx` has no Documents or Reports route after A4u.
- Native admin navigation deliberately keeps Reports and Documents absent: `WooPaymentsAdminNavigationController` says those legacy routes are intentionally absent until native UI/API surfaces are ported, and `WooPaymentsAdminNavigationControllerTest::test_reports_and_documents_menu_items_remain_absent_until_surfaces_are_ported()` asserts that state.
- Reference Documents is enabled only when cached account data has `is_documents_enabled`; the reference account status also exposes `hasSubmittedVatData` and `isDocumentsEnabled`.
- Reference Documents route/menu path is `/payments/documents`; native should expose it as `/woopayments/documents` under Core Settings > Payments and add `/payments/documents` as a compatibility redirect only once the native surface exists.
- Reference Documents frontend is bounded: `client/documents/index.tsx` renders test-mode notice, `DocumentsList`, error-boundary-wrapped spotlight; `client/documents/list/index.tsx` renders a table with Date, Type, Description, Download, filters, summary count, VAT modal-gated downloads, and query-triggered direct download support.
- Reference Documents data calls `/wc/v3/payments/documents`, `/wc/v3/payments/documents/summary`, and `/wc/v3/payments/documents/{document_id}`. List params preserve `page`, `pagesize`, `sort`, `direction`, `match`, date filters, and type filters. The preserved extension hook is `wcpay_list_documents_request`.
- Reference document downloads record `wcpay_document_downloaded` with document ID and test/live mode, forward raw document response headers/body, and validate the document ID with `^[\\w-]+$`.
- Reference Documents downloads are not standalone: VAT invoices open a VAT-details modal when `hasSubmittedVatData` is false. That modal uses `/wc/v3/payments/vat/{vat_number}` for validation, `/wc/v3/payments/vat` for saving, hooks `wcpay_validate_vat_request`, and refreshes account data after saving.
- Native has no exact `is_documents_enabled`, `has_submitted_vat_data`, Documents REST, VAT REST, or VAT frontend support today. Those are functional parity gaps, not cosmetic gaps.
- Reports is materially larger than Documents: reference `client/reports` contains Balance and Fees report modules, custom date filtering, export/download flows, a report state layer, and existing reference DataViews usage. Combining Reports with Documents would make A4v too broad and risk leaving both half-done.

## DataViews Decision

Documents is naturally tabular and should use a native DataViews-backed table if it can preserve the route/query behavior, summary/count, field visibility, loading/empty states, and accessible Download buttons. This is acceptable even if it visually departs from the reference TableCard, as long as the merchant-facing behavior and copy are preserved. The VAT modal should use normal WordPress components and semantic form controls; there is no need to revive the plugin wizard abstraction if a small native modal preserves the functional contract cleanly.

## Recommended A4v Slice

A4v should port the native Documents surface only: account/nav gating, `/woopayments/documents` route, `/payments/documents` compatibility redirect, Documents list/summary/download REST API, VAT validate/save REST dependency, DataViews-backed Documents list UI, VAT modal, spotlight mount, test-mode notice, focused unit tests, A4 admin harness widening, Playwriter comparison, logs, changelog, and commit. Reports should remain the next heavier A4 slice.

## Current Disposition

Proceed with an A4v Documents parity JIT plan unless a sidecar explorer returns a source-backed blocker.
