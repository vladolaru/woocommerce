# ON-03 — Manual install + setup · AGENT

Guards the install → badge → setup journey. On the reference store this is the extension's literal path: WooPayments installed/activated from the Plugins page, the "Finish setup" badge/notice appears, and the setup wizard completes. On the native target there is no plugin to install — the equivalent journey is discovering the built-in WooPayments setup entry (Settings → Payments → WooPayments onboarding entry) and completing test-mode onboarding. That visual divergence is acceptable; what may not regress is the functional journey: a setup affordance is discoverable from where the merchant lands, the wizard/NOX flow completes on a local test account (no KYC needed — this row is fully exercisable locally), the Jetpack/WordPress.com connection step is handled, and the card method is live at checkout afterwards.

## Fixtures (both stores)

- WooPayments reset to a not-onboarded state (WCPay Dev Tools: disconnect/clear account cache), aligned across stores per HARNESS.md store-config discipline.
- Reference: WooPayments extension present but freshly (re)activated so the setup badge/notice shows. Target: native WooPayments not yet onboarded.
- Local Transact platform reachable; a simple in-stock product for the checkout check.

## Layer A — agent-driven browser

Reference store:

1. WP Admin → Plugins. Confirm WooPayments is installed; activate (or deactivate/reactivate) it. **Confirm the setup badge/notice renders** with a "Finish setup" affordance.
2. Follow it into the setup wizard.

Target store:

3. WP Admin → WooCommerce → Settings → Payments. **Confirm the WooPayments setup entry is discoverable** without any plugin install (native gateway, enable/setup call-to-action).
4. Start setup from that entry.

BOTH stores:

5. Complete Jetpack/WordPress.com connection if prompted; take the test-mode onboarding path. **Confirm the wizard/NOX flow completes** and returns to a configured Settings → Payments state.
6. Add the product to the cart and open checkout. **Confirm the WooPayments card method renders and is selectable.**

End state: both stores finish onboarded in test mode with the card method live at checkout; the differing entry points are recorded as visual divergence, not regression.

Agent oracle mode: comparable (dual-store; reference is the golden oracle).
