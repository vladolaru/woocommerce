# WooPayments critical-flows parity suite (native-in-core)

Supervisor-owned verification suite for the native WooPayments-in-core merge. **Independent orchestration, assertions, and evidence provenance** from the implementor's harness at `tools/woopayments-merge/` — but NOT independent machinery: several Layer-D verdicts deliberately delegate to implementor exercisers and gates (`flow-drive.sh` for SC-01, `i18n-notes-gate.sh` for MA-10, `mc-rates-gate.sh` for MC-06, and the SC-04/SC-14/SS-10 gates named in their specs). A fail-open bug in a shared gate would green both harnesses at once; the double-coverage claim holds only for this suite's own assertions layered on top (state asserts, log-clean scans, identity probes, provenance binding). Where the two harnesses disagree on a flow, the disagreement itself is a finding to run down.

The suite's sources, specs, matrix, and tests are git-tracked; only run output under `evidence/` is git-ignored.

## Mandate

Every critical flow the WooPayments **extension** supports must pass on native WooPayments-in-core, **functionally and from a UX perspective**, even where visual UI changed. Flow set = the WooPayments [Critical flows](https://github.com/Automattic/woocommerce-payments/wiki/Critical-flows) wiki table (validated on every extension release); procedures = the [Testing instructions](https://github.com/Automattic/woocommerce-payments/wiki/Testing-instructions-for-critical-flows). A flow that passes on the reference store but fails/degrades on native is a release-blocking regression. Explicit native-continuity flows without a WooPayments 10.8 reference equivalent are target-only and must remain parity-blocked/not comparable rather than manufacturing a reference result.

## Hybrid architecture — two deliberately-overlapping layers

The suite checks each flow with a **deterministic layer** and/or an **AI-agent-driven browser layer**. Prefer overlap: high-risk money/UX flows get BOTH. A gap (a flow neither layer can run) is marked `BLOCKED`, never assumed-pass.

### Layer D — Deterministic (the repeatable backbone)

Scriptable, fast, CI-able, zero human judgment:

- **Setup/fixtures** (`setup/`): WP-CLI to set account state (via WCPay Dev Tools cache), create products, toggle settings, create coupons/shipping zones. Idempotent; snapshot+restore.
- **Exercisers** (`flows/*.sh`): drive a code path without a human — WP-CLI `eval-file` calling gateway/service methods, REST/API calls to create orders / process payments / refunds, and `playwright-cli` scripts for UI steps that ARE deterministic (fill the card iframe on a stable form, click place-order).
- **Assertions** (`lib/assert.sh`): verify resulting **store state** — order status, `_intent_id`/`_charge_id`/token meta, transaction record, subscription status + next-renewal date, refund record, email sent (mail catcher), debug-log clean. This is the "expected store state" backbone.
- Verdict: machine pass/fail + state diff vs reference.

### Layer A — AI-agent-driven browser (where deterministic is flaky/unrealistic/too complex)

A subagent drives a real browser and **judges** functional + UX parity against the reference, for:

- dynamic/interactive UI deterministic scripts handle poorly: SCA/3DS modals, Stripe-hosted redirect pages (Bancontact/iDEAL/P24/Klarna/Affirm/Afterpay), WooPay, Payment Request sheets;
- **UX/visual judgment** a script can't make: "is the saved-card radio discoverable and does selecting it actually work", "did the test-mode badge/notice render", "is this a cosmetic difference or a broken affordance";
- multi-step admin journeys where selector churn makes scripts brittle.
- Verdict: structured JSON (rubric below) + evidence (screenshots, observations). See `agent-specs/_template.md`.

Completed Layer-A evidence is ingested by `run.sh` from `--agent-results-dir` (default: `evidence/agent-results`). Write one JSON file per Markdown flow, named `<flow>.json`, using the template's shape: `{flow, oracle_mode, store_results, parity_verdict, regression_note}`. For comparable flows, the runner records per-store verdicts and enforces `parity_verdict` on the target. For target-only flows, it requires the spec and artifact to agree on `oracle_mode: target-only`, preserves the intentional reference/parity `BLOCKED` result, and records a complete target result independently without requeueing it. Missing, malformed, wrong-flow, missing-store, blocked target, or failing evidence remains fail-closed; incomplete evidence keeps the spec queued.

`build-agent-results.py` can generate those JSON files from authoritative gate rollups and browser/state artifacts that already drove evidence, such as SC-04 saved-card checkout, plugin-active settings, MS-07 admin subscription payment-method changes, LPM checkout, and token continuity. For SC-04, `verify.sh` first creates the immutable aggregate context and then runs `sc04-saved-card-gate.py`. The gate uses short-lived WordPress customer auth sessions and isolated Playwright contexts to exercise normal saved-card checkout plus saved-token SCA on both Classic and Blocks, destroys each auth session, and captures one browser artifact and one authoritative WP-CLI state artifact per store. The browser artifacts prove saved-token discovery/selection, no forced new-card fields, completed 3DS challenges, local order-received outcomes, and expected amount/currency. The state artifacts bind those order IDs to the same customer and provider-backed tokens, current source and store/account context, successful WooPayments order status, matching amounts/currencies, and intent/charge metadata. The adapter then validates and stamps those raw artifacts under the same aggregate run.

The adapter can also validate and copy a completed Layer-A v2 JSON whose capture already matches the active context. Legacy, stale, or mismatched copied evidence remains non-gating `BLOCKED`; missing required raw evidence also produces an explicit `BLOCKED` result. The adapter never converts incomplete evidence into a pass.

### Overlap policy

For money-moving and high-risk-UX flows, run BOTH: Layer D asserts the money/state outcome is correct; Layer A confirms a real user could discover + complete it. Example — saved-card checkout (B2): D asserts the saved token was charged with correct meta and SCA persisted; A confirms the shopper could select the saved card and complete the 3DS modal. Either layer failing fails the flow.

### Dual-store oracle

Comparable flows run on **reference `:8082`** (current WC + WooPayments extension — golden) and **target `:8889`** (native). Reference is the source of truth; target must reproduce its functional outcome + UX affordances. Same fixtures both sides.

**Target-only exception:** SS-10 proves additive SEPA-token continuity after cutover on the native target. WooPayments 10.8 has no equivalent split-SEPA scheduled-renewal callback, so its target result may pass while its cross-store parity verdict remains `BLOCKED`/not comparable. Account-country, business-type, and capability restrictions are recorded as environment/manual-testing prerequisites, not patched into Core behavior.

## Verdict rubric (encodes "visual may change, function/UX may not")

| Verdict | Rule |
|---------|------|
| **PASS** | All steps completable AND end-state correct (amount, order/subscription state, meta, emails, token saved/charged) AND every reference affordance discoverable + every critical feedback signal present. Styling/layout/copy-format may differ. |
| **PASS — visual divergence** | As PASS; cosmetic differences recorded, not a regression. |
| **FAIL — functional** | A step can't complete, OR end-state differs in a way that matters (wrong amount/status/meta, no email, token not saved/charged, money moved wrong). |
| **FAIL — UX** | End-state reachable in principle, but a user can't reasonably discover/complete it because a reference affordance or critical feedback is missing/broken on native. |
| **BLOCKED** | Can't be exercised locally (live-account-only, real-card-only, external SMS/redirect). Record the reason. |

Boundary: end-state wrong → functional fail; end-state right but required interaction/signal missing → UX fail; only pixels differ → pass (visual divergence).

## Folder layout

```text
tools/woopayments-critical-flows/
  README.md            # this spec + the matrix (authoritative)
  run.sh               # orchestrator: D suite -> ingest/queue A flows -> verdict rollup
  lib/
    common.sh          # store selection (:8082 ref / :8889 target), WP-CLI wrappers, assertions
  setup/
    fixtures.sh        # idempotent fixture setup (account state, products, settings, coupons, shipping) + snapshot/restore
  flows/
    SC-01-card-checkout.sh         # Layer D example (deterministic exerciser + state assert)
    SC-04-saved-card.md            # Hybrid example (D state-assert + A browser) — B2 acceptance test
    MS-07-admin-change-method.md   # Hybrid example (A browser + D state-assert) — B3 acceptance test
    ...                            # one per matrix row
  agent-specs/
    _template.md       # structured prompt template for Layer-A flow verification (rubric + evidence schema)
  evidence/            # run output (per flow, per store) — git-excluded
    agent-results/     # optional completed Layer-A JSON files named <flow>.json
```

## The matrix

Legend — **Layers:** `D` deterministic, `A` agent-driven, `D+A` both (overlap). **Status:** `PENDING` · `KNOWN-FAIL (Bn)` · `BLOCKED (reason)` · `TARGET-ONLY (parity not comparable)` · `TARGET-CONFIRMED (runner-unverified)`.

**`TARGET-CONFIRMED (runner-unverified)`** records that ad-hoc browser/state observation confirmed the behavior on the native target, but the verdict has NOT been earned through this suite's machinery: no runner-ingested Layer-A result, and reference-side evidence is partial or absent (see the per-flow `evidence/` narrative for exactly what was and wasn't driven). It is an observation ledger entry, not a PASS — the row still blocks enablement. **Re-earn path:** produce the context-bound gate/browser evidence the flow spec names, generate v2 results via `build-agent-results.py` against a live evidence context, and ingest through `run.sh` so the rollup records reference+target for each assigned layer. Only then may the row flip to PASS.

