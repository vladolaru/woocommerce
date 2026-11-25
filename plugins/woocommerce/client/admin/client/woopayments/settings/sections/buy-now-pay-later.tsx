/**
 * External dependencies
 */
import { __ } from '@wordpress/i18n';

/**
 * Internal dependencies
 */
import { SettingsSection } from '../settings-shell';
import { useGetAvailablePaymentMethodIds } from '../data/hooks';
import { asStringArray } from '../settings-values';
import { SettingsPaymentMethodsList } from './payment-methods';
import { BuyNowPayLaterSectionDescription } from './section-descriptions';
import { BNPL_METHOD_IDS } from './utils';

const BuyNowPayLaterSettingsSection = () => {
	const availableBuyNowPayLaterMethodIds = asStringArray(
		useGetAvailablePaymentMethodIds()
	).filter( ( methodId ) => BNPL_METHOD_IDS.includes( methodId ) );

	return availableBuyNowPayLaterMethodIds.length === 0 ? null : (
		<SettingsSection
			id="buy-now-pay-later-methods"
			title={ __( 'Buy now, pay later', 'woocommerce' ) }
			description={ <BuyNowPayLaterSectionDescription /> }
		>
			<SettingsPaymentMethodsList
				methodIds={ availableBuyNowPayLaterMethodIds }
			/>
		</SettingsSection>
	);
};

export { BuyNowPayLaterSettingsSection };
