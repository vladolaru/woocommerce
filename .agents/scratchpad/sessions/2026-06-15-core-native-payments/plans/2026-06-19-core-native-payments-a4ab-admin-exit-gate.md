---
session: 2026-06-15-core-native-payments
type: plan
by: codex
created: 2026-06-19 23:18
target: A4ab widened native admin exit gate
reconciles:
  - ../supervisor-prompt-2026-06-18-2344-N12.md
  - ../analysis-a4ab-admin-exit-gate.md
  - ../analysis-a4z-admin-exit-residuals.md
  - ../staging-log.md
last_updated: 2026-06-20 00:41
status: final
---

# A4ab Widened Admin Exit Gate Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use $subagent-driven-development (recommended) or $executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Build and run the widened A4/N12 native WooPayments admin exit gate so admin readiness is based on merchant-reachable browser/runtime evidence, not only source/chunk assertions.

**Architecture:** Keep WooPayments admin surfaces under the Core Settings > Payments provider route seam. Treat the existing `a4-admin-surface-gate.py` as the source/chunk guardrail and add a Playwriter-driven browser matrix that records target/reference route content, failed responses, loaded chunks, screenshots, and runtime diagnostics. Do not change WPCOM, do not touch the standalone reference plugin, and do not flip native admin readiness in this slice unless a follow-up A5 readiness plan explicitly does so after the gate is green.

**Tech Stack:** Ignored local harness scripts in `tools/woopayments-merge`, Playwriter via `npx --yes playwriter@latest`, WooCommerce admin React routes, Docker/WP debug logs, JSON evidence in `.agents/scratchpad/sessions/2026-06-15-core-native-payments/data/`, and review-agent gate critique.

---

## File Map

- Create `tools/woopayments-merge/a4-admin-browser-gate.playwriter.mjs`: Playwriter browser matrix script for target and reference native/admin routes. This harness path is gitignored and should not be committed unless the user later asks to publish harness improvements.
- Modify `tools/woopayments-merge/a4-admin-surface-gate.py` only if source verification exposes a missing static contract that should fail closed. Do not weaken existing assertions or mask product bugs.
- Write evidence to `.agents/scratchpad/sessions/2026-06-15-core-native-payments/data/a4ab-admin-browser-gate.json` and screenshots under `.agents/scratchpad/sessions/2026-06-15-core-native-payments/data/a4ab/`.
- Update `implementation-log.md`, `staging-log.md`, `spec-conformance-baseline.md`, and this plan after the gate runs.

## Task 1: Define the Exit-Gate Matrix

**Files:**
- Create: `tools/woopayments-merge/a4-admin-browser-gate.playwriter.mjs`

- [x] **Step 1: Add the route matrix skeleton.**

Create `tools/woopayments-merge/a4-admin-browser-gate.playwriter.mjs` with this initial matrix and helper shape:

