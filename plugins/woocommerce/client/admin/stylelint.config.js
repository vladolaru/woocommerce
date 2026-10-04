// A literal px length above 1px (0 and 1px are not design tokens). Negative values count too.
const woopaymentsLiteralPx =
	/(?:^|[^\w.$-])-?(?:[2-9]|[1-9]\d+|1\.\d+)(?:\.\d+)?px\b/;

// A four-value margin or padding shorthand, which sets the physical left and right sides.
const woopaymentsFourValues = /^\S+\s+\S+\s+\S+\s+\S+$/;

module.exports = {
	extends: '@wordpress/stylelint-config/scss',
	ignoreFiles: [ './vendor/**/*.scss' ],
	rules: {
		'at-rule-empty-line-before': null,
		'at-rule-no-unknown': null,
		'comment-empty-line-before': null,
		'declaration-block-no-duplicate-properties': null,
		'declaration-colon-newline-after': null,
		'declaration-property-unit-allowed-list': null,
		'font-weight-notation': null,
		'max-line-length': null,
		'no-descending-specificity': null,
		'no-duplicate-selectors': null,
		'rule-empty-line-before': null,
		'selector-class-pattern': null,
		'string-quotes': 'double',
		'value-keyword-case': null,
		'value-list-comma-newline-after': null,
		// TODO: fix these rules
		// New rules enabled after updating @wordpress/stylelint-config
		'scss/at-import-partial-extension': 'always',
		'scss/at-import-no-partial-leading-underscore': null,
		'scss/no-global-function-names': null,
		'scss/operator-no-unspaced': null,
		'scss/at-extend-no-missing-placeholder': null,
		'scss/selector-no-redundant-nesting-selector': null,
		'selector-id-pattern': null,
		'no-invalid-position-at-import-rule': null,
		'length-zero-no-unit': [ true, { ignoreFunctions: [ 'calc', 'var' ] } ],
	},
	overrides: [
		{
			// Native WooPayments styles take colours, spacing, type and radii from upstream tokens:
			// the WP admin colour scheme, @wordpress/components, WooCommerce's shared admin variables,
			// then client/woopayments/_tokens.scss. Literal px survive only for 0 and 1px.
			files: [ 'client/woopayments/**/*.scss' ],
			rules: {
				'color-no-hex': true,
				// Logical properties, as the repo's AGENTS.md asks: no physical left/right margins, paddings, borders,
				// offsets, floats or alignment, and no four-value margin/padding shorthand (it sets left and right).
				'property-disallowed-list': [
					'/^(margin|padding|border)-(left|right)(-.+)?$/',
					'/^(left|right)$/',
				],
				'declaration-property-value-allowed-list': {
					'text-align': [
						'/^(start|end|center|justify|inherit|initial|unset)$/',
					],
					float: [ '/^(none|inline-start|inline-end|inherit|initial|unset)$/' ],
				},
				'declaration-property-value-disallowed-list': [
					{
						'/^(margin|padding)(-.+)?$/': [ woopaymentsLiteralPx ],
						'/^(margin|padding)$/': [ woopaymentsFourValues ],
						'/^(row-|column-)?gap$/': [ woopaymentsLiteralPx ],
						'font-size': [ woopaymentsLiteralPx ],
						'/^border(-.+)?-radius$/': [ woopaymentsLiteralPx ],
						'/^(min-|max-)?(width|height|inline-size|block-size)$/':
							[ woopaymentsLiteralPx ],
					},
					{
						message: ( property, value ) =>
							woopaymentsFourValues.test( value )
								? `Use ${ property }-block and ${ property }-inline (logical) instead of a four-value shorthand.`
								: `Use an upstream token or client/woopayments/_tokens.scss for "${ property }" instead of a literal px value above 1px.`,
					},
				],
			},
		},
	],
};
