# Vendored WooCommerce PayPal Payments

This directory is a verbatim copy of the built WooCommerce PayPal Payments extension. It is never edited by hand; a change here is a re-vendor recorded below.

| Field          | Value                                                                 |
|----------------|-----------------------------------------------------------------------|
| Source         | https://github.com/woocommerce/woocommerce-paypal-payments            |
| Branch, commit | `poc/wallet-in-core-build` (local, never pushed) at `85d6179b7`, which is `dev/develop` at vendoring time |
| Plugin version | 4.1.3 (header), development build                                     |
| Built with     | `composer install --no-dev --prefer-dist`, `npm ci`-equivalent install, `npm run build`; `@inpsyde/playwright-utils` (dev-only e2e, GitHub Packages) was uninstalled before the build |
| Copied with    | `rsync -a --delete --exclude-from=.distignore --exclude=.git --exclude=node_modules` |
| Vendored on    | 2026-10-02                                                            |

Build note: `npm ci` needs a GitHub Packages token for the `@inpsyde` scope, so the build ran `npm uninstall @inpsyde/playwright-utils` (which installs everything else from `package-lock.json`), then `npm run build`, then `git checkout package.json package-lock.json`. The extension clone's `git status --short` was empty afterwards, so `poc/wallet-in-core-build` is a clean pointer at `85d6179b7`. A GitHub Packages token for the `@inpsyde` scope would make this workaround unnecessary.

Rules: the shell in the parent directory (`PayPalWalletBootstrap`) is the only code that loads this tree; PHPCS, PHPStan and the composer classmap exclude it; the placement test keeps `WooCommerce\PayPalCommerce` declarations inside it.
