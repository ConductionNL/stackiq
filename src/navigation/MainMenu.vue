<script setup>
import { navigationStore } from '../store/store.js'
</script>

<template>
	<NcAppNavigation>
		<NcAppNavigationList>
			<NcAppNavigationItem :active="navigationStore.selected === 'organisaties'" name="Organisaties" @click="navigationStore.setSelected('organisaties')">
				<template #icon>
					<AccountGroup :size="20" />
				</template>
			</NcAppNavigationItem>
			<NcAppNavigationItem :active="navigationStore.selected === 'contactpersonen'" name="Contactpersonen" @click="navigationStore.setSelected('contactpersonen')">
				<template #icon>
					<AccountMultiple :size="20" />
				</template>
			</NcAppNavigationItem>
			<NcAppNavigationItem :active="navigationStore.selected === 'voorzieningen'" name="Voorzieningen" @click="navigationStore.setSelected('voorzieningen')">
				<template #icon>
					<ApplicationCog :size="20" />
				</template>
			</NcAppNavigationItem>
			<NcAppNavigationItem :active="navigationStore.selected === 'contracten'" name="Contracten" @click="navigationStore.setSelected('contracten')">
				<template #icon>
					<FileDocumentEdit :size="20" />
				</template>
			</NcAppNavigationItem>
		</NcAppNavigationList>
	</NcAppNavigation>
</template>
<script>

import {
	NcAppNavigation,
	NcAppNavigationList,
	NcAppNavigationItem,
} from '@nextcloud/vue'

// Icons
import AccountGroup from 'vue-material-design-icons/AccountGroup.vue'
import AccountMultiple from 'vue-material-design-icons/AccountMultiple.vue'
import ApplicationCog from 'vue-material-design-icons/ApplicationCog.vue'
import FileDocumentEdit from 'vue-material-design-icons/FileDocumentEdit.vue'

export default {
	name: 'MainMenu',
	components: {
		// components
		NcAppNavigation,
		NcAppNavigationList,
		NcAppNavigationItem,
		// icons
		AccountGroup,
		AccountMultiple,
		ApplicationCog,
		FileDocumentEdit,
	},
	methods: {
		openLink(url, type = '') {
			window.open(url, type)
		},
	},
}
</script>
