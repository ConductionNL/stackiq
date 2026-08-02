/**
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * The app's ONLY component test, and it exists on purpose.
 *
 * `jest.config.js` maps `^.+\.vue$` to a transform (`@vue/vue3-jest` since the
 * Vue 3 migration; `@vue/vue2-jest` before it). Jest resolves a transform lazily,
 * per matched file — so with no spec importing a `.vue` file anywhere in the
 * repo, that mapping was never exercised. It could have named a package that was
 * no longer installed, or one belonging to the wrong Vue major, and all 8 jest
 * suites would still have gone green.
 *
 * This spec mounts a real SFC through the configured transform with Vue Test
 * Utils v2, so the SFC toolchain is actually covered rather than merely declared.
 * It deliberately targets the simplest presentational component in the app: the
 * point is to exercise compile + mount + props + a computed, not this component's
 * behaviour in particular.
 *
 * @spec openspec/specs/fe-shell-navigation/spec.md
 */

import { mount } from '@vue/test-utils'
import PublishedIcon from './PublishedIcon.vue'

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
