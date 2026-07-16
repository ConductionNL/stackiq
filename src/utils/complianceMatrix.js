/**
 * complianceMatrix — pure data mapper for the module ✕ standard compliance matrix.
 *
 * "Does application X support standard Y?" is the procurement question a
 * municipal buyer asks a software catalog. This module turns the raw catalog
 * data — modules, compliancy records, and a selection of standaardversies —
 * into a matrix whose every cell carries one of three honest states:
 *
 *  - `verified` — a compliancy record links the module to the standaardversie
 *    AND carries evidence (a `bewijs` file, a `bewijsReferentie` NC Files
 *    link, or a `url`);
 *  - `claimed`  — the link exists but no evidence is attached;
 *  - `none`     — no compliancy record exists for that (module, standard) pair.
 *
 * Per the design (Decision 2) verified and claimed MUST stay distinct — a PvE
 * reader acting on "supports the ZGW API standard" has to be able to tell a
 * supplier claim from an evidenced fact. Collapsing the two is the failure
 * mode of every self-reported catalog and is forbidden here.
 *
 * The `standaardversie` relation (Decision 3) is the canonical column key.
 * `standaardGemma` (a free string) is consulted only when the relation is
 * unresolved; such records are reported separately as `unresolved` rather
 * than being silently merged into a column.
 *
 * The module is pure and framework-light so it is unit-testable and reusable
 * across the matrix page, the catalog standard-filter, and the organisation
 * coverage view.
 *
 * @module utils/complianceMatrix
 * @author Ruben Linde
 * @copyright 2026 Conduction B.V.
 * @license AGPL-3.0-or-later
 *
 * @spec openspec/specs/module-compliance-assessment/spec.md
 */

/**
 * @typedef {('verified'|'claimed'|'none')} CellState
 */

/**
 * Cell-state constants. Exported so callers never compare against bare strings.
 *
 * @type {{VERIFIED: CellState, CLAIMED: CellState, NONE: CellState}}
 */
export const CELL = Object.freeze({
	VERIFIED: 'verified',
	CLAIMED: 'claimed',
	NONE: 'none',
})

/**
 * Resolve the UUID of a related object that may be served as a bare string,
 * an object with `uuid`/`id`, or null. Mirrors the leniency of the backend
 * ModuleComplianceService::extractStandaardversieUuids().
 *
 * @param {*} value A relation value (string UUID, object, or null).
 * @return {string} The resolved UUID, or '' when none.
 *
 * @spec openspec/specs/module-compliance-assessment/spec.md
 */
export function resolveUuid(value) {
	if (typeof value === 'string') {
		return value.trim()
	}
	if (value && typeof value === 'object') {
		const uuid = value.uuid ?? value.id ?? value['@self']?.id ?? ''
		return typeof uuid === 'string' ? uuid.trim() : String(uuid || '')
	}
	return ''
}

/**
 * Decide whether a compliancy record carries usable evidence.
 *
 * Evidence is any of: a non-empty legacy base64 `bewijs` file, a non-empty
 * `bewijsReferentie` NC Files reference, or a non-empty `url`.
 *
 * @param {object} record A compliancy object's data.
 * @return {boolean} True when the record is evidenced.
 *
 * @spec openspec/specs/module-compliance-assessment/spec.md
 */
export function hasEvidence(record) {
	if (!record || typeof record !== 'object') {
		return false
	}

	const bewijs = record.bewijs
	const hasBewijs = !!bewijs
		&& (typeof bewijs === 'string'
			? bewijs.trim() !== ''
			: (typeof bewijs === 'object' ? Object.keys(bewijs).length > 0 : true))

	const ref = record.bewijsReferentie
	const hasRef = typeof ref === 'string' ? ref.trim() !== '' : !!ref

	const url = record.url
	const hasUrl = typeof url === 'string' && url.trim() !== ''

	return hasBewijs || hasRef || hasUrl
}

/**
 * Read the data bag of a record that may be a plain object or an OR object
 * envelope (`{ '@self': …, …props }`). OR object-API responses already inline
 * the properties at the top level, so we treat the record itself as the bag.
 *
 * @param {object} record A compliancy record (OR object or plain data).
 * @return {object} The property bag.
 */
function dataOf(record) {
	if (!record || typeof record !== 'object') {
		return {}
	}
	// OR responses inline properties alongside `@self`; nested `object` only
	// appears in some legacy shapes — prefer it when present.
	if (record.object && typeof record.object === 'object') {
		return record.object
	}
	return record
}

/**
 * Partition compliancy records into (a) those resolvable to a standaardversie
 * UUID and (b) those that only carry an unresolved `standaardGemma` string.
 *
 * @param {Array<object>} records Compliancy records (OR objects or data bags).
 * @return {{resolved: Array<{moduleUuid: string, standaardversieUuid: string, evidenced: boolean, record: object}>, unresolved: Array<{moduleUuid: string, standaardGemma: string, evidenced: boolean, record: object}>}}
 *
 * @spec openspec/specs/module-compliance-assessment/spec.md
 */
export function partitionCompliancy(records) {
	const resolved = []
	const unresolved = []

	for (const record of (records || [])) {
		const data = dataOf(record)
		const moduleUuid = resolveUuid(data.module)
		const standaardversieUuid = resolveUuid(data.standaardversie)
		const evidenced = hasEvidence(data)

		if (standaardversieUuid !== '') {
			resolved.push({ moduleUuid, standaardversieUuid, evidenced, record })
			continue
		}

		const standaardGemma = typeof data.standaardGemma === 'string' ? data.standaardGemma.trim() : ''
		if (standaardGemma !== '') {
			unresolved.push({ moduleUuid, standaardGemma, evidenced, record })
		}
		// Records with neither a resolved relation nor a string are dropped —
		// they cannot be placed in any column and carry no buyer-facing signal.
	}

	return { resolved, unresolved }
}

