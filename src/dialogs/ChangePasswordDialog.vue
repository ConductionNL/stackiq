<!--
  - SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
  - SPDX-License-Identifier: EUPL-1.2
  -
  - Change-password dialog for the Nextcloud user account behind a
  - contactpersoon. Extracted from ContactpersonenList.vue per ADR-004/ADR-012:
  - a dialog lives in its own file under src/dialogs/ and is imported by its
  - parent.
  -
  - Everything that decides whether a password MAY be saved is dialog-local:
  - the requirement checklist, the debounced Have I Been Pwned lookup (k-anonymity
  - range query over a SHA-1 prefix, so the password itself never leaves the
  - browser), and the store call that writes it. The parent only mounts this
  - component (v-if) and reacts to `saved` / `close`.
  -
  - The dialog is mounted fresh on every open, so `data()` IS the reset that the
  - parent used to perform by hand, and `beforeUnmount` IS the timeout cleanup.
  -
  - @spec openspec/specs/fe-organizations/spec.md
  -->

<template>
	<NcDialog
		:name="t('softwarecatalog', 'Change Password')"
		size="small"
		@closing="$emit('close')">
		<div class="password-dialog">
			<p class="dialog-description">
				{{
					t("softwarecatalog", "Change password for user: {username}", {
						username,
					})
				}}
			</p>

			<div class="password-input">
				<NcTextField
					v-model="newPassword"
					type="password"
					:label="t('softwarecatalog', 'New password')"
					:placeholder="t('softwarecatalog', 'Enter new password')"
					class="compact-input" />
			</div>

			<!-- Password Requirements -->
			<div class="password-requirements">
				<h4>{{ t("softwarecatalog", "Password Requirements:") }}</h4>
				<ul class="requirements-list">
					<li :class="{ 'requirement-met': passwordValidation.minLength }">
						<CheckCircle
							v-if="passwordValidation.minLength"
							:size="16"
							class="check-icon" />
						<CloseCircle v-else :size="16" class="close-icon" />
						{{ t("softwarecatalog", "At least 10 characters") }}
					</li>
					<li :class="{ 'requirement-met': passwordValidation.hasUppercase }">
						<CheckCircle
							v-if="passwordValidation.hasUppercase"
							:size="16"
							class="check-icon" />
						<CloseCircle v-else :size="16" class="close-icon" />
						{{ t("softwarecatalog", "At least one uppercase letter") }}
					</li>
					<li :class="{ 'requirement-met': passwordValidation.hasLowercase }">
						<CheckCircle
							v-if="passwordValidation.hasLowercase"
							:size="16"
							class="check-icon" />
						<CloseCircle v-else :size="16" class="close-icon" />
						{{ t("softwarecatalog", "At least one lowercase letter") }}
					</li>
					<li :class="{ 'requirement-met': passwordValidation.hasNumber }">
						<CheckCircle
							v-if="passwordValidation.hasNumber"
							:size="16"
							class="check-icon" />
						<CloseCircle v-else :size="16" class="close-icon" />
						{{ t("softwarecatalog", "At least one number") }}
					</li>
					<li
						:class="{ 'requirement-met': passwordValidation.hasSpecialChar }">
						<CheckCircle
							v-if="passwordValidation.hasSpecialChar"
							:size="16"
							class="check-icon" />
						<CloseCircle v-else :size="16" class="close-icon" />
						{{
							t(
								"softwarecatalog",
								"At least one special character (!@#$%^&*)"
							)
						}}
					</li>
					<li :class="{ 'requirement-met': passwordValidation.notPwned }">
						<NcLoadingIcon
							v-if="pwnedCheckLoading"
							:size="16"
							class="loading-icon" />
						<CheckCircle
							v-else-if="passwordValidation.notPwned"
							:size="16"
							class="check-icon" />
						<CloseCircle
							v-else
							:size="16"
							class="close-icon" />
						{{
							t(
								"softwarecatalog",
								"Password has not appeared in known data breaches"
							)
						}}
					</li>
				</ul>
			</div>

			<div class="dialog-actions">
				<NcButton variant="secondary" @click="$emit('close')">
					{{ t("softwarecatalog", "Cancel") }}
				</NcButton>
				<NcButton
					variant="primary"
					:disabled="passwordLoading || !isPasswordValid || pwnedCheckLoading"
					@click="savePassword">
					<template #icon>
						<NcLoadingIcon v-if="passwordLoading" :size="20" />
					</template>
					{{ t("softwarecatalog", "Save") }}
				</NcButton>
			</div>
		</div>
	</NcDialog>
