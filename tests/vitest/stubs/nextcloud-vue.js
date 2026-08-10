/**
 * SPDX-FileCopyrightText: 2026 Conduction / SoftwareCatalog Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Vitest stub for `@nextcloud/vue`.
 *
 * The OFFLINE vitest suite has no Nextcloud runtime, and the real package is a
 * large ESM bundle that expects one. These stubs are deliberately minimal: each
 * one renders a SINGLE root element and passes its slots straight through, so
 * attribute/listener fallthrough behaves the way the real components do and a
 * test can click a button or look inside a modal.
 *
 * `NcModal` honours `show` the way the real component does (default `true`,
 * nothing rendered when false) — the section specs depend on the info panel
 * being absent until the (i) button is pressed.
 *
 * Anything asserted THROUGH these stubs must be app-owned behaviour (which slot
 * a section forwards, which button toggles what), never @nextcloud/vue's own.
 */

import { defineComponent, h } from 'vue'

/**
 * Build a stub that renders one root element and forwards the default slot.
 *
 * @param {string} name - Component name.
 * @param {string} tag - Root element tag to render.
 * @param {string} className - Class applied to the root element.
 * @return {object} A Vue component definition.
 */
function passthrough(name, tag, className) {
	return defineComponent({
		name,
		setup(props, { slots }) {
			return () => h(tag, { class: className }, slots.default ? slots.default() : [])
		},
	})
}

export const NcSettingsSection = defineComponent({
	name: 'NcSettingsSection',
	props: {
		name: { type: String, default: '' },
		description: { type: String, default: '' },
		docUrl: { type: String, default: '' },
	},
	setup(props, { slots }) {
		return () => h('section', { class: 'settings-section' }, slots.default ? slots.default() : [])
	},
})

export const NcButton = defineComponent({
	name: 'NcButton',
	props: {
		variant: { type: String, default: 'secondary' },
		disabled: { type: Boolean, default: false },
	},
	setup(props, { slots }) {
		return () => h(
			'button',
			{ disabled: props.disabled },
			[
				slots.icon ? slots.icon() : null,
				slots.default ? slots.default() : null,
			],
		)
	},
})

export const NcModal = defineComponent({
	name: 'NcModal',
	props: {
		// The real NcModal is open unless told otherwise.
		show: { type: Boolean, default: true },
		title: { type: String, default: '' },
		name: { type: String, default: '' },
	},
	setup(props, { slots }) {
		return () => (props.show
			? h('div', { class: 'modal-stub', 'data-modal-title': props.title }, slots.default ? slots.default() : [])
			: null)
	},
})

export const NcLoadingIcon = passthrough('NcLoadingIcon', 'span', 'loading-icon-stub')
export const NcNoteCard = passthrough('NcNoteCard', 'div', 'note-card-stub')
export const NcEmptyContent = passthrough('NcEmptyContent', 'div', 'empty-content-stub')
export const NcActions = passthrough('NcActions', 'div', 'actions-stub')
export const NcActionButton = passthrough('NcActionButton', 'button', 'action-button-stub')
export const NcDialog = passthrough('NcDialog', 'div', 'dialog-stub')
export const NcTextField = passthrough('NcTextField', 'input', 'text-field-stub')
export const NcCheckboxRadioSwitch = passthrough('NcCheckboxRadioSwitch', 'label', 'checkbox-stub')
