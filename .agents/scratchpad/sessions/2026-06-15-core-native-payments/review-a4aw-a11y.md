---
session: 2026-06-15-core-native-payments
type: review
by: codex
created: 2026-06-20 21:33
tool: pirategoat-tools:a11y-reviewer
target: A4aw VAT modal deep-link parity
reconciles:
  - analysis-a4aw-vat-modal-deep-link-parity.md
status: final
---

# A4aw Accessibility Review

> **Prompt:** "Read-only focused accessibility/UX review for A4aw VAT modal deep-link parity in /Users/vladolaru/Work/a8c/woocommerce-develop-2. Do not modify files, do not access any WPCOM sandbox, do not push/commit. Review the current uncommitted diff only for accessibility and user-facing behavior in plugins/woocommerce/client/admin/client/woopayments/settings/settings-page.tsx, plugins/woocommerce/client/admin/client/woopayments/admin/documents/vat-modal.tsx, vat-modal.scss, and related tests. Pay special attention to modal focus behavior, keyboard/cancel close path, screen-reader announcement of notices, Suspense fallback impact, visible styling after moving SCSS, and preserving existing Documents-page VAT modal behavior. Evidence available under .agents/scratchpad/sessions/2026-06-15-core-native-payments/data/a4aw-vat-modal-deep-link/. Write your report to .agents/scratchpad/sessions/2026-06-15-core-native-payments/review-a4aw-a11y.md with valid scratchpad frontmatter and no hard-wrapped prose. Final answer should list only critical/high/medium findings with file:line, or say none; include low-risk notes separately."

## Scope

I reviewed the current uncommitted frontend VAT modal deep-link changes in `plugins/woocommerce/client/admin/client/woopayments/settings/settings-page.tsx`, `plugins/woocommerce/client/admin/client/woopayments/admin/documents/vat-modal.tsx`, the new untracked `plugins/woocommerce/client/admin/client/woopayments/admin/documents/vat-modal.scss`, the SCSS move out of `plugins/woocommerce/client/admin/client/woopayments/admin/documents/style.scss`, and the related `plugins/woocommerce/client/admin/client/woopayments/settings/test/settings-page.test.tsx` coverage. I also read the local reference WooPayments settings manager and `@wordpress/components` Modal implementation only as context for focus and close-path behavior; I did not review or report outside the requested accessibility/user-facing surface.

## Findings

No critical, high, or medium accessibility/UX findings.

## Verification Notes

- Modal focus and close paths: `WooPaymentsSettingsPage` routes all close paths through `closeVatDetailsModal()` (`settings-page.tsx:2061`), and `WooPaymentsVatModal` passes that to `@wordpress/components` Modal via `onRequestClose` (`vat-modal.tsx:221`). The WordPress Modal implementation provides focus-on-mount, constrained tabbing, focus return, Escape close, and click-outside close in the shared component (`plugins/woocommerce/client/admin/node_modules/@wordpress/components/src/modal/index.tsx:98`, `:101`, `:102`, `:206`, `:250`, `:282`). Cancel also calls the same close handler (`vat-modal.tsx:252`, `:298`), so the query cleanup path is shared rather than split.
- Screen-reader announcements: the new notices use `dispatch( 'core/notices' ).createErrorNotice/createInfoNotice` (`settings-page.tsx:2018`, `:2028`, `:2048`, `:2075`). WooCommerce admin renders `core/notices` through `TransientNotices` and `Snackbar`, and `Snackbar` calls `speak()` for the spoken message (`layout/transient-notices/index.js:36`, `layout/transient-notices/snackbar/index.js:28`). Runtime evidence also shows the disabled-documents notice visible in `data/a4aw-vat-modal-deep-link/target-documents-disabled-notice.json` and `.png`.
- Styling after SCSS move: the modal styles removed from `admin/documents/style.scss` were moved into the new `admin/documents/vat-modal.scss` and imported directly by `vat-modal.tsx` (`vat-modal.tsx:25`). This preserves the Documents-page modal because Documents imports the modal component directly, and it gives the settings-page lazy chunk access to the same styles. Runtime evidence `target-modal-open.png` shows the modal content, actions, spacing, and minimum width are applied.
- Suspense fallback: `settings-page.tsx:2149` uses `fallback={ null }` while the VAT modal chunk loads. I am not reporting this as a medium issue because the supplied browser evidence shows the modal chunk loading and rendering correctly, and the modal itself handles focus once mounted. See the low-risk note below for a possible resilience improvement.
- Existing Documents-page behavior: the shared modal remains the same component used by `admin/documents/page.tsx:484`, and the focus change in `vat-modal.tsx:78` and `:93` reduces repeated focus stealing after the details step appears. The initial focus move into the first details field remains intentional when the first-step controls are replaced.

## Low-Risk Notes

- Consider adding focused Jest coverage for Escape and click-outside close on the deep-linked modal. Source shows the shared Modal routes those paths to `onRequestClose`, and Cancel/browser evidence covers query cleanup, so this is test-hardening rather than a current barrier.
- Consider replacing the null Suspense fallback with a small accessible loading affordance if this chunk is expected to load slowly on production connections. A polite status such as "Loading tax details..." would avoid a silent pending state before the dialog mounts, but the current evidence does not show a user-facing failure.
- The disabled-documents and already-submitted notice paths intentionally leave `woopayments-vat-details-modal=true` in the URL, matching the local reference settings manager behavior I inspected. If product wants single-shot notice URLs later, that would be a parity decision rather than an a11y bug in this slice.
