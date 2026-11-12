/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */
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

import { dataOf, resolveUuid } from './lifecyclePhase.js'

export { dataOf, resolveUuid }

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
 * @typedef {('standard_version'|'bioMeasure')} ColumnSource
 */

/**
 * Column-source constants for the matrix / coverage mappers. Exported so
 * callers never compare against bare strings.
 *
 * @type {{STANDAARDVERSIE: ColumnSource, BIO_MAATREGEL: ColumnSource}}
 */
export const COLUMN_SOURCE = Object.freeze({
	STANDAARDVERSIE: 'standard_version',
	BIO_MAATREGEL: 'bioMeasure',
})

/**
 * Whether an evidence value carries something: a non-blank string, an object
 * with keys, or any other truthy value.
 *
 * @param {string|object|number|boolean|null} value The evidence value.
 * @return {boolean} True when it carries something.
 */
function hasEvidenceValue(value) {
	if (!value) {
		return false
	}
	if (typeof value === 'string') {
		return value.trim() !== ''
	}
	if (typeof value === 'object') {
		return Object.keys(value).length > 0
	}
	return true
}

/**
 * Whether an evidence reference is set: a non-blank string or any truthy value.
 *
 * @param {string|object|number|boolean|null} ref The reference.
 * @return {boolean} True when it is set.
 */
function hasReference(ref) {
	return typeof ref === 'string' ? ref.trim() !== '' : !!ref
}

/**
 * Whether a url is a non-blank string.
 *
 * @param {string|number|null} url The url.
 * @return {boolean} True when it is set.
 */
function hasUrl(url) {
	return typeof url === 'string' && url.trim() !== ''
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
	return (
		hasEvidenceValue(record.evidence)
		|| hasReference(record.evidenceReference)
		|| hasUrl(record.url)
	)
}

/**
 * The relations a compliancy record names, resolved to uuids, and whether it
 * carries evidence.
 *
 * @param {object} record The compliancy record.
 * @return {object} The record, its data and its relations.
 */
function compliancyRelations(record) {
	const data = dataOf(record)
	return {
		record,
		data,
		moduleUuid: resolveUuid(data.module),
		standaardversieUuid: resolveUuid(data.standard_version),
		bioMaatregelUuid: resolveUuid(data.bioMeasure),
		evidenced: hasEvidence(data),
	}
}

/**
 * The conflicted entry of a record that names both a standard and a BIO
 * measure, or null. Such a record is a data-quality issue and is matched to
 * neither column (module-compliance-assessment, "A record with both relations
 * set is flagged, not matched").
 *
 * @param {object} rel The record's relations.
 * @return {Array|null} The bucket and entry, or null.
 */
function conflictEntry(rel) {
	if (rel.standaardversieUuid === '' || rel.bioMaatregelUuid === '') {
		return null
	}
	const { moduleUuid, standaardversieUuid, bioMaatregelUuid, record } = rel
	return [
		'conflicted',
		{ moduleUuid, standaardversieUuid, bioMaatregelUuid, record },
	]
}

/**
 * The resolved entry of a record placed in a column.
 *
 * @param {object} rel The record's relations.
 * @param {string} columnUuid The column.
 * @return {Array} The bucket and entry.
 */
function resolvedEntry(rel, columnUuid) {
	const { moduleUuid, evidenced, record } = rel
	return ['resolved', { moduleUuid, columnUuid, evidenced, record }]
}

/**
 * The unresolved entry of a record that only names a GEMMA standard by
 * text, or null when it names none. The key is `standardGemma`, as the
 * consumers read it.
 *
 * @param {object} rel The record's relations.
 * @return {Array|null} The bucket and entry, or null.
 */
function gemmaEntry(rel) {
	const value = rel.data.standardGemma
	const standardGemma = typeof value === 'string' ? value.trim() : ''
	if (standardGemma === '') {
		return null
	}
	const { moduleUuid, evidenced, record } = rel
	return ['unresolved', { moduleUuid, standardGemma, evidenced, record }]
}

/**
 * Where a compliancy record goes. For the BIO matrix only a BIO measure
 * places it; a record with only a standaardversie (or neither) is dropped.
 * Records with neither a relation nor a GEMMA text are dropped too.
 *
 * @param {object} rel The record's relations.
 * @param {string} columnSource The column source.
 * @return {Array|null} The bucket and entry, or null when it is dropped.
 */
