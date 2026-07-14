# MC-04 — Block widget (editor) · AGENT

Guards the block-based currency switcher: the WooPayments Currency Switcher block must be insertable in the block editor, render a live preview, and actually switch the storefront currency when published. Native must keep the block registered and functional — a block missing from the inserter, erroring in the editor ("block contains unexpected content"), or rendering a dead switcher on the frontend is a regression. Converted checkout totals downstream are owned by MCS-01/MCS-02 (corroborated by the implementor's `converted-currency-gate.sh`); this row owns editor insertion + frontend switching.

## Fixtures (both stores)

- Connected test account; default currency `USD`; `GBP` and `EUR` enabled with automatic rates cached, aligned across stores per HARNESS.md store-config discipline.
- A block theme or block-widget-capable area; a draft page (or widget area) to insert into; a simple in-stock product with a known USD price.
- Admin user with editing rights on the target page/widget area.

## Layer A — agent-driven browser

BOTH stores:

1. Edit a page (or Appearance → Editor / block widgets). Open the inserter and search "currency switcher". **Confirm the WooPayments Currency Switcher block is listed.**
2. Insert it. **Confirm it renders a valid preview in the editor** — no block error, no crash, settings panel (title/flag/symbol toggles) usable.
3. Toggle a block setting (e.g. show currency symbol), publish/update, then reopen the editor. **Confirm the block reloads without an "unexpected content" error and the setting persisted.**
4. Visit the published page on the storefront. **Confirm the switcher block renders** with USD, GBP, and EUR choices.
5. Switch to `EUR`. **Confirm storefront prices re-render in EUR** (€ symbol, amount consistent with the cached rate).
6. Navigate to the shop page. **Confirm the EUR selection persists across pages.**
7. Switch back to `USD`. **Confirm prices return to the original USD values.**

End state: block inserted and published on both stores; frontend switching works; storefront back on USD.

Agent oracle mode: comparable (dual-store; reference is the golden oracle).
