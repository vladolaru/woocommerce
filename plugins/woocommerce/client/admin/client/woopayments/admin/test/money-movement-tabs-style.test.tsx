/**
 * External dependencies
 */
import { TabPanel } from '@wordpress/components';
import { render, screen } from '@testing-library/react';

/**
 * Internal dependencies
 */
import { isStyledBy } from './helpers/compiled-rules';

test( 'keeps each transactions tab label on one line', () => {
	render(
		<TabPanel
			className="woocommerce-woopayments-money-movement__tabs"
			tabs={ [
				{ name: 'transactions', title: 'Transactions' },
				{ name: 'uncaptured', title: 'Uncaptured (1)' },
				{ name: 'blocked', title: 'Blocked' },
			] }
		>
			{ () => null }
		</TabPanel>
	);

	expect(
		isStyledBy(
			screen.getByRole( 'tab', { name: 'Uncaptured (1)' } ),
			'style.scss',
			'white-space',
			'nowrap'
		)
	).toBe( true );
} );
