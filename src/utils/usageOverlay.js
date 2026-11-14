/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */
/**
 * The applications that fill a reference component, as Vue Flow nodes nested
 * inside it (architecture-shared-usage-on-views). Own usage comes from the
 * enriched node's `usage`, shared usage from its `deelnamesGebruik`. A
 * shared node is told apart by its class (a dashed border), the badge
 * "Shared" and an accessible name that names the partner, never by colour
 * alone. An application both owned and shared on one component is drawn
 * once, as owned, the rule ViewService applies.
 *
 * @spec openspec/changes/architecture-shared-usage-on-views/specs/shared-usage-on-views/spec.md#requirement-req-suv-002-a-shared-application-must-be-drawn-apart-from-an-own-application-without-relying-on-colour
 */

/** Height of one overlay row, in pixels. */
export const USAGE_ROW_HEIGHT = 28

/** Space left at the top of a component for its own label, in pixels. */
export const USAGE_HEADER_HEIGHT = 32

/** Inner margin of the overlay inside a component, in pixels. */
export const USAGE_PADDING = 6

/**
 * The key that makes two usages the same application: the module, else the name.
 *
 * @param {object} usage A usage record.
 * @return {string} The key.
 */
function applicationKey(usage) {
	return String(usage?.module ?? usage?.moduleName ?? usage?.name ?? '')
}

/**
 * A usage's display name.
 *
 * @param {object} usage A usage record.
 * @return {string} The name.
 */
function applicationName(usage) {
	return String(usage?.moduleName ?? usage?.name ?? usage?.['@self']?.name ?? '')
}

/**
 * A usage's own id, for the node id and the click-through to its detail page.
 *
 * @param {object} usage A usage record.
 * @return {string} The id.
 */
function usageId(usage) {
	return String(usage?.['@self']?.id ?? usage?.id ?? usage?.uuid ?? applicationKey(usage))
}

/**
 * The usages to draw for a component, own first, shared after, without a
 * shared one for an application the organisation also owns there.
 *
 * @param {object} componentNode The enriched view node.
 * @param {{own: boolean, shared: boolean}} show Which kinds are switched on.
 * @return {Array<{usage: object, shared: boolean}>} The rows.
 *
 * @spec openspec/changes/architecture-shared-usage-on-views/specs/shared-usage-on-views/spec.md#requirement-req-suv-002-a-shared-application-must-be-drawn-apart-from-an-own-application-without-relying-on-colour
 */
export function overlayRows(componentNode, show) {
	const own = show?.own ? (componentNode?.usage ?? []) : []
	const owned = new Set((componentNode?.usage ?? []).map(applicationKey))
	const shared = show?.shared
		? (componentNode?.deelnamesGebruik ?? []).filter((usage) => !owned.has(applicationKey(usage)))
		: []
	return [
		...own.map((usage) => ({ usage, shared: false })),
		...shared.map((usage) => ({ usage, shared: true })),
	]
}

/**
 * The overlay nodes for one reference component.
 *
 * @param {object} componentNode The enriched view node; `id` is its Vue Flow node id.
 * @param {{own: boolean, shared: boolean}} show Which kinds are switched on.
 * @param {object} options Layout and text.
 * @param {number} options.width The component's width.
 * @param {number} options.height The component's height.
 * @param {function(string, string, object=): string} options.t The translator: app, text, variables.
 * @return {Array<object>} Vue Flow nodes, the last one "+N more" when they do not all fit.
 *
 * @spec openspec/changes/architecture-shared-usage-on-views/specs/shared-usage-on-views/spec.md#requirement-req-suv-002-a-shared-application-must-be-drawn-apart-from-an-own-application-without-relying-on-colour
 */
export function usageOverlayNodes(componentNode, show, { width, height, t }) {
	const rows = overlayRows(componentNode, show)
	const parentId = String(componentNode?.id ?? '')
	if (rows.length === 0 || parentId === '') {
		return []
	}

	const capacity = Math.max(1, Math.floor((height - USAGE_HEADER_HEIGHT - USAGE_PADDING) / USAGE_ROW_HEIGHT))
	const shown = rows.length > capacity ? rows.slice(0, capacity - 1) : rows
	const nodeWidth = Math.max(0, width - (2 * USAGE_PADDING))
	const place = (index, count) => ({
		x: USAGE_PADDING,
		y: height - USAGE_PADDING - ((count - index) * USAGE_ROW_HEIGHT),
	})
	const count = shown.length + (shown.length < rows.length ? 1 : 0)

	const nodes = shown.map(({ usage, shared }, index) => {
		const name = applicationName(usage)
		const partner = String(usage?._sourceOrganization ?? '')
		return {
			id: parentId + '::usage::' + usageId(usage),
			type: 'default',
			parentNode: parentId,
			extent: 'parent',
			draggable: false,
			position: place(index, count),
			style: { width: nodeWidth + 'px', height: (USAGE_ROW_HEIGHT - 4) + 'px' },
			class: shared ? 'usage-node usage-node--shared' : 'usage-node usage-node--own',
			data: {
				label: name,
				usageId: usageId(usage),
				shared,
				partner: shared ? partner : null,
				badge: shared ? t('stackiq', 'Shared') : null,
				ariaLabel: shared ? t('stackiq', '{name}, shared by {partner}', { name, partner }) : name,
			},
		}
	})

	if (shown.length < rows.length) {
		const more = rows.length - shown.length
		nodes.push({
			id: parentId + '::usage::more',
			type: 'default',
			parentNode: parentId,
			extent: 'parent',
			draggable: false,
			position: place(shown.length, count),
			style: { width: nodeWidth + 'px', height: (USAGE_ROW_HEIGHT - 4) + 'px' },
			class: 'usage-node usage-node--more',
			data: { label: t('stackiq', '+{count} more', { count: more }), more, ariaLabel: t('stackiq', '+{count} more', { count: more }) },
		})
	}

	return nodes
}
