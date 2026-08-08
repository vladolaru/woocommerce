# WooPayments pilot calibration notes

Durable, public-safe record of calibration blockers and ledger revisions
that the evidence JSON schema cannot carry. Newest entries first.

## 2026-08-08 — Multi-currency empty-selection guard

Stable reference: `calibration-notes:2026-08-08:multi-currency-empty-selection-guard`.

The empty-selection submit guard is the one carve-out in the 2026-08-08
decision: built rather than dispositioned away. Native's "Add enabled
currencies" modal disabled its primary action only while a save was in flight,
so a merchant who cleared the list and pressed a button labelled "Update
selected" removed every additional enabled currency behind a success notice.
The action is now non-actionable while nothing is checked, with an
`aria-describedby` hint saying why, and the row takes its planned rewrite
disposition against the native settings screen.

The guard is deliberately narrow, which answers the row's own deferral packet
rather than ignoring it. That packet argues Core "intentionally permits a
default-only submission so merchants can remove every additional currency", and
that is true — so `saveEnabledCurrencies` is untouched and the per-row Remove
action can still take a store down to its default alone. Only the bulk
remove-everything path through a modal titled "Add enabled currencies" is
closed, and that path was indistinguishable from an accidental submission.

Three of this family's rows are not here, for two separate reasons that are
worth keeping apart.

**The catalog is one code wide.** The multi-select row and the
selection-persistence row need at least two selectable non-default currencies
and four respectively. This store's `available` set is `USD` and `EUR`, and
that is not a stale cache: deleting `wcpay_multi_currency_cached_currencies`
and re-reading the currencies route refetches and re-caches **zero** currencies
with `errored: false`. `MultiCurrencyStateBuilder::build()` composes `available`
from the store default plus the cached provider rates plus already-enabled
currencies carrying a manual rate, and every route that could widen it
validates its codes against `available` first, so no product path adds a third
currency and there is no filter or registrar hook to inject rates. Both rows'
unlock decisions name a deterministic catalog as an environment prerequisite;
it is genuinely still unmet, so they keep their `PILOT-NATIVE-READINESS`
deferrals untouched. The remaining provider-free option would be a write route
in the fail-closed read-only diagnostics test plugin, which is not worth
widening for two rows.

**Enabling automatic geolocation switching bricks the store.** Writing the
proof for the geolocation opt-in row uncovered a native defect: with
`wcpay_multi_currency_enable_auto_currency` set to `yes`, every request dies of
memory exhaustion, WP-CLI included, and the settings screen that turned it on
cannot load to turn it off. `MultiCurrencyAsyncPriceRendererController::register()`
runs from `WooCommerce::init_hooks()`, inside `WooCommerce::__construct()`,
before the singleton is assigned; its `has_active_session()` argument calls
`WC()`, which constructs a second `WooCommerce`, whose `init_hooks()` calls
`register()` again. The recursion is unbounded rather than merely expensive — a
2 GB limit exhausts too — and the automatic-switching check is the only guard
standing between a normal store and the cycle. Bisected by stubbing
`register()`, which lets the store boot with the option still on; a depth guard
in `MultiCurrencySelectedCurrencyController::handle_geolocation_init()` never
fired, ruling that path out. The store was restored with
`wp --skip-plugins --skip-themes option update ... no` and verified healthy.

The opt-in row therefore stays deferred: its packet requires proving the
setting persists and survives a full reload, and doing that on current native
code takes the store down. The geolocation preview row was to be retired
against it, and retiring a contract against a broken native behaviour would be
a false pairing, so it travels with it.

**The refusal was silent, and the review round caught it.** The first version
disabled the control and described it with `aria-describedby`, which reaches a
merchant who navigates onto the button — and nobody else. Probed live: clearing
the last currency leaves focus on the checkbox just unchecked, both WordPress
announcement regions empty, and the newly unavailable button two tab stops away
past Cancel. That is a state change with no status message, so the guard now
calls `speak()` on the false-to-true edge, sharing one string with the visible
hint. Edge only: a modal opened on an already-empty selection says nothing
extra, because the hint is read with the dialog. An `aria-live` region on the
hint would have announced twice — once on insert, once when focus reaches the
`aria-describedby` target — and cannot work at all while the hint is
conditionally rendered. The reviewer also confirmed the announcement is
reachable rather than merely present: this dialog is `role="dialog"` with no
`aria-modal`, so the body-level polite region is not hidden from assistive
technology, which is the failure mode that would have left the fix cosmetic and
every test green.

The oracle was mutation-checked in three directions on the closed bytes.
Reverting the built bundle's `disabled` prop to the saving flag alone fails the
`aria-disabled` assertion with a received value of empty string. Neutralising
the `speak()` call fails the announcement assertion with an empty region.
Writing the enabled-currency option down to the store default alone fails the
precondition that something must be enabled beyond the default for the guard to
be reachable; that option was written raw rather than through the REST route,
because the route also deletes the per-currency settings and this store's EUR is
*available* only because its manual-rate option exists. Both the enabled and
available sets were confirmed restored afterwards.

Two advisories are recorded rather than changed.

The browser test proves tab-reachability with one Tab press anchored on Cancel,
because both modal buttons sit in a fixed actions row. An earlier version used
attribute assertions instead, on the stated grounds that counting Tab presses
would couple the test to the store's currency catalog. That reasoning was
wrong — the reviewer measured the tab order live — and is corrected here so it
is not carried forward. The attribute assertions stay alongside the Tab press
because they localise a lost-reachability failure to its cause.

