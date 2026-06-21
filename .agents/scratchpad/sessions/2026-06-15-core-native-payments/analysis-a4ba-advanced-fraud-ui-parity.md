---
session: 2026-06-15-core-native-payments
type: analysis
by: codex
created: 2026-06-21 00:21
target: A4ba advanced fraud UI parity
reconciles:
  - review-a4az-fraud-residual-source-check.md
  - implementation-log.md
status: draft
last_updated: 2026-06-21 00:21
---

# A4ba Advanced Fraud UI Parity Analysis

> **Prompt:** "Continue after A4az by source-verifying the remaining reopened-A4/N12 fraud UI residual that A4az deliberately parked: advanced fraud loading and card-detail parity."

## Status

Started after A4az closeout. Product worktree is clean except branch-ahead metadata. No product code has been edited for A4ba yet.

## Source Findings

The remaining fraud residual is source-backed and merchant-facing. Native `fraud-protection/advanced/index.tsx` currently renders a single consolidated advanced page with plain WP `Card`, `CheckboxControl`, `TextControl`, and a plain loading text branch (`Loading WooPayments settings…`). The reference advanced page in `/Users/vladolaru/Work/a8c/woocommerce-payments/client/settings/fraud-protection/advanced-settings/index.tsx` wraps the route in `SettingsLayout`, `SettingsSection`, `FormBusyState`, `ErrorBoundary`, and seven `LoadableBlock numLines={20}` skeleton blocks around individual rule cards.

The highest-value UI gap is not simply component decomposition. The native page drops several rule-card details that shoppers do not see but merchants do while configuring fraud rules:

- AVS Mismatch warning: reference links "selling locations" to WooCommerce general settings when none of the configured selling locations support AVS; native shows the warning text without the link.
- International IP Address: reference links "IP addresses" to an external explainer, links "supported countries" to WooCommerce general settings, and shows `AllowedCountriesNotice` when the filter is configurable. Native omits those links and omits the allowed-countries info notice.
- IP Address Mismatch: reference links "IP address" to an external explainer. Native uses plain text.
- Purchase Price Threshold: reference uses a `Limits` subsection, currency-prefixed amount inputs, "Leave blank for no limit" help, and inline warning/error notices while editing. Native uses plain number `TextControl`s labelled minimum/maximum order amount and only validates at Save.
- Order Items Threshold: reference uses a `Limits` subsection, field labels "Minimum items per order" and "Maximum items per order", "Leave blank for no limit" help, integer keydown guards, and inline warning/error notices while editing. Native uses plain number `TextControl`s labelled minimum/maximum items and only validates at Save.
- Loading/busy: reference shows per-card skeletons and a form busy overlay on save. Native replaces the whole page with a plain status string during load and only marks the Save button busy during save.

Source-backed differences that are likely not worth changing in this slice:

- Reference uses plugin-specific `CardBody`, `InlineNotice`, Gridicons, `SettingsLayout`, and `SettingsSection`; native Core should not mechanically port those private component dependencies. We can restore the merchant-facing behavior with Core/WP components and native route styling.
- Reference breadcrumb points to legacy `section=woocommerce_payments`; native correctly uses the Core provider route URL. Keep the native back route.
- Reference `IPAddressMismatchRuleCard` id is `ip-address-mismatch`, while native uses `ip-address-mismatch-card`; native fixed the Tracks observer mapping in A4ay and should keep the current id unless source evidence shows reference DOM id is required.
- Reference uses `useConfirmNavigation`; native uses `beforeunload` without clobbering existing handlers. This is already intentionally improved and covered by tests, so do not replace it in A4ba.

## Candidate Slice

A4ba should be a frontend-only advanced fraud UI detail/loading slice. It should keep the current native settings data/store architecture and avoid broader settings-page route refactors. The coherent scope is: restore advanced rule-card detail parity for links, allowed-countries notices, threshold `Limits` controls/help/inline notices, and loading/busy presentation using Core/WP components and native styling. Browser proof should cover the target advanced fraud route in at least loaded and loading/error-controlled states where practical, with log scans unchanged.
