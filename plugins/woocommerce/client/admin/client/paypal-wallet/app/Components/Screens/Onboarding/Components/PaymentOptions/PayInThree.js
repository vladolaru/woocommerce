import { __ } from '@wordpress/i18n';

import BadgeBox from '../../../../ReusableComponents/BadgeBox';

const PayInThree = ( { learnMore = '' } ) => {
	return (
		<BadgeBox
			title={ __( 'Pay in 3', 'woocommerce' ) }
			imageBadge={ [ 'icon-payment-method-paypal-small.svg' ] }
			description={ __(
				'Offer installment payment options and get paid upfront - at no extra cost to you.',
				'woocommerce'
			) }
			learnMoreLink={ learnMore }
		/>
	);
};

export default PayInThree;
