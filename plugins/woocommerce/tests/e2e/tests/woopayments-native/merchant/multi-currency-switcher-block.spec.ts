import type {
	APIRequestContext,
	FrameLocator,
	Locator,
	Page,
} from '@playwright/test';

import {
	expect,
	tags,
	test,
	waitForWordPressLoginReady,
} from '../../../fixtures/woopayments-native';
import { admin } from '../../../test-data/data';

/**
 * Native multi-currency switcher block (mc-switcher-block-spec).
 *
 * Four provider-free contracts against the Core-owned currency switcher block
 * (`woocommerce-payments/multi-currency-switcher`), registered server-side by
 * MultiCurrencySwitcherBlockController and rendered by
 * MultiCurrencySwitcherProjectionService:
 *
 * - the merchant sees the enabled currencies while editing the block;
 * - the merchant can configure the block's presentation choices and they
 *   persist;
 * - persisted presentation choices reach the shopper-visible control;
 * - the merchant can publish the block in content and a visitor gets a
 *   working switcher there.
 *
 * NO FORCED PREMISE. Unlike the sibling multi-currency pricing and settings
 * specs, nothing here widens the store's available-currency catalog: every
 * contract is about the switcher *given* whatever the merchant has enabled,
 * so each test reads the authoritative enabled set through
 * `/wc/v3/payments/multi-currency/currencies` and asserts against exactly
 * that set. The tests never add, remove, or reconfigure a currency — the
 * standing store's USD default plus its EUR manual-rate currency (which the
 * frozen `shopper/multi-currency.spec.ts` depends on) are read, never
 * written. A store without at least one additional enabled currency fails the
 * precondition guard loudly rather than passing vacuously.
 *
 * Run-owned state is limited to posts this spec creates and force-deletes.
 *
 * Deliberately NOT claimed here (they belong to the frozen
 * `shopper/multi-currency.spec.ts`): that switching converts prices on the
 * product, cart, and checkout surfaces. Row :61's own packet also puts
 * currency selection and repricing outside its scope, so the publish row
 * proves the published control renders and offers the enabled set, not that
 * prices move.
 */

// The same environment-first resolution the harness fixtures use.
const ADMIN_USERNAME =
	process.env.E2E_WOOPAYMENTS_ADMIN_USERNAME ?? admin.username;
const ADMIN_PASSWORD =
	process.env.E2E_WOOPAYMENTS_ADMIN_PASSWORD ?? admin.password;

const WIDGET_CONTRACT_PREFIX =
	'default::chromium::tests/e2e/specs/wcpay/merchant/merchant-multi-currency-widget.spec.ts:';

const CONTRACT_IDS = {
	adminCurrencies: `${ WIDGET_CONTRACT_PREFIX }52::Multi-currency widget setup › displays enabled currencies correctly in the admin`,
	updateProperties: `${ WIDGET_CONTRACT_PREFIX }79::Multi-currency widget setup › can update widget properties`,
	frontendProperties: `${ WIDGET_CONTRACT_PREFIX }204::Multi-currency widget setup › widget settings are applied in the frontend`,
	publishInContent:
		'default::chromium::tests/e2e/specs/wcpay/merchant/multi-currency.spec.ts:61::Multi-currency › can add the currency switcher to a post/page and verify on frontend',
} as const;

const MULTI_CURRENCY_API = '/wp-json/wc/v3/payments/multi-currency';
const POSTS_API = '/wp-json/wp/v2/posts';

const BLOCK_NAME = 'woocommerce-payments/multi-currency-switcher';
const BLOCK_TITLE = 'Currency switcher';

// Inspector copy authored by core
// (client/blocks/assets/js/blocks/multi-currency-switcher/block.js).
const DISPLAY_FLAGS_LABEL = 'Display flags';
const DISPLAY_SYMBOLS_LABEL = 'Display currency symbols';
const SHOW_BORDER_LABEL = 'Show border';

// The two presentation configurations the frontend row contrasts. Every
// attribute is written explicitly on both blocks so no assertion depends on
// WordPress filling registered defaults, and every pair differs, so a render
// that ignored the saved attributes cannot satisfy both halves.
const BASELINE_PRESENTATION = {
	symbol: true,
	flag: false,
	border: true,
	borderRadius: 3,
	borderColor: '#000000',
	fontSize: 14,
	fontColor: '#000000',
	backgroundColor: 'transparent',
	fontLineHeight: 1.5,
} as const;

