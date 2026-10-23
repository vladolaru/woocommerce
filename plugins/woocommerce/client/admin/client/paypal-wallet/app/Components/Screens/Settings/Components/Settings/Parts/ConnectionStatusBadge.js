import { __ } from '@wordpress/i18n';

import TitleBadge, {
	TITLE_BADGE_NEGATIVE,
	TITLE_BADGE_POSITIVE,
} from '../../../../../ReusableComponents/TitleBadge';

const ConnectionStatusBadge = ( { isActive, isSandbox, isBusinessSeller } ) => {
	if ( isActive ) {
		let label;

		if ( isBusinessSeller ) {
			label = isSandbox
				? __( 'Business | Sandbox', 'woocommerce' )
				: __( 'Business | Live', 'woocommerce' );
		} else {
			label = isSandbox
				? __( 'Sandbox', 'woocommerce' )
				: __( 'Active', 'woocommerce' );
		}

		return <TitleBadge type={ TITLE_BADGE_POSITIVE } text={ label } />;
	}

	return (
		<TitleBadge
			type={ TITLE_BADGE_NEGATIVE }
			text={ __( 'Not Connected', 'woocommerce' ) }
		/>
	);
};

export default ConnectionStatusBadge;
