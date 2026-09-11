import type { APIRequestContext } from '@playwright/test';

import type { ProviderWriteSession } from '../../../fixtures/woopayments-native';
import { ResourceQuarantineRequiredError } from '../resource-locks';

/**
 * Activates a block theme for the duration of one case and puts the store back.
 *
 * Why this exists as a driver rather than as a few lines in a spec: the active
 * theme is the most global thing a WooPayments run can touch. Every other spec
 * in this tree - Classic checkout markup, Blocks notice templates, the merchant
 * screens - renders through whatever theme is active, so a run that switches the
 * theme and does not switch it back does not fail its own case, it fails
 * everything that runs afterwards, quietly and confusingly. Restoration is
 * therefore treated the way the card-testing-protection controller treats its
 * option rows: snapshot first, mutate second, verify the mutation, and on the
 * way out restore and *read the store back* rather than assume the write landed.
 * A restore that cannot be proven raises `ResourceQuarantineRequiredError` with
 * `restoration-failed`, which stops the account and store resources rather than
 * letting the next spec discover the wrong theme by failing on a selector.
 *
 * `FIDELITY-CLAIMS.md` names one shared snapshot/restore driver for block-theme
 * activation, used by `basic-card-charge`'s `B1f`/`B1pf` now and by the Site
 * Editor 3DS spec later; this is it.
 *
 * Two deliberate boundaries.
 *
 * **It refuses to run when the target theme is already active.** The standing
 * store is a described environment - `plugins/woocommerce/.wp-env.json` installs
 * Twenty Twenty-Four alongside the store's own Twenty Twenty-Five - so finding
 * the store already on the theme this driver activates means some earlier run
 * did not restore it. Adopting that as the baseline would launder an unrestored
 * store into a passing run and then "restore" it to the wrong theme. Failing
 * before anything is mutated is the honest answer, and it costs no provider
 * write.
 *
 * **It reports what WordPress discloses about block-theme-ness, and asserts
 * nothing about the rendered surface.** `is_block_theme` is read from the themes
 * route when WordPress publishes it and a `false` is refused, but a caller that
 * needs to prove the shopper actually met a Site Editor surface must observe
 * that on the page - WooCommerce prints `wp_is_block_theme()` onto the body as
 * `woocommerce-uses-block-theme`, which is a stronger oracle than a theme
 * header because it is the predicate the storefront itself branched on.
 */

const THEMES_REST_PATH = '/wp-json/wp/v2/themes';
const THEMES_SCREEN_PATH = '/wp-admin/themes.php';
const THEME_SLUG_PATTERN = /^[A-Za-z0-9][A-Za-z0-9._-]*$/;

/**
 * The capability a provider-fixture approval must list before this driver
 * changes the store's theme. Named for the act, not for the family that asks:
 * the same approval covers every suite that shares the driver.
 */
export const BLOCK_THEME_CAPABILITY = 'block-theme-activation';

export interface BlockThemeIdentity {
	stylesheet: string;
	template: string;
	name: string;
	/**
	 * `undefined` when this WordPress does not publish the field in the context
	 * the driver reads. Absence is not evidence of a classic theme, so it is
	 * carried rather than coerced.
	 */
	isBlockTheme: boolean | undefined;
}

export interface InstalledTheme extends BlockThemeIdentity {
	active: boolean;
}

/**
 * The two store operations this driver needs, behind an interface so the
 * restoration logic can be tested without a WordPress.
 */
export interface BlockThemeGateway {
	listInstalledThemes: () => Promise< InstalledTheme[] >;
	activateTheme: ( stylesheet: string ) => Promise< void >;
}

export interface BlockThemeScope {
	/** The theme the store was on when this scope opened. */
	readonly baseline: BlockThemeIdentity;
	/** The theme the store is on inside the callback, proven by a read. */
	readonly active: BlockThemeIdentity;
}

interface WithActiveBlockThemeOptions {
	gateway?: BlockThemeGateway;
}

function fail( message: string ): never {
	throw new Error( `Block theme activation ${ message }` );
}

function quarantine(
	message: string,
	reasonCode: ConstructorParameters<
		typeof ResourceQuarantineRequiredError
	>[ 1 ],
	primaryError?: unknown
): ResourceQuarantineRequiredError {
	return new ResourceQuarantineRequiredError(
		message,
		reasonCode,
		primaryError
	);
}

function escapeRegExp( value: string ): string {
	return value.replace( /[.*+?^${}()|[\]\\]/g, '\\$&' );
}