</template>

<script>
import {
	NcButton,
	NcDialog,
	NcLoadingIcon,
	NcTextField,
} from '@nextcloud/vue'

import CheckCircle from 'vue-material-design-icons/CheckCircle.vue'
import CloseCircle from 'vue-material-design-icons/CloseCircle.vue'

import { showSuccess, showError } from '@nextcloud/dialogs'
import { useOrganisatieStore } from '../store/modules/organisatie.js'

export default {
	name: 'ChangePasswordDialog',

	components: {
		NcButton,
		NcDialog,
		NcLoadingIcon,
		NcTextField,
		CheckCircle,
		CloseCircle,
	},

	props: {
		/**
		 * Nextcloud username whose password is being changed.
		 */
		username: {
			type: String,
			default: '',
		},
	},

	emits: ['close', 'saved'],

	data() {
		return {
			newPassword: '',
			passwordLoading: false,
			// Start out assuming the password IS pwned: the checklist may only go
			// green once the HIBP lookup has actually answered.
			isPasswordPwned: true,
			pwnedCheckLoading: false,
			pwnedCheckTimeout: null,
		}
	},

	computed: {
		/**
		 * @spec openspec/specs/fe-organizations/spec.md
		 */
		organisatieStore() {
			return useOrganisatieStore()
		},

		// Password validation computed properties
		/**
		 * @spec openspec/specs/fe-organizations/spec.md
		 */
		passwordValidation() {
			return {
				minLength: this.newPassword.length >= 10,
				hasUppercase: /[A-Z]/.test(this.newPassword),
				hasLowercase: /[a-z]/.test(this.newPassword),
				hasNumber: /\d/.test(this.newPassword),
				hasSpecialChar: /[!@#$%^&*(),.?":{}|<>]/.test(this.newPassword),
				// Only consider notPwned valid if check is complete and password is not pwned
				// If check is still loading, treat as invalid to prevent premature save
				notPwned: !this.pwnedCheckLoading && !this.isPasswordPwned,
			}
		},

		/**
		 * @spec openspec/specs/fe-organizations/spec.md
		 */
		isPasswordValid() {
			return Object.values(this.passwordValidation).every(
				(requirement) => requirement,
			)
		},
	},

	watch: {
		/**
		 * @spec openspec/specs/fe-organizations/spec.md
		 */
		newPassword(newVal) {
			// Clear existing timeout
			if (this.pwnedCheckTimeout) {
				clearTimeout(this.pwnedCheckTimeout)
			}

			// Only check if password meets minimum length requirement
			if (newVal && newVal.length >= 10) {
				// Set loading state immediately to prevent save during debounce
				this.pwnedCheckLoading = true
				// Debounce the API call to avoid excessive requests
				this.pwnedCheckTimeout = setTimeout(() => {
					this.checkPasswordPwned(newVal)
				}, 500)
			} else {
				// Password too short, no check needed
				this.pwnedCheckLoading = false
			}
		},
	},

	/**
	 * @spec openspec/specs/fe-organizations/spec.md
	 */
	beforeUnmount() {
		// Clean up the debounce timeout to prevent memory leaks
		if (this.pwnedCheckTimeout) {
			clearTimeout(this.pwnedCheckTimeout)
		}
	},

	methods: {
		/**
		 * Compute SHA-1 hash of a string
		 * @param {string} str - String to hash
		 * @return {Promise<string>} SHA-1 hash in hexadecimal format (uppercase)
		  * @spec openspec/specs/fe-organizations/spec.md
		 */
		async sha1(str) {
			// Simple SHA-1 implementation
			// Based on: https://github.com/emn178/js-sha1
			const encoder = new TextEncoder()
			const utf8Bytes = encoder.encode(str)
			const bytes = Array.from(utf8Bytes)

			const h = [0x67452301, 0xEFCDAB89, 0x98BADCFE, 0x10325476, 0xC3D2E1F0]

			const w = []
			const length = bytes.length * 8

			bytes.push(0x80)
			while (bytes.length % 64 !== 56) {
				bytes.push(0)
			}

			for (let i = 0; i < bytes.length; i += 4) {
				w.push(
					(bytes[i] << 24)
					| (bytes[i + 1] << 16)
					| (bytes[i + 2] << 8)
					| bytes[i + 3],
				)
			}

			w.push(0)
			w.push(0)
			w[w.length - 2] = Math.floor(length / 0x100000000)
			w[w.length - 1] = length & 0xffffffff

			for (let i = 0; i < w.length; i += 16) {
				// Create a local copy of w for this 512-bit block (expand to 80 words)
				const wLocal = new Array(80)
				for (let j = 0; j < 16; j++) {
					wLocal[j] = w[i + j]
				}

				// Expand w array for rounds 16-79
				for (let j = 16; j < 80; j++) {
					const wVal = wLocal[j - 3]
						^ wLocal[j - 8]
						^ wLocal[j - 14]
						^ wLocal[j - 16]
					wLocal[j] = ((wVal << 1) | (wVal >>> 31)) >>> 0
				}

				let a = h[0]
				let b = h[1]
				let c = h[2]
				let d = h[3]
				let e = h[4]

				for (let j = 0; j < 80; j++) {
					let f
					let k
					if (j < 20) {
						f = (b & c) | (~b & d)
						k = 0x5A827999
					} else if (j < 40) {
						f = b ^ c ^ d
						k = 0x6ED9EBA1
					} else if (j < 60) {
						f = (b & c) | (b & d) | (c & d)
						k = 0x8F1BBCDC
					} else {
						f = b ^ c ^ d
						k = 0xCA62C1D6
					}

					const temp = (this.rotl(a, 5) + f + e + k + wLocal[j]) >>> 0
					e = d
					d = c
					c = this.rotl(b, 30) >>> 0
					b = a
					a = temp
				}

				h[0] = (h[0] + a) >>> 0
				h[1] = (h[1] + b) >>> 0
				h[2] = (h[2] + c) >>> 0
				h[3] = (h[3] + d) >>> 0
				h[4] = (h[4] + e) >>> 0
			}

			return (
				h[0].toString(16).padStart(8, '0')
				+ h[1].toString(16).padStart(8, '0')
				+ h[2].toString(16).padStart(8, '0')
				+ h[3].toString(16).padStart(8, '0')
				+ h[4].toString(16).padStart(8, '0')
			).toUpperCase()
		},

		/**
		 * Rotate left operation for SHA-1
		 * @param {number} value - Value to rotate
		 * @param {number} amount - Amount to rotate
		 * @return {number} Rotated value
		  * @spec openspec/specs/fe-organizations/spec.md
		 */
		rotl(value, amount) {
			return ((value << amount) | (value >>> (32 - amount))) >>> 0
		},

		/**
		 * Check if password is in Have I Been Pwned database
		 * @param {string} password - Password to check
		  * @spec openspec/specs/fe-organizations/spec.md
		 */
		async checkPasswordPwned(password) {
			if (!password || password.length < 10) {
				this.isPasswordPwned = true
				return
			}

			this.pwnedCheckLoading = true

			try {
				// Hash the password with SHA-1
				const sha1Hash = await this.sha1(password)
				const prefix = sha1Hash.substring(0, 5)
				const suffix = sha1Hash.substring(5)

				// Call Have I Been Pwned API
				const response = await fetch(
					`https://api.pwnedpasswords.com/range/${prefix}`,
					{
						method: 'GET',
						headers: {
							'User-Agent': 'Nextcloud-SoftwareCatalog',
						},
					},
				)

				if (!response.ok) {
					console.error(
						'HIBP API error:',
						response.status,
						response.statusText,
					)
					// If API fails, don't block password (fail open)
					this.isPasswordPwned = false
					return
				}

				const text = await response.text()
				const hashes = text.split('\n')

				// Check if our suffix is in the list
				for (const line of hashes) {
					const [hashSuffix] = line.split(':')
					if (hashSuffix && hashSuffix.toUpperCase() === suffix) {
						this.isPasswordPwned = true
						this.pwnedCheckLoading = false
						return
					}
				}

				// Password not found in database
				this.isPasswordPwned = false
			} catch (error) {
				console.error('Error checking password against HIBP:', error)
				// If check fails, don't block password (fail open)
				this.isPasswordPwned = false
			} finally {
				this.pwnedCheckLoading = false
			}
		},

		/**
		 * Persist the new password for the selected user.
		 * @return {Promise<void>}
		  * @spec openspec/specs/fe-organizations/spec.md
		 */
		async savePassword() {
			if (!this.newPassword || this.newPassword.length < 10) {
				showError(
					this.t(
						'softwarecatalog',
						'Password must be at least 10 characters long',
					),
				)
				return
			}

			if (!this.isPasswordValid) {
				if (this.isPasswordPwned) {
					showError(
						this.t(
							'softwarecatalog',
							'This password has been found in data breaches and is not secure. Please choose a different password.',
						),
					)
				} else {
					showError(
						this.t(
							'softwarecatalog',
							'Password does not meet all requirements',
						),
					)
				}
				return
			}

			this.passwordLoading = true

			try {
				await this.organisatieStore.changePassword(
					this.username,
					this.newPassword,
				)
				showSuccess(this.t('softwarecatalog', 'Password changed successfully'))
				this.$emit('saved')
			} catch (error) {
				showError(
					this.t('softwarecatalog', 'Failed to change password: {error}', {
						error: error.message,
					}),
				)
			} finally {
				this.passwordLoading = false
			}
		},
	},
}
</script>

<style scoped>
.password-dialog {
	padding: 12px;
	min-width: 320px;
	max-width: 400px;
}

.dialog-description {
	margin: 0 0 12px 0;
	font-size: 14px;
	color: var(--color-text-lighter);
}

.password-input {
	margin: 12px 0;
}

.compact-input {
	margin: 8px 0;
}

.dialog-actions {
	display: flex;
	justify-content: flex-end;
	gap: 8px;
	margin-top: 12px;
	padding-top: 8px;
	border-top: 1px solid var(--color-border);
}

/* Make NcTextField more compact */
.compact-input :deep(.input-field) {
	margin-bottom: 8px;
}

.compact-input :deep(.input-field__main-wrapper) {
	min-height: 36px;
}

.compact-input :deep(.input-field__input) {
	padding: 8px 12px;
	font-size: 14px;
}

/* Password Requirements Styles */
.password-requirements {
	margin: 16px 0;
	padding: 12px;
	background: var(--color-background-dark);
	border-radius: 6px;
	border: 1px solid var(--color-border);
}

.password-requirements h4 {
	margin: 0 0 8px 0;
	font-size: 14px;
	font-weight: 600;
	color: var(--color-text-dark);
}

.requirements-list {
	list-style: none;
	padding: 0;
	margin: 0;
}

.requirements-list li {
	display: flex;
	align-items: center;
	gap: 8px;
	padding: 4px 0;
	font-size: 13px;
	color: var(--color-text-lighter);
	transition: color 0.2s ease;
}

.requirements-list li.requirement-met {
	color: var(--color-success);
}

.check-icon {
	color: var(--color-success);
}

.close-icon {
	color: var(--color-error);
}

.loading-icon {
	color: var(--color-text-lighter);
}

/* WCAG 2.3.3 — the password-requirement colour transition is decorative; the
   met/unmet colour still changes, it just changes instantly. */
@media (prefers-reduced-motion: reduce) {
	.requirements-list li {
		transition: none;
	}
}
</style>
