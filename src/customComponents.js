// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 Conduction B.V.
//
// Custom-component registry for softwarecatalog's manifest-driven app shell.
//
// Every entry here is the "escape hatch" — pages or sidebar tabs that
// don't fit one of the manifest's built-in types/widgets. Keep this
// file SHORT. Adding entries should require explicit justification in
// the design doc; deleting them is the right direction.
//
// Resolution order at runtime:
//   1. Built-in page types          (CnIndexPage, CnDetailPage, …)
//   2. Built-in widget types        (version-info, register-mapping, …)
//   3. customComponents (this file) ← consumer-injected components
//
// See:
//   - openspec/changes/softwarecatalog-manifest-v1/design.md
//   - @conduction/nextcloud-vue → docs/migrating-to-manifest.md

import OrganisatieIndexView from './views/organisaties/OrganisatieIndex.vue'
import SoftwareCatalogSettingsPage from './views/settings/SoftwareCatalogSettings.vue'
import DashboardCustomView from './views/Dashboard.vue'

export default {
	// --- Lib gap: bespoke OrganisatieCard + AddContactpersoonModal flow. ---
	// `cardComponent` on type='index' landed (CnIndexPage resolves a
	// registered card by name), but Organisaties also owns a bespoke flow
	// the manifest can't express yet: OrganisationModal CRUD + the
	// AddContactpersoonModal, the activate/deactivate status dialogs,
	// `_extend: contactpersonen` on the collection fetch, URL-hash deep
	// links (search/filters/page) and the cross-component `organisation*`
	// store subscriptions. So it stays type='custom' for now — but it does
	// drive a CnIndexPage internally; the residual custom surface is the
	// modal/dialog/deep-link wiring around it.
	OrganisatieIndexView,

	// --- Lib gap: settings sub-section orchestration. ---
	// The lib's type='settings' rich-section widgets cover individual
	// widget rendering but not the multi-tab navigation pattern + ArchiMate
	// status polling + register selector that SoftwareCatalogSettings.vue
	// orchestrates. Settings stays type='settings' but its single section
	// delegates to this custom component.
	SoftwareCatalogSettingsPage,

	// --- Lib gap: dashboard widget extraction pending. ---
	// The existing Dashboard.vue (info-box + 2 stats tables) does not yet
	// fit any built-in widget type. Preserved verbatim until extraction
	// to a generic schema-stats widget.
	DashboardCustomView,
}
