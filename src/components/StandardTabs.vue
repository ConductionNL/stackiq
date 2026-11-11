<!--
 - @copyright Copyright (c) 2023 Ruben Linde <info@conduction.nl>
 - @license EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 -
 - Licensed under the EUPL, Version 1.2 or – as soon they will be approved by
 - the European Commission – subsequent versions of the EUPL (the "Licence");
 - You may not use this work except in compliance with the Licence.
 - You may obtain a copy of the Licence at:
 -
 - https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 -
 - Unless required by applicable law or agreed to in writing, software
 - distributed under the Licence is distributed on an "AS IS" basis,
 - WITHOUT WARRANTIES OR CONDITIONS OF ANY KIND, either express or implied.
 - See the Licence for the specific language governing permissions and
 - limitations under the Licence.
 -->

<template>
	<div class="standard-tabs">
		<!-- Tab Navigation -->
		<div class="tab-navigation">
			<button
				v-for="tab in tabs"
				:key="tab.key"
				class="tab-button"
				:class="{ active: activeTab === tab.key }"
				@click="$emit('update:active-tab', tab.key)">
				{{ tab.title }}
			</button>
		</div>

		<!-- Tab Content -->
		<div class="tab-content">
			<slot />
		</div>
	</div>
</template>

<script>
/**
 * Standard Tabs Component
 *
 * A reusable tab component with consistent styling across all sections
 *
 * @author Ruben Linde <info@conduction.nl>
 * @copyright 2023 Conduction B.V.
 * @license EUPL-1.2
 * @version 1.0.0
 */

export default {
	name: 'StandardTabs',

	props: {
		/**
		 * Array of tab objects with key and title
		 *
		 * @type {Array<{key: string, title: string}>}
		 */
		tabs: {
			type: Array,
			required: true,
		},

		/**
		 * Currently active tab key
		 *
		 * @type {string}
		 */
		activeTab: {
			type: String,
			required: true,
		},
	},

	emits: ['update:active-tab'],
}
</script>

<style scoped src="./standardTabs.css"></style>

<style scoped>
/* WCAG 2.3.3 — honour a reduced-motion preference for the tab hover/active
   transition. Scoped to the selector that declares motion; everything else
   keeps its appearance. */
@media (prefers-reduced-motion: reduce) {
	.tab-button {
		transition: none;
	}
}
</style>
