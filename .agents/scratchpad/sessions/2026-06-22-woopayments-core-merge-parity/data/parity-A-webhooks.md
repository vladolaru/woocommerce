---
session: 2026-06-22-woopayments-core-merge-parity
type: analysis
by: subagent:parity-A
created: 2026-06-22 22:55
target: webhooks & event processing
---

# Parity Analysis — Webhooks & Event Processing

CORE = `woocommerce-develop-2` (merge branch under review)
CLIENT = `woocommerce-payments` v10.8.0 @ `a82cbee4` (oracle)

Three findings examined. Summary verdicts:

| id | finding | verdict | confidence |
|----|---------|---------|------------|
| 53de2d4b | webhook idempotency TOCTOU | NEW-IN-CORE (net improvement, with a real-but-narrow TOCTOU window) | high |
| 339eeb0c | unguarded `$response['data']` | IMPROVED (premise mostly mitigated by provider normalization) | high |
| 0b11bf8a | dispute note XSS (missing esc) | REGRESSION + BC-RISK | high |

---

## Finding 1 — 53de2d4b: Webhook idempotency TOCTOU (NEW-IN-CORE)

**CORE:** `WooPaymentsEventIngestor::process()` lines 197-209.
```php
$event_id = $this->get_event_id( $event );
if ( '' !== $event_id && $this->is_event_already_processed( $event_id ) ) {
    return;
}
$this->dispatch( $event );             // side effects run here
if ( '' !== $event_id ) {
    $this->mark_event_processed( $event_id );   // marker written only after success
}
```
Idempotency is a `get_transient` read → `dispatch()` → `set_transient` write (`wcpay_processed_event_<md5(id)>`, TTL `HOUR_IN_SECONDS`). Two concurrent deliveries of the same event ID can both pass the `is_event_already_processed()` check before either writes the marker — classic check-then-act TOCTOU. The transient is not a lock; there is no `wp_cache_add`/`GET_LOCK`/DB-unique gate. So concurrent Stripe retries (or an Action Scheduler retry racing a fresh delivery) can both reach `dispatch()`.

**CLIENT equivalent — searched and read:**
- `WC_REST_Payments_Webhook_Controller::handle_webhook()` (`includes/admin/class-wc-rest-payments-webhook-controller.php:76-90`) — calls `$this->webhook_processing_service->process( $body )` **directly, with no idempotency check of any kind.**
- `WC_Payments_Webhook_Processing_Service::process()` (`includes/class-wc-payments-webhook-processing-service.php:145-254`) — reads `type`/`id`, logs, mode-mismatch check, then a `switch` straight into side effects. **No processed-event marker, no lock, no dedupe.**
- `WC_Payments_Webhook_Reliability_Service::process_event()` (`includes/class-wc-payments-webhook-reliability-service.php:132-150`) — the replay path: `get_event_data` → `delete_event_data` → `process()`. Note it **deletes the stored event BEFORE processing** (line 137 before 145), the opposite ordering from core's failed-event store (which deletes after). No idempotency marker either.
- Grep for `wcpay_processed_event`, `already_processed`, `idempot`, `processed_event` across `includes/` returns only the *outbound* request idempotency key (`includes/core/server/request/class-refund-charge.php`, `class-wc-payments-api-client.php` `Idempotency-Key` header) and the unrelated `Duplicate_Payment_Prevention_Service` (checkout session dedupe, not webhooks). **There is no inbound webhook dedupe construct in the client at all.**

**Decisive client code** (`class-wc-rest-payments-webhook-controller.php:76-90`):
```php
public function handle_webhook( $request ) {
    $body = $request->get_json_params();
    try {
        $this->webhook_processing_service->process( $body );  // no dedupe whatsoever
    } catch ( Invalid_Webhook_Data_Exception $e ) { ... }
    ...
}
```

**Verdict: NEW-IN-CORE.** The per-event "already processed" marker is a construct that **does not exist in the client**. The client processes every delivery unconditionally and relies on lower-level guards (`order_note_exists()` before adding notes, refund-ID lookups before creating refunds) to be naturally idempotent. Core's marker is therefore a **net improvement** over the oracle for the common (sequential re-delivery) case.

The finding's TOCTOU claim is technically real but narrower than stated:
- The *idempotency marker itself* has a genuine check-then-act race under true concurrency.
- However the money-affecting side effects it guards mostly have their **own** secondary idempotency in both core and client: the dispute handler calls `order_note_exists()` before `update_status`/`add_order_note` (core `WooPaymentsDisputeEventHandler` lines 185, 207, 258) and the refund path keys off remaining-refund-amount; the refund handler dedupes on refund ID. So "duplicate refunds on charge.dispute.closed" is partially mitigated by `get_remaining_refund_amount()` going to zero after the first refund, and "double payment_intent.succeeded" mostly re-writes the same meta. The marker is a coarse first-line guard, not the only guard.
- Bottom line: the TOCTOU window is real and worth a lock (`wp_cache_add` sentinel or AS unique-claim) for hardening, but core is **strictly better than the client oracle here**, not worse. Classify as NEW-IN-CORE with a recommend-hardening note rather than REGRESSION.

