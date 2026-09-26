/**
 * Undo the HTML escaping core applies to a Store API error message.
 *
 * `CheckoutTrait::process_payment()` re-throws a payment failure as
 * `throw new RouteException( …, esc_html( $e->getMessage() ), 400 )`, so the
 * transport JSON carries `Your card&#039;s security code is incorrect.` where
 * the sentence native queued has a plain apostrophe. That is core encoding the
 * message for transport, not a different sentence, so a comparison decodes
 * rather than hard-coding the entity — which would leave the expectation
 * silently wrong the day core stops escaping.
 *
 * Deliberately narrow: it reverses exactly the five substitutions `esc_html()`
 * makes and nothing else, so a genuinely different string cannot be massaged
 * into a match. Shopper-facing assertions still run against the rendered DOM,
 * where a literal `&#039;` would fail to match and would be a real bug rather
 * than something this helper hides.
 */
export function decodeEscapedHtml( value: string ): string {
	return value
		.replace( /&#0?39;/g, "'" )
		.replace( /&quot;/g, '"' )
		.replace( /&lt;/g, '<' )
		.replace( /&gt;/g, '>' )
		.replace( /&amp;/g, '&' );
}
