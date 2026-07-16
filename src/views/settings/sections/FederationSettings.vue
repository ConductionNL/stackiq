<!--
 - @copyright Copyright (c) 2026 Conduction B.V. <info@conduction.nl>
 - @license AGPL-3.0-or-later
 -
 - Federation settings admin section: view federation availability + the
 - configured peers, add/remove a peer (subject to the server-side SSRF
 - allowlist), trigger a manual pull, and show per-peer status (failure streak
 - + stale flag). Rendered inside the admin settings panel — admin-gated by the
 - IDelegatedSettings framework and by the AuthorizedAdminSetting attribute on
 - every FederationController method; NOT registered in the in-app router.
 -->

<template>
	<AlwaysVisibleSection
		:name="t('softwarecatalog', 'Catalog federation')"
		:description="t('softwarecatalog', 'Subscribe to peer catalogs and pull their published entries into this instance.')"
		:loading="loading"
		:loading-text="t('softwarecatalog', 'Loading federation status…')"
		:show-refresh-button="true"
		:refreshing="loading"
		:refresh-button-text="t('softwarecatalog', 'Refresh status')"
		@refresh="loadStatus">
		<template #header-actions>
			<NcButton
				type="primary"
				:disabled="!status.available || !status.enabled || pulling || status.peers.length === 0"
				@click="triggerPull">
				<template #icon>
					<NcLoadingIcon v-if="pulling" :size="20" />
					<Sync v-else :size="20" />
				</template>
				{{ t('softwarecatalog', 'Pull now') }}
			</NcButton>
		</template>

		<!-- Availability banner. -->
		<NcNoteCard v-if="!status.available" type="warning">
			{{ t('softwarecatalog', 'Federation is unavailable. It requires the OpenCatalogi app to be installed and enabled.') }}
		</NcNoteCard>
		<NcNoteCard v-else-if="!status.enabled" type="warning">
			{{ t('softwarecatalog', 'Federation is available but disabled. Enable it in the app configuration to pull peer catalogs.') }}
		</NcNoteCard>

		<div class="federation-directory">
			<h3>{{ t('softwarecatalog', 'Directory') }}</h3>
			<p class="help-text">
				{{ status.directoryUrl || t('softwarecatalog', 'No directory configured') }}
			</p>
		</div>

		<!-- Add-peer control. -->
		<div class="federation-add-peer">
			<h3>{{ t('softwarecatalog', 'Add a federation peer') }}</h3>
			<p class="help-text">
				{{ t('softwarecatalog', 'Private and loopback hosts are blocked unless explicitly allowlisted via the local_federation_hosts setting.') }}
			</p>
			<div class="federation-add-row">
				<NcTextField
					:value.sync="newPeerUrl"
					:label="t('softwarecatalog', 'Peer catalog URL')"
					:placeholder="'https://catalog.example.org'"
					:disabled="adding"
					@keydown.enter="addPeer" />
				<NcButton
					type="secondary"
					:disabled="adding || newPeerUrl.trim() === ''"
					@click="addPeer">
					<template #icon>
						<NcLoadingIcon v-if="adding" :size="20" />
						<Plus v-else :size="20" />
					</template>
					{{ t('softwarecatalog', 'Add peer') }}
				</NcButton>
			</div>
		</div>

		<!-- Peer list. -->
		<div class="federation-peers">
			<h3>{{ t('softwarecatalog', 'Subscribed peers') }}</h3>

			<NcEmptyContent
				v-if="status.peers.length === 0"
				:name="t('softwarecatalog', 'No peers subscribed')"
				:description="t('softwarecatalog', 'Add a peer catalog URL above to start federating.')">
				<template #icon>
					<LanDisconnect :size="40" />
				</template>
			</NcEmptyContent>

			<ul v-else class="peer-list">
				<li v-for="peer in status.peers" :key="peer.url" class="peer-item">
					<div class="peer-info">
						<span class="peer-url">{{ peer.url }}</span>
						<span class="peer-badges">
							<span v-if="peer.stale" class="peer-badge peer-badge--stale">
								{{ t('softwarecatalog', 'Stale') }}
							</span>
							<span v-else-if="!peer.allowed" class="peer-badge peer-badge--blocked">
								{{ t('softwarecatalog', 'Blocked by SSRF guard') }}
							</span>
							<span v-else class="peer-badge peer-badge--ok">
								{{ t('softwarecatalog', 'Healthy') }}
							</span>
						</span>
						<span class="peer-failures help-text">
							{{ n('softwarecatalog', '{count} consecutive failure (stale after {threshold})', '{count} consecutive failures (stale after {threshold})', peer.failures, { count: peer.failures, threshold: status.staleAfter }) }}
						</span>
					</div>
					<NcButton
						type="tertiary"
						:aria-label="t('softwarecatalog', 'Remove peer')"
						:disabled="removingUrl === peer.url"
						@click="removePeer(peer.url)">
						<template #icon>
							<NcLoadingIcon v-if="removingUrl === peer.url" :size="20" />
							<Delete v-else :size="20" />
						</template>
					</NcButton>
				</li>
			</ul>
		</div>

		<!-- Last manual-pull result. -->
		<NcNoteCard v-if="pullSummary" type="success">
			{{ pullSummary }}
		</NcNoteCard>
	</AlwaysVisibleSection>
</template>

