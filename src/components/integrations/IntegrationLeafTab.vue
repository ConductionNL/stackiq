<!--
  - SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
  - SPDX-License-Identifier: EUPL-1.2
  -
  - One integration leaf (contacts, calendar, deck, bookmarks, ...) as a
  - declared sidebar tab. A detail page that lists its own sidebar `tabs`
  - turns off CnObjectSidebar's registry mode, which is what renders one tab
  - per leaf, so the leaves its schema declares in `linkedTypes` would not
  - show. This widget renders the leaf the same way registry mode does: the
  - provider's own tab, or its mount host for a `renderMode: 'mount'` leaf,
  - with the generic linked-items tab when the provider is not registered.
  -
  - Used as `{ "type": "IntegrationLeafTab", "props": { "integrationId": "calendar" } }`
  - in a sidebar tab's `widgets` (adopt-integration-leaves task 3.1).
  -
  - @spec openspec/changes/adopt-integration-leaves
  -->

<template>
	<CnLeafMountHost
		v-if="mountMode"
		:provider="provider"
		:active="true"
		:mountProps="mountProps" />
	<component
		:is="tabComponent"
		v-else-if="tabComponent"
		v-bind="tabProps" />
	<CnIntegrationTab
		v-else
		:integrationId="integrationId"
		v-bind="tabProps" />
</template>

<script>
import { CnIntegrationTab, CnLeafMountHost, useIntegrationRegistry } from '@conduction/nextcloud-vue'

export default {
	name: 'IntegrationLeafTab',

	components: {
		CnIntegrationTab,
		CnLeafMountHost,
	},

	props: {
		/** The leaf's integration id, e.g. `calendar`. */
		integrationId: {
			type: String,
			required: true,
		},

		/** The object the tab belongs to (from CnObjectSidebar). */
		objectId: {
			type: String,
			default: '',
		},

		/** The object's type (from CnObjectSidebar). */
		objectType: {
			type: String,
			default: '',
		},

		/** The object's register (from CnObjectSidebar). */
		register: {
			type: String,
			default: '',
		},

		/** The object's schema slug (from CnObjectSidebar). */
		schema: {
			type: [String, Object],
			default: '',
		},

		/** The OpenRegister API base (from CnObjectSidebar). */
		apiBase: {
			type: String,
			default: '/apps/openregister/api',
		},
	},

	/**
	 * @spec exclude registry wiring in setup() — composable plumbing
	 */
	setup() {
		return { leaves: useIntegrationRegistry() }
	},

	computed: {
		/**
		 * The registered provider of the leaf, or null.
		 *
		 * @return {?object} The provider.
		 * @spec openspec/changes/adopt-integration-leaves
		 */
		provider() {
			const list = this.leaves.integrations.value || []
			return list.find((entry) => entry && entry.id === this.integrationId) || null
		},

		/**
		 * Whether the provider mounts its own framework into a host element.
		 *
		 * @return {boolean} Whether it does.
		 */
		mountMode() {
			return Boolean(this.provider) && this.provider.renderMode === 'mount'
		},

		/**
		 * The provider's tab component, or null when it has none.
		 *
		 * @return {?object} The component.
		 * @spec openspec/changes/adopt-integration-leaves
		 */
		tabComponent() {
			return this.provider ? this.leaves.resolveTab(this.integrationId) : null
		},

		/**
		 * The props every tab gets, as CnObjectSidebar passes them in registry mode.
		 *
		 * @return {object} The props.
		 */
		tabProps() {
			return {
				objectId: this.objectId,
				objectType: this.objectType,
				register: this.register,
				schema: typeof this.schema === 'string' ? this.schema : (this.schema?.slug || ''),
				apiBase: this.apiBase,
			}
		},

		/**
		 * The props of a mount-mode leaf, as CnObjectSidebar passes them.
		 *
		 * @return {object} The props.
		 */
		mountProps() {
			return {
				...this.tabProps,
				surface: 'single-entity',
				integrationContext: {
					register: this.register,
					schema: this.tabProps.schema,
					objectId: this.objectId,
				},
			}
		},
	},
}
</script>
