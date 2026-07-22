/**
 * Webpack config for the Playwright designer-canvas harness.
 *
 * Unlike the main build, nothing is externalized: React, antd, and the
 * WordPress packages are bundled so the harness page runs standalone
 * from file:// with no WordPress vendor scripts.
 */
const defaultConfig = require( '@wordpress/scripts/config/webpack.config' );
const path = require( 'path' );

module.exports = {
	...defaultConfig,
	mode: 'development',
	devtool: false,
	entry: {
		harness: path.resolve(
			process.cwd(),
			'tests',
			'playwright',
			'designer',
			'harness',
			'harness.jsx'
		),
	},
	output: {
		path: path.resolve(
			process.cwd(),
			'tests',
			'playwright',
			'designer',
			'harness',
			'dist'
		),
		filename: '[name].js',
	},
	// Drop DependencyExtractionWebpackPlugin so @wordpress/* bundle in.
	plugins: defaultConfig.plugins.filter(
		( plugin ) =>
			plugin.constructor.name !== 'DependencyExtractionWebpackPlugin'
	),
	performance: { hints: false },
};
