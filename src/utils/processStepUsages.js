// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.

/**
 * processStepUsages — the applications in use that support a process step.
 *
 * The step stores usage ids. A reader may not be allowed to see every usage,
 * so the stored ids are compared with the usages that come back and the rest
 * are counted as hidden, instead of leaving a silent gap.
 *
 * @spec openspec/changes/architecture-process-mapping/specs/business-process-mapping/spec.md#requirement-req-bpm-002-a-step-shall-name-the-applications-in-use-that-support-it
 */

import { stepRefId } from './processStepFlow.js'

/**
 * The unique usage ids a step stores, in stored order.
 *
 * @param {object|null} step - a processStep.
 * @return {string[]}
 * @spec openspec/changes/architecture-process-mapping/specs/business-process-mapping/spec.md#requirement-req-bpm-002-a-step-shall-name-the-applications-in-use-that-support-it
 */
export function storedUsageIds(step) {
	const usages = Array.isArray(step?.usages) ? step.usages : []
	return [...new Set(usages.map(stepRefId).filter((id) => id !== ''))]
}

/**
 * The name to show for a usage.
 *
 * @param {object} usage - a usage object.
 * @param {string} id - its id, the last resort.
 * @return {string}
 * @spec openspec/changes/architecture-process-mapping/specs/business-process-mapping/spec.md#requirement-req-bpm-002-a-step-shall-name-the-applications-in-use-that-support-it
 */
export function usageName(usage, id) {
	const name = usage?.['@self']?.name || usage?.name || usage?.title
	return typeof name === 'string' && name.trim() !== '' ? name : id
}

/**
 * Fetch the usages a list of ids names: the visible ones, and how many did not come back.
 *
 * @param {string[]} ids - usage ids.
 * @param {function(string, string): Promise<object|null>} fetchObject - fetchObject(type, id), null when not visible.
 * @return {Promise<{visible: Array<{id: string, name: string}>, hidden: number}>}
 * @spec openspec/changes/architecture-process-mapping/specs/business-process-mapping/spec.md#requirement-req-bpm-002-a-step-shall-name-the-applications-in-use-that-support-it
 */
export async function loadUsages(ids, fetchObject) {
	const fetched = await Promise.all(ids.map((id) => fetchObject('usage', id).catch(() => null)))
	const visible = []
	fetched.forEach((usage, index) => {
		if (usage) {
			visible.push({ id: ids[index], name: usageName(usage, ids[index]) })
		}
	})
	return { visible, hidden: ids.length - visible.length }
}

/**
 * The usages of one step, from its id.
 *
 * @param {string} stepId - the processStep id.
 * @param {function(string, string): Promise<object|null>} fetchObject - fetchObject(type, id).
 * @return {Promise<{visible: Array<{id: string, name: string}>, hidden: number}>}
 * @spec openspec/changes/architecture-process-mapping/specs/business-process-mapping/spec.md#requirement-req-bpm-002-a-step-shall-name-the-applications-in-use-that-support-it
 */
export async function loadStepUsages(stepId, fetchObject) {
	const step = await fetchObject('processStep', stepId)
	return loadUsages(storedUsageIds(step), fetchObject)
}

/**
 * The steps of one process and the names of the applications that support them.
 *
 * @param {string} processId - the process id.
 * @param {{fetchList: function(string, object): Promise<Array<object>>, fetchObject: function(string, string): Promise<object|null>}} store - the two store lookups.
 * @return {Promise<{steps: Array<object>, usageNames: Record<string, string>}>}
 * @spec openspec/changes/architecture-process-mapping/specs/business-process-mapping/spec.md#requirement-req-bpm-004-the-process-page-shall-show-the-steps-as-a-read-only-flow
 */
export async function loadProcessSteps(processId, { fetchList, fetchObject }) {
	const steps = await fetchList('processStep', { process: processId, _limit: 500 })
	const list = Array.isArray(steps) ? steps : []
	const ids = [...new Set(list.flatMap((step) => storedUsageIds(step)))]
	const { visible } = await loadUsages(ids, fetchObject)
	return { steps: list, usageNames: Object.fromEntries(visible.map((usage) => [usage.id, usage.name])) }
}
