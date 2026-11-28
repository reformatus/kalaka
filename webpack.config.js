/**
 * The one JavaScript build (engineering.md, "Builds"): `@wordpress/scripts`' own configuration, which finds
 * every `block.json`, with the admin app's entries added to its script build and one more classic script
 * entry: the claim popup of a form page (`Forms/Blocks/form/claim.js`, a `@wordpress/components` Modal),
 * which no `block.json` names because it is enqueued only on a page with a pending claim
 * (`Forms\Module::CLAIM_SCRIPT`). That entry belongs to the Kaláka plugin's build only: the other builds
 * (`kalaka-ranking`, `kalaka-support`) have the plain configuration.
 */
const defaults = require( '@wordpress/scripts/config/webpack.config' );

const configs = Array.isArray( defaults ) ? defaults : [ defaults ];
const scripts = configs[ 0 ];
const entry = scripts.entry;

const claim = {
	'Forms/Blocks/form/claim': './plugins/kalaka/src/Forms/Blocks/form/claim.js',
};

// `wp-scripts` sets `WP_SOURCE_PATH` from `--source-path`; the claim popup is in the Kaláka plugin's source.
const sourcePath = ( process.env.WP_SOURCE_PATH || '' ).replace( /\/+$/, '' );
const isKalaka = sourcePath === 'plugins/kalaka/src';

scripts.entry = async () => ( {
	...( typeof entry === 'function' ? await entry() : entry ),
	'Admin/app/grid': './plugins/kalaka/src/Admin/app/grid/index.js',
	...( isKalaka ? claim : {} ),
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
