/**
 * External dependencies
 */
import { render } from '@testing-library/react';
import { createElement } from '@wordpress/element';

/**
 * Internal dependencies
 */
import StoreNotices from '../../../../../../../../packages/public-api/blocks-components/store-notices-container/store-notices';

/**
 * Render notices as the Blocks notices container does, with the container's own component: it groups the dismissible
 * errors under one summary heading with a close button, and shows a notice that cannot be dismissed alone.
 *
 * @param {Array<Object>} notices The notices as the notices store keeps them, each with its `context`.
 * @return {Object} The render result, and `update( notices )` to show the container again with other notices.
 */
export const renderNoticeList = ( notices ) => {
	/** @param {Array<Object>} shown The notices to show. */
	const list = ( shown ) =>
		createElement( StoreNotices, {
			className: '',
			notices: shown.map( ( /** @type {Object} */ notice ) => ( {
				type: 'default',
				...notice,
			} ) ),
		} );
	const result = render( list( notices ) );

	// Shows the container again with the notices the store holds now.
	return {
		...result,
		update: ( /** @type {Array<Object>} */ shown ) =>
			result.rerender( list( shown ) ),
	};
};

export const SUMMARY = 'Please fix the following error before continuing';
export const DISMISS_LABEL = 'Dismiss this notice';
