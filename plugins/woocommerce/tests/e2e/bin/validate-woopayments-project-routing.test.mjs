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
const terminalRow = {
	case_id: 'synthetic-terminal-contract',
	migration_state: 'verified',
	target_contract: 'synthetic transition contract',
	target_path:
		'plugins/woocommerce/tests/e2e/tests/woopayments-native/pilots/synthetic-transition.spec.ts',
};
const validAnnotationRecord = {
	description: terminalRow.case_id,
	expectedStatus: 'passed',
	file: 'woopayments-native/pilots/synthetic-transition.spec.ts',
	projectName: 'woopayments-native-transition',
	tags: [
		'woopayments-native',
		'woopayments-provider',
		'woopayments-transition',
	],
	title: terminalRow.target_contract,
};
const correctedRefundLowerLayerTarget =
	'plugins/woocommerce/tests/php/includes/class-wc-ajax-test.php';
const refundValidationSmokeTarget =
	'plugins/woocommerce/tests/e2e/tests/woopayments-native/merchant/orders-refunds.spec.ts';
const multiTargetTerminalRow = {
	...terminalRow,
	target_path: `${ correctedRefundLowerLayerTarget };${ refundValidationSmokeTarget }`,
};
const validMultiTargetAnnotationRecord = {
	...validAnnotationRecord,
	file: 'woopayments-native/merchant/orders-refunds.spec.ts',
};
const scenarioTarget =
	'plugins/woocommerce/tests/e2e/tests/woopayments-native/scenarios/card-payment.ts';
const implementedScenarioRow = {
	case_id: 'synthetic-implemented-scenario-contract',
	migration_state: 'implemented',
	target_contract: 'synthetic implemented scenario contract',
	target_path: scenarioTarget,
};
const secondImplementedScenarioRow = {
	...implementedScenarioRow,
	case_id: 'second-synthetic-implemented-scenario-contract',
	target_contract: 'second synthetic implemented scenario contract',
};
const validScenarioAnnotationRecord = {
	description: implementedScenarioRow.case_id,
	expectedStatus: 'passed',
	file: 'woopayments-native/scenarios/card-payment.ts',
	projectName: 'woopayments-native-provider',
	tags: [ 'woopayments-native', 'woopayments-provider', 'woopayments-pr' ],
	title: implementedScenarioRow.target_contract,
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
			wooPaymentsContractAnnotations: [
				'default::chromium::tests/e2e/specs/wcpay/merchant/merchant-admin-transactions.spec.ts:14::Admin transactions › page should load without errors',
				'default::chromium::tests/e2e/specs/wcpay/merchant/merchant-orders-manual-capture.spec.ts:39::Order › Manual Capture › should create an "On hold" order then capture the charge',
				'default::chromium::tests/e2e/specs/wcpay/shopper/shopper-checkout-failures.spec.ts:155::Shopper › Checkout › Failures with various cards › should throw an error that the card was declined due to incorrect card number',
				'default::chromium::tests/e2e/specs/wcpay/shopper/shopper-checkout-failures.spec.ts:89::Shopper › Checkout › Failures with various cards › should throw an error that the card CVV number is invalid',
				'default::chromium::tests/e2e/specs/wcpay/shopper/shopper-checkout-purchase.spec.ts:53::Successful purchase › Carding protection false › using a basic card',
				'default::chromium::tests/e2e/specs/wcpay/shopper/shopper-checkout-purchase.spec.ts:53::Successful purchase › Carding protection true › using a basic card',
				'default::chromium::tests/e2e/specs/wcpay/shopper/shopper-checkout-save-card-and-purchase.spec.ts:117::Saved cards › When using a basic card added on checkout › should not allow guest user to save the card',
				'default::chromium::tests/e2e/specs/wcpay/shopper/shopper-checkout-save-card-and-purchase.spec.ts:93::Saved cards › When using a basic card added on checkout › should process a payment with the saved card',
				'default::chromium::tests/e2e/specs/wcpay/shopper/shopper-multi-currency-widget.spec.ts:40::Shopper Multi-Currency widget › should display currency switcher widget if multi-currency is enabled',
				'default::chromium::tests/e2e/specs/wcpay/shopper/shopper-multi-currency-widget.spec.ts:59::Shopper Multi-Currency widget › Should allow shopper to switch currency › at the product page',
				'default::chromium::tests/e2e/specs/wcpay/shopper/shopper-multi-currency-widget.spec.ts:63::Shopper Multi-Currency widget › Should allow shopper to switch currency › at the cart page',
				'default::chromium::tests/e2e/specs/wcpay/shopper/shopper-multi-currency-widget.spec.ts:67::Shopper Multi-Currency widget › Should allow shopper to switch currency › at the checkout page',
				'default::chromium::tests/e2e/specs/wcpay/shopper/shopper-myaccount-payment-methods-add-fail.spec.ts:71::Payment Methods › when attempting to add a declined-incorrect card › it should not add the card',
				'default::chromium::tests/e2e/specs/wcpay/shopper/shopper-myaccount-saved-cards.spec.ts:214::Shopper can save and delete cards › Testing card: basic › should be able to set the basic card as default payment method',
				'default::chromium::tests/e2e/specs/wcpay/shopper/shopper-wc-blocks-checkout-failures.spec.ts:90::WooCommerce Blocks › Checkout failures › Should show error – Your card number is invalid.',
				'default::chromium::tests/e2e/specs/wcpay/shopper/shopper-wc-blocks-checkout-failures.spec.ts:90::WooCommerce Blocks › Checkout failures › Should show error – Your card’s expiration year is in the past.',
				'default::chromium::tests/e2e/specs/wcpay/shopper/shopper-wc-blocks-checkout-failures.spec.ts:90::WooCommerce Blocks › Checkout failures › Should show error – Your card’s security code is incomplete.',
				'default::chromium::tests/e2e/specs/wcpay/shopper/shopper-wc-blocks-saved-card-checkout-and-usage.spec.ts:71::WooCommerce Blocks › Saved cards › should process a payment with the saved card from Blocks checkout',
			],
		} );
	} finally {
		rmSync( temporaryDirectory, { recursive: true, force: true } );
	}
} );