Confidence: **high** (client has zero inbound dedupe — confirmed by reading the controller + processing service + reliability service and grepping the whole `includes/` tree).

---

## Finding 2 — 339eeb0c: Unguarded `$response['data']` fatals the AS job (IMPROVED)

**CORE:** `WooPaymentsWebhookReliabilityService::fetch_events_and_schedule_processing_jobs()` lines 131-151.
```php
$response = $this->failed_events_provider->get_failed_webhook_events();
foreach ( $response['data'] as $event ) {        // line 134 — flagged
    ...
}
if ( ! empty( $response['has_more'] ) ) { ... }
```
The finding says an unexpected provider response shape would fatal the Action Scheduler job at the unguarded `$response['data']`.

**But the provider guarantees the shape.** `WooPaymentsFailedEventsProvider::get_failed_webhook_events()` (`WooPaymentsFailedEventsProvider.php:53-84`) is the sole supplier wired into the service (`init()` injects `WooPaymentsFailedEventsProvider`, and line 132 calls it). It **always** returns a normalized `array{data: array, has_more: bool}`:
- entry default is `array( 'data' => array(), 'has_more' => false )` (lines 54-57);
- on `WooPaymentsApiException` it logs and keeps that default (lines 62-67);
- both the API result and any value the `FILTER_FAILED_WEBHOOK_EVENTS` filter returns are passed through `normalize_events_page()` (lines 69, 83), which forces `data` to an `array_values(array_filter(..., 'is_array'))` and `has_more` to `(bool)` (lines 92-106), and returns the safe default if `$events` is not even an array.

So `$response['data']` is, by the provider's contract, **always a (possibly empty) array.** The `foreach` cannot fatal on a missing key or non-iterable in normal operation. A fatal would require calling the consumer with a raw client response that bypassed the provider — which the wiring does not do.

**CLIENT equivalent:** `WC_Payments_Webhook_Reliability_Service::fetch_events_and_schedule_processing_jobs()` (`includes/class-wc-payments-webhook-reliability-service.php:100-123`):
```php
try {
    $payload = $this->payments_api_client->get_failed_webhook_events();
} catch ( API_Exception $e ) {
    Logger::error( ... ); return;           // exception guarded inline
}
if ( $payload[ self::CONTINUOUS_FETCH_FLAG_EVENTS_LIST ] ?? false ) { ... }   // null-coalesced
$events = $payload['data'] ?? [];           // line 113 — DEFENSIVE: null-coalesced!
foreach ( $events as $event ) { ... }
```
The client reads the **raw** API client response directly (no normalizing provider layer), and it explicitly guards with `$payload['data'] ?? []` (line 113) and `$payload[...] ?? false` (line 108). It also catches `API_Exception` inline (line 103).

**Comparison:**
- Client: raw response, but defensively null-coalesces `data` to `[]`. Cannot fatal on missing `data`. (If the API returned `data` as a non-array scalar, `foreach` would warn/fail — a theoretical gap, but the server contract makes that not happen.)
- Core: pushes the same defensiveness one layer down into a dedicated `WooPaymentsFailedEventsProvider` that normalizes `data` to a real array **and** filters non-array elements **and** swallows the API exception. The consumer line 134 looks unguarded in isolation but is protected by a strictly stronger upstream contract than the client's inline `?? []`.

**Verdict: IMPROVED.** The literal line-134 access has no inline guard, but the injected provider provides a stronger normalization guarantee than the client's `?? []`. The finding's stated failure mode (job fatals on unexpected provider response) is **not reachable** through the wired code path. Core is at parity-or-better, not worse. (A defensive `$response['data'] ?? []` at the call site would still be cheap insurance and would make the safety self-evident without cross-file reasoning — worth a nit, not a bug.)

Confidence: **high** (read the full provider, confirmed both the default-init and the `normalize_events_page` post-filter run on every return path; confirmed the service is wired to the provider, not the raw client).

---

## Finding 3 — 0b11bf8a: Dispute note built without esc_html()/esc_url() (REGRESSION + BC-RISK)

**CORE:** `WooPaymentsDisputeEventHandler` note builders.
- `process_dispute_updated()` lines 251-256:
```php
$note = sprintf(
    __( '%1$s. See <a href="%2$s">dispute overview</a> for more details.', 'woocommerce' ),
    $message,
    $this->get_dispute_url( $charge_id, $balance_transaction_id )
);
```
- `get_dispute_created_note()` lines 438-455 and `get_dispute_closed_note()` lines 467-482 — same pattern: raw `sprintf( __( '... %1$s ... <a href="%4$s" ...>...</a>' ), $amount, $reason, $due_by, $url )` / `( '... status %1$s ... <a href="%2$s">...' ), $status, $url )`.

