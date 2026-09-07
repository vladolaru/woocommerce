import { expect, test } from '@playwright/test';

async function loadConfig() {
	process.env.BASE_URL ??= 'http://localhost:8086';
	const configModule = await import(
		'../../envs/woopayments-native/playwright.config'
	);
	const importedConfig =
		configModule.default as typeof configModule.default & {
			default?: typeof configModule.default;
		};
	return importedConfig.projects ? importedConfig : importedConfig.default;
}

test( 'provider and transition projects serialize with truthful metadata', async () => {
	const config = await loadConfig();

	for ( const name of [
		'woopayments-native-provider',
		'woopayments-native-transition',
	] ) {
		const project = ( config?.projects ?? [] ).find(
			( candidate ) => candidate.name === name
		) as
			| {
					retries?: number;
					workers?: number;
					metadata?: { woopaymentsWorkerLimit?: number };
			  }
			| undefined;

		expect( project, `${ name } project must exist` ).toBeTruthy();
		expect( project?.retries ).toBe( 0 );
		expect( project?.workers ).toBe( 1 );
		expect( project?.metadata?.woopaymentsWorkerLimit ).toBe(
			project?.workers
		);
	}
} );

test( 'readonly is serial, seeded globally, and excludes external compatibility profiles', async () => {
	const config = await loadConfig();
	const project = ( config?.projects ?? [] ).find(
		( candidate ) => candidate.name === 'woopayments-native-readonly'
	) as
		| {
				workers?: number;
				grepInvert?: RegExp;
		  }
		| undefined;

	expect( config?.globalSetup ).toMatch( /readonly-global-setup/ );
	expect( project?.workers ).toBe( 1 );
	expect( project?.grepInvert?.test( '@woopayments-extension-compat' ) ).toBe(
		true
	);
	expect( project?.grepInvert?.test( '@woopayments-provider' ) ).toBe( true );
} );
