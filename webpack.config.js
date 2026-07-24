const defaultConfig = require( '@wordpress/scripts/config/webpack.config' );
const path = require( 'path' );

module.exports = {
	...defaultConfig,
	entry: {
		dashboard: path.resolve(
			process.cwd(),
			'src',
			'dashboard',
			'index.js'
		),
		designer: path.resolve( process.cwd(), 'src', 'designer', 'index.js' ),
		settings: path.resolve( process.cwd(), 'src', 'settings', 'index.js' ),
		issue: path.resolve( process.cwd(), 'src', 'issue', 'index.js' ),
		onboarding: path.resolve(
			process.cwd(),
			'src',
			'onboarding',
			'index.js'
		),
		// Blocks are keyed 'blocks/<name>/index' so they emit into build/blocks/<name>/.
		'blocks/verify/index': path.resolve(
			process.cwd(),
			'blocks',
			'verify',
			'index.js'
		),
	},
	output: {
		path: path.resolve( process.cwd(), 'build' ),
		filename: '[name].js',
		// Async chunks use their webpackChunkName so code-split screens emit
		// with readable names instead of numeric ids.
		chunkFilename: '[name].js',
	},
};
