# MC-03 — Add switcher widget · AGENT

Guards the classic currency-switcher widget: the merchant must be able to add the WooPayments currency switcher from the widgets admin, and a shopper using it must see storefront prices re-render in the chosen currency. Native must keep the widget registered, configurable, and functional — a switcher that renders but does not actually change displayed prices (or silently disappears from the widget catalog) is a regression. Converted totals downstream of the switch are asserted by MCS-01/MCS-02 and corroborated by the implementor's `converted-currency-gate.sh`; this row owns widget placement + switching UX.

## Fixtures (both stores)

- Connected test account; default currency `USD`; `GBP` and `EUR` enabled with automatic rates cached, aligned across stores per HARNESS.md store-config discipline.
- A theme with a widget-capable sidebar/footer area; a simple in-stock product with a known USD price.
- No switcher widget placed yet.

## Layer A — agent-driven browser

BOTH stores:

1. WP Admin → Appearance → Widgets. **Confirm the WooPayments currency-switcher widget is listed** in the available widgets.
2. Add it to a visible widget area; set a title (e.g. "Currency"); save. **Confirm the widget config saves without error.**
3. Visit the storefront shop page. **Confirm the switcher renders** with USD, GBP, and EUR as choices.
4. Switch to `GBP`. **Confirm the page reloads/re-renders with product prices in GBP** (£ symbol, converted amount consistent with the cached rate).
5. Navigate to the single product page. **Confirm the GBP selection persists across pages.**
6. Add the product to the cart. **Confirm cart line and totals follow the switched currency** (GBP, consistent with the shop-page price).
7. Switch back to `USD`. **Confirm prices and cart totals return to the original USD values.**

End state: switcher widget placed and functional on both stores; storefront back on USD.

Agent oracle mode: comparable (dual-store; reference is the golden oracle).
