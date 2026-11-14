// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.

/**
 * processStepFlow — the steps of one business process as a read-only flow.
 *
 * Each step becomes a node (its name, step type, risk level, compliance check
 * and the applications that support it). An edge runs from each step a step
 * `follows`; a step that follows nothing named runs on from the step(s) with
 * the next lower position. Positions come from the shared flow layout.
 *
 * @spec openspec/changes/architecture-process-mapping/specs/business-process-mapping/spec.md#requirement-req-bpm-004-the-process-page-shall-show-the-steps-as-a-read-only-flow
 */

import { layoutFlowNodes } from '@conduction/nextcloud-vue/src/composables/flowGraphLayout.js'

/**
 * The id of a stored object or reference.
 *
 * @param {object|string|null|undefined} ref - an object, its id or uuid, or nothing.
 * @return {string} The id, or ''.
 */
export function stepRefId(ref) {
	if (typeof ref === 'string') {
		return ref
	}
	if (ref && typeof ref === 'object') {
		return String(ref.id || ref.uuid || ref['@self']?.id || '')
	}
	return ''
}

/**
 * Whether a step needs attention: a high risk or a failed compliance check.
 *
 * @param {object} step - a processStep.
 * @return {boolean}
 * @spec openspec/changes/architecture-process-mapping/specs/business-process-mapping/spec.md#requirement-req-bpm-003-a-step-shall-carry-a-risk-level-and-a-compliance-check
 */
export function stepNeedsAttention(step) {
	return step?.riskLevel === 'high' || step?.complianceCheck === 'not compliant'
}

/**
 * The steps in process order: by position, then by name.
 *
 * @param {Array<object>} steps - the process's steps.
 * @return {Array<object>} A new, sorted array.
 * @spec openspec/changes/architecture-process-mapping/specs/business-process-mapping/spec.md#requirement-req-bpm-001-a-municipal-information-manager-shall-record-a-business-process-with-ordered-steps
 */
export function orderSteps(steps) {
	return (Array.isArray(steps) ? steps : [])
		.filter((step) => stepRefId(step) !== '')
		.slice()
		.sort((a, b) => (Number(a.position) - Number(b.position)) || String(a.name || '').localeCompare(String(b.name || '')))
}

/**
 * The edges between the steps.
 *
 * @param {Array<object>} ordered - the steps in process order.
 * @return {Array<{id: string, source: string, target: string}>}
 * @spec openspec/changes/architecture-process-mapping/specs/business-process-mapping/spec.md#requirement-req-bpm-004-the-process-page-shall-show-the-steps-as-a-read-only-flow
 */
export function stepEdges(ordered) {
	const ids = new Set(ordered.map(stepRefId))
	const edges = []
	for (const step of ordered) {
		const target = stepRefId(step)
		let sources = (Array.isArray(step.follows) ? step.follows : [])
			.map(stepRefId)
			.filter((id) => ids.has(id) && id !== target)
		if (sources.length === 0) {
			const lower = ordered.filter((other) => Number(other.position) < Number(step.position))
			const previous = lower.length ? Math.max(...lower.map((other) => Number(other.position))) : null
			sources = previous === null ? [] : lower.filter((other) => Number(other.position) === previous).map(stepRefId)
		}
		for (const source of [...new Set(sources)]) {
			edges.push({ id: source + '->' + target, source, target })
		}
	}
	return edges
}

/**
 * The flow of one process: positioned nodes and their edges.
 *
 * @param {Array<object>} steps - the process's steps.
 * @param {Record<string, string>} [usageNames] - application names by usage id.
 * @return {{nodes: Array<object>, edges: Array<object>}}
 * @spec openspec/changes/architecture-process-mapping/specs/business-process-mapping/spec.md#requirement-req-bpm-004-the-process-page-shall-show-the-steps-as-a-read-only-flow
 */
export function buildStepFlow(steps, usageNames = {}) {
	const ordered = orderSteps(steps)
	const edges = stepEdges(ordered)
	const targets = new Set(edges.map((edge) => edge.target))
	const nodes = ordered.map((step) => ({
		id: stepRefId(step),
		type: 'default',
		data: {
			name: String(step.name || ''),
			stepType: step.stepType || 'task',
			riskLevel: step.riskLevel || 'not assessed',
			complianceCheck: step.complianceCheck || 'not checked',
			applications: (Array.isArray(step.usages) ? step.usages : [])
				.map((usage) => usageNames[stepRefId(usage)] || '')
				.filter((name) => name !== ''),
			needsAttention: stepNeedsAttention(step),
		},
	}))
	const startIds = nodes.filter((node) => !targets.has(node.id)).map((node) => node.id)
	return { nodes: layoutFlowNodes(nodes, edges, startIds), edges }
}
