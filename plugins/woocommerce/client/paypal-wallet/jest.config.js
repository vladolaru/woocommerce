const path = require( 'path' );

module.exports = {
	preset: '@wordpress/jest-preset-default',
	rootDir: __dirname,
	moduleDirectories: [ 'node_modules' ],
	moduleNameMapper: {
		'^react$': require.resolve( 'react' ),
		'^react-dom$': require.resolve( 'react-dom' ),
		'^@ppcp-button/(.*)$':
			'<rootDir>/modules/ppcp-button/resources/js/modules/$1',
		'^@ppcp-settings/(.*)$':
			'<rootDir>/modules/ppcp-settings/resources/js/$1',
		'^@ppcp-blocks/(.*)$': '<rootDir>/modules/ppcp-blocks/resources/js/$1',
		'^@ppcp-paylater-block/(.*)$':
			'<rootDir>/modules/ppcp-paylater-block/resources/js/$1',
		'^@ppcp-sdk-v6/(.*)$': '<rootDir>/modules/ppcp-sdk-v6/resources/js/$1',
		'^@ppcp-wc-gateway/(.*)$':
			'<rootDir>/modules/ppcp-wc-gateway/resources/js/$1',
		'^@ppcp-test/(.*)$': '<rootDir>/tests/js/$1',
	},
	testPathIgnorePatterns: [ '<rootDir>/tests/', '<rootDir>/node_modules/' ],
	transform: {
		'^.+\\.(js|ts|tsx)$': '<rootDir>/tests/js/jestPreprocess.js',
	},
	cacheDirectory: path.resolve(
		__dirname,
		'../../node_modules/.cache/jest-paypal-wallet'
	),
};
