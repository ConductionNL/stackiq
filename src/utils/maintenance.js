/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Planned maintenance and the supplier roadmap: which maintenance windows an
 * organisation should see, and a product's versions as timeline events.
 *
 * @spec openspec/specs/maintenance-and-supplier-roadmap/spec.md
 */

const PAGE = 500
const DAY_MS = 24 * 60 * 60 * 1000

/**
 * The id of a row or a relation value.
 *
 * @param {object|string|null} value A row, a relation object or an id.
 * @return {string|null} The id.
 * @spec openspec/specs/maintenance-and-supplier-roadmap/spec.md#requirement-req-msr-002-organisations-that-use-a-product-see-its-planned-maintenance
 */
export function refId(value) {
	if (typeof value === 'string') {
		return value || null
	}
	return value?.id ?? value?.['@self']?.id ?? value?.uuid ?? null
}

/**
 * The planned windows on the given products that start within `days` days.
 *
 * @param {Array<object>} windows Maintenance windows.
 * @param {Array<string>} moduleIds The products the organisation uses.
 * @param {Date} [now] The current moment.
 * @param {number} [days] How far ahead to look.
 * @return {Array<object>} The windows, earliest first.
 * @spec openspec/specs/maintenance-and-supplier-roadmap/spec.md#requirement-req-msr-002-organisations-that-use-a-product-see-its-planned-maintenance
 */
export function upcomingMaintenance(
	windows,
	moduleIds,
	now = new Date(),
	days = 30,
) {
	const used = new Set(moduleIds)
	const from = now.getTime()
	const until = from + days * DAY_MS
	return (windows ?? [])
		.filter((w) => w?.status === 'planned' && used.has(refId(w.module)))
		.filter((w) => {
			const start = Date.parse(w.startsAt)
			const end = Date.parse(w.endsAt || w.startsAt)
			return !Number.isNaN(start) && end >= from && start <= until
		})
		.sort((a, b) => Date.parse(a.startsAt) - Date.parse(b.startsAt))
}

/**
 * Load the upcoming maintenance on the products an organisation uses.
 *
 * @param {string} organisationId The active organisation.
 * @param {(schema: string, params: object) => Promise<Array<object>>} fetchList Reads a list of objects.
 * @param {Date} [now] The current moment.
 * @return {Promise<Array<object>>} The windows, earliest first.
 * @spec openspec/specs/maintenance-and-supplier-roadmap/spec.md#requirement-req-msr-002-organisations-that-use-a-product-see-its-planned-maintenance
 */
export async function loadUpcomingMaintenance(
	organisationId,
	fetchList,
	now = new Date(),
) {
	if (!organisationId) {
		return []
	}
	const usages = await fetchList('usage', {
		consumer: organisationId,
		_limit: PAGE,
	})
	const moduleIds = [
		...new Set((usages ?? []).map((u) => refId(u.module)).filter(Boolean)),
	]
	if (moduleIds.length === 0) {
		return []
	}
	const windows = await fetchList('maintenanceWindow', {
		module: moduleIds,
		status: 'planned',
		_limit: PAGE,
	})
	return upcomingMaintenance(windows, moduleIds, now)
}

/**
 * A product's versions as timeline events, placed on the date they go or went
 * into use, or the date development started when no go-live date is set.
 *
 * @param {Array<object>} versions Module versions.
 * @return {Array<object>} Events for CnTimelineView.
 * @spec openspec/specs/maintenance-and-supplier-roadmap/spec.md#requirement-req-msr-004-a-product-page-shows-the-supplier-s-roadmap
 */
export function roadmapEvents(versions) {
	return (versions ?? [])
		.map((v) => ({
			id: refId(v) ?? v.version,
			title: v.version || '',
			start: v.dateInUse || v.dateInDevelopment || '',
			description: v.shortDescription || '',
			kind: v.status === 'in development' ? 'planned' : 'released',
			status: v.status || '',
		}))
		.filter((e) => e.start && !Number.isNaN(Date.parse(e.start)))
}
