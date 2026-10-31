/**
 * External dependencies
 */
import { speak } from '@wordpress/a11y';
import { useEffect } from '@wordpress/element';

/**
 * Announce a loading message to screen readers when loading starts.
 *
 * A live region mounted together with its text is usually not read, so the message goes through the always-present
 * WordPress live region instead.
 *
 * @param isLoading Whether the section is loading.
 * @param message   Message to announce.
 */
export const useLoadingAnnouncement = (
	isLoading: boolean,
	message: string
) => {
	useEffect( () => {
		if ( isLoading ) {
			speak( message, 'polite' );
		}
	}, [ isLoading, message ] );
};
