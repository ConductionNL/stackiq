/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Catalogue webhooks over OpenRegister's webhooks API. A catalogue webhook
 * listens to OpenRegister's object created and updated events, filtered to
 * the catalogue register and to the schemas of the catalogue events chosen
 * (applications, contracts). Webhooks on other registers are not ours and
 * are left out of the list.
 *
 * @spec openspec/changes/platform-properties/specs/platform-properties/spec.md#requirement-another-system-is-notified-when-a-catalogue-entry-changes-req-plat-005
 */

import axios from '@nextcloud/axios'
import { translate as t } from '@nextcloud/l10n'
import { generateUrl } from '@nextcloud/router'

/** OpenRegister's events a catalogue webhook listens to. */
export const OBJECT_EVENTS = Object.freeze([
	'OCA\\OpenRegister\\Event\\ObjectCreatedEvent',
	'OCA\\OpenRegister\\Event\\ObjectUpdatedEvent',
])

/**
 * The catalogue events to choose from, each the schema whose changes it sends,
 * named by its key in the voorzieningen configuration.
 *
 * @return {Array<{id: string, label: string, configKey: string}>} The events.
 * @spec openspec/changes/platform-properties/specs/platform-properties/spec.md#requirement-another-system-is-notified-when-a-catalogue-entry-changes-req-plat-005
 */
export function catalogueEvents() {
	return [
		{ id: 'module', label: t('stackiq', 'Application changed'), configKey: 'module_schema' },
		{ id: 'catalogContract', label: t('stackiq', 'Contract changed'), configKey: 'contract_schema' },
	]
}

/**
 * The register and the schema id of each catalogue event, from the
 * voorzieningen configuration; null when the catalogue register is not set up.
 *
 * @param {object} config The voorzieningen configuration.
 * @return {{register: string, schemas: {[event: string]: string}}|null} The catalogue.
 * @spec openspec/changes/platform-properties/specs/platform-properties/spec.md#requirement-another-system-is-notified-when-a-catalogue-entry-changes-req-plat-005
 */
export function catalogueTarget(config) {
	const register = config?.register
	if (!register) {
		return null
	}
	const schemas = {}
	for (const event of catalogueEvents()) {
		if (config[event.configKey]) {
			schemas[event.id] = String(config[event.configKey])
		}
	}
	return { register: String(register), schemas }
}

/**
 * Whether a webhook is filtered to the catalogue register.
 *
 * @param {object} webhook An OpenRegister webhook.
 * @param {{register: string}} target The catalogue.
 * @return {boolean} True for a catalogue webhook.
 * @spec openspec/changes/platform-properties/specs/platform-properties/spec.md#requirement-another-system-is-notified-when-a-catalogue-entry-changes-req-plat-005
 */
export function isCatalogueWebhook(webhook, target) {
	const register = webhook?.filters?.register
	return register !== undefined && register !== null && String(register) === target.register
}

/**
 * The catalogue events a webhook sends: those whose schema its filter names.
 *
 * @param {object} webhook An OpenRegister webhook.
 * @param {{schemas: {[event: string]: string}}} target The catalogue.
 * @return {string[]} The event ids.
 * @spec openspec/changes/platform-properties/specs/platform-properties/spec.md#requirement-another-system-is-notified-when-a-catalogue-entry-changes-req-plat-005
 */
export function webhookEvents(webhook, target) {
	const filter = webhook?.filters?.schema
	const schemas = (Array.isArray(filter) ? filter : [filter]).filter((id) => id !== undefined && id !== null).map(String)
	return Object.entries(target.schemas).filter(([, schema]) => schemas.includes(schema)).map(([event]) => event)
}

/**
 * The last delivery of a webhook: its last success or failure, whichever is later.
 *
 * @param {object} webhook An OpenRegister webhook.
 * @return {{at: string, ok: boolean}|null} The last delivery, or null when there was none.
 * @spec openspec/changes/platform-properties/specs/platform-properties/spec.md#requirement-another-system-is-notified-when-a-catalogue-entry-changes-req-plat-005
 */
export function lastDelivery(webhook) {
	const success = webhook?.lastSuccessAt || null
	const failure = webhook?.lastFailureAt || null
	if (!success && !failure) {
		return null
	}
	if (failure && (!success || new Date(failure) > new Date(success))) {
		return { at: failure, ok: false }
	}
	return { at: success, ok: true }
}

/**
 * The OpenRegister webhook body for an address and the chosen catalogue events.
 *
 * @param {{name?: string, url: string, events: string[]}} form What the administrator entered.
 * @param {{register: string, schemas: {[event: string]: string}}} target The catalogue.
 * @return {object} The webhook body.
 * @spec openspec/changes/platform-properties/specs/platform-properties/spec.md#requirement-another-system-is-notified-when-a-catalogue-entry-changes-req-plat-005
 */
export function webhookBody(form, target) {
	const url = String(form.url || '').trim()
	return {
		name: String(form.name || '').trim() || url,
		url,
		method: 'POST',
		enabled: true,
		events: [...OBJECT_EVENTS],
		filters: {
			register: target.register,
			schema: form.events.map((event) => target.schemas[event]).filter(Boolean),
		},
	}
}

/**
 * The catalogue webhooks, read from OpenRegister.
 *
 * @param {{register: string}} target The catalogue.
 * @return {Promise<object[]>} The catalogue's webhooks.
 * @spec openspec/changes/platform-properties/specs/platform-properties/spec.md#requirement-another-system-is-notified-when-a-catalogue-entry-changes-req-plat-005
 */
export async function listCatalogueWebhooks(target) {
	const { data } = await axios.get(generateUrl('/apps/openregister/api/webhooks'))
	const results = Array.isArray(data?.results) ? data.results : []
	return results.filter((webhook) => isCatalogueWebhook(webhook, target))
}

/**
 * Create a webhook, or update the one with `id`.
 *
 * @param {object} body The webhook body.
 * @param {number|null} id The webhook to update, or null to create one.
 * @return {Promise<object>} The stored webhook.
 * @spec openspec/changes/platform-properties/specs/platform-properties/spec.md#requirement-another-system-is-notified-when-a-catalogue-entry-changes-req-plat-005
 */
export async function saveWebhook(body, id = null) {
	if (id === null || id === undefined) {
		return (await axios.post(generateUrl('/apps/openregister/api/webhooks'), body)).data
	}
	return (await axios.put(generateUrl('/apps/openregister/api/webhooks/{id}', { id }), body)).data
}

/**
 * Remove a webhook.
 *
 * @param {number} id The webhook.
 * @return {Promise<void>}
 * @spec openspec/changes/platform-properties/specs/platform-properties/spec.md#requirement-another-system-is-notified-when-a-catalogue-entry-changes-req-plat-005
 */
export async function removeWebhook(id) {
	await axios.delete(generateUrl('/apps/openregister/api/webhooks/{id}', { id }))
}
