const path = require('path')
const fs = require('fs')
const webpack = require('webpack')
const webpackConfig = require('@nextcloud/webpack-vue-config')
const { VueLoaderPlugin } = require('vue-loader')

const buildMode = process.env.NODE_ENV
const isDev = buildMode === 'development'
webpackConfig.devtool = isDev ? 'cheap-source-map' : 'source-map'

webpackConfig.stats = {
	colors: true,
	modules: false,
}

const appId = 'softwarecatalog'
webpackConfig.entry = {
	main: {
		import: path.join(__dirname, 'src', 'main.js'),
		filename: appId + '-main.js',
	},
	adminSettings: {
		import: path.join(__dirname, 'src', 'settings.js'),
		filename: appId + '-settings.js',
	},
	conceptOrganisatiesWidget: {
		import: path.join(__dirname, 'src', 'conceptOrganisatiesWidget.js'),
		filename: appId + '-conceptOrganisatiesWidget.js',
	},
}

// Nextcloud's shared webpack config hardcodes `publicPath: '/apps/<app>/js/'`,
// but this app is installed under `custom_apps/`, which is served from
// `/custom_apps/<app>/js/`. The wrong path does NOT 404 — Nextcloud answers
// 200 with `text/html`, so lazy chunks fail as a MIME refusal / ChunkLoadError
// rather than a missing file. Vue 2 never surfaced this because it emitted no
// async chunks; the Vue 3 dependency set splits @nextcloud/dialogs@7,
// @nextcloud/files, @nextcloud/paths and @mdi/js into dozens.
webpackConfig.output = {
	...(webpackConfig.output || {}),
	publicPath: 'auto',
}

// Use local source when available (monorepo dev), otherwise fall back to npm package.
//
// ⚠️ USE_LOCAL_LIB is opt-IN (ADR-090). Building against a developer's working
// checkout is the wrong default for a build that can ship, and the old opt-OUT
// default silently compiled Vue 2 library sources into this Vue 3 app while
// still producing a green build.
//
// The version test looks for the `-vue3.` marker, NOT the semver major. The
// previous check required `startsWith('2.')` on the theory that the Vue 2 line
// was 1.x — true when it was written, false since. Today BOTH lines are major 2:
//
//     Vue 2 line   2.0.5
//     Vue 3 line   2.2.0-vue3.16
//
// so a major-based test passes the Vue 2 library straight through, which is
// exactly what it existed to prevent. Only the `-vue3.` tag distinguishes them.
const localLib = path.resolve(__dirname, '../nextcloud-vue/src')
const localLibPkg = path.resolve(__dirname, '../nextcloud-vue/package.json')
let useLocalLib = process.env.USE_LOCAL_LIB === 'true' && fs.existsSync(localLib)
if (useLocalLib && fs.existsSync(localLibPkg)) {
	const localVersion =
		JSON.parse(fs.readFileSync(localLibPkg, 'utf8')).version || ''
	if (!/-vue3\./.test(localVersion)) {
		useLocalLib = false
		// eslint-disable-next-line no-console
		console.warn(
			`[softwarecatalog] IGNORING sibling @conduction/nextcloud-vue@${localVersion} — `
				+ 'that is not a -vue3 build and this app is Vue 3. Building against the npm dist.',
		)
	}
}

webpackConfig.resolve = {
	// `.mjs` matters now: @nextcloud/vue@9, @nextcloud/dialogs@7 and
	// vue-router@4/5 all ship .mjs entry files, and overriding `extensions`
	// drops the framework default that would otherwise cover them.
	extensions: ['.ts', '.tsx', '.vue', '.js', '.mjs', '.json'],
	alias: {
		'@': path.resolve(__dirname, 'src'),
		...(useLocalLib ? { '@conduction/nextcloud-vue': localLib } : {}),
		// Deduplicate shared packages so the aliased library source uses
		// the same instances as the app (prevents dual-Pinia / dual-Vue bugs).
		vue$: path.resolve(__dirname, 'node_modules/vue'),
		pinia$: path.resolve(__dirname, 'node_modules/pinia'),
		// ⚠️ @nextcloud/vue@9 ships an `exports` map with NO `main` and NO
		// `module`. Webpack applies an exports map to a PACKAGE REQUEST, never
		// to an already-absolutised path, so the Vue-2-era alias to the package
		// DIRECTORY resolves to nothing and every import fails with
		// "Can't resolve '@nextcloud/vue'". Alias to the absolute FILE.
		'@nextcloud/vue$': path.resolve(
			__dirname,
			'node_modules/@nextcloud/vue/dist/index.mjs',
		),
		// @nextcloud/vue@9 hard-depends on vue-router ^5.1.0 while this app is
		// on vue-router 4, so npm installs a SECOND nested copy under
		// node_modules/@nextcloud/vue/node_modules/vue-router. Two router
		// modules mean two injection keys: nc-vue's own RouterLink would look
		// up a router this app never provided and navigation dies with no
		// console error. Force every `vue-router` request onto one file.
		'vue-router$': path.resolve(
			__dirname,
			'node_modules/vue-router/dist/vue-router.mjs',
		),
	},
}

