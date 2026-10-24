/**
 * External dependencies
 */
import { getSettings, setSettings } from '@wordpress/date';

/**
 * Sets WordPress's default site date and time formats ("F j, Y" and "g:i a"), which the
 * admin pages read from `@wordpress/date` as the client reads `wcpaySettings.dateFormat`.
 */
export const setSiteDateFormats = () => {
	const settings = getSettings();

	setSettings( {
		...settings,
		formats: { ...settings.formats, date: 'F j, Y', time: 'g:i a' },
	} );
};
