/**
 * Object store for Softwarecatalog — powered by @conduction/nextcloud-vue.
 *
 * Uses createObjectStore('object') to maintain the same Pinia store ID
 * that all existing views reference. The full implementation (CRUD,
 * pagination, caching, resolveReferences, fetchSchema) lives in the shared library.
 *
 * The softwarecatalogPlugin adds app-specific operations: settings management,
 * active object tracking, lifecycle actions, mass operations, and column management.
 *
 * @module Store
 * @author Ruben Linde
 * @copyright 2024
 * @license AGPL-3.0-or-later
 * @version 2.0.0
 * @see {@link https://github.com/opencatalogi/softwarecatalog}
 *
 * @spec openspec/changes/retrofit-2026-05-24-annotate-softwarecatalog/tasks.md#task-12
 */
import { createObjectStore, filesPlugin, auditTrailsPlugin, relationsPlugin } from '@conduction/nextcloud-vue'
import { softwarecatalogPlugin } from '../plugins/softwarecatalogPlugin.js'

export const useObjectStore = createObjectStore('object', {
	plugins: [
		filesPlugin(),
		auditTrailsPlugin(),
		relationsPlugin(),
		softwarecatalogPlugin(),
	],
})
