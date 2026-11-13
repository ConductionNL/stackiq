// @vitest-environment jsdom
/**
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * Mounts a real SFC with Vue Test Utils v2, so the single-file component
 * toolchain is exercised and not only declared. It targets the simplest
 * presentational component: the point is compile, mount, props and a
 * computed, not this component's behaviour in particular.
 *
 * @spec openspec/specs/fe-shell-navigation/spec.md
 */

import { mount } from '@vue/test-utils'
import { describe, expect, it } from 'vitest'
import PublishedIcon from '../../src/components/PublishedIcon.vue'

describe('PublishedIcon', () => {
	it('renders the published branch and its default tooltip', () => {
		const wrapper = mount(PublishedIcon, { props: { isPublished: true } })

		expect(wrapper.find('.published-icon-svg').exists()).toBe(true)
		expect(wrapper.find('.unpublished-icon-svg').exists()).toBe(false)
		expect(wrapper.classes()).toContain('published')
		expect(wrapper.attributes('title')).toBe('Published')
	})

	it('renders the unpublished branch and its default tooltip', () => {
		const wrapper = mount(PublishedIcon, { props: { isPublished: false } })

		expect(wrapper.find('.unpublished-icon-svg').exists()).toBe(true)
		expect(wrapper.find('.published-icon-svg').exists()).toBe(false)
		expect(wrapper.classes()).not.toContain('published')
		expect(wrapper.attributes('title')).toBe('Not Published')
	})

	it('prefers an explicit tooltip over the computed default', () => {
		const wrapper = mount(PublishedIcon, {
			props: { isPublished: true, tooltip: 'Live since 2026' },
		})

		expect(wrapper.attributes('title')).toBe('Live since 2026')
	})
})