function requiredString( value: unknown, label: string ): string {
	if ( typeof value !== 'string' || ! value.trim() ) {
		fail( `requires a non-empty ${ label }.` );
	}
	return value;
}

function readThemeName( value: unknown ): string {
	if ( typeof value === 'string' ) {
		return value;
	}
	if ( typeof value === 'object' && value !== null && 'rendered' in value ) {
		return requiredString(
			( value as { rendered?: unknown } ).rendered,
			'theme name'
		);
	}
	return fail( 'requires a theme name.' );
}

function readBlockThemeFlag( value: unknown ): boolean | undefined {
	if ( value === undefined ) {
		return undefined;
	}
	if ( typeof value !== 'boolean' ) {
		fail( 'requires the block-theme flag to be boolean when disclosed.' );
	}
	return value;
}

function identity( theme: InstalledTheme ): BlockThemeIdentity {
	return {
		stylesheet: theme.stylesheet,
		template: theme.template,
		name: theme.name,
		isBlockTheme: theme.isBlockTheme,
	};
}

function sameIdentity(
	left: BlockThemeIdentity,
	right: BlockThemeIdentity
): boolean {
	return (
		left.stylesheet === right.stylesheet &&
		left.template === right.template &&
		left.name === right.name &&
		left.isBlockTheme === right.isBlockTheme
	);
}

/**
 * Reads and activates themes through WordPress itself.
 *
 * The read is the REST themes route, which is the same source
 * `shopper/theme-compatibility.spec.ts` treats as authoritative for "which
 * theme is active". The write is the Themes screen's own activation link,
 * because WordPress exposes no REST route that switches a theme; this is the
 * mechanism `@wordpress/e2e-test-utils-playwright` uses for the same reason,
 * and the nonce is taken from the screen rather than minted, so an
 * unauthenticated or under-privileged context fails here instead of silently
 * doing nothing.
 */
export class WordPressAdminBlockThemeGateway implements BlockThemeGateway {
	private readonly api: APIRequestContext;

	public constructor( api: APIRequestContext ) {
		this.api = api;
	}

	public async listInstalledThemes(): Promise< InstalledTheme[] > {
		const response = await this.api.get(
			`${ THEMES_REST_PATH }?status=active%2Cinactive&per_page=100`
		);
		if ( ! response.ok() ) {
			fail(
				`could not list installed themes: HTTP ${ response.status() }.`
			);
		}
		const themes = ( await response.json() ) as unknown;
		if ( ! Array.isArray( themes ) || themes.length === 0 ) {
			fail( 'requires WordPress to disclose at least one theme.' );
		}

		return themes.map( ( value, index ) => {
			if ( typeof value !== 'object' || value === null ) {
				fail( `requires theme ${ index + 1 } to be an object.` );
			}
			const theme = value as Record< string, unknown >;
			return {
				stylesheet: requiredString( theme.stylesheet, 'stylesheet' ),
				template: requiredString( theme.template, 'template' ),
				name: readThemeName( theme.name ),
				isBlockTheme: readBlockThemeFlag( theme.is_block_theme ),
				active: theme.status === 'active',
			};
		} );
	}

	public async activateTheme( stylesheet: string ): Promise< void > {
		const screen = await this.api.get( THEMES_SCREEN_PATH );
		if ( ! screen.ok() ) {
			fail(
				`could not open the Themes screen: HTTP ${ screen.status() }.`
			);
		}
		const html = await screen.text();
		const encoded = escapeRegExp( encodeURIComponent( stylesheet ) );
		// The exact stylesheet first. The optional folder form is the fallback
		// WordPress itself needs for a theme inside a subdirectory; trying the
		// exact form first is what keeps `twentytwentyfour` from matching a
		// `twentytwentyfour-child__*` sibling, of which this store has three.
		const match =
			html.match(
				new RegExp(
					`action=activate&amp;stylesheet=${ encoded }&amp;_wpnonce=([a-z0-9]+)`
				)
			) ??
			html.match(
				new RegExp(
					`action=activate&amp;stylesheet=([a-z0-9-]+%2F${ encoded })&amp;_wpnonce=([a-z0-9]+)`
				)
			);
		if ( ! match ) {
			fail(
				`could not find an activation link for theme ${ stylesheet }; it is probably not installed on this store.`
			);
		}
		const activation = match[ 0 ].replace( /&amp;/g, '&' );
		const activated = await this.api.get(
			`${ THEMES_SCREEN_PATH }?${ activation }`
		);
		if ( ! activated.ok() ) {
			fail(
				`could not activate theme ${ stylesheet }: HTTP ${ activated.status() }.`
			);
		}
		await activated.dispose();
	}
}

