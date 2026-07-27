import assert from 'node:assert/strict';
import { mkdtempSync, mkdirSync, rmSync, writeFileSync } from 'node:fs';
import { dirname, join, resolve } from 'node:path';
import { test } from 'node:test';
import { fileURLToPath } from 'node:url';

import { validateWooPaymentsProjectRouting } from './validate-woopayments-project-routing.mjs';

const binDirectory = dirname( fileURLToPath( import.meta.url ) );
const wooPaymentsTestRoot = resolve(
	binDirectory,
	'../tests/woopayments-native'
);

test( 'WooPayments specs are collected once by their owning projects', () => {
	const temporaryDirectory = mkdtempSync(
		join( wooPaymentsTestRoot, 'routing-fixture-' )
	);
	const nestedDirectory = join( temporaryDirectory, 'future', 'nested' );
	const futureSpec = join(
		nestedDirectory,
		'future-woopayments-routing.spec.ts'
	);

	mkdirSync( nestedDirectory, { recursive: true } );
	writeFileSync(
		futureSpec,
		[
			"import { test } from '@playwright/test';",
			'',
			'test(',
			"\t'future WooPayments nested routing sentinel',",
			"\t{ tag: '@woopayments-native' },",
			'\t() => {}',
			');',
			'',
		].join( '\n' ),
		'utf8'
	);

	try {
		assert.deepEqual( validateWooPaymentsProjectRouting(), {
			baseProjectsCollectWooPayments: false,
			readonlyCollectsProviderTests: false,
			providerCollectsExactly: [
				'shopper-card-payment',
				'merchant-transaction-navigation',
				'merchant-manual-capture',
			],
			transitionCollectsExactly: [ 'saved-method-cutover' ],
			everyReadonlyRetryCount: 0,
			everyProviderRetryCount: 0,
			everyTransitionRetryCount: 0,
			providerWorkerCount: 1,
			transitionWorkerCount: 1,
			futureWooPaymentsSpecProjects: [ 'woopayments-native-readonly' ],
		} );
	} finally {
		rmSync( temporaryDirectory, { recursive: true, force: true } );
	}
} );
