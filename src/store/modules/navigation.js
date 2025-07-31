/* eslint-disable no-console */
import { defineStore } from 'pinia'

export const useNavigationStore = defineStore('ui', {
	state: () => ({
		// The currently active menu item, defaults to 'dashboard' which triggers the dashboard
		selected: 'dashboard',
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
		setSelected(selected) {
			this.selected = selected
			console.log('Active menu item set to ' + selected)
		},
		setSelectedOrganisatie(selectedOrganisatie) {
			this.selectedOrganisatie = selectedOrganisatie
			console.log('Active organisatie menu set to ' + selectedOrganisatie)
		},
		setModal(modal) {
			this.modal = modal
			console.log('Active modal set to ' + modal)
		},
		setDialog(dialog, properties) {
			this.dialog = dialog
			this.dialogProperties = properties || null
			console.log('Active dialog set to ' + dialog, properties ? 'with properties' : '')
		},
		setTransferData(transferData) {
			this.transferData = transferData
		},
		getTransferData() {
			const tempData = this.transferData
			this.transferData = null
			return tempData
		},
	},
})