webpackConfig.module = {
	rules: [
		{
			test: /\.vue$/,
			loader: 'vue-loader',
		},
		{
			test: /\.css$/,
			use: ['style-loader', 'css-loader'],
		},
		{
			// SCSS used by aliased @conduction/nextcloud-vue components (e.g. CnCard, CnDataTable)
			test: /\.scss$/,
			use: ['style-loader', 'css-loader', 'sass-loader'],
		},
	],
}

webpackConfig.plugins = [
	new VueLoaderPlugin(),
	new webpack.DefinePlugin({ appName: JSON.stringify(appId) }),
	new webpack.DefinePlugin({
		appVersion: JSON.stringify(process.env.npm_package_version),
	}),
]

// Force @nextcloud/dialogs to resolve from this app's node_modules, preventing
// a nested copy from leaking in. Register the exact-match style.css alias BEFORE
// the bare package alias below: enhanced-resolve applies the first matching
// entry, so '@nextcloud/dialogs/style.css' (imported by nextcloud-vue's
// useAppInstaller) must be mapped explicitly.
// ⚠️ dialogs v7 is the same exports-map-only shape as @nextcloud/vue@9 — the
// bare alias must point at the absolute ENTRY FILE, not the package directory.
webpackConfig.resolve.alias['@nextcloud/dialogs/style.css$'] = path.resolve(
	__dirname,
	'node_modules/@nextcloud/dialogs/dist/style.css',
)
webpackConfig.resolve.alias['@nextcloud/dialogs$'] = path.resolve(
	__dirname,
	'node_modules/@nextcloud/dialogs/dist/index.mjs',
)

// @nextcloud/dialogs drags in a FilePicker chunk that imports node's `path`, and
// webpack 5 no longer auto-polyfills node core modules. `path-browserify` is a
// declared dependency rather than `false`, because @nextcloud/paths (pulled in by
// the Vue 3 dependency set) genuinely calls into it at runtime and an empty
// module would only defer the failure to a route the user actually opens.
webpackConfig.resolve.fallback = {
	...(webpackConfig.resolve.fallback || {}),
	path: require.resolve('path-browserify'),
}

// Bypass @nextcloud/axios's `exports` field which only declares the `import`
// condition. @nextcloud/vue's CJS bundle still uses require('@nextcloud/axios')
// and webpack 5's CommonJS resolver fails the exports check with:
//   "." is not exported under the conditions ["require","module","webpack",...]
// Aliasing the bare specifier directly at the dist entry sidesteps the
// exports field gate. Use the $-suffixed exact-match form so subpath imports
// (e.g. @nextcloud/axios/dist/foo) keep their normal resolution.
webpackConfig.resolve.alias['@nextcloud/axios$'] = path.resolve(
	__dirname,
	'node_modules/@nextcloud/axios/dist/index.js',
)

// Shared chunks so widgets reuse Vue / Pinia / @nextcloud/vue + @conduction/nextcloud-vue
// instead of bundling them per entry. Slashes \\/ used to match both posix and win paths.
webpackConfig.optimization = {
	...(webpackConfig.optimization || {}),
	runtimeChunk: { name: 'runtime' },
	splitChunks: {
		...(webpackConfig.optimization?.splitChunks || {}),
		chunks: 'all',
		cacheGroups: {
			default: false,
			defaultVendors: false,
			ncVue: {
				name: appId + '-shared-nc-vue',
				test: /[\\/]node_modules[\\/](@nextcloud[\\/]vue|@conduction[\\/]nextcloud-vue)[\\/]|[\\/]nextcloud-vue[\\/]src[\\/]/,
				priority: 30,
				reuseExistingChunk: true,
				enforce: true,
				filename: appId + '-shared-nc-vue.js',
			},
			vendor: {
				name: appId + '-shared-vendor',
				test: /[\\/]node_modules[\\/](vue|vue-router|pinia|vue-material-design-icons|@vueuse|core-js)[\\/]/,
				priority: 20,
				reuseExistingChunk: true,
				enforce: true,
				filename: appId + '-shared-vendor.js',
			},
		},
	},
}

module.exports = webpackConfig
