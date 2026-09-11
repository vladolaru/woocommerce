interface AdminSessionPage {
	goto: ( path: string ) => Promise< unknown >;
	url: () => string;
	locator: ( selector: string ) => { count: () => Promise< number > };
}

interface FixtureAdminSessionOptions {
	baseURL: string;
	fixtureEnabled: boolean;
	page: AdminSessionPage;
	login: () => Promise< void >;
}

/**
 * Create an explicit storage-state-free role context even when the selected
 * Playwright project carries warmed administrator state.
 */
export function isolatedBrowserContextOptions( baseURL: string ) {
	return {
		baseURL,
		storageState: { cookies: [], origins: [] },
	};
}

/**
 * Consume the readonly project's warmed browser state in fixture CI.
 */
export async function openFixtureAdminSession(
	options: FixtureAdminSessionOptions
): Promise< void > {
	if ( ! options.fixtureEnabled ) {
		await options.login();
		return;
	}

	await options.page.goto( '/wp-admin/' );
	const expected = new URL( options.baseURL );
	const actual = new URL( options.page.url() );
	if (
		actual.origin !== expected.origin ||
		! actual.pathname.startsWith( '/wp-admin/' ) ||
		( await options.page.locator( '#loginform' ).count() ) !== 0 ||
		( await options.page.locator( '#wpadminbar' ).count() ) !== 1
	) {
		throw new Error(
			'Fixture readonly browser did not consume the warmed admin session.'
		);
	}
}

interface FixtureManualCaptureOptions {
	fixtureEnabled: boolean;
	read: () => Promise< boolean >;
	write: ( enabled: boolean ) => Promise< void >;
}

/**
 * Track the fixture transaction test's temporary manual-capture state.
 */
export class FixtureManualCaptureScope {
	private original: boolean | undefined;

	public async before(
		options: FixtureManualCaptureOptions
	): Promise< void > {
		this.original = undefined;
		if ( ! options.fixtureEnabled ) {
			return;
		}

		this.original = await options.read();
		await options.write( true );
		if ( ! ( await options.read() ) ) {
			throw new Error( 'Fixture manual capture did not enable.' );
		}
	}

	public async after(
		options: Pick< FixtureManualCaptureOptions, 'read' | 'write' >
	): Promise< void > {
		if ( this.original === undefined ) {
			return;
		}

		const original = this.original;
		this.original = undefined;
		await options.write( original );
		if ( ( await options.read() ) !== original ) {
			throw new Error( 'Fixture manual capture did not restore.' );
		}
	}
}
