/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Applies an adopted scenario to the landscape (architecture-future-state-scenarios,
 * design D5). Each change is one plain object write through OpenRegister's
 * objects API as the signed-in user, so RBAC, multitenancy and the audit trail
 * stay with the platform:
 *
 * | Action    | Write                                                                      |
 * |-----------|----------------------------------------------------------------------------|
 * | add       | a new usage: consumer, module, reference components, Planned, in production on the date |
 * | phase out | `startDateOutPhased` on the usage                                          |
 * | replace   | `plannedReplacement` and `plannedReplacementDate` on the usage            |
 *
 * After each write the change records the usage in `appliedTo`, so a retry
 * skips it; `appliedAt` on the scenario is written last, only when every change
 * is in. The object client is injected: the view passes the shared object
 * store, the specs pass a fake.
 */

/** The actions a scenario change can take (the `scenarioChange.action` enum). */
export const ACTIONS = Object.freeze({
	ADD: 'add',
	PHASE_OUT: 'phase out',
	REPLACE: 'replace',
})

/** The usage status a scenario addition starts in. */
export const ADDED_STATUS = 'Planned'

/**
 * The uuid of a related object, whether it is a uuid string or an object.
 *
 * @param {string|object|null|undefined} value The relation value.
 * @return {string|null} The uuid.
 * @spec openspec/changes/architecture-future-state-scenarios/specs/future-state-scenarios/spec.md#requirement-req-fss-004-a-scenario-shall-move-through-a-declared-lifecycle-and-an-adopted-scenario-shall-be-applied-to-the-landscape
 */
export function relationId(value) {
	if (value === null || value === undefined || value === '') {
		return null
	}
	if (typeof value === 'object') {
		return value['@self']?.id ?? value.id ?? value.uuid ?? null
	}
	return String(value)
}

/**
 * The relation ids of a list value.
 *
 * @param {Array|undefined|null} values The related objects or uuids.
 * @return {string[]} Their uuids.
 * @spec openspec/changes/architecture-future-state-scenarios/specs/future-state-scenarios/spec.md#requirement-req-fss-004-a-scenario-shall-move-through-a-declared-lifecycle-and-an-adopted-scenario-shall-be-applied-to-the-landscape
 */
function relationIds(values) {
	return (Array.isArray(values) ? values : []).map(relationId).filter((id) => id !== null)
}

/**
 * Whether the scenario may be applied: adopted and not applied before.
 *
 * @param {object|null|undefined} scenario The scenario.
 * @return {boolean}
 * @spec openspec/changes/architecture-future-state-scenarios/specs/future-state-scenarios/spec.md#requirement-req-fss-004-a-scenario-shall-move-through-a-declared-lifecycle-and-an-adopted-scenario-shall-be-applied-to-the-landscape
 */
export function canApply(scenario) {
	return scenario?.status === 'adopted' && !scenario?.appliedAt
}

/**
 * The date a change takes effect: its own, else the scenario's target date.
 *
 * @param {object} change   The scenario change.
 * @param {object} scenario The scenario.
 * @return {string|null} A Y-m-d date.
 * @spec openspec/changes/architecture-future-state-scenarios/specs/future-state-scenarios/spec.md#requirement-req-fss-004-a-scenario-shall-move-through-a-declared-lifecycle-and-an-adopted-scenario-shall-be-applied-to-the-landscape
 */
export function effectiveDate(change, scenario) {
	return change?.effectiveDate || scenario?.targetDate || null
}

/**
 * The changes in the order they are applied: by effective date, undated last,
 * then in the order given.
 *
 * @param {Array<object>} changes  The scenario's changes.
 * @param {object}        scenario The scenario.
 * @return {Array<object>} The changes, ordered.
 * @spec openspec/changes/architecture-future-state-scenarios/specs/future-state-scenarios/spec.md#requirement-req-fss-004-a-scenario-shall-move-through-a-declared-lifecycle-and-an-adopted-scenario-shall-be-applied-to-the-landscape
 */
export function orderChanges(changes, scenario) {
	return (changes || [])
		.map((change, index) => ({ change, index, date: effectiveDate(change, scenario) || '￿' }))
		.sort((a, b) => (a.date === b.date ? a.index - b.index : (a.date < b.date ? -1 : 1)))
		.map((entry) => entry.change)
}

/**
 * An object as a write body: without its `@self` metadata, with its id.
 *
 * @param {object} object The object as read.
 * @return {object} The body.
 * @spec openspec/changes/architecture-future-state-scenarios/specs/future-state-scenarios/spec.md#requirement-req-fss-004-a-scenario-shall-move-through-a-declared-lifecycle-and-an-adopted-scenario-shall-be-applied-to-the-landscape
 */
function writable(object) {
	const data = { ...(object || {}) }
	delete data['@self']
	const id = relationId(object)
	return id ? { ...data, id } : data
}

/**
 * The usage fields a change writes (design D5), without the usage it builds on.
 *
 * @param {object} change   The scenario change.
 * @param {object} scenario The scenario.
 * @return {object} The fields.
 * @throws {Error} For an unknown action, or a change without the usage or module it needs.
 * @spec openspec/changes/architecture-future-state-scenarios/specs/future-state-scenarios/spec.md#requirement-req-fss-004-a-scenario-shall-move-through-a-declared-lifecycle-and-an-adopted-scenario-shall-be-applied-to-the-landscape
 */
