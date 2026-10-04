import { sdkV6Config } from './config';

afterEach( () => {
	delete window.wc_ppcp_sdk_v6;
	delete window.wc;
} );

describe( 'sdkV6Config', () => {
	test( 'returns the classic-page global when present', () => {
		const config = { other: true };
		window.wc_ppcp_sdk_v6 = config;

		expect( sdkV6Config() ).toBe( config );
	} );

	test( 'falls back to the block-page payment method data when the classic global is absent', () => {
		const config = { other: false };
		window.wc = {
			wcSettings: {
				getSetting: ( key ) =>
					key === 'paymentMethodData'
						? { 'ppcp-sdk-v6': config }
						: undefined,
			},
		};

		expect( sdkV6Config() ).toBe( config );
	} );

	test( 'returns null when neither the classic global nor the block payment method data exists', () => {
		expect( sdkV6Config() ).toBeNull();
	} );

	test( 'returns null when the block payment method data exists but has no v6 entry', () => {
		window.wc = {
			wcSettings: {
				getSetting: () => ( { 'some-other-gateway': {} } ),
			},
		};

		expect( sdkV6Config() ).toBeNull();
	} );
} );
