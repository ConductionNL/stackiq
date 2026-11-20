<!--
  - SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
  - SPDX-License-Identifier: EUPL-1.2
  -->
<template>
	<CnWidgetWrapper
		:title="t('stackiq', 'Attributes')"
		titleIconPosition="left"
		:showRefresh="false"
		:showRequestFeature="false">
		<template #title-icon>
			<CnIcon name="NoteTextOutline" :size="20" />
		</template>
		<div class="data-entity-attributes" data-testid="data-entity-attributes">
			<NcLoadingIcon
				v-if="loading"
				:size="32"
				:name="t('stackiq', 'Loading the attributes')" />
			<NcNoteCard v-else-if="loadError" type="error">
				{{ t('stackiq', 'The attributes could not be loaded.') }}
			</NcNoteCard>
			<template v-else>
				<NcNoteCard v-if="!own" type="info" data-testid="data-entity-attributes-imported">
					{{ t('stackiq', 'The GGM does not publish attributes in this file.') }}
				</NcNoteCard>
				<NcNoteCard v-if="message" :type="message.type" data-testid="data-entity-attributes-message">
					{{ message.text }}
				</NcNoteCard>

				<form v-if="editing" class="data-entity-attributes__form" @submit.prevent="save">
					<fieldset
						v-for="(row, index) in rows"
						:key="row.key"
						class="data-entity-attributes__row"
						data-testid="data-entity-attribute-row">
						<legend class="hidden-visually">
							{{ t('stackiq', 'Attribute {number}', { number: index + 1 }) }}
						</legend>
						<NcTextField
							:modelValue="row.name"
							:label="t('stackiq', 'Name')"
							@update:modelValue="row.name = $event" />
						<NcSelect
							v-model="row.dataType"
							:inputLabel="t('stackiq', 'Data type')"
							:options="dataTypes"
							:reduce="(option) => option.id"
							label="label"
							:clearable="false" />
						<NcCheckboxRadioSwitch
							:modelValue="row.isKey"
							@update:modelValue="row.isKey = $event">
							{{ t('stackiq', 'Part of the key') }}
						</NcCheckboxRadioSwitch>
						<NcTextField
							:modelValue="row.description"
							:label="t('stackiq', 'Description')"
							@update:modelValue="row.description = $event" />
						<NcButton
							variant="tertiary"
							:aria-label="t('stackiq', 'Remove attribute {number}', { number: index + 1 })"
							@click="remove(index)">
							{{ t('stackiq', 'Remove') }}
						</NcButton>
					</fieldset>
					<div class="data-entity-attributes__actions">
						<NcButton @click="addRow">
							{{ t('stackiq', 'Add attribute') }}
						</NcButton>
						<NcButton type="submit" variant="primary" :disabled="saving">
							{{ t('stackiq', 'Save attributes') }}
						</NcButton>
						<NcButton variant="tertiary" :disabled="saving" @click="cancel">
							{{ t('stackiq', 'Cancel') }}
						</NcButton>
					</div>
				</form>

				<template v-else>
					<p v-if="attributes.length === 0 && own" class="data-entity-attributes__empty">
						{{ t('stackiq', 'No attributes yet.') }}
					</p>
					<table v-if="attributes.length" class="data-entity-attributes__table" data-testid="data-entity-attributes-table">
						<caption class="hidden-visually">
							{{ t('stackiq', 'Attributes') }}
						</caption>
						<thead>
							<tr>
								<th scope="col">
									{{ t('stackiq', 'Name') }}
								</th>
								<th scope="col">
									{{ t('stackiq', 'Data type') }}
								</th>
								<th scope="col">
									{{ t('stackiq', 'Key') }}
								</th>
								<th scope="col">
									{{ t('stackiq', 'Description') }}
								</th>
							</tr>
						</thead>
						<tbody>
							<tr v-for="attribute in attributes" :key="attribute.name" data-testid="data-entity-attribute">
								<td>{{ attribute.name }}</td>
								<td>{{ dataTypeLabel(attribute.dataType) }}</td>
								<td>
									<span v-if="attribute.isKey" class="data-entity-attributes__key">{{ t('stackiq', 'key') }}</span>
								</td>
								<td>{{ attribute.description }}</td>
							</tr>
						</tbody>
					</table>
					<NcButton v-if="own" data-testid="data-entity-attributes-edit" @click="edit">
						{{ t('stackiq', 'Edit attributes') }}
					</NcButton>
				</template>
			</template>
		</div>
	</CnWidgetWrapper>
