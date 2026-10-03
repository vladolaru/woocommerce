import { __ } from '@wordpress/i18n';

import BadgeBox from '@ppcp-settings/Components/ReusableComponents/BadgeBox';

const Crypto = ( { learnMore = '' } ) => {
	return (
		<BadgeBox
			title={ __( 'Pay with Crypto', 'woocommerce' ) }
			imageBadge={ [ 'icon-payment-method-crypto.svg' ] }
			description={ __(
				'Let customers check out with cryptocurrencies while you get paid in cash.',
				'woocommerce'
			) }
			learnMoreLink={ learnMore }
		/>
	);
};

export default Crypto;
