/**
 * External dependencies
 */
import { fireEvent } from '@testing-library/react';
import { getHistory } from '@woocommerce/navigation';

/**
 * Click a link with the primary button and expect the settings shell to route it in the app, as
 * `handleSettingsPaymentsProviderRouteClick()` does: the browser's own navigation is prevented and the admin.php
 * route is pushed through the shell's history.
 *
 * @param link      The link.
 * @param adminPath The admin.php path the click pushes.
 */
export const expectPlainClickRoutesInApp = (
	link: Parameters< typeof fireEvent.click >[ 0 ],
	adminPath: string
) => {
	const push = jest
		.spyOn( getHistory(), 'push' )
		.mockImplementation( () => undefined );

	try {
		// `fireEvent` returns false when the default navigation was prevented.
		expect( fireEvent.click( link ) ).toBe( false );
		expect( push ).toHaveBeenCalledWith( adminPath );
	} finally {
		push.mockRestore();
	}
};
