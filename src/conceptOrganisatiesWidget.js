import Vue from 'vue'
import { PiniaVuePlugin } from 'pinia'
import pinia from './pinia.js'
import ConceptOrganisatiesWidget from './views/widgets/ConceptOrganisatiesWidget.vue'

// Library CSS (CnDataTable + cn-cell utilities). This standalone dashboard
// bundle never runs main.js, so it must import the lib CSS itself — and the
// import must be explicit (webpack tree-shakes side-effect imports from
// aliased packages).
import '@conduction/nextcloud-vue/css/index.css'

Vue.use(PiniaVuePlugin)

OCA.Dashboard.register('softwarecatalog_concept_organisaties_widget', async (el, { widget }) => {
	Vue.mixin({ methods: { t, n } })
	const View = Vue.extend(ConceptOrganisatiesWidget)
	new View({
		pinia,
		propsData: { title: widget.title },
	}).$mount(el)
})
