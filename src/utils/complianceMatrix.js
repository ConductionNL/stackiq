/**
 * complianceMatrix — pure data mapper for the module ✕ standard compliance matrix.
 *
 * "Does application X support standard Y?" is the procurement question a
 * municipal buyer asks a software catalog. This module turns the raw catalog
 * data — modules, compliancy records, and a selection of standard versions —
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
 * The `standaardversie` relation (Decision 3) is the canonical column key for
 * the standards matrix; `standardGemma` (a free string) is consulted only
 * when the relation is unresolved, and such records are reported separately
 * as `unresolved` rather than being silently merged into a column. The
 * `bioMaatregel` relation (bio-compliance-assessment) is the parallel
 * canonical key for the BIO-measure matrix — selected via `columnSource`,
 * never a second mapper. A record that carries BOTH a `standaardversie` and
 * a `bioMaatregel` relation is a data-quality issue: it is reported
 * separately as `conflicted` and matched to neither column
 * (module-compliance-assessment, "A record with both relations set is
 * flagged, not matched").
 *
 * The module is pure and framework-light so it is unit-testable and reusable
 * across the matrix page, the catalog standard-filter, and the organisation
 * coverage view.
 *
 * @module utils/complianceMatrix
 * @author Ruben Linde
 * @copyright 2026 Conduction B.V.
 * @license EUPL-1.2
 *
 * @spec openspec/specs/module-compliance-assessment/spec.md
 * @spec openspec/specs/bio-compliance-assessment/spec.md
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
 * @typedef {('standard_version'|'bioMaatregel')} ColumnSource
 */

/**
 * Column-source constants for the matrix / coverage mappers. Exported so
 * callers never compare against bare strings.
 *
 * @type {{STANDAARDVERSIE: ColumnSource, BIO_MAATREGEL: ColumnSource}}
 */