const CONFIGURED_PRESENTATION = {
	symbol: false,
	flag: true,
	border: false,
	borderRadius: 12,
	borderColor: '#0000ff',
	fontSize: 24,
	fontColor: '#ff0000',
	backgroundColor: '#00ff00',
	fontLineHeight: 2,
} as const;

// The US regional-indicator pair MultiCurrencySwitcherProjectionService
// derives for the USD store default.
const USD_FLAG = '\u{1F1FA}\u{1F1F8}';

/** The editor canvas is iframed in block themes and inline otherwise. */
type EditorCanvas = FrameLocator | Page;

interface WpPreferencesActions {
	set?: ( scope: string, name: string, value: unknown ) => void;
}

type WindowWithWpData = Window & {
	wp?: {
		data?: {
			dispatch: ( store: string ) => WpPreferencesActions | undefined;
		};
	};
};

interface CurrencyRecord {
	code: string;
	name: string;
	rate: number;
	is_default: boolean;
}

interface StoreCurrencies {
	available: Record< string, CurrencyRecord >;
	enabled: Record< string, CurrencyRecord >;
	default: CurrencyRecord;
}

function requireBaseUrl( baseURL: string | undefined ): string {
	if ( ! baseURL ) {
		throw new Error( 'BASE_URL is required for this spec.' );
	}
	return baseURL.replace( /\/+$/, '' );
}

async function readJson< Result = Record< string, unknown > >(
	response: Awaited< ReturnType< APIRequestContext[ 'get' ] > >,
	description: string
): Promise< Result > {
	if ( ! response.ok() ) {
		throw new Error(
			`${ description } failed: HTTP ${ response.status() } ${ await response.text() }`
		);
	}
	return ( await response.json() ) as Result;
}

async function logInAsAdmin( page: Page ): Promise< void > {
	// Clear first, matching the harness's own admin login: a stale session
	// cookie would redirect wp-login.php to wp-admin and leave the form fill
	// hunting a field that is not there.
	await page.context().clearCookies();
	await page.goto( 'wp-login.php' );
	await waitForWordPressLoginReady( page );
	await page.getByLabel( 'Username or Email Address' ).fill( ADMIN_USERNAME );
	await page
		.getByRole( 'textbox', { name: 'Password' } )
		.fill( ADMIN_PASSWORD );
	await page.getByRole( 'button', { name: 'Log In' } ).click();
	await page.waitForURL( '**/wp-admin/**' );
}

// Assets belonging to plugins other than the one under test. The standing
// store carries unrelated third-party plugins — an installed
// woocommerce-subscriptions build 404s on its admin stylesheet on every admin
// screen — and Chromium echoes each such network failure as a console error.
// Those say nothing about the switcher block. The exclusion is scoped by
// owner rather than being a blanket console filter: every same-origin failure
// that is not another plugin's asset still counts, and uncaught exceptions
// always count regardless of source.
const THIRD_PARTY_PLUGIN_ASSET = /\/wp-content\/plugins\/(?!woocommerce\/)/;
// WordPress's own editor keepalive. Its post-lock heartbeat keeps firing
// while a test leaves the editor and answers 400 once its nonce goes stale,
// which says nothing about the block under test. Only this endpoint is
// exempt: the block's own data flows over the REST API, which the
// failed-store-request tracker still watches strictly.
const WORDPRESS_HEARTBEAT_ENDPOINT = /\/wp-admin\/admin-ajax\.php$/;

function isAmbientForeignResource( url: string, baseUrl: string ): boolean {
	if ( ! url.startsWith( baseUrl ) ) {
		// Cross-origin resources (gravatars, w.org emoji) are never this
		// surface's responsibility.
		return true;
	}
	try {
		const { pathname } = new URL( url );
		return (
			THIRD_PARTY_PLUGIN_ASSET.test( pathname ) ||
			WORDPRESS_HEARTBEAT_ENDPOINT.test( pathname )
		);
	} catch {
		return false;
	}
}

/**
 * Collect uncaught page exceptions and attributable console errors, so a
 * surface that renders while its scripts throw is not mistaken for a healthy
 * one. Mirrors the oracle in `merchant/multi-currency-settings-management.spec.ts`.
 *
 * @param page    Page to observe.
 * @param baseUrl Store base URL used to attribute resources.
 * @return Accessor returning the errors collected so far.
 */
