---
session: 2026-06-15-core-native-payments
type: analysis
by: codex
created: 2026-06-19 11:36
target: plugins/woocommerce/client/admin/node_modules/@wordpress/dataviews
last_updated: 2026-06-19 11:39
status: final
---

# DataViews API Analysis

## Evidence Log

- Confirmed WooCommerce admin depends on `@wordpress/dataviews` through `plugins/woocommerce/client/admin/package.json`; local installed package is `4.22.0` at `plugins/woocommerce/client/admin/node_modules/@wordpress/dataviews`.
- Initial local import scan found WooPayments admin list table work importing `DataViews`, `Field`, and `View` from `@wordpress/dataviews/wp` in `plugins/woocommerce/client/admin/client/woopayments/admin/money-movement/dataviews.tsx`; settings email also imports the wp entry.
- Confirmed the package export map routes `@wordpress/dataviews/wp` to the same generated types as the plain entry, but to the `build-wp/index.js` runtime bundle.
- Read the local type definitions for `Field`, `Filter`, `View`, `ViewTable`, and `DataViewsProps`.
- Read the DataViews source paths for search, filters, table column header menu, pagination, view config, table layout, field normalization, and local filtering/sorting.
- Compared with local WooCommerce usage in settings email and the current untracked A4s money-movement files. The A4s files inspected are local working tree state, not committed truth.

## Findings

### 1. `View` round-trip expectations

`View` is the single state object passed into `DataViews` and returned through `onChangeView`. The local types define `ViewBase` with `type`, `search`, `filters`, `sort`, `page`, `perPage`, `fields`, `titleField`, `mediaField`, `descriptionField`, and show toggles; `ViewTable` adds `layout.styles` and `layout.density`.

Most DataViews internal mutations spread the previous view and replace only the changed property:

- Search calls `onChangeView( { ...view, page: 1, search: debouncedSearch } )`.
- Pagination calls `onChangeView( { ...view, page: nextPage } )`.
- Filter add/update/remove/reset calls preserve unrelated keys, reset `page` to `1`, and replace `filters`.
- Sort changes replace `sort` and set `showLevels: false`.
- Per-page changes replace `perPage` and reset `page` to `1`.
- Field show/hide/reorder changes replace `fields`.
- Table density changes preserves `view.layout` and writes `layout.density`.

The main exception is layout switching: the old `layout` key is deleted and the selected `defaultLayouts[type]` object is merged into the view. With table-only `defaultLayouts={{ table: {} }}`, no layout switcher appears.

Recommendation for A4s: treat `onChangeView(nextView)` as authoritative for the full DataViews view state. Derive REST/query state only from query-owned keys (`page`, `perPage`, `search`, `sort`, `filters`) and persist visual preferences separately (`fields`, `layout`, `titleField`, `showTitle`). Do not reconstruct `nextView` by merging a remembered old view unless necessary.

### 2. Filter field IDs and field metadata

The filter UI is generated from `Field` definitions, not from a separate filter registry. `useFilters()` iterates normalized fields and only creates filters for fields with `elements.length` and at least one sanitized operator. The normalized filter's `field` is exactly `field.id`.

Local settings-email usage follows that model: `view.filters[*].field` values are `status`, `recipients`, and `updates`, matching the corresponding `Field.id`; those fields also provide `elements` and `filterBy.operators`, with `updates` marked `filterBy.isPrimary`.

DataViews supports only the operators listed in local constants: `is`, `isNot`, `isAny`, `isNone`, `isAll`, `isNotAll`. If a field mixes single-selection operators (`is`, `isNot`) with multi-selection operators, sanitization drops the multi-selection operators. If no operators are provided, filterable fields default to `isAny` and `isNone`.

