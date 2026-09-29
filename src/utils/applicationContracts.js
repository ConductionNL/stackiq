/**
 * The contracts behind an application, and routing a catalogue row to its page.
 *
 * A contract points at a usage and a service, not at the application, so the
 * application's contracts are two hops away: through a usage of it, or
 * through a service that offers it.
 *
 * @spec openspec/changes/landscape-application-page/specs/application-page/spec.md#requirement-req-apg-003-the-application-page-lists-the-contracts-behind-the-application
 */

const PAGE = 500

/**
 * The id of an object row, wherever the API put it.
 *
 * @param {object} row The row
 * @return {string|null} The id
 */
function rowId(row) {
	return row?.id ?? row?.['@self']?.id ?? row?.uuid ?? null
}

/**
 * Load every readable contract behind an application, once each.
 *
 * @param {string} applicationId The application (module) id
 * @param {Function} fetchList (type, params) resolving to the rows the user may read
 * @return {Promise<object[]>} The contracts
 * @spec openspec/changes/landscape-application-page/specs/application-page/spec.md#requirement-req-apg-003-the-application-page-lists-the-contracts-behind-the-application
 */
export async function loadApplicationContracts(applicationId, fetchList) {
	const usages = await fetchList('usage', { module: applicationId, _limit: PAGE })
	const services = await fetchList('catalogService', {
		modules: applicationId,
		_limit: PAGE,
	})
	const usageIds = (usages ?? []).map(rowId).filter(Boolean)
	const serviceIds = (services ?? []).map(rowId).filter(Boolean)

	const lists = []
	if (usageIds.length > 0) {
		lists.push(
			await fetchList('catalogContract', { usage: usageIds, _limit: PAGE }),
		)
	}
	if (serviceIds.length > 0) {
		lists.push(
			await fetchList('catalogContract', {
				service: serviceIds,
				_limit: PAGE,
			}),
		)
	}

	const byId = new Map()
	for (const contract of lists.flat()) {
		const id = rowId(contract)
		if (id && !byId.has(id)) {
			byId.set(id, contract)
		}
	}
	return [...byId.values()]
}

/**
 * Where a click on a catalogue row goes: the page's detail route, or nowhere.
 *
 * @param {string} detailRoute The named route of the detail page, or empty
 * @param {object} row The clicked row
 * @return {{name: string, params: {id: string}}|null} The router location
 * @spec openspec/changes/landscape-application-page/specs/application-page/spec.md#requirement-req-apg-004-the-applications-list-opens-the-application-page
 */
export function rowDetailLocation(detailRoute, row) {
	const id = rowId(row)
	if (!detailRoute || !id) {
		return null
	}
	return { name: detailRoute, params: { id: String(id) } }
}
