/**
 * External dependencies
 */
import fs from 'fs';
import path from 'path';
import * as sass from 'sass';

/**
 * The selectors of a native WooPayments stylesheet's rules that set a property to a value,
 * for checking which rendered elements a rule reaches. `@import`s are left out, so the
 * inlined DataViews stylesheet does not count.
 *
 * @param stylesheet The stylesheet, relative to `woopayments/admin/`, such as `dataviews.scss`.
 * @param property   The property, such as `justify-content`.
 * @param value      The value, such as `center`.
 */
export const getSelectorsSetting = (
	stylesheet: string,
	property: string,
	value: string
) => {
	const css = sass.compileString(
		fs
			.readFileSync(
				path.resolve( __dirname, '../..', stylesheet ),
				'utf8'
			)
			.replace( /^@import .*$/gm, '' )
	).css;

	return [ ...css.matchAll( /([^{}]+)\{([^{}]*)\}/g ) ]
		.filter( ( [ , , body ] ) =>
			new RegExp(
				`(^|[;\\s])${ property }:\\s*${ value }\\s*(;|$)`
			).test( body )
		)
		.flatMap( ( [ , selectors ] ) =>
			selectors.split( ',' ).map( ( selector ) => selector.trim() )
		);
};

/**
 * Whether one of a stylesheet's rules setting the property to the value reaches the element.
 *
 * @param element    The rendered element.
 * @param stylesheet The stylesheet, relative to `woopayments/admin/`.
 * @param property   The property.
 * @param value      The value.
 */
export const isStyledBy = (
	element: Element,
	stylesheet: string,
	property: string,
	value: string
) =>
	getSelectorsSetting( stylesheet, property, value ).some( ( selector ) =>
		element.matches( selector )
	);
