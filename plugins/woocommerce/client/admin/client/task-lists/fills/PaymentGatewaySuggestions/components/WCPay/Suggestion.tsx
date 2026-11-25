/**
 * External dependencies
 */
import { __ } from '@wordpress/i18n';
import {
	WCPayBanner,
	WCPayBannerFooter,
	WCPayBannerBody,
	WCPayBenefits,
	WCPayBannerImageCut,
} from '@woocommerce/onboarding';
import { useDispatch, useSelect } from '@wordpress/data';
import { paymentSettingsStore } from '@woocommerce/data';

/**
 * Internal dependencies
 */
import { Action } from '../Action';
import { connectWcpay, openWooPaymentsOnboarding } from './utils';
import './suggestion.scss';

interface PaymentGateway {
	id: string;
	needsSetup: boolean;
	installed: boolean;
	enabled: boolean;
	plugins?: string[];
}

interface SuggestionProps {
	paymentGateway: PaymentGateway;
	onSetupCallback?: ( ( resolve?: () => void ) => void ) | null;
}

export const Suggestion = ( {
	paymentGateway,
	onSetupCallback = null,
}: SuggestionProps ) => {
	const {
		id,
		needsSetup,
		installed,
		enabled: isEnabled,
		installed: isInstalled,
	} = paymentGateway;
	// The suggestion names the extension to install only while the WooPayments feature is off.
	const hasPlugins = !! paymentGateway.plugins?.length;

	const isWooPayEligible = useSelect( ( select ) => {
		const store = select( paymentSettingsStore );
		return store.getIsWooPayEligible();
	}, [] );

	const { createNotice } = useDispatch( 'core/notices' );
	// When WCPay is installed and onSetupCallback is null
	// Overwrite onSetupCallback to redirect to the setup page
	// when the user clicks on the "Finish setup" button.
	// WCPay doesn't need to be configured in WCA.
	// It should be configured in its onboarding flow.
	if ( hasPlugins && installed && onSetupCallback === null ) {
		onSetupCallback = () => {
			connectWcpay( createNotice );
		};
	}

	if ( ! hasPlugins && onSetupCallback === null ) {
		onSetupCallback = ( resolve?: () => void ) => {
			openWooPaymentsOnboarding();
			resolve?.();
		};
	}

	const setupButtonText: string = isInstalled
		? __( 'Finish setup', 'woocommerce' )
		: __( 'Get started', 'woocommerce' );

	return (
		<div className="woocommerce-wcpay-suggestion">
			<WCPayBanner>
				<WCPayBannerBody
					textPosition="left"
					actionButton={
						<Action
							id={ id }
							hasSetup={ true }
							needsSetup={ needsSetup }
							isEnabled={ isEnabled && ! needsSetup }
							isRecommended={ true }
							isInstalled={ isInstalled }
							hasPlugins={ hasPlugins }
							setupButtonText={ setupButtonText }
							onSetupCallback={ onSetupCallback }
						/>
					}
					bannerImage={ <WCPayBannerImageCut /> }
					isWooPayEligible={ isWooPayEligible }
				/>
				<WCPayBenefits isWooPayEligible={ isWooPayEligible } />
				<WCPayBannerFooter isWooPayEligible={ isWooPayEligible } />
			</WCPayBanner>
		</div>
	);
};
