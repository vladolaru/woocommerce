describe( 'Settings Payments provider route bootstrap', () => {
	const originalSettings = window.wcSettings;

	beforeEach( () => {
		jest.resetModules();
		window.wcSettings = {
			...originalSettings,
			admin: {
				...originalSettings?.admin,
				paypalWalletOwned: true,
			},
		};
	} );

	afterEach( () => {
		window.wcSettings = originalSettings;
	} );

	it( 'registers the PayPal Wallet route from the bootstrap module while core owns the wallet', async () => {
		await jest.isolateModulesAsync( async () => {
			const { getSettingsPaymentsProviderRoutes } = await import(
				'../provider-routes'
			);

			await import( '../register-provider-routes' );

			const routes = getSettingsPaymentsProviderRoutes();

			expect(
				routes.map( ( { id, path: routePath } ) => ( {
					id,
					path: routePath,
				} ) )
			).toEqual( [
				{ id: 'paypal-wallet-settings', path: '/paypal-wallet' },
			] );
		} );
	} );
} );
