/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */
/**
 * licensePosture — portfolio software-license posture (SAM overview).
 *
 * Aggregates the IN-PRODUCTION application portfolio into a license posture:
 * the open-source vs closed-source share, the licence-type mix, deployment
 * counts, and per-vendor / per-organisation rollups. Everything is weighted by
 * in-production `gebruik` (what we RUN), reusing the exact
 * application-lifecycle-tracking predicate (`startDatumInProductie` set,
 * `startDatumUitGefaseerd` empty) via `isInProduction` — a closed-source product
 * registered but never deployed does not inflate the closed share.
 *
 * Boundary: this owns PORTFOLIO posture. It CONSUMES contract-administration's
 * annualised cost (`totalAnnualisedCost`) for the per-vendor cost column — it
 * NEVER re-implements the Maandelijks×12 / Jaarlijks×1 maths. When contract data
 * is absent, cost degrades to null while licence mix + deployment counts still
 * work. Nothing is stored; every figure is derived at query time.
 *
 * @module utils/licensePosture
 * @author Ruben Linde
 * @copyright 2026 Conduction B.V.
 * @license EUPL-1.2
 *
 * @spec openspec/specs/software-license-posture/spec.md
 */

import { totalAnnualisedCost } from './contractCost.js'
import { dataOf, resolveUuid } from './lifecyclePhase.js'
import { isInProduction } from './vulnerabilityExposure.js'

/**
 * Licence-type policy axis constants. `Unknown` is the empty-licentietype bucket
 * — an unclassified running application is itself a posture gap worth counting.
 *
 * @type {{OPEN: string, CLOSED: string, UNKNOWN: string}}
 */
export const LICENSE_TYPE = Object.freeze({
	OPEN: 'Open source',
	CLOSED: 'Closed source',
	UNKNOWN: 'Unknown',
})

/**
 * Normalise a module's `licentietype` to the policy axis (empty → Unknown).
 *
 * @param {*} value A raw `licentietype` value.
 * @return {string} One of LICENSE_TYPE.*.
 *
 * @spec openspec/specs/software-license-posture/spec.md
 */
export function normaliseLicenseType(value) {
	if (value === LICENSE_TYPE.OPEN || value === LICENSE_TYPE.CLOSED) {
		return value
	}
	return LICENSE_TYPE.UNKNOWN
}

/**
 * Index modules by UUID for lookups.
 *
 * @param {Array<object>} modules Module records.
 * @return {object} UUID → module data bag.
 *
 * @spec openspec/specs/software-license-posture/spec.md
 */
export function indexModules(modules) {
	const index = {}
	for (const m of modules || []) {
		const id = resolveUuid(m.uuid ?? m.id ?? m['@self']?.id ?? m)
		if (id !== '') {
			index[id] = dataOf(m)
		}
	}
	return index
}

/**
 * The in-production usages (weight unit of every posture aggregate).
 *
 * @param {Array<object>} usages Gebruik records.
 * @return {Array<object>} In-production usages.
 *
 * @spec openspec/specs/software-license-posture/spec.md
 */
export function inProductionUsages(usages) {
	return (usages || []).filter((g) => isInProduction(g))
}

/**
 * Deployment count (license consumption) of a single application: the number of
 * in-production `gebruik` records referencing it.
 *
 * @param {string}        moduleId  The module UUID.
 * @param {Array<object>} usages Gebruik records.
 * @return {number} The in-production deployment count.
 *
 * @spec openspec/specs/software-license-posture/spec.md
 */
export function deploymentCount(moduleId, usages) {
	return inProductionUsages(usages).filter(
		(g) => resolveUuid(dataOf(g).module) === moduleId,
	).length
}

/**
 * Portfolio posture: open vs closed share and licence-type mix of the
 * in-production portfolio, weighted by deployment (each in-production usage is
 * one unit). Applications with an empty `licentietype` count as Unknown.
 *
 * @param {Array<object>} modules   Module records.
 * @param {Array<object>} usages Gebruik records.
 * @return {{total: number, open: number, closed: number, unknown: number, openShare: (number|null), byLicense: object}}
 *   Posture summary. `openShare` is open / (open + closed) or null when neither is present.
 *
 * @spec openspec/specs/software-license-posture/spec.md
 */