### Shopper — Checkout

| ID | Flow | Layers | Functional acceptance | UX / parity-sensitive | Status |
|----|------|--------|-----------------------|-----------------------|--------|
| SC-01 | Card checkout, shortcode (new card) | D+A | Order paid; txn recorded; amount/currency correct | Card fields; incomplete-form errors; test-mode badge + test-card copy | PENDING |
| SC-02 | Card checkout, Blocks (new card) | D+A | Order paid; txn recorded | Payment Element mounts; test-mode badge; errors | PENDING |
| SC-03 | 3DS-required card (`4000002500003155`), classic + Blocks | A (+D assert) | SCA → order paid | 3DS modal completes; fail path errors | PENDING |
| SC-04 | **Saved card → checkout (classic + Blocks)** | D+A | Saved token charged (not new PM); SCA handled | Saved-card radio selectable; no forced new-card; 3DS on saved token | PASS (runner-verified 2026-07-14: context-bound sc04 gate — classic+Blocks, normal+SCA, both stores; run archived under evidence/runs/) |
| SC-05 | Pay for order (My Account), new + save, 3DS | D+A | Pending order paid; PM optionally saved | "Pay" affordance; save checkbox; PM appears | PENDING |
| SC-06 | Save-PM checkbox + terms behavior (sub vs regular) | A | UI logic | Mandate only when save checked (regular); hidden for subs | PENDING |
| SC-07 | $1M cart limit | D+A | Checkout blocked over limit | Error below WooPayments method | PENDING |
| SC-08 | WooPay signup + checkout | A | Account created; order paid; PM+address reusable | WooPay button; OTP; redirect | BLOCKED (SMS OTP) |
| SC-09 | Stripe Link save + reuse | A | Order paid; Link UI on return | Link registration + reuse UI | BLOCKED (real Chrome card) |
| SC-10 | Regional — Bancontact / iDEAL / P24 | A (+D assert) | Currency-gated; pay + refund; correct logo | Shows only in correct currency; add/remove clean | PENDING |
| SC-11 | BNPL — Klarna / Affirm (≥$50) / Afterpay | A (+D assert) | Shows; pay + refund; "Payment via X"; logo | BNPL group; add/remove clean | PENDING |
| SC-12 | Add card via OTHER gateway (no WooPayments conflict) | D+A | Card saved via alt gateway | Only WooPayments "credit card" shown; no false "incomplete" error | PENDING |
| SC-13 | Shipping cost updates on method switch | D+A | Totals recompute $20→$40→$20 | Live total update | PENDING |
| SC-14 | LPM wave-1 checkout | D+A | Each wave-1 method pays or redirects with method-specific gateway/token metadata | Method appears only for valid currency/country and does not fall back to card | PENDING |

