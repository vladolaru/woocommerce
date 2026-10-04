const path = require( 'path' );
const CopyWebpackPlugin = require( 'copy-webpack-plugin' );
const WooCommerceDependencyExtractionWebpackPlugin = require( '@woocommerce/dependency-extraction-webpack-plugin' );
const defaultConfig = require( '@wordpress/scripts/config/webpack.config' );

// Writes directly to the plugin's assets/client/paypal-wallet/ so PHP (AssetGetter) can enqueue from the final location.
const BUILD_DIR = path.resolve(
	__dirname,
	'../../assets/client/paypal-wallet'
);

// Entries per kept module, as in the extension's webpack.config.js; the compiled name is "<module>-<type>-<path with dashes>".
const modulesAssets = {
	'ppcp-admin-notices': [ 'js/boot-admin.js', 'css/styles.scss' ],
	'ppcp-blocks': [
		'js/checkout-block.js',
		'js/ProductPayLaterMessagesBlock/product-paylater-block.js',
		'js/ProductSmartButtonsBlock/product-smart-buttons-block.js',
		'css/gateway.scss',
		'css/gateway-editor.scss',
	],
	'ppcp-button': [
		'js/button.js',
		'css/gateway.scss',
	],
	'ppcp-paylater-block': [ 'js/paylater-block.js', 'css/edit.scss' ],
	'ppcp-paylater-wc-blocks': [
		'js/CartPayLaterMessagesBlock/cart-paylater-block.js',
		'js/CartPayLaterMessagesBlock/cart-paylater-block-inserter.js',
		'js/CheckoutPayLaterMessagesBlock/checkout-paylater-block.js',
	],
	'ppcp-save-payment-methods': [ 'js/add-payment-method.js' ],
	'ppcp-sdk-v6': [
		'js/boot.js',
		'js/checkout-block.js',
		'js/boot-add-payment-method.js',
		'css/gateway.scss',
		'css/checkout-block.scss',
	],
	'ppcp-settings': [ 'js/index.js', 'css/styles.scss' ],
	'ppcp-wc-gateway': [
		'js/common.js',
		'js/gateway-settings.js',
		'js/fraudnet.js',
		'js/void-button.js',
		'css/gateway-settings.scss',
		'css/common.scss',
	],
	'ppcp-vault-component': [ 'js/checkout.js' ],
};

const entries = {};
const aliases = {};
for ( const [ moduleId, assets ] of Object.entries( modulesAssets ) ) {
	for ( const relativePath of assets ) {
		const name =
			moduleId +
			'-' +
			relativePath
				.replace( /\.jsx?$/g, '' )
				.replace( /\.scss$/g, '' )
				.split( '/' )
				.join( '-' );
		entries[ name ] = `./modules/${ moduleId }/resources/${ relativePath }`;
	}
	aliases[ '@' + moduleId ] = path.resolve(
		__dirname,
		`./modules/${ moduleId }/resources/js`
	);
	if ( moduleId === 'ppcp-button' ) {
		aliases[ '@' + moduleId ] += '/modules';
	}
}

module.exports = {
	...defaultConfig,
	resolve: {
		...defaultConfig.resolve,
		alias: aliases,
	},
	entry: entries,
	output: {
		publicPath: './',
		path: BUILD_DIR,
		filename: '[name].js',
	},
	plugins: [
		...defaultConfig.plugins.filter(
			( plugin ) =>
				plugin.constructor.name !== 'DependencyExtractionWebpackPlugin'
		),
		new WooCommerceDependencyExtractionWebpackPlugin(),
		new CopyWebpackPlugin( {
			patterns: [
				// Static files PHP serves through AssetGetter::get_static_asset_url(): static/<module>/<path>.
				{
					from: 'modules/*/assets/**/*',
					to: ( { absoluteFilename } ) =>
						path.join(
							'static',
							path
								.relative(
									path.join( __dirname, 'modules' ),
									absoluteFilename
								)
								.replace( /^([^/]+)\/assets\//, '$1/' )
						),
					noErrorOnMissing: true,
				},
				// block.json files PHP registers from ppcp.path-to-plugin-folder . 'modules/<module>/...'.
				{
					from: 'modules/**/block.json',
					to: ( { absoluteFilename } ) =>
						path.relative( __dirname, absoluteFilename ),
					noErrorOnMissing: true,
				},
			],
		} ),
	],
};
