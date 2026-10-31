/**
 * External dependencies
 */
import { Timeline } from '@woocommerce/components';
import type { ReactElement, ReactNode } from 'react';

/**
 * One timeline line in the shape core's `Timeline` component takes.
 */
export type WooPaymentsTimelineItem = {
	date: Date;
	icon: ReactElement;
	headline: ReactNode;
	body: ReactNode[];
	hideTimestamp?: boolean;
};

/**
 * Renders the payment timeline items. Client 11.1.0 `payment-details/timeline/index.js` uses core's
 * `Timeline` with the site timezone; keep the renderer here so a later change touches one file.
 *
 * @param props       Component props.
 * @param props.items Timeline items, newest first after the component sorts them.
 */
export const WooPaymentsTimelineList = ( {
	items,
}: {
	items: WooPaymentsTimelineItem[];
} ) => (
	// The component's generated types read its `items = []` default as `never[]`.
	<Timeline items={ items as never[] } timezone="site" />
);