The sibling `shopper/theme-compatibility.spec.ts` still matches multi-currency
writes by path substring only. On a plain-permalink store the browser issues
REST writes as `?rest_route=…` with percent-encoded slashes, which that form
would miss. This store uses pretty permalinks, and that spec's primary oracle
is a settings byte comparison rather than the write count, so the gap is
latent. It was fixed here and not there because reopening a closed row to
harden a secondary assertion costs more than it buys; a future change to that
file should carry the same fix.

## 2026-08-08 — Multi-currency theme compatibility pairing

Stable reference: `calibration-notes:2026-08-08:multi-currency-theme-compatibility-pairing`.

The two Currency Switcher widget rows take their planned client-only
disposition under the 2026-08-08 decision that the multi-currency settings
screen is native Core's equivalent of the client's onboarding wizard. Both
rows keep their client test and pair with native coverage; the ledger's
approved client-only target,
`tests/woopayments-native/shopper/theme-compatibility.spec.ts`, carries the
single native surface smoke their `smallest_honest_adaptation` asks for.

Four owner-decided deviations are recorded rather than hidden.

The smoke proves only the negative half directly. Storefront is not installed
on the dedicated native store — `wp theme list` reports Twenty Twenty-Five
active with Twenty Twenty-Four and Twenty Twenty-Three available — so the
Storefront-positive row is paired rather than re-asserted end to end. Both
halves of its conditional are pinned natively at the layer that owns them, and
both were read before being named:
`MultiCurrencyStorefrontIntegrationControllerTest` asserts that the breadcrumb
filter and the style action register under Storefront and do not register under
Twenty Twenty-Five, and `store-settings.test.tsx` asserts that the placement
checkbox renders when `site_theme` is `Storefront` and is absent when it is
`Twenty Twenty-Four`. Installing a theme to re-prove a conditional two native
tests already pin would buy coupling, not coverage.

The merchant-facing test lives under `shopper/` because that is the row's
frozen approved client-only target and the only allowlisted path whose
disposition set permits `clientOnly`. Extending the allowlist to add a
`merchant/` path would have meant approving my own target, which is the one
thing the allowlist exists to prevent. The directory is a wart; the approval
provenance is not. A future session that wants the file moved should move it
deliberately, with the allowlist entry changed in the same step.

The retained lower-layer target is narrowed to
`MultiCurrencySettingsControllerTest.php`. The frozen analysis listed four
lower-layer files; the other three are the frontend-price, rate-provider and
switcher-block tests, none of which says anything about which placements the
settings screen offers. Dropping the switcher-block test also keeps two shipped
production modules out of the closure bundle, which the validator would
otherwise pull in through that test's imports and so tie this closure to
routine edits of the block. The two tests that actually carry the pairing —
`MultiCurrencyStorefrontIntegrationControllerTest` and `store-settings.test.tsx`
— cannot appear in `target_path` at all, because a retained target must come
from the row's frozen `native_lower_layer_context` and neither is in it. They
are named in the closure evidence instead.

The unsupported-theme row's reproduced unlock decision asks for "a
deterministic preinstalled exact Twenty Twenty-Four store". The store runs
Twenty Twenty-Five. Amendment 9 requires the satisfaction to reproduce the
prior text verbatim, so the wording stands as written and the deviation is
recorded here: the contract is about a theme that cannot carry the Storefront
breadcrumb placement, and the smoke proves that property of whatever theme is
active rather than hard-coding one slug. That generalization is deliberate and
is the stronger claim.

The oracle was mutation-checked in three directions on the closed bytes.
Inverting the built bundle's theme predicate so the Storefront placement
renders under every theme fails the absence assertion with a received count of
one. Regressing the `site_theme` projection to an empty string fails the
precondition join with "the settings screen must report the same active theme
WordPress does", which is what stops a broken theme derivation from satisfying
the precondition and the absence assertion at the same time. Turning the
Storefront-switcher setting on through the settings route fails the precondition
that the placement must start off, which keeps "the offer is absent" from being
a claim about a store that had it enabled all along. The store's multi-currency
settings were re-read after the sweep and match the pre-run response exactly.

One advisory is recorded rather than changed, on its own reviewer's guidance.
The smoke compares `site_theme` against the themes route's `name.rendered`,
which WordPress passes through the display filters, while the projection reads
the raw `Name` header. A theme name carrying a straight apostrophe, quotes or a
double hyphen would texturize on one side and not the other, and the join would
red for a reason unrelated to the contract. It is exact on this store and on
every bundled theme, and it fails loudly rather than silently, so this is
latent brittleness only; hardening it would cost a full review round for no
change in what the smoke proves today.

Two product observations from the review round, both outside these rows' frozen
scope and neither caused by this work:

- **The two theme predicates disagree.** The native Storefront integration
  decides eligibility from the theme *slug*
  (`MultiCurrencyStorefrontProjectionService::is_storefront_theme` matches
  `storefront` as stylesheet or template), while the settings screen decides
  from the *display name* (`site_theme` is `wp_get_theme()->get( 'Name' )`).
  Under a Storefront child theme the backend registers the breadcrumb hooks
  while the settings screen hides the checkbox that turns them on. Row 214's
  `affected_scope` explicitly puts child-theme eligibility outside the row, so
  this does not block the pairing; it is worth a native defect on its own
  terms. The smoke now asserts the two identities agree for the active theme,
  which is the join no lower-layer test makes.
- **The store-settings loading and error paragraphs are inserted together with
  their own text**, so an `aria-live="polite"` region added at the same moment
  is unlikely to announce, and nothing announces the loaded state when the form
  replaces it. A `speak()` call or a persistent live region would fix it.

## 2026-08-07 — admin-surface-load family smoke

Stable reference: `calibration-notes:2026-08-07:admin-surface-load-family-smoke`.

