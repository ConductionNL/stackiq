// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// color-no-hex-outside-var.js: a stylelint rule that refuses a hex colour in
// a declaration unless it is the fallback of a `var()`.
//
// Colours come from the Nextcloud theme variables, so an organisation's house
// style reaches every page. `color: var(--color-error-text, #c62828)` passes,
// `color: #c62828` fails, and so does a hex next to a var() rather than in
// its fallback (`border: 1px solid #000, var(--x)`).
//
// @spec openspec/specs/platform-properties/spec.md#requirement-the-look-comes-from-the-theme-not-the-code-req-plat-003

const valueParser = require('postcss-value-parser')
const stylelint = require('stylelint')

const ruleName = 'stackiq/color-no-hex-outside-var'
const messages = stylelint.utils.ruleMessages(ruleName, {
	rejected: (hex) => `Unexpected hex colour "${hex}" outside a var() fallback; use a theme variable such as var(--color-main-text, ${hex})`,
})

const HEX = /^#(?:[0-9a-f]{3,4}|[0-9a-f]{6}|[0-9a-f]{8})$/i

/**
 * The hex colours in a declaration value that are not inside the fallback of
 * a `var()`.
 *
 * @spec openspec/specs/platform-properties/spec.md#requirement-the-look-comes-from-the-theme-not-the-code-req-plat-003
 * @param {string} value The declaration value.
 * @return {string[]} The offending hex colours, in order.
 */
function hexOutsideVarFallback(value) {
	const found = []
	const walk = (nodes, inFallback) => {
		for (const node of nodes) {
			if (node.type === 'word' && !inFallback && HEX.test(node.value)) {
				found.push(node.value)
			}
			if (node.type === 'function' && node.nodes) {
				if (node.value.toLowerCase() === 'var') {
					const comma = node.nodes.findIndex((n) => n.type === 'div' && n.value === ',')
					walk(node.nodes.slice(0, comma === -1 ? node.nodes.length : comma), inFallback)
					if (comma !== -1) {
						walk(node.nodes.slice(comma + 1), true)
					}
				} else {
					walk(node.nodes, inFallback)
				}
			}
		}
	}
	walk(valueParser(value).nodes, false)
	return found
}

/**
 * The rule: reports every hex colour that is not a var() fallback.
 *
 * @spec openspec/specs/platform-properties/spec.md#requirement-the-look-comes-from-the-theme-not-the-code-req-plat-003
 * @param {boolean} primary Whether the rule is on.
 * @return {(root: object, result: object) => void} The PostCSS walker.
 */
function rule(primary) {
	return (root, result) => {
		if (!stylelint.utils.validateOptions(result, ruleName, { actual: primary, possible: [true] }) || !primary) {
			return
		}
		root.walkDecls((decl) => {
			if (decl.prop.startsWith('--')) {
				// A custom property's own value is where a theme defines a colour.
				return
			}
			for (const hex of hexOutsideVarFallback(decl.value)) {
				stylelint.utils.report({ ruleName, result, node: decl, word: hex, message: messages.rejected(hex) })
			}
		})
	}
}

rule.ruleName = ruleName
rule.messages = messages

module.exports = stylelint.createPlugin(ruleName, rule)
module.exports.hexOutsideVarFallback = hexOutsideVarFallback
