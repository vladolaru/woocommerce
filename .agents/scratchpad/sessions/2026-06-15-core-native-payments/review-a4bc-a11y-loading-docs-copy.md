---
session: 2026-06-15-core-native-payments
type: review
by: subagent:a11y-reviewer
created: 2026-06-21 02:02
target: A4bc settings loading/docs/copy accessibility review
status: final
---

# A4bc Settings Loading/Docs/Copy Accessibility Review

> **Prompt:** "Read-only review task for WooCommerce Core native WooPayments A4bc. Workspace: /Users/vladolaru/Work/a8c/woocommerce-develop-2, branch exp/core-native-payments. Do not access WPCOM, do not edit product code, do not use network. You may write one report only: .agents/scratchpad/sessions/2026-06-15-core-native-payments/review-a4bc-a11y-loading-docs-copy.md with frontmatter session: 2026-06-15-core-native-payments, type: review, by: subagent:a11y-reviewer, created: 2026-06-21 02:02, target: A4bc settings loading/docs/copy accessibility review, status: final. Review the current uncommitted diff in these files: plugins/woocommerce/client/admin/client/woopayments/settings/settings-page.tsx, style.scss, and test/settings-page.test.tsx. Focus on accessibility and frontend behavior: loading skeleton semantics/aria-hidden, removed page-level spinner, ExternalLink interpolation, React key warnings, keyboard/screen-reader impact of added links, and whether tests/browser proof cover the accessible behavior. Treat WP admin known JQMIGRATE and Chrome unload policy as known noise only; do not ignore source-backed warnings. Return findings ordered by severity with file/line refs, or explicitly say no blocking findings. Also mention any residual risk."

## Scope

Reviewed the current uncommitted diff only in:

- `plugins/woocommerce/client/admin/client/woopayments/settings/settings-page.tsx`
- `plugins/woocommerce/client/admin/client/woopayments/settings/style.scss`
- `plugins/woocommerce/client/admin/client/woopayments/settings/test/settings-page.test.tsx`

No WPCOM access, no network access, and no product code edits were performed.

## Findings

### Medium: Initial settings loading state is now visual-only for screen reader users

Location: `plugins/woocommerce/client/admin/client/woopayments/settings/settings-page.tsx:2505`

Problem: The loading branch now renders `<SettingsLoadingSections />` after removing the previous `aria-live="polite"` loading message. The new skeleton placeholders are correctly hidden from assistive technology at `settings-page.tsx:457-465`, but there is no replacement `role="status"`, `aria-live`, or `aria-busy` signal on the loading branch. A screen reader user can encounter the page heading, section headings, docs links, and empty control cards without any announcement that the actual settings controls are still loading. Sighted users get animated skeletons; non-visual users get no equivalent loading state.

WCAG: 4.1.3 Status Messages.

Anti-pattern: AP-06, dynamic/loading state not announced.

Fix: Keep the skeletons `aria-hidden`, but add a screen-reader-only status and busy state to the loading branch. For example, render a `role="status" aria-live="polite"` message such as "Loading WooPayments settings..." alongside `<SettingsLoadingSections />`, and put `aria-busy="true"` on the loading content wrapper until settings are available. The existing `SettingsBusyState` component shows the local pattern for this at `plugins/woocommerce/client/admin/client/woopayments/settings/settings-busy-state.tsx:20-30`.

Test coverage to add: Extend the new loading test at `settings-page.test.tsx:602-646` to assert the loading state is exposed with `getByRole( 'status' )` and/or `aria-busy="true"`. The current test explicitly asserts the old loading text is absent and only checks hidden placeholder DOM, so it would not catch this regression.

Effort: 0.5-1 hour.

Confidence: 0.88.

## No Blocking Findings

No P0/high accessibility blockers were found in the reviewed diff.

## Positive Checks

- The skeleton spans are non-interactive and hidden from assistive technology with `aria-hidden="true"`; I did not find an `aria-hidden` focusable descendant issue.
- The skeleton animation in `style.scss:139` has a `prefers-reduced-motion: reduce` fallback at `style.scss:158-160`.
- The new documentation links are semantic `ExternalLink` anchors with visible text. The added test verifies the interpolated manual-capture and In-Person Payments links retain their rendered text and expected hrefs at `settings-page.test.tsx:681-698`.
- `Children.toArray()` around the interpolated manual-capture help links should address React key stability without changing the accessible link text.
- I did not find a newly introduced keyboard trap or focus-return issue in the changed hunks. The nearby manual-capture modal conditional render is existing context rather than a new close-path change.

## Residual Risk

This was a source review plus local diff checks. I did not run the Jest suite or browser/Playwright proof because the task was constrained to read-only review work with one report file. The added unit tests cover DOM presence, hidden skeleton placeholders, and link href/text, but they do not prove real browser focus order, screen reader announcement behavior, or visual contrast. `git diff --check` produced no whitespace warnings for the three reviewed files.
