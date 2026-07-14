# MA-05 — Search transactions · HYBRID (A + D)

Guards transaction search: the search field must exist and return exactly the transactions matching the queried customer name or order number. The regression risk is a native search box that is missing, ignores input, or returns unrelated rows — leaving the merchant unable to find a specific customer's payment during a support interaction.

## Fixtures (both stores)

- Connected test account; at least 3 transactions per store from DIFFERENT customers, one of them a distinctively named customer (e.g. billing name `MA05 Searchable`) created via a driven checkout or Test Lab mock data.
- Record that customer's transaction ID and order number.

## Layer A — agent-driven browser

BOTH stores (reference `admin.php?page=wc-admin&path=/payments/transactions`, target `admin.php?page=wc-admin&path=/woopayments/transactions`):

1. Open Payments → Transactions as admin.
2. **Confirm the search field is discoverable** on the list toolbar.
3. Search for `MA05 Searchable`. **Confirm the results narrow to only that customer's transaction(s)** — the other customers' rows must not appear.
4. Search for the recorded order number. **Confirm the matching transaction is returned.**
5. Search for a nonsense term (`zzz-no-match-ma05`). **Confirm an honest empty-result state renders** (not an error, not the full unfiltered list).
6. Clear the search; confirm the full list returns. End state: read-only, full list restored on both stores.

## Layer D — deterministic state assertion

- Call the transactions list endpoint via internal REST with the search parameter set to the fixture customer name; assert every returned row belongs to that customer and the known transaction ID is present.
- Repeat with the order number; assert the same transaction is returned.
- Assert the nonsense term returns zero rows (not a fallback to the unfiltered list).
- Compare reference vs target: same match/no-match semantics for the same fixture data.

Deterministic exerciser: NOT YET WIRED — assertions above are the contract for the future flows/MA-05-*.sh.

Agent oracle mode: comparable (dual-store; reference is the golden oracle).
