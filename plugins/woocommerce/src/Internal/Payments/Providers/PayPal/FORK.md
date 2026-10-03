# Forked PayPal wallet code

| Item | Value |
|------|-------|
| Source | `woocommerce/woocommerce-paypal-payments`, branch `poc/wallet-in-core-build` at `0083204e7` (local, never pushed): `dev/develop` at `85d6179b7` plus five commits (module availability contract, guarded reads, main-file constant guards, webhook skip filter, Pay Later capability status) |
| Forked on | 2026-10-02 (the session's `tools/fork-wallet/fork.php`, run once; `supplement.py` adds the pieces listed below) |
| Kept | 19 module directories plus the plugin root; see `Wallet/modules.php` |
| Not forked | ppcp-applepay, ppcp-googlepay, ppcp-axo, ppcp-axo-block, ppcp-card-fields, ppcp-local-alternative-payment-methods, ppcp-order-tracking, ppcp-store-sync, ppcp-paypal-subscriptions, ppcp-fraud-protection, ppcp-abilities, ppcp-status-report, ppcp-uninstall (except the pieces under "Kept from dropped modules") |
| Namespace | `WooCommerce\PayPalCommerce\` → `Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\`; `…\Vendor\` → `Automattic\WooCommerce\Vendor\`. Prefixed in `lib/packages/` by Mozart: `inpsyde/modularity` 1.12.0, `psr/container` 1.1 and `psr/log` 1.1.4 (the extension's unprefixed PSR-3, rewritten to the prefixed name in the forked code) |
| JS | `client/paypal-wallet/modules/<module>/resources/`, built to `assets/client/paypal-wallet/` by `@woocommerce/paypal-wallet` (the entry set matches the extension's own build for the kept modules) |
| Shared with the extension | gateway IDs, options, `woocommerce_paypal_payments_*` hooks, `paypal/v1` routes, `ppcp-*` handles, inbox note source, constants, and the stored class names below; the list is the spec's appendix (`design-fork-and-trim.md`) |
| Version | `WalletProperties::EXTENSION_VERSION` (`4.1.3`); bump only when porting a migration |

## Keeping up with the extension

The extension clone is the read-only source. `tools/fork-wallet/path-map.json` maps every forked file to its core path; `tools/fork-wallet/drift-report.sh <extension clone> <since-ref> [<until-ref>]` lists extension commits since the fork point that touch mapped paths, grouped by core file, and marks the ones that touch a shared-contract name. Port or reject each by hand, then record the new fork point here. There is no automatic merge.

The three stored DTO files are always marked as contract: any upstream change to `modules/ppcp-settings/src/DTO/LocationStylingDTO.php`, `PayLaterMessagingDTO.php` or `OAuthConnectionDTO.php` must be mirrored into `Wallet/SerializedClasses/` in the same release (see below).

## Kept from dropped modules

Kept wallet code still reaches a few classes of dropped modules, which the earlier vendored mirror supplied for free. `tools/fork-wallet/supplement.py` adds them under the extension's own namespace paths, so no forked file changed:

- Fourteen contract stubs, final classes carrying only the gateway `ID`: Apple Pay, Google Pay, Axo, ten local payment methods (Bancontact, Blik, EPS, iDEAL, Multibanco, MyBank, OXXO, P24, PWC, Trustly) and Pay upon Invoice. They are the first entries of plan B's dropped-feature list: plan B replaces each `::ID` read with a literal or a constants class when its feature is cut.
- Six copies with behavior: the Apple Pay, Google Pay and Axo `PropertiesDictionary` helpers, the order tracking trait `TrackingAvailabilityTrait`, and the Pay upon Invoice helper and product status.
- Two JS files of the card-fields module (`Render.js`, `CardFieldsHelper.js`), imported by the button and save-payment-methods modules; they go with plan B's card cut.

## Stored class names (compatibility shim)

The wallet's settings data models save their data as it is, so the options `woocommerce-ppcp-data-styling`, `woocommerce-ppcp-data-paylater-messaging` and (for an hour during OAuth) `ppcp_oauth_connection_details` hold PHP objects, and a serialized object carries its class name. The extension reads and writes the same options, so `LocationStylingDTO`, `PayLaterMessagingDTO` and `OAuthConnectionDTO` keep the extension's names, `WooCommerce\PayPalCommerce\Settings\DTO\*`, byte for byte. They live in `Wallet/SerializedClasses/`, outside any PSR-4 match so Composer's optimized classmap skips them. `load.php` defines them with guards, before the module list is built, and aliases each to its name in the wallet namespace so forked code is unchanged.

This is a shim, not a design to keep: the extension is expected to store arrays and read either shape in a coming release. Once that release is the coexistence floor, core switches to arrays and deletes the directory.

Known residue: the Jetpack autoloader's manifest still lists the three classes (it scans PSR-4 roots as plain classmaps), so with the extension active its loader can serve core's copy. That is harmless only while the files stay identical to the extension's. The session and seller-status caches that hold entity objects are checked with `instanceof` by their readers and rebuild themselves, so they need no shim.

## Conventions until plan B lifts them

`Wallet/` is excluded from core's phpcs and PHPStan runs (it keeps the extension's style); the JS package's ESLint keeps inherited code at warning level for 18 rules. Three settings filters stay in `PayPalWalletBootstrap` (the two empty gateway groups and the removal of the card button from the payment methods data) because the kept Settings module still lists card and local payment methods from static ID lists; plan B's card and APM cuts remove them. The lint ledger in the session folder lists what is still at that level.
