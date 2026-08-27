<script setup>
// No imports needed for this simple component
</script>

<template>
	<NcAppSidebar name="Stackiq" subname="Quick navigation">
		<NcAppSidebarTab id="info-tab" name="Information" :order="1">
			<template #icon>
				<InformationOutline :size="20" />
			</template>
			<NcNoteCard type="info">
				<p>
					Welcome to the Stackiq dashboard. Use the main navigation to
					access different features.
				</p>
			</NcNoteCard>
		</NcAppSidebarTab>
	</NcAppSidebar>
</template>

<script>
import { NcAppSidebar, NcAppSidebarTab, NcNoteCard } from '@nextcloud/vue'
import InformationOutline from 'vue-material-design-icons/InformationOutline.vue'

export default {
	name: 'DashboardSideBar',
	components: {
		NcAppSidebar,
		NcAppSidebarTab,
		NcNoteCard,
		// Icons
		InformationOutline,
	},
}
</script>
