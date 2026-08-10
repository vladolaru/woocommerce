import assert from 'node:assert/strict';
import { mkdtempSync, mkdirSync, rmSync, writeFileSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { dirname, join, resolve } from 'node:path';
import { test } from 'node:test';
import { fileURLToPath } from 'node:url';

import * as projectRouting from './validate-woopayments-project-routing.mjs';

const binDirectory = dirname( fileURLToPath( import.meta.url ) );
const packageRoot = resolve( binDirectory, '../../..' );
const wooPaymentsTestRoot = resolve(
	binDirectory,
	'../tests/woopayments-native'
);
const lowerLayerTarget =
	'plugins/woocommerce/tests/php/includes/class-wc-ajax-test.php';
const closedSpecRow = {
	case_id: 'synthetic-closed-contract',
	migration_state: 'closed',
	native_support_state: 'supported',
	target_contract: 'synthetic closed contract',
	target_path:
		'plugins/woocommerce/tests/e2e/tests/woopayments-native/pilots/synthetic-closed.spec.ts',
};
const validAnnotationRecord = {
	description: closedSpecRow.case_id,
	expectedStatus: 'passed',
	file: 'woopayments-native/pilots/synthetic-closed.spec.ts',
	title: closedSpecRow.target_contract,
};
const multiTargetClosedRow = {
	...closedSpecRow,
	target_path: `${ lowerLayerTarget };${ closedSpecRow.target_path }`,
};
const scenarioClosedRow = {
	...closedSpecRow,
	target_path:
		'plugins/woocommerce/tests/e2e/tests/woopayments-native/scenarios/card-payment.ts',
};
const validScenarioAnnotationRecord = {
	...validAnnotationRecord,
	file: 'woopayments-native/scenarios/card-payment.ts',
};

const validateSyntheticBindings = ( rows, annotations ) =>
	projectRouting.validateContractAnnotationBindings(
		rows,
		annotations,
		packageRoot
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
		assert.deepEqual( projectRouting.validateWooPaymentsProjectRouting(), {
			baseProjectsCollectWooPayments: false,
			readonlyCollectsProviderTests: false,
			providerCollectsExactly: [
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

// ---------------------------------------------------------------------------
// Per-row annotation bindings: a closed, non-retired ledger row whose targets
// include a Playwright test module under tests/e2e must be proven by exactly
// one collected annotation carrying the row's case_id and target_contract in
// that file. No hand-maintained annotation list: uniqueness, bijection for
// closed E2E rows, and orphan rejection are checked as properties.
// ---------------------------------------------------------------------------

test( 'one passing exact annotation satisfies a closed E2E row', () => {
	assert.doesNotThrow( () =>
		validateSyntheticBindings( [ closedSpecRow ], [ validAnnotationRecord ] )
	);
} );

test( 'a closed E2E row without its annotation is rejected', () => {
	assert.throws(
		() => validateSyntheticBindings( [ closedSpecRow ], [] ),
		new RegExp(
			`Closed ledger contract has no collected woopayments-contract annotation: ${ closedSpecRow.case_id }`
		)
	);
} );

test( 'a retired closed contract needs no annotation because nothing was migrated', () => {
	const retiredRow = {
		...closedSpecRow,
		case_id: 'synthetic-retired-contract',
		native_support_state: 'not-applicable-retired',
	};

	assert.doesNotThrow( () => validateSyntheticBindings( [ retiredRow ], [] ) );
} );

test( 'a closed row targeting only lower-layer unit tests needs no annotation', () => {
	const lowerLayerRow = {
		...closedSpecRow,
		target_path: lowerLayerTarget,
	};

	assert.doesNotThrow( () =>
		validateSyntheticBindings( [ lowerLayerRow ], [] )
	);
} );

test( 'a non-closed row needs no annotation even with an E2E target', () => {
	const deferredRow = {
		...closedSpecRow,
		migration_state: 'deferred',
		native_support_state: 'blocked-environment',
	};

	assert.doesNotThrow( () =>
		validateSyntheticBindings( [ deferredRow ], [] )
	);
} );

test( 'an annotation may exist before its row closes', () => {
	const deferredRow = {
		...closedSpecRow,
		migration_state: 'deferred',
		native_support_state: 'blocked-environment',
	};

	assert.doesNotThrow( () =>
		validateSyntheticBindings( [ deferredRow ], [ validAnnotationRecord ] )
	);
} );

test( 'a closed scenario-module target binds its annotation like any spec', () => {
	assert.doesNotThrow( () =>
		validateSyntheticBindings(
			[ scenarioClosedRow ],
			[ validScenarioAnnotationRecord ]
		)
	);
} );

test( 'closed annotations must use their exact ledger title', () => {
	assert.throws(
		() =>
			validateSyntheticBindings(
				[ closedSpecRow ],
				[
					{
						...validAnnotationRecord,
						title: 'an unrelated test',
					},
				]
			),
		new RegExp(
			`Closed ledger contract annotation has the wrong test title: ${ closedSpecRow.case_id }`
		)
	);
} );

test( 'closed annotations must live in a target file of their row', () => {
	assert.throws(
		() =>
			validateSyntheticBindings(
				[ closedSpecRow ],
				[
					{
						...validAnnotationRecord,
						file: 'woopayments-native/pilots/unrelated.spec.ts',
					},
				]
			),
		new RegExp(
			`Closed ledger contract annotation has the wrong target file: ${ closedSpecRow.case_id }`
		)
	);
} );

test( 'a multi-target closed row binds its annotation to its E2E target', () => {
	assert.doesNotThrow( () =>
		validateSyntheticBindings(
			[ multiTargetClosedRow ],
			[ validAnnotationRecord ]
		)
	);
} );

test( 'a multi-target closed row rejects an annotation from an unrelated spec', () => {
	assert.throws(
		() =>
			validateSyntheticBindings(
				[ multiTargetClosedRow ],
				[
					{
						...validAnnotationRecord,
						file: 'woopayments-native/merchant/unrelated.spec.ts',
					},
				]
			),
		new RegExp(
			`Closed ledger contract annotation has the wrong target file: ${ closedSpecRow.case_id }`
		)
	);
} );

for ( const expectedStatus of [ 'skipped', 'failed' ] ) {
	test( `annotations expecting to be ${ expectedStatus } cannot prove closed contracts`, () => {
		assert.throws(
			() =>
				validateSyntheticBindings(
					[ closedSpecRow ],
					[
						{
							...validAnnotationRecord,
							expectedStatus,
						},
					]
				),
			new RegExp(
				`Closed ledger contract annotation must expect to pass: ${ closedSpecRow.case_id }`
			)
		);
	} );
}

test( 'contract annotation records must be unique per case_id', () => {
	assert.throws(
		() =>
			validateSyntheticBindings(
				[ closedSpecRow ],
				[ validAnnotationRecord, validAnnotationRecord ]
			),
		new RegExp(
			`Duplicate woopayments-contract annotation records for ledger contract: ${ closedSpecRow.case_id }`
		)
	);
} );

test( 'orphan contract annotations are rejected', () => {
	assert.throws(
		() =>
			validateSyntheticBindings(
				[ closedSpecRow ],
				[
					{
						...validAnnotationRecord,
						description: 'unknown-contract',
					},
				]
			),
		/woopayments-contract annotation does not resolve to a ledger contract: unknown-contract/
	);
} );

// ---------------------------------------------------------------------------
// Provider machinery reachability must match declared provider tags.
// ---------------------------------------------------------------------------

const providerDriverImport =
	"import { completeCardCheckout } from '../../../utils/woopayments-native/drivers/checkout';";
const rogueSpecFile = 'woopayments-native/shopper/rogue.spec.ts';
const collectedTest = ( tags ) => ( {
	file: rogueSpecFile,
	tags: [ 'woopayments-native', ...tags ],
	title: 'reaches the provider',
} );

// The walk reads real files, so each case builds a throwaway package tree
// rather than writing into the checked-in test tree.
const withSyntheticPackage = ( files, assertion ) => {
	const packageDirectory = mkdtempSync(
		join( tmpdir(), 'woopayments-machinery-' )
	);

	try {
		for ( const [ relativePath, contents ] of Object.entries( files ) ) {
			const absolutePath = join( packageDirectory, relativePath );

			mkdirSync( dirname( absolutePath ), { recursive: true } );
			writeFileSync( absolutePath, contents, 'utf8' );
		}

		assertion( packageDirectory );
	} finally {
		rmSync( packageDirectory, { recursive: true, force: true } );
	}
};

const validateMachineryTags = ( tests, packageDirectory ) =>
	projectRouting.validateProviderMachineryTags( tests, packageDirectory );

test( 'a test reaching a provider driver without a provider tag is rejected', () => {
	withSyntheticPackage(
		{ [ `tests/e2e/tests/${ rogueSpecFile }` ]: providerDriverImport },
		( packageDirectory ) => {
			assert.throws(
				() =>
					validateMachineryTags(
						[ collectedTest( [] ) ],
						packageDirectory
					),
				/must carry @woopayments-provider or @woopayments-transition/
			);
		}
	);
} );

test( 'either provider involvement tag satisfies the provider machinery rule', () => {
	withSyntheticPackage(
		{ [ `tests/e2e/tests/${ rogueSpecFile }` ]: providerDriverImport },
		( packageDirectory ) => {
			for ( const tag of [
				'woopayments-provider',
				'woopayments-transition',
			] ) {
				assert.doesNotThrow( () =>
					validateMachineryTags(
						[ collectedTest( [ tag ] ) ],
						packageDirectory
					)
				);
			}
		}
	);
} );

test( 'provider-prefixed utility modules count as provider machinery', () => {
	withSyntheticPackage(
		{
			[ `tests/e2e/tests/${ rogueSpecFile }` ]:
				"import { requireApprovedProviderFixture } from '../../../utils/woopayments-native/provider-fixture';",
		},
		( packageDirectory ) => {
			assert.throws(
				() =>
					validateMachineryTags(
						[ collectedTest( [] ) ],
						packageDirectory
					),
				/must carry @woopayments-provider/
			);
		}
	);
} );

test( 'provider machinery reached through a local scenario module is caught', () => {
	withSyntheticPackage(
		{
			[ `tests/e2e/tests/${ rogueSpecFile }` ]:
				"import { runScenario } from '../scenarios/shared-scenario';",
			'tests/e2e/tests/woopayments-native/scenarios/shared-scenario.ts':
				providerDriverImport,
		},
		( packageDirectory ) => {
			assert.throws(
				() =>
					validateMachineryTags(
						[ collectedTest( [] ) ],
						packageDirectory
					),
				/must carry @woopayments-provider/
			);
		}
	);
} );

test( 'the shared fixtures barrel does not make every test provider-involved', () => {
	withSyntheticPackage(
		{
			[ `tests/e2e/tests/${ rogueSpecFile }` ]:
				"import { expect, tags, test } from '../../../fixtures/woopayments-native';",
			// The real barrel imports provider machinery, which is exactly why
			// the walk must not descend into it.
			'tests/e2e/fixtures/woopayments-native.ts':
				"import { completeCardCheckout } from '../utils/woopayments-native/drivers/checkout';",
		},
		( packageDirectory ) => {
			assert.doesNotThrow( () =>
				validateMachineryTags(
					[ collectedTest( [] ) ],
					packageDirectory
				)
			);
		}
	);
} );