Sixth family executed under the bucket-A family-smoke acceptance: the
admin-surface-load family, release page-load smokes for the disputes list and
the WooCommerce Subscriptions settings tab. The family's third candidate, the
transactions list row, is deliberately excluded: it is already claimed by the
merchant-transaction-navigation provider pilot, which carries its annotation
and its accepted rewrite disposition, and re-targeting it here would duplicate
that annotation and take a row another workstream owns. The two remaining rows
reopen from their native-readiness deferrals and take the rewrite disposition
their approved target permits, with
`tests/woopayments-native/merchant/payouts-disputes-smoke.spec.ts` as the
approved target.

The single provider-free test drives all three payments-admin surfaces as an
administrator — the transactions surface is visited but not claimed, so the
failed-fetch oracle spans the whole payments admin app —
behind preconditions that bind the run to the state the contracts assume: a
connected account, an enabled gateway, and an active Subscriptions extension
— the last because without it the Subscriptions settings contract would pass
vacuously on a store that never registered the tab. Each surface must show
its own heading and exhibit none of the failure shapes the rows name:
capability denial, fatal error, or pending-migration notice; the two payments
surfaces must additionally render their list table rather than chrome alone.

The disputes packet asks specifically for HTTP 200 from the default list and
summary requests, so the run collects every failing REST response the three
surfaces fetch from the store and requires the set to be empty. That oracle
is deliberately scoped to the store's own REST API rather than to all
store-origin requests: the first draft failed on a missing built stylesheet
belonging to the Subscriptions extension, which is a local packaging gap in
this environment rather than a payments surface failure, and folding it into
the oracle would have made the family hostage to unrelated build state.

The single-target question the disputes packet raised — whether to complete
the shared provisional bundle or assign a disputes-only target — is answered
by this family's shape: the disputes and Subscriptions-settings rows share one
approved smoke target, and the transactions row keeps the separate pilot target
it already had.

**The load proof this family needed, and how it was found.** A first version
asserted each surface's heading plus the presence of a list table. Both the
code and accessibility reviewers independently established that this proves
chrome, not a load: the payments surfaces render their heading and an empty
grid while a fetch is still pending, so the run could navigate on while
requests were in flight, and a failure delivered inside an HTTP 200 body was
invisible to a status-code oracle entirely. The accessibility reviewer
demonstrated it on the live store — with the disputes fetch broken, every
assertion passed while the page announced "Could not get a valid response from
the server." to assistive technology.

The fix needed no product change, because both surfaces already publish a
screen-reader live region whose copy distinguishes loading, loaded, empty and
error, flipping to an alert role on failure. Each payments surface now asserts
that region's exact terminal message before the run leaves it, which settles
the surface and upgrades the claim from "the chrome rendered" to "the data
resolved", through the same channel a screen-reader user consumes. The table
check became a named column header rather than whichever table came first in
the document, and the failure-text checks moved ahead of the heading check so
a denial or fatal — which replace the document — reports as itself.

**Two correct review findings pulled in opposite directions.** The code
reviewer observed that asserting only the loaded message silently requires a
seeded store, while this row's packet asks for a "loaded-or-empty terminal
status". Widening the pattern to accept the empty message satisfied the
contract — and, on re-running the silent-failure mutation, passed: a malformed
payload yields zero rows, which renders the empty message. Widening alone gave
back exactly the protection the accessibility finding had bought. The
resolution keeps both properties rather than trading between them. The live
region proves terminality and accepts loaded-or-empty, so an unseeded store is
honest; and a separate read of the disputes route through the admin API
requires its data key to be an array, proving the payload was well-formed
independently of row count. The general lesson: when a contract's wording and
an anti-vacuity guard conflict, the conflict usually means one assertion is
carrying two jobs, and the fix is to split them rather than to loosen either.

The oracle was mutation-checked in three directions: forcing the disputes REST
routes to answer 500 fails the run with both the list and summary routes named
in the failed-response set; returning HTTP 200 with a wrong-shaped body for the
same routes — the silent shape, with no failing status for a network oracle to
see, and the case both the first version and the naively-widened version passed
on — fails at the payload-shape assertion; and deleting the account cache fails
it at the connected-account precondition. Both were reverted and the store verified
healthy — the disputes route answering 200 again — before the final green
runs. Recorded environment note for the next session: the mutation mu-plugin
must be removed through the same `wp-env run cli` path that created it; a
`docker exec` against a similarly named container writes to a different
container than the one `wp-env` targets, and removing the file there leaves
the real one in place.

## 2026-08-07 — settings-modal-copy family smoke

Stable reference: `calibration-notes:2026-08-07:settings-modal-copy-family-smoke`.

Fifth family executed under the bucket-A family-smoke acceptance: the
settings-modal-copy family, three contracts about the manual-capture
confirmation modal and the payment-method incompatibility UI on the native
WooPayments settings screen. All three rows reopen from their manual-capture
deferral packets and take their planned shared-scenario disposition, with
`tests/woopayments-native/merchant/settings-methods.spec.ts` as the approved
target; the core settings and order-effect logic is proven by the retained
native coverage their frozen lower-layer context names.

The single provider-free test drives the real settings screen as an
administrator. It repairs its baseline idempotently over REST — manual
capture must start disabled, since the modal only guards the off-to-on
transition — then proves all three contracts in the test body. Enabling
opens a confirmation dialog located by its accessible name (so the heading is
proven to name the modal, not merely to sit inside some dialog, and it is
disambiguated from the promotion-badge tooltip dialog the surface also
renders) whose copy warns about the seven-day capture deadline and the
card-only incompatibility; cancelling closes it with nothing enabled;
confirming enables the toggle. With manual capture on, every incompatible
method carries the reason chip, is removed from the tab order (native
disabled, not merely aria-disabled), and has the reason programmatically
associated through aria-describedby so a screen-reader user hears it.
Disabling asks no confirmation and restores the flagged methods' eligibility.