```javascript
const fs = require( 'node:fs' );
const path = require( 'node:path' );

const targetBase = 'http://store8889.localhost:8889/wp-admin/admin.php?page=wc-settings&tab=checkout&path=';
const referenceBase = 'http://localhost:8082/wp-admin/admin.php?page=wc-admin&path=';
const dataDir = '/Users/vladolaru/Work/a8c/woocommerce-develop-2/.agents/scratchpad/sessions/2026-06-15-core-native-payments/data/a4ab';
const evidencePath = '/Users/vladolaru/Work/a8c/woocommerce-develop-2/.agents/scratchpad/sessions/2026-06-15-core-native-payments/data/a4ab-admin-browser-gate.json';

const surfaces = [
    { id: 'settings', target: targetBase + encodeURIComponent( '/woopayments/settings' ), reference: 'http://localhost:8082/wp-admin/admin.php?page=wc-settings&tab=checkout&section=woocommerce_payments', targetTokens: [ 'WooPayments', 'General', 'Payment methods', 'Transactions', 'Payouts', 'Account notifications', 'Fraud protection', 'Advanced settings' ], referenceTokens: [ 'WooPayments', 'General', 'Payment methods', 'Transactions', 'Payouts', 'Account notifications', 'Fraud protection', 'Advanced settings' ] },
    { id: 'settings-express-woopay', target: targetBase + encodeURIComponent( '/woopayments/settings/express-checkout/woopay' ), reference: 'http://localhost:8082/wp-admin/admin.php?page=wc-settings&tab=checkout&section=woocommerce_payments&method=woopay', targetTokens: [ 'WooPay', 'Appearance', 'Preview', 'Save changes' ], referenceTokens: [ 'WooPay', 'Appearance', 'Preview', 'Save changes' ] },
    { id: 'settings-express-payment-request', target: targetBase + encodeURIComponent( '/woopayments/settings/express-checkout/payment_request' ), reference: 'http://localhost:8082/wp-admin/admin.php?page=wc-settings&tab=checkout&section=woocommerce_payments&method=payment_request', targetTokens: [ 'Apple Pay / Google Pay', 'Button appearance', 'Preview', 'Save changes' ], referenceTokens: [ 'Apple Pay / Google Pay', 'Button appearance', 'Preview', 'Save changes' ] },
    { id: 'settings-express-amazon-pay', target: targetBase + encodeURIComponent( '/woopayments/settings/express-checkout/amazon_pay' ), reference: 'http://localhost:8082/wp-admin/admin.php?page=wc-settings&tab=checkout&section=woocommerce_payments&method=amazon_pay', targetTokens: [ 'Amazon Pay', 'Button appearance', 'Preview', 'Save changes' ], referenceTokens: [ 'Amazon Pay', 'Button appearance', 'Preview', 'Save changes' ] },
    { id: 'fraud-protection', target: targetBase + encodeURIComponent( '/woopayments/settings/fraud-protection' ), reference: 'http://localhost:8082/wp-admin/admin.php?page=wc-settings&tab=checkout&section=woocommerce_payments&view=advanced_fraud_protection', targetTokens: [ 'Advanced fraud protection', 'Filter configuration', 'Address mismatch', 'CVC Verification', 'Save changes' ], referenceTokens: [ 'Advanced fraud protection', 'Filter configuration', 'Address mismatch', 'CVC Verification', 'Save changes' ] },
    { id: 'overview', target: targetBase + encodeURIComponent( '/woopayments/overview' ), reference: referenceBase + encodeURIComponent( '/payments/overview' ), targetTokens: [ 'Overview', 'Balance', 'Payouts', 'Account details', 'Dispute readiness', 'Inbox', 'WooPayments settings' ], referenceTokens: [ 'Overview', 'Balance', 'Payouts', 'Account details', 'Dispute readiness', 'Inbox' ] },
    { id: 'payouts', target: targetBase + encodeURIComponent( '/woopayments/payouts' ), reference: referenceBase + encodeURIComponent( '/payments/deposits' ), targetTokens: [ 'Payout history', 'Status', 'Amount', 'Date' ], referenceTokens: [ 'Payouts', 'Status', 'Amount', 'Date' ] },
    { id: 'transactions', target: targetBase + encodeURIComponent( '/woopayments/transactions' ), reference: referenceBase + encodeURIComponent( '/payments/transactions' ), targetTokens: [ 'Transactions', 'Search', 'Export', 'Date', 'Amount' ], referenceTokens: [ 'Transactions', 'Search', 'Export', 'Date', 'Amount' ] },
    { id: 'uncaptured', target: targetBase + encodeURIComponent( '/woopayments/transactions?view=uncaptured' ), reference: referenceBase + encodeURIComponent( '/payments/transactions?tab=uncaptured' ), targetTokens: [ 'Uncaptured transactions', 'Capture', 'Cancel' ], referenceTokens: [ 'Uncaptured transactions', 'Capture', 'Cancel' ], allowEmptyState: true },
    { id: 'disputes', target: targetBase + encodeURIComponent( '/woopayments/disputes' ), reference: referenceBase + encodeURIComponent( '/payments/disputes' ), targetTokens: [ 'Disputes', 'Status', 'Amount', 'Respond by' ], referenceTokens: [ 'Disputes', 'Status', 'Amount', 'Respond by' ], allowEmptyState: true },
    { id: 'documents', target: targetBase + encodeURIComponent( '/woopayments/documents' ), reference: referenceBase + encodeURIComponent( '/payments/documents' ), targetTokens: [ 'Documents' ], referenceTokens: [ 'Documents' ], allowEmptyState: true },
    { id: 'reports-fees', target: targetBase + encodeURIComponent( '/woopayments/reports?report=fees' ), reference: referenceBase + encodeURIComponent( '/payments/reports?report=fees' ), targetTokens: [ 'Reports', 'Fees', 'Date' ], referenceTokens: [ 'Reports', 'Fees', 'Date' ], allowEmptyState: true },
    { id: 'card-readers', target: targetBase + encodeURIComponent( '/woopayments/card-readers' ), reference: referenceBase + encodeURIComponent( '/payments/card-readers' ), targetTokens: [ 'Connected card readers' ], referenceTokens: [ 'Connected card readers' ], allowEmptyState: true },
    { id: 'capital', target: targetBase + encodeURIComponent( '/woopayments/loans' ), reference: referenceBase + encodeURIComponent( '/payments/loans' ), targetTokens: [ 'Capital Loans' ], referenceTokens: [ 'Capital Loans' ], allowEmptyState: true },
];

const viewports = [
    { id: 'desktop', width: 1440, height: 1100 },
    { id: 'mobile', width: 390, height: 844 },
];
```