</template>

<script>
import { CnIcon, CnWidgetWrapper } from '@conduction/nextcloud-vue'
import axios from '@nextcloud/axios'
import { translate as t } from '@nextcloud/l10n'
import { NcButton, NcCheckboxRadioSwitch, NcLoadingIcon, NcNoteCard, NcSelect, NcTextField } from '@nextcloud/vue'
import { ATTRIBUTE_DATA_TYPES, createDataEntityApi, isOwnEntity, sortedAttributes } from '../../utils/dataEntityGraph.js'

let rowKey = 0

/**
 * A row the editor holds.
 *
 * @param {object} attribute The attribute.
 * @return {object}
 */
function toRow(attribute) {
	rowKey += 1
	return { key: rowKey, name: attribute.name ?? '', dataType: attribute.dataType || 'text', isKey: attribute.isKey === true, description: attribute.description ?? '' }
}

/**
 * The attributes of a data entity, the key attributes first and marked "key"
 * in text. An own entity (`origin` drawn) can be edited; an imported GGM entity
 * shows that the GGM file publishes no attributes, and no edit control.
 *
 * @spec openspec/changes/architecture-data-model-and-ggm/specs/data-model-and-ggm/spec.md#requirement-req-dmg-004-a-municipality-shall-add-its-own-data-entities-with-attributes-keys-and-cardinalities
 */