function trackPageErrors( page: Page, baseUrl: string ): () => string[] {
	const errors: string[] = [];
	page.on( 'pageerror', ( error ) => {
		errors.push( `pageerror: ${ error.message }` );
	} );
	page.on( 'console', ( message ) => {
		if ( message.type() !== 'error' ) {
			return;
		}
		const source = message.location().url;
		if ( source && isAmbientForeignResource( source, baseUrl ) ) {
			return;
		}
		errors.push(
			`console: ${ message.text() } (${ source || 'no source' })`
		);
	} );
	return () => [ ...errors ];
}

async function getStoreCurrencies(
	adminApi: APIRequestContext
): Promise< StoreCurrencies > {
	return readJson< StoreCurrencies >(
		await adminApi.get( `${ MULTI_CURRENCY_API }/currencies` ),
		'Multi-currency state read'
	);
}

/**
 * Read the enabled-currency set every contract in this file is asserted
 * against, and refuse to run against a store that cannot express the
 * contract.
 *
 * The switcher projection returns empty markup unless the store has an
 * additional enabled currency
 * (`MultiCurrencySwitcherProjectionService::get_block_markup` →
 * `MultiCurrencyState::has_additional_currencies_enabled`), so on a
 * single-currency store every assertion below would be about an absent
 * control. That must fail loudly here, before any post is created, rather
 * than surface as a confusing locator timeout.
 *
 * @param adminApi Authenticated admin REST context.
 * @return Sorted enabled codes and the store default code.
 */
async function readEnabledCurrencies( adminApi: APIRequestContext ): Promise< {
	enabledCodes: string[];
	defaultCode: string;
} > {
	const currencies = await getStoreCurrencies( adminApi );
	const defaultCode = currencies.default.code;
	expect(
		defaultCode,
		'these switcher contracts assume a USD store default; the option-label expectations are derived from it'
	).toBe( 'USD' );

	const enabledCodes = Object.keys( currencies.enabled ).toSorted();
	expect(
		enabledCodes,
		'the enabled set always contains the store default'
	).toContain( defaultCode );
	expect(
		enabledCodes.length,
		'the switcher only renders when the store has an additional enabled currency; this store offers none, so the contract has no expression here'
	).toBeGreaterThan( 1 );

	return { enabledCodes, defaultCode };
}

function switcherBlockMarkup( attributes?: Record< string, unknown > ): string {
	return attributes
		? `<!-- wp:${ BLOCK_NAME } ${ JSON.stringify( attributes ) } /-->`
		: `<!-- wp:${ BLOCK_NAME } /-->`;
}

interface RunPost {
	id: number;
	link: string;
}

/**
 * Create a run-owned published post carrying the given block markup.
 *
 * @param adminApi Authenticated admin REST context.
 * @param runId    Playwright run identifier, used to keep the slug unique.
 * @param blocks   Serialized block markup for the post body.
 * @param label    Short label distinguishing this post in the admin list.
 * @return The created post's ID and permalink.
 */
async function createRunPost(
	adminApi: APIRequestContext,
	runId: string,
	blocks: string[],
	label: string
): Promise< RunPost > {
	const created = await readJson< { id: number; link: string } >(
		await adminApi.post( POSTS_API, {
			data: {
				title: `WooPayments MC switcher ${ label } ${ runId }`,
				slug: `woopayments-mc-switcher-${ label }-${ runId }`,
				status: 'publish',
				content: blocks.join( '\n\n' ),
			},
		} ),
		'Run post creation'
	);
	return { id: created.id, link: created.link };
}

async function readPostContent(
	adminApi: APIRequestContext,
	postId: number
): Promise< string > {
	const post = await readJson< { content: { raw?: string } } >(
		await adminApi.get( `${ POSTS_API }/${ postId }?context=edit` ),
		'Run post content read'
	);
	return post.content.raw ?? '';
}

/**
 * Every switcher block in the given serialized post content, as its parsed
 * attribute object. This is the authoritative saved representation the editor
 * writes, so it is the persistence oracle rather than any editor-side state.
 *
 * @param rawContent Serialized post content.
 * @return One attribute object per switcher block, in document order.
 */
function parseSwitcherBlocks(
	rawContent: string
): Array< Record< string, unknown > > {
	const pattern = new RegExp(
		`<!--\\s+wp:${ BLOCK_NAME }(?:\\s+(\\{[^}]*\\}))?\\s+/-->`,
		'g'
	);
	return [ ...rawContent.matchAll( pattern ) ].map( ( match ) =>
		match[ 1 ]
			? ( JSON.parse( match[ 1 ] ) as Record< string, unknown > )
			: {}
	);
}

