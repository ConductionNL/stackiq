/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Small pieces of the object view's form: register and schema select
 * options, whether a schema title is usable as a display name, and the
 * content of a markdown editor.
 *
 * @spec openspec/specs/fe-object-modals/spec.md
 */

/**
 * A register or schema as a select option.
 *
 * @param {object} item The register or schema.
 * @return {{id: string, label: string}} The option.
 */
export function titleOption(item) {
	return {
		id: item.id,
		label: item.title,
	}
}

/**
 * The ids of the schemas both a register and a catalog list, in the
 * register's order.
 *
 * @param {object} register The register.
 * @param {object} catalog The catalog.
 * @return {Array<string>} The schema ids.
 */
export function sharedSchemaIds(register, catalog) {
	const registerSchemaIds = register.schemas?.map((schema) => schema.id) || []
	const catalogSchemaIds = catalog.schemas || []
	// only get schema ids where the id is in both registerSchemaIds and catalogSchemaIds
	return registerSchemaIds.filter((id) => catalogSchemaIds.includes(id))
}

/**
 * Whether a schema title can stand for its property: a non-blank string
 * that is not the "new" placeholder.
 *
 * @param {*} title The title.
 * @return {boolean} Whether it can.
 */
export function isUsableTitle(title) {
	return (
		Boolean(title)
		&& typeof title === 'string'
		&& title.trim() !== ''
		&& title.trim().toLowerCase() !== 'new'
	)
}

/**
 * The content of a markdown editor: its markdown, else its HTML, else the
 * value itself when it is a string, else ''.
 *
 * @param {*} editorInstance The editor, or a value.
 * @return {string} The content.
 */
export function editorContent(editorInstance) {
	// Always prefer markdown output over HTML
	if (typeof editorInstance?.getMarkdown === 'function') {
		return editorInstance.getMarkdown()
	}
	// Fallback to HTML if markdown not available
	if (typeof editorInstance?.getHTML === 'function') {
		return editorInstance.getHTML()
	}
	// Fallback: if it's a string, use it directly
	return typeof editorInstance === 'string' ? editorInstance : ''
}