### Shopper — Payment methods (My Account)

| ID | Flow | Layers | Functional acceptance | UX checkpoints | Status |
|----|------|--------|-----------------------|----------------|--------|
| SP-01 | Add PM — regular card | D+A | PM saved; usable | Success notice; listed | PENDING |
| SP-02 | Add PM — 3DSv2 (`4000000000003220`) fail→succeed | A | Fail errors; success saves | 3DS modal; auth-fail msg; success | PENDING |
| SP-03 | Add PM — declined (`4000000000000002`) | D+A | Save rejected | "Card was declined" error | PENDING |
| SP-04 | Delete PM | D+A | PM removed | Delete affordance; list updates | PENDING |
| SP-05 | Saved-PM management / set default | D+A | Default reflected | Default control | PENDING |

### Shopper — Subscriptions

| ID | Flow | Layers | Functional acceptance | UX checkpoints | Status |
|----|------|--------|-----------------------|----------------|--------|
| SS-01 | Purchase subscription (initial) | D+A | Subscription+order; token saved | Mandate; no save-PM checkbox | PENDING |
| SS-02 | Change PM → new card | D+A | PM updated; renews on it | "Change payment"; PM row updates | PENDING |
| SS-03 | **Change PM → saved card** | D+A | Saved token set; renews on it | Saved-card selectable in change-payment | PASS (runner-verified 2026-07-14: browser Layer A both stores; renewal D layer provider-reconciled with chosen-token binding artifact; run archived under evidence/runs/) |
| SS-04 | Set / change default PM | D | Default updated; renewals use it | Set-default control | PENDING |
| SS-05 | Renew now (manual, shopper) | D+A | Renewal order paid; date advances | "Renew now" + result | PENDING |
| SS-06 | Cancel + re-subscribe | D+A | Cancel + new subscription | Cancel control; re-subscribe | PENDING |
| SS-07 | Coupon (signup/one-off/recurring) | D | Discount per type across renewals | Discount at checkout + schedule | PENDING |
| SS-08 | Free-trial subscription | D+A | Trial set; $0 initial; first renewal charges | Trial messaging; initial total | PENDING |
| SS-09 | Multiple subscriptions one purchase | D | All created | Per-sub schedules | PENDING |
| SS-10 | SEPA-token renewal after cutover | D+A | Plugin-created SEPA token remains visible and renews under native | My Account token row and renewal feedback remain discoverable | TARGET-ONLY (parity not comparable) |

