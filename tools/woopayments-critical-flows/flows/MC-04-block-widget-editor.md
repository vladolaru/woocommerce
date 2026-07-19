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

## Latest runner evidence (2026-07-19)

- Context `ad452df9-e7a6-41ae-96d1-202c13abae37` at source `8a4d612f69a6d14c090b7bde92a9773807be1196` was accepted by runner archive `evidence/runs/20260719T032524Z-73598-partial` with stamped result `sha256:db7819a484c49ee01ef7adc6a9537c252e0e81adb60894b0aa1b04d2a8727460`: reference `PASS`, target `FAIL - functional`, parity `FAIL - functional`.
- Reference inserted the public block through the normal page editor, persisted `flag: true` without recovery, published exactly one content-owned switcher, switched to EUR through GET navigation, kept EUR on the shop at the expected rounded `18,00 €`, and restored USD at `$20.00`.
- Target registers the same public server block name, public attribute-name set, and render callback, but its block type has no editor script handle. The identical inserter search reported `No results found.`; the fixture page remained an empty draft and no fallback block markup was injected.
- This is a new native gap relative to the WooPayments client, not one of the previously expected redirect, manual-capture, or wallet limitations. The matrix row remains `PENDING` because a callable server renderer does not satisfy the merchant-facing `Inserts + switches` contract.
- Re-earn requires Core to register a native editor implementation for `woocommerce-payments/multi-currency-switcher` that preserves the existing public name and serialized attributes, then complete this spec's insertion, preview, persistence, published switching, EUR navigation, and USD restoration journey under a fresh evidence context.
