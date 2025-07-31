<script setup>
import { navigationStore } from '../store/store.js'
</script>

<template>
	<!-- Placeholder div for all of the views-->
	<NcAppContent>
		<template #default>
			<Dashboard v-if="navigationStore.selected === 'dashboard'" />
			<OrganisatieIndex v-if="navigationStore.selected === 'organisaties'" />
		</template>
	</NcAppContent>
</template>

<script>
import { NcAppContent } from '@nextcloud/vue'
import Dashboard from './dashboard/DashboardIndex.vue'
import OrganisatieIndex from './organisaties/OrganisatieIndex.vue'

export default {
	name: 'Views',
	components: {
		Dashboard,
		OrganisatieIndex,
		NcAppContent,
	},
}
</script>
