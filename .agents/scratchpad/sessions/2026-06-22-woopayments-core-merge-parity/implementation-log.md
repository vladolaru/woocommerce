---
session: 2026-06-22-woopayments-core-merge-parity
type: implementation-log
by: claude
created: 2026-07-06 16:22
target: exp/core-native-payments — execution of plan.md (all 7 phases)
reconciles:
  - plan.md
  - parity.md
status: final
---

# Implementation log — plan.md execution

Executed the remediation plan via subagent-driven development (fresh implementer per unit + two-stage spec/quality review with fix loops). All 45 review findings are resolved or dispositioned.

**Git range:** `5abb021c6e..0cae35157d` — **41 commits, 100 files** (25 PHP source, 38 PHP test, 1 `includes/`, 10 JS/TS, 1 scss, 25 changelogs).

## How the plan was executed

The plan's tasks were file-overlapping (e.g. 5 findings all edit `WooPaymentsApiClient.php`), so they were re-clustered into 19 file-coherent **units** to avoid parallel edits to the same file. Each unit: TDD implement → spec-compliance review → code-quality review → fix loop → commit(s). Independent-file units were run in parallel with specific-file commits.

## Findings dispositioned

### Fixed (code changes) — Phases 0–5
- **Regressions vs client (P0):** post-charge apply guard (`bfcb6ce5`), dispute-note escaping (`0b11bf8a`), list-request `send()` BC (`147606e9`), account-field sanitization (`deb2d8c9`), swallowed-exception logging (`5076c7dc`/`6ea1b4a8`/`24a6f21d`), orphan docblock (`d3111113`).
- **Backward-compat (P1):** onboarding filters re-fired via `apply_filters_deprecated` (`c49baad5`), GET account-session route documented as BC (`3eece18f`), `@since` on section constant (`9d771cef`).
- **Beat-the-client hardening (P2):** WooPay auth filter made strengthen-only (`c17bc8f3`), blocks checkout kses-after-filter (`195a39ae`), SetupIntent ownership check (`47644832`), atomic webhook claim (`53de2d4b`), serialized refund resolution (`7c43c8da`), MC perf: existence cache (`1062b791`), StateBuilder option reads (`f42b6e68`), cheaper backtrace capture (`f35bc174`), cart-type cache (`e0e84777`).
- **Shared parity bugs (P3):** flat dispute-summary filters (`0175d50d`), hyphenated route IDs (`6022b9aa`), VAT rawurlencode (`bd8596d0`), jittered retry backoff (`54b15aba`), timezone guard (`8ad0a91a`), prepared analytics SQL (`b6ab58da`), WooPay message kses (`6a3e12e5`), mobile metadata sanitize (`8965abd1`), numeric order_id regex (`1a302332`), customer-create lock (`61a68295`), Capital error status (`4be7ec7c`), settings single-write documented (`a9e93194`).
- **a11y (P4):** duplicate SR announcements (`90565c18`/`bccc465d`), announceable dash (`99309eb5`), conditional aria-controls (`901cb561`).
- **Tests (P5):** nonce-rejection coverage (`5bd7b33b`), `set_up`/`tear_down` (`3593fad3`), `assertTrue(true)` → real assertions (`7c7309ac`), await userEvent (`23405cc0`), settings-page contract guards (`11adbec5`).

### Changed disposition during execution
- **`7522b93d` (referrer Tracks nonce):** the plan proposed a nonce. On execution the handler proved to be a post-KYC **email CTA** (clicked days later → session nonce is the wrong tool and broke the analytics). Re-decided: **reverted the nonce and documented the accepted risk** (capability-gated, allowlisted stage, safe same-page redirect → negligible CSRF exposure). This is the robust, backward-compatible resolution.

### Documented closures (no code) — P6
- `048c849b` (file endpoint `__return_true`): by-design parity — client uses empty `permission_callback`; both gate inside the handler to a `business_logo`/`business_icon` allowlist. Not a vulnerability.
- `339eeb0c`: the wired provider always normalizes `data`; the fatal is unreachable.
- `106f2823`: WP `<Modal>` `useFocusReturn` handles dismiss focus; core's extra machinery is intentional post-accept.
- `53de2d4b`/`7c43c8da` re-rated from "critical regression" to "improvement over the client" (still hardened above).

## Phase 7 gate (all green)
- **PHPStan:** 0 errors across all 26 modified source files (no baseline additions).
- **phpcs:** 0 violations across all 64 changed PHP files.
- **PHP suites:** WooPayments **1538 tests OK**; MultiCurrency **583 tests OK**.
- **ts:check:** exit 0 (U17 type removal is type-safe).
- **JS suites:** woopayments admin+settings **475 tests / 30 suites** pass.
- **Pre-existing failures:** a broad `--filter Payment` run surfaced 11 failures in Store-API/gateway-list/order tests — **confirmed identical at the pre-change baseline `5abb021c6e`**, unrelated to this work (untouched files).

## Follow-ups / notes for the PR
- **Reconciliation consumer:** `bfcb6ce5` makes a charged-but-unapplied order *reconcilable* (transaction id persisted + error log), not auto-*reconciled* — confirm something consumes the `native-payments` error log to finish the lifecycle.
- **Dev-tools WooPay mocking:** strengthen-only auth (`c17bc8f3`) neutralizes the WooPayments Dev Tools grant-only mock against the native controller — local WooPay e2e must use a signed request. Not a production risk.
- **MC existence cache staleness:** `1062b791` can hold a stale "false" up to 1h if MC orders are inserted via non-CRUD paths that skip `woocommerce_new_order` (rare; migration/import). Bounded by the 1h TTL. Worth a "known limitations" line.
- **`send()` return shape:** `147606e9` returns a decoded array, not the plugin's `Response` object; `test_mode` placement differs slightly on the reporting path (server reads it either way).
- **Timezone sibling divergence:** `8ad0a91a` falls back to UTC+shift while the reports controller passes through unshifted — worth aligning or commenting.
- **`sanitize_key` lossiness / metadata cap counts kept entries:** acceptable trade-offs, noted.
- **Minor commit-message framing:** the `3593fad3` commit describes a convention fix as a leak fix (code is correct; WP's `clean_up_global_scope` still ran under the polyfill).
- **Not pushed:** branch is 41 commits ahead of `origin/exp/core-native-payments`; push left to the user.