function placeCompliancy(rel, columnSource) {
	const conflict = conflictEntry(rel)
	if (conflict) {
		return conflict
	}
	if (columnSource === COLUMN_SOURCE.BIO_MAATREGEL) {
		return rel.bioMaatregelUuid === ''
			? null
			: resolvedEntry(rel, rel.bioMaatregelUuid)
	}
	if (rel.standaardversieUuid !== '') {
		return resolvedEntry(rel, rel.standaardversieUuid)
	}
	return gemmaEntry(rel)
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
	const buckets = { resolved: [], unresolved: [], conflicted: [] }
	for (const record of records || []) {
		const placed = placeCompliancy(compliancyRelations(record), columnSource)
		if (placed) {
			buckets[placed[0]].push(placed[1])
		}
	}
	return buckets
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
 * Resolved records by `${moduleUuid}::${columnUuid}`, each cell holding the
 * strongest state and, once verified, an evidenced record.
 *
 * @param {Array<object>} resolved The resolved entries.
 * @return {Map<string, {state: string, record: object}>} The cells.
 */
function indexCells(resolved) {
	const index = new Map()
	for (const entry of resolved) {
		const key = `${entry.moduleUuid}::${entry.columnUuid}`
		index.set(key, mergeCell(index.get(key), entry))
	}
	return index
}

/**
 * A cell after adding one resolved entry.
 *
 * @param {{state: string, record: object}|undefined} existing The cell so far.
 * @param {object} entry The resolved entry.
 * @return {{state: string, record: object}} The cell.
 */
function mergeCell(existing, entry) {
	const state = entry.evidenced ? CELL.VERIFIED : CELL.CLAIMED
	if (existing === undefined) {
		return { state, record: entry.record }
	}
	const merged = strongest(existing.state, state)
	const preferEvidenced = merged === CELL.VERIFIED && entry.evidenced
	return {
		state: merged,
		record: preferEvidenced ? entry.record : existing.record,
	}
}

/**
 * The matrix columns: uuid and label of each column object.
 *
 * @param {Array} columnObjects The column objects or ids.
 * @return {Array<{uuid: string, label: string}>} The columns.
 */
function matrixColumns(columnObjects) {
	return columnObjects.map((column) => ({
		uuid: resolveUuid(column.uuid ?? column.id ?? column),
		label: columnLabel(column),
	}))
}

/**
 * One matrix row: the module and its cell per column.
 *
 * @param {object|string} module The module.
 * @param {{columns: Array, index: Map}} grid The columns and the cell index.
 * @return {object} The row.
 */
function matrixRow(module, grid) {
	const moduleUuid = resolveUuid(module.uuid ?? module.id ?? module)
	const cells = {}
	for (const column of grid.columns) {
		const hit = grid.index.get(`${moduleUuid}::${column.uuid}`)
		cells[column.uuid] =
			hit === undefined
				? { state: CELL.NONE, record: null }
				: { state: hit.state, record: hit.record }
	}
	return { module, moduleUuid, cells }
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
	const { resolved, unresolved, conflicted } = partitionCompliancy(
		compliancy,
		columnSource,
	)
	const index = indexCells(resolved)
	const columns = matrixColumns(columnObjectsParam ?? standardVersions ?? [])
	const rows = modules.map((module) => matrixRow(module, { columns, index }))
	return { rows, columns, unresolved, conflicted }
}

/**
 * The uuid of a column object, as its last-resort label.
 *
 * @param {object} column The column.
 * @return {string} The uuid.
 */
function columnUuidLabel(column) {
	return resolveUuid(column.uuid ?? column.id ?? '')
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
	const named = [column.name, column.titel, column.title, column.label].find(
		Boolean,
	)
	return named || columnUuidLabel(column)
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
 * The strongest cell state per module for one target column.
 *
 * @param {Array<object>} resolved The resolved entries.
 * @param {string} targetUuid The column.
 * @return {Map<string, string>} The state by module uuid.
 */
function moduleStates(resolved, targetUuid) {
	const moduleState = new Map()
	for (const entry of resolved) {
		if (entry.columnUuid !== targetUuid) {
			continue
		}
		const state = entry.evidenced ? CELL.VERIFIED : CELL.CLAIMED
		const current = moduleState.get(entry.moduleUuid) ?? CELL.NONE
		moduleState.set(entry.moduleUuid, strongest(current, state))
	}
	return moduleState
}

/**
 * The coverage row of one usage: its module and that module's state.
 *
 * @param {object} gebruik The usage.
 * @param {{moduleIndex: object, moduleState: Map}} lookup The module index and the states.
 * @return {object} The row.
 */
function coverageRow(gebruik, lookup) {
	const moduleUuid = resolveUuid(dataOf(gebruik).module)
	return {
		gebruik,
		moduleUuid,
		module: lookup.moduleIndex[moduleUuid] ?? null,
		state: lookup.moduleState.get(moduleUuid) ?? CELL.NONE,
	}
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
 * @return {Array<{usage: object, moduleUuid: string, module: (object|null), state: CellState}>}
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
	const moduleState = moduleStates(resolved, targetUuid)
	return (usages || []).map((gebruik) =>
		coverageRow(gebruik, { moduleIndex, moduleState }),
	)
}