Run: `node --check tools/woopayments-merge/a4-admin-browser-gate.playwriter.mjs`

Expected: PASS syntax. If this file is untracked, keep it uncommitted unless explicitly publishing harness updates.

- [x] **Step 2: Record gate scope in the script header.**

Add a top comment stating that this script is a browser/runbook gate, not a source gate, and that red results must become product fixes or tracked A4 follow-up slices. The comment should also state that WPCOM access is forbidden and the script uses only local target/reference stores.

Run: `node --check tools/woopayments-merge/a4-admin-browser-gate.playwriter.mjs`

Expected: PASS syntax.

## Task 2: Implement the Playwriter Browser Gate

**Files:**
- Modify: `tools/woopayments-merge/a4-admin-browser-gate.playwriter.mjs`

- [x] **Step 1: Add page setup and response collection.**

Append this helper code after the route matrix:

```javascript
function ensureDir( dir ) {
    fs.mkdirSync( dir, { recursive: true } );
}

function interestingFailure( response ) {
    const status = response.status();
    if ( status < 400 ) {
        return false;
    }
    const url = response.url();
    return ! /favicon\.ico|load-scripts\.php.*ver=/.test( url );
}

async function getOrCreatePage() {
    if ( state.page && ! state.page.isClosed() ) {
        return state.page;
    }
    state.page = context.pages().find( ( candidate ) => candidate.url() === 'about:blank' ) || await context.newPage();
    return state.page;
}
```

Run: `node --check tools/woopayments-merge/a4-admin-browser-gate.playwriter.mjs`

Expected: PASS syntax.

- [x] **Step 2: Add the route checker.**

Append this checker:

```javascript
async function checkRoute( page, store, surface, viewport ) {
    const url = store === 'target' ? surface.target : surface.reference;
    const expectedTokens = store === 'target' ? surface.targetTokens : surface.referenceTokens;
    const failedResponses = [];
    const consoleIssues = [];
    const responseHandler = ( response ) => {
        if ( interestingFailure( response ) ) {
            failedResponses.push( { status: response.status(), url: response.url() } );
        }
    };
    const consoleHandler = ( message ) => {
        const text = message.text();
        if ( /error|warning|fatal|exception/i.test( text ) && ! /JQMIGRATE|Permissions policy violation: unload/i.test( text ) ) {
            consoleIssues.push( { type: message.type(), text } );
        }
    };

    page.on( 'response', responseHandler );
    page.on( 'console', consoleHandler );
    await page.setViewportSize( { width: viewport.width, height: viewport.height } );
    await page.goto( url, { waitUntil: 'domcontentloaded', timeout: 45000 } );
    await waitForPageLoad( { page, timeout: 20000, minWait: 1000 } );
    const mainText = await page.locator( 'body' ).innerText( { timeout: 20000 } );
    const missingTokens = expectedTokens.filter( ( token ) => ! mainText.includes( token ) );
    const scripts = await page.locator( 'script[src]' ).evaluateAll( ( nodes ) => nodes.map( ( node ) => node.getAttribute( 'src' ) ).filter( Boolean ) );
    const screenshotPath = path.join( dataDir, `${ store }-${ surface.id }-${ viewport.id }.png` );
    await page.screenshot( { path: screenshotPath, fullPage: true, scale: 'css' } );
    page.off( 'response', responseHandler );
    page.off( 'console', consoleHandler );

    return {
        store,
        surface: surface.id,
        viewport: viewport.id,
        url: page.url(),
        expectedTokens,
        missingTokens,
        failedResponses,
        consoleIssues,
        scripts: scripts.filter( ( src ) => /settings-payments-woopayments|woocommerce-payments\/dist|wcpay-/i.test( src ) ),
        screenshotPath,
        passed: missingTokens.length === 0 && failedResponses.length === 0 && consoleIssues.length === 0,
    };
}
```