export default {
	name: 'DataEntityAttributes',

	components: {
		CnIcon,
		CnWidgetWrapper,
		NcButton,
		NcCheckboxRadioSwitch,
		NcLoadingIcon,
		NcNoteCard,
		NcSelect,
		NcTextField,
	},

	props: {
		/** The entity's uuid. */
		objectId: {
			type: String,
			required: true,
		},

		/** The AMEF register (resolved from `@resolve:amef_register`). */
		register: {
			type: [String, Number],
			required: true,
		},

		/** An axios-like client; the specs pass a fake. */
		http: {
			type: Object,
			default: () => axios,
		},
	},

	data() {
		return {
			loading: true,
			loadError: false,
			saving: false,
			editing: false,
			message: null,
			entity: null,
			rows: [],
		}
	},

	computed: {
		/**
		 * @return {object} The reads and the write over the AMEF register.
		 *
		 * @spec openspec/changes/architecture-data-model-and-ggm/specs/data-model-and-ggm/spec.md#requirement-req-dmg-004-a-municipality-shall-add-its-own-data-entities-with-attributes-keys-and-cardinalities
		 */
		api() {
			return createDataEntityApi({ http: this.http, register: this.register })
		},

		/**
		 * @return {boolean} Whether the entity is the organisation's own.
		 *
		 * @spec openspec/changes/architecture-data-model-and-ggm/specs/data-model-and-ggm/spec.md#requirement-req-dmg-004-a-municipality-shall-add-its-own-data-entities-with-attributes-keys-and-cardinalities
		 */
		own() {
			return isOwnEntity(this.entity)
		},

		/**
		 * @return {Array<object>} The attributes, key attributes first.
		 *
		 * @spec openspec/changes/architecture-data-model-and-ggm/specs/data-model-and-ggm/spec.md#requirement-req-dmg-004-a-municipality-shall-add-its-own-data-entities-with-attributes-keys-and-cardinalities
		 */
		attributes() {
			return sortedAttributes(this.entity)
		},

		/**
		 * @return {Array<{id: string, label: string}>} The data types.
		 *
		 * @spec openspec/changes/architecture-data-model-and-ggm/specs/data-model-and-ggm/spec.md#requirement-req-dmg-004-a-municipality-shall-add-its-own-data-entities-with-attributes-keys-and-cardinalities
		 */
		dataTypes() {
			return ATTRIBUTE_DATA_TYPES.map((id) => ({ id, label: this.dataTypeLabel(id) }))
		},
	},

	watch: {
		/**
		 * @spec openspec/changes/architecture-data-model-and-ggm/specs/data-model-and-ggm/spec.md#requirement-req-dmg-004-a-municipality-shall-add-its-own-data-entities-with-attributes-keys-and-cardinalities
		 */
		objectId() {
			this.load()
		},
	},

	mounted() {
		this.load()
	},

	methods: {
		t,

		/**
		 * Load the entity.
		 *
		 * @return {Promise<void>}
		 *
		 * @spec openspec/changes/architecture-data-model-and-ggm/specs/data-model-and-ggm/spec.md#requirement-req-dmg-004-a-municipality-shall-add-its-own-data-entities-with-attributes-keys-and-cardinalities
		 */
		async load() {
			this.loading = true
			this.loadError = false
			this.editing = false
			try {
				this.entity = await this.api.loadEntity(this.objectId)
			} catch {
				this.loadError = true
			} finally {
				this.loading = false
			}
		},

		/**
		 * Start editing an own entity's attributes.
		 *
		 * @return {void}
		 *
		 * @spec openspec/changes/architecture-data-model-and-ggm/specs/data-model-and-ggm/spec.md#requirement-req-dmg-004-a-municipality-shall-add-its-own-data-entities-with-attributes-keys-and-cardinalities
		 */
		edit() {
			if (!this.own) return
			this.message = null
			this.rows = this.attributes.map(toRow)
			if (this.rows.length === 0) this.addRow()
			this.editing = true
		},

		/**
		 * @return {void}
		 *
		 * @spec openspec/changes/architecture-data-model-and-ggm/specs/data-model-and-ggm/spec.md#requirement-req-dmg-004-a-municipality-shall-add-its-own-data-entities-with-attributes-keys-and-cardinalities
		 */
		addRow() {
			this.rows.push(toRow({}))
		},

		/**
		 * @param {number} index The row to remove.
		 * @return {void}
		 *
		 * @spec openspec/changes/architecture-data-model-and-ggm/specs/data-model-and-ggm/spec.md#requirement-req-dmg-004-a-municipality-shall-add-its-own-data-entities-with-attributes-keys-and-cardinalities
		 */
		remove(index) {
			this.rows.splice(index, 1)
		},

		/**
		 * @return {void}
		 *
		 * @spec openspec/changes/architecture-data-model-and-ggm/specs/data-model-and-ggm/spec.md#requirement-req-dmg-004-a-municipality-shall-add-its-own-data-entities-with-attributes-keys-and-cardinalities
		 */
		cancel() {
			this.editing = false
			this.rows = []
		},

		/**
		 * Store the attributes and show them, key attributes first.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/changes/architecture-data-model-and-ggm/specs/data-model-and-ggm/spec.md#requirement-req-dmg-004-a-municipality-shall-add-its-own-data-entities-with-attributes-keys-and-cardinalities
		 */
		async save() {
			this.saving = true
			this.message = null
			try {
				const saved = await this.api.saveAttributes(this.entity, this.rows)
				this.entity = saved && typeof saved === 'object' && Array.isArray(saved.attributes) ? saved : { ...this.entity, attributes: saved?.attributes ?? [] }
				this.editing = false
				this.message = { type: 'success', text: t('stackiq', 'The attributes are saved.') }
			} catch {
				this.message = { type: 'error', text: t('stackiq', 'The attributes could not be saved.') }
			} finally {
				this.saving = false
			}
		},

		/**
		 * @param {string} type The data type.
		 * @return {string}
		 *
		 * @spec openspec/changes/architecture-data-model-and-ggm/specs/data-model-and-ggm/spec.md#requirement-req-dmg-004-a-municipality-shall-add-its-own-data-entities-with-attributes-keys-and-cardinalities
		 */
		dataTypeLabel(type) {
			return {
				text: t('stackiq', 'Text'),
				number: t('stackiq', 'Number'),
				date: t('stackiq', 'Date'),
				boolean: t('stackiq', 'Yes or no'),
				reference: t('stackiq', 'Reference'),
			}[type] || type
		},
	},
}
</script>

<style scoped>
.data-entity-attributes__table {
	width: 100%;
	border-collapse: collapse;
	margin-bottom: 12px;
}

.data-entity-attributes__table th,
.data-entity-attributes__table td {
	padding: 6px 8px;
	border-bottom: 1px solid var(--color-border);
	text-align: start;
	color: var(--color-main-text);
}

.data-entity-attributes__key {
	font-weight: 600;
}

.data-entity-attributes__row {
	display: flex;
	flex-wrap: wrap;
	align-items: flex-end;
	gap: 8px;
	padding: 8px 0;
	border: none;
	border-bottom: 1px solid var(--color-border);
}

.data-entity-attributes__actions {
	display: flex;
	gap: 8px;
	margin-top: 12px;
}

.data-entity-attributes__empty {
	color: var(--color-text-maxcontrast);
}
</style>
