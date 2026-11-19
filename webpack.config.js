/**
 * The one JavaScript build (engineering.md, "Builds"): `@wordpress/scripts`' own configuration, which finds
 * every `block.json`, with one more classic script entry: the claim popup of a form page
 * (`Forms/Blocks/form/claim.js`, a `@wordpress/components` Modal), which no `block.json` names because it
 * is enqueued only on a page with a pending claim (`Forms\Module::CLAIM_SCRIPT`).
 */
const defaults = require('@wordpress/scripts/config/webpack.config');

const [script, module_] = Array.isArray(defaults) ? defaults : [defaults];

const claim = {
	'Forms/Blocks/form/claim': './plugins/kalaka/src/Forms/Blocks/form/claim.js',
};

const withClaim = {
	...script,
	entry: async () => {
		const entry =
			typeof script.entry === 'function'
				? await script.entry()
				: script.entry;
		return { ...entry, ...claim };
	},
};

module.exports = module_ === undefined ? withClaim : [withClaim, module_];
