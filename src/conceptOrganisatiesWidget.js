import Vue from 'vue'
import { PiniaVuePlugin } from 'pinia'
import pinia from './pinia.js'
import ConceptOrganisatiesWidget from './views/widgets/ConceptOrganisatiesWidget.vue'

Vue.use(PiniaVuePlugin)

OCA.Dashboard.register('softwarecatalog_concept_organisaties_widget', async (el, { widget }) => {
	Vue.mixin({ methods: { t, n } })
	const View = Vue.extend(ConceptOrganisatiesWidget)
	new View({
		pinia,
		propsData: { title: widget.title },
	}).$mount(el)
})
