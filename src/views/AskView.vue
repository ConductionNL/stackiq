<!--
  - SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
  - SPDX-License-Identifier: EUPL-1.2
  -
  - Ask the catalogue (insight-assistant-answers-and-action-trail D2): one
  - question, the assistant's reply, the entries it cited and, folded away, every
  - entry that was searched. The menu entry shows only when hermiq is installed
  - (manifest `visibleIf.appInstalled`); a direct visit without it reads the
  - server's 503 as "not available".
  -->
<template>
	<div class="askView">
		<h2 class="ask-title">
			{{ t('stackiq', 'Ask the catalogue') }}
		</h2>
		<p class="ask-intro">
			{{ t('stackiq', 'Ask a question about the applications, services, organisations, usages, connections, compliance and contracts in the catalogue. Only the entries you may see are searched, and only those are sent to the assistant.') }}
		</p>

		<form class="ask-form" data-testid="ask-form" @submit.prevent="submit">
			<NcTextArea
				v-model="question"
				:label="t('stackiq', 'Your question')"
				:maxlength="maxQuestion"
				resize="vertical"
				data-testid="ask-question" />
			<NcButton
				type="submit"
				variant="primary"
				:disabled="loading || !canAsk"
				data-testid="ask-submit">
				<template #icon>
					<NcLoadingIcon v-if="loading" :size="20" />
				</template>
				{{ t('stackiq', 'Ask') }}
			</NcButton>
		</form>

		<div aria-live="polite">
			<NcNoteCard v-if="failure" type="error" data-testid="ask-failure">
				{{ failure }}
			</NcNoteCard>

			<p v-else-if="answer && answer.empty" class="ask-empty" data-testid="ask-empty">
				{{ t('stackiq', 'No catalogue entries matched this question.') }}
			</p>

			<section v-else-if="answer" class="ask-answer" data-testid="ask-answer">
				<p class="ask-reply">
					{{ answer.reply }}
				</p>

				<h3 class="ask-heading">
					{{ t('stackiq', 'Sources') }}
				</h3>
				<ul v-if="answer.sources.length > 0" class="ask-list" data-testid="ask-sources">
					<li v-for="entry in answer.sources" :key="entry.ref">
						<a v-if="entry.url" :href="entry.url">{{ entry.title }}</a>
						<span v-else>{{ entry.title }}</span>
					</li>
				</ul>
				<p v-else class="ask-muted">
					{{ t('stackiq', 'The reply cites no catalogue entry.') }}
				</p>

				<details class="ask-searched" data-testid="ask-searched">
					<summary>
						{{ t('stackiq', 'Entries searched: {count}', { count: answer.searched.length }) }}
					</summary>
					<ul class="ask-list">
						<li v-for="entry in answer.searched" :key="entry.ref">
							<a v-if="entry.url" :href="entry.url">{{ entry.title }}</a>
							<span v-else>{{ entry.title }}</span>
							<span v-if="entry.summary" class="ask-muted"> — {{ entry.summary }}</span>
						</li>
					</ul>
				</details>
			</section>
		</div>
	</div>
</template>

<script>
import axios from '@nextcloud/axios'
import { translate as t } from '@nextcloud/l10n'
import { generateUrl } from '@nextcloud/router'
import { NcButton, NcLoadingIcon, NcNoteCard, NcTextArea } from '@nextcloud/vue'
import { askBody, askFailure, MAX_QUESTION, readAnswer } from '../utils/askCatalogue.js'

export default {
	name: 'AskView',
	components: { NcButton, NcLoadingIcon, NcNoteCard, NcTextArea },
	data() {
		return {
			question: '',
			sessionId: null,
			loading: false,
			answer: null,
			failure: '',
			maxQuestion: MAX_QUESTION,
		}
	},

	computed: {
		canAsk() {
			return askBody(this.question) !== null
		},
	},

	methods: {
		t,
		async submit() {
			const body = askBody(this.question, this.sessionId)
			if (body === null) {
				return
			}
			this.loading = true
			this.failure = ''
			try {
				const { data } = await axios.post(generateUrl('/apps/stackiq/api/ask'), body)
				this.answer = readAnswer(data)
				this.sessionId = this.answer.sessionId
			} catch (error) {
				this.answer = null
				this.failure = askFailure(error, (text) => t('stackiq', text))
			} finally {
				this.loading = false
			}
		},
	},
}
</script>

<style scoped>
.askView {
	padding: calc(var(--default-grid-baseline) * 4);
	max-width: 900px;
}

.ask-intro,
.ask-muted {
	color: var(--color-text-maxcontrast);
}

.ask-form {
	display: flex;
	flex-direction: column;
	align-items: flex-start;
	gap: calc(var(--default-grid-baseline) * 2);
	margin-block: calc(var(--default-grid-baseline) * 4);
}

.ask-form > :first-child {
	width: 100%;
}

.ask-reply {
	white-space: pre-wrap;
}

.ask-heading {
	margin-top: calc(var(--default-grid-baseline) * 4);
}

.ask-list {
	padding-inline-start: calc(var(--default-grid-baseline) * 5);
	list-style: disc;
}

.ask-list a {
	color: var(--color-primary-element);
	text-decoration: underline;
}

.ask-searched {
	margin-top: calc(var(--default-grid-baseline) * 4);
}
</style>
