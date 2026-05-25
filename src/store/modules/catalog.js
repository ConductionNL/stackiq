import { defineStore } from 'pinia'

/**
 * Catalog store for managing catalog-related state
 * This is a simplified version to replace the missing catalogStore from OpenCatalogi
 */
export const useCatalogStore = defineStore('catalog', {
	state: () => ({
		// Basic catalog state
		catalogs: [],
		currentCatalog: null,
		loading: false,
		error: null,
	}),

	getters: {
		/**
		 * Get the current catalog
		 * @param {object} state - The store state
		 * @return {object | null} The current catalog or null
		 */
		getCurrentCatalog: (state) => state.currentCatalog,

		/**
		 * Check if loading
		 * @param {object} state - The store state
		 * @return {boolean} True if loading
		 */
		isLoading: (state) => state.loading,

		/**
		 * Get error message
		 * @param {object} state - The store state
		 * @return {string|null} Error message or null
		 */
		getError: (state) => state.error,
	},

	actions: {
		/**
		 * Set loading state
		 * @param {boolean} loading - Loading state
		 */
		setLoading(loading) {
			this.loading = loading
		},

		/**
		 * Set error message
		 * @param {string|null} error - Error message
		 */
		setError(error) {
			this.error = error
		},

		/**
		 * Set current catalog
		 * @param {object | null} catalog - Catalog object
		 */
		setCurrentCatalog(catalog) {
			this.currentCatalog = catalog
		},

		/**
		 * Clear error
		  * @spec openspec/changes/retrofit-2026-05-26-fe-stores/tasks.md#task-4
		 */
		clearError() {
			this.error = null
		},

		/**
		 * Reset store state
		  * @spec openspec/changes/retrofit-2026-05-26-fe-stores/tasks.md#task-4
		 */
		reset() {
			this.catalogs = []
			this.currentCatalog = null
			this.loading = false
			this.error = null
		},
	},
})
