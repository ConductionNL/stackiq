/**
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * The CMDB import section's municipality chooser: which organisations it
 * offers, and what it sends for a typed name.
 *
 * @spec openspec/changes/cmdb-export-import/specs/cmdb-export-import/spec.md#requirement-every-import-shall-have-exactly-one-consuming-municipality-chosen-by-the-admin-req-cmdb-004
 */

import axios from '@nextcloud/axios'
import { flushPromises, shallowMount } from '@vue/test-utils'
import CmdbImport from './CmdbImport.vue'

// Virtual: the package's `exports` field declares only the `import` condition, which jest does not resolve.
jest.mock(
	'@nextcloud/axios',
	() => ({
		__esModule: true,
		default: { get: jest.fn(), post: jest.fn() },
	}),
	{ virtual: true },
)
jest.mock('@nextcloud/router', () => ({
	generateUrl: (url, params = {}) =>
		url.replace(/{(\w+)}/g, (match, key) => String(params[key] ?? match)),
	generateOcsUrl: (url) => url,
}))
// The component libraries are stubbed by shallowMount; virtual for the same `exports` reason.
jest.mock(
	'@nextcloud/vue',
	() => ({
		NcButton: { render: () => null },
		NcCheckboxRadioSwitch: { render: () => null },
		NcLoadingIcon: { render: () => null },
		NcNoteCard: { render: () => null },
		NcProgressBar: { render: () => null },
		NcSelect: { render: () => null },
	}),
	{ virtual: true },
)
jest.mock(
	'@conduction/nextcloud-vue',
	() => ({
		CnDataTable: { render: () => null },
		CnStatusBadge: { render: () => null },
	}),
	{ virtual: true },
)
// The icon SFCs live in node_modules, which jest does not transform.
jest.mock('vue-material-design-icons/Close.vue', () => ({ render: () => null }))
jest.mock('vue-material-design-icons/DatabaseImport.vue', () => ({
	render: () => null,
}))
jest.mock('vue-material-design-icons/TrayArrowUp.vue', () => ({
	render: () => null,
}))
jest.mock('../../../components/AlwaysVisibleSection.vue', () => ({
	render: () => null,
}))

const ORGANISATIONS = [
	{
		id: 'aaaaaaaa-1111',
		name: 'Gemeente Bergen',
		type: 'Municipality',
		status: 'Active',
	},
	{
		id: 'bbbbbbbb-2222',
		name: 'Gemeente Bergen',
		type: 'Municipality',
		status: 'Active',
	},
	{
		id: 'cccccccc-3333',
		name: 'Gemeente Oud',
		type: 'Municipality',
		status: 'merged',
	},
	{
		id: 'dddddddd-4444',
		name: 'Gemeente Slaap',
		type: 'Municipality',
		status: 'Inactive',
	},
]

/**
 * Mount the section with OpenRegister answering the given organisations.
 *
 * @return {Promise<object>} The wrapper, after the municipalities loaded
 */
async function mountSection() {
	axios.get.mockImplementation((url) =>
		Promise.resolve(
			url.includes('/voorzieningen/config')
				? { data: { config: { register: 7, organisatie_schema: 33 } } }
				: { data: { results: ORGANISATIONS } },
		),
	)
	const wrapper = shallowMount(CmdbImport)
	await flushPromises()
	return wrapper
}

describe('CmdbImport municipality chooser', () => {
	afterEach(() => {
		jest.clearAllMocks()
	})

	it('does not offer a merged or an inactive municipality', async () => {
		const wrapper = await mountSection()

		const ids = wrapper.vm.municipalityOptions.map((option) => option.id)
		expect(ids).toEqual(['aaaaaaaa-1111', 'bbbbbbbb-2222'])
		expect(
			new Set(wrapper.vm.municipalityOptions.map((o) => o.label)).size,
		).toBe(2)
	})

	it('posts a typed name that two municipalities share as municipalityName and shows MUNICIPALITY_AMBIGUOUS', async () => {
		const wrapper = await mountSection()
		axios.post.mockRejectedValue({
			response: {
				status: 422,
				data: {
					success: false,
					error: 'MUNICIPALITY_AMBIGUOUS',
					message:
						'Several municipalities have this name; choose one from the list.',
					details: { matches: ['aaaaaaaa-1111', 'bbbbbbbb-2222'] },
				},
			},
		})

		wrapper.vm.municipality =
			wrapper.vm.createMunicipalityOption('gemeente bergen')
		wrapper.vm.selectedFile = new File(['x'], 'export.xlsx')
		await wrapper.vm.startImport()

		expect(axios.post).toHaveBeenCalledTimes(1)
		const form = axios.post.mock.calls[0][1]
		expect(form.get('municipalityName')).toBe('gemeente bergen')
		expect(form.has('municipalityUuid')).toBe(false)
		expect(wrapper.vm.error.error).toBe('MUNICIPALITY_AMBIGUOUS')
		expect(wrapper.vm.report).toBe(null)
	})
})