The whole modal interaction is pre-save client state — the confirm action
dispatches a local reducer, not the settings-save generator — so the run
proves zero writes to the settings route and re-reads the stored setting as
still disabled, leaving the store untouched regardless of outcome. The
copy strings are core-authored, not provider copy, so pinning them is in
scope for this family unlike the provider-JS validation family.

Recorded constraint: the idempotent baseline writes the same manual-capture
setting that the provider pilot `merchant-manual-capture` governs with a
formal feature-setting lock. This readonly smoke takes no such lock, matching
the switcher family's precedent of unlocked readonly setup on the disposable
store; the ledger rows themselves carry the `serial by account or mutable
setting` CI-lane constraint, so the two must not run concurrently against the
same store.

The oracle was mutation-checked in both directions before closure: rewording
the seven-day deadline copy in the built admin chunks fails the run exactly
at the dialog deadline-warning assertion, and rewording the incompatibility
chip copy fails it exactly at the chip visibility assertion. A gateway-off
precondition mutation was not used because disabling the connected gateway
hangs wp-admin login on this store (the same behaviour the validation family
recorded); the two copy mutations and the non-vacuous chip-breadth
precondition carry the anti-vacuity proof instead. The store was verified
clean — gateway enabled, manual capture disabled — after the mutation sweep.

## 2026-08-07 — capability-breadth family smoke

Stable reference: `calibration-notes:2026-08-07:capability-breadth-family-smoke`.

Fourth family executed under the bucket-A family-smoke acceptance: the
capability-breadth family, two contracts about lower-privileged wp-admin
access on a WooPayments store. Both rows reopen from their shared
native-readiness deferral packet and take their planned shared-scenario
disposition, with `tests/woopayments-native/merchant/role-access.spec.ts` as
the approved target; their core capability gating is proven by the retained
native REST-controller integration and runtime-arbiter coverage their frozen
lower-layer context names.

The single provider-free test establishes a run-stable editor idempotently
over REST — re-asserting both credentials and the editor role on every run so
a leftover capability grant cannot silently weaken the denial oracle — and
proves both directions. Preconditions bind the run to the fully onboarded
state the first contract names: the runtime status must report a connected
account and an enabled gateway. Positive controls keep every denial oracle
non-vacuous: an administrator genuinely reaches the payments admin surface
and the payments settings REST route at the same addresses the editor is then
denied on. The editor then gets an untouched dashboard at its own URL — no
onboarding or payments interception — a working editing surface, the core
permissions message on the classic settings screen, the payments admin app's
own not-allowed screen, and a capability-coded REST denial under a genuine
authenticated nonce.

The second contract's before/after-onboarding half is carried by the family
acceptance as follows: native ships no admin interception mechanism keyed on
payments or onboarding state (verified at source — the payments namespace
registers no admin-page redirect), so the state-change breakage vector the
client plugin's onboarding wizard created has no native counterpart, and the
retained lower-layer evidence proves the capability gates independently of
account state. The pre-onboarding byte-snapshot projection machinery the
packet described is deliberately not built, by the standing disposable-store
owner decision; the connected state is the one the smoke executes, and the
account-cache-absent state was exercised as a mutation direction rather than
a maintained fixture.

The oracle was mutation-checked in both directions before closure: granting
the editor role the payments management capability fails the run exactly at
the first denial assertion, and deleting the account cache fails it exactly
at the connected-account precondition, with the cache captured privately and
restored byte-equivalently before the final green runs.

Recorded coverage limit: the capability mutation aborts at the first denial
assertion — the classic settings screen — so the two later denial oracles,
the payments admin app's not-allowed screen and the capability-coded REST
403, are proven to pass against the healthy store but were not observed to
flip under the mutated capability. They are each backed by a same-URL
administrator positive control that proves the surface and route genuinely
exist, so a missing surface cannot masquerade as a denial. Recorded inherited
product observations on the denial surfaces, not asserted because neither is
assertable without a product change: the core permissions screen is a bare
wp_die with no heading, landmark, or recovery path, and the payments admin
not-allowed screen neither announces itself through a live region nor moves
focus on the route change.

## 2026-08-07 — provider-js-validation family smoke

Stable reference: `calibration-notes:2026-08-07:provider-js-validation-family-smoke`.

Third family executed under the bucket-A family-smoke acceptance: the
provider-JS validation family, six contracts asserting the provider's
client-side field validation across the classic checkout, the blocks checkout,
and the My Account add-payment-method surface. All six reopen from their
native-readiness deferrals and take the lower-layer disposition, with
`tests/woopayments-native/shopper/declines.spec.ts` as the approved family
smoke target and each row's retained native coverage — the gateway,
token-service, and blocks-integration tests its frozen lower-layer context
names — as lower-layer evidence.

The programme owner's Decision 5, folded into the bucket-A family settlement,
downscoped these rows to a single accessibility-focused check: the validated
behaviour belongs to the provider's hosted JavaScript, and Core committing to
per-field provider copy would fossilize a third party's strings. The smoke
therefore performs three independent invalid-input gestures once each on the
blocks checkout payment element — a Luhn-failing card number, an expiration
date in the past, and an incomplete security code — and for each proves the
rejection is exposed accessibly and associated with the exact field it
concerns: the field is marked invalid, an error element is programmatically
associated through the field's description list, that element carries
non-empty text, the same text is announced through an alert live region, and
correcting the input clears the invalid state. No provider copy is asserted,
by that owner decision; the packets' "literal semantic errors" phrasing is
satisfied at the semantics level, not the strings level.

