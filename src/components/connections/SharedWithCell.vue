<!--
SPDX-License-Identifier: EUPL-1.2
Copyright (C) 2026 Conduction B.V.

SharedWithCell.vue

Table cell widget `sharedWith`: on Connections, the column Shared with lists
the organisations the connection is shared with through the usages that hold
it ("Alblasserdam, Papendrecht"), or "nobody". Empty while the lookup is
unknown (loading, or refused for a user without a gebruik role).

@spec openspec/changes/archive/2026-11-24-shared-usage-visible-to-participants/specs/deelnames-gebruik/spec.md#requirement-the-connections-list-says-with-whom-a-connection-is-shared-req-dlg-011
-->

<template>
	<span v-if="organisations !== null" class="shared-with-cell" data-testid="shared-with-cell">{{
		organisations.length === 0 ? t('stackiq', 'nobody') : organisations.map(nameOf).join(', ')
	}}</span>
</template>

<script>
import { translate as t } from '@nextcloud/l10n'
import { loadSharedConnections, sharedWithOf } from '../../utils/sharedConnections.js'
import { relationId } from '../../utils/sharedUsage.js'

export default {
	name: 'SharedWithCell',

	props: {
		/** The connection row. */
		row: {
			type: Object,
			default: null,
		},
	},

	data() {
		return {
			organisations: null,
			names: {},
		}
	},

	computed: {
		/**
		 * @return {string} The connection uuid.
		 * @spec openspec/changes/archive/2026-11-24-shared-usage-visible-to-participants/specs/deelnames-gebruik/spec.md#requirement-the-connections-list-says-with-whom-a-connection-is-shared-req-dlg-011
		 */
		connectionId() {
			return relationId(this.row)
		},
	},

	watch: {
		connectionId: {
			immediate: true,
			/**
			 * Look up with whom the connection is shared, then the organisations' names.
			 *
			 * @param {string} id The connection uuid.
			 * @return {Promise<void>}
			 * @spec openspec/changes/archive/2026-11-24-shared-usage-visible-to-participants/specs/deelnames-gebruik/spec.md#requirement-the-connections-list-says-with-whom-a-connection-is-shared-req-dlg-011
			 */
			async handler(id) {
				this.organisations = null
				this.names = {}
				if (!id) {
					return
				}
				const organisations = sharedWithOf(await loadSharedConnections(), id)
				if (id !== this.connectionId) {
					return
				}
				this.organisations = organisations
				if (organisations && organisations.length > 0) {
					await this.loadNames(organisations)
				}
			},
		},
	},

	methods: {
		t,

		/**
		 * Fetch the organisation names; one the reader cannot fetch keeps its uuid.
		 *
		 * @param {string[]} ids The organisation uuids.
		 * @return {Promise<void>}
		 * @spec openspec/changes/archive/2026-11-24-shared-usage-visible-to-participants/specs/deelnames-gebruik/spec.md#requirement-the-connections-list-says-with-whom-a-connection-is-shared-req-dlg-011
		 */
		async loadNames(ids) {
			try {
				// Loaded on demand: the cell registry is imported where the store is not wanted.
				const [{ objectStore }, { ensureObjectTypeOrSlug }] = await Promise.all([
					import('../../store/store.js'),
					import('../../utils/objectTypes.js'),
				])
				ensureObjectTypeOrSlug('organization')
				const names = {}
				await Promise.all(ids.map(async (id) => {
					try {
						const organisation = await objectStore.fetchObject('organization', id)
						if (typeof organisation?.name === 'string') {
							names[id] = organisation.name
						}
					} catch {
						// Not readable: the uuid stays.
					}
				}))
				this.names = names
			} catch {
				// No store: the uuids stay.
			}
		},

		/**
		 * @param {string} id An organisation uuid.
		 * @return {string} Its name, else the uuid.
		 * @spec openspec/changes/archive/2026-11-24-shared-usage-visible-to-participants/specs/deelnames-gebruik/spec.md#requirement-the-connections-list-says-with-whom-a-connection-is-shared-req-dlg-011
		 */
		nameOf(id) {
			return this.names[id] || id
		},
	},
}
</script>
