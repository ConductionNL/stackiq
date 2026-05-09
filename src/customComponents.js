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
	// CnIndexPage exposes a #card slot but the manifest cannot reference
	// a customComponents entry to fill it. Until a `cardComponent` config
	// field lands on type='index', Organisaties stays type='custom'.
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