async function deleteRunPost(
	adminApi: APIRequestContext,
	postId: number
): Promise< void > {
	const deletion = await adminApi.delete( `${ POSTS_API }/${ postId }`, {
		data: { force: true },
	} );
	if ( ! deletion.ok() ) {
		throw new Error(
			`Run post cleanup failed: HTTP ${ deletion.status() }.`
		);
	}
	// Verified restore: the run-owned content is gone, so a later run cannot
	// inherit a stray switcher post.
	const reread = await adminApi.get( `${ POSTS_API }/${ postId }` );
	expect(
		reread.status(),
		'the force-deleted run post must no longer resolve'
	).toBe( 404 );
}

/**
 * Currency switchers rendered inside the post body.
 *
 * Scoped to core's own post-content wrapper (`core/post-content` always emits
 * `entry-content`) rather than the whole document: the standing store's theme
 * header may carry its own switcher placement, and a page-wide locator would
 * alias it with the one this spec published.
 *
 * @param page Page rendering the post.
 * @return Locator matching every in-content switcher.
 */
function contentSwitchers( page: Page ): Locator {
	return page
		.locator( '.entry-content' )
		.getByRole( 'combobox', { name: 'Currency', exact: true } );
}

/**
 * The block editor's content canvas.
 *
 * WordPress iframes the canvas in block themes and renders it inline
 * otherwise, so every canvas query goes through this. Deliberately local
 * rather than imported from `@woocommerce/e2e-utils-playwright`: that
 * package's published type build is stale in this checkout, and importing it
 * would add type errors to this file.
 *
 * @param page Editor page.
 * @return The canvas frame, or the page when the editor is not iframed.
 */
async function getEditorCanvas( page: Page ): Promise< EditorCanvas > {
	const iframe = page.locator( 'iframe[name="editor-canvas"]' );
	await iframe
		.waitFor( { state: 'attached', timeout: 10_000 } )
		.catch( () => {
			// A non-iframed editor is a supported shape, not a failure.
		} );
	return ( await iframe.count() ) > 0 ? iframe.contentFrame() : page;
}

/**
 * Turn off the first-run editor welcome guide.
 *
 * The guide is ambient first-run UI whose modal intercepts every editor
 * interaction and has nothing to do with the contracts here; a merchant who
 * has dismissed it once never meets it again. Written through the
 * `core/preferences` store — the guide's own source of truth — under both the
 * historical and current scope names, so it does not depend on which
 * WordPress version the store runs.
 *
 * @param page Editor page.
 */
async function dismissEditorWelcomeGuide( page: Page ): Promise< void > {
	await page.waitForFunction( () =>
		Boolean( ( window as unknown as WindowWithWpData ).wp?.data?.dispatch )
	);
	await page.evaluate( () => {
		const preferences = (
			window as unknown as WindowWithWpData
		 ).wp?.data?.dispatch( 'core/preferences' );
		preferences?.set?.( 'core/edit-post', 'welcomeGuide', false );
		preferences?.set?.( 'core', 'welcomeGuide', false );
	} );
}

async function openPostEditor(
	page: Page,
	postId: number
): Promise< EditorCanvas > {
	await page.goto( `wp-admin/post.php?post=${ postId }&action=edit` );
	await dismissEditorWelcomeGuide( page );
	return getEditorCanvas( page );
}

function editorSwitcherBlock( canvas: EditorCanvas ): Locator {
	return canvas.locator( `[data-type="${ BLOCK_NAME }"]` );
}

/**
 * Select the switcher block in the editor canvas and prove the selection
 * landed, so a later inspector assertion cannot silently read another block's
 * settings.
 *
 * @param canvas Editor canvas (iframe or page).
 * @return The selected block locator.
 */
async function selectSwitcherBlock( canvas: EditorCanvas ): Promise< Locator > {
	const block = editorSwitcherBlock( canvas );
	await expect( block ).toHaveCount( 1 );
	await block.click();
	await expect( block ).toHaveClass( /is-selected/ );
	return block;
}

/**
 * Type a post title in the editor canvas.
 *
 * @param page  Editor page.
 * @param title Title to set.
 */
async function fillPostTitle( page: Page, title: string ): Promise< void > {
	const canvas = await getEditorCanvas( page );
	const titleField = canvas.getByLabel( /Add title|Block: Title/ ).first();
	await titleField.click();
	await titleField.fill( title );
}

function escapeForRegExp( value: string ): string {
	return value.replace( /[.*+?^${}()|[\]\\]/g, '\\$&' );
}

