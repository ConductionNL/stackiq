/**
 * Object store for Stackiq — powered by @conduction/nextcloud-vue.
 *
 * Uses createObjectStore('object') to maintain the same Pinia store ID
 * that all existing views reference. The full implementation (CRUD,
 * pagination, caching, resolveReferences, fetchSchema) lives in the shared library.
 *
 * The stackiqPlugin adds app-specific operations: settings management,
 * active object tracking, lifecycle actions, mass operations, and column management.
 *
 * @module Store
 * @author Ruben Linde
 * @copyright 2024
 * @license EUPL-1.2
 * @version 2.0.0
 * @see {@link https://github.com/ConductionNL/stackiq}
 *
 * @spec openspec/specs/softwarecatalog-store-migration/spec.md#requirement-createobjectstore-for-openregister-crud-stores
 */
import {
	auditTrailsPlugin,
	createObjectStore,
	filesPlugin,
	relationsPlugin,
} from '@conduction/nextcloud-vue'
import { stackiqPlugin } from '../plugins/stackiqPlugin.js'

export const useObjectStore = createObjectStore('object', {
	plugins: [
		filesPlugin(),
		auditTrailsPlugin(),
		relationsPlugin(),
		stackiqPlugin(),
	],
})
