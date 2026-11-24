// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.

/**
 * Loads Stackiq's manifest with the stored Buildiq override applied.
 *
 * Buildiq keeps a keyed manifest delta per fleet app at
 * `/apps/buildiq/api/app-overrides/{appId}` (an empty delta `{}` when there is
 * none). When Buildiq is installed and enabled for the user, the manifest is
 * loaded through `useAppManifest`'s backend branch with
 * `mergeStrategy: 'delta'`, so a stored delta (a renamed page, a removed
 * widget, a new order) merges over the bundled manifest on every load, and a
 * cleared override falls back to the bundled manifest. Without Buildiq the
 * in-memory branch mounts the bundled manifest with no request at all.
 *
 * @spec openspec/specs/softwarecatalog-manifest-v1/spec.md
 */

import { useAppManifest } from '@conduction/nextcloud-vue'
import { getCapabilities } from '@nextcloud/capabilities'
import { generateUrl } from '@nextcloud/router'

export const APP_ID = 'stackiq'

/**
 * The Buildiq override endpoint for this app, or null when Buildiq is not
 * there to answer it.
 *
 * Buildiq publishes `buildiq.enabled` in the capabilities document; an
 * instance without Buildiq has no such key, and then no request is made.
 *
 * @param {function(): object} [readCapabilities] Returns the capabilities document.
 * @return {string|null} The override URL, or null.
 * @spec openspec/specs/softwarecatalog-manifest-v1/spec.md
 */
export function buildiqOverrideEndpoint(readCapabilities = getCapabilities) {
	let capabilities
	try {
		capabilities = readCapabilities()
	} catch {
		return null
	}
	if (capabilities?.buildiq?.enabled !== true) {
		return null
	}
	return generateUrl('/apps/buildiq/api/app-overrides/' + APP_ID)
}

/**
 * (buildiq change openbuild-inline-edit-persistence, task 5.1.)
 *
 * Load the manifest: the bundled one with Buildiq's stored delta merged over
 * it when Buildiq is enabled, else the bundled one as it is.
 *
 * @param {object} resolvedManifest The bundled manifest, sentinels resolved.
 * @param {function(): object} [readCapabilities] Returns the capabilities document.
 * @return {{manifest: import('vue').Ref<object>}} The `useAppManifest` result.
 * @spec openspec/specs/softwarecatalog-manifest-v1/spec.md
 */
export function loadManifest(resolvedManifest, readCapabilities = getCapabilities) {
	const endpoint = buildiqOverrideEndpoint(readCapabilities)
	if (endpoint === null) {
		return useAppManifest({ manifest: resolvedManifest })
	}
	return useAppManifest(APP_ID, resolvedManifest, { mergeStrategy: 'delta', endpoint })
}
