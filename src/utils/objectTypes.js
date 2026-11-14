/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Registering an object type with the object store before a widget fetches
 * objects of it. A widget opened straight from a link can mount before the
 * app has registered the types it needs.
 *
 * @spec openspec/specs/application-value-assessment/spec.md#requirement-req-ava-002-the-usage-page-shows-the-risk-signals-next-to-the-risk-score
 */

import { objectStore } from '../store/store.js'

/**
 * Whether a type still has to be registered with the store.
 *
 * @param {string} type The schema slug.
 * @return {boolean} True when the store can register types and does not know this one.
 */
function needsObjectType(type) {
	return (
		typeof objectStore.registerObjectType === 'function'
		&& !objectStore.objectTypeRegistry?.[type]
	)
}

/**
 * Register a type from its schema configuration when that names both ids.
 *
 * @param {string} type The schema slug.
 * @param {object|null|undefined} config The type's schema configuration.
 * @return {boolean} True when the type was registered.
 */
function registerFromSchemaConfig(type, config) {
	if (config?.register && config?.schema) {
		objectStore.registerObjectType(type, config.schema, config.register)
		return true
	}
	return false
}

/**
 * The schema configuration of a type, or null when reading it fails.
 *
 * @param {string} type The schema slug.
 * @return {object|null|undefined} The configuration.
 */
function schemaConfigOrNull(type) {
	try {
		return objectStore.getSchemaConfig?.(type)
	} catch {
		return null
	}
}

/**
 * Register a type by its slug in the voorzieningen register.
 *
 * OpenRegister accepts the schema slug where it takes an id, so a type the
 * settings do not map still resolves in the voorzieningen register.
 *
 * @param {string} type The schema slug.
 * @return {void}
 */
function registerInVoorzieningen(type) {
	const voorzieningen =
		objectStore.settings?.voorzieningen
		|| objectStore.settings?.voorzieningenConfig
		|| {}
	if (voorzieningen.register) {
		objectStore.registerObjectType(type, type, voorzieningen.register)
	}
}

/**
 * Register a type from the settings when the store does not know it yet.
 *
 * @param {string} type The schema slug.
 * @return {void}
 */
export function ensureObjectType(type) {
	if (needsObjectType(type)) {
		registerFromSchemaConfig(type, objectStore.getSchemaConfig?.(type))
	}
}

/**
 * Register a type from the settings, or else by its slug in the
 * voorzieningen register, when the store does not know it yet. A failing
 * settings lookup counts as no configuration.
 *
 * @param {string} type The schema slug.
 * @return {void}
 */
export function ensureObjectTypeOrSlug(type) {
	if (
		!needsObjectType(type)
		|| registerFromSchemaConfig(type, schemaConfigOrNull(type))
	) {
		return
	}
	registerInVoorzieningen(type)
}