Recorded deviations, all under the standing disposable-store owner decision
or the family-level acceptance: the blocks checkout gesture set represents
the family's classic-checkout and My Account mounts, whose core-side handling
is the retained lower-layer evidence; the isolated-customer, byte-snapshot,
and restoration-journal machinery the packets describe is replaced by
idempotent setup, per-run request counters, and REST cardinality equality;
and the authorized-window readiness the two classic-checkout packets name is
satisfied by the provider-involvement-gated readiness model — these gestures
write nothing to the provider, so the run proves runtime ownership and the
live payment-element mount rather than a provider account window.

The oracle was mutation-checked in three directions before closure: renaming
the payment-element container in the built integration fails the mount gate
while the REST precondition stays green; feeding a valid card number where
the invalid one belongs fails the invalid-state assertion, proving the error
oracle demands a real rejection; and disabling the gateway fails the run
loudly at setup. The gateway-off direction surfaced a store observation worth
recording: with a connected account and the gateway disabled, wp-admin login
hangs past the action timeout, so the run dies at admin authentication before
the REST precondition is even read.

Closure-bundle membership notes. The three blocks rows' retained lower-layer
member is the PHP blocks-integration test rather than the JS test their
frozen context also names, because the closure validator attests every
production module a JS bundle member imports, which would couple ledger
validity to routine edits of the shipped blocks integration. And the retained
gateway coverage is the gateway test under the provider's own test namespace;
a similarly named sibling file exists one directory level up and is not the
attested member — resolve bundle members by full path, never by class name.

## 2026-08-07 — guest-save family smoke

Stable reference: `calibration-notes:2026-08-07:guest-save-family-smoke`.

Second family executed under the bucket-A family-smoke acceptance: the
guest-save UI absence family, one contract. The row reopens from its
native-readiness deferral and takes the lower-layer disposition, with
`tests/woopayments-native/shopper/saved-methods.spec.ts` as the approved family
smoke target and the native token-service PHPUnit coverage retained as
lower-layer evidence.

The packet's unlock decision asked for approved target/owner, native
readiness, a provider-free guest fixture, a semantic Core save-control
adapter, zero-dispatch and local-record counters, a fresh anonymous context,
and proof that Card stays operable while credential persistence is
unavailable. All are satisfied by the smoke. The single provider-free test
establishes its own run-stable product and customer idempotently over REST,
asserts the store preconditions the absence claim depends on (gateway
enabled, saved cards enabled, guest checkout permitted), then proves both
directions on the blocks checkout: a logged-in customer sees exactly the
semantic save control (the positive control that keeps the absence oracle
non-vacuous), and a fresh anonymous guest gets a visible, mounted card payment
surface with zero accessible save controls, zero persistence-implying copy,
zero Store API checkout dispatches, and unchanged order and token
cardinalities. The context-restoration and quarantine machinery the packet
described is deliberately replaced, by owner decision, with idempotent setup
and context teardown, because the standing native store is dedicated to this
programme and disposable.

The oracle was mutation-checked in both directions before closure: forcing
the blocks save-control eligibility open for guests fails the zero-count
assertion with a received count of one, and disabling saved cards fails
loudly at the REST precondition rather than letting the absence pass
vacuously.

## 2026-08-07 — switcher family smoke

Stable reference: `calibration-notes:2026-08-07:switcher-family-smoke`.

The programme owner settled the bucket-A acceptance decision on family-level UI
smokes: partial contracts whose core logic is already proven natively receive
one thin browser smoke per feature family rather than one migrated test per
row. The shopper currency-switcher family is the first family executed under
that decision. Its four contracts — switcher visibility on the storefront, and
currency switching at the product, cart, and checkout pages — reopen from their
native-readiness deferral and take the lower-layer disposition, with
`tests/woopayments-native/shopper/multi-currency.spec.ts` as the approved
family smoke target and the native multi-currency frontend-prices PHPUnit
coverage retained as lower-layer evidence.

The deferral packets' unlock decisions asked for an approved target and owner,
native readiness, a deterministic product/rate/placement graph, and semantic
per-surface assertions. All are satisfied by the smoke; the byte-exact raw
snapshot and restoration machinery those decisions also described is
deliberately replaced, by owner decision, with idempotent REST setup, because
the standing native store is dedicated to this programme and disposable. The
smoke establishes its own state on every run: a manual EUR rate of 0.80 with
rounding and charm pinned to zero, enabled currencies reduced to exactly USD
and EUR, the native switcher block placed in both the theme header and the
WooCommerce checkout-header template parts, and one run-stable USD 10.00
virtual product, so USD 10.00 converts to exactly EUR 8.00 on every surface.

The smoke asserts in the test body per context — one keyboard-operable
Currency combobox visible and unique on the shop page, conversion proven at
the product, cart, and checkout surfaces, and the EUR selection persisting
across a query-free request — replacing the client suite's afterEach-only
oracle. The native REST surface can only re-assert availability for a currency
that is already available (both multi-currency routes validate against
available currencies, and this store caches no provider rates), so the run
fails loudly at its precondition if the store ever loses the family state
rather than passing vacuously; this was verified by mutation before closure.

The disabled-side contract (switcher absent when multi-currency is disabled)
is not part of this family smoke and remains deferred in its packet.

Stable reference: `calibration-notes:2026-08-06:duplicate-contract-retirement`.

The ledger owner granted retirement authority for the twelve contracts gated on
`ambiguous-decision:PILOT-RETIREMENT-AUTHORITY`, resolving that gate. Each retired
contract names the retained contract that already carries it, recorded in
`evidence/duplicate-contract-retirement.json`.

