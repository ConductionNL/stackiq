/**
 * Display helpers for a moderation-queue data bag.
 *
 * The pending-queue items come straight from the OpenRegister object envelope
 * (an arbitrary registration object), so the UI cannot assume a fixed field.
 * These helpers pick a human title + a supporting subtitle from the most likely
 * fields, falling back to the uuid so an item is never rendered blank. Pure +
 * unit-tested so the index view never has to assert this logic itself.
 *
 * @spec openspec/specs/open-data-publishing/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

const TITLE_FIELDS = ['name', 'titel', 'title', 'organization', 'organisation']
const SUBTITLE_FIELDS = [
	'email',
	'contactEmail',
	'url',
	'website',
	'description',
	'description',
]

/**
 * A field value as trimmed text, or '' when it is not a filled string.
 *
 * @param {*} value The field value.
 * @return {string} The text.
 */
function filledText(value) {
	return typeof value === 'string' && value.trim() !== '' ? value.trim() : ''
}

/**
 * The trimmed texts of a set of fields, in field order.
 *
 * @param {object} bag The registration data bag.
 * @param {Array<string>} fields The fields.
 * @return {Array<string>} The texts ('' for an unfilled field).
 */
function fieldTexts(bag, fields) {
	return fields.map((field) => filledText(bag[field]))
}

/**
 * Pick the best human-readable title for a moderation item.
 *
 * @param {object} item - The registration data bag.
 * @return {string} A non-empty display title.
 * @spec openspec/specs/open-data-publishing/spec.md
 */
export function moderationItemTitle(item) {
	const bag = item || {}
	const title = fieldTexts(bag, TITLE_FIELDS).find((text) => text !== '')
	if (title) {
		return title
	}
	const id = bag.id || bag.uuid
	return id ? String(id) : 'Untitled registration'
}

/**
 * Pick a supporting subtitle (contact/url/description) for a moderation item,
 * never duplicating the chosen title.
 *
 * @param {object} item - The registration data bag.
 * @return {string} A subtitle, or '' when none is available.
 * @spec openspec/specs/open-data-publishing/spec.md
 */
export function moderationItemSubtitle(item) {
	const bag = item || {}
	const title = moderationItemTitle(bag)
	const subtitle = fieldTexts(bag, SUBTITLE_FIELDS).find(
		(text) => text !== '' && text !== title,
	)
	return subtitle || ''
}