Run: `node --check tools/woopayments-merge/a4-admin-browser-gate.playwriter.mjs`

Expected: PASS syntax.

- [x] **Step 3: Add the runner and fail-closed exit.**

Append this runner:

```javascript
ensureDir( dataDir );
const page = await getOrCreatePage();
const startedAt = new Date().toISOString();
const results = [];

for ( const viewport of viewports ) {
    for ( const surface of surfaces ) {
        results.push( await checkRoute( page, 'target', surface, viewport ) );
        results.push( await checkRoute( page, 'reference', surface, viewport ) );
    }
}

const failures = results.filter( ( result ) => ! result.passed );
const evidence = {
    gate: 'a4ab-admin-browser-gate',
    startedAt,
    finishedAt: new Date().toISOString(),
    surfaces: surfaces.map( ( surface ) => surface.id ),
    viewports: viewports.map( ( viewport ) => viewport.id ),
    results,
    failures,
};

fs.writeFileSync( evidencePath, `${ JSON.stringify( evidence, null, 2 ) }\n` );
console.log( JSON.stringify( { evidencePath, checked: results.length, failures: failures.length }, null, 2 ) );
if ( failures.length > 0 ) {
    throw new Error( `A4 admin browser gate failed with ${ failures.length } route checks failing. See ${ evidencePath }` );
}
```

Run: `node --check tools/woopayments-merge/a4-admin-browser-gate.playwriter.mjs`

Expected: PASS syntax.

## Task 3: Run Source, Build, Browser, And Log Gates

**Files:**
- Read/write evidence only under `.agents/scratchpad/sessions/2026-06-15-core-native-payments/data/`

- [x] **Step 1: Run the current source/chunk gate.**

Run:

```bash
python3 tools/woopayments-merge/a4-admin-surface-gate.py --repo /Users/vladolaru/Work/a8c/woocommerce-develop-2 --plugin-repo /Users/vladolaru/Work/a8c/woocommerce-payments --out .agents/scratchpad/sessions/2026-06-15-core-native-payments/data/a4ab-admin-surface-gate.json
```

Expected: PASS. A failure here is source/chunk drift and must be fixed or tracked before the browser gate can be trusted.

- [x] **Step 2: Run the browser matrix through Playwriter.**

Run:

```bash
SESSION_ID="$(npx --yes playwriter@latest session new | tail -n 1)"
npx --yes playwriter@latest -s "$SESSION_ID" -f tools/woopayments-merge/a4-admin-browser-gate.playwriter.mjs --timeout 300000
```

Expected: either PASS with `failures: 0` or a concrete JSON failure list in `data/a4ab-admin-browser-gate.json`. The script writes JSON incrementally after each route so a timeout is a gate-harness issue with partial evidence, not a reason to lose the browser run. Treat any failed response, missing token, or unexpected console issue as a gate failure unless source evidence proves it is a reference-compatible sentinel.

- [x] **Step 3: Scan local runtime logs for the browser window.**

Run after the browser gate:

```bash
docker ps --format '{{.Names}}' | rg 'wordpress|wpcom' > "$TMPDIR/a4ab-containers.txt"
while read -r container; do docker logs --since 10m "$container" 2>&1 | rg -n 'PHP (Fatal|Warning|Notice|Deprecated|Parse)|Uncaught|Stack trace|Database error|HTTP/[0-9.]+\" 4[0-9]{2}|HTTP/[0-9.]+\" 5[0-9]{2}' && echo "FAIL: diagnostics in $container"; done < "$TMPDIR/a4ab-containers.txt"
```