### Merchant — Subscriptions (admin)

| ID | Flow | Layers | Functional acceptance | UX checkpoints | Status |
|----|------|--------|-----------------------|----------------|--------|
| MS-01 | Create subscription product | D+A | Purchasable | Subscription type + settings | PENDING |
| MS-02 | Purchase (merchant view) | D | Visible admin + My Account | Admin record | PENDING |
| MS-03 | Suspend + resume | D+A | On-hold blocks renewal; resume restores | Suspend/Resume + status | PENDING |
| MS-04 | Promote w/ coupon | D | Schedule reflects coupon | Coupon effect | PENDING |
| MS-05 | Renew automatically (scheduled) | D | Charges on date; email; active; next date | Renewal order + email | PENDING |
| MS-06 | Renew manually (admin) | D+A | Renewal order paid; date advances | "Renew" action | PENDING |
| MS-07 | **Admin change payment method** | D+A | Admin sets/corrects token; renewal uses it | WooPayments selectable AND editable token fields render+save | PASS (runner-verified 2026-07-14: admin browser Layer A both stores; renewal D layer provider-reconciled with token-id-pinned gate run + chosen-token binding artifact; run archived under evidence/runs/) |

### Merchant — Order (capture / refunds)

| ID | Flow | Layers | Functional acceptance | UX checkpoints | Status |
|----|------|--------|-----------------------|----------------|--------|
| MO-01 | Manual capture (order) | D+A | Captured; status; amount | Capture button → captured | PENDING |
| MO-02 | Manual capture — Uncaptured tab | D+A | Capture from list; row leaves tab | Tab lists eligible; capture works | PENDING |
| MO-03 | Manual capture — payment-details page | D+A | Capture from detail | Capture on detail; clears | PENDING |
| MO-04 | Full refund | D+A | Full refunded; status; txn shows refund | Refund control + result | PENDING |
| MO-05 | Partial refund (one + several) | D+A | Each partial + cumulative correct | Per-line refund; running total | PENDING |
| MO-06 | Refund failure handling | D | Failure surfaced; no phantom local refund | Error; state consistent | PENDING |

