import { expect, test } from '@playwright/test';
import { execFileSync } from 'node:child_process';
import path from 'node:path';

import { ADMIN_STATE_PATH } from '../../playwright.config';

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

type JsonSuite = {
	specs: Array< {
		title: string;
		tests: Array< {
			annotations: Array< { type: string; description: string } >;
			status: string;
		} >;
	} >;
	suites?: JsonSuite[];
};

function readProfileDispositions( suites: JsonSuite[] ): unknown[] {
	return suites.flatMap( ( suite ) => [
		...suite.specs.map( ( spec ) => ( {
			title: spec.title,
			status: spec.tests[ 0 ]?.status,
			reason: spec.tests[ 0 ]?.annotations.find(
				( annotation ) => annotation.type === 'profile-unavailable'
			)?.description,
		} ) ),
		...readProfileDispositions( suite.suites ?? [] ),
	] );
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
				use?: { storageState?: string };
		  }
		| undefined;

	expect( config?.globalSetup ).toMatch( /readonly-global-setup/ );
	expect( project?.workers ).toBe( 1 );
	expect( project?.use?.storageState ).toBe( ADMIN_STATE_PATH );
	expect( project?.grepInvert?.test( '@woopayments-extension-compat' ) ).toBe(
		true
	);
	expect( project?.grepInvert?.test( '@woopayments-provider' ) ).toBe( true );
} );

test( 'actual Playwright project commands expose readonly setup only when readonly is selected', () => {
	const pluginRoot = path.resolve( __dirname, '../../../..' );
	const configPath = path.join(
		pluginRoot,
		'tests/e2e/envs/woopayments-native/playwright.config.ts'
	);
	const selectionCases = [
		[ 'woopayments-native-provider', false, 'undefined' ],
		[ 'woopayments-native-transition', false, 'undefined' ],
		[ 'woopayments-native-extension-compat', false, 'undefined' ],
		[ 'woopayments-native-readonly', true, 'string' ],
	] as const;

	for ( const [
		projectName,
		expectedReadonlySetup,
		expectedStatePathType,
	] of selectionCases ) {
		const output = execFileSync(
			'pnpm',
			[
				'exec',
				'playwright',
				'test',
				`--config=${ configPath }`,
				'--list',
				'--reporter=json',
				`--project=${ projectName }`,
			],
			{
				cwd: pluginRoot,
				env: {
					...process.env,
					BASE_URL: 'http://localhost:8086',
				},
				encoding: 'utf8',
			}
		);
		const document = JSON.parse( output ) as {
			config: {
				projects: Array< {
					name: string;
					metadata: Record< string, unknown >;
				} >;
			};
		};
		const selected = document.config.projects.find(
			( project ) => project.name === projectName
		);

		expect( selected?.metadata.woopaymentsReadonlySetup ).toBe(
			expectedReadonlySetup
		);
		expect( typeof selected?.metadata.woopaymentsAdminStatePath ).toBe(
			expectedStatePathType
		);
	}
} );

test( 'the CI profile-disposition project lists only the two unavailable provider contracts', () => {
	const pluginRoot = path.resolve( __dirname, '../../../..' );
	const configPath = path.join(
		pluginRoot,
		'tests/e2e/envs/woopayments-native/playwright.config.ts'
	);
	const output = execFileSync(
		'pnpm',
		[
			'exec',
			'playwright',
			'test',
			`--config=${ configPath }`,
			'--list',
			'--reporter=json',
			'--project=woopayments-native-ci-profile-skips',
		],
		{
			cwd: pluginRoot,
			env: {
				...process.env,
				BASE_URL: 'http://localhost:8086',
			},
			encoding: 'utf8',
		}
	);
	const document = JSON.parse( output ) as { suites: JsonSuite[] };

	expect( readProfileDispositions( document.suites ) ).toEqual( [
		{
			title: 'Klarna Checkout › shows provider-hosted messaging in the product page @woopayments-provider',
			status: 'skipped',
			reason: 'RULE 5: provider-hosted Klarna iframe content requires the connected provider profile.',
		},
		{
			title: 'invalid card input yields accessible field-associated errors before any payment dispatch @woopayments-provider',
			status: 'skipped',
			reason: 'RULE 5: provider-owned card field validation and accessibility require the connected provider profile.',
		},
	] );
} );

test( 'missing extension compatibility skips before any authenticated fixture setup', () => {
	const pluginRoot = path.resolve( __dirname, '../../../..' );
	const configPath = path.join(
		pluginRoot,
		'tests/e2e/envs/woopayments-native/playwright.config.ts'
	);
	const output = execFileSync(
		'pnpm',
		[
			'exec',
			'playwright',
			'test',
			`--config=${ configPath }`,
			'--reporter=json',
			'--project=woopayments-native-extension-compat',
		],
		{
			cwd: pluginRoot,
			env: {
				...process.env,
				BASE_URL: 'http://127.0.0.1:1',
				E2E_WOOPAYMENTS_EXTENSION_COMPAT: '',
			},
			encoding: 'utf8',
		}
	);
	const document = JSON.parse( output ) as {
		stats: { expected: number; skipped: number; unexpected: number };
	};

	expect( document.stats ).toMatchObject( {
		expected: 0,
		skipped: 1,
		unexpected: 0,
	} );
} );
