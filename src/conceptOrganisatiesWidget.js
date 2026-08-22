import { translatePlural as n, translate as t } from '@nextcloud/l10n'
import { createApp, h } from 'vue'
import ConceptOrganisatiesWidget from './views/widgets/ConceptOrganisatiesWidget.vue'
import pinia from './pinia.js'

// Library CSS (CnDataTable + cn-cell utilities). This standalone dashboard
// bundle never runs main.js, so it must import the lib CSS itself — and the
// import must be explicit (webpack tree-shakes side-effect imports from
// aliased packages).
import '@conduction/nextcloud-vue/css/index.css'

OCA.Dashboard.register(
	'stackiq_concept_organisaties_widget',
	async (el, { widget }) => {
		const app = createApp({
			render: () => h(ConceptOrganisatiesWidget, { title: widget.title }),
		})

		// Previously `Vue.mixin({ methods: { t, n } })` referenced bare `t`/`n`,
		// which resolved to Nextcloud's globals rather than to the l10n module.
		// Import them explicitly so the widget bundle does not depend on globals.
		app.mixin({ methods: { t, n } })
		app.use(pinia)

		app.mount(el)
	},
)
