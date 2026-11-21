/**
 * Sets the current user's email (preloaded WooPayments settings) and locale (`wcSettings.locale`)
 * that list exports send, and returns a function that restores the previous settings.
 *
 * @param email  The current user's email.
 * @param locale The user locale.
 */
export const setExportRecipient = ( email: string, locale: string ) => {
	const original = window.wcSettings;

	window.wcSettings = {
		...original,
		locale: { ...original?.locale, userLocale: locale },
		admin: {
			...original?.admin,
			woopaymentsSettings: { currentUserEmail: email },
		},
	} as typeof window.wcSettings;

	return () => {
		window.wcSettings = original;
	};
};