Recommendation for A4s: if we want DataViews-rendered filter controls, define filterable fields whose `id` is the UI/domain field ID and use query mapping only at the REST boundary. For example, keep `Field.id: 'status'` and map `status` plus `isNot`/`isNone` to `status_is_not` in query serialization. Avoid creating filter-only IDs like `status_is` unless there is also a matching hidden/visible `Field` with `elements`, because DataViews only discovers filters from fields.

Current A4s snapshot has query/view conversion support for filters, but the rendered transaction/dispute/payout field arrays do not define `elements` or `filterBy`, so the DataViews UI will not expose filter controls for those fields yet.

### 3. `@wordpress/dataviews/wp` compound API support

The WooCommerce admin package exports `@wordpress/dataviews/wp` with `types: ./build-types/index.d.ts` and `default: ./build-wp/index.js`. The local `DataViewsProps` type has no `children` prop and no static subcomponents. The `build-wp` runtime `DataViews` destructures fixed props only and renders its own search/filter toolbar, view config, layout, and footer internally.

The compound API (`DataViews.Search`, `DataViews.FiltersToggle`, `DataViews.LayoutSwitcher`, `DataViews.ViewConfig`, `DataViews.FiltersToggled`, `DataViews.Layout`, `DataViews.Footer`) exists in local experimental-products-app usage, but that package uses a separate custom `@wordpress/dataviews` 14.2.0 tarball, not the WooCommerce admin `@wordpress/dataviews/wp` 4.22.0 bundle.

Recommendation for A4s: use the prop-only API for wp-admin/WooCommerce admin list tables. Keep custom controls in `header` or adjacent wrapper UI, not as DataViews children. Do not model A4s on the experimental-products-app compound usage unless the admin package is upgraded and verified locally.

## Key References

- `plugins/woocommerce/client/admin/package.json:85` and `pnpm-lock.yaml:2899` show the admin dependency resolving to `@wordpress/dataviews` 4.22.0.
- `plugins/woocommerce/client/admin/node_modules/@wordpress/dataviews/package.json:27` maps `@wordpress/dataviews/wp` to `build-wp/index.js`.
- `plugins/woocommerce/client/admin/node_modules/@wordpress/dataviews/build-types/types.d.ts:60` defines `Field`; `:164` defines `Filter`; `:208` defines `ViewBase`; `:290` defines `ViewTable`.
- `plugins/woocommerce/client/admin/node_modules/@wordpress/dataviews/build-types/components/dataviews/index.d.ts:9` defines prop-only `DataViewsProps`.
- `plugins/woocommerce/client/admin/node_modules/@wordpress/dataviews/src/components/dataviews-search/index.tsx:32` shows search round-tripping through `onChangeView`.
- `plugins/woocommerce/client/admin/node_modules/@wordpress/dataviews/src/components/dataviews-filters/index.tsx:27` shows filters being derived from `Field.id`, `elements`, and `filterBy`.
- `plugins/woocommerce/client/admin/node_modules/@wordpress/dataviews/src/components/dataviews-view-config/index.tsx:64` shows layout switching; `:129`, `:164`, `:221`, and `:471` show sort, per-page, and field updates.
- `plugins/woocommerce/client/admin/node_modules/@wordpress/dataviews/src/dataviews-layouts/table/column-header-menu.tsx:84` shows column-header filter eligibility; `:138`, `:162`, and `:190` show sort/filter/field updates.
- `plugins/woocommerce/client/admin/client/settings-email/settings-email-listing-listview.tsx:83` is the local wp-admin filter metadata example.
- `plugins/woocommerce/client/admin/client/woopayments/admin/money-movement/query.ts:278` and `:346` are the current A4s query/view conversion points.
- `plugins/woocommerce/client/admin/client/woopayments/admin/money-movement/view-preferences.ts:6` stores `fields`, `layout`, `showTitle`, and `titleField` outside REST query state.
- `plugins/woocommerce/client/admin/node_modules/@wordpress/dataviews/build-wp/index.js:14593` shows the wp bundle's prop-only `DataViews` runtime.
