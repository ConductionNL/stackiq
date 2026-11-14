<!--
  - SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
  - SPDX-License-Identifier: EUPL-1.2
  -
  - The Features and roadmap page with its second tab, Extensions: the store
  - list the Store page showed, so the app has one entry for what it can do
  - and what can be added to it.
  -
  - @spec openspec/changes/features-roadmap-page/specs/features-roadmap/spec.md#requirement-the-uitbreidingen-tab-lists-the-extensions-from-the-store-req-frm-002
-->
<template>
	<div class="sk-features-roadmap-tabs">
		<div class="sk-features-roadmap-tabs__bar" role="tablist">
			<button
				v-for="tab in tabs"
				:id="`sk-frm-tab-${tab.id}`"
				:key="tab.id"
				type="button"
				role="tab"
				class="sk-features-roadmap-tabs__tab"
				:class="{ 'sk-features-roadmap-tabs__tab--active': active === tab.id }"
				:aria-selected="active === tab.id ? 'true' : 'false'"
				:aria-controls="`sk-frm-panel-${tab.id}`"
				:data-testid="`frm-tab-${tab.id}`"
				@click="select(tab.id)">
				{{ tab.label }}
				<span
					v-if="tab.count !== null"
					class="sk-features-roadmap-tabs__count"
					data-testid="frm-tab-count">{{ tab.count }}</span>
			</button>
		</div>

		<div
			v-show="active === 'features'"
			id="sk-frm-panel-features"
			role="tabpanel"
			aria-labelledby="sk-frm-tab-features"
			data-testid="frm-panel-features">
			<CnFeaturesAndRoadmapPage :documentationUrl="documentationUrl" />
		</div>

		<div
			v-show="active === 'extensions'"
			id="sk-frm-panel-extensions"
			role="tabpanel"
			aria-labelledby="sk-frm-tab-extensions"
			data-testid="frm-panel-extensions">
			<CnStorePage
				ref="store"
				:app="store.app || 'stackiq'"
				:title="store.title || ''"
				:description="store.description || ''"
				:canInstall="canInstall" />
		</div>
	</div>
</template>

<script>
import { CnFeaturesAndRoadmapPage, CnStorePage } from '@conduction/nextcloud-vue'
import { getCurrentUser } from '@nextcloud/auth'
import { translate as t } from '@nextcloud/l10n'

/**
 * The tab ids, in display order.
 */
export const TAB_IDS = ['features', 'extensions']

/**
 * The tab a route query asks for, falling back to the features tab.
 *
 * @param {string|undefined|null} requested The `tab` query value.
 * @return {string} A known tab id.
 */
export function tabFromQuery(requested) {
	return TAB_IDS.includes(requested) ? requested : 'features'
}

/**
 * The number of extensions the store list shows: remote cards plus the
 * built-in items it falls back to. Null until the store has rendered.
 *
 * @param {object|null|undefined} store The CnStorePage instance.
 * @return {number|null} The count.
 */
export function storeItemCount(store) {
	if (!store || !Array.isArray(store.cards)) {
		return null
	}

	const builtIn = Array.isArray(store.visibleBuiltIn) ? store.visibleBuiltIn.length : 0
	return store.cards.length + builtIn
}

export default {
	name: 'FeaturesRoadmapTabs',

	components: { CnFeaturesAndRoadmapPage, CnStorePage },

	props: {
		/**
		 * Where the roadmap links to the documentation.
		 */
		documentationUrl: {
			type: String,
			default: '',
		},

		/**
		 * The store list's app id, title and lead paragraph.
		 */
		store: {
			type: Object,
			default: () => ({}),
		},

		/**
		 * Set on the old Store route: send the user to this tab of the
		 * FeaturesRoadmap page instead of rendering a second copy.
		 */
		redirectToTab: {
			type: String,
			default: '',
		},
	},

	data() {
		return {
			active: tabFromQuery(this.$route?.query?.tab),
			extensionCount: null,
		}
	},

	computed: {
		/**
		 * The tabs with their labels and counts.
		 *
		 * @return {Array<{id: string, label: string, count: number|null}>} The tabs.
		 */
		tabs() {
			return [
				{ id: 'features', label: t('stackiq', 'Features and roadmap'), count: null },
				{ id: 'extensions', label: t('stackiq', 'Extensions'), count: this.extensionCount },
			]
		},

		/**
		 * Install is for administrators only; everyone else sees the list.
		 *
		 * @return {boolean} Whether the Install action is shown.
		 */
		canInstall() {
			return getCurrentUser()?.isAdmin === true
		},
	},

	watch: {
		'$route.query.tab': function(requested) {
			this.active = tabFromQuery(requested)
		},
	},

	created() {
		if (this.redirectToTab !== '' && this.$router) {
			this.$router.replace({ name: 'FeaturesRoadmap', query: { tab: tabFromQuery(this.redirectToTab) } }).catch(() => {})
		}
	},

	mounted() {
		this.$watch(
			() => storeItemCount(this.$refs.store),
			(count) => { this.extensionCount = count },
			{ immediate: true },
		)
	},

	methods: {
		t,

		/**
		 * Switch tab and keep the choice in the URL, so the tab survives a reload.
		 *
		 * @param {string} id The tab id.
		 * @return {void}
		 */
		select(id) {
			this.active = tabFromQuery(id)
			if (this.$router && this.$route && this.$route.query?.tab !== this.active) {
				this.$router.replace({ query: { ...this.$route.query, tab: this.active } }).catch(() => {})
			}
		},
	},
}
</script>

<style scoped>
.sk-features-roadmap-tabs__bar {
	display: flex;
	gap: 4px;
	padding: 8px 16px 0;
	border-bottom: 1px solid var(--color-border);
}

.sk-features-roadmap-tabs__tab {
	display: inline-flex;
	align-items: center;
	gap: 6px;
	margin: 0;
	padding: 8px 12px;
	border: none;
	border-bottom: 2px solid transparent;
	border-radius: 0;
	background: none;
	color: var(--color-text-maxcontrast);
	cursor: pointer;
}

.sk-features-roadmap-tabs__tab--active {
	border-bottom-color: var(--color-primary-element);
	color: var(--color-main-text);
	font-weight: bold;
}

.sk-features-roadmap-tabs__count {
	padding: 0 6px;
	border-radius: var(--border-radius-pill);
	background: var(--color-background-dark);
	font-size: 0.85em;
}
</style>
