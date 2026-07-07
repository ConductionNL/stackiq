// SPDX-License-Identifier: EUPL-1.2
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

import OrganisatieCard from './components/cards/OrganisatieCard.vue'
import SoftwareCatalogSettingsPage from './views/settings/SoftwareCatalogSettings.vue'
import DashboardCustomView from './views/Dashboard.vue'
import LifecycleRoadmapView from './views/LifecycleRoadmapView.vue'
import ComplianceMatrixView from './views/ComplianceMatrixView.vue'
import ContractApprovalPanel from './components/contracts/ContractApprovalPanel.vue'
import KwetsbaarhedenView from './views/KwetsbaarhedenView.vue'
import VulnerabilityExposurePanel from './components/vulnerabilities/VulnerabilityExposurePanel.vue'

export default {
	// OrganisatieCard — the bespoke card (inline contactpersoon toggle) used as
	// the `cardComponent` of the now-decomposed Organisaties type='index' page
	// (Phase 8). CnIndexPage's cardComponent config closed the prior lib gap.
	OrganisatieCard,

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

	// --- Lib gap: per-organisation lifecycle roadmap. ---
	// Gebruiken grouped by a DERIVED lifecycle phase (computed from dates, not
	// stored) with EOL badges + planned replacements is a portfolio view no
	// built-in index/detail type expresses. Stays custom until the lib grows a
	// grouped-timeline widget.
	LifecycleRoadmapView,

	// --- Lib gap: filter-first compliance matrix. ---
	// Modules × selected standard versions with verified/claimed/none cell
	// states is a cross-tabulation no built-in index/detail type expresses.
	// Stays a custom page until the lib grows a matrix/cross-tab widget.
	ComplianceMatrixView,

	// --- Cross-app decision seam: contract approval delegated to decidesk. ---
	// Read-only Approval panel rendered as a ContractDetail sidebar tab. The
	// approval/sign-off/renewal DECISION is raised in decidesk via the ADR-019
	// integration registry and projected back onto the contract; softwarecatalog
	// owns no approval workflow. Stays a custom tab component because it surfaces
	// a cross-app outcome no built-in detail widget expresses.
	ContractApprovalPanel,

	// --- Lib gap: CVSS-derived severity index + severity-band quick filters. ---
	// The Vulnerabilities index shows a DERIVED severity band (from cvssScore),
	// an affected-application count and an exposed-in-production-usage count, and
	// filters by severity band — none of which a built-in type='index' (schema
	// columns + exact-match quick filters) can express. Create/edit/delete reuse
	// the app's generic ObjectModal. Stays custom until the lib grows derived
	// columns + computed-range filters.
	KwetsbaarhedenView,

	// --- Read-time exposure join rendered as a KwetsbaarheidDetail sidebar tab. ---
	// "Which in-production usages are exposed to this CVE" is the join
	// kwetsbaarheid.modules → module ← gebruik → afnemer (in-production only),
	// computed on demand and never stored. No built-in detail widget expresses a
	// cross-schema relational join, so it stays a custom tab component.
	VulnerabilityExposurePanel,
}
