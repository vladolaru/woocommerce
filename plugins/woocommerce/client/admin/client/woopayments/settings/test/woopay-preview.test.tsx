/**
 * External dependencies
 */
import { render, screen } from '@testing-library/react';

/**
 * Internal dependencies
 */
import { WooPayPreview } from '../express-checkout/woopay-preview';

describe( 'WooPayPreview store logo', () => {
	afterEach( () => {
		delete ( window as typeof window & { wcSettings?: unknown } )
			.wcSettings;
	} );

	// Client 11.1.0 `woopay-preview.js:428` builds the logo URL from the preloaded REST root
	// (`class-wc-payments-admin.php:1048`), so a subdirectory store on Plain permalinks gets the right URL.
	it( 'builds the uploaded logo URL from the preloaded REST root', () => {
		window.wcSettings = {
			admin: {
				woopaymentsSettings: {
					restUrl: 'https://example.test/shop/?rest_route=/',
				},
			},
		} as typeof window.wcSettings;

		render(
			<WooPayPreview
				customMessage=""
				siteLogoUrl=""
				storeLogo="file_logo"
				storeName="Example store"
			/>
		);

		expect(
			screen.getByRole( 'img', { name: 'Store logo' } )
		).toHaveAttribute(
			'src',
			'https://example.test/shop/?rest_route=/wc/v3/payments/file/file_logo'
		);
	} );

	// Without a preloaded REST root the uploaded logo has no reliable URL, so the preview falls back to what it shows
	// without an uploaded logo instead of building one from the domain root.
	it( 'shows the site logo when no REST root is preloaded', () => {
		render(
			<WooPayPreview
				customMessage=""
				siteLogoUrl="https://example.test/shop/wp-content/uploads/site-logo.png"
				storeLogo="file_logo"
				storeName="Example store"
			/>
		);

		expect(
			screen.getByRole( 'img', { name: 'Store logo' } )
		).toHaveAttribute(
			'src',
			'https://example.test/shop/wp-content/uploads/site-logo.png'
		);
	} );

	it( 'shows the store name when no REST root and no site logo exist', () => {
		render(
			<WooPayPreview
				customMessage=""
				siteLogoUrl=""
				storeLogo="file_logo"
				storeName="Example store"
			/>
		);

		expect(
			screen.queryByRole( 'img', { name: 'Store logo' } )
		).not.toBeInTheDocument();
		expect( screen.getByText( 'Example store' ) ).toBeInTheDocument();
	} );
} );
