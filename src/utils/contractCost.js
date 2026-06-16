/**
 * contractCost — annualised cost derivation for catalog contracts.
 *
 * A contract carries `kosten` (amount) and `kostenPeriode` (period). The
 * portfolio question "what does this application cost per year" needs a
 * single comparable annual figure:
 *
 *   - `Maandelijks` (monthly) → amount × 12
 *   - `Jaarlijks`   (yearly)  → amount × 1
 *   - `Eenmalig`    (one-off) → EXCLUDED from the annual figure (annualising a
 *     one-off is a lie); reported separately as a one-off.
 *
 * Derived figures are never persisted — they are computed at render time
 * (design Decision: no stored derived costs).
 *
 * @module utils/contractCost
 * @author Ruben Linde
 * @copyright 2026 Conduction B.V.
 * @license AGPL-3.0-or-later
 *
 * @spec openspec/changes/contract-administration/specs/contract-administration/spec.md
 */

/**
 * Period constants matching the contract schema's `kostenPeriode` enum.
 *
 * @type {{MONTHLY: string, YEARLY: string, ONEOFF: string}}
 */
export const PERIOD = Object.freeze({
	MONTHLY: 'Maandelijks',
	YEARLY: 'Jaarlijks',
	ONEOFF: 'Eenmalig',
})

/**
 * Read the data bag of a contract that may be an OR object envelope or plain data.
 *
 * @param {object} contract A contract record.
 * @return {object} The property bag.
 */
function dataOf(contract) {
	if (!contract || typeof contract !== 'object') {
		return {}
	}
	if (contract.object && typeof contract.object === 'object') {
		return contract.object
	}
	return contract
}

/**
 * Coerce a cost value to a finite number, or null when not derivable.
 *
 * @param {*} value A raw `kosten` value (number or numeric string).
 * @return {number|null} The numeric amount, or null.
 *
 * @spec openspec/changes/contract-administration/specs/contract-administration/spec.md
 */
export function parseAmount(value) {
	if (typeof value === 'number' && Number.isFinite(value)) {
		return value
	}
	if (typeof value === 'string' && value.trim() !== '') {
		const n = Number(value.replace(',', '.'))
		return Number.isFinite(n) ? n : null
	}
	return null
}

/**
 * Annualised cost of a single contract.
 *
 * Returns `{ annual, oneOff }`:
 *   - `annual` — the per-year figure for `Maandelijks`/`Jaarlijks`, else 0;
 *   - `oneOff` — the amount for `Eenmalig`, else 0.
 * An unknown/absent period or unparseable amount yields zeros (never throws).
 *
 * @param {object} contract A contract record (OR object or data bag).
 * @return {{annual: number, oneOff: number}} The split cost.
 *
 * @spec openspec/changes/contract-administration/specs/contract-administration/spec.md
 */
export function annualisedCost(contract) {
	const data = dataOf(contract)
	const amount = parseAmount(data.kosten)
	if (amount === null) {
		return { annual: 0, oneOff: 0 }
	}

	switch (data.kostenPeriode) {
	case PERIOD.MONTHLY:
		return { annual: amount * 12, oneOff: 0 }
	case PERIOD.YEARLY:
		return { annual: amount, oneOff: 0 }
	case PERIOD.ONEOFF:
		return { annual: 0, oneOff: amount }
	default:
		// Unknown period: do not annualise an amount we can't classify.
		return { annual: 0, oneOff: 0 }
	}
}

/**
 * Total annualised cost across a set of contracts.
 *
 * @param {Array<object>} contracts Contract records.
 * @return {{annual: number, oneOff: number}} Summed split cost.
 *
 * @spec openspec/changes/contract-administration/specs/contract-administration/spec.md
 */
export function totalAnnualisedCost(contracts) {
	return (contracts || []).reduce((acc, contract) => {
		const { annual, oneOff } = annualisedCost(contract)
		return { annual: acc.annual + annual, oneOff: acc.oneOff + oneOff }
	}, { annual: 0, oneOff: 0 })
}

/**
 * Whether a contract is a one-off (Eenmalig) — useful for marking it in lists.
 *
 * @param {object} contract A contract record.
 * @return {boolean} True when the contract's period is Eenmalig.
 *
 * @spec openspec/changes/contract-administration/specs/contract-administration/spec.md
 */
export function isOneOff(contract) {
	return dataOf(contract).kostenPeriode === PERIOD.ONEOFF
}
