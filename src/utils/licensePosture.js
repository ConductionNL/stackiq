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

import { resolveUuid } from './lifecyclePhase.js'
import { isInProduction } from './vulnerabilityExposure.js'
import { totalAnnualisedCost } from './contractCost.js'

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
 * Read the data bag of a record that may be an OR object envelope or plain data.
 *
 * @param {object} record Any OR object or data bag.
 * @return {object} The property bag.
 */
function dataOf(record) {
	if (!record || typeof record !== 'object') {
		return {}
	}
	if (record.object && typeof record.object === 'object') {
		return record.object
	}
	return record
}

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
	for (const m of (modules || [])) {
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
 * @param {Array<object>} gebruiken Gebruik records.
 * @return {Array<object>} In-production usages.
 *
 * @spec openspec/specs/software-license-posture/spec.md
 */
export function inProductionUsages(gebruiken) {
	return (gebruiken || []).filter((g) => isInProduction(g))
}

/**
 * Deployment count (license consumption) of a single application: the number of
 * in-production `gebruik` records referencing it.
 *
 * @param {string}        moduleId  The module UUID.
 * @param {Array<object>} gebruiken Gebruik records.
 * @return {number} The in-production deployment count.
 *
 * @spec openspec/specs/software-license-posture/spec.md
 */
export function deploymentCount(moduleId, gebruiken) {
	return inProductionUsages(gebruiken)
		.filter((g) => resolveUuid(dataOf(g).module) === moduleId)
		.length
}

/**
 * Portfolio posture: open vs closed share and licence-type mix of the
 * in-production portfolio, weighted by deployment (each in-production usage is
 * one unit). Applications with an empty `licentietype` count as Unknown.
 *
 * @param {Array<object>} modules   Module records.
 * @param {Array<object>} gebruiken Gebruik records.
 * @return {{total: number, open: number, closed: number, unknown: number, openShare: (number|null), byLicense: object}}
 *   Posture summary. `openShare` is open / (open + closed) or null when neither is present.
 *
 * @spec openspec/specs/software-license-posture/spec.md
 */
export function portfolioPosture(modules, gebruiken) {
	const idx = indexModules(modules)
	const acc = { total: 0, open: 0, closed: 0, unknown: 0, byLicense: {} }

	for (const g of inProductionUsages(gebruiken)) {
		const moduleId = resolveUuid(dataOf(g).module)
		const mod = idx[moduleId] || {}
		const type = normaliseLicenseType(mod.licentietype)
		acc.total += 1
		if (type === LICENSE_TYPE.OPEN) {
			acc.open += 1
		} else if (type === LICENSE_TYPE.CLOSED) {
			acc.closed += 1
		} else {
			acc.unknown += 1
		}
		const licence = (typeof mod.licentie === 'string' && mod.licentie.trim() !== '')
			? mod.licentie
			: LICENSE_TYPE.UNKNOWN
		acc.byLicense[licence] = (acc.byLicense[licence] || 0) + 1
	}

	const denom = acc.open + acc.closed
	return {
		...acc,
		openShare: denom > 0 ? acc.open / denom : null,
	}
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
		const gebruikId = resolveUuid(dataOf(c).gebruik)
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
 * @param {Array<object>} gebruiken Gebruik records.
 * @param {Array<object>} contracts Contract records (optional — cost degrades to null when absent).
 * @return {Array<{vendorId: string, deployments: number, mix: object, annualCost: (number|null)}>}
 *   One row per vendor with in-production deployments.
 *
 * @spec openspec/specs/software-license-posture/spec.md
 */
export function perVendorRollup(modules, gebruiken, contracts) {
	const idx = indexModules(modules)
	const vendors = {}

	for (const g of inProductionUsages(gebruiken)) {
		const data = dataOf(g)
		const moduleId = resolveUuid(data.module)
		const mod = idx[moduleId] || {}
		const vendorId = resolveUuid(mod.aanbieder)
		if (vendorId === '') {
			continue
		}
		if (!vendors[vendorId]) {
			vendors[vendorId] = {
				vendorId,
				deployments: 0,
				mix: { [LICENSE_TYPE.OPEN]: 0, [LICENSE_TYPE.CLOSED]: 0, [LICENSE_TYPE.UNKNOWN]: 0 },
				usageIds: new Set(),
			}
		}
		vendors[vendorId].deployments += 1
		vendors[vendorId].mix[normaliseLicenseType(mod.licentietype)] += 1
		const usageId = resolveUuid(g.id ?? g['@self']?.id ?? data.id ?? '')
		if (usageId !== '') {
			vendors[vendorId].usageIds.add(usageId)
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
 * @param {Array<object>} gebruiken Gebruik records.
 * @return {{total: number, open: number, closed: number, unknown: number, openShare: (number|null), closedContributors: Array<string>}}
 *   The organisation's posture. `closedContributors` is the distinct closed-source module UUIDs.
 *
 * @spec openspec/specs/software-license-posture/spec.md
 */
export function perOrganisationPosture(orgId, modules, gebruiken) {
	const idx = indexModules(modules)
	const acc = { total: 0, open: 0, closed: 0, unknown: 0, closedContributors: new Set() }

	for (const g of inProductionUsages(gebruiken)) {
		const data = dataOf(g)
		if (resolveUuid(data.afnemer) !== orgId) {
			continue
		}
		const moduleId = resolveUuid(data.module)
		const mod = idx[moduleId] || {}
		const type = normaliseLicenseType(mod.licentietype)
		acc.total += 1
		if (type === LICENSE_TYPE.OPEN) {
			acc.open += 1
		} else if (type === LICENSE_TYPE.CLOSED) {
			acc.closed += 1
			acc.closedContributors.add(moduleId)
		} else {
			acc.unknown += 1
		}
	}

	const denom = acc.open + acc.closed
	return {
		total: acc.total,
		open: acc.open,
		closed: acc.closed,
		unknown: acc.unknown,
		openShare: denom > 0 ? acc.open / denom : null,
		closedContributors: [...acc.closedContributors],
	}
}
