const {
	MultiCurrencyAsyncPriceRenderer,
} = require( '../multi-currency-async-renderer' );

describe( 'MultiCurrencyAsyncPriceRenderer', () => {
	let renderer;

	const mockConfig = {
		default_currency: 'USD',
		selected_currency: 'EUR',
		charm_only_products: true,
		currencies: {
			USD: {
				code: 'USD',
				symbol: '$',
				rate: 1,
				decimals: 2,
				decimal_sep: '.',
				thousand_sep: ',',
				symbol_pos: 'left',
				rounding: 0,
				charm: 0,
			},
			EUR: {
				code: 'EUR',
				symbol: '€',
				rate: 0.85,
				decimals: 2,
				decimal_sep: ',',
				thousand_sep: '.',
				symbol_pos: 'right_space',
				rounding: 1,
				charm: -0.01,
			},
		},
	};

	beforeEach( () => {
		document.body.textContent = '';
		window.wcpayAsyncPriceConfig = {
			apiUrl: 'https://example.test/wp-json/wc/v3/payments/multi-currency/public/config',
			defaultCurrency: mockConfig.currencies.USD,
			srText: {
				sale_original: 'Original price was: %s.',
				sale_current: 'Current price is: %s.',
				range: 'Price range: %1$s through %2$s',
			},
		};

		renderer = new MultiCurrencyAsyncPriceRenderer();
		renderer.config = mockConfig;
	} );

	afterEach( () => {
		renderer.destroy();
		delete window.wcpayAsyncPriceConfig;
	} );

	test( 'converts skeleton prices into WooCommerce price markup', () => {
		const wrapper = document.createElement( 'span' );
		wrapper.className = 'woocommerce-Price-amount amount wcpay-async-price';
		wrapper.setAttribute( 'data-wcpay-price', '10.00' );
		wrapper.setAttribute( 'data-wcpay-price-type', 'product' );
		wrapper.innerHTML =
			'<bdi class="wcpay-price-skeleton"></bdi><span class="screen-reader-text wcpay-price-placeholder">$10.00</span>';
		document.body.appendChild( wrapper );

		renderer.convertAllPrices();

		const bdi = wrapper.querySelector( 'bdi' );

		expect( wrapper.classList.contains( 'wcpay-price-converted' ) ).toBe(
			true
		);
		expect( wrapper.querySelector( '.wcpay-price-skeleton' ) ).toBeNull();
		expect(
			wrapper.querySelector( '.wcpay-price-placeholder' )
		).toBeNull();
		expect( bdi.textContent ).toBe( '8,99 €' );
		expect(
			bdi.querySelector( '.woocommerce-Price-currencySymbol' ).textContent
		).toBe( '€' );
	} );

	test( 'updates annotated sale and range screen reader text', () => {
		document.body.innerHTML = `
			<span class="screen-reader-text" data-wcpay-sr-type="sale_original" data-wcpay-sr-price="50">Original price was: $50.00.</span>
			<span class="screen-reader-text" data-wcpay-sr-type="sale_current" data-wcpay-sr-price="35">Current price is: $35.00.</span>
				<span class="screen-reader-text" data-wcpay-sr-type="range"
					data-wcpay-sr-price-from="10" data-wcpay-sr-price-to="30">Price range: $10.00 through $30.00</span>
		`;

		renderer.convertScreenReaderText();

		const saleOriginal = document.querySelector(
			'[data-wcpay-sr-type="sale_original"]'
		);
		const saleCurrent = document.querySelector(
			'[data-wcpay-sr-type="sale_current"]'
		);
		const range = document.querySelector( '[data-wcpay-sr-type="range"]' );

		expect( saleOriginal.textContent ).toBe(
			'Original price was: 42,99 €.'
		);
		expect( saleCurrent.textContent ).toBe( 'Current price is: 29,99 €.' );
		expect( range.textContent ).toBe(
			'Price range: 8,99 € through 25,99 €'
		);
		expect( saleOriginal.classList.contains( 'wcpay-sr-converted' ) ).toBe(
			true
		);
	} );

	test( 'formats default-currency prices on fetch failure when fallback config exists', () => {
		const wrapper = document.createElement( 'span' );
		wrapper.setAttribute( 'data-wcpay-price', '10.00' );
		wrapper.innerHTML =
			'<bdi class="wcpay-price-skeleton"></bdi><span class="screen-reader-text wcpay-price-placeholder">$10.00</span>';
		document.body.appendChild( wrapper );

		renderer.showErrorState();

		expect( wrapper.classList.contains( 'wcpay-price-converted' ) ).toBe(
			true
		);
		expect(
			wrapper.querySelector( '.wcpay-price-placeholder' )
		).toBeNull();
		expect( wrapper.querySelector( 'bdi' ).textContent ).toBe( '$10.00' );
	} );

	test( 'keeps screen reader fallback text when no default-currency config exists', () => {
		delete window.wcpayAsyncPriceConfig.defaultCurrency;

		const wrapper = document.createElement( 'span' );
		wrapper.setAttribute( 'data-wcpay-price', '10.00' );
		wrapper.innerHTML =
			'<bdi class="wcpay-price-skeleton"></bdi><span class="screen-reader-text wcpay-price-placeholder">$10.00</span>';
		document.body.appendChild( wrapper );

		renderer.showErrorState();

		expect(
			wrapper.querySelector( '.wcpay-price-placeholder' )
		).not.toBeNull();
		expect(
			wrapper.querySelector( '.wcpay-price-error' ).textContent
		).toBe( '—' );
	} );

	// Expected values are the client renderer's output for the same input (client 11.1.0
	// includes/multi-currency/client/async-renderer/index.ts:233-301, decimal.js-light arithmetic).
	const currency = ( code, rate, decimals, rounding, charm ) => ( {
		code,
		symbol: code,
		rate,
		decimals,
		decimal_sep: '.',
		thousand_sep: ',',
		symbol_pos: 'left',
		rounding,
		charm,
	} );

	test.each( [
		[
			'product, rounding 1, charm -0.01',
			currency( 'EUR', 0.85, 2, 1, -0.01 ),
			'10.00',
			'product',
			true,
			'8.99',
		],
		[
			'half cent, rounding 0',
			currency( 'GBP', 0.5, 2, 0, 0 ),
			'20.15',
			'product',
			true,
			'10.08',
		],
		[
			'step crossing, rounding 0.10',
			currency( 'GBP', 0.1, 2, 0.1, 0 ),
			'3.00',
			'product',
			true,
			'0.30',
		],
		[
			'shipping, charm on products only',
			currency( 'GBP', 0.82, 2, 0.5, -0.1 ),
			'10.00',
			'shipping',
			true,
			'8.50',
		],
		[
			'shipping, charm on shipping too',
			currency( 'GBP', 0.82, 2, 0.5, -0.1 ),
			'10.00',
			'shipping',
			false,
			'8.40',
		],
		[
			'coupon, half cent',
			currency( 'GBP', 0.5, 2, 0.5, -0.1 ),
			'20.15',
			'coupon',
			true,
			'10.08',
		],
		[
			'zero-decimal currency',
			currency( 'JPY', 151, 0, 0, 0 ),
			'10.50',
			'product',
			true,
			'1,586',
		],
		[
			'charm below zero clamps to zero',
			currency( 'GBP', 0.5, 2, 0, -1 ),
			'1.00',
			'product',
			true,
			'0.00',
		],
		[
			'store currency selected, half cent',
			mockConfig.currencies.USD,
			'10.075',
			'product',
			true,
			'10.08',
		],
		[
			'rate written with an exponent',
			currency( 'BTC', 1e-7, 2, 0, 0 ),
			'123456789',
			'product',
			true,
			'12.35',
		],
	] )(
		'converts %s as the client does',
		( label, selected, price, type, charmOnlyProducts, expected ) => {
			renderer.config = {
				default_currency: 'USD',
				selected_currency: selected.code,
				charm_only_products: charmOnlyProducts,
				currencies: {
					USD: mockConfig.currencies.USD,
					[ selected.code ]: selected,
				},
			};

			expect( renderer.convertPrice( price, type ) ).toBe( expected );
		}
	);

	// The same cases as MultiCurrencyPriceCalculatorTest::rendered_price_provider(), from the same canonical strings, so the price
	// shown and the price the server charges agree.
	test.each( [
		[
			'product, rounding 1, charm -0.01',
			'EUR',
			'0.85',
			'1',
			-0.01,
			'10.00',
			'product',
			true,
			'8.99',
		],
		[
			'half cent, rounding 0',
			'GBP',
			'0.5',
			'0',
			0.0,
			'20.15',
			'product',
			true,
			'10.08',
		],
		[
			'step crossing, rounding 0.10',
			'GBP',
			'0.1',
			'0.1',
			0.0,
			'3.00',
			'product',
			true,
			'0.30',
		],
		[
			'shipping, charm on products only',
			'GBP',
			'0.82',
			'0.5',
			-0.1,
			'10.00',
			'shipping',
			true,
			'8.50',
		],
		[
			'shipping, charm on shipping too',
			'GBP',
			'0.82',
			'0.5',
			-0.1,
			'10.00',
			'shipping',
			false,
			'8.40',
		],
		[
			'coupon, half cent',
			'GBP',
			'0.5',
			'0.5',
			-0.1,
			'20.15',
			'coupon',
			true,
			'10.08',
		],
		[
			'zero-decimal currency',
			'JPY',
			'151.0',
			'0',
			0.0,
			'10.50',
			'product',
			true,
			'1586',
		],
		[
			'charm below zero clamps to zero',
			'GBP',
			'0.5',
			'0',
			-1.0,
			'1.00',
			'product',
			true,
			'0.00',
		],
		[
			'rate written with an exponent',
			'BTC',
			'1e-7',
			'0',
			0.0,
			'123456789',
			'product',
			true,
			'12.35',
		],
		[
			'excess above a 0.01 step',
			'GBP',
			'0.10000000001',
			'0.01',
			0.0,
			'3.00',
			'product',
			true,
			'0.31',
		],
		[
			'excess above a 0.10 step',
			'GBP',
			'0.1000000001',
			'0.1',
			0.0,
			'3.00',
			'product',
			true,
			'0.40',
		],
		[
			'excess above a 1 step',
			'GBP',
			'0.3333333334',
			'1',
			0.0,
			'3.00',
			'product',
			true,
			'2.00',
		],
		[
			'excess above a 100 step',
			'GBP',
			'0.3333333334',
			'100',
			0.0,
			'300.00',
			'product',
			true,
			'200.00',
		],
		[
			'excess above a 100 step, JPY',
			'JPY',
			'150.000000001',
			'100',
			0.0,
			'100.00',
			'product',
			true,
			'15100',
		],
		[
			'JPY with a 100 step',
			'JPY',
			'151.0',
			'100',
			0.0,
			'10.50',
			'product',
			true,
			'1600',
		],
		[
			'just below a 0.10 step',
			'GBP',
			'0.1',
			'0.1',
			0.0,
			'2.99',
			'product',
			true,
			'0.30',
		],
		[
			'large exact quotient, 0.01 step',
			'GBP',
			'0.3',
			'0.01',
			0.0,
			'1000000.00',
			'product',
			true,
			'300000.00',
		],
		[
			'large quotient with an excess',
			'GBP',
			'0.7',
			'0.01',
			0.0,
			'999999.99',
			'product',
			true,
			'700000.00',
		],
		[
			'exact 0.05 step',
			'GBP',
			'0.7',
			'0.05',
			0.0,
			'10.50',
			'product',
			true,
			'7.35',
		],
		[
			'nine-digit rate just above a cent',
			'GBP',
			'3.00000003',
			'0.01',
			0.0,
			'666666.67',
			'product',
			true,
			'2000000.04',
		],
		[
			'rate one float step above 1',
			'GBP',
			'1.000000000000005',
			'100',
			0.0,
			'100.00',
			'product',
			true,
			'200.00',
		],
		[
			'exact 0.25 step',
			'GBP',
			'0.1',
			'0.25',
			0.0,
			'10.00',
			'product',
			true,
			'1.00',
		],
		[
			'excess above a 0.25 step',
			'GBP',
			'0.1000000001',
			'0.25',
			0.0,
			'10.00',
			'product',
			true,
			'1.25',
		],
		[
			'exact 0.5 step',
			'GBP',
			'0.5',
			'0.5',
			0.0,
			'3.00',
			'product',
			true,
			'1.50',
		],
		[
			'between 0.5 steps',
			'GBP',
			'0.3',
			'0.5',
			0.0,
			'7.00',
			'product',
			true,
			'2.50',
		],
		[
			'exact 1 step',
			'GBP',
			'0.25',
			'1',
			0.0,
			'12.00',
			'product',
			true,
			'3.00',
		],
	] )(
		'computes %s from the canonical strings',
		(
			label,
			code,
			rate,
			rounding,
			charm,
			price,
			type,
			charmOnlyProducts,
			expected
		) => {
			const selected = {
				...currency(
					code,
					Number( rate ),
					'JPY' === code ? 0 : 2,
					Number( rounding ),
					charm
				),
				thousand_sep: '',
				rate_decimal: rate,
				rounding_decimal: rounding,
			};
			renderer.config = {
				default_currency: 'USD',
				selected_currency: code,
				charm_only_products: charmOnlyProducts,
				currencies: {
					USD: mockConfig.currencies.USD,
					[ code ]: selected,
				},
			};

			expect( renderer.convertPrice( price, type ) ).toBe( expected );
		}
	);

	test( 'computes with the canonical decimal strings when the config has them', () => {
		renderer.config = {
			default_currency: 'USD',
			selected_currency: 'GBP',
			charm_only_products: true,
			currencies: {
				USD: mockConfig.currencies.USD,
				// The numbers stay for configs served before the strings; the strings win.
				GBP: {
					...currency( 'GBP', 0.5, 2, 0.5, 0 ),
					rate_decimal: '0.1',
					rounding_decimal: '0.10',
				},
			},
		};

		expect( renderer.convertPrice( '3.00', 'product' ) ).toBe( '0.30' );
	} );

	test( 'publishes the selected currency after its single async renderer initialization', async () => {
		const originalFetch = window.fetch;
		jest.resetModules();
		window.fetch = jest.fn().mockResolvedValue( {
			ok: true,
			json: jest.fn().mockResolvedValue( mockConfig ),
		} );

		try {
			require( '../multi-currency-async-renderer' );

			expect( window.wcpayAsyncCurrency.ready ).toBeInstanceOf( Promise );
			await expect( window.wcpayAsyncCurrency.ready ).resolves.toBe(
				'eur'
			);
			expect( window.fetch ).toHaveBeenCalledTimes( 1 );
		} finally {
			window.fetch = originalFetch;
			delete window.wcpayAsyncCurrency;
		}
	} );
} );
