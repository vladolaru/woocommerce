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

## Latest runner evidence (2026-07-19)

- Accepted result: `sha256:92784f3d406f05c81fd54f639df5c7a8f637935520cd05c25b5aea3e8cef289b`, bound to context `e8355fa5-cdf2-4c17-b926-8628589e10ce` (`sha256:349bb9f1b13e4b11b30cbc36cbcfebfe5a11e759125cdacac7ee978f5563a85b`) at source `34540dd81f4420c9462f03ccf8aba3610d5670b5`. The accepted fixture aligns both stores on Twenty Twenty-One 2.8 with exactly `sidebar-1`, preserves unrelated options, and creates no order.
- Runner archive: `evidence/runs/20260719T020212Z-49117-partial` — reference PASS, target `FAIL - functional`, parity `FAIL - functional`; 1 passed, 1 failed, 0 blocked, and 0 MC-03 agent specs queued.
- Both stores save exactly one Currency switcher with symbols enabled and flags disabled, expose USD/EUR/GBP, render the USD 20.00 product at GBP 15.00, persist GBP through product and quantity-one cart views, and return the cart to USD. Decisive cart screenshots are bound to network-idle, post-load, stable-DOM, skeleton-free, and perceptual-stability checks.
- The native target fails the WooPayments lifecycle contract during the GBP request: configured `woocommerce_currency` remains USD, but `wcpay_multi_currency_store_currency` changes to shopper-selected GBP. The reference keeps both values at USD. The target's accepted automatic-rate cache remains present and healthy at EUR `0.88` and GBP `0.75`; an earlier diagnostic-only cache eviction did not reproduce and is not part of the verdict.
- This is a new regression outside the previously expected redirect-return, manual-capture, and express fraud-token gaps. Re-earn MC-03 after the native lifecycle abstraction reads the unfiltered configured store currency rather than the shopper-filtered currency, while preserving the existing client widget contract and shopper behavior. The matrix remains `PENDING` until target and parity PASS.
