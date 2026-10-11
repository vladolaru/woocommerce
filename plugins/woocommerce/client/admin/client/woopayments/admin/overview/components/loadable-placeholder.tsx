/**
 * External dependencies
 */
import type { ReactNode } from 'react';

/**
 * A grey bar the width of its hidden text, as client 11.1.0 `components/loadable` draws while a read is pending.
 *
 * @param props          Component props.
 * @param props.children Text that sets the bar's width; it is never shown or announced.
 * @param props.isBlock  Whether the bar is a full-width block, like the client's `LoadableBlock`.
 */
export const LoadablePlaceholder = ( {
	children,
	isBlock = false,
}: {
	children: ReactNode;
	isBlock?: boolean;
} ) => (
	<span
		className={ `woocommerce-woopayments-overview__placeholder${
			isBlock ? ' is-block' : ''
		}` }
		aria-hidden="true"
	>
		{ children }
	</span>
);