/**
 * Combine two cell states for the same (module, standard) pair, keeping the
 * strongest signal. verified > claimed > none. Two compliancy records for the
 * same pair therefore render verified if either is evidenced.
 *
 * @param {CellState} a First state.
 * @param {CellState} b Second state.
 * @return {CellState} The strongest of the two.
 */
function strongest(a, b) {
	if (a === CELL.VERIFIED || b === CELL.VERIFIED) {
		return CELL.VERIFIED
	}
	if (a === CELL.CLAIMED || b === CELL.CLAIMED) {
		return CELL.CLAIMED
	}
	return CELL.NONE
}

/**
 * Build the compliance matrix for a set of modules and selected standards.
 *
 * The result is filter-first: only the columns in `standaardversies` are
 * produced (Decision 4 — no cartesian wall). Every (module, standard) pair
 * gets a cell; pairs with no compliancy record render `none`.
 *
 * @param {object}        params                  Mapper input.
 * @param {Array<object>} params.modules          Module objects (need `uuid`/`id` + `naam`).
 * @param {Array<object>} params.standaardversies Selected standard objects (need `uuid`/`id` + `naam`/`titel`).
 * @param {Array<object>} params.compliancy       Compliancy records (OR objects or data bags).
 * @return {{rows: Array<{module: object, moduleUuid: string, cells: {[key: string]: {state: CellState, record: (object|null)}}}>, columns: Array<{uuid: string, label: string}>, unresolved: Array<object>}}
 *
 * @spec openspec/specs/module-compliance-assessment/spec.md
 */
export function buildComplianceMatrix({ modules = [], standaardversies = [], compliancy = [] } = {}) {
	const { resolved, unresolved } = partitionCompliancy(compliancy)

	// Index resolved records by `${moduleUuid}::${standaardversieUuid}`.
	const index = new Map()
	for (const entry of resolved) {
		const key = `${entry.moduleUuid}::${entry.standaardversieUuid}`
		const state = entry.evidenced ? CELL.VERIFIED : CELL.CLAIMED
		const existing = index.get(key)
		if (existing === undefined) {
			index.set(key, { state, record: entry.record })
		} else {
			const merged = strongest(existing.state, state)
			// Prefer to surface an evidenced record when the cell becomes verified.
			const record = (merged === CELL.VERIFIED && entry.evidenced) ? entry.record : existing.record
			index.set(key, { state: merged, record })
		}
	}

	const columns = standaardversies.map((standard) => ({
		uuid: resolveUuid(standard.uuid ?? standard.id ?? standard),
		label: standardLabel(standard),
	}))

	const rows = modules.map((module) => {
		const moduleUuid = resolveUuid(module.uuid ?? module.id ?? module)
		const cells = {}
		for (const column of columns) {
			const hit = index.get(`${moduleUuid}::${column.uuid}`)
			cells[column.uuid] = hit !== undefined
				? { state: hit.state, record: hit.record }
				: { state: CELL.NONE, record: null }
		}
		return { module, moduleUuid, cells }
	})

	return { rows, columns, unresolved }
}

/**
 * Human label for a standard object. Falls back through the common GEMMA
 * element name fields.
 *
 * @param {object} standard A standaardversie object.
 * @return {string} A display label.
 *
 * @spec openspec/specs/module-compliance-assessment/spec.md
 */
export function standardLabel(standard) {
	if (!standard || typeof standard !== 'object') {
		return String(standard || '')
	}
	return standard.naam || standard.titel || standard.title || standard.label
		|| resolveUuid(standard.uuid ?? standard.id ?? '')
}

/**
 * Compute per-organisation coverage of a single standard across the
 * organisation's in-use applications (gebruiken → modules → compliancy).
 *
 * For each gebruik, the module's support for the standard is resolved to
 * verified / claimed / none. Applications whose module has no compliancy
 * record for the standard are listed as `none` — never omitted (the absence
 * is itself a finding).
 *
 * @param {object}        params                   Coverage input.
 * @param {Array<object>} params.gebruiken         The organisation's gebruik objects (carry `module`).
 * @param {string}        params.standaardversieUuid The standard to report on.
 * @param {Array<object>} params.compliancy        Compliancy records.
 * @param {{[key: string]: object}} [params.moduleIndex] Optional UUID→module lookup for labels.
 * @return {Array<{gebruik: object, moduleUuid: string, module: (object|null), state: CellState}>}
 *
 * @spec openspec/specs/module-compliance-assessment/spec.md
 */
export function buildOrganisationCoverage({ gebruiken = [], standaardversieUuid = '', compliancy = [], moduleIndex = {} } = {}) {
	const { resolved } = partitionCompliancy(compliancy)
	const targetUuid = resolveUuid(standaardversieUuid)

	// module → strongest cell state for the target standard.
	const moduleState = new Map()
	for (const entry of resolved) {
		if (entry.standaardversieUuid !== targetUuid) {
			continue
		}
		const state = entry.evidenced ? CELL.VERIFIED : CELL.CLAIMED
		moduleState.set(entry.moduleUuid, strongest(moduleState.get(entry.moduleUuid) ?? CELL.NONE, state))
	}

	return (gebruiken || []).map((gebruik) => {
		const data = dataOf(gebruik)
		const moduleUuid = resolveUuid(data.module)
		return {
			gebruik,
			moduleUuid,
			module: moduleIndex[moduleUuid] ?? null,
			state: moduleState.get(moduleUuid) ?? CELL.NONE,
		}
	})
}