Expected: no output except container names written to `$TMPDIR/a4ab-containers.txt`. If this reports known pre-existing noise, verify timestamp/source and record it; do not ignore fresh WooPayments diagnostics.

- [x] **Step 4: Inspect screenshot evidence.**

Use `view_image` or Playwriter resized screenshots for at least settings, overview, transactions, disputes, documents/reports, and one mobile route. Check for visible overlap, clipped controls, browser-default buttons, missing styling, and route-level blank states.

Expected: either no layout regressions or a source-backed failure added to the next product fix list.

## Task 4: Gate Review And Failure Disposition

**Files:**
- Modify docs only unless the gate exposes a bounded product bug.

- [x] **Step 1: Dispatch review agents over the gate, not just product code.**

Ask one reviewer to critique gate coverage against N12 and one reviewer to critique browser evidence reliability. The review prompt must include `data/a4ab-admin-browser-gate.json`, `data/a4ab-admin-surface-gate.json`, N12, and the current `a4-admin-browser-gate.playwriter.js` script. Findings must be source-backed before changing product code.

Expected: no Critical/High gate-coverage findings before A4 can be treated as exit-gate green.

- [x] **Step 2: If the gate is red, choose the next broad product slice.**

If failures cluster by surface, create the next canonical A4 plan using the true surface name, such as A4ac settings visual parity, A4ac money-movement visual parity, or A4ac route/account-state parity. If failures are isolated and bounded, fix them in A4ab with RED tests and rerun the affected route plus the full final gate.

Expected: no silent waivers. Every red route/token/log item is either fixed with evidence or recorded as a tracked A4 follow-up before any readiness claim.

- [x] **Step 3: If the gate is green, record A4 exit posture without flipping A5 readiness.**

Update `implementation-log.md`, `staging-log.md`, and `spec-conformance-baseline.md` with the source gate, browser matrix, screenshots, log scans, review disposition, and explicit caveat that A5 readiness still requires a separate cutover/readiness slice before changing `FILTER_NATIVE_ADMIN_SURFACES_READY`.

Expected: docs record A4/N12 browser-gate green or fail-closed red. No push.

## Verification Checklist

- `node --check tools/woopayments-merge/a4-admin-browser-gate.playwriter.mjs`
- `python3 tools/woopayments-merge/a4-admin-surface-gate.py --repo /Users/vladolaru/Work/a8c/woocommerce-develop-2 --plugin-repo /Users/vladolaru/Work/a8c/woocommerce-payments --out .agents/scratchpad/sessions/2026-06-15-core-native-payments/data/a4ab-admin-surface-gate.json`
- `npx --yes playwriter@latest -s "$SESSION_ID" -f tools/woopayments-merge/a4-admin-browser-gate.playwriter.mjs --timeout 300000`
- Docker log scan for target/reference/local-WPCOM containers after the browser window.
- Review-agent gate critique resolved or recorded fail-closed.
- `git status --short --untracked-files=all` before any product commit; do not stage `.agents` or ignored harness evidence.

## Exit Criteria

A4ab is complete when the widened gate either passes with recorded source/browser/log/review evidence or fails closed with every finding verified and assigned to a concrete next A4 product slice. A4ab by itself does not authorize cutover readiness unless a separate A5 readiness plan explicitly flips the admin-readiness default after the standing stage-boundary gate.

## Closeout

A4ab closed green after two product fixes and one harness hardening pass. Product commits: `410ad0e3ec` (`fix(payments): close native admin gate regressions`) and `c293d2f1cc` (`chore(payments): add native admin gate changelog`), with git range `44c9640bac...c293d2f1cc`. The final Playwriter matrix wrote `data/a4ab-admin-browser-gate.json` with 57 checks and 0 failures; all expected target lazy chunks were observed through resource timing except the explicitly noted top-level WC settings route, and target `debug.log` stayed empty. A5/native admin readiness remains fail-closed.