async function readActiveTheme(
	gateway: BlockThemeGateway
): Promise< BlockThemeIdentity > {
	const active = ( await gateway.listInstalledThemes() ).filter(
		( theme ) => theme.active
	);
	if ( active.length !== 1 ) {
		fail(
			`requires exactly one active theme; WordPress reported ${ active.length }.`
		);
	}
	return identity( active[ 0 ] );
}

/**
 * Runs `callback` with `stylesheet` active, and returns the store to the theme
 * it was on with a verified read.
 *
 * Must be called inside owned provider write locks: the theme is store state,
 * and `assertCanWrite` is re-checked before each mutation so a lock lost
 * mid-case cannot become a theme change nobody owns. It deliberately does not
 * acquire locks of its own, because its only protection-on caller already runs
 * inside the card-testing-protection controller's lock scope, and those locks
 * cannot nest.
 */
export async function withActiveBlockTheme< Result >(
	session: ProviderWriteSession,
	runId: string,
	stylesheet: string,
	callback: ( scope: BlockThemeScope ) => Promise< Result >,
	options: WithActiveBlockThemeOptions = {}
): Promise< Result > {
	if ( session.runtime !== 'native' ) {
		fail( 'requires the native runtime.' );
	}
	if ( ! runId || runId !== session.runId ) {
		fail( 'requires the active run ID.' );
	}
	if ( ! THEME_SLUG_PATTERN.test( stylesheet ) ) {
		fail( 'requires a plain theme stylesheet slug.' );
	}
	session.requireApprovedProviderFixture( BLOCK_THEME_CAPABILITY );
	await session.assertCanWrite();

	const gateway =
		options.gateway ??
		new WordPressAdminBlockThemeGateway( session.adminApi );

	const installed = await gateway.listInstalledThemes();
	const activeThemes = installed.filter( ( theme ) => theme.active );
	if ( activeThemes.length !== 1 ) {
		fail(
			`requires exactly one active theme; WordPress reported ${ activeThemes.length }.`
		);
	}
	const baseline = identity( activeThemes[ 0 ] );
	const target = installed.find(
		( theme ) => theme.stylesheet === stylesheet
	);
	if ( ! target ) {
		fail( `requires theme ${ stylesheet } to be installed on this store.` );
	}
	if ( target.isBlockTheme === false ) {
		fail( `requires theme ${ stylesheet } to be a block theme.` );
	}
	if ( baseline.stylesheet === stylesheet ) {
		fail(
			`requires the store to be at rest on another theme; ${ stylesheet } is already active, which usually means an earlier run did not restore it.`
		);
	}

	// Everything from the first write onwards is inside the restored region.
	// An activation that throws may still have landed, so the restore has to
	// run for it too; only a failure before the first write skips restoration,
	// because then there is provably nothing to put back.
	await session.assertCanWrite();
	let result: Result | undefined;
	let primaryError: unknown;
	try {
		await gateway.activateTheme( stylesheet );
		const active = await readActiveTheme( gateway );
		if (
			active.stylesheet !== stylesheet ||
			active.template !== stylesheet
		) {
			fail(
				`did not take for ${ stylesheet }; the store reports ${ active.stylesheet }.`
			);
		}
		if ( active.isBlockTheme === false ) {
			fail(
				`activated ${ stylesheet }, which WordPress reports is not a block theme.`
			);
		}
		result = await callback( { baseline, active } );
	} catch ( error ) {
		primaryError = error;
	}

	let restorationError: unknown;
	try {
		await session.assertCanWrite();
		await gateway.activateTheme( baseline.stylesheet );
		const restored = await readActiveTheme( gateway );
		if ( ! sameIdentity( restored, baseline ) ) {
			throw new Error(
				`the store came back on ${ restored.stylesheet }, not ${ baseline.stylesheet }.`
			);
		}
	} catch ( error ) {
		restorationError = error;
	}

	if ( restorationError !== undefined ) {
		throw quarantine(
			`The store was not returned to theme ${ baseline.stylesheet }.`,
			'restoration-failed',
			primaryError ?? restorationError
		);
	}
	if ( primaryError !== undefined ) {
		throw primaryError;
	}
	return result as Result;
}