The twelve divide into duplicate release-zip project instances of the retained
basic-project smokes, card-variant duplicates where the parameterized 3DS and 3DS2
iterations repeat a card-agnostic action already proven by the retained basic
iteration, the legacy Storefront sidebar switcher whose shopper-visible behaviour
the retained shopper switcher contract proves, and the standalone editor
authentication setup row, which is a harness fixture with no independent
WooPayments outcome.

A thirteenth contract originally proposed for retirement, the non-WooPayments
gateway add-payment-method isolation row, was retained instead: its client test
never exercised the titled behaviour, so the contract had never been proven
anywhere. It is now covered natively by
`WooPaymentsAddPaymentMethodIsolationTest`.

Retirement carries no reviewed source bundle and no verification run, so these
rows close through the retirement branch of the migration evidence schema rather
than by manufacturing closure reviews.

## 2026-08-06 — card protection execution budget

Stable reference: `calibration-notes:2026-08-06:card-protection-execution-budget`.

The ledger owner authorized exactly two provider executions for this slice,
rather than the single execution the previous slice carried.

The first re-proves the generalized strict-false basic-card scenario. Its
closure was re-established on 2026-08-05 at bundle SHA-256
`60fdf437783b3d2dbf5d935140bcfa8f5527de515bf51df2bcb51ab4c053cd91`, but its
provider verification was retained from the 2026-08-04 run, which exercised the
pre-generalization bytes. Twelve fresh role reviews approved the current
bundle, and the checkout, provider and record readers are byte-identical, so
the closure was defensible; it was nevertheless a closure whose live proof came
from other bytes. This execution rebinds it to a run of its own bytes.

The second executes the protected basic-card contract, whose only prior
execution stopped before any mutation.

The previous execution was lost to an approval-fixture field-name error: two
required capabilities were written to a non-schema field while the real
allowlist kept three values, and the run failed at the first missing capability
after the budget was spent. Two harness changes now make that class of error
cheap. The approval parser rejects any field outside its schema, so a
misspelled key fails at parse time. A capability preflight asserts the entire
required set against the approval before a provider interval opens, and reports
every missing capability at once.

The approved capability set for both executions is `product/payment`,
`basic-card`, `basic-card-entry`, `card-testing-protection-setting`, and
`classic-checkout-page`. Each execution is a single invocation with one worker,
zero retries, and no replay, under one exclusively owned listener.

## 2026-08-05 — false basic-card closure scheduled for current bytes

The native protection-false basic-card contract is reopened from
`closed / supported` to `implemented / not-assessed` before changing either
pinned source file. Its current closure bundle SHA-256 is
`80bfb8c4f8007723ee9947d157a5bbc41d2f99494bb7179781788f94bb74e892`.
The selected protection-true slice must generalize the shared
`card-payment.ts` scenario and re-express the false composition through the new
definition, so the retained closure must be reviewed and rebound to the future
current bytes.

The overlapping saved-token files `drivers/checkout.ts`,
`provider-evidence.ts`, and `record-evidence.ts` remain byte-identical. The
false contract keeps its own immutable provider execution and receives fresh
provider-free regression proof plus current-bundle reviews; it does not borrow
the selected protection-true payment and no second provider payment is
authorized. If that evidence cannot truthfully support current-byte reclosure,
the false row will be deferred rather than rerun.

No scenario, pilot, driver, or evidence-reader byte changed in this back-edge.
No browser, listener, store, account, option, session, cart, product, order,
provider, lock, journal, or quarantine state changed. The selected true row
remains `specified`; the other four owner-authorized card rows remain deferred
and untouched.

## 2026-08-05 — native protected basic-card prerequisites calibrated

Stable reference:
`calibration-notes:2026-08-05:native-protected-basic-card-prerequisites-calibrated`.

The owner decision dated 2026-08-04 authorizes the exact native protected
basic-card contract, the accepted `card-payment.ts` target, and owner
`autonomous-run:pilot-calibration`. The standing-store Card, test-mode, USD,
callback, account, provider-writer, and exclusive-listener readiness recorded
on 2026-08-03 remains current at WooCommerce commit
`f606e403bcd97ecbe2b46926d96a0743fb2e484c`, rolling read-only WooPayments
reference `265e275802e9d8cadefe61c68c492911d14a1534`, and rolling read-only
WooCommerce Subscriptions reference
`0887151df977fd7e0454c55b2b49984c545df94a`.

Fresh read-only calibration found an authoritative account cache whose
effective card-testing-protection state is strict false, no force-override
option, and no pre-existing marker-owned Classic checkout page. The repository
already provides the bounded resource-lock, restoration-journal, submission-
journal, quarantine, raw WordPress option, WooCommerce session, native fraud-
service, Classic gateway bridge, order, and provider-evidence primitives needed
to implement an exact raw-state controller and one-shot Classic adapter. The
current environment has no active provider listener and no active lock,
restoration journal, submission attempt, or quarantine entry. One provider
writer and one exclusively owned listener remain authorized for the later
single selected-row execution.

This calibration establishes pre-implementation availability under the
required reopen-first lifecycle. It does not claim that the strict-true
controller, Classic driver, selected payment, immutable evidence, restoration,
or reviews already exist; those remain mandatory before the row can advance
beyond `specified`. No browser or provider mutation ran, and no store,
account, protection, gateway, session, cart, product, order, listener, lock,
journal, or quarantine state changed.

## 2026-08-03 — native standing store calibrated

Stable reference: `calibration-notes:2026-08-03:native-standing-store-calibrated`.