None of these wrap the assembled note in `esc_html`/`esc_url`, and the interpolated values include **webhook-supplied** strings:
- `$status` — from `get_required_string( $event_object, 'status' )` (dispute object `status`), flows into `get_dispute_closed_note` `%1$s` and into `$is_inquiry`.
- `$reason` — from the webhook via `get_dispute_reason_description()` (this one is mapped to a fixed allow-list, lines 491-511, so it is safe), but `$amount`/`$due_by` are formatted from webhook numbers.
- The `<a href="%2$s">` URL in `process_dispute_updated` is `get_dispute_url()` output, sprintf'd raw into an attribute. `get_dispute_url()` → `Utils::wc_payments_legacy_admin_url()` (`Utils.php:373-384`) builds the URL with `add_query_arg` and **no `esc_url`**; `charge_id` and `balance_transaction_id` (both webhook-derived) land in query args.

The decisive risk is `$status`: a dispute `status` value such as `lost"><script>...` (or any unexpected provider/string-tampered value) is concatenated into the order-note HTML and later rendered in WP-Admin order notes without escaping. `get_required_string` only casts to string; it does not sanitize.

**CLIENT equivalent — `WC_Payments_Order_Service`:**
- `mark_payment_dispute_created()` (`includes/class-wc-payments-order-service.php:539`) → `generate_dispute_created_note()` (line 2233).
- `mark_payment_dispute_closed()` (line 565) → `generate_dispute_closed_note()` (line 2279).
- `process_webhook_dispute_updated()` builds its note in the processing service (`class-wc-payments-webhook-processing-service.php:723-731`).

The created/closed builders wrap **every** note in `WC_Payments_Utils::esc_interpolated_html()`:
```php
// generate_dispute_closed_note(), order service lines 2296-2306
return sprintf(
    WC_Payments_Utils::esc_interpolated_html(
        __( 'Dispute has been closed with status %1$s. See <a>dispute overview</a> for more details.', 'woocommerce-payments' ),
        [ 'a' => '<a href="%2$s" target="_blank" rel="noopener noreferrer">' ]
    ),
    $status,            // %1$s
    $dispute_url        // %2$s
);
```
`esc_interpolated_html()` (`includes/class-wc-payments-utils.php:67-138`) splits the string on the `<a>` token and runs `esc_html()` over **every** non-tag segment (lines 130-135: `esc_html( array_shift( $string_queue ) ) . array_shift( $token_queue )`). Because the `%1$s`/`%2$s`/`%3$s`/`%4$s` placeholders live in those escaped segments, the runtime `$status`, `$amount`, `$reason`, `$due_by`, and the `$dispute_url` are all HTML-escaped when the final note is assembled. The `<a>` tag is the only thing emitted unescaped, and its `href` is a fixed template the developer controls. **So in the client, an injected `status` is neutralized.**

(Note one nuance: the client `process_webhook_dispute_updated` note at `class-wc-payments-webhook-processing-service.php:723` does NOT use `esc_interpolated_html` — but its only interpolated values are a fixed `$message` from an internal `switch` and the `$charge_id` URL via `add_query_arg`; no free webhook string flows into it. So even the client's un-escaped updated-note is not attacker-controlled, whereas core's created/closed notes interpolate the webhook `status` raw.)

**Verdict: REGRESSION.** The client systematically escapes dispute order-note content via `esc_interpolated_html()`; core dropped that wrapper and interpolates webhook-supplied `status` (and the URL into a raw `href`) without `esc_html`/`esc_url`. The escaping was present and correct in the oracle and was lost in the port. Webhook bodies are an external/forwarded input boundary, so this is a stored-XSS-shaped exposure in WP-Admin order notes.

**Also BC-RISK:** the note *text* differs from the oracle in structure (core notes carry `target="_blank" rel="noopener noreferrer"` inline and a 4th `transaction_id` URL param; client notes are assembled by `esc_interpolated_html` and `compose_dispute_url` with no `transaction_id`). The `order_note_exists()` dedupe in BOTH compares **exact** note string equality (core lines 608-623; client `order_note_exists`). Because the assembled strings differ from what the client wrote, a store migrating from client → core can get **duplicate dispute notes** for the same dispute (the core string won't match a pre-existing client-authored note). That is a behavioral/BC concern on top of the security one.

Recommended fix: wrap the assembled note in the equivalent escaping (port `esc_interpolated_html` or apply `esc_html()` to `$status`/`$amount`/`$reason`/`$due_by` and `esc_url()` to the dispute URL before/within the `sprintf`).

Confidence: **high** (read both core builders and the client `generate_dispute_*_note` + `esc_interpolated_html` implementation line-by-line; confirmed `get_required_string` does not sanitize and `wc_payments_legacy_admin_url` does not `esc_url`).