export function portfolioPosture(modules, usages) {
	const idx = indexModules(modules)
	const acc = { total: 0, open: 0, closed: 0, unknown: 0, byLicense: {} }

	for (const g of inProductionUsages(usages)) {
		const { mod } = moduleOfUsage(idx, g)
		countLicenseType(acc, normaliseLicenseType(mod.licentietype))
		const licence = licenceName(mod)
		acc.byLicense[licence] = (acc.byLicense[licence] || 0) + 1
	}

	return { ...acc, openShare: openShare(acc) }
}

/**
 * The module a usage runs, looked up in a module index.
 *
 * @param {object} idx Module data by uuid, from indexModules.
 * @param {object} usage A usage record.
 * @return {{moduleId: string, mod: object}} The module uuid and its data, empty when unknown.
 */
function moduleOfUsage(idx, usage) {
	const moduleId = resolveUuid(dataOf(usage).module)
	return { moduleId, mod: idx[moduleId] || {} }
}

/**
 * Count one deployment of a licence type into an open/closed/unknown tally.
 *
 * @param {{total: number, open: number, closed: number, unknown: number}} acc The tally.
 * @param {string} type A normalised licence type.
 * @return {void}
 */
function countLicenseType(acc, type) {
	acc.total += 1
	if (type === LICENSE_TYPE.OPEN) {
		acc.open += 1
	} else if (type === LICENSE_TYPE.CLOSED) {
		acc.closed += 1
	} else {
		acc.unknown += 1
	}
}

/**
 * The open share among deployments of a known licence type.
 *
 * @param {{open: number, closed: number}} acc The tally.
 * @return {number|null} The share, or null when no type is known.
 */
function openShare(acc) {
	const denom = acc.open + acc.closed
	return denom > 0 ? acc.open / denom : null
}

/**
 * The licence a module names, or Unknown when it names none.
 *
 * @param {object} mod The module data.
 * @return {string} The licence.
 */
function licenceName(mod) {
	const licence = mod.licence
	return typeof licence === 'string' && licence.trim() !== ''
		? licence
		: LICENSE_TYPE.UNKNOWN
}

/**
 * The rollup entry of a vendor, created empty on first use.
 *
 * @param {object} vendors Rollup entries by vendor uuid.
 * @param {string} vendorId The vendor uuid.
 * @return {object} The entry.
 */
function vendorEntry(vendors, vendorId) {
	if (!vendors[vendorId]) {
		vendors[vendorId] = {
			vendorId,
			deployments: 0,
			mix: {
				[LICENSE_TYPE.OPEN]: 0,
				[LICENSE_TYPE.CLOSED]: 0,
				[LICENSE_TYPE.UNKNOWN]: 0,
			},
			usageIds: new Set(),
		}
	}
	return vendors[vendorId]
}

/**
 * The uuid of a usage record: its own id, its @self id or the id in its data.
 *
 * @param {object} usage A usage record.
 * @return {string} The uuid, empty when it has none.
 */
function usageUuid(usage) {
	return resolveUuid(usage.id ?? usage['@self']?.id ?? dataOf(usage).id ?? '')
}

/**
 * Sum the annualised cost of the contracts belonging to a vendor's usages,
 * CONSUMING contract-administration's `totalAnnualisedCost` (never re-derived).
 *
 * @param {Set<string>}   vendorModuleUsageIds The set of in-production gebruik UUIDs for the vendor.
 * @param {Array<object>} contracts            Contract records.
 * @return {number|null} The annualised cost, or null when no contract applies.
 *
 * @spec openspec/specs/software-license-posture/spec.md
 */
function vendorAnnualCost(vendorModuleUsageIds, contracts) {
	const relevant = (contracts || []).filter((c) => {
		const gebruikId = resolveUuid(dataOf(c).usage)
		return gebruikId !== '' && vendorModuleUsageIds.has(gebruikId)
	})
	if (relevant.length === 0) {
		return null
	}
	return totalAnnualisedCost(relevant).annual
}