test( 'a retired terminal contract needs no annotation because nothing was migrated', () => {
	const retiredRow = {
		...terminalRow,
		case_id: 'synthetic-retired-contract',
		migration_state: 'closed',
		native_support_state: 'not-applicable-retired',
	};

	assert.doesNotThrow( () =>
		validateSyntheticBindings( [ retiredRow ], [] )
	);
} );

test( 'a non-retired terminal contract still requires its annotation', () => {
	assert.throws(
		() => validateSyntheticBindings( [ terminalRow ], [] ),
		new RegExp(
			`Terminal ledger contract has no collected woopayments-contract annotation: ${ terminalRow.case_id }`
		)
	);
} );

test( 'two implemented contracts can bind to one canonical scenario file', () => {
	assert.doesNotThrow( () =>
		validateSyntheticBindings(
			[ implementedScenarioRow, secondImplementedScenarioRow ],
			[
				validScenarioAnnotationRecord,
				{
					...validScenarioAnnotationRecord,
					description: secondImplementedScenarioRow.case_id,
					title: secondImplementedScenarioRow.target_contract,
				},
			]
		)
	);
} );

test( 'implemented scenario contract annotations must be unique per contract ID', () => {
	assert.throws(
		() =>
			validateSyntheticBindings(
				[ implementedScenarioRow ],
				[ validScenarioAnnotationRecord, validScenarioAnnotationRecord ]
			),
		/Duplicate woopayments-contract annotation records for ledger contract: synthetic-implemented-scenario-contract/
	);
} );

