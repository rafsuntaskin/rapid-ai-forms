/**
 * Custom webpack config: extend @wordpress/scripts' default with two named entries
 * so admin.js and frontend.js end up in build/ as distinct bundles.
 */
const defaultConfig = require( '@wordpress/scripts/config/webpack.config' );
const path = require( 'path' );

module.exports = {
	...defaultConfig,
	entry: {
		admin: path.resolve( __dirname, 'src/admin/index.js' ),
		frontend: path.resolve( __dirname, 'src/frontend/index.js' ),
	},
};
