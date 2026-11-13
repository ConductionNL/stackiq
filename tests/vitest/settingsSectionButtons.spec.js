// @vitest-environment jsdom

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Unit test for the header buttons of the two settings sections and the
 * standard tabs: which buttons show, what they emit, the busy icons, the
 * info modal and collapsing, and the tab the tabs component asks for.
 *
 * @spec openspec/specs/fe-shell-navigation/spec.md
 */

import { mount } from '@vue/test-utils'
import { describe, expect, it } from 'vitest'
import AlwaysVisibleSection from '../../src/components/AlwaysVisibleSection.vue'
import CollapsibleSection from '../../src/components/CollapsibleSection.vue'
import StandardTabs from '../../src/components/StandardTabs.vue'

const ButtonStub = {
	name: 'NcButton',
	props: ['disabled', 'variant', 'ariaLabel'],
	emits: ['click'],
	template: '<button :disabled="disabled" :class="variant" :aria-label="ariaLabel" @click="$emit(\'click\')"><slot name="icon" /><slot /></button>',
}

const stubs = {
	NcSettingsSection: { template: '<section><slot /></section>' },
	NcButton: ButtonStub,
	NcLoadingIcon: { name: 'NcLoadingIcon', template: '<i class="busy" />' },
	Save: { template: '<i class="save" />' },
	Refresh: { template: '<i class="refresh" />' },
	Information: true,
	ChevronUp: { template: '<i class="up" />' },
	ChevronDown: { template: '<i class="down" />' },
	AlwaysVisibleSectionInfoModal: { name: 'AlwaysVisibleSectionInfoModal', props: ['show', 'name'], emits: ['close'], template: '<div class="info-modal" />' },
	CollapsibleSectionInfoModal: { name: 'CollapsibleSectionInfoModal', props: ['name'], emits: ['close'], template: '<div class="info-modal" />' },
}

/**
 * Mount a section with the given props.
 *
 * @param {object} component The section component.
 * @param {object} props The props.
 * @return {object} The wrapper.
 */
function mountSection(component, props) {
	return mount(component, {
		props: { name: 'Mail', ...props },
		slots: { default: '<p class="body">Body</p>' },
		global: { stubs },
	})
}

describe.each([
	['AlwaysVisibleSection', AlwaysVisibleSection, {}],
	['CollapsibleSection', CollapsibleSection, { defaultExpanded: true }],
])('%s header buttons', (label, component, extra) => {
	it('shows no buttons by default', () => {
		const wrapper = mountSection(component, extra)
		expect(wrapper.find('button.primary').exists()).toBe(false)
		expect(wrapper.find('button.secondary').exists()).toBe(false)
		expect(wrapper.find('button[aria-label="Show information about Mail"]').exists()).toBe(false)
	})

	it('emits save and refresh from their buttons with their labels', async () => {
		const wrapper = mountSection(component, { ...extra, showSaveButton: true, showRefreshButton: true, saveButtonText: 'Opslaan', refreshButtonText: 'Vernieuw' })

		await wrapper.find('button.primary').trigger('click')
		await wrapper.find('button.secondary').trigger('click')

		expect(wrapper.find('button.primary').text()).toBe('Opslaan')
		expect(wrapper.find('button.secondary').text()).toBe('Vernieuw')
		expect(wrapper.emitted('save')).toHaveLength(1)
		expect(wrapper.emitted('refresh')).toHaveLength(1)
	})

	it('shows busy icons and disables the buttons while saving or refreshing', () => {
		const wrapper = mountSection(component, { ...extra, showSaveButton: true, showRefreshButton: true, saving: true, refreshing: true })

		expect(wrapper.find('button.primary .busy').exists()).toBe(true)
		expect(wrapper.find('button.primary .save').exists()).toBe(false)
		expect(wrapper.find('button.secondary .busy').exists()).toBe(true)
		expect(wrapper.find('button.primary').attributes('disabled')).toBeDefined()
		expect(wrapper.find('button.secondary').attributes('disabled')).toBeDefined()
	})

	it('disables save when it cannot save', () => {
		const wrapper = mountSection(component, { ...extra, showSaveButton: true, canSave: false })

		expect(wrapper.find('button.primary .save').exists()).toBe(true)
		expect(wrapper.find('button.primary').attributes('disabled')).toBeDefined()
	})

	it('opens the info modal from the info button and closes it on close', async () => {
		const wrapper = mountSection(component, { ...extra, hasInfoContent: true })
		const modalName = `${label}InfoModal`
		const isOpen = () => {
			const modal = wrapper.findComponent({ name: modalName })
			return modal.exists() && modal.props('show') !== false
		}

		expect(isOpen()).toBe(false)
		await wrapper.find('button[aria-label="Show information about Mail"]').trigger('click')
		expect(isOpen()).toBe(true)

		await wrapper.findComponent({ name: modalName }).vm.$emit('close')
		expect(isOpen()).toBe(false)
	})
})

describe('AlwaysVisibleSection loading', () => {
	it('replaces the content by the loading text while loading', () => {
		const loading = mountSection(AlwaysVisibleSection, { loading: true, loadingText: 'Even geduld' })
		expect(loading.find('.body').exists()).toBe(false)
		expect(loading.find('.loading-text').text()).toBe('Even geduld')

		const ready = mountSection(AlwaysVisibleSection, {})
		expect(ready.find('.body').exists()).toBe(true)
		expect(ready.find('.loading-text').exists()).toBe(false)
	})
})

describe('CollapsibleSection collapsing', () => {
	it('starts collapsed unless asked otherwise and toggles its content', async () => {
		const wrapper = mountSection(CollapsibleSection, {})
		const toggle = wrapper.find('button[aria-label="Expand section"]')

		expect(wrapper.find('.body').exists()).toBe(false)
		expect(toggle.find('.down').exists()).toBe(true)

		await toggle.trigger('click')
		expect(wrapper.find('.body').exists()).toBe(true)
		expect(wrapper.find('button[aria-label="Collapse section"] .up').exists()).toBe(true)

		await wrapper.find('button[aria-label="Collapse section"]').trigger('click')
		expect(wrapper.find('.body').exists()).toBe(false)
	})

	it('hides save and refresh while loading', () => {
		const wrapper = mountSection(CollapsibleSection, { showSaveButton: true, showRefreshButton: true, loading: true })

		expect(wrapper.find('button.primary').exists()).toBe(false)
		expect(wrapper.find('button.secondary').exists()).toBe(false)
	})
})

describe('StandardTabs', () => {
	it('renders a button per tab, marks the active one and asks for the clicked one', async () => {
		const wrapper = mount(StandardTabs, {
			props: { tabs: [{ key: 'a', title: 'Alpha' }, { key: 'b', title: 'Beta' }], activeTab: 'b' },
			slots: { default: '<p class="panel">Panel</p>' },
		})
		const buttons = wrapper.findAll('button.tab-button')

		expect(buttons.map((button) => button.text())).toEqual(['Alpha', 'Beta'])
		expect(buttons.map((button) => button.classes('active'))).toEqual([false, true])
		expect(wrapper.find('.tab-content .panel').exists()).toBe(true)

		await buttons[0].trigger('click')
		expect(wrapper.emitted('update:active-tab')).toEqual([['a']])
	})
})