test( 'implemented scenario annotations must use their canonical ledger path', () => {
	assert.throws(
		() =>
			validateSyntheticBindings(
				[ implementedScenarioRow ],
				[
					{
						...validScenarioAnnotationRecord,
						file: 'woopayments-native/scenarios/unrelated.ts',
					},
				]
			),
		/Scenario ledger contract annotation has the wrong target file: synthetic-implemented-scenario-contract/
	);
} );

for ( const missingTag of [
	'woopayments-native',
	'woopayments-provider',
	'woopayments-pr',
] ) {
	test( `implemented scenario annotations require the ${ missingTag } tag`, () => {
		assert.throws(
			() =>
				validateSyntheticBindings(
					[ implementedScenarioRow ],
					[
						{
							...validScenarioAnnotationRecord,
							tags: validScenarioAnnotationRecord.tags.filter(
								( tag ) => tag !== missingTag
							),
						},
					]
				),
			new RegExp(
				`Scenario ledger contract annotation is missing required tag ${ missingTag }: synthetic-implemented-scenario-contract`
			)
		);
	} );
}

test( 'implemented scenario annotations must use their exact ledger title', () => {
	assert.throws(
		() =>
			validateSyntheticBindings(
				[ implementedScenarioRow ],
				[
					{
						...validScenarioAnnotationRecord,
						title: 'an unrelated scenario test',
					},
				]
			),
		/Scenario ledger contract annotation has the wrong test title: synthetic-implemented-scenario-contract/
	);
} );

for ( const expectedStatus of [ 'skipped', 'failed' ] ) {
	test( `implemented scenario annotations cannot expect to be ${ expectedStatus }`, () => {
		assert.throws(
			() =>
				validateSyntheticBindings(
					[ implementedScenarioRow ],
					[
						{
							...validScenarioAnnotationRecord,
							expectedStatus,
						},
					]
				),
			/Scenario ledger contract annotation must expect to pass: synthetic-implemented-scenario-contract/
		);
	} );
}

test( 'implemented scenario annotations must be collected by the provider project', () => {
	assert.throws(
		() =>
			validateSyntheticBindings(
				[ implementedScenarioRow ],
				[
					{
						...validScenarioAnnotationRecord,
						projectName: 'woopayments-native-readonly',
					},
				]
			),
		/Scenario ledger contract annotation must be owned by project woopayments-native-provider: synthetic-implemented-scenario-contract/
	);
} );

for ( const migrationState of [ 'implemented', 'verified', 'closed' ] ) {
	test( `${ migrationState } scenario rows require a collected contract annotation`, () => {
		assert.throws(
			() =>
				validateSyntheticBindings(
					[
						{
							...implementedScenarioRow,
							migration_state: migrationState,
						},
					],
					[]
				),
			/Scenario ledger contract has no collected woopayments-contract annotation: synthetic-implemented-scenario-contract/
		);
	} );
}

test( 'specified scenario rows do not require a collected contract annotation', () => {
	assert.doesNotThrow( () =>
		validateSyntheticBindings(
			[
				{
					...implementedScenarioRow,
					migration_state: 'specified',
				},
			],
			[]
		)
	);
} );

test( 'terminal ledger rows require a collected contract annotation', () => {
	assert.throws(
		() => validateSyntheticBindings( [ terminalRow ], [] ),
		/Terminal ledger contract has no collected woopayments-contract annotation: synthetic-terminal-contract/
	);
} );

test( 'terminal annotations must name the exact target file', () => {
	assert.throws(
		() =>
			validateSyntheticBindings(
				[ terminalRow ],
				[
					{
						...validAnnotationRecord,
						file: 'woopayments-native/pilots/unrelated.spec.ts',
					},
				]
			),
		/Terminal ledger contract annotation has the wrong target file: synthetic-terminal-contract/
	);
} );

test( 'a multi-target terminal row binds its annotation to its sole WooPayments-native E2E spec', () => {
	assert.doesNotThrow( () =>
		validateSyntheticBindings(
			[ multiTargetTerminalRow ],
			[ validMultiTargetAnnotationRecord ]
		)
	);
} );

