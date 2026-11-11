/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * OpenRegister's data API write routes require Nextcloud's request token.
 * Every raw fetch() in src/ that writes to /apps/openregister/api must send
 * the `requesttoken` header (or go through @nextcloud/axios or the
 * nextcloud-vue buildHeaders() helper, which add it).
 */

import fs from 'fs'
import path from 'path'
import { describe, expect, it } from 'vitest'

const SRC = path.resolve(__dirname, '../../src')
const WRITE = /method\s*:\s*[^,}]*\b(POST|PUT|PATCH|DELETE)\b/i
const TOKEN = /requesttoken|getRequestToken|OCS-APIRequest|buildHeaders\(/i

/**
 * Every .js/.vue/.ts file under a directory, specs excluded.
 *
 * @param {string} dir - The directory
 * @return {string[]} The file paths
 */
function sourceFiles(dir) {
	return fs.readdirSync(dir, { withFileTypes: true }).flatMap((entry) => {
		const full = path.join(dir, entry.name)
		if (entry.isDirectory()) {
			return entry.name === '__snapshots__' ? [] : sourceFiles(full)
		}
		return /\.(js|vue|ts)$/.test(entry.name) && !/\.(spec|test)\./.test(entry.name) ? [full] : []
	})
}

/**
 * The text of every fetch(...) call in a source, up to its closing paren.
 *
 * @param {string} text - The source
 * @return {Array<{line: number, call: string, before: string}>} The calls
 */
function fetchCalls(text) {
	const calls = []
	const re = /(?<![\w.])fetch\s*\(/g
	let match
	while ((match = re.exec(text)) !== null) {
		let i = re.lastIndex
		let depth = 1
		while (i < text.length && depth > 0) {
			if ('([{'.includes(text[i])) depth++
			else if (')]}'.includes(text[i])) depth--
			i++
		}
		calls.push({
			line: text.slice(0, match.index).split('\n').length,
			call: text.slice(match.index, i),
			before: text.slice(Math.max(0, match.index - 600), match.index),
		})
	}
	return calls
}

describe('fetch() writes to OpenRegister send the request token', () => {
	it('has no token-less write call', () => {
		const missing = []
		for (const file of sourceFiles(SRC)) {
			const text = fs.readFileSync(file, 'utf8')
			for (const { line, call, before } of fetchCalls(text)) {
				const targetsOpenRegister = call.includes('openregister') || before.includes('openregister/api')
				if (targetsOpenRegister && WRITE.test(call) && !TOKEN.test(call)) {
					missing.push(`${path.relative(SRC, file)}:${line}`)
				}
			}
		}
		expect(missing).toEqual([])
	})
})
