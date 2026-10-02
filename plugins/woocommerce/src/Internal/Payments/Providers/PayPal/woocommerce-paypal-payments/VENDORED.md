# Vendored WooCommerce PayPal Payments

This directory is a verbatim copy of the built WooCommerce PayPal Payments extension. It is never edited by hand; a change here is a re-vendor recorded below.

| Field          | Value                                                                 |
|----------------|-----------------------------------------------------------------------|
| Source         | https://github.com/woocommerce/woocommerce-paypal-payments            |
| Branch, commit | `poc/wallet-in-core-build` (local, never pushed) at `0083204e7`; five commits on top of `85d6179b7`, which is `dev/develop` at first vendoring |
| Plugin version | 4.1.3 (header), development build                                     |
| Built with     | `composer install --no-dev --prefer-dist`, `npm ci`-equivalent install, `npm run build`; `@inpsyde/playwright-utils` (dev-only e2e, GitHub Packages) was uninstalled before the build |
| Copied with    | `rsync -a --delete --exclude-from=.distignore --exclude=.git --exclude=node_modules` |
| Vendored on    | 2026-10-02, second time (first at `85d6179b7`)                        |

Build note (first vendoring): `npm ci` needs a GitHub Packages token for the `@inpsyde` scope, so the build ran `npm uninstall @inpsyde/playwright-utils` (which installs everything else from `package-lock.json`), then `npm run build`, then `git checkout package.json package-lock.json`. The extension clone's `git status --short` was empty afterwards, so `poc/wallet-in-core-build` is a clean pointer at `85d6179b7`. A GitHub Packages token for the `@inpsyde` scope would make this workaround unnecessary.

Changes over `dev/develop` (the five build-branch commits, each a candidate upstream PR): a root-level `ppcp.module-availability` service (`src/ModuleAvailability.php`) that the settings, gateway, compat, button and webhooks wiring use to tolerate absent optional modules, with `<prefix>.available` registered by every optional module; `! defined()` guards on the six main-file constants (R26); a `woocommerce_paypal_payments_skip_webhook_unregister_on_deactivate` filter honoured by the webhook teardown (R27); a `wcgateway.apm-capability-status` service so the Pay Later messaging feature reads its capability without the local APM module. No JS changes; the built assets are those of the first vendoring.

Rules: the shell in the parent directory (`PayPalWalletBootstrap`) is the only code that loads this tree; PHPCS, PHPStan and the composer classmap exclude it; the placement test keeps `WooCommerce\PayPalCommerce` declarations inside it.
