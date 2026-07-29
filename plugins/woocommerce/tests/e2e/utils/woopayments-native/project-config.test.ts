import { expect, test } from '@playwright/test';

test( 'provider and transition projects serialize with truthful metadata', async () => {
	process.env.BASE_URL ??= 'http://localhost:8086';
	const configModule = await import(
		'../../envs/woopayments-native/playwright.config'
	);
	const importedConfig =
		configModule.default as typeof configModule.default & {
			default?: typeof configModule.default;
		};
	const config = importedConfig.projects
		? importedConfig
		: importedConfig.default;

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
