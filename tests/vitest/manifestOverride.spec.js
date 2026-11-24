/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The manifest loader merges Buildiq's stored override delta over the bundled
 * manifest only when Buildiq says it is enabled, and otherwise mounts the
 * bundled manifest with no request.
 *
 * @spec openspec/changes/openbuild-inline-edit-persistence/tasks.md#5-runtime-contract-proof-one-app
 */

import { beforeEach, describe, expect, it, vi } from 'vitest'

const lib = vi.hoisted(() => ({}))

vi.mock('@conduction/nextcloud-vue', () => ({
	useAppManifest: (...args) => lib.useAppManifest(...args),
}))
vi.mock('@nextcloud/capabilities', () => ({
	getCapabilities: () => ({}),
}))

const { APP_ID, buildiqOverrideEndpoint, loadManifest } = await import('../../src/manifestOverride.js')

const manifest = { version: '1.0.0', pages: [{ id: 'dashboard', title: 'Dashboard' }] }

describe('buildiqOverrideEndpoint', () => {
	it('points at the Buildiq override of this app when Buildiq is enabled', () => {
		expect(buildiqOverrideEndpoint(() => ({ buildiq: { enabled: true, canEdit: false } })))
			.toBe('/index.php/apps/buildiq/api/app-overrides/stackiq')
	})

	it('is null without Buildiq in the capabilities', () => {
		expect(buildiqOverrideEndpoint(() => ({}))).toBeNull()
		expect(buildiqOverrideEndpoint(() => undefined)).toBeNull()
	})

	it('is null when Buildiq reports itself not enabled', () => {
		expect(buildiqOverrideEndpoint(() => ({ buildiq: { enabled: false } }))).toBeNull()
		expect(buildiqOverrideEndpoint(() => ({ buildiq: { enabled: 'yes' } }))).toBeNull()
	})

	it('is null when the capabilities cannot be read', () => {
		expect(buildiqOverrideEndpoint(() => {
			throw new Error('no capabilities')
		})).toBeNull()
	})

	it('reads the real capabilities by default', () => {
		expect(buildiqOverrideEndpoint()).toBeNull()
	})
})

describe('loadManifest', () => {
	beforeEach(() => {
		lib.useAppManifest = vi.fn(() => ({ manifest: { value: 'loaded' } }))
	})

	it('merges the stored delta over the bundled manifest when Buildiq is enabled', () => {
		const result = loadManifest(manifest, () => ({ buildiq: { enabled: true, canEdit: true } }))

		expect(result.manifest.value).toBe('loaded')
		expect(lib.useAppManifest).toHaveBeenCalledTimes(1)
		expect(lib.useAppManifest).toHaveBeenCalledWith(APP_ID, manifest, {
			mergeStrategy: 'delta',
			endpoint: '/index.php/apps/buildiq/api/app-overrides/stackiq',
		})
	})

	it('mounts the bundled manifest in memory without Buildiq', () => {
		loadManifest(manifest, () => ({}))

		expect(lib.useAppManifest).toHaveBeenCalledTimes(1)
		expect(lib.useAppManifest).toHaveBeenCalledWith({ manifest })
	})

	it('mounts the bundled manifest in memory by default when the capabilities lack Buildiq', () => {
		loadManifest(manifest)

		expect(lib.useAppManifest).toHaveBeenCalledWith({ manifest })
	})
})
