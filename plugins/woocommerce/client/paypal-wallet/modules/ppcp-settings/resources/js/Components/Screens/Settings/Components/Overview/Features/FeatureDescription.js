import { __ } from '@wordpress/i18n';
import { Button, Icon } from '@wordpress/components';
import { reusableBlock } from '@wordpress/icons';

const FeatureDescription = ( { refreshHandler, isRefreshing } ) => {
	const buttonLabel = isRefreshing
		? __( 'Refreshing…', 'woocommerce' )
		: __( 'Refresh', 'woocommerce' );

	return (
		<>
			<p>
				{ __(
					'Enable additional features and capabilities on your WooCommerce store.',
					'woocommerce'
				) }
			</p>
			<p>
				{ __(
					'Click Refresh to update your current features after making changes.',
					'woocommerce'
				) }
			</p>
			<Button
				variant="tertiary"
				onClick={ refreshHandler }
				disabled={ isRefreshing }
			>
				<Icon icon={ reusableBlock } size={ 18 } />
				{ buttonLabel }
			</Button>
		</>
	);
};

export default FeatureDescription;
