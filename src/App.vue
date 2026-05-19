<!-- SPDX-License-Identifier: AGPL-3.0-or-later -->
<!-- Copyright (C) 2026 Conduction B.V. -->

<!--
 SoftwareCatalog app shell. Mounts CnAppRoot with the bundled manifest
 and the customComponents registry; provides the `objectSidebarState`
 channel so detail pages (CnDetailPage) can drive a single
 host-rendered CnObjectSidebar through the #sidebar slot.

 Global modals/dialogs (`<Modals />`, `<Dialogs />`) stay mounted at
 the app root so legacy custom components (OrganisatieIndexView,
 SoftwareCatalogSettingsPage) can still trigger them through the
 navigationStore.modal channel.

 @spec openspec/changes/softwarecatalog-manifest-v1/tasks.md#task-4.3
-->
<template>
	<div class="softwarecatalog-app-root">
		<CnAppRoot
			:manifest="manifest"
			:custom-components="customComponents"
			:page-types="pageTypes"
			app-id="softwarecatalog"
			:translate="translateForApp"
			:permissions="permissions">
			<template #sidebar>
				<CnObjectSidebar
					v-if="objectSidebarState.active"
					:title="objectSidebarState.title"
					:subtitle="objectSidebarState.subtitle"
					:object-type="objectSidebarState.objectType"
					:object-id="objectSidebarState.objectId"
					:register="objectSidebarState.register"
					:schema="objectSidebarState.schema"
					:hidden-tabs="objectSidebarState.hiddenTabs"
					:tabs="objectSidebarState.tabs"
					:open="objectSidebarState.open"
					@update:open="objectSidebarState.open = $event" />
			</template>
		</CnAppRoot>

		<!-- Legacy global modals + dialogs (keep until every consumer migrates to CnFormDialog / CnDeleteDialog). -->
		<Modals />
		<Dialogs />
	</div>
</template>

<script>
import Vue from 'vue'
import { translate as ncT } from '@nextcloud/l10n'
import { CnAppRoot, CnObjectSidebar } from '@conduction/nextcloud-vue'
import Modals from './modals/Modals.vue'
import Dialogs from './dialogs/Dialogs.vue'
import { settingsStore } from './store/store.js'

export default {
	name: 'App',

	components: {
		CnAppRoot,
		CnObjectSidebar,
		Modals,
		Dialogs,
	},

	provide() {
		return {
			// Channel for CnDetailPage → host-rendered CnObjectSidebar.
			// Vue.observable makes the plain object reactive for Vue 2.
			objectSidebarState: this.objectSidebarState,
		}
	},

	props: {
		/**
		 * Manifest object — passed from main.js bootstrap. CnAppRoot reads
		 * `manifest.dependencies` for the dependency-check phase and
		 * `manifest.menu` for the default CnAppNav.
		 */
		manifest: {
			type: Object,
			required: true,
		},
		/**
		 * Registry of consumer-injected components used by:
		 *   - `type: "custom"` pages (`page.component`)
		 *   - `headerComponent` / `actionsComponent` slot overrides
		 *   - `pages[].config.sidebar.tabs[].component` (detail tab tabs)
		 *   - `pages[].config.sections[].component` (settings rich sections)
		 */
		customComponents: {
			type: Object,
			default: () => ({}),
		},
		/**
		 * 5-kind component registry (v2 manifest pattern per hydra ADR-036).
		 * Each entry: { kind, component, ...kindMetadata }. Replaces
		 * customComponents for v2 manifests; both coexist during the
		 * transition window.
		 */
		registry: {
			type: Object,
			default: () => ({}),
		},
		/**
		 * Page-type registry — `{ index, detail, dashboard, settings, ... }`.
		 * Wired through to descendant `CnPageRenderer` instances via
		 * provide/inject.
		 */
		pageTypes: {
			type: Object,
			default: null,
		},
	},

	data() {
		return {
			objectSidebarState: Vue.observable({
				active: false,
				open: true,
				objectType: '',
				objectId: '',
				title: '',
				subtitle: '',
				register: '',
				schema: '',
				hiddenTabs: [],
				tabs: undefined,
			}),
		}
	},

	computed: {
		permissions() {
			return window.OC?.currentUser?.permissions ?? []
		},
	},

	async created() {
		// SoftwareCatalog stores still need to come up so legacy custom
		// components (OrganisatieIndexView, SoftwareCatalogSettingsPage)
		// keep working through the transition. CnAppRoot itself doesn't
		// depend on them — the openregister dependency check happens via
		// `manifest.dependencies` + `useAppStatus()`.
		try {
			await settingsStore.loadSettings()
		} catch (e) {
			// eslint-disable-next-line no-console
			console.warn('[softwarecatalog] settingsStore.loadSettings() failed; continuing with defaults', e)
		}
	},

	methods: {
		/**
		 * Translate function passed down to CnAppRoot / CnAppNav /
		 * CnPageRenderer. Closes over the Nextcloud `translate` import so
		 * the lib never has to know our app id.
		 *
		 * @param {string} key Translation key.
		 * @return {string} Translated string (or the key on miss).
		 */
		translateForApp(key) {
			return ncT('softwarecatalog', key)
		},
	},
}
</script>
