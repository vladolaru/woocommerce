# PayPal wallet fork tooling

Maintenance tooling for the PayPal wallet code forked from the PayPal Payments extension into `src/Internal/Payments/Providers/PayPal/Wallet/`. `src/Internal/Payments/Providers/PayPal/FORK.md` describes the fork and the procedure for tracking the extension. Every script finds its sibling files relative to its own location, so it runs from any working directory.

The fork point is extension commit `0083204e7`. A real `fork.php` run writes its audit of what a rename cannot prove safe to `audit.txt` in this directory (not tracked), next to `path-map.json`; `--dry-run` writes neither.

| File | What it does |
|------|--------------|
| `drift-report.sh` | Lists extension commits since a ref that touch forked paths, grouped by core file, and marks the ones that touch a shared-contract name (the list items of the appendix). Files dropped in core come last. |
| `contract-list.sh` | Regenerates the shared-contract appendix (hooks, options, REST routes, handles, gateway IDs, meta keys) from the extension clone. Line 1 names the extension commit and carries no timestamp, so regenerating at the same commit gives the same file. |
| `path-map.json` | Maps every forked extension path to its core path. `null` marks a file dropped in core. The JS rows point at the admin and blocks clients, where the JS lives since the transitional wallet package was folded into them; `fork.php` and `supplement.py` still name that package's directory because they record the original run. |
| `module-map.json` | Maps each kept extension module to its segment under `Wallet/`. Read by `fork.php`. |
| `fork.php` | The one-shot fork. Copies the kept modules, rewrites namespaces and the text domain, and writes `path-map.json`. Kept as the record; do not run it again on a forked tree: a re-run would overwrite `path-map.json` without the entries `supplement.py` added. `--dry-run` counts and audits without writing any file. |
| `supplement.py` | Adds classes of dropped modules that kept code still reaches, and records them in `path-map.json`. Its lists are empty, so it adds nothing; it stays as the record of how those files got in. |

## Usage

```bash
# Extension commits since the fork point that touch forked paths
bash bin/paypal-wallet-fork/drift-report.sh [--appendix <path>] <extension clone> <since-ref> [<until-ref>]

# Regenerate the contract appendix next to FORK.md; the script writes the file itself and leaves it untouched on failure
bash bin/paypal-wallet-fork/contract-list.sh [--out <path>|--out -] <extension clone>

# The one-shot fork and its supplement (already applied; the supplement's lists are empty)
php bin/paypal-wallet-fork/fork.php --extension=<extension clone> --core=<core clone> [--dry-run]
python3 bin/paypal-wallet-fork/supplement.py <extension clone> <core clone>
```

`contract-list.sh --out` defaults to the same appendix path (`--out -` prints to stdout); never redirect its output into the appendix. `--appendix` defaults to `src/Internal/Payments/Providers/PayPal/contract-appendix.md`. `<until-ref>` defaults to `dev/develop`. Run the scripts from `plugins/woocommerce`.
