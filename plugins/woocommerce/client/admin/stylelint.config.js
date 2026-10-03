// A literal px length above 1px (0 and 1px are not design tokens). Negative values count too.
const woopaymentsLiteralPx =
	/(?:^|[^\w.$-])-?(?:[2-9]|[1-9]\d+|1\.\d+)(?:\.\d+)?px\b/;

// Native WooPayments files the styling sweep has not converted yet; each leaves the list when it converts.
const woopaymentsUnconvertedFiles = [
	'client/woopayments/admin/capital/active-loan-summary.scss',
	'client/woopayments/admin/capital/style.scss',
	'client/woopayments/admin/card-readers/style.scss',
	'client/woopayments/admin/dataviews.scss',
	'client/woopayments/admin/documents/style.scss',
	'client/woopayments/admin/documents/vat-modal.scss',
	'client/woopayments/admin/money-movement/dispute-evidence.scss',
	'client/woopayments/admin/money-movement/transaction-details.scss',
	'client/woopayments/admin/money-movement/transaction-timeline.scss',
	'client/woopayments/admin/overview/components/help-popover.scss',
	'client/woopayments/admin/overview/components/status-chip.scss',
	'client/woopayments/admin/payout-details.scss',
	'client/woopayments/admin/reports/style.scss',
	'client/woopayments/admin/style.scss',
	'client/woopayments/promotions/style.scss',
];

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
		{
			// Files not yet converted by the native styling sweep. Remove each file as it converts.
			files: woopaymentsUnconvertedFiles,
			rules: {
				'color-no-hex': null,
				'declaration-property-value-disallowed-list': null,
			},
		},
	],
};
