import fs from 'fs';
import path from 'path';
import * as sass from 'sass';

const normalizeColor = ( color: string ) => {
	return color === '#dcdcde' ? 'rgb(220, 220, 222)' : color;
};

afterEach( () => {
	document.head.innerHTML = '';
	document.body.className = '';
	document.body.innerHTML = '';
} );

test( 'scopes the white header background to mounted Reports content', () => {
	const compiledReportsStyles = sass.compileString(
		fs.readFileSync(
			path.resolve( __dirname, '../reports/style.scss' ),
			'utf8'
		)
	).css;
	const baseStyles = document.createElement( 'style' );
	baseStyles.textContent = `.woocommerce-layout__header { background: #f0f0f1; border-bottom: 1px solid #dcdcde; }`;
	const reportsStyles = document.createElement( 'style' );
	reportsStyles.textContent = compiledReportsStyles;
	document.head.append( baseStyles, reportsStyles );

	document.body.className =
		'woocommerce_page_wc-settings woocommerce-settings-payments-tab';
	const layoutRoot = document.createElement( 'div' );
	layoutRoot.innerHTML =
		'<div class="woocommerce-layout"><header class="woocommerce-layout__header"></header></div>';
	const reportsRoot = document.createElement( 'div' );
	reportsRoot.innerHTML =
		'<div class="woocommerce-woopayments-reports"></div>';
	document.body.append( layoutRoot, reportsRoot );

	const header = layoutRoot.querySelector( '.woocommerce-layout__header' );
	if ( ! header ) {
		throw new Error( 'Expected the WooCommerce Admin header to mount.' );
	}

	const mountedHeaderStyles = window.getComputedStyle( header );
	expect( mountedHeaderStyles.backgroundColor ).toBe( 'rgb(255, 255, 255)' );
	expect( mountedHeaderStyles.borderBottomStyle ).toBe( 'solid' );
	expect( mountedHeaderStyles.borderBottomWidth ).toBe( '1px' );
	expect( normalizeColor( mountedHeaderStyles.borderBottomColor ) ).toBe(
		'rgb(220, 220, 222)'
	);

	reportsRoot.remove();

	expect( window.getComputedStyle( header ).backgroundColor ).toBe(
		'rgb(240, 240, 241)'
	);
} );
