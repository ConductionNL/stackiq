/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Ask the catalogue: the request to `POST /api/ask` and the answer it gives,
 * shaped for the Ask page. The server searches OpenRegister as the caller and
 * hands only the entries it found to hermiq; this module only asks and reads.
 *
 * @spec openspec/changes/archive/2026-11-24-insight-assistant-answers-and-action-trail/specs/assistant-insight/spec.md#requirement-req-ask-001-an-answer-lists-the-catalogue-entries-it-used
 */

/** The longest question the server accepts. */
export const MAX_QUESTION = 2000

/**
 * The body for `POST /api/ask`, or null when there is nothing to ask.
 *
 * @param {string} question The question as typed.
 * @param {string|null} sessionId The conversation so far, if any.
 * @return {{question: string, sessionId: string|null}|null}
 */
export function askBody(question, sessionId = null) {
	const text = String(question ?? '').trim()
	if (text === '' || text.length > MAX_QUESTION) {
		return null
	}
	return { question: text, sessionId: sessionId || null }
}

/**
 * One entry as the page lists it: its title, its link and what kind it is.
 *
 * @param {object} entry An entry of `sources` or `searched`.
 * @return {{ref: string, title: string, url: string, schema: string, summary: string}}
 */
function listed(entry) {
	return {
		ref: String(entry?.ref ?? ''),
		title: String(entry?.title || entry?.id || entry?.ref || ''),
		url: String(entry?.url ?? ''),
		schema: String(entry?.schema ?? ''),
		summary: String(entry?.summary ?? ''),
	}
}

/**
 * The answer the page shows. `empty` is true when no catalogue entry matched,
 * in which case the server did not ask the assistant and there is no reply.
 *
 * @param {object} data The JSON `POST /api/ask` answered with.
 * @return {{sessionId: string|null, reply: string, sources: Array, searched: Array, empty: boolean}}
 */
export function readAnswer(data) {
	const searched = Array.isArray(data?.searched) ? data.searched.map(listed) : []
	const sources = Array.isArray(data?.sources) ? data.sources.map(listed) : []
	return {
		sessionId: data?.sessionId || null,
		reply: String(data?.reply ?? ''),
		sources,
		searched,
		empty: searched.length === 0,
	}
}

/**
 * What to tell the user when the question could not be answered. The server's
 * own message is used when it sent one; 503 means the assistant is not there.
 *
 * @param {object} error The axios error.
 * @param {(text: string) => string} t The translate function, bound to the app.
 * @return {string}
 */
export function askFailure(error, t) {
	const status = error?.response?.status
	const message = error?.response?.data?.error
	if (status === 503) {
		return t('The assistant is not available.')
	}
	if (status === 429) {
		return t('You asked many questions in a short time. Try again in a minute.')
	}
	if (typeof message === 'string' && message !== '') {
		return message
	}
	return t('The assistant could not answer this question.')
}
