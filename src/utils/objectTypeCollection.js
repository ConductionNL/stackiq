/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Load an object type collection for a view that joins several of them.
 *
 * The type is registered from its resolved schema config before fetching:
 * the slug-based auto-registration only fires when the settings
 * `availableRegisters` carries a register slugged exactly 'stackiq', which is
 * not guaranteed on every instance. Without this the nc-vue object store
 * throws "Object type <type> is not registered".
 *
 * @spec openspec/specs/sbom-import/spec.md#requirement-the-module-version-detail-page-shows-imported-components-with-summary-counts
 */

/**
 * Register an object type from its schema config when it is not registered yet.
 *
 * @param {object} store The object store.
 * @param {string} type Object type slug.
 * @return {void}
 */
export function registerObjectTypeFromConfig(store, type) {
	if (
		typeof store.registerObjectType !== 'function'
		|| store.objectTypeRegistry?.[type]
	) {
		return
	}
	let cfg = null
	try {
		cfg = store.getSchemaConfig?.(type)
	} catch {
		// getSchemaConfig throws when no schema/register resolves;
		// fall through so fetchCollection surfaces its own error.
	}
	if (cfg?.register && cfg?.schema) {
		store.registerObjectType(type, cfg.schema, cfg.register)
	}
}

/**
 * Register an object type when needed and fetch its collection (at most 1000 objects).
 *
 * @param {object} store The object store.
 * @param {string} type Object type slug.
 * @return {Promise<void>} Resolves once fetched.
 */
export async function fetchObjectTypeCollection(store, type) {
	registerObjectTypeFromConfig(store, type)
	if (typeof store.fetchCollection === 'function') {
		await store.fetchCollection(type, { _limit: 1000 })
	}
}