/**
 * Insert a block through the editor's own inserter, which is what makes this
 * a discoverability proof rather than a content injection.
 *
 * The inserter's open state differs across WordPress versions (it can start
 * expanded), so the open and close steps converge on the search field being
 * visible or hidden rather than assuming a starting state. No fixed waits are
 * involved: each retry re-reads the real state.
 *
 * @param page      Editor page.
 * @param blockName Block title as shown in the inserter.
 */
async function insertBlockFromInserter(
	page: Page,
	blockName: string
): Promise< void > {
	const inserterToggle = page
		.getByRole( 'button', {
			name: /Toggle block inserter|Block Inserter/,
		} )
		.first();
	const searchField = page.getByPlaceholder( 'Search', { exact: true } );

	await expect( async () => {
		if ( ! ( await searchField.isVisible() ) ) {
			await inserterToggle.click();
		}
		await expect( searchField ).toBeVisible( { timeout: 3_000 } );
	} ).toPass();

	await searchField.fill( blockName );
	// The result's accessible name is the title with the icon's own
	// whitespace around it, so this matches the title within the name rather
	// than anchoring to the string start. Scoping to the filtered results
	// list keeps that unambiguous, and editor versions expose the entries as
	// listbox options or as buttons, so both roles are accepted. It stays a
	// discoverability proof: the block has to be findable by searching its
	// own title for this to resolve at all.
	const resultName = new RegExp( escapeForRegExp( blockName ) );
	const results = page.getByRole( 'listbox' ).first();
	const result = results
		.getByRole( 'option', { name: resultName } )
		.or( results.getByRole( 'button', { name: resultName } ) )
		.first();
	await expect( result ).toBeVisible();
	await result.click();

	await expect( async () => {
		if ( await searchField.isVisible() ) {
			await inserterToggle.click();
		}
		await expect( searchField ).toBeHidden( { timeout: 3_000 } );
	} ).toPass();
}

/**
 * Publish the post currently open in the editor, resolving once the store has
 * answered the publish request.
 *
 * WordPress may or may not interpose the pre-publish confirmation panel
 * depending on a user preference, so the flow races the panel against the
 * publish request instead of assuming either shape.
 *
 * @param page Editor page.
 */
async function publishOpenPost( page: Page ): Promise< void > {
	const publishResponse = page.waitForResponse(
		( response ) =>
			response.request().method() === 'POST' &&
			/\/wp\/v2\/posts(\/\d+)?(\?|$)/.test( response.url() ) &&
			! response.url().includes( 'autosaves' ) &&
			response.ok()
	);
	const panelPublish = page
		.getByRole( 'region', { name: 'Editor publish' } )
		.getByRole( 'button', { name: 'Publish', exact: true } );

	await page
		.getByRole( 'button', { name: 'Publish', exact: true } )
		.first()
		.click();
	await Promise.race( [
		publishResponse,
		panelPublish.waitFor( { state: 'visible' } ),
	] );
	if ( await panelPublish.isVisible() ) {
		await panelPublish.click();
	}
	await publishResponse;
}

/**
 * Make the block inspector visible.
 *
 * The settings sidebar is a persisted editor preference, so its starting
 * state is whatever the admin profile last left. Retrying the open-if-absent
 * step converges without a fixed sleep: once the inspector control is
 * visible, the callback stops toggling.
 *
 * @param page Editor page.
 */
async function openBlockInspector( page: Page ): Promise< void > {
	const inspectorControl = page.getByRole( 'checkbox', {
		name: DISPLAY_FLAGS_LABEL,
	} );
	await expect( async () => {
		if ( ! ( await inspectorControl.isVisible() ) ) {
			await page
				.getByRole( 'button', { name: 'Settings', exact: true } )
				.click();
		}
		await expect( inspectorControl ).toBeVisible( { timeout: 3_000 } );
	} ).toPass();
}