test( 'a multi-target terminal row rejects zero WooPayments-native E2E specs', () => {
	const row = {
		...multiTargetTerminalRow,
		target_path: `${ correctedRefundLowerLayerTarget };plugins/woocommerce/tests/php/includes/class-wc-cart-test.php`,
	};

	assert.throws(
		() =>
			validateSyntheticBindings(
				[ row ],
				[ validMultiTargetAnnotationRecord ]
			),
		/Terminal multi-target ledger contract must contain exactly one WooPayments-native E2E spec target: synthetic-terminal-contract/
	);
} );

test( 'a multi-target terminal row rejects multiple WooPayments-native E2E specs', () => {
	const row = {
		...multiTargetTerminalRow,
		target_path: `${ multiTargetTerminalRow.target_path };plugins/woocommerce/tests/e2e/tests/woopayments-native/pilots/synthetic-transition.spec.ts`,
	};

	assert.throws(
		() =>
			validateSyntheticBindings(
				[ row ],
				[ validMultiTargetAnnotationRecord ]
			),
		/Terminal multi-target ledger contract must contain exactly one WooPayments-native E2E spec target: synthetic-terminal-contract/
	);
} );

test( 'a multi-target terminal row rejects an annotation from an unrelated spec', () => {
	assert.throws(
		() =>
			validateSyntheticBindings(
				[ multiTargetTerminalRow ],
				[
					{
						...validMultiTargetAnnotationRecord,
						file: 'woopayments-native/merchant/unrelated.spec.ts',
					},
				]
			),
		/Terminal ledger contract annotation has the wrong target file: synthetic-terminal-contract/
	);
} );

test( 'terminal annotations must name the exact target contract', () => {
	assert.throws(
		() =>
			validateSyntheticBindings(
				[ terminalRow ],
				[
					{
						...validAnnotationRecord,
						title: 'an unrelated test',
					},
				]
			),
		/Terminal ledger contract annotation has the wrong test title: synthetic-terminal-contract/
	);
} );

test( 'contract annotation records must be unique', () => {
	assert.throws(
		() =>
			validateSyntheticBindings(
				[ terminalRow ],
				[ validAnnotationRecord, validAnnotationRecord ]
			),
		/Duplicate woopayments-contract annotation records for ledger contract: synthetic-terminal-contract/
	);
} );

test( 'statically skipped annotations cannot prove terminal contracts', () => {
	assert.throws(
		() =>
			validateSyntheticBindings(
				[ terminalRow ],
				[
					{
						...validAnnotationRecord,
						expectedStatus: 'skipped',
					},
				]
			),
		/Terminal ledger contract annotation must expect to pass: synthetic-terminal-contract/
	);
} );

test( 'expected-failure annotations cannot prove terminal contracts', () => {
	assert.throws(
		() =>
			validateSyntheticBindings(
				[ terminalRow ],
				[
					{
						...validAnnotationRecord,
						expectedStatus: 'failed',
					},
				]
			),
		/Terminal ledger contract annotation must expect to pass: synthetic-terminal-contract/
	);
} );

test( 'terminal annotations must be collected by their owning project', () => {
	assert.throws(
		() =>
			validateSyntheticBindings(
				[ terminalRow ],
				[
					{
						...validAnnotationRecord,
						projectName: 'woopayments-native-provider',
					},
				]
			),
		/Terminal ledger contract annotation has the wrong owning project: synthetic-terminal-contract/
	);
} );

test( 'unknown contract annotations are rejected', () => {
	assert.throws(
		() =>
			validateSyntheticBindings(
				[ terminalRow ],
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

test( 'one passing exact annotation satisfies a terminal transition row', () => {
	assert.doesNotThrow( () =>
		validateSyntheticBindings( [ terminalRow ], [ validAnnotationRecord ] )
	);
} );

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
