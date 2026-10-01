/**
 * External dependencies
 */
import fs from 'fs';
import path from 'path';
import * as sass from 'sass';

type CompiledRule = { media: string; selectors: string[]; body: string };
type Matchable = { matches: ( selector: string ) => boolean };

/**
 * The rules of a native WooPayments stylesheet, each with the media condition it sits under.
 *
 * @param stylesheet The stylesheet, relative to `woopayments/admin/`, such as `style.scss`.
 */
const getCompiledRules = ( stylesheet: string ): CompiledRule[] => {
	const css = sass.compileString(
		fs.readFileSync(
			path.resolve( __dirname, '../..', stylesheet ),
			'utf8'
		)
	).css;
	const mediaBlocks = [
		...css.matchAll( /@media ([^{]+)\{((?:[^{}]*\{[^{}]*\})*)\s*\}/g ),
	];
	const topLevel = mediaBlocks.reduce(
		( text, [ block ] ) => text.replace( block, '' ),
		css
	);
	const collect = ( text: string, media: string ) =>
		[ ...text.matchAll( /([^{}]+)\{([^{}]*)\}/g ) ].map(
			( [ , selectors, body ] ) => ( {
				media,
				selectors: selectors.split( ',' ).map( ( s ) => s.trim() ),
				body,
			} )
		);

	return [
		...collect( topLevel, '' ),
		...mediaBlocks.flatMap( ( [ , media, inner ] ) =>
			collect( inner, media.trim() )
		),
	];
};

/**
 * Whether a rule of the stylesheet that sets the property to the value reaches the element.
 *
 * @param element    The rendered element.
 * @param stylesheet The stylesheet, relative to `woopayments/admin/`.
 * @param property   The property, such as `display`.
 * @param value      The value, such as `flex`.
 * @param media      Text the rule's media condition must contain; empty matches every rule.
 */
export const hasStyleRule = (
	element: Matchable,
	stylesheet: string,
	property: string,
	value: string,
	media = ''
) =>
	getCompiledRules( stylesheet ).some(
		( rule ) =>
			rule.media.includes( media ) &&
			new RegExp(
				`(^|[;\\s])${ property }:\\s*${ value }\\s*(;|$)`
			).test( rule.body ) &&
			rule.selectors.some( ( selector ) => element.matches( selector ) )
	);