test(
	'A merchant editing the currency switcher sees the store default and every enabled currency as a choice',
	{
		annotation: [
			{
				type: 'woopayments-contract',
				description: CONTRACT_IDS.adminCurrencies,
			},
		],
		tag: [ tags.WOOPAYMENTS_NATIVE ],
	},
	async ( { adminApi, baseURL, page, runId } ) => {
		const storeBase = requireBaseUrl( baseURL );
		const { enabledCodes, defaultCode } =
			await readEnabledCurrencies( adminApi );

		const post = await createRunPost(
			adminApi,
			runId,
			[ switcherBlockMarkup() ],
			'admin'
		);

		const pageErrors = trackPageErrors( page, storeBase );
		await logInAsAdmin( page );
		const canvas = await openPostEditor( page, post.id );

		// The block is the native switcher, not an "unsupported block"
		// placeholder: an editor without the registered block type would fail
		// here rather than pass on empty markup.
		const block = editorSwitcherBlock( canvas );
		await expect( block ).toHaveCount( 1 );

		// The editor renders the block through ServerSideRender inside
		// `<Disabled>`, which makes the preview non-interactive; the select is
		// therefore addressed structurally within the block rather than by
		// role, but its identity (`name="currency"`) is the projection
		// service's own contract.
		const preview = block.locator( 'select[name="currency"]' );
		await expect( preview ).toBeVisible();

		// Exactly the enabled set, no more and no less: the count pins
		// "no extras" and the per-code check pins "none missing".
		await expect( preview.locator( 'option' ) ).toHaveCount(
			enabledCodes.length
		);
		for ( const code of enabledCodes ) {
			await expect(
				preview.locator( `option[value="${ code }"]` ),
				`the merchant must see ${ code } as a switcher choice`
			).toHaveCount( 1 );
		}
		// The store default is one of those choices, which is the half the
		// contract names explicitly.
		await expect(
			preview.locator( `option[value="${ defaultCode }"]` )
		).toHaveCount( 1 );

		expect( pageErrors() ).toEqual( [] );

		await deleteRunPost( adminApi, post.id );
	}
);

test(
	'A merchant can change the currency switcher presentation choices and the saved block keeps them',
	{
		annotation: [
			{
				type: 'woopayments-contract',
				description: CONTRACT_IDS.updateProperties,
			},
		],
		tag: [ tags.WOOPAYMENTS_NATIVE ],
	},
	async ( { adminApi, baseURL, page, runId } ) => {
		const storeBase = requireBaseUrl( baseURL );
		await readEnabledCurrencies( adminApi );

		// The block starts with no serialized attributes, so the editor shows
		// the registered defaults and every change below is observably a
		// change.
		const post = await createRunPost(
			adminApi,
			runId,
			[ switcherBlockMarkup() ],
			'properties'
		);
		expect(
			parseSwitcherBlocks( await readPostContent( adminApi, post.id ) )
		).toEqual( [ {} ] );

		const pageErrors = trackPageErrors( page, storeBase );
		await logInAsAdmin( page );
		const canvas = await openPostEditor( page, post.id );
		await selectSwitcherBlock( canvas );
		await openBlockInspector( page );

		const displayFlags = page.getByRole( 'checkbox', {
			name: DISPLAY_FLAGS_LABEL,
		} );
		const displaySymbols = page.getByRole( 'checkbox', {
			name: DISPLAY_SYMBOLS_LABEL,
		} );
		const showBorder = page.getByRole( 'checkbox', {
			name: SHOW_BORDER_LABEL,
		} );

		// Observable baseline: the merchant genuinely starts from the
		// registered defaults, so a no-op save could not pass the assertions
		// below.
		await expect( displayFlags ).not.toBeChecked();
		await expect( displaySymbols ).toBeChecked();
		await expect( showBorder ).toBeChecked();

		// Three supported presentation choices, each moved away from its
		// default through the real inspector control. The numeric and color
		// controls are deliberately not driven here: the ledger's disposition
		// for this row keeps one representative save-and-reload boundary
		// rather than coupling native coverage to every current inspector
		// control, and the frontend row proves the numeric/color attributes
		// reach the shopper.
		await displayFlags.check();
		await displaySymbols.uncheck();
		await showBorder.uncheck();

		await expect( displayFlags ).toBeChecked();
		await expect( displaySymbols ).not.toBeChecked();
		await expect( showBorder ).not.toBeChecked();

		// Keyboard save rather than a labelled button: the post editor's save
		// affordance is named differently across WordPress versions, while
		// the shortcut is stable, and the persistence oracle below is the
		// stored representation rather than any notice copy. Awaiting the
		// save round trip in the page also keeps the reload below from racing
		// an in-flight save.
		const saveResponse = page.waitForResponse(
			( response ) =>
				response.request().method() === 'POST' &&
				response.url().includes( `/wp/v2/posts/${ post.id }` ) &&
				! response.url().includes( 'autosaves' ) &&
				response.ok()
		);
		await page.keyboard.press( 'ControlOrMeta+s' );
		await saveResponse;

		// Authoritative persistence: the saved post content carries exactly
		// the three changed attributes. Gutenberg omits attributes equal to
		// their registered default, so this set is both "the changes landed"
		// and "nothing else was touched".
		await expect
			.poll(
				async () =>
					parseSwitcherBlocks(
						await readPostContent( adminApi, post.id )
					),
				{
					message:
						'the saved post content must carry the changed switcher attributes',
					timeout: 30_000,
				}
			)
			.toEqual( [ { flag: true, symbol: false, border: false } ] );

		// Save-and-reload boundary: the merchant's choices come back when the
		// editor is rebuilt from stored content, so this cannot pass on
		// transient client-side state.
		await page.reload();
		await dismissEditorWelcomeGuide( page );
		const reloadedCanvas = await getEditorCanvas( page );
		await selectSwitcherBlock( reloadedCanvas );
		await openBlockInspector( page );
		await expect(
			page.getByRole( 'checkbox', { name: DISPLAY_FLAGS_LABEL } )
		).toBeChecked();
		await expect(
			page.getByRole( 'checkbox', { name: DISPLAY_SYMBOLS_LABEL } )
		).not.toBeChecked();
		await expect(
			page.getByRole( 'checkbox', { name: SHOW_BORDER_LABEL } )
		).not.toBeChecked();

		expect( pageErrors() ).toEqual( [] );

		await deleteRunPost( adminApi, post.id );
	}
);