export const COLUMN_SOURCE = Object.freeze({
	STANDAARDVERSIE: 'standard_version',
	BIO_MAATREGEL: 'bioMaatregel',
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

	const bewijs = record.evidence
	const hasBewijs =
		!!bewijs
		&& (typeof bewijs === 'string'
			? bewijs.trim() !== ''
			: typeof bewijs === 'object'
				? Object.keys(bewijs).length > 0
				: true)

	const ref = record.evidenceReference
	const hasRef = typeof ref === 'string' ? ref.trim() !== '' : !!ref

	const url = record.url
	const hasUrl = typeof url === 'string' && url.trim() !== ''

	return hasBewijs || hasRef || hasUrl
}

/**
 * Read the data bag of a record that may be a plain object or an OR object
 * envelope (`{ '@self': …, …props }`). OR object-API responses already inline
 * the properties at the top level, so we treat the record itself as the bag.
 * Exported for reuse by callers that unwrap other object types (e.g.
 * `gebruik` records) the same way.
 *
 * @param {object} record A record (OR object or plain data).
 * @return {object} The property bag.
 * @spec openspec/specs/module-compliance-assessment/spec.md
 */
export function dataOf(record) {
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
 * Partition compliancy records for a given column source into (a) those
 * resolvable to a column UUID, (b) those that only carry an unresolved
 * `standardGemma` string (standaardversie source only — BIO measures have
 * no string-fallback field), and (c) those that carry BOTH a
 * `standaardversie` and a `bioMaatregel` relation — a data-quality conflict
 * matched to neither column regardless of the requested column source.
 *
 * @param {Array<object>} records      Compliancy records (OR objects or data bags).
 * @param {ColumnSource}  [columnSource] Which relation to key on. Defaults to standaardversie.
 * @return {{resolved: Array<{moduleUuid: string, columnUuid: string, evidenced: boolean, record: object}>, unresolved: Array<{moduleUuid: string, standardGemma: string, evidenced: boolean, record: object}>, conflicted: Array<{moduleUuid: string, standaardversieUuid: string, bioMaatregelUuid: string, record: object}>}}
 *
 * @spec openspec/specs/module-compliance-assessment/spec.md
 * @spec openspec/specs/bio-compliance-assessment/spec.md
 */
export function partitionCompliancy(
	records,
	columnSource = COLUMN_SOURCE.STANDAARDVERSIE,
) {
	const resolved = []
	const unresolved = []
	const conflicted = []

	for (const record of records || []) {
		const data = dataOf(record)
		const moduleUuid = resolveUuid(data.module)
		const standaardversieUuid = resolveUuid(data.standard_version)
		const bioMaatregelUuid = resolveUuid(data.bioMaatregel)
		const evidenced = hasEvidence(data)

		// A record naming both a standard and a BIO measure is a data-quality
		// issue — flag it and match it to neither column (module-compliance-
		// assessment, "A record with both relations set is flagged, not
		// matched").
		if (standaardversieUuid !== '' && bioMaatregelUuid !== '') {
			conflicted.push({
				moduleUuid,
				standaardversieUuid,
				bioMaatregelUuid,
				record,
			})
			continue
		}

		if (columnSource === COLUMN_SOURCE.BIO_MAATREGEL) {
			if (bioMaatregelUuid !== '') {
				resolved.push({
					moduleUuid,
					columnUuid: bioMaatregelUuid,
					evidenced,
					record,
				})
			}
			// A record with only a standaardversie (or neither) is not
			// applicable to the BIO matrix — dropped, same as the inverse
			// case below.
			continue
		}

		if (standaardversieUuid !== '') {
			resolved.push({
				moduleUuid,
				columnUuid: standaardversieUuid,
				evidenced,
				record,
			})
			continue
		}

		// The variable name is the KEY, because the push below uses shorthand.
		// The rename updated the read (`data.standardGemma`) and the consumers, but
		// a shorthand property has no `name:` for a key-rewrite to match, so this
		// object kept emitting `standaardGemma` while everything reading it had
		// moved on.
		const standardGemma =
			typeof data.standardGemma === 'string' ? data.standardGemma.trim() : ''
		if (standardGemma !== '') {
			unresolved.push({ moduleUuid, standardGemma, evidenced, record })
		}
		// Records with neither a resolved relation nor a string are dropped —
		// they cannot be placed in any column and carry no buyer-facing signal.
	}

	return { resolved, unresolved, conflicted }
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
 * Build the compliance matrix for a set of modules and a selected set of
 * columns (standard versions, or — since bio-compliance-assessment — BIO
 * measures).
 *
 * The result is filter-first: only the given columns are produced
 * (Decision 4 — no cartesian wall). Every (module, column) pair gets a
 * cell; pairs with no compliancy record render `none`.
 *
 * @param {object}        params                   Mapper input.
 * @param {Array<object>} params.modules           Module objects (need `uuid`/`id` + `naam`).
 * @param {Array<object>} [params.standardVersions] Selected column objects when columnSource is standaardversie (need `uuid`/`id` + `naam`/`titel`). Back-compat alias for `columns`.
 * @param {Array<object>} [params.columns]          Selected column objects (standaardversie or bioMaatregel, per columnSource). Preferred over `standardVersions` for new callers.
 * @param {Array<object>} params.compliancy        Compliancy records (OR objects or data bags).
 * @param {ColumnSource}  [params.columnSource]     Which relation to key on. Defaults to standaardversie.
 * @return {{rows: Array<{module: object, moduleUuid: string, cells: {[key: string]: {state: CellState, record: (object|null)}}}>, columns: Array<{uuid: string, label: string}>, unresolved: Array<object>, conflicted: Array<object>}}
 *
 * @spec openspec/specs/module-compliance-assessment/spec.md
 * @spec openspec/specs/bio-compliance-assessment/spec.md
 */
export function buildComplianceMatrix({
	modules = [],
	standardVersions,
	columns: columnObjectsParam,
	compliancy = [],
	columnSource = COLUMN_SOURCE.STANDAARDVERSIE,
} = {}) {
	const columnObjects = columnObjectsParam ?? standardVersions ?? []
	const { resolved, unresolved, conflicted } = partitionCompliancy(
		compliancy,
		columnSource,
	)

	// Index resolved records by `${moduleUuid}::${columnUuid}`.
	const index = new Map()
	for (const entry of resolved) {
		const key = `${entry.moduleUuid}::${entry.columnUuid}`
		const state = entry.evidenced ? CELL.VERIFIED : CELL.CLAIMED
		const existing = index.get(key)
		if (existing === undefined) {
			index.set(key, { state, record: entry.record })
		} else {
			const merged = strongest(existing.state, state)
			// Prefer to surface an evidenced record when the cell becomes verified.
			const record =
				merged === CELL.VERIFIED && entry.evidenced
					? entry.record
					: existing.record
			index.set(key, { state: merged, record })
		}
	}

	const columns = columnObjects.map((column) => ({
		uuid: resolveUuid(column.uuid ?? column.id ?? column),
		label: columnLabel(column),
	}))

	const rows = modules.map((module) => {
		const moduleUuid = resolveUuid(module.uuid ?? module.id ?? module)
		const cells = {}
		for (const column of columns) {
			const hit = index.get(`${moduleUuid}::${column.uuid}`)
			cells[column.uuid] =
				hit !== undefined
					? { state: hit.state, record: hit.record }
					: { state: CELL.NONE, record: null }
		}
		return { module, moduleUuid, cells }
	})

	return { rows, columns, unresolved, conflicted }
}

/**
 * Human label for a column object (a standaardversie `element` or a
 * `bioMaatregel`). Falls back through the common name fields shared by
 * both schemas.
 *
 * @param {object} column A standaardversie or bioMaatregel object.
 * @return {string} A display label.
 *
 * @spec openspec/specs/module-compliance-assessment/spec.md
 * @spec openspec/specs/bio-compliance-assessment/spec.md
 */
export function columnLabel(column) {
	if (!column || typeof column !== 'object') {
		return String(column || '')
	}
	return (
		column.name
		|| column.titel
		|| column.title
		|| column.label
		|| resolveUuid(column.uuid ?? column.id ?? '')
	)
}

/**
 * @deprecated Back-compat alias for {@link columnLabel} — kept because
 * existing callers import `standardLabel` directly.
 *
 * @param {object} standard A standaardversie or bioMaatregel object.
 * @return {string} A display label.
 *
 * @spec openspec/specs/module-compliance-assessment/spec.md
 */
export function standardLabel(standard) {
	return columnLabel(standard)
}

/**
 * Compute per-organisation coverage of a single column (a standard version,
 * or — since bio-compliance-assessment — a BIO measure) across the
 * organisation's in-use applications (usages → modules → compliancy).
 *
 * For each gebruik, the module's support for the column is resolved to
 * verified / claimed / none. Applications whose module has no compliancy
 * record for the column are listed as `none` — never omitted (the absence
 * is itself a finding).
 *
 * @param {object}        params                   Coverage input.
 * @param {Array<object>} params.usages         The organisation's gebruik objects (carry `module`).
 * @param {string}        [params.standaardversieUuid] The standard to report on. Back-compat alias for `columnUuid`.
 * @param {string}        [params.columnUuid]      The standard/measure UUID to report on. Preferred over `standaardversieUuid` for new callers.
 * @param {Array<object>} params.compliancy        Compliancy records.
 * @param {ColumnSource}  [params.columnSource]     Which relation to key on. Defaults to standaardversie.
 * @param {{[key: string]: object}} [params.moduleIndex] Optional UUID→module lookup for labels.
 * @return {Array<{gebruik: object, moduleUuid: string, module: (object|null), state: CellState}>}
 *
 * @spec openspec/specs/module-compliance-assessment/spec.md
 * @spec openspec/specs/bio-compliance-assessment/spec.md
 */
export function buildOrganisationCoverage({
	usages = [],
	standaardversieUuid,
	columnUuid,
	compliancy = [],
	columnSource = COLUMN_SOURCE.STANDAARDVERSIE,
	moduleIndex = {},
} = {}) {
	const { resolved } = partitionCompliancy(compliancy, columnSource)
	const targetUuid = resolveUuid(columnUuid ?? standaardversieUuid ?? '')

	// module → strongest cell state for the target column.
	const moduleState = new Map()
	for (const entry of resolved) {
		if (entry.columnUuid !== targetUuid) {
			continue
		}
		const state = entry.evidenced ? CELL.VERIFIED : CELL.CLAIMED
		moduleState.set(
			entry.moduleUuid,
			strongest(moduleState.get(entry.moduleUuid) ?? CELL.NONE, state),
		)
	}

	return (usages || []).map((gebruik) => {
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
