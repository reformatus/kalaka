/**
 * Jest with its test and lint tooling (the devDependencies of the root package.json and the
 * `jest` key there; `wp-scripts test-unit-js` is Vitest from `@wordpress/scripts` 36).
 *
 * The Jest transform: Babel with WordPress's preset, after removing the one top-level `await` of
 * `@wordpress/interactivity` (its development-only import of `preact/debug`), which CommonJS cannot run.
 */
const { createTransformer } = require('babel-jest');

const babel = createTransformer({ presets: ['@wordpress/babel-preset-default'] });

module.exports = {
	...babel,
	process(source, path, options) {
		const code = path.includes('/@wordpress/interactivity/')
			? source.replace(/if \(globalThis\.SCRIPT_DEBUG\) \{\s*await import\("preact\/debug"\);\s*\}/, '')
			: source;
		return babel.process(code, path, options);
	},
};
