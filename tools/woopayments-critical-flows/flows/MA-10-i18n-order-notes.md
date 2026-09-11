# MA-10 — Localized WooPayments order notes · DETERMINISTIC

Native order notes must keep the WooPayments extension's merchant-facing localization behavior instead of writing hardcoded English strings.

## Fixtures

- Use the reference store as the extension localization oracle for the same note family.
- Target store can switch locale for the probe user.
- Use the German (`de_DE`) locale fixture from `i18n-notes-gate.sh`.
- Drive native order-note creation without sending real money.

## Layer D

Run `i18n-notes-gate.sh` against the target store. The gate is fail-closed: if the native order notes remain English under the German probe, the flow fails.

Required deterministic evidence:

- The probe captures native order notes in the German locale.
- The note text is translated and does not match the hardcoded English fallback.
- Debug output is clean for the target run.

## Layer A

No browser layer is required. The acceptance target is the stored merchant note text.

## Latest runner result

`BLOCKED` on 2026-07-16. The reference extension's same-note-family oracle is not wired, so the comparable reference row cannot earn a verdict. On the target, the native implementation probe generated localized, deduplicated charge, refund, and dispute merchant notes with each deterministic marker bound to the expected provider ID in the same note. The marker-bounded target debug log stayed clean, but the installed German WooCommerce release catalog does not translate the exact exercised charge, reason-bearing refund, or dispute-created message IDs. Exact language restoration and owned temporary translation-probe cleanup passed; the target returned to `en_US` with the probe absent.

Append-only evidence: `evidence/runs/20260716T105904Z-7160-partial/`.
