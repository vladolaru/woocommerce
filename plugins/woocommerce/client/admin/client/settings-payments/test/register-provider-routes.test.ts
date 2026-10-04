describe( 'Settings Payments provider route bootstrap', () => {
	beforeEach( () => {
		jest.resetModules();
	} );

	it( 'registers the PayPal Wallet route from the bootstrap module', async () => {
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
