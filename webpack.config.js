const defaultConfig = require( '@wordpress/scripts/config/webpack.config' );
const path = require( 'path' );

module.exports = {
	...defaultConfig,
	entry: {
		designer: path.resolve( process.cwd(), 'src', 'designer', 'index.js' ),
		settings: path.resolve( process.cwd(), 'src', 'settings', 'index.js' ),
		// Blocks are keyed 'blocks/<name>/index' so they emit into build/blocks/<name>/, e.g.:
		// 'blocks/my-certificates/index': path.resolve( process.cwd(), 'blocks', 'my-certificates', 'index.js' ),
	},
	output: {
		path: path.resolve( process.cwd(), 'build' ),
		filename: '[name].js',
		// Async chunks use their webpackChunkName so code-split screens emit
		// with readable names instead of numeric ids.
		chunkFilename: '[name].js',
	},
};