export function changeFields(change, scenario) {
	const date = effectiveDate(change, scenario)
	const module = relationId(change?.module)
	const usage = relationId(change?.usage)

	if (change?.action === ACTIONS.ADD) {
		if (!module) {
			throw new Error('An addition names no application.')
		}
		return {
			consumer: relationId(scenario?.organisation),
			module,
			usedForReferenceComponents: relationIds(change.referenceComponents),
			status: ADDED_STATUS,
			startDateInProduction: date,
		}
	}
	if (change?.action === ACTIONS.PHASE_OUT) {
		if (!usage) {
			throw new Error('A phase-out names no application in use.')
		}
		return { startDateOutPhased: date }
	}
	if (change?.action === ACTIONS.REPLACE) {
		if (!usage || !module) {
			throw new Error('A replacement needs the application in use and its successor.')
		}
		return { plannedReplacement: module, plannedReplacementDate: date }
	}
	throw new Error(`Unknown action "${change?.action}".`)
}

/**
 * Apply an adopted scenario's changes to the landscape.
 *
 * Changes that carry `appliedTo` are skipped. The first failed write stops the
 * apply: the changes written before it keep their `appliedTo`, `appliedAt`
 * stays empty and the result names the failed change, so a retry picks up
 * where this one stopped.
 *
 * @param {object}   options          The apply.
 * @param {object}   options.scenario The scenario.
 * @param {Array}    options.changes  Its changes.
 * @param {object}   options.objects  `{get(type, id), save(type, data)}`, both returning the object or null.
 * @param {() => Date} [options.now] The clock.
 * @return {Promise<{applied: string[], skipped: string[], failed: ({change: object, message: string}|null), appliedAt: (string|null)}>}
 * @spec openspec/changes/architecture-future-state-scenarios/specs/future-state-scenarios/spec.md#requirement-req-fss-004-a-scenario-shall-move-through-a-declared-lifecycle-and-an-adopted-scenario-shall-be-applied-to-the-landscape
 */
export async function applyScenario({ scenario, changes, objects, now = () => new Date() }) {
	const result = { applied: [], skipped: [], failed: null, appliedAt: null }
	if (!canApply(scenario)) {
		throw new Error('Only an adopted scenario that was not applied before can be applied.')
	}

	for (const change of orderChanges(changes, scenario)) {
		const changeId = relationId(change)
		if (relationId(change.appliedTo)) {
			result.skipped.push(changeId)
			continue
		}
		try {
			const usageId = await writeUsage(change, scenario, objects)
			const saved = await objects.save('scenarioChange', { ...writable(change), appliedTo: usageId })
			if (!saved) {
				throw new Error('The change could not be marked as applied.')
			}
			result.applied.push(changeId)
		} catch (error) {
			result.failed = { change, message: error?.message || String(error) }
			return result
		}
	}

	const appliedAt = now().toISOString()
	const saved = await objects.save('scenario', { ...writable(scenario), appliedAt })
	if (!saved) {
		result.failed = { change: null, message: 'The scenario could not be marked as applied.' }
		return result
	}
	result.appliedAt = appliedAt
	return result
}

/**
 * Write one change to its usage and return the usage's id.
 *
 * @param {object} change   The scenario change.
 * @param {object} scenario The scenario.
 * @param {object} objects  The object client.
 * @return {Promise<string>} The written usage's id.
 * @throws {Error} When the usage cannot be read or written.
 * @spec openspec/changes/architecture-future-state-scenarios/specs/future-state-scenarios/spec.md#requirement-req-fss-004-a-scenario-shall-move-through-a-declared-lifecycle-and-an-adopted-scenario-shall-be-applied-to-the-landscape
 */
async function writeUsage(change, scenario, objects) {
	const fields = changeFields(change, scenario)
	if (change.action === ACTIONS.ADD) {
		return addUsage(fields, objects)
	}
	const usage = await objects.get('usage', relationId(change.usage))
	if (!usage) {
		throw new Error('The application in use could not be read.')
	}
	const id = relationId(await objects.save('usage', { ...writable(usage), ...fields }))
	if (!id) {
		throw new Error('The application in use could not be written.')
	}
	return id
}

/**
 * Create the usage an addition makes, then move it to its status.
 *
 * The usage lifecycle starts every new usage in its initial status and refuses
 * another one on create, so the usage is created without a status and then
 * saved with ADDED_STATUS, which the lifecycle reaches from the initial one.
 *
 * @param {object} fields  The usage fields, status included.
 * @param {object} objects The object client.
 * @return {Promise<string>} The new usage's id.
 * @throws {Error} When the usage cannot be created or moved to its status.
 * @spec openspec/changes/architecture-future-state-scenarios/specs/future-state-scenarios/spec.md#requirement-req-fss-004-a-scenario-shall-move-through-a-declared-lifecycle-and-an-adopted-scenario-shall-be-applied-to-the-landscape
 */
async function addUsage(fields, objects) {
	const { status, ...initial } = fields
	const created = await objects.save('usage', initial)
	const id = relationId(created)
	if (!id) {
		throw new Error('The application in use could not be written.')
	}
	if (status && created?.status !== status) {
		const moved = await objects.save('usage', { ...writable(created), status })
		if (!relationId(moved)) {
			throw new Error('The application in use could not be written.')
		}
	}
	return id
}

/**
 * An object client over the shared object store.
 *
 * @param {object} store The object store (`fetchObject`, `saveObject`).
 * @return {{get: (type: string, id: string) => Promise<object|null>, save: (type: string, data: object) => Promise<object|null>}} The client.
 * @spec openspec/changes/architecture-future-state-scenarios/specs/future-state-scenarios/spec.md#requirement-req-fss-004-a-scenario-shall-move-through-a-declared-lifecycle-and-an-adopted-scenario-shall-be-applied-to-the-landscape
 */
export function storeObjects(store) {
	return {
		get: (type, id) => store.fetchObject(type, id),
		save: (type, data) => store.saveObject(type, data),
	}
}
