const {
	defineConfig,
} = require('@eslint/config-helpers')

const js = require('@eslint/js')

const {
	FlatCompat,
} = require('@eslint/eslintrc')

const compat = new FlatCompat({
	baseDirectory: __dirname,
	recommendedConfig: js.configs.recommended,
	allConfig: js.configs.all,
})

module.exports = defineConfig([
	...compat.extends('@nextcloud'),

	{
		languageOptions: {
			// set latest version of ECMAScript
			// default (non explicitly set) causes errors when importing
			ecmaVersion: 'latest',
			sourceType: 'module',

			// also pass through to parsers that still read parserOptions
			parserOptions: {
				ecmaVersion: 'latest',
				sourceType: 'module',
			},
		},

		settings: {
			'import/resolver': {
				alias: {
					map: [
						['@', './src'],
					],
					extensions: ['.js', '.ts', '.vue', '.json'],
				},
			},

			// import/parsers is used to parse the files
			// espree is used to parse the JavaScript files
			// @typescript-eslint/parser is used to parse the TypeScript files
			// vue-eslint-parser is used to parse the Vue files
			'import/parsers': {
				espree: ['.js', '.mjs', '.cjs', '.jsx'],
				'@typescript-eslint/parser': ['.ts', '.tsx', '.mts', '.cts'],
				'vue-eslint-parser': ['.vue'],
			},
		},

		rules: {
			'jsdoc/require-jsdoc': 'off',
			// Allow @spec tag used for OpenSpec traceability links
			'jsdoc/check-tag-names': ['warn', { definedTags: ['spec'] }],
			'vue/first-attribute-linebreak': 'off',
			'vue/enforce-style-attribute': ['error', { allow: ['scoped'] }],
			'@typescript-eslint/no-explicit-any': 'off',
			'n/no-missing-import': 'off',
			'n/no-missing-require': 'off',
			'n/no-extraneous-require': 'off',
			'n/no-process-exit': 'off',
			'n/shebang': 'off',
			'no-console': 'off',
			'import/no-unresolved': ['error', { ignore: ['^@conduction/nextcloud-vue'] }],
			// `import/named` cannot statically resolve barrel re-exports through
			// the npm package's frozen ESM module records (CnAppRoot,
			// CnPageRenderer, defaultPageTypes are re-exported from internal
			// modules). Disabled here; webpack + runtime catches real issues.
			'import/named': 'off',
			// Library re-exports CnAppRoot/CnPageRenderer/etc as part of v1.x —
			// the static analyser can't introspect frozen/aliased exports cleanly.
			// CI and runtime catch real issues.
			'import/namespace': 'off',
			'import/default': 'off',
			'import/no-named-as-default': 'off',
			'import/no-named-as-default-member': 'off',
		},
	},
	{
		// validate-manifest.js is a Node script — relax browser-oriented rules.
		files: ['tests/validate-manifest.js'],
		rules: {
			'import/named': 'off',
		},
	},
])
