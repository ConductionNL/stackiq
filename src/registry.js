// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 Conduction B.V.
//
// 5-kind component registry for v2 manifest (per hydra ADR-036).
//
// The v1 customComponents.js map is preserved alongside this file for
// backward-compat during the migration window. CnAppRoot accepts both
// props; the v2 renderer emits a one-shot deprecation warning when both
// are present and the manifest is v2.
//
// Resolution at runtime (v2 path):
//   1. Built-in widgets registry (CnWidgetGrid built-ins)
//   2. registry prop (this file) — keyed by widgetKey / target / appliesTo
//
// References:
//   - hydra ADR-036
//   - nextcloud-app-template scaffold-v2 (#44) — canonical layout
//   - procest #512 / mydash #206 — first reference migrations

import OrganisatieIndexView from './views/organisaties/OrganisatieIndex.vue'
import SoftwareCatalogSettingsPage from './views/settings/SoftwareCatalogSettings.vue'
import DashboardCustomView from './views/Dashboard.vue'

export default {
	// --- Lib gap: bespoke OrganisatieCard + AddContactpersoonModal flow. ---
	// Bespoke modal/dialog/deep-link wiring around CnIndexPage. The
	// Organisaties manifest entry is type:'custom' with a _note pointing
	// here; the page mounts this view, which internally uses CnIndexPage.
	OrganisatieIndexView: {
		kind: 'page',
		component: OrganisatieIndexView,
	},

	// --- Lib gap: settings sub-section orchestration. ---
	// Multi-tab nav + ArchiMate status polling + register selector that
	// the lib's type:'settings' rich-section widgets can't express yet.
	SoftwareCatalogSettingsPage: {
		kind: 'page',
		component: SoftwareCatalogSettingsPage,
	},

	// --- Lib gap: dashboard widget extraction pending. ---
	// Preserved verbatim until extraction to a generic schema-stats widget.
	DashboardCustomView: {
		kind: 'page',
		component: DashboardCustomView,
	},
}
