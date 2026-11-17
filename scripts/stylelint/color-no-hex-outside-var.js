/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * stylelint rule `stackiq/color-no-hex`: a hex colour is allowed only as the
 * fallback of a `var()`, so the look comes from the Nextcloud theme and not
 * from the code (platform-properties REQ-PLAT-003). Stylelint's own
 * `color-no-hex` also refuses `var(--color-success, #46ba61)`, which is the
 * one form the requirement keeps.
 *
 * @spec openspec/changes/platform-properties
 */
const stylelint = require('stylelint')

const ruleName = 'stackiq/color-no-hex'
const messages = stylelint.utils.ruleMessages(ruleName, {
	rejected: (hex) => `Unexpected hex colour "${hex}" outside a var() fallback; use a Nextcloud theme variable`,
})

const HEX = /#(?:[0-9a-fA-F]{8}|[0-9a-fA-F]{6}|[0-9a-fA-F]{3,4})(?![0-9a-zA-Z_-])/g

/**
 * Blank out every `var(...)` call (nested calls included) so only the hex
 * colours outside a fallback remain, keeping offsets intact.
 *
 * @spec openspec/changes/platform-properties
 * @param {string} value A declaration value.
 * @return {string} The value with each var() call replaced by spaces.
 */
function maskVarCalls(value) {
	let out = ''
	let i = 0
	while (i < value.length) {
		if (value.slice(i, i + 4).toLowerCase() === 'var(') {
			let depth = 0
			let j = i
			for (; j < value.length; j++) {
				if (value[j] === '(') depth++
				else if (value[j] === ')') {
					depth--
					if (depth === 0) break
				}
			}
			out += ' '.repeat(Math.min(j + 1, value.length) - i)
			i = j + 1
			continue
		}
		out += value[i]
		i++
	}
	return out
}

/**
 * Find the hex colours of a declaration value that are not var() fallbacks.
 *
 * @spec openspec/changes/platform-properties
 * @param {string} value A declaration value.
 * @return {Array<{hex: string, index: number}>} Each offending colour.
 */
function findHexOutsideVar(value) {
	const masked = maskVarCalls(value)
	const found = []
	for (const match of masked.matchAll(HEX)) {
		found.push({ hex: match[0], index: match.index })
	}
	return found
}

/**
 * The rule: report every hex colour of a declaration that is not a var()
 * fallback.
 *
 * @spec openspec/changes/platform-properties
 * @param {boolean} primary The rule is on.
 * @return {Function} The stylelint rule callback.
 */
function rule(primary) {
	return (root, result) => {
		if (!stylelint.utils.validateOptions(result, ruleName, { actual: primary })) {
			return
		}
		root.walkDecls((decl) => {
			for (const { hex, index } of findHexOutsideVar(decl.value)) {
				const start = decl.prop.length + (decl.raws.between || ':').length + index
				stylelint.utils.report({
					ruleName,
					result,
					node: decl,
					message: messages.rejected(hex),
					index: start,
					endIndex: start + hex.length,
				})
			}
		})
	}
}

rule.ruleName = ruleName
rule.messages = messages

module.exports = stylelint.createPlugin(ruleName, rule)
module.exports.findHexOutsideVar = findHexOutsideVar
