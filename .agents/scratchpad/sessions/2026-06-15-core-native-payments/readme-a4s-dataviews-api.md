---
session: 2026-06-15-core-native-payments
type: readme
by: codex
created: 2026-06-19 11:36
last_updated: 2026-06-19 11:39
status: final
---

# DataViews API Money Movement

> **Prompt:** "In /Users/vladolaru/Work/a8c/woocommerce-develop-2, investigate the locally installed/currently used @wordpress/dataviews and @wordpress/dataviews/wp APIs relevant to WooPayments admin list tables. Do not edit files. Focus on: (1) how View.fields/layout/search/sort/filters are expected to round-trip through onChangeView, (2) how filter field ids should relate to Field ids and any field filter metadata in existing local usage, (3) whether the wp-admin entry supports the compound DataViews children API or prop-only API. Read local code/types/packages only. Return concise findings with file references and recommendations for our A4s money-movement wrapper/pages. The main agent is concurrently editing export/view-state files, so avoid overlapping work or assumptions from memory."

## What this session did

Investigated the local `@wordpress/dataviews` and `@wordpress/dataviews/wp` package surfaces used by WooCommerce admin, plus local WooCommerce call sites relevant to WooPayments admin list tables. Findings are in `analysis.md`.

## Arc

1. Confirm the locally installed WooCommerce admin package version and exports.
2. Read type definitions and implementation for `View`, `DataViews`, filters, sorting, search, and layout updates.
3. Compare with local WooCommerce admin and WooPayments usages.
4. Summarize recommendations in `analysis.md`.

## Status

- `analysis.md`: final