The calibrated WooCommerce commit is
`4a96a82c76e113bb7a47aa4052beeb51ce965c4a`. Its rolling, read-only
WooPayments reference is `265e275802e9d8cadefe61c68c492911d14a1534`,
and its rolling, read-only WooCommerce Subscriptions reference is
`0887151df977fd7e0454c55b2b49984c545df94a`. There is no pinned
Subscriptions provisioner artifact; the same clone is mounted by design. The
ledger SHA-256 is
`285080c37e06ab087d83d0865b8c99bbd188e67325ccffdbeb53be511e4a2402`.

The public-safe identity triple is site URL
`http://store8889.localhost:8889`, WPCOM blog ID `4`, and account identity
`redacted:account:sha256:3e5fe6b30db44a9921dd5141bca73e4a1dc99add811541b56c65df558f5eb578`.
Store doctor confirmed the exact blog, domain, and token match with the current
adapter loaded. Core-native onboarding adopted the already-dedicated non-live
test-drive account without replacement or reset. Runtime ownership is native
and enabled, the standalone client is inactive, the kill switch is false, the
gateway is enabled in test mode, and Card is enabled.

The callback probe confirmed the exact blog `4` and store, Jetpack capability
authentication, and registered, reachable, successful delivery with
`provider_write=false`. The repository's real
`assertRuntimeReady(... requireCallback:true)` passed against the
independent private identity. Public-safe private artifact references are:

- `redacted:runtime-status:sha256:9d3e31177d68a8b4ddd4ce6eb8a8406b47387a0a9184a2ebe1c6272bf8122bdb`
- `redacted:callback-probe:sha256:b7809b0a8efd7a42ade37661dca634555887130134a4dd0d670b5800f1c1b5e8`
- `redacted:readiness-assertion:sha256:4cee6e6c92ba77c388923c7839a9a6c2e8698a6c30ceaef697a8c8facd85e754`
- `redacted:owned-listener-record:sha256:f96865031dd695dbdf3361c96e9d34e3d756f47794c4b2325491ba37d26ffa3e`
- `redacted:final-zero-listener-scan:sha256:23d2b94ccc5b4c8d15d4e281f78d87cd8df13a65959f9a5641bf832dc4c6ef38`

A pre-existing listener was stopped by exact identity under dated authority.
One owned, PID-recorded wrapper and direct child remained identity-stable
through the callback proof. The owned listener was then stopped, and immediate
and delayed global scans both found zero listeners.

No checkout, payment, or provider mutation ran. No account was reset or
deleted, no shared environment was reset or reseeded, the `:8082` standalone
store received no write, no other repository was changed, and no ledger row
transitioned. The ledger remains exactly `2 closed / 179 deferred`. Browser
inspection reported one non-blocking missing WooCommerce Subscriptions
`build/admin.css` local build artifact; native UI, state, and proofs remained
complete, and the read-only clone was untouched. No Core-native defect was
found.

## 2026-08-02 — historical default-token provider evidence deferred

The dedicated historical default-token transition was implemented at commit
`0ddb1576e51c174b2034a1068e08dac7f0c27edf` and invoked exactly once as run
`historical-default-20260802`, with one worker and zero retries. It used the
approved deterministic WooPayments 10.5.0 seed with transport SHA-256
`899fea3b8594b6823a9454572404bf3736d0c4ad4713e4a60abd28575488f178`
and canonical tar SHA-256
`8c4cbfe257f23ab19bfe0cca4c4de5b1f76c1d3658048847e72ab55009583b1b`.
The retained test reached `1 passed`; disposable teardown completed; the
run-owned listener observed 17 of 17 HTTP 200 deliveries; and no transition
lease, journal, or quarantine residue remained. The public-safe run reference
is `redacted:transition-log:sha256:2629999777465229b21805f519085a26c960c855983615c4f0d4e3d91b924c23`.

Closure is nevertheless deferred. A separately owned listener appeared after
the exact single-listener preflight and remained active during part of the
provider interval, so exclusive webhook delivery attribution cannot be
established. The run-owned listener was stopped by exact ownership; the
separately owned process was left untouched. The provider mutation was not
rerun. The exact row is deferred as `blocked-environment` under
`PILOT-PROVIDER-LISTENER-EXCLUSIVITY`; the smallest unlock is a fresh
authorized migration run with one exclusively owned listener maintained for
the full provider interval.

## 2026-08-01 — saved-token closures re-established under narrow driver bundles

The authorized run `slice1-reclose-20260801222519` executed exactly once and
passed its one test with one worker and zero retries. Three fresh closure
reviews in the `code`, `e2e-tests`, and `reliability` roles approved the exact
six-file behavior bundle at source SHA-256
`ae55a768a61a02394d068d0e26d82c21a65a37fa5e954cde218be27b402ed54e`.
The run used the approved deterministic WooPayments 10.5.0 seed with transport
SHA-256
`899fea3b8594b6823a9454572404bf3736d0c4ad4713e4a60abd28575488f178`
and canonical tar SHA-256
`8c4cbfe257f23ab19bfe0cca4c4de5b1f76c1d3658048847e72ab55009583b1b`.

The exact saved cards were cleaned up, ephemeral teardown completed, and no
active lock, journal, or quarantine residue remained. All 21 listener
deliveries returned HTTP 200. The Task-owned Transact listener was then
stopped, and no listener process remains. The retained public-safe run
reference is `redacted:transition-log:sha256:39edea9135787e7079b60a70e6053a46b47ab420520e84466238a68e9ad4c332`.
Exactly the Classic and Blocks basic saved-token rows were reclosed; no other
contract changed state.

## 2026-08-01 — deterministic transition seed baseline replaced