### Merchant — Admin (overview / transactions / payouts)

| ID | Flow | Layers | Functional acceptance | UX checkpoints | Status |
|----|------|--------|-----------------------|----------------|--------|
| MA-01 | Open admin as non-admin | D | Capability-gated | `manage_woocommerce` gating | PENDING |
| MA-02 | View account balances | A (+D) | Balance + available/pending correct | Overview cards | PENDING |
| MA-03 | View transactions | D+A | List populated | Table + row→detail | PENDING |
| MA-04 | Filter transactions | D+A | Narrows to criteria | Filter controls | PENDING |
| MA-05 | Search transactions | D+A | Results match | Search field | PENDING |
| MA-06 | Export transactions CSV | D | CSV downloads; integrity | Export affordance | PENDING |
| MA-07 | View transaction details | A (+D) | Fields correct | Method logo + formatting | PENDING |
| MA-08 | View payouts (+ details) | D+A | List + detail correct | Amounts/dates/status | PENDING |
| MA-09 | Large-dataset perf | D | Loads + filters responsively | No degradation | PENDING |
| MA-10 | Localized WooPayments order notes | D | Native order notes are localized like the plugin | Merchant-facing note text is not hardcoded English | PENDING |
| MA-11 | Plugin-active WooPayments settings screen | A (+D) | Plugin-owned settings page renders without native store collisions | `woocommerce_payments` settings screen is usable while plugin-active | PENDING |

### Merchant — Disputes

| ID | Flow | Layers | Functional acceptance | UX checkpoints | Status |
|----|------|--------|-----------------------|----------------|--------|
| MD-01 | Created: note + on-hold + notify | D+A | Order on-hold; dispute note | Note + status | PENDING |
| MD-02 | Save evidence | A (+D) | Evidence persisted | Evidence form + save | PENDING |
| MD-03 | Winning dispute | D | Won; funds returned; notes | Lifecycle notes | PENDING |
| MD-04 | Losing dispute | D | Lost; chargeback; notes | Lifecycle notes | PENDING |

### Merchant — Onboarding

| ID | Flow | Layers | Functional acceptance | UX checkpoints | Status |
|----|------|--------|-----------------------|----------------|--------|
| ON-01 | Onboard via WC Settings (NOX), test→live | A | Test+live account; methods configured | Incentive→NOX→KYC→task complete | PENDING (KYC) |
| ON-02 | Onboard via Launch Your Store (NOX) | A | Account ready; methods enabled | LYS task → complete | PENDING (KYC) |
| ON-03 | Manual install + setup | A | Wizard completes; methods at checkout | Install → badge → setup | PENDING |
| ON-04 | Plugin update via plugins page | D+A | Update clean; pages load; checkout works | No errors post-update | PENDING |

### Multi-currency

| ID | Flow | Layers | Functional acceptance | UX checkpoints | Status |
|----|------|--------|-----------------------|----------------|--------|
| MC-01 | Set up | D+A | Currencies enabled; rates | Setup UI | PENDING |
| MC-02 | Edit settings | D | Persist | Edit UI | PENDING |
| MC-03 | Add switcher widget | A | Renders + switches | Widget config | PENDING |
| MC-04 | Block widget (editor) | A | Inserts + switches | Editor block | PENDING |
| MC-05 | MC onboarding/setup task | A | Completes | Setup surface | PENDING |
| MC-06 | Automatic rates refresh | D | USD refresh produces matching GBP/EUR cached rates on reference and target | Admin rate state remains truthful after refresh | PENDING |
| MCS-01 | Shopper checkout — guest, selected currency | D+A | Order in currency; amounts correct | Switcher; prices; order currency | PENDING |
| MCS-02 | Shopper checkout — logged-in, selected currency | D+A | Selection persists; correct currency | Saved PMs; persistence | PENDING |

### Express checkout (Payment Request — Apple/Google Pay)

| ID | Flow | Layers | Functional acceptance | UX checkpoints | Status |
|----|------|--------|-----------------------|----------------|--------|
| EC-01 | PRB from product page | A | Order via PRB | "Pay now"; PRB sheet | BLOCKED (real card) |
| EC-02 | PRB from cart | A | Order via PRB | PRB on cart | BLOCKED (real card) |
| EC-03 | PRB with 3DS card | A | 3DS via PRB | 3DS in PRB | BLOCKED (real card) |

