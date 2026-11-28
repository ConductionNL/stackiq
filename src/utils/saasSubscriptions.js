/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * saasSubscriptions — every usage whose hosting is SaaS, with its supplier, the
 * yearly cost of its contracts and who bought it (capability-rows-the-code-does-
 * not-yet-meet, REQ-CLR-001).
 *
 * A subscription is a usage whose `cloudDienstverleningsmodel` includes SaaS,
 * whatever its lifecycle phase: a subscription a department pays for is spend,
 * also before it is in production. The yearly cost CONSUMES
 * contract-administration's `totalAnnualisedCost` over the contracts linked to
 * the usage (one-off costs stay out of it); it is null when no contract is
 * linked. Nothing is stored; every row is derived when the page renders.
 *
 * @spec openspec/changes/capability-rows-the-code-does-not-yet-meet/specs/saas-subscriptions/spec.md#requirement-the-licences-page-lists-saas-subscriptions-with-their-yearly-cost-including-ones-bought-outside-it-req-clr-001
 */

import { totalAnnualisedCost } from './contractCost.js'
import { dataOf, resolveUuid } from './lifecyclePhase.js'

/** The hosting value that makes a usage a subscription. */
export const SAAS = 'SaaS'

/**
 * Whether a usage is hosted as SaaS.
 *
 * @param {object} usage A usage record.
 * @return {boolean}
 * @spec openspec/changes/capability-rows-the-code-does-not-yet-meet/specs/saas-subscriptions/spec.md#requirement-the-licences-page-lists-saas-subscriptions-with-their-yearly-cost-including-ones-bought-outside-it-req-clr-001
 */
export function isSaas(usage) {
	const hosting = dataOf(usage).cloudDienstverleningsmodel
	const values = Array.isArray(hosting) ? hosting : [hosting]
	return values.includes(SAAS)
}

/**
 * Whether a usage was bought outside IT.
 *
 * @param {object} usage A usage record.
 * @return {boolean}
 * @spec openspec/changes/capability-rows-the-code-does-not-yet-meet/specs/saas-subscriptions/spec.md#requirement-the-licences-page-lists-saas-subscriptions-with-their-yearly-cost-including-ones-bought-outside-it-req-clr-001
 */
export function isBoughtOutsideIt(usage) {
	return dataOf(usage).boughtOutsideIt === true
}

/**
 * The id of a record.
 *
 * @param {object} record A record.
 * @return {string} Its uuid, or empty.
 */
function idOf(record) {
	return resolveUuid(record?.uuid ?? record?.id ?? record?.['@self']?.id ?? dataOf(record).id ?? '')
}

/**
 * The SaaS subscriptions as rows, optionally only those bought outside IT.
 *
 * @param {object}        sources           The records.
 * @param {Array<object>} sources.usages    Usage records.
 * @param {Array<object>} sources.contracts Contract records.
 * @param {Array<object>} sources.modules   Module records, for the supplier.
 * @param {boolean}       [outsideItOnly]   Keep only the subscriptions bought outside IT.
 * @return {Array<{usageId: string, moduleId: string, supplierId: string, consumerId: string, department: string, boughtOutsideIt: boolean, annualCost: (number|null)}>}
 *   One row per subscription, the highest yearly cost first, unknown costs last.
 * @spec openspec/changes/capability-rows-the-code-does-not-yet-meet/specs/saas-subscriptions/spec.md#requirement-the-licences-page-lists-saas-subscriptions-with-their-yearly-cost-including-ones-bought-outside-it-req-clr-001
 */
export function saasSubscriptionRows({ usages, contracts, modules }, outsideItOnly = false) {
	const supplierOf = {}
	for (const module of modules || []) {
		supplierOf[idOf(module)] = resolveUuid(dataOf(module).provider)
	}
	const contractsOf = {}
	for (const contract of contracts || []) {
		const usageId = resolveUuid(dataOf(contract).usage)
		if (usageId !== '') {
			(contractsOf[usageId] = contractsOf[usageId] || []).push(contract)
		}
	}

	return (usages || [])
		.filter((usage) => isSaas(usage) && (!outsideItOnly || isBoughtOutsideIt(usage)))
		.map((usage) => {
			const data = dataOf(usage)
			const usageId = idOf(usage)
			const moduleId = resolveUuid(data.module)
			const linked = contractsOf[usageId] || []
			return {
				usageId,
				moduleId,
				supplierId: supplierOf[moduleId] || '',
				consumerId: resolveUuid(data.consumer),
				department: data.buyingDepartment || '',
				boughtOutsideIt: isBoughtOutsideIt(usage),
				annualCost: linked.length > 0 ? totalAnnualisedCost(linked).annual : null,
			}
		})
		.sort((a, b) => (b.annualCost ?? -1) - (a.annualCost ?? -1))
}
