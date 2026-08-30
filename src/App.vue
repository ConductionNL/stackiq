<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->

<!--
 Stackiq app shell. Mounts CnAppRoot with the bundled manifest
 and the customComponents registry; provides the `objectSidebarState`
 channel so detail pages (CnDetailPage) can drive a single
 host-rendered CnObjectSidebar through the #sidebar slot.

 Global modals/dialogs (`<Modals />`, `<Dialogs />`) stay mounted at
 the app root so legacy custom components (OrganisatieIndexView,
 StackiqSettingsPage) can still trigger them through the
 navigationStore.modal channel.

 @spec openspec/changes/softwarecatalog-manifest-v1/tasks.md#task-4.3
-->
<template>
	<div class="stackiq-app-root">
		<CnAppRoot
			:aiCompanion="true"
			:manifest="manifest"
			:customComponents="customComponents"
			:registry="registry"
			:pageTypes="pageTypes"
			appId="stackiq"
			:translate="translateForApp"
			:permissions="permissions"
			:initialOrganisationUuid="activeOrganisationUuid"
			:initialOrganisation="activeOrganisation">
			<template #sidebar="{ pageSidebarComponent }">
				<CnObjectSidebar
					v-if="objectSidebarState.active"
					:title="objectSidebarState.title"
					:subtitle="objectSidebarState.subtitle"
					:objectType="objectSidebarState.objectType"
					:objectId="objectSidebarState.objectId"
					:register="objectSidebarState.register"
					:schema="objectSidebarState.schema"
					:hiddenTabs="objectSidebarState.hiddenTabs"
					:tabs="objectSidebarState.tabs"
					:open="objectSidebarState.open"
					@update:open="objectSidebarState.open = $event" />
				<!-- The manifest page's own sidebar (pages[].sidebarComponent). Passed in
				     as a slot prop because filling this slot suppresses CnAppRoot's
				     fallback, which is what hid the flow sidebar. -->
				<component :is="pageSidebarComponent" v-if="pageSidebarComponent" />
			</template>
			<!-- Suppress the default read-only CnTenantBadge — OrganisationSwitcher
			     below renders a combined active-organisation label + switcher
			     instead, self-contained (see design.md's "no slot-inject
			     dependency" decision), so there is exactly one on-screen
			     indicator. -->
			<template #tenant-badge />
			<template #header-actions>
				<OrganisationSwitcher
					v-if="organisations.length > 0"
					:organisations="organisations"
					:activeOrganisationUuid="activeOrganisationUuid"
					:isBeheerder="isBeheerder" />
			</template>
		</CnAppRoot>

		<!-- Legacy global modals + dialogs (keep until every consumer migrates to CnFormDialog / CnDeleteDialog). -->
		<Modals />
		<Dialogs />
	</div>
</template>

<script>
import { CnAppRoot, CnObjectSidebar } from '@conduction/nextcloud-vue'
import { translate as ncT } from '@nextcloud/l10n'
import { generateUrl } from '@nextcloud/router'
import { reactive } from 'vue'
import OrganisationSwitcher from './components/organisations/OrganisationSwitcher.vue'
import Dialogs from './dialogs/Dialogs.vue'
import Modals from './modals/Modals.vue'
import { setActiveOrganisationUuid } from './composables/orClient.js'
import { settingsStore } from './store/store.js'

export default {
	name: 'App',

	components: {
		CnAppRoot,
		CnObjectSidebar,
		Modals,
		Dialogs,
		OrganisationSwitcher,
	},

	/**
	 * @spec exclude Vue dependency-injection provider — framework plumbing
	 */
	provide() {
		return {
			// Channel for CnDetailPage → host-rendered CnObjectSidebar.
			// `reactive()` (Vue 3) makes the plain object reactive; injecting
			// the object itself — not a value read off it — is what keeps the
			// channel live for every injecting descendant.
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
			objectSidebarState: reactive({
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

			/**
			 * The authenticated user's organisations, and which one is
			 * currently active — fetched once at boot from `/api/me`
			 * (multi-org-membership). Empty/null until that fetch resolves;
			 * `OrganisationSwitcher` only renders once populated.
			 */
			organisations: [],
			activeOrganisationUuid: null,
			activeOrganisation: null,
			isBeheerder: false,
		}
	},

	computed: {
		/**
		 * @spec exclude pure passthrough getter of injected permissions — DI getter
		 */
		permissions() {
			return window.OC?.currentUser?.permissions ?? []
		},
	},

	/**
	 * @spec exclude Vue lifecycle hook — SPA shell bootstrap
	 */
	async created() {
		// Stackiq stores still need to come up so legacy custom
		// components (OrganisatieIndexView, StackiqSettingsPage)
		// keep working through the transition. CnAppRoot itself doesn't
		// depend on them — the openregister dependency check happens via
		// `manifest.dependencies` + `useAppStatus()`.
		try {
			await settingsStore.loadSettings()
		} catch (e) {
			// eslint-disable-next-line no-console
			console.warn(
				'[stackiq] settingsStore.loadSettings() failed; continuing with defaults',
				e,
			)
		}

		await this.loadOrganisations()
	},

	methods: {
		/**
		 * Fetch the authenticated user's organisations and active
		 * organisation from the already-shipped `/api/me` aggregate
		 * endpoint (multi-org-membership). Seeds `CnAppRoot`'s tenant
		 * context for first paint and the `orClient.js` write-header
		 * getter; a failure leaves the app in its pre-existing
		 * single-tenant behaviour (no switcher rendered, no header
		 * stamped) rather than blocking boot.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/specs/multi-org-membership/spec.md#requirement-the-organisation-switcher-must-list-only-the-authenticated-user-s-own-organisations-req-003
		 */
		async loadOrganisations() {
			try {
				const response = await fetch(generateUrl('/apps/stackiq/api/me'), {
					headers: { requesttoken: OC.requestToken },
				})
				if (!response.ok) return

				const data = await response.json()
				const orgs = data?.organisations?.all ?? []
				const active = data?.organisations?.active ?? null

				this.organisations = orgs
				this.activeOrganisation = active
				this.activeOrganisationUuid = active?.uuid ?? null
				this.isBeheerder = data?.isBeheerder === true
				setActiveOrganisationUuid(this.activeOrganisationUuid)
			} catch (e) {
				// eslint-disable-next-line no-console
				console.warn(
					'[stackiq] Failed to load organisations; continuing single-tenant',
					e,
				)
			}
		},

		/**
		 * Translate function passed down to CnAppRoot / CnAppNav /
		 * CnPageRenderer. Closes over the Nextcloud `translate` import so
		 * the lib never has to know our app id.
		 *
		 * @param {string} key Translation key.
		 * @return {string} Translated string (or the key on miss).
		 * @spec exclude i18n wrapper around @nextcloud/l10n translate
		 */
		translateForApp(key) {
			return ncT('stackiq', key)
		},
	},
}
</script>
