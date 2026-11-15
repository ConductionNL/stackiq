// @vitest-environment jsdom

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The two settings-section shells and the tab strip the settings pages use.
 *
 * CollapsibleSection: starts collapsed unless told otherwise, toggles, hides
 * its buttons while loading, disables save while saving or when it cannot
 * save, and emits save / refresh. AlwaysVisibleSection: always shows its
 * body (or the loading text), keeps its buttons but disables them while
 * loading. Both open the info modal from the (i) button and fill it from
 * `info-content` or, failing that, `info`. StandardTabs marks the active tab
 * and asks for another one by key.
 */

import { mount } from '@vue/test-utils'
import { describe, expect, it } from 'vitest'
import AlwaysVisibleSection from '../../src/components/AlwaysVisibleSection.vue'
import CollapsibleSection from '../../src/components/CollapsibleSection.vue'
import StandardTabs from '../../src/components/StandardTabs.vue'

const stubs = {
	NcSettingsSection: { props: ['name'], template: '<section><slot /></section>' },
	NcButton: {
		props: ['disabled', 'variant'],
		emits: ['click'],
		template: '<button :disabled="disabled" :data-variant="variant" @click="$emit(\'click\')"><slot name="icon" /><slot /></button>',
	},
	NcLoadingIcon: { template: '<i class="loading" />' },
	CollapsibleSectionInfoModal: { emits: ['close'], template: '<div class="info-modal"><slot /></div>' },
	AlwaysVisibleSectionInfoModal: {
		props: ['show'],
		emits: ['close'],
		template: '<div v-if="show" class="info-modal"><slot /></div>',
	},
}

const buttonWithText = (wrapper, text) => wrapper.findAll('button').find((b) => b.text().trim() === text)

describe('CollapsibleSection', () => {
	const mountSection = (props = {}, slots = {}) =>
		mount(CollapsibleSection, {
			props: { name: 'Mail', ...props },
			slots: { default: '<p class="body">Body</p>', ...slots },
			global: { stubs },
		})

	it('starts collapsed and toggles open and closed', async () => {
		const wrapper = mountSection()
		expect(wrapper.find('.body').exists()).toBe(false)

		const toggle = wrapper.find('button[aria-label="Expand section"]')
		await toggle.trigger('click')
		expect(wrapper.find('.body').exists()).toBe(true)
		expect(wrapper.find('button[aria-label="Collapse section"]').exists()).toBe(true)

		await wrapper.find('button[aria-label="Collapse section"]').trigger('click')
		expect(wrapper.find('.body').exists()).toBe(false)
	})

	it('can start expanded, and shows the loading icon instead of the body while loading', () => {
		const wrapper = mountSection({ defaultExpanded: true, loading: true, showSaveButton: true, showRefreshButton: true })

		expect(wrapper.find('.body').exists()).toBe(false)
		expect(wrapper.find('.loading').exists()).toBe(true)
		expect(buttonWithText(wrapper, 'Save')).toBeUndefined()
		expect(buttonWithText(wrapper, 'Refresh')).toBeUndefined()
	})

	it('emits save and refresh from its buttons with their custom texts', async () => {
		const wrapper = mountSection({ showSaveButton: true, showRefreshButton: true, saveButtonText: 'Opslaan', refreshButtonText: 'Vernieuwen' })

		await buttonWithText(wrapper, 'Opslaan').trigger('click')
		await buttonWithText(wrapper, 'Vernieuwen').trigger('click')

		expect(wrapper.emitted('save')).toHaveLength(1)
		expect(wrapper.emitted('refresh')).toHaveLength(1)
	})

	it('disables save while saving or when it cannot save, and refresh while refreshing', () => {
		const saving = mountSection({ showSaveButton: true, showRefreshButton: true, saving: true, refreshing: true })
		expect(saving.findAll('button')[0].attributes('disabled')).toBeDefined()
		expect(saving.findAll('button')[1].attributes('disabled')).toBeDefined()
		expect(saving.findAll('.loading')).toHaveLength(2)

		const blocked = mountSection({ showSaveButton: true, canSave: false })
		expect(buttonWithText(blocked, 'Save').attributes('disabled')).toBeDefined()
	})

	it('opens the info modal with info-content, falling back to info and then a default', async () => {
		const withContent = mountSection({ hasInfoContent: true }, { 'info-content': '<p class="info">Content</p>' })
		expect(withContent.find('.info-modal').exists()).toBe(false)
		await withContent.find('button[aria-label="Show information about Mail"]').trigger('click')
		expect(withContent.find('.info-modal').text()).toBe('Content')

		const withInfo = mountSection({ hasInfoContent: true }, { info: '<p>Alias</p>' })
		await withInfo.find('button[aria-label="Show information about Mail"]').trigger('click')
		expect(withInfo.find('.info-modal').text()).toBe('Alias')

		const empty = mountSection({ hasInfoContent: true })
		await empty.find('button[aria-label="Show information about Mail"]').trigger('click')
		expect(empty.find('.info-modal').text()).toBe('No additional information available.')

		await empty.findComponent(stubs.CollapsibleSectionInfoModal).vm.$emit('close')
		expect(empty.find('.info-modal').exists()).toBe(false)
	})
})