The explicit user authorization `Replace the missing baseline` approves the
deterministic replacement for the unavailable transition seed. The new
compressed transport SHA-256 is
`899fea3b8594b6823a9454572404bf3736d0c4ad4713e4a60abd28575488f178`,
which supersedes the prior transport hash
`dc69d2cbb1ad73ce54ed585a3d8aebfb1085cd4dd59fb6a55e954309dcb9b186`.
Its uncompressed canonical tar SHA-256 is
`8c4cbfe257f23ab19bfe0cca4c4de5b1f76c1d3658048847e72ab55009583b1b`
under archive profile `git-sha1-fixed-pax+gzip-n9-v1`.

The immutable payload remains WooPayments 10.5.0 from source commit
`a1f755fc903966387f8629f78f75976ac8d2016e`. Its Composer lock SHA-256 is
`d345f41ee68fc121f00f6f7ca713fd0cb0c2764e844b3f102c9e12eefa216e8e`
with 15 production packages. Its frontend lock SHA-256 is
`6e279cfadb1851486976f67a72a11bc9ea36fa62c7f74d31b4d0d73c006b34b1`.
The four production bundle hashes are:

- `dist/index.js`: `065744d76e24ef28d1cb824301ef22d0816426d7a3f0fbe2e549c210b2e78ed7`
- `dist/index.css`: `f9e9ac58624842d627efe38d95710beaf17bae7c760d0c2736b396c3d208266a`
- `dist/checkout.js`: `4257da2777c531a375cf579ceb0ae9f69eee7d6157783ac9aa8a93fec054c17c`
- `dist/blocks-checkout.js`: `0ec1ee938fe1727eb3be3bdc6768faf0465c4585f66f40f12909f2c08bd33d57`

The observed six-value build toolchain was Composer
`Composer version 2.9.5 2026-01-29 11:40:53`, Git `git version 2.54.0`,
gzip `Apple gzip 479`, Node `v20.11.1`, npm `10.2.4`, and zlib
`1.2.13.1-motley-5daffc7`. Two independent builds produced byte-identical
archives and byte-identical schema-2 manifests. Independent checks reproduced
both hashes, lock and bundle hashes, package count, canonical plugin entry,
read-only modes, and absence of Git metadata and `node_modules`. The real
provisioner's read-only `plan` accepted the first pair without mutating its
empty workspace. Task 10 and its provider-backed transition scenario have not
run yet.

## 2026-08-01 — Slice 1 deferred on seed hash mismatch

The automated provider preflight rebuilt the pinned WooPayments 10.5.0 seed
from commit `a1f755fc903966387f8629f78f75976ac8d2016e` after the prior temporary
artifacts were absent. The tracked builder produced archive SHA-256
`168464e960225fcc570176a64d6e741fe9e3c5a5f64049e329812629c116a3a1`,
which differs from the required recorded hash
`dc69d2cbb1ad73ce54ed585a3d8aebfb1085cd4dd59fb6a55e954309dcb9b186`.
The manifest self-consistently binds the new archive and the approved version
and commit, but exact artifact provenance cannot be assumed across the hash
mismatch. No provider scenario ran and no Transact listener was changed.

The affected scope is Slice 1 Tasks 10–11: the single transition run and the
two saved-token re-closures remain blocked; completed Slice 0 work is
unaffected. The smallest unlock is to reproduce or explain the recorded seed
hash from the tracked builder and pinned toolchain, or explicitly approve a
new immutable seed baseline after comparing artifact contents and build
provenance. Then restart Task 9 from a clean tracked state.

## 2026-07-31 — driver decomposition and bundle policy

The pilot fixture was decomposed into a provider-write session plus
per-feature driver modules under `utils/woopayments-native/drivers/`.
Closure evidence bundles now attest the target spec plus its transitive
static relative behavior imports and exports (drivers and oracle readers);
controller infrastructure (locks, journal, quarantine, session, readiness)
is attested by the controller unit suite instead of per-closure. The validator
enforces bundle coverage for every verified or closed row. Editing a driver
reopens only the closures that statically import or re-export it; editing
infrastructure reopens none but must keep the controller suite green.

## 2026-07-30 — saved-token closures reopened after capture safety fix

The shared fixture changed after the single transition calibration. Manual
capture now retains a durable attempt from before Apply through exact provider
proof and quarantines ambiguity. The saved-token behavior remains implemented,
but current-byte closure needs a separately authorized transition run and fresh
reviews.

## 2026-07-30 — saved-token closures re-established

One serialized, zero-retry transition run re-established the Classic
and Blocks saved-token closures against the immutable WooPayments 10.5.0
seed. Schema-v2 row-scoped evidence binds the passing run and three fresh
`code`, `e2e-tests`, and `reliability` approvals to the retained source
bundle at commit `5f68bfd468e30a121fc1cde550636b21f0a8dfd2`.

## 2026-07-29 — saved-token closures reopened

The two saved-token contracts (classic and Blocks saved-card payment)
were reopened from `closed` to `implemented`. Their evidence attested
the pilot source bundle at an earlier commit, while later commits
changed the fixture and spec; a closure must attest the currently
retained bytes. The pilots remain implemented. They re-close after one
clean transition run and fresh closure reviews under the row-scoped,
current-byte evidence schema.

## Standing blockers

- Standing-native runtime readiness failed its read-only preflight
  before any standing-store native pilot ran. Manual capture and the
  other partial contracts therefore remain open (`specified`), which is
  the intended fail-closed outcome, not a regression.
- Provider-backed Playwright projects have no external CI worker
  allocation yet. Provider and transition pilots run locally only, one
  worker, zero retries.
