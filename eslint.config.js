const { defineConfig } = require('@eslint/config-helpers')

const js = require('@eslint/js')

const { FlatCompat } = require('@eslint/eslintrc')

// The `@nextcloud` v8 base is Vue-2 era: on its own it activates ZERO
// `vue/no-deprecated-*` rules, so Vue-2 idioms (`beforeDestroy`, `.sync`,
// `filters:`) survive a green lint. `conductionVue3Fixes` from
// @conduction/nextcloud-vue layers the Vue 3 rules on top. It is an ARRAY of
// three configs (shared parserOptions, a `.vue` parser layer, and the rule
// layer) and must be spread LAST so it wins over the Vue-2 base.
// It registers no plugins, which is why it layers cleanly.
//
// CJS: the extensionless subpath works because the package ships no `exports`
// map. From ESM this would need `/eslint/index.js`.
const { conductionVue3Fixes } = require('@conduction/nextcloud-vue/eslint')

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
					map: [['@', './src']],
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
			'import/no-unresolved': [
				'error',
				{ ignore: ['^@conduction/nextcloud-vue'] },
			],
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
	// Spread LAST so the Vue-3 rules win over the Vue-2 @nextcloud base.
	...conductionVue3Fixes,
	// `eslint-config-prettier` LAST OF ALL, and it has to be: it only turns rules
	// OFF — every stylistic rule prettier now owns (indent, quotes,
	// operator-linebreak, comma-dangle…). Anything spread after it would switch
	// some of them back on, and eslint and prettier would then demand opposite
	// things — the unfixable state this fleet already hit once with php-cs-fixer
	// and PHPCS.
	//
	// It disables no CORRECTNESS rule: the `vue/no-deprecated-*` family spread
	// just above is still present and still ON, because prettier has no opinion
	// about it. `indent` is now off HERE and enforced by prettier's
	// `useTabs: true` instead — the same tab, from the tool that also covers CSS
	// and SCSS, which @nextcloud/stylelint-config no longer does.
	require('eslint-config-prettier'),
])
