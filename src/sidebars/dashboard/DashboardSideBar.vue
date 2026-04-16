<template>
	<NcAppSidebar
		:name="t('softwarecatalog', 'Software Catalog')"
		:subname="t('softwarecatalog', 'Quick navigation')">
		<NcAppSidebarTab id="info-tab" :name="t('softwarecatalog', 'Information')" :order="1">
			<template #icon>
				<InformationOutline :size="20" />
			</template>
			<NcNoteCard type="info">
				<p>{{ t('softwarecatalog', 'Welcome to the Software Catalog dashboard. Use the main navigation to access different features.') }}</p>
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
