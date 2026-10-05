import { STEP_INFO } from '@ppcp-settings/services/tracking/funnels/onboarding';
import { getSteps } from './index';

// The step screens are not under test here, and loading them pulls in the data stores.
jest.mock( './StepWelcome', () => () => null );
jest.mock( './StepBusiness', () => () => null );
jest.mock( './StepProducts', () => () => null );
jest.mock( './StepCompleteSetup', () => () => null );

describe( 'Onboarding steps', () => {
	it( 'lists welcome, business, products and complete when casual selling is available', () => {
		const steps = getSteps( { canUseCasualSelling: true } );

		expect( steps.map( ( { id } ) => id ) ).toEqual( [
			'welcome',
			'business',
			'products',
			'complete',
		] );
	} );

	it( 'skips the business step when casual selling is not available', () => {
		const steps = getSteps( { canUseCasualSelling: false } );

		expect( steps.map( ( { id } ) => id ) ).toEqual( [
			'welcome',
			'products',
			'complete',
		] );
	} );

	it( 'tracks exactly one view event per step, keyed 0 to 3 in step order', () => {
		expect( Object.keys( STEP_INFO ) ).toEqual( [ '0', '1', '2', '3' ] );
		expect(
			Object.values( STEP_INFO ).map( ( { name } ) => name )
		).toEqual( [ 'welcome', 'account_type', 'products', 'complete' ] );
	} );
} );