<script>
import { defineComponent } from 'vue'
import { translate as t, translatePlural as n } from '@nextcloud/l10n'
import { showError, showSuccess } from '@nextcloud/dialogs'
import {
	NcButton,
	NcEmptyContent,
	NcLoadingIcon,
	NcNoteCard,
	NcTextField,
} from '@nextcloud/vue'
import Sync from 'vue-material-design-icons/Sync.vue'
import Plus from 'vue-material-design-icons/Plus.vue'
import Delete from 'vue-material-design-icons/Delete.vue'
import LanDisconnect from 'vue-material-design-icons/LanDisconnect.vue'
import AlwaysVisibleSection from '../../../components/AlwaysVisibleSection.vue'
import { apiRequest, normaliseFederationStatus } from '../../../utils/adminApi.js'

/**
 * Federation settings admin section.
 *
 * @spec openspec/specs/federated-catalog-sync/spec.md
 */
export default defineComponent({
	name: 'FederationSettings',
	components: {
		AlwaysVisibleSection,
		NcButton,
		NcEmptyContent,
		NcLoadingIcon,
		NcNoteCard,
		NcTextField,
		Sync,
		Plus,
		Delete,
		LanDisconnect,
	},

	/**
	 * @return {object} Component data.
	 */
	data() {
		return {
			loading: false,
			adding: false,
			pulling: false,
			removingUrl: null,
			newPeerUrl: '',
			pullSummary: '',
			status: {
				available: false,
				enabled: false,
				directoryUrl: '',
				staleAfter: 3,
				message: '',
				peers: [],
			},
		}
	},

	/**
	 * Load the federation status on mount.
	 *
	 * @spec openspec/specs/federated-catalog-sync/spec.md
	 */
	async created() {
		await this.loadStatus()
	},

	methods: {
		t,
		n,

		/**
		 * Load federation status from the admin endpoint.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/specs/federated-catalog-sync/spec.md
		 */
		async loadStatus() {
			this.loading = true
			try {
				const data = await apiRequest('federation/status')
				this.status = normaliseFederationStatus(data)
			} catch (error) {
				showError(t('softwarecatalog', 'Could not load federation status') + ': ' + error.message)
			} finally {
				this.loading = false
			}
		},

		/**
		 * Add the entered peer URL to the federation allowlist.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/specs/federated-catalog-sync/spec.md
		 */
		async addPeer() {
			const url = this.newPeerUrl.trim()
			if (url === '') {
				return
			}
			this.adding = true
			try {
				await apiRequest('federation/peers', { method: 'POST', body: { peerUrl: url } })
				showSuccess(t('softwarecatalog', 'Peer added'))
				this.newPeerUrl = ''
				await this.loadStatus()
			} catch (error) {
				showError(t('softwarecatalog', 'Could not add peer') + ': ' + error.message)
			} finally {
				this.adding = false
			}
		},

		/**
		 * Remove a peer from the federation allowlist.
		 *
		 * @param {string} url - The peer base URL to remove.
		 * @return {Promise<void>}
		 * @spec openspec/specs/federated-catalog-sync/spec.md
		 */
		async removePeer(url) {
			this.removingUrl = url
			try {
				await apiRequest('federation/peers', { method: 'DELETE', body: { peerUrl: url } })
				showSuccess(t('softwarecatalog', 'Peer removed'))
				await this.loadStatus()
			} catch (error) {
				showError(t('softwarecatalog', 'Could not remove peer') + ': ' + error.message)
			} finally {
				this.removingUrl = null
			}
		},

		/**
		 * Trigger a manual pull of all configured peers.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/specs/federated-catalog-sync/spec.md
		 */
		async triggerPull() {
			this.pulling = true
			this.pullSummary = ''
			try {
				const result = await apiRequest('federation/pull', { method: 'POST' })
				const peers = Array.isArray(result.peers) ? result.peers : []
				this.pullSummary = t('softwarecatalog', 'Pulled {count} peer(s).', { count: peers.length })
				showSuccess(t('softwarecatalog', 'Federation pull completed'))
				await this.loadStatus()
			} catch (error) {
				showError(t('softwarecatalog', 'Federation pull failed') + ': ' + error.message)
			} finally {
				this.pulling = false
			}
		},
	},
})
</script>

<style scoped>
.help-text {
	font-size: 12px;
	color: var(--color-text-lighter);
	margin: 0;
}

.federation-directory,
.federation-add-peer,
.federation-peers {
	margin-bottom: 1.5rem;
	max-width: 640px;
}

.federation-add-row {
	display: flex;
	align-items: flex-end;
	gap: 0.5rem;
	margin-top: 0.5rem;
}

.federation-add-row > :first-child {
	flex: 1;
}

.peer-list {
	list-style: none;
	margin: 0.5rem 0 0;
	padding: 0;
}

.peer-item {
	display: flex;
	align-items: center;
	justify-content: space-between;
	gap: 0.5rem;
	padding: 0.5rem 0;
	border-bottom: 1px solid var(--color-border);
}

.peer-info {
	display: flex;
	flex-direction: column;
	gap: 0.15rem;
	min-width: 0;
}

.peer-url {
	font-weight: 600;
	word-break: break-all;
}

.peer-badge {
	display: inline-block;
	border-radius: var(--border-radius-pill, 16px);
	padding: 0 0.5rem;
	font-size: 11px;
	line-height: 1.6;
}

.peer-badge--ok {
	background-color: var(--color-success, var(--color-primary-element));
	color: var(--color-primary-element-text, #fff);
}

.peer-badge--stale {
	background-color: var(--color-warning, var(--color-warning-text));
	color: var(--color-main-background);
}

.peer-badge--blocked {
	background-color: var(--color-error, var(--color-error-text));
	color: var(--color-main-background);
}
</style>
