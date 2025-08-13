<script setup>
import { navigationStore } from '../store/store.js'
</script>

<template>
	<!-- Placeholder div for all of the views-->
	<NcAppContent>
		<template #default>
			<Dashboard v-if="navigationStore.selected === 'dashboard'" />
			<OrganisatieIndex v-if="navigationStore.selected === 'organisaties'" />
			<ContactpersoonIndex v-if="navigationStore.selected === 'contactpersonen'" />
			<VoorzieningIndex v-if="navigationStore.selected === 'voorzieningen'" />
			<ContractIndex v-if="navigationStore.selected === 'contracten'" />
		</template>
	</NcAppContent>
</template>

<script>
import { NcAppContent } from '@nextcloud/vue'
import Dashboard from './dashboard/DashboardIndex.vue'
import OrganisatieIndex from './organisaties/OrganisatieIndex.vue'
import ContactpersoonIndex from './contactpersonen/ContactpersoonIndex.vue'
import VoorzieningIndex from './voorzieningen/VoorzieningIndex.vue'
import ContractIndex from './contracten/ContractIndex.vue'

export default {
	name: 'Views',
	components: {
		Dashboard,
		OrganisatieIndex,
		ContactpersoonIndex,
		VoorzieningIndex,
		ContractIndex,
		NcAppContent,
	},
}
</script>