/**
 * Per-vendor rollup: for each supplier (`aanbieder`), the in-production
 * deployment count, the licence-type mix, and the annualised cost (consumed from
 * contract-administration; null when no contracts). Grouped by the vendor
 * reference on each deployed module.
 *
 * @param {Array<object>} modules   Module records.
 * @param {Array<object>} usages Gebruik records.
 * @param {Array<object>} contracts Contract records (optional — cost degrades to null when absent).
 * @return {Array<{vendorId: string, deployments: number, mix: object, annualCost: (number|null)}>}
 *   One row per vendor with in-production deployments.
 *
 * @spec openspec/specs/software-license-posture/spec.md
 */
export function perVendorRollup(modules, usages, contracts) {
	const idx = indexModules(modules)
	const vendors = {}

	for (const g of inProductionUsages(usages)) {
		const { mod } = moduleOfUsage(idx, g)
		const vendorId = resolveUuid(mod.provider)
		if (vendorId === '') {
			continue
		}
		const vendor = vendorEntry(vendors, vendorId)
		vendor.deployments += 1
		vendor.mix[normaliseLicenseType(mod.licentietype)] += 1
		const usageId = usageUuid(g)
		if (usageId !== '') {
			vendor.usageIds.add(usageId)
		}
	}

	return Object.values(vendors).map((v) => ({
		vendorId: v.vendorId,
		deployments: v.deployments,
		mix: v.mix,
		annualCost: vendorAnnualCost(v.usageIds, contracts),
	}))
}

/**
 * Per-organisation open-source-first posture: for the given organisation
 * (`afnemer`), the open vs closed share of its in-use applications plus the list
 * of closed-source modules contributing to the closed share.
 *
 * @param {string}        orgId     The organisation UUID.
 * @param {Array<object>} modules   Module records.
 * @param {Array<object>} usages Gebruik records.
 * @return {{total: number, open: number, closed: number, unknown: number, openShare: (number|null), closedContributors: Array<string>}}
 *   The organisation's posture. `closedContributors` is the distinct closed-source module UUIDs.
 *
 * @spec openspec/specs/software-license-posture/spec.md
 */
export function perOrganisationPosture(orgId, modules, usages) {
	const idx = indexModules(modules)
	const acc = { total: 0, open: 0, closed: 0, unknown: 0 }
	const closedContributors = new Set()

	for (const g of inProductionUsages(usages)) {
		if (resolveUuid(dataOf(g).consumer) !== orgId) {
			continue
		}
		const { moduleId, mod } = moduleOfUsage(idx, g)
		const type = normaliseLicenseType(mod.licentietype)
		countLicenseType(acc, type)
		if (type === LICENSE_TYPE.CLOSED) {
			closedContributors.add(moduleId)
		}
	}

	return {
		...acc,
		openShare: openShare(acc),
		closedContributors: [...closedContributors],
	}
}

/**
 * Licence metrics that have no seat to count. A contract on one of these gets
 * no seat comparison (contracts-licence-seats, design D3).
 *
 * @type {ReadonlyArray<string>}
 */
export const UNCOUNTED_METRICS = Object.freeze(['Per organisation', 'Other'])

/**
 * Seat states a counted licence contract can be in.
 *
 * @type {{WITHIN: string, OVER: string, UNKNOWN: string, NOT_COUNTED: string}}
 */
export const SEAT_STATE = Object.freeze({
	WITHIN: 'within',
	OVER: 'over',
	UNKNOWN: 'unknown',
	NOT_COUNTED: 'not-counted',
})

/**
 * Read a licence count: a whole number of zero or more, or null when empty.
 *
 * @param {string|number|null|undefined} value The raw field value.
 * @return {number|null} The count, or null.
 */
function seatCount(value) {
	if (value === null || value === undefined || value === '') {
		return null
	}
	const n = Number(value)
	return Number.isInteger(n) && n >= 0 ? n : null
}

