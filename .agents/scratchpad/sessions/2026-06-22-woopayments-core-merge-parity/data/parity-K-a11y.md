---
session: 2026-06-22-woopayments-core-merge-parity
type: analysis
by: subagent:parity-K
created: 2026-06-22 22:55
target: React a11y
---

# Parity-K — React Admin Accessibility (REGRESSION vs PARITY)

Batch: 5 React admin a11y findings from the WooPayments → WooCommerce-core merge.

- **CORE**: `/Users/vladolaru/Work/a8c/woocommerce-develop-2` — React admin under `plugins/woocommerce/client/admin/client/woopayments/`
- **CLIENT (oracle)**: `/Users/vladolaru/Work/a8c/woocommerce-payments` (v10.8.0, develop) — React admin under `client/`

Note on cited CORE paths: findings 2 and 3 cited `.../money-movement/...` but the files actually live under `.../admin/money-movement/...`. Finding 4 cited `.../capital/page.tsx` but it lives under `.../admin/capital/page.tsx`. Verdicts use the real files.

---

## Finding 1 — 90565c18 (MED) — Bank-reference copy success announced twice

**CORE**: `plugins/woocommerce/client/admin/client/woopayments/admin/payout-details.tsx:266-271`

```tsx
setCopyStatusMessage( successMessage );   // -> feeds aria-live region (lines 289-291)
speak( successMessage, 'polite' );        // -> second announcement
```

`copyStatusMessage` flows into `liveStatusMessage` (lines 289-291) which is rendered in an `aria-live` region, AND `speak()` queues the same string on `wp.a11y` — so the success is announced twice.

**CLIENT equivalent**: `client/components/copy-button/index.tsx:25-50` (used by `client/deposits/details/index.tsx:275`)

```tsx
const copyToClipboard = () => {
    navigator.clipboard.writeText( textToCopy );
    setCopied( true );   // CSS animation only — no speak(), no aria-live
};
```

The client's `CopyButton` has **no SR announcement at all** on copy success — only a `state--copied` class for a CSS animation. There is no `speak()` and no `aria-live` region, so it cannot double-announce. The entire announcement mechanism (and therefore the double-announce bug) was introduced when the core port hand-rolled its own copy handler instead of reusing a shared button.

**Verdict: NEW-IN-CORE** (the announcement feature, and its double-fire defect, does not exist in the client). Confidence: **High**.

---

## Finding 2 — 106f2823 (MED) — Accept-dispute modal doesn't restore focus to trigger on dismiss

**CORE**: `plugins/woocommerce/client/admin/client/woopayments/admin/money-movement/transaction-dispute-details.tsx:286-289, 399-430`

```tsx
const closeAcceptModal = () => {
    shouldRestoreFocusAfterAcceptRef.current = false;
    setIsAcceptModalOpen( false );
};
...
{ isAcceptModalOpen && (
    <Modal title={ __( 'Accept the dispute?', 'woocommerce' ) }
           onRequestClose={ closeAcceptModal }> ... </Modal>
) }
```

The modal is `@wordpress/components` `Modal`. On plain dismiss (Cancel / Escape / overlay → `onRequestClose`), no custom focus code runs; WP `Modal` handles focus return itself. The extra custom machinery (`disputeHeadingRef`, `shouldFocusDisputeDetails`, useEffect lines 264-275, `shouldRestoreFocusAfterAcceptRef`) deliberately moves focus to the dispute **heading** (`<h3 tabIndex={-1}>`, lines 349-355) **only after a successful accept** — an intentional redirect because the trigger button is removed/replaced once the dispute is resolved.

**WP Modal verified**: `node_modules/.pnpm/@wordpress+components@29.12.../modal/index.tsx:102,285` calls `useFocusReturn()` unconditionally and applies `focusReturnRef`, so focus returns to the previously-focused element (the trigger) on unmount by default.

**CLIENT equivalent**: `client/payment-details/dispute-details/dispute-awaiting-response-details.tsx:232-238, 409-469`

```tsx
const handleModalClose = () => {
    if ( isDisputeAcceptRequestPending ) return;
    setModalOpen( false );      // no manual focus restoration
};
...
<Button data-testid="open-accept-dispute-modal-button"
        onClick={ () => { ...; setModalOpen( true ); } } />   // trigger, no ref
...
{ isModalOpen && (
    <Modal title={ disputeAcceptAction.modalTitle }
           onRequestClose={ handleModalClose }> ... </Modal>
) }
```

The client's trigger has no ref and `handleModalClose` does **only** `setModalOpen(false)`. The client relies entirely on WP `Modal`'s built-in `useFocusReturn` to restore focus to the trigger on dismiss — exactly the same mechanism the core port relies on for dismiss.

So on **dismiss**, both client and core restore focus to the trigger via WP Modal (correct in both). The finding's premise ("doesn't restore focus to the trigger on dismiss") does not hold against the WP Modal default; and where core adds custom behavior, it's a deliberate, accessible post-accept redirect to the resolved heading that the client does not implement.

