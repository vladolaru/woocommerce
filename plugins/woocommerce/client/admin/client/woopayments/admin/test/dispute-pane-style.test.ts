/**
 * External dependencies
 */
import fs from 'fs';
import path from 'path';
import * as sass from 'sass';

// The margins that the stylesheet's rules reaching an element set. jsdom's computed style does not weigh specificity,
// so the rules are matched directly.
const getMarginsReaching = ( css: string, element: Element ) =>
	[ ...css.matchAll( /([^{}]+)\{([^{}]*)\}/g ) ]
		.filter( ( [ , selectors ] ) =>
			selectors
				.split( ',' )
				.some( ( selector ) => element.matches( selector.trim() ) )
		)
		.flatMap( ( [ , , body ] ) =>
			[ ...body.matchAll( /(?:^|;)\s*(margin[a-z-]*):\s*([^;]+)/g ) ].map(
				( [ , property, value ] ) => `${ property }: ${ value.trim() }`
			)
		);

// Client 11.1.0 `components/accordion/style.scss:36-42`: the accordion title has no margin, so the collapsed "Steps you
// can take" panel is only its toggle row. The dispute pane's heading margin is for its own "Dispute details" heading.
test( 'keeps the dispute heading margin off the "Steps you can take" accordion title', () => {
	// The placeholder mixin comes from @woocommerce/internal-style-build; it is not under test here.
	const css = sass.compileString(
		'@mixin placeholder() {}\n' +
			fs.readFileSync(
				path.resolve(
					__dirname,
					'../money-movement/transaction-details.scss'
				),
				'utf8'
			)
	).css;
	document.body.innerHTML = `
		<div class="woocommerce-woopayments-money-movement">
			<div class="woocommerce-woopayments-dispute-pane">
				<div class="woocommerce-woopayments-dispute-pane__details">
					<hr />
					<h2 id="dispute-heading">Dispute details</h2>
					<div class="woocommerce-woopayments-money-movement__dispute-response">
						<div class="woocommerce-woopayments-accordion">
							<h2 class="woocommerce-woopayments-accordion__title" id="steps-title">Steps you can take</h2>
						</div>
					</div>
				</div>
			</div>
		</div>`;

	expect(
		getMarginsReaching(
			css,
			document.getElementById( 'steps-title' ) as Element
		)
	).toEqual( [ 'margin: 0' ] );
	expect(
		getMarginsReaching(
			css,
			document.getElementById( 'dispute-heading' ) as Element
		)
	).toEqual( [ 'margin: 24px 0' ] );

	document.body.innerHTML = '';
} );
