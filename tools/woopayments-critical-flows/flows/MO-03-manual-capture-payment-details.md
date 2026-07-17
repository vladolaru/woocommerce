# MO-03 — Manual capture from the payment-details page · HYBRID (A + D)

Guards the third capture surface: the per-transaction payment-details page (Payments → Transactions → Uncaptured → open a row). For an uncaptured intent this page must show the authorized state and offer a Capture action; capturing from here must pay the order and clear the authorization from the Uncaptured tab. The regression to catch: under native the detail page renders the authorization without any capture affordance (dead end for a merchant investigating a specific charge), or the detail-level capture completes in UI but the order/intent state never transitions.

## Fixtures (both stores)

- Connected test account; manual capture enabled (MO-01 fixture).
- One authorized-not-captured order (test checkout with `4242 4242 4242 4242` while manual capture is on).

## Layer A — agent-driven browser

BOTH stores:

1. WP Admin → Payments → Transactions → Uncaptured tab → click the authorization's row to open its payment-details page.
2. **The page identifies the payment (amount, order link) and shows an authorized/uncaptured status chip.**
3. **A Capture action (button) is present on the detail page.** Click it and confirm.
4. **A success signal renders and the on-page status flips to a captured/paid state; the Capture action is no longer offered.**
5. Return to Payments → Transactions → Uncaptured: **the row is gone.**
6. WooCommerce → Orders → the linked order: **status Processing with a capture order note for the full amount.**

End state: intent captured from the detail page; Uncaptured tab cleared; order paid.

## Layer D — deterministic state assertion

- Pre-capture: assert order `on-hold`, `_intent_id` with status `requires_capture`, `_charge_id` present and listed by the authorizations endpoint.
- Post-capture: assert intent `succeeded`, order `processing`, captured amount equals order total, authorization gone from the authorizations endpoint, capture note on the order.
- Compare ref vs target end-state.

Deterministic exerciser: `MO-03-manual-capture-payment-details.sh` creates and binds one fresh provider-backed manual authorization per store, proves the exact authorization-list/order/provider transition, captures that fixture once through the deterministic provider/order capture behavior, and validates masked cross-store state plus financial parity through an independently recomputed manifest. This Layer-D evidence does not prove the payment-details page's Capture affordance or interaction; Layer A remains decisive for that browser surface.

Runner-verified Layer-D evidence: `20260717T093508Z-67583-partial`. Both stores pass the exact manual-authorization, full-capture, authorization-removal, Processing-order, full-amount-note, financial-parity, and clean-log contract.

Runner-verified Layer-A evidence: `20260717T104031Z-97822-partial`. Reference passes the complete row-to-detail browser contract and captures successfully from Payment details. Native renders the exact authorization row and row-level Capture/Cancel actions, but fails UX because the row exposes zero links and ordinary clicks on both the row and its exact order cell leave the Uncaptured URL unchanged. The required Payment details journey is therefore unreachable; no target capture mutation was emitted, and authoritative state proves the authorization remained intact rather than being bypassed through another surface.

Capture evidence distinguishes the reference plugin's HTTP result from Core's native `PaymentOutcome`: plugin success requires `succeeded` plus HTTP 2xx, while native success requires `completed` and permits the outcome's absent HTTP status (`0`). A native failure without authoritative transport provenance is blocked, even when its finite provider code resembles a product decline. Unknown provider diagnostics are archived only as finite sentinels and fingerprints. Shared clean-log evidence uses a ready-before-flow FIFO witness as an evidence-integrity mechanism, not a general durable message queue. Its archival scope covers only writes made by the authorized deterministic flow after authenticated `ready` and before the coordinated terminal, conditional on successful backing append and PHP flush. `fsync()` is opportunistic when the runtime exposes it; the harness does not claim stable-media durability on PHP 7.4. Observed pre-ready or unknown-writer activity, unprovable writer quiescence, cap or deadline exhaustion, and post-read append, flush, or available-sync failure are fixed secret-safe BLOCKED conditions and can never support PASS. The harness does not claim archival preservation for bytes implicated in those blocked conditions. Exact child reaping, authenticated journal and terminal continuity, strict store and artifact identities, and current-run store, flow, and path origin binding remain mandatory before restoration or clean evidence.

Agent oracle mode: comparable (dual-store; reference is the golden oracle).