describe('AlwaysVisibleSection', () => {
	const mountSection = (props = {}, slots = {}) =>
		mount(AlwaysVisibleSection, {
			props: { name: 'Users', ...props },
			slots: { default: '<p class="body">Body</p>', ...slots },
			global: { stubs },
		})

	it('always shows its body, or the loading text while loading', () => {
		expect(mountSection().find('.body').exists()).toBe(true)

		const loading = mountSection({ loading: true, loadingText: 'Bezig...' })
		expect(loading.find('.body').exists()).toBe(false)
		expect(loading.find('.loading-text').text()).toBe('Bezig...')
	})

	it('keeps its buttons while loading but disables them', () => {
		const wrapper = mountSection({ loading: true, showSaveButton: true, showRefreshButton: true })

		expect(buttonWithText(wrapper, 'Save').attributes('disabled')).toBeDefined()
		expect(buttonWithText(wrapper, 'Refresh').attributes('disabled')).toBeDefined()
	})

	it('emits save and refresh', async () => {
		const wrapper = mountSection({ showSaveButton: true, showRefreshButton: true })

		await buttonWithText(wrapper, 'Save').trigger('click')
		await buttonWithText(wrapper, 'Refresh').trigger('click')

		expect(wrapper.emitted('save')).toHaveLength(1)
		expect(wrapper.emitted('refresh')).toHaveLength(1)
	})

	it('shows the info modal from info-content or info, and renders header actions', async () => {
		const wrapper = mountSection(
			{ hasInfoContent: true },
			{ 'info-content': '<p>Content</p>', 'header-actions': '<a class="extra">Extra</a>' },
		)
		expect(wrapper.find('.extra').exists()).toBe(true)
		expect(wrapper.find('.info-modal').exists()).toBe(false)

		await wrapper.find('button[aria-label="Show information about Users"]').trigger('click')
		expect(wrapper.find('.info-modal').text()).toBe('Content')

		await wrapper.findComponent(stubs.AlwaysVisibleSectionInfoModal).vm.$emit('close')
		expect(wrapper.find('.info-modal').exists()).toBe(false)

		const alias = mountSection({ hasInfoContent: true }, { info: '<p>Alias</p>' })
		await alias.find('button[aria-label="Show information about Users"]').trigger('click')
		expect(alias.find('.info-modal').text()).toBe('Alias')
	})
})

describe('StandardTabs', () => {
	it('marks the active tab and asks for another by key', async () => {
		const wrapper = mount(StandardTabs, {
			props: { tabs: [{ key: 'a', title: 'Alpha' }, { key: 'b', title: 'Beta' }], activeTab: 'a' },
			slots: { default: '<p class="panel">Panel</p>' },
		})

		const buttons = wrapper.findAll('.tab-button')
		expect(buttons.map((b) => b.text())).toEqual(['Alpha', 'Beta'])
		expect(buttons[0].classes()).toContain('active')
		expect(buttons[1].classes()).not.toContain('active')
		expect(wrapper.find('.panel').exists()).toBe(true)

		await buttons[1].trigger('click')
		expect(wrapper.emitted('update:active-tab')).toEqual([['b']])
	})
})
