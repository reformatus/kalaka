// Needs the devDependency `@eslint/js` (see plugins/kalaka/tests/js/transform.cjs).
// ESLint's recommended rules only (engineering.md, "Code style"): no formatting rules.
const js = require('@eslint/js');

const browser = {
	console: 'readonly',
	clearTimeout: 'readonly',
	crypto: 'readonly',
	document: 'readonly',
	Element: 'readonly',
	Event: 'readonly',
	HTMLElement: 'readonly',
	KeyboardEvent: 'readonly',
	MouseEvent: 'readonly',
	Node: 'readonly',
	fetch: 'readonly',
	performance: 'readonly',
	PopStateEvent: 'readonly',
	URL: 'readonly',
	setTimeout: 'readonly',
	window: 'readonly',
};
const jest = {
	afterEach: 'readonly',
	beforeEach: 'readonly',
	describe: 'readonly',
	expect: 'readonly',
	it: 'readonly',
	jest: 'readonly',
	test: 'readonly',
};

module.exports = [
	{ ignores: ['**/build/**', '**/node_modules/**', '**/vendor/**'] },
	js.configs.recommended,
	{ languageOptions: { globals: browser } },
	{
		// The admin app (Admin/app) is React with JSX, as wp-scripts builds it.
		files: ['plugins/kalaka/src/Admin/app/**/*.js'],
		languageOptions: {
			parserOptions: { ecmaFeatures: { jsx: true } },
			globals: { ...browser, navigator: 'readonly', URL: 'readonly', URLSearchParams: 'readonly' },
		},
	},
	{
		files: ['plugins/*/tests/js/**/*.js'],
		languageOptions: { globals: { ...browser, ...jest, require: 'readonly' } },
	},
	{
		files: ['**/*.cjs', 'eslint.config.js'],
		languageOptions: {
			sourceType: 'commonjs',
			globals: { require: 'readonly', module: 'writable', __dirname: 'readonly' },
		},
	},
];
