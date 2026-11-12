/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The property rows of the object view: a schema property's value or its
 * default by type, the order the rows are shown in, and the basic rows of an
 * object without any.
 *
 * @spec openspec/specs/fe-object-modals/spec.md
 */

const TYPE_DEFAULTS = {
	string: (schemaProperty) => schemaProperty.const || '',
	number: () => 0,
	integer: () => 0,
	boolean: () => false,
	array: () => [],
	object: () => ({}),
}

/**
 * The value a schema property shows when the object lacks it: its constant
 * for a string, otherwise the empty value of its type.
 *
 * @param {object} schemaProperty The schema property.
 * @return {string|number|boolean|Array|object} A new default value.
 */
export function schemaDefaultValue(schemaProperty) {
	const fallback = Object.hasOwn(TYPE_DEFAULTS, schemaProperty.type)
		? TYPE_DEFAULTS[schemaProperty.type]
		: () => ''
	return fallback(schemaProperty)
}

/**
 * The rows of the schema properties, split into those with an order and
 * those without, each row `[key, value, schemaProperty]`.
 *
 * @param {object} objectData The object.
 * @param {object} schemaProperties The schema properties.
 * @return {{withOrder: Array, withoutOrder: Array}} The rows.
 */
export function schemaPropertyRows(objectData, schemaProperties) {
	const rows = Object.entries(schemaProperties).map((entry) =>
		schemaRow(objectData, entry),
	)
	return {
		withOrder: rows.filter(hasSchemaOrder),
		withoutOrder: rows.filter((row) => !hasSchemaOrder(row)),
	}
}

/**
 * The row of one schema property: the object's value, or the default.
 *
 * @param {object} objectData The object.
 * @param {Array} entry The schema key and property.
 * @return {Array} The row `[key, value, schemaProperty]`.
 */
function schemaRow(objectData, entry) {
	const [schemaKey, schemaProperty] = entry
	const propertyValue = Object.hasOwn(objectData, schemaKey)
		? objectData[schemaKey]
		: schemaDefaultValue(schemaProperty)
	return [schemaKey, propertyValue, schemaProperty]
}

/**
 * Whether a row's schema property has an order value.
 *
 * @param {Array} row The row.
 * @return {boolean} Whether it has an order.
 */
function hasSchemaOrder(row) {
	return row[2].order !== undefined && row[2].order !== null
}

/**
 * The rows in the order the view shows them: schema properties with an order
 * (by order, then key), those without (by key), then the object's own (by
 * key), as `[key, value]` for the schema ones; the basic rows when there are none.
 *
 * @param {{withOrder: Array, withoutOrder: Array}} schemaRows The schema property rows.
 * @param {Array} additionalRows The object's own property rows.
 * @return {Array} The rows.
 */
export function orderedPropertyRows({ withOrder, withoutOrder }, additionalRows) {
	withOrder.sort(byOrderThenKey)
	withoutOrder.sort(byKey)
	additionalRows.sort(byKey)

	if (withOrder.length + withoutOrder.length + additionalRows.length === 0) {
		return basicPropertyRows()
	}

	return [
		...withOrder.map(([key, value]) => [key, value]),
		...withoutOrder.map(([key, value]) => [key, value]),
		...additionalRows,
	]
}

/**
 * Compare two rows by their schema order (ascending), then by key.
 *
 * @param {Array} a A row.
 * @param {Array} b A row.
 * @return {number} The comparison.
 */
function byOrderThenKey(a, b) {
	const orderA = a[2]?.order || 0
	const orderB = b[2]?.order || 0
	if (orderA !== orderB) {
		return orderA - orderB
	}
	return byKey(a, b)
}

/**
 * Compare two rows by key.
 *
 * @param {Array} a A row.
 * @param {Array} b A row.
 * @return {number} The comparison.
 */
function byKey(a, b) {
	return a[0].localeCompare(b[0])
}

/**
 * The rows a new object without a schema starts with.
 *
 * @return {Array} The rows.
 */
function basicPropertyRows() {
	return [
		['title', ''],
		['description', ''],
		['omschrijving', ''],
		['category', ''],
		['status', 'draft'],
	]
}
