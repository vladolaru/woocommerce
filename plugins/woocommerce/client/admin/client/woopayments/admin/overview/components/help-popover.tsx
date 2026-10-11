/**
 * External dependencies
 */
import { Button, Dropdown } from '@wordpress/components';
import { help } from '@wordpress/icons';
import clsx from 'clsx';
import type { ReactNode } from 'react';

/**
 * Internal dependencies
 */
import './help-popover.scss';

/**
 * A help icon that opens its explanation on click, as client 11.1.0 `components/tooltip` `ClickTooltip` does.
 */
export const HelpPopover = ( {
	label,
	children,
	size = 16,
}: {
	label: string;
	children: ReactNode;
	/** The client's `buttonSize`: 16 by default, 24 beside the payout status chip. */
	size?: 16 | 24;
} ) => (
	<Dropdown
		className="woocommerce-woopayments-overview__help-popover"
		renderToggle={ ( { isOpen, onToggle } ) => (
			<Button
				className={ clsx(
					'woocommerce-woopayments-overview__help-popover-toggle',
					{ 'is-large': size === 24 }
				) }
				icon={ help }
				iconSize={ size }
				label={ label }
				aria-expanded={ isOpen }
				onClick={ onToggle }
			/>
		) }
		renderContent={ () => (
			<div className="woocommerce-woopayments-overview__help-popover-content">
				{ children }
			</div>
		) }
	/>
);