test(
	'Persisted currency switcher presentation settings reach the shopper-visible control',
	{
		annotation: [
			{
				type: 'woopayments-contract',
				description: CONTRACT_IDS.frontendProperties,
			},
		],
		tag: [ tags.WOOPAYMENTS_NATIVE ],
	},
	async ( { adminApi, baseURL, page, runId } ) => {
		const storeBase = requireBaseUrl( baseURL );
		await readEnabledCurrencies( adminApi );

		// Two independently configured switchers on one page: every
		// presentation attribute differs between them, so the shopper-visible
		// difference is attributable to the saved settings rather than to a
		// theme default that happened to match.
		const post = await createRunPost(
			adminApi,
			runId,
			[
				switcherBlockMarkup( BASELINE_PRESENTATION ),
				switcherBlockMarkup( CONFIGURED_PRESENTATION ),
			],
			'presentation'
		);

		const pageErrors = trackPageErrors( page, storeBase );
		// A fresh anonymous visitor: no admin session, no stored currency.
		await page.context().clearCookies();
		await page.goto( post.link );

		const switchers = contentSwitchers( page );
		await expect( switchers ).toHaveCount( 2 );
		const baseline = switchers.nth( 0 );
		const configured = switchers.nth( 1 );
		await expect( baseline ).toBeVisible();
		await expect( configured ).toBeVisible();

		// Symbol/flag semantics, both directions. The baseline block shows the
		// currency symbol and no flag; the configured block shows the flag and
		// no symbol. The flag half is asserted on markup rather than on text so
		// it is independent of whether WordPress's emoji script leaves the
		// glyph as text or swaps in an <img alt="…"> — the packet for this row
		// puts emoji glyph *implementation* out of scope, not flag semantics.
		const baselineDefaultOption = baseline.locator( 'option[value="USD"]' );
		const configuredDefaultOption = configured.locator(
			'option[value="USD"]'
		);
		await expect( baselineDefaultOption ).toHaveText( '$ USD' );
		expect( await configuredDefaultOption.innerHTML() ).toContain(
			USD_FLAG
		);
		expect( await configuredDefaultOption.textContent() ).not.toContain(
			'$'
		);

		// Representative configured presentation, as computed by the browser
		// rather than as declared: this is what the shopper actually sees.
		await expect( baseline ).toHaveCSS( 'border-top-width', '1px' );
		await expect( baseline ).toHaveCSS( 'border-top-left-radius', '3px' );
		await expect( baseline ).toHaveCSS( 'font-size', '14px' );
		await expect( baseline ).toHaveCSS( 'color', 'rgb(0, 0, 0)' );
		await expect( baseline ).toHaveCSS(
			'background-color',
			'rgba(0, 0, 0, 0)'
		);

		await expect( configured ).toHaveCSS( 'border-top-width', '0px' );
		await expect( configured ).toHaveCSS(
			'border-top-left-radius',
			'12px'
		);
		await expect( configured ).toHaveCSS( 'font-size', '24px' );
		await expect( configured ).toHaveCSS( 'color', 'rgb(255, 0, 0)' );
		await expect( configured ).toHaveCSS(
			'background-color',
			'rgb(0, 255, 0)'
		);

		// Line height is projected onto the switcher's wrapper, whose computed
		// value depends on the theme's inherited font size; the declared value
		// is the deterministic half, matched tolerantly so inline whitespace
		// is not part of the claim.
		await expect( baseline.locator( 'xpath=..' ) ).toHaveAttribute(
			'style',
			/line-height:\s*1\.5\s*;/
		);
		await expect( configured.locator( 'xpath=..' ) ).toHaveAttribute(
			'style',
			/line-height:\s*2\s*;/
		);

		expect( pageErrors() ).toEqual( [] );

		await deleteRunPost( adminApi, post.id );
	}
);

