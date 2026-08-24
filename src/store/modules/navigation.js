/* eslint-disable no-console */
import { defineStore } from 'pinia'

export const useNavigationStore = defineStore('ui', {
	state: () => ({
		// The currently active menu item, defaults to 'organisaties' since that is the primary page users interact with.
		selected: 'organisaties',
		// The currently selected organisatie within 'organisaties'
		selectedOrganisatie: null,
		// The currently active modal, managed through the state to ensure that only one modal can be active at the same time
		modal: null,
		// The currently active dialog
		dialog: null,
		// Properties for the active dialog
		dialogProperties: null,
		// Any data needed in various models, dialogs, views which cannot be transferred through normal means or without writing crappy/excessive code
		transferData: null,
	}),
	actions: {
		/**
		 * @param selected
		 * @spec openspec/specs/fe-stores/spec.md
		 */
		setSelected(selected) {
			this.selected = selected
			console.log('Active menu item set to ' + selected)
		},
		/**
		 * @param selectedOrganisatie
		 * @spec openspec/specs/fe-stores/spec.md
		 */
		setSelectedOrganisatie(selectedOrganisatie) {
			this.selectedOrganisatie = selectedOrganisatie
			console.log('Active organisatie menu set to ' + selectedOrganisatie)
		},
		/**
		 * @param modal
		 * @spec openspec/specs/fe-stores/spec.md
		 */
		setModal(modal) {
			this.modal = modal
			console.log('Active modal set to ' + modal)
		},
		/**
		 * @param dialog
		 * @param properties
		 * @spec openspec/specs/fe-stores/spec.md
		 */
		setDialog(dialog, properties) {
			this.dialog = dialog
			this.dialogProperties = properties || null
			console.log(
				'Active dialog set to ' + dialog,
				properties ? 'with properties' : '',
			)
		},
		setTransferData(transferData) {
			this.transferData = transferData
		},
		/**
		 * @spec openspec/specs/fe-stores/spec.md
		 */
		getTransferData() {
			const tempData = this.transferData
			this.transferData = null
			return tempData
		},
	},
})
