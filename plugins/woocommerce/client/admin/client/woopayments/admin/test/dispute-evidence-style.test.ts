/**
 * External dependencies
 */
import fs from 'fs';
import path from 'path';
import * as sass from 'sass';

// The `flex-direction` values that the stylesheet's top-level rules reaching an element set. jsdom's computed style
// does not weigh specificity, and media queries are left out, so this reads a wide screen.
const getFlexDirectionsReaching = ( css: string, element: Element ) =>
	[
		...css
			.replace( /@media[^{]*\{(?:[^{}]*\{[^{}]*\})*[^{}]*\}/g, '' )
			.matchAll( /([^{}]+)\{([^{}]*)\}/g ),
	]
		.filter( ( [ , selectors ] ) =>
			selectors
				.split( ',' )
				.some( ( selector ) => element.matches( selector.trim() ) )
		)
		.flatMap( ( [ , , body ] ) =>
			[ ...body.matchAll( /flex-direction:\s*([^;]+)/g ) ].map(
				( [ , value ] ) => value.trim()
			)
		);

// Client 11.1.0 `new-evidence/customer-details.tsx` and `style.scss:81-103`: Name, Phone, Email and IP address sit in
// one row, each a label over its value, with the billing address under the row.
test( 'lays the challenge customer details out as one row of four', () => {
	const css = sass.compileString(
		fs.readFileSync(
			path.resolve(
				__dirname,
				'../money-movement/dispute-evidence.scss'
			),
			'utf8'
		)
	).css;
	document.body.innerHTML = `
		<section class="woocommerce-woopayments-dispute-evidence-customer">
			<h3>Customer details</h3>
			<div class="woocommerce-woopayments-dispute-evidence-customer__row" id="row">
				<div id="name"><div class="woocommerce-woopayments-dispute-evidence-customer__label">NAME</div><a href="#">Ada</a></div>
			</div>
			<div id="billing"><div class="woocommerce-woopayments-dispute-evidence-customer__label">BILLING ADDRESS</div></div>
		</section>`;

	const byId = ( id: string ) => document.getElementById( id ) as Element;

	expect( getFlexDirectionsReaching( css, byId( 'row' ) ) ).toEqual( [] );
	expect( getFlexDirectionsReaching( css, byId( 'name' ) ) ).toEqual( [
		'column',
	] );
	expect( getFlexDirectionsReaching( css, byId( 'billing' ) ) ).toEqual( [
		'column',
	] );
	expect( css ).toMatch(
		/@media \(max-width: 700px\)\s*\{\s*\.woocommerce-woopayments-dispute-evidence-customer__row\s*\{[^}]*flex-direction: column/
	);

	document.body.innerHTML = '';
} );
