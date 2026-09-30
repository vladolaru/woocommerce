/**
 * External dependencies
 */
import { act } from '@testing-library/react';

type SettingsWindow = typeof window & {
	wcSettings?: {
		admin?: {
			woopaymentsSettings?: Record< string, unknown >;
		};
	};
};

/**
 * Preload the WooPayments test and dev mode flags, as `preload_shared_settings()` does server-side.
 *
 * Mutates the existing `wcSettings` object so tests that define it as a read-only property still see the flags.
 *
 * @param testMode Whether WooPayments runs in test mode.
 * @param devMode  Whether WooPayments runs in dev mode.
 */
export const mockAccountMode = ( testMode: boolean, devMode = false ) => {
	const settingsWindow = window as SettingsWindow;
	if ( ! settingsWindow.wcSettings ) {
		settingsWindow.wcSettings = {};
	}

	const settings = settingsWindow.wcSettings;
	settings.admin = {
		...settings.admin,
		woopaymentsSettings: {
			...settings.admin?.woopaymentsSettings,
			testMode,
			devMode,
		},
	};
};

const TEST_MODE_NOTICE_SELECTOR = '.woocommerce-woopayments-test-mode-notice';

/**
 * Let the page settle, then read its test-mode notice copy.
 *
 * When the notice renders, it must sit above the page heading, as the client renders it first on each page.
 *
 * @return The notice copy, or null when no notice rendered.
 */
export const getTestModeNoticeText = async (): Promise< string | null > => {
	await act( () => Promise.resolve() );

	const notice = document.querySelector( TEST_MODE_NOTICE_SELECTOR );
	if ( ! notice ) {
		return null;
	}

	// The page heading, or the transactions tabs that come first on those pages.
	const heading = document.querySelector( 'h1, h2, [role="tablist"], nav' );
	expect( heading ).not.toBeNull();
	expect(
		// eslint-disable-next-line no-bitwise -- compareDocumentPosition returns a bitmask.
		notice.compareDocumentPosition( heading as Element ) &
			window.Node.DOCUMENT_POSITION_FOLLOWING
	).toBeTruthy();

	return (
		notice.querySelector( '.components-notice__content' )?.textContent ?? ''
	)
		.replace( /\s+/g, ' ' )
		.trim();
};
