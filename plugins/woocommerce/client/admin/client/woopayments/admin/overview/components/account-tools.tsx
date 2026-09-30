/**
 * External dependencies
 */
import { Button } from '@wordpress/components';
import { useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import { addQueryArgs } from '@wordpress/url';

/**
 * Internal dependencies
 */
import { WooPaymentsResetAccountModal } from '~/settings-payments/components/modals';

const RESET_PATH = '/wc-admin/settings/payments/woopayments/onboarding/reset';

// Client 11.1.0 `components/account-details/account-tools/index.tsx:17-22`: the reset starts over from onboarding.
const RESET_SOURCE = {
	from: 'WCPAY_RESET_ACCOUNT',
	source: 'wcpay-reset-account',
};

// Client 11.1.0 `components/account-details/account-tools/index.tsx:24-54`, shown only during test-mode onboarding.
export const AccountTools = ( {
	onboardingUrl,
}: {
	onboardingUrl: string;
} ) => {
	const [ isModalOpen, setModalOpen ] = useState( false );

	return (
		<div className="woocommerce-woopayments-account-details__tools">
			<h3>{ __( 'Account tools', 'woocommerce' ) }</h3>
			<p>
				{ __(
					'You are using a test account. If you are experiencing problems completing account setup, or wish to test with a different email/country associated with your account, you can reset your account and start from the beginning.',
					'woocommerce'
				) }
			</p>
			<Button
				variant="secondary"
				onClick={ () => setModalOpen( true ) }
				__next40pxDefaultSize
			>
				{ __( 'Reset account', 'woocommerce' ) }
			</Button>
			<WooPaymentsResetAccountModal
				isOpen={ isModalOpen }
				onClose={ () => setModalOpen( false ) }
				hasAccount
				isTestMode
				resetPath={ addQueryArgs( RESET_PATH, RESET_SOURCE ) }
				onResetSuccess={ () =>
					window.location.assign(
						addQueryArgs( onboardingUrl, RESET_SOURCE )
					)
				}
			/>
		</div>
	);
};
