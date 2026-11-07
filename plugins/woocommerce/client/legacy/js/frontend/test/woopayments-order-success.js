describe( 'WooPayments order success', () => {
	const loadScript = () => {
		document.dispatchEvent( new Event( 'DOMContentLoaded' ) );
	};

	const flushPromises = async () => {
		await Promise.resolve();
		await Promise.resolve();
	};

	beforeAll( () => {
		require( '../utils/woopayments-appearance' );
		require( '../woopayments-order-success' );
	} );

	beforeEach( () => {
		jest.useFakeTimers();
		document.body.innerHTML = `
			<div id="wc-payment-gateway-multibanco-instructions-container" style="color: rgb(10, 20, 30); background-color: transparent">
				<button type="button" class="copy-btn" data-copy-value="12345"><i class="copy-icon"></i></button>
				<button type="button" class="print-btn">Print</button>
				<p class="woocommerce-woopayments-copy-status" aria-live="polite"></p>
			</div>
		`;
		window.wc_woopayments_order_success_params = {
			copied: 'Copied to clipboard.',
			copyFailed: 'Copy failed. Copy the value manually.',
		};
		window.print = jest.fn();
		window.prompt = jest.fn();
	} );

	afterEach( () => {
		jest.useRealTimers();
		delete window.wc_woopayments_order_success_params;
		delete navigator.clipboard;
		document.body.innerHTML = '';
	} );

	it( 'copies values and announces the temporary copied state', async () => {
		const writeText = jest.fn().mockResolvedValue();
		Object.defineProperty( navigator, 'clipboard', {
			configurable: true,
			value: { writeText },
		} );
		loadScript();

		const button = document.querySelector( '.copy-btn' );
		button.click();
		await flushPromises();

		expect( writeText ).toHaveBeenCalledWith( '12345' );
		expect( button.classList.contains( 'copied' ) ).toBe( true );
		expect(
			document.querySelector( '.woocommerce-woopayments-copy-status' )
				.textContent
		).toBe( 'Copied to clipboard.' );

		jest.runOnlyPendingTimers();
		expect( button.classList.contains( 'copied' ) ).toBe( false );
	} );

	it( 'offers the value for manual copying when the Clipboard API is unavailable', async () => {
		loadScript();

		document.querySelector( '.copy-btn' ).click();
		await flushPromises();

		expect( window.prompt ).toHaveBeenCalledWith(
			'Copy failed. Copy the value manually.',
			'12345'
		);
	} );

	it( 'prints the Multibanco instructions', () => {
		loadScript();

		document.querySelector( '.print-btn' ).click();

		expect( window.print ).toHaveBeenCalledTimes( 1 );
	} );

	it( 'uses dark payment-method artwork on dark order summaries', () => {
		document.body.innerHTML = `
			<div class="wc-payment-gateway-method-logo-wrapper" style="background-color: rgb(10, 10, 10)">
				<img src="light.svg" data-dark-src="dark.svg" alt="Multibanco">
			</div>
		`;

		loadScript();

		expect( document.querySelector( 'img' ).getAttribute( 'src' ) ).toBe(
			'dark.svg'
		);
	} );
} );