test(
	'A merchant can publish the currency switcher block in content and a visitor gets a working switcher there',
	{
		annotation: [
			{
				type: 'woopayments-contract',
				description: CONTRACT_IDS.publishInContent,
			},
		],
		tag: [ tags.WOOPAYMENTS_NATIVE ],
	},
	async ( { adminApi, baseURL, page, runId } ) => {
		const storeBase = requireBaseUrl( baseURL );
		const { enabledCodes, defaultCode } =
			await readEnabledCurrencies( adminApi );
		const additionalCode = enabledCodes.filter(
			( code ) => code !== defaultCode
		)[ 0 ];
		const postTitle = `WooPayments MC switcher publish ${ runId }`;

		const pageErrors = trackPageErrors( page, storeBase );
		await logInAsAdmin( page );

		// The merchant half of the contract: the block is discoverable by its
		// own title in the inserter and can be placed in post content.
		await page.goto( 'wp-admin/post-new.php' );
		await dismissEditorWelcomeGuide( page );
		await fillPostTitle( page, postTitle );
		await insertBlockFromInserter( page, BLOCK_TITLE );

		const canvas = await getEditorCanvas( page );
		await expect( editorSwitcherBlock( canvas ) ).toHaveCount( 1 );

		await publishOpenPost( page );

		// The editor swaps the draft URL for the saved post's edit URL once
		// the publish response lands; waiting for it keeps the ID read below
		// off a stale address.
		await page.waitForURL( /[?&]post=\d+/ );
		const postId = Number(
			new URL( page.url() ).searchParams.get( 'post' )
		);
		expect(
			Number.isSafeInteger( postId ) && postId > 0,
			`the published post must expose a durable ID: ${ page.url() }`
		).toBe( true );

		// Authoritative saved representation: exactly one switcher block
		// reached the published content, so a double insertion or a silently
		// dropped block cannot pass.
		const publishedPost = await readJson< {
			link: string;
			status: string;
			content: { raw?: string };
		} >(
			await adminApi.get( `${ POSTS_API }/${ postId }?context=edit` ),
			'Published post read'
		);
		expect( publishedPost.status ).toBe( 'publish' );
		expect(
			parseSwitcherBlocks( publishedPost.content.raw ?? '' )
		).toEqual( [ {} ] );

		// Editor reload: the published block parses back intact rather than
		// resolving to an invalid or unsupported block.
		await page.reload();
		await dismissEditorWelcomeGuide( page );
		await expect(
			editorSwitcherBlock( await getEditorCanvas( page ) )
		).toHaveCount( 1 );

		// The shopper half: a fresh anonymous visitor gets exactly one
		// switcher in the published content, offering the enabled set.
		await page.context().clearCookies();
		await page.goto( publishedPost.link );
		const switcher = contentSwitchers( page );
		await expect( switcher ).toHaveCount( 1 );
		await expect( switcher ).toBeVisible();
		await expect( switcher ).toHaveValue( defaultCode );
		await expect( switcher.locator( 'option' ) ).toHaveCount(
			enabledCodes.length
		);
		for ( const code of enabledCodes ) {
			await expect(
				switcher.locator( `option[value="${ code }"]` )
			).toHaveCount( 1 );
		}
		// The published control is a real, operable control rather than
		// rendered markup: it takes focus, and committing a selection is
		// honoured — which is what this row's residual risk asks for
		// ("validate switch behavior, not visibility alone"). Price
		// conversion is deliberately not claimed here; the frozen
		// shopper/multi-currency.spec.ts owns that assertion.
		await switcher.focus();
		await expect( switcher ).toBeFocused();
		await switcher.selectOption( additionalCode );
		await page.waitForURL(
			new RegExp( `[?&]currency=${ additionalCode }` )
		);
		await expect( contentSwitchers( page ) ).toHaveValue( additionalCode );

		expect( pageErrors() ).toEqual( [] );

		await deleteRunPost( adminApi, postId );
	}
);
