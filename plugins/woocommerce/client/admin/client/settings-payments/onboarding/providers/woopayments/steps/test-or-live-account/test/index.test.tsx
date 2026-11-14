/**
 * External dependencies
 */
import { render, screen } from '@testing-library/react';

/**
 * Internal dependencies
 */
import TestOrLiveAccountStep from '..';

jest.mock( '../../../components/header', () => ( {
	__esModule: true,
	default: () => null,
} ) );

jest.mock( '../../../data/onboarding-context', () => ( {
	useOnboardingContext: () => ( {
		closeModal: jest.fn(),
		currentStep: {},
		sessionEntryPoint: 'settings',
		navigateToNextStep: jest.fn(),
		refreshStoreData: jest.fn(),
		getStepByKey: jest.fn(),
	} ),
} ) );

describe( 'TestOrLiveAccountStep', () => {
	it( 'renders signup help at the WooPayments signup process documentation', () => {
		render( <TestOrLiveAccountStep /> );

		expect(
			screen.getByRole( 'link', {
				name: 'Learn more about the WooPayments sign-up process (opens in a new tab)',
			} )
		).toHaveAttribute(
			'href',
			'https://woocommerce.com/document/woopayments/startup-guide/#signup-process'
		);
	} );
} );