/**
 * Where one contract stands on its licences: in use against bought.
 *
 * @param {object} contract A catalogContract record (envelope or data bag).
 * @return {{state: string, metric: string, bought: (number|null), inUse: (number|null), over: number}}
 *   The seat position; `over` is how many licences are in use above what was bought.
 * @spec openspec/specs/licence-seats/spec.md#requirement-req-lsc-002-the-contract-detail-page-must-show-licences-in-use-against-licences-bought
 */
export function seatPosition(contract) {
	const data = dataOf(contract)
	const metric = typeof data.licenceMetric === 'string' ? data.licenceMetric : ''
	const bought = seatCount(data.licencesBought)
	const inUse = seatCount(data.licencesInUse)
	const position = { state: SEAT_STATE.UNKNOWN, metric, bought, inUse, over: 0 }

	if (UNCOUNTED_METRICS.includes(metric)) {
		return { ...position, state: SEAT_STATE.NOT_COUNTED }
	}
	if (metric === '' || bought === null || inUse === null) {
		return position
	}
	if (inUse > bought) {
		return { ...position, state: SEAT_STATE.OVER, over: inUse - bought }
	}
	return { ...position, state: SEAT_STATE.WITHIN }
}

/**
 * The uuid of a record: id, uuid or @self id.
 *
 * @param {object} record A record.
 * @return {string} The uuid, empty when it has none.
 */
function recordUuid(record) {
	return resolveUuid(record?.id ?? record?.uuid ?? record?.['@self']?.id ?? '')
}

/**
 * Usage data by usage uuid.
 *
 * @param {Array<object>} usages The usage records.
 * @return {object} The index.
 */
function indexUsages(usages) {
	const usageIndex = {}
	for (const u of usages || []) {
		const id = recordUuid(u)
		if (id !== '') {
			usageIndex[id] = dataOf(u)
		}
	}
	return usageIndex
}

/**
 * Whether a seat position belongs in the seat table: counted, with a metric
 * and a bought number.
 *
 * @param {object} position A seat position.
 * @return {boolean} True when it is listed.
 */
function isCountedSeat(position) {
	return (
		position.state !== SEAT_STATE.NOT_COUNTED
		&& position.metric !== ''
		&& position.bought !== null
	)
}

/**
 * The seat table row of one contract.
 *
 * @param {object} contract The contract record.
 * @param {{position: object, usageIndex: object}} context Its seat position and the usage index.
 * @return {object} The row.
 */
function seatRow(contract, context) {
	const { position, usageIndex } = context
	const data = dataOf(contract)
	const usage = usageIndex[resolveUuid(data.usage)] || {}
	return {
		contractId: recordUuid(contract),
		contractNumber: data.contractNumber || '',
		moduleId: resolveUuid(usage.module),
		consumerId: resolveUuid(usage.consumer),
		metric: position.metric,
		bought: position.bought,
		inUse: position.inUse,
		state: position.state,
		over: position.over,
	}
}

/**
 * One row per counted licence contract for the Seats section of the License
 * posture page, over-licence rows first (most over first). A contract is left
 * out when its metric is uncounted or empty, or when `licencesBought` is empty.
 *
 * @param {Array<object>} contracts catalogContract records.
 * @param {Array<object>} usages    Usage records, to find the application and the organisation.
 * @return {Array<{contractId: string, contractNumber: string, moduleId: string, consumerId: string, metric: string, bought: number, inUse: (number|null), state: string, over: number}>}
 *   The seat rows.
 * @spec openspec/specs/licence-seats/spec.md#requirement-req-lsc-003-the-license-posture-page-shall-list-every-counted-licence-contract-with-its-seat-state-over-use-first
 */
export function seatRows(contracts, usages) {
	const usageIndex = indexUsages(usages)
	const rows = []
	for (const c of contracts || []) {
		const position = seatPosition(c)
		if (isCountedSeat(position)) {
			rows.push(seatRow(c, { position, usageIndex }))
		}
	}

	const rank = (row) => (row.state === SEAT_STATE.OVER ? 0 : 1)
	return rows.sort((a, b) => rank(a) - rank(b) || b.over - a.over)
}
