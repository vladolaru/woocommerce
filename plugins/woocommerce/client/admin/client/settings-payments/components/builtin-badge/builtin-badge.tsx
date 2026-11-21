/**
 * External dependencies
 */
import { __ } from '@wordpress/i18n';

/**
 * Internal dependencies
 */
import { OfficialBadge } from '~/settings-payments/components/official-badge';

const LEARN_MORE_URL = 'https://woocommerce.com/payments/';

/**
 * The badge of the WooPayments built into WooCommerce: the official badge with its own copy, so it behaves and
 * records the same Tracks events as the badge on every other suggestion.
 *
 * @param {Object} props              Component props.
 * @param {string} props.suggestionId The id of the suggestion matching the built-in WooPayments.
 */
export const BuiltinBadge = ( { suggestionId }: { suggestionId: string } ) => (
	<OfficialBadge
		variant="expanded"
		suggestionId={ suggestionId }
		label={ __( 'Built in', 'woocommerce' ) }
		imageAlt={ __( 'Built-in WooPayments badge', 'woocommerce' ) }
		popoverText={ __(
			'WooPayments is built into WooCommerce. <learnMoreLink />',
			'woocommerce'
		) }
		learnMoreUrl={ LEARN_MORE_URL }
		testId="woocommerce-builtin-badge"
	/>
);
