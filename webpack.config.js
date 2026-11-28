/**
 * The one JavaScript build (engineering.md, "Builds"): wp-scripts' own configuration, with the admin app's
 * entries added to its script build.
 */
const defaults = require( '@wordpress/scripts/config/webpack.config' );

const configs = Array.isArray( defaults ) ? defaults : [ defaults ];
const scripts = configs[ 0 ];
const entry = scripts.entry;

scripts.entry = async () => ( {
	...( typeof entry === 'function' ? await entry() : entry ),
	'Admin/app/grid': './plugins/kalaka/src/Admin/app/grid/index.js',
} );

// DataViews' stylesheet is bundled with the app; a request under `@wordpress/` would be made a WordPress
// script dependency instead, which WordPress does not ship.
scripts.resolve = {
	...scripts.resolve,
	alias: {
		...( scripts.resolve && scripts.resolve.alias ),
		'kalaka-dataviews-style$': require.resolve( '@wordpress/dataviews/build-style/style.css' ),
	},
};

// The package marks its files free of side effects, which would drop the stylesheet import.
scripts.module.rules.push( {
	test: /@wordpress[\\/]dataviews[\\/]build-style[\\/]style\.css$/,
	sideEffects: true,
} );

module.exports = Array.isArray( defaults ) ? configs : scripts;