### Multisite

| ID | Flow | Layers | Functional acceptance | UX checkpoints | Status |
|----|------|--------|-----------------------|----------------|--------|
| MU-01 | Network install + activate | D | Network-wide availability | Network activation | PENDING |
| MU-02 | Manual install (multisite) | D | Per-site activation | Site-level control | PENDING |
| MU-03 | Plugin update (multisite) | D+A | Clean on primary+secondary | Pages load both | PENDING |
| MU-04 | Card checkout primary + secondary | D+A | Independent orders/txns per site | Per-site isolation | PENDING |

## Pre-seeded known regressions (N13 acceptance test)

FAILED on native per the certification; must flip to PASS (verified both stores, both layers, **through the runner**) before enablement:

- **SC-04 / SS-03 — saved-card (B2):** classic place-order always created a new PaymentMethod; Blocks had no `savedTokenComponent` (saved-token SCA unhandled). Saved cards default on.
- **MS-07 — admin change PM (B3):** WooPayments selectable but no editable token fields; save left it unbillable. Needs `woocommerce_subscription_payment_meta` family.

Current state: **all three are runner-verified PASS (2026-07-14)**. SC-04: context-bound `sc04-saved-card-gate.py` run (saved-token selection + saved-token SCA, classic and Blocks, both stores). SS-03: browser change-payment on both stores (saved-token list, persistence) plus driven renewals charging the chosen tokens, provider-reconciled. MS-07: admin edit on both stores (WooPayments selectable, `woocommerce_subscription_payment_meta` token fields editable, save persists) plus driven renewals charging the admin-set tokens, provider-reconciled — the exact-token linkage is machine-pinned by a token-id-pinned renewal-gate run and per-order chosen-token binding artifacts (renewal `_payment_method_id` == chosen token PM on every store/flow). All three were ingested through `run.sh` against a live evidence context; runs archived under `evidence/runs/`.

## Coverage ledger (no silent omissions)

- **BLOCKED locally:** SC-08 (WooPay OTP), SC-09 (Link real card), EC-01/02/03 (PRB real card). Manual/live pass only.
- **Layer-A-primary (external dependency):** regional + BNPL (Stripe redirects), disputes (simulation), onboarding (KYC), large-dataset perf, multisite.
- **Everything else: Layer D capable + Layer A overlap** on high-risk money/UX rows — including the saved-token-selection cases the implementor's gates missed.

## Run

```bash
# Full suite (both stores, both layers). MC-06 needs explicit store URLs:
./run.sh --store both --layer all --ref-url http://localhost:8082 --target-url http://store8889.localhost:8889
./run.sh --store target --flow SC-04         # one flow on native (partial scope)
./run.sh --layer deterministic --ref-url http://localhost:8082 --target-url http://store8889.localhost:8889
./run.sh --layer agent --agent-results-dir evidence/agent-results --context-file <evidence-context.json>
```

A flow passes only with reference+target evidence on file for each assigned layer. Before any Layer-D flow runs, the runner probes each store's identity (reference must run the plugin, target must run native, homes must differ) and blocks otherwise.

**Scope and matrix accounting:** only a *full-scope* run (`--store both --layer all`, no `--flow`) can claim the suite; it reads `matrix.tsv` (the machine-readable 76-row matrix, kept in sync with the tables above by a self-test) and refuses `status: "pass"` while any matrix row has no evidence — expect `blocked`/exit 3 with `matrix.uncovered_ids` in the rollup until every row is spec'd and evidenced. Filtered runs keep their per-run verdict but are marked `scope: "partial"` in the rollup and never speak for the suite. Every run is archived append-only under `evidence/runs/<stamp>-<scope>/`; the top-level `rollup.json` is just the latest pointer.

## Relationship to the implementor's harness

`tools/woopayments-merge/` is the implementor's verification harness. This suite is separately orchestrated and may overlap it. Overlap = corroboration — but see the header note: several Layer-D verdicts share the implementor's exercisers/gates, so treat agreement between the harnesses on those flows as one signal, not two.
