/**
 * Pinia store for GEMMA view enrichment state.
 *
 * Manages the include_gebruik, include_deelnames_gebruik and include_products
 * toggle state and fetches enriched views from the backend API.
 *
 * @spec openspec/changes/deelnames-gebruik/tasks.md#task-5
 * @spec openspec/changes/view-products-enrichment/specs/view-enrichment-api/spec.md
 */

import { defineStore } from 'pinia'
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'

export const useViewStore = defineStore('view', {
	state: () => ({
		// Enrichment toggles — all disabled by default per spec.
		includeGebruik: false,
		includeDeelnamesGebruik: false,
		includeProducts: false,

		// Fetched views data.
		views: [],
		activeView: null,
		loading: false,
		error: null,
	}),

	actions: {
		/**
		 * Set the gebruik toggle state.
		 *
		 * @param {boolean} value - New state for the gebruik toggle.
		 * @return {void}
		 */
		setIncludeGebruik(value) {
			this.includeGebruik = value
		},

		/**
		 * Set the deelnames gebruik toggle state.
		 *
		 * Independent from the gebruik toggle per spec.
		 *
		 * @param {boolean} value - New state for the deelnames toggle.
		 * @return {void}
		 */
		setIncludeDeelnamesGebruik(value) {
			this.includeDeelnamesGebruik = value
		},

		/**
		 * Set the products toggle state.
		 *
		 * Independent from the gebruik/deelnames toggles per spec.
		 *
		 * @param {boolean} value - New state for the products toggle.
		 * @return {void}
		 */
		setIncludeProducts(value) {
			this.includeProducts = value
		},

		/**
		 * Fetch all views with current enrichment flags from the backend.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/specs/view-enrichment-api/spec.md#requirement-frontend-must-call-enrichment-api-for-views
		 */
		async fetchViews() {
			this.loading = true
			this.error = null

			try {
				const params = {}

				if (this.includeGebruik) {
					params.include_gebruik = true
				}

				if (this.includeDeelnamesGebruik) {
					params.include_deelnames_gebruik = true
				}

				if (this.includeProducts) {
					params.include_products = true
				}

				const url = generateUrl('/apps/softwarecatalog/api/views')
				const response = await axios.get(url, { params })

				this.views = response.data.views ?? []
			} catch (error) {
				this.error = error.message ?? 'Failed to fetch views'
				console.error('ViewStore: failed to fetch views', error)
			} finally {
				this.loading = false
			}
		},

		/**
		 * Fetch a specific view by ID with current enrichment flags.
		 *
		 * @param {string} viewId - The view identifier.
		 * @return {Promise<void>}
		 * @spec openspec/specs/view-enrichment-api/spec.md#requirement-frontend-must-call-enrichment-api-for-views
		 */
		async fetchView(viewId) {
			this.loading = true
			this.error = null

			try {
				const params = {}

				if (this.includeGebruik) {
					params.include_gebruik = true
				}

				if (this.includeDeelnamesGebruik) {
					params.include_deelnames_gebruik = true
				}

				if (this.includeProducts) {
					params.include_products = true
				}

				const url = generateUrl(
					'/apps/softwarecatalog/api/views/' + encodeURIComponent(viewId),
				)
				const response = await axios.get(url, { params })

				this.activeView = response.data.view ?? null
			} catch (error) {
				this.error = error.message ?? 'Failed to fetch view'
				console.error('ViewStore: failed to fetch view', error)
			} finally {
				this.loading = false
			}
		},
	},
})
