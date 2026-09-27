/**
 * External dependencies
 */
import { useEffect, useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import {
	loadConnectAndInitialize,
	type LoadError,
	type StripeConnectInstance,
} from '@stripe/connect-js';
import {
	ConnectComponentsProvider,
	ConnectNotificationBanner,
} from '@stripe/react-connect-js';

/**
 * Internal dependencies
 */
import appearance from '~/settings-payments/onboarding/providers/woopayments/steps/business-verification/components/embedded/appearance';
import StripeSpinner from '~/settings-payments/onboarding/providers/woopayments/components/stripe-spinner';
import BannerNotice from '~/settings-payments/onboarding/providers/woopayments/components/banner-notice';
import { createWooPaymentsAccountSession } from '../data';

export interface StripeNotificationsChange {
	total: number;
	actionRequired: number;
}

const genericError = __(
	'Unable to start onboarding. If this problem persists, please contact support.',
	'woocommerce'
);

/**
 * Stripe's embedded notification banner, as client 11.1.0 `embedded-components/index.tsx:192-224`.
 */
export const StripeNotificationsBanner = ( {
	onLoadError,
	onNotificationsChange,
}: {
	onLoadError: ( loadError: LoadError ) => void;
	onNotificationsChange: ( change: StripeNotificationsChange ) => void;
} ) => {
	const [ connectInstance, setConnectInstance ] =
		useState< StripeConnectInstance | null >( null );
	const [ errorMessage, setErrorMessage ] = useState< string | null >( null );
	const [ loading, setLoading ] = useState( true );

	useEffect( () => {
		const initialize = async () => {
			try {
				const session = await createWooPaymentsAccountSession();
				if ( ! session.publishableKey ) {
					throw new Error( genericError );
				}
				const clientSecret = session.clientSecret ?? '';

				setConnectInstance(
					loadConnectAndInitialize( {
						publishableKey: session.publishableKey,
						fetchClientSecret: async () => clientSecret,
						appearance: { overlays: 'drawer', ...appearance },
						locale: ( session.locale ?? '' ).replace( '_', '-' ),
					} )
				);
			} catch ( error ) {
				setErrorMessage(
					error instanceof Error ? error.message : genericError
				);
			} finally {
				setLoading( false );
			}
		};

		void initialize();
	}, [] );

	return (
		<>
			{ ( loading || ! connectInstance ) && <StripeSpinner /> }
			{ errorMessage && (
				<BannerNotice status="error">{ errorMessage }</BannerNotice>
			) }
			{ connectInstance && (
				<ConnectComponentsProvider connectInstance={ connectInstance }>
					<ConnectNotificationBanner
						onLoadError={ onLoadError }
						onNotificationsChange={ onNotificationsChange }
						collectionOptions={ {
							fields: 'eventually_due',
							futureRequirements: 'omit',
						} }
					/>
				</ConnectComponentsProvider>
			) }
		</>
	);
};

export default StripeNotificationsBanner;
