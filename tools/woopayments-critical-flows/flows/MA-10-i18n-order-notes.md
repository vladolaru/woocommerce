# MA-10 — Localized WooPayments order notes · DETERMINISTIC

Native order notes must keep the WooPayments extension's merchant-facing localization behavior instead of writing hardcoded English strings.

## Fixtures

- Use the reference store as the extension localization oracle for the same note family.
- Target store can switch locale for the probe user.
- Use the French locale fixture from `i18n-notes-gate.sh`.
- Drive native order-note creation without sending real money.

## Layer D

Run `i18n-notes-gate.sh` against the target store. The gate is fail-closed: if the native order notes remain English under the French probe, the flow fails.

Required deterministic evidence:

- The probe captures native order notes in the French locale.
- The note text is translated and does not match the hardcoded English fallback.
- Debug output is clean for the target run.

## Layer A

No browser layer is required. The acceptance target is the stored merchant note text.

## Verdict

BLOCKED until `i18n-notes-gate.sh` records translated native order notes for the target store.
