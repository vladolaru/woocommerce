/**
 * External dependencies
 */
import { Button, Dropdown } from '@wordpress/components';
import { help } from '@wordpress/icons';
import type { ReactNode } from 'react';

/**
 * A help icon that opens its explanation on click, as client 11.1.0 `components/tooltip` `ClickTooltip` does.
 */
export const HelpPopover = ( {
	label,
	children,
}: {
	label: string;
	children: ReactNode;
} ) => (
	<Dropdown
		className="woocommerce-woopayments-overview__help-popover"
		renderToggle={ ( { isOpen, onToggle } ) => (
			<Button
				icon={ help }
				size="small"
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
