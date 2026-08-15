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

<style scoped>
.standard-tabs {
	width: 100%;
}

.tab-navigation {
	display: flex;
	border: 1px solid var(--color-border);
	border-bottom: none;
	margin-bottom: 0;
	background-color: var(--color-background-hover);
	border-radius: var(--border-radius-large) var(--border-radius-large) 0 0;
	padding: 0 8px;
}

.tab-button {
	flex: 1;
	padding: 12px 16px;
	background: none;
	border: none;
	color: var(--color-text-lighter);
	font-weight: 500;
	font-size: 14px;
	cursor: pointer;
	transition: all 0.2s ease;
	position: relative;
	min-height: 48px;
	display: flex;
	align-items: center;
	justify-content: center;
}

.tab-button:hover {
	color: var(--color-main-text);
	background-color: var(--color-background-hover);
}

.tab-button.active {
	color: var(--color-primary);
	background-color: var(--color-background);
	font-weight: 600;
	border: 1px solid var(--color-border);
	border-bottom: 2px solid var(--color-background);
	border-radius: var(--border-radius-large) var(--border-radius-large) 0 0;
}

.tab-button.active::after {
	content: '';
	position: absolute;
	bottom: -2px;
	left: 0;
	right: 0;
	height: 2px;
	background-color: var(--color-primary);
}

.tab-content {
	padding: 8px 0 0 0;
	border: none;
	border-top: none;
	border-radius: 0;
	background-color: transparent;
}

/* Responsive design */
@media (max-width: 768px) {
	.tab-navigation {
		flex-direction: column;
		border-bottom: none;
		border-radius: var(--border-radius-large) var(--border-radius-large) 0 0;
		padding: 8px;
	}

	.tab-button {
		border-bottom: none;
		border-radius: var(--border-radius);
		margin-bottom: 4px;
		min-height: 40px;
	}

	.tab-button.active {
		background-color: var(--color-primary);
		color: white;
		border-bottom-color: transparent;
	}

	.tab-button.active::after {
		display: none;
	}
	.tab-content {
		border-top: 1px solid var(--color-border);
	}
}

/* WCAG 2.3.3 — honour a reduced-motion preference for the tab hover/active
   transition. Scoped to the selector that declares motion; everything else
   keeps its appearance. */
@media (prefers-reduced-motion: reduce) {
	.tab-button {
		transition: none;
	}
}
</style>
