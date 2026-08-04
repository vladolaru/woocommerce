import assert from 'node:assert/strict';
import { mkdtempSync, mkdirSync, rmSync, writeFileSync } from 'node:fs';
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
const cardPaymentContract =
	'default::chromium::tests/e2e/specs/wcpay/shopper/shopper-checkout-purchase.spec.ts:53::Successful purchase › Carding protection false › using a basic card';
const validCardPaymentScenarioRecord = {
	annotations: [
		{
			type: 'woopayments-contract',
			description: cardPaymentContract,
		},
	],
	expectedStatus: 'passed',
	file: 'woopayments-native/scenarios/card-payment.ts',
	projectName: 'woopayments-native-provider',
	tags: [ 'woopayments-native', 'woopayments-provider', 'woopayments-pr' ],
	title: 'Successful purchase › Carding protection false › using a basic card',
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
				'default::chromium::tests/e2e/specs/wcpay/shopper/shopper-checkout-purchase.spec.ts:53::Successful purchase › Carding protection false › using a basic card',
				'default::chromium::tests/e2e/specs/wcpay/shopper/shopper-checkout-save-card-and-purchase.spec.ts:93::Saved cards › When using a basic card added on checkout › should process a payment with the saved card',
				'default::chromium::tests/e2e/specs/wcpay/shopper/shopper-myaccount-saved-cards.spec.ts:214::Shopper can save and delete cards › Testing card: basic › should be able to set the basic card as default payment method',
				'default::chromium::tests/e2e/specs/wcpay/shopper/shopper-wc-blocks-saved-card-checkout-and-usage.spec.ts:71::WooCommerce Blocks › Saved cards › should process a payment with the saved card from Blocks checkout',
			],
		} );
	} finally {
		rmSync( temporaryDirectory, { recursive: true, force: true } );
	}
} );

test( 'card payment scenario collection must not be duplicated', () => {
	assert.throws(
		() =>
			projectRouting.validateCardPaymentScenarioOwnership( [
				validCardPaymentScenarioRecord,
				{ ...validCardPaymentScenarioRecord },
			] ),
		/Card payment scenario must be collected exactly once: found 2/
	);
} );

test( 'card payment scenario must use its canonical relative file path', () => {
	assert.throws(
		() =>
			projectRouting.validateCardPaymentScenarioOwnership( [
				{
					...validCardPaymentScenarioRecord,
					file: 'woopayments-native/pilots/shopper-card-payment.spec.ts',
				},
			] ),
		/Card payment scenario must use its canonical relative file path: woopayments-native\/scenarios\/card-payment\.ts/
	);
} );

test( 'card payment scenario requires its exact native ownership metadata', () => {
	const invalidRecords = [
		{
			label: 'contract annotation',
			record: {
				...validCardPaymentScenarioRecord,
				annotations: [],
			},
			expectedError:
				/Card payment scenario must include exactly one required contract annotation/,
		},
		...[
			'woopayments-native',
			'woopayments-provider',
			'woopayments-pr',
		].map( ( missingTag ) => ( {
			label: missingTag,
			record: {
				...validCardPaymentScenarioRecord,
				tags: validCardPaymentScenarioRecord.tags.filter(
					( tag ) => tag !== missingTag
				),
			},
			expectedError: new RegExp(
				`Card payment scenario must include required tag: ${ missingTag }`
			),
		} ) ),
		{
			label: 'provider project',
			record: {
				...validCardPaymentScenarioRecord,
				projectName: 'woopayments-native-readonly',
			},
			expectedError:
				/Card payment scenario must be owned by project: woopayments-native-provider/,
		},
	];

	for ( const { expectedError, label, record } of invalidRecords ) {
		assert.throws(
			() =>
				projectRouting.validateCardPaymentScenarioOwnership( [
					record,
				] ),
			expectedError,
			label
		);
	}
} );

test( 'card payment scenario must use its exact title', () => {
	assert.throws(
		() =>
			projectRouting.validateCardPaymentScenarioOwnership( [
				{
					...validCardPaymentScenarioRecord,
					title: 'an unrelated card payment test',
				},
			] ),
		/Card payment scenario must use its exact title: Successful purchase › Carding protection false › using a basic card/
	);
} );

for ( const expectedStatus of [ 'skipped', 'failed' ] ) {
	test( `card payment scenario cannot expect to be ${ expectedStatus }`, () => {
		assert.throws(
			() =>
				projectRouting.validateCardPaymentScenarioOwnership( [
					{
						...validCardPaymentScenarioRecord,
						expectedStatus,
					},
				] ),
			/Card payment scenario must expect to pass/
		);
	} );
}

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