**Verdict: PARITY** (focus-on-dismiss is identical — both inherit WP Modal's `useFocusReturn`; core additionally adds a correct post-accept focus redirect, a minor IMPROVED nuance). Confidence: **High** for parity-on-dismiss; **Medium** that the finding as written is a non-issue (depends on whether reviewer tested Cancel/Escape vs. the post-accept path).

---

## Finding 3 — 99309eb5 (LOW) — aria-label on a bare `<span>` (Dash placeholder)

**CORE**: `plugins/woocommerce/client/admin/client/woopayments/admin/money-movement/transaction-detail-sections.tsx:42-43`

```tsx
const Dash = () => (
    <span aria-label={ __( 'Unavailable', 'woocommerce' ) }>-</span>
);
```

`aria-label` on a non-interactive, non-landmark `<span>` is not reliably exposed by assistive tech (it's only guaranteed on interactive roles / landmarks / img). Used widely (lines 100, 125, 378-471, 733-826) as the missing-value placeholder.

**CLIENT equivalent**: none. Searched `client/payment-details/**` and all of `client/`:
- No `<span aria-label="Unavailable">` / `Unavailable` aria-label anywhere.
- No `Dash` component.
- The client renders missing values either as plain empty/`Not available` text (e.g. deposit bank-reference `Not available`, `client/deposits/details/index.tsx:287-290`) or via `Loadable` skeleton placeholders (`client/payment-details/summary/index.tsx`) — none use a bare-span `aria-label` dash.

The whole money-movement transaction-detail view is a hand-rolled core view, not a 1:1 port of `client/payment-details`. The `Dash` placeholder and its mis-applied `aria-label` originate in core.

**Verdict: NEW-IN-CORE**. Confidence: **High**.

---

## Finding 4 — bccc465d (LOW) — "No Capital loans found" rendered twice (visible dup + double SR announce)

**CORE**: `plugins/woocommerce/client/admin/client/woopayments/admin/capital/page.tsx:339-372`

```tsx
const statusMessage = ... ( loans.length === 0 && __( 'No Capital loans found.', ... ) ) ...;
...
<p role={ errorMessage ? 'alert' : 'status' } aria-live={ ... }>{ statusMessage }</p>   // SR announce #1
{ ! isLoading && ! errorMessage && loans.length === 0 && (
    <p className="...__empty">{ __( 'No Capital loans found.', 'woocommerce' ) }</p>     // visible dup
) }
```

The same string is both pushed into the `aria-live` status `<p>` (announced) **and** rendered again as a visible `<p>` — duplicate visible text plus a redundant SR announcement.

**CLIENT equivalent**: `client/capital/index.tsx:209-234`

```tsx
<TableCard
    title={ __( 'All loans', 'woocommerce-payments' ) }
    isLoading={ isLoading }
    totalRows={ loans.length }
    rows={ getRowsData( loans ) }
    ... />
```

The client has **no custom empty state** for the loans list and **no `aria-live` status region**. It defers entirely to `@woocommerce/components` `TableCard`'s built-in empty handling. There is no "No Capital loans found." string, no duplicate render, and no double announce. The status-region + separate visible empty `<p>` pattern is a core re-implementation.

**Verdict: NEW-IN-CORE**. Confidence: **High**.

---

## Finding 5 — 901cb561 (LOW) — fee/promotion tooltip sets aria-controls even when controlled element is unmounted (dangling IDREF)

**CORE**: `plugins/woocommerce/client/admin/client/woopayments/settings/payment-methods-list.tsx:542-564`

```tsx
<button ... aria-controls={ tooltipId }                    // always set
            aria-describedby={ isTooltipOpen ? tooltipId : undefined } >
  { feeDescription }
</button>
{ isTooltipOpen && (
    <span id={ tooltipId } role="tooltip" ...> ... </span>  // only mounted when open
) }
```

`aria-controls={tooltipId}` is set unconditionally, but the `id={tooltipId}` element only exists while `isTooltipOpen`. When closed, `aria-controls` points at a non-existent ID (dangling IDREF). (Note: `aria-describedby` is already correctly gated on `isTooltipOpen`.)

**CLIENT equivalent**: `client/settings/payment-methods-list/payment-method.tsx:217-241` → `HoverTooltip` from `client/components/tooltip/index.tsx`

```tsx
<HoverTooltip content={ formatMethodFeesTooltip( ... ) }>
    <Pill>...</Pill>
</HoverTooltip>
```

`HoverTooltip` (`tooltip/index.tsx:37-99`) wraps children in a `<button>` and renders content via `TooltipBase` gated on `isVisible`. Grep across `client/components/tooltip` and `client/settings/payment-methods-list` for `aria-controls` returns **zero matches** — the client never establishes an `aria-controls` IDREF binding at all, so a dangling IDREF is impossible. The `aria-controls={tooltipId}` pattern (and its dangling-reference defect) was introduced by the core port's hand-rolled `FeeDetails` tooltip.

**Verdict: NEW-IN-CORE**. Confidence: **High**.

---

## Summary

| id | classification | confidence | client_file:line | reason |
|----|----------------|-----------|-------------------|--------|
| 90565c18 | NEW-IN-CORE | High | `client/components/copy-button/index.tsx:31-34` | Client CopyButton has no SR announcement at all (CSS-only); double-announce mechanism is core-only |
| 106f2823 | PARITY | High (parity) / Med (finding validity) | `client/payment-details/dispute-details/dispute-awaiting-response-details.tsx:232-238` | Both rely on WP Modal `useFocusReturn` for focus-on-dismiss; core adds a correct post-accept redirect on top |
| 99309eb5 | NEW-IN-CORE | High | (none) | No Dash/`aria-label="Unavailable"` span exists anywhere in client; core hand-rolled placeholder |
| bccc465d | NEW-IN-CORE | High | `client/capital/index.tsx:221-232` | Client uses TableCard built-in empty handling; no custom empty `<p>` or aria-live status region |
| 901cb561 | NEW-IN-CORE | High | `client/components/tooltip/index.tsx:37-99` | Client tooltip never sets aria-controls (zero matches); dangling-IDREF binding is core-only |
