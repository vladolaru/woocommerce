// A literal px length above 1px (0 and 1px are not design tokens). Negative values count too.
const woopaymentsLiteralPx =
	/(?:^|[^\w.$-])-?(?:[2-9]|[1-9]\d+|1\.\d+)(?:\.\d+)?px\b/;

// A margin or padding shorthand of one to three values; a fourth value sets the physical left side.
// A parenthesised group (calc(), var()) counts as one value.
const woopaymentsValue = '(?:[^\\s()]|\\((?:[^()]|\\([^()]*\\))*\\))+';
const woopaymentsUpToThreeValues = new RegExp(
	`^${ woopaymentsValue }(?:\\s+${ woopaymentsValue }){0,2}$`
);

module.exports = {
	extends: '../../../../stylelint.config.cjs',
	ignoreFiles: [ './vendor/**/*.scss' ],
	rules: {
		'scss/load-partial-extension': 'always',
	},
	overrides: [
		{
			// Native WooPayments styles take colours, spacing, type and radii from upstream tokens:
			// the WP admin colour scheme, @wordpress/components, WooCommerce's shared admin variables,
			// then client/woopayments/_tokens.scss. Literal px survive only for 0 and 1px.
			files: [ 'client/woopayments/**/*.scss' ],
			rules: {
				'color-no-hex': true,
				// Logical properties, as the repo's AGENTS.md asks: no physical left/right margins, paddings, borders
				// or offsets here; the allowed list below keeps alignment, floats and margin/padding shorthands logical.
				'property-disallowed-list': [
					'/^(margin|padding|border)-(left|right)(-.+)?$/',
					'/^(left|right)$/',
				],
				'declaration-property-value-allowed-list': [
					{
						'text-align': [
							'/^(start|end|center|justify|inherit|initial|unset)$/',
						],
						float: [
							'/^(none|inline-start|inline-end|inherit|initial|unset)$/',
						],
						'/^(margin|padding)$/': [ woopaymentsUpToThreeValues ],
					},
					{
						// This rule passes no arguments to a message function, so one message covers both cases.
						message:
							'Use logical values: text-align start or end, float inline-start or inline-end, and margin-block/margin-inline (or padding-) instead of a four-value shorthand.',
					},
				],
				'declaration-property-value-disallowed-list': [
					{
						'/^(margin|padding)(-.+)?$/': [ woopaymentsLiteralPx ],
						'/^(row-|column-)?gap$/': [ woopaymentsLiteralPx ],
						'font-size': [ woopaymentsLiteralPx ],
						'/^border(-.+)?-radius$/': [ woopaymentsLiteralPx ],
						'/^(min-|max-)?(width|height|inline-size|block-size)$/':
							[ woopaymentsLiteralPx ],
					},
					{
						message: ( property ) =>
							`Use an upstream token or client/woopayments/_tokens.scss for "${ property }" instead of a literal px value above 1px.`,
					},
				],
			},
		},
	],
};
