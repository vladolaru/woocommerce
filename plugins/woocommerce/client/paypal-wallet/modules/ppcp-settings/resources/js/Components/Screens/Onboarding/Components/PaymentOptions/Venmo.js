import { __ } from '@wordpress/i18n';

import BadgeBox from '@ppcp-settings/Components/ReusableComponents/BadgeBox';

const Venmo = ( { learnMore = '' } ) => {
	return (
		<BadgeBox
			title={ __( 'Venmo', 'woocommerce' ) }
			imageBadge={ [ 'icon-button-venmo.svg' ] }
			description={ __(
				'Automatically offer Venmo checkout to millions of active users.',
				'woocommerce'
			) }
			learnMoreLink={ learnMore }
		/>
	);
};

export default Venmo;
