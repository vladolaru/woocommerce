/**
 * External dependencies
 */
import { useEffect, useState } from '@wordpress/element';
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
import { createWooPaymentsAccountSession } from '../data';

export interface StripeNotificationsChange {
	total: number;
	actionRequired: number;
}

/**
 * Stripe's embedded notification banner, as client 11.1.0 `embedded-components/index.tsx:192-224`.
 *
 * A failed session renders no copy: the client's onboarding-failure notice sits in the Overview's hidden wrapper.
 */
export const StripeNotificationsBanner = ( {
	onInitError,
	onLoadError,
	onNotificationsChange,
}: {
	onInitError: () => void;
	onLoadError: ( loadError: LoadError ) => void;
	onNotificationsChange: ( change: StripeNotificationsChange ) => void;
} ) => {
	const [ connectInstance, setConnectInstance ] =
		useState< StripeConnectInstance | null >( null );
	const [ hasFailed, setFailed ] = useState( false );
	const [ loading, setLoading ] = useState( true );

	useEffect( () => {
		const initialize = async () => {
			try {
				const session = await createWooPaymentsAccountSession();
				if ( ! session.publishableKey ) {
					throw new Error( 'Missing publishable key.' );
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
			} catch {
				setFailed( true );
				onInitError();
			} finally {
				setLoading( false );
			}
		};

		void initialize();
		// One session per visit, as the client; the page passes new callbacks every render.
		// eslint-disable-next-line react-hooks/exhaustive-deps
	}, [] );

	return (
		<>
			{ ! hasFailed && ( loading || ! connectInstance ) && (
				<StripeSpinner />
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
