// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// Custom-component registry for stackiq's manifest-driven app shell.
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
//   - openspec/changes/stackiq-manifest-v1/design.md
//   - @conduction/nextcloud-vue → docs/migrating-to-manifest.md

import axios from '@nextcloud/axios'
import { showError } from '@nextcloud/dialogs'
import { emit } from '@nextcloud/event-bus'
import { translate as t } from '@nextcloud/l10n'
import { generateUrl } from '@nextcloud/router'
import AiActChecklist from './components/ai/AiActChecklist.vue'
import OrganisatieCard from './components/cards/OrganisatieCard.vue'
import ConnectionMapWidget from './components/connections/ConnectionMapWidget.vue'
import ConnectionSuggestionsPanel from './components/connections/ConnectionSuggestionsPanel.vue'
import ApplicationContractsPanel from './components/contracts/ApplicationContractsPanel.vue'
import ContractApprovalPanel from './components/contracts/ContractApprovalPanel.vue'
import ContractSeatsPanel from './components/contracts/ContractSeatsPanel.vue'
import ChangeEntryTypePanel from './components/entries/ChangeEntryTypePanel.vue'
import ConnectionWizardAction from './components/entryWizard/ConnectionWizardAction.vue'
import IntegrationLeafTab from './components/integrations/IntegrationLeafTab.vue'
import MergedRecordBanner from './components/merge/MergedRecordBanner.vue'
import ModuleClassificationSummary from './components/modules/ModuleClassificationSummary.vue'
import ClaimedUsageList from './components/organisations/ClaimedUsageList.vue'
import ContactPersonName from './components/organisations/ContactPersonName.vue'
import OrganisationExportAction from './components/organisations/OrganisationExportAction.vue'
import OrganisationMergePanel from './components/organisations/OrganisationMergePanel.vue'
import OrganisationParticipantsList from './components/organisations/OrganisationParticipantsList.vue'
import ClaimAnswerNotice from './components/portfolio/ClaimAnswerNotice.vue'
import UsageRiskSignals from './components/portfolio/UsageRiskSignals.vue'
import PublicationAction from './components/publication/PublicationAction.vue'
import ReviewsPanel from './components/reviews/ReviewsPanel.vue'
import ProductRoadmap from './components/roadmap/ProductRoadmap.vue'
import SbomComponentsPanel from './components/sbom/SbomComponentsPanel.vue'
import UsageClassificationPanel from './components/usages/UsageClassificationPanel.vue'
import UsageParticipantsCard from './components/usages/UsageParticipantsCard.vue'
import UsageRunsOnPanel from './components/usages/UsageRunsOnPanel.vue'
import VulnerabilityExposurePanel from './components/vulnerabilities/VulnerabilityExposurePanel.vue'
import ArchitectureViewEditor from './views/architecture/ArchitectureViewEditor.vue'
import DataEntityAttributes from './views/architecture/DataEntityAttributes.vue'
import DataEntityRelations from './views/architecture/DataEntityRelations.vue'
import ProcessStepFlow from './views/architecture/ProcessStepFlow.vue'
import ProcessStepUsages from './views/architecture/ProcessStepUsages.vue'
import ViewVersionCompare from './views/architecture/ViewVersionCompare.vue'
import AskView from './views/AskView.vue'
import AssistantActionsView from './views/AssistantActionsView.vue'
import ComplianceMatrixView from './views/ComplianceMatrixView.vue'
import FacetedCatalogIndexView from './views/FacetedCatalogIndexView.vue'
import FeaturesRoadmapTabs from './views/FeaturesRoadmapTabs.vue'
import KwetsbaarhedenView from './views/KwetsbaarhedenView.vue'
import LicensePostureView from './views/LicensePostureView.vue'
import LifecycleRoadmapView from './views/LifecycleRoadmapView.vue'
import DataQualityReportView from './views/organisaties/DataQualityReport.vue'
import GemmaPlateView from './views/organisaties/GemmaPlate.vue'
import PortfolioReportView from './views/organisaties/PortfolioReport.vue'
import CustomReportsView from './views/reports/CustomReportsView.vue'
import StackiqSettingsPage from './views/settings/StackiqSettings.vue'
import SuitesIndexView from './views/suites/SuitesIndexView.vue'
import SyncStatusView from './views/sync/SyncStatusView.vue'
import { createAttestationHandlers } from './services/attestation.js'
import { createConnectionHandlers } from './services/connectionRegistry.js'
import { createMoveHandlers } from './services/moveToOrganisation.js'
import { navigationStore } from './store/store.js'

export default {
	// Header-action handler: the Integrations page's Add integration
	// (adopt-connection-registry). A FUNCTION, because it leaves the app for
	// integriq's Connections overview and a header action's `navigate` only
	// pushes a route inside this app. CnIndexPage resolves a handler name
	// against this map. It navigates in JavaScript because the library renders
	// every header action as a button; once a header action can carry an
	// `href` (ConductionNL/nextcloud-vue#1314) this becomes a link in the
	// manifest and the handler goes.
	...createConnectionHandlers({
		generateUrl,
		assign: (url) => window.location.assign(url),
	}),

	// Confirmation rounds (landscape-owner-attestation D5): Start round opens
	// StartAttestationRoundDialog; Confirm and Open entry are the row actions of
	// My confirmation requests. Confirm POSTs the request's confirm endpoint and
	// refetches the list.
	...createAttestationHandlers({
		setDialog: (name, properties) =>
			navigationStore.setDialog(name, properties),
		post: (url) => axios.post(url),
		generateUrl,
		assign: (url) => window.location.assign(url),
		refresh: () => emit('cn:page:refresh', {}),
		notifyError: (message) =>
			showError(message || t('stackiq', 'The request could not be confirmed')),
	}),

	// Bulk-action handlers: Move to organisation on the Applications,
	// Services, Applications in use, Connections and Contracts lists
	// (landscape-move-between-organisations D3). FUNCTIONS, because the
	// action opens the app's own MoveToOrganisationDialog with the selection,
	// which a bulk action's `open-modal` does not reach (CnPageRenderer has
	// no listener for the index page's `open-modal` event).
	...createMoveHandlers((name, properties) => navigationStore.setDialog(name, properties)),

	// OrganisatieCard — the bespoke card (inline contactpersoon toggle) used as
	// the `cardComponent` of the now-decomposed Organisaties type='index' page
	// (Phase 8). CnIndexPage's cardComponent config closed the prior lib gap.
	OrganisatieCard,

	// A declared sidebar tab for one integration leaf (adopt-integration-leaves
	// 3.1). A detail page that lists its own sidebar tabs turns off the object
	// sidebar's registry mode, so the leaves the schema declares in
	// `linkedTypes` would not show; this widget renders the leaf's own tab.
	IntegrationLeafTab,

	// The steps of a business process as a read-only flow on CnGraphCanvas, and
	// the applications that support a step with the hidden ones counted
	// (architecture-process-mapping D3, D4): neither is a list a built-in
	// widget expresses.
	ProcessStepFlow,
	ProcessStepUsages,

	// The architecture view editor and the version compare
	// (architecture-views-editor D4, D6): an authoring canvas that places
	// elements, draws connections backed by relation objects and takes the
	// object lock, and two marked read-only canvases side by side. Neither is
	// a page type or widget the library has.
	ArchitectureViewEditor,
	ViewVersionCompare,

	// The relations of a data entity drawn around it with a text table, and its
	// attributes with keys, edited on an own entity
	// (architecture-data-model-and-ggm D4, D5): no built-in widget reads a
	// relation by ArchiMate identifier at either end or edits one list
	// property of an object in place.
	DataEntityRelations,
	DataEntityAttributes,

	// --- Lib gap: settings sub-section orchestration. ---
	// The lib's type='settings' rich-section widgets cover individual
	// widget rendering but not the multi-tab navigation pattern + ArchiMate
	// status polling + register selector that StackiqSettings.vue
	// orchestrates. Settings stays type='settings' but its single section
	// delegates to this custom component.
	StackiqSettingsPage,

	// The organisation sync's schedule, last run and running progress
	// (operations-sync-status-and-progress D5): it polls /api/sync/status while
	// a run is going, which no built-in page type does.
	SyncStatusView,

	// --- Lib gap: dashboard widget extraction pending. ---

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
	// integration registry and projected back onto the contract; stackiq
	// owns no approval workflow. Stays a custom tab component because it surfaces
	// a cross-app outcome no built-in detail widget expresses.
	// The contracts behind an application, a bodyWidgets section on ModuleDetail
	// (landscape-application-page): two hops, so not an object-list widget.
	ApplicationContractsPanel,

	// The connections of an application as a map, with SVG, PNG and CSV
	// downloads (connections-diagram-and-graph-export D1, D3): a one-hop graph
	// over two queries, which no object-list widget draws.
	ConnectionMapWidget,

	ContractApprovalPanel,

	// Licences in use against licences bought (contracts-licence-seats).
	ContractSeatsPanel,

	// The supplier's roadmap statement and the product's versions on a timeline,
	// a bodyWidgets section on ModuleDetail (lifecycle-maintenance-and-supplier-roadmap):
	// it reads the module and its versions, which no built-in widget combines.
	ProductRoadmap,

	// Change an application into a service or system software and back
	// (landscape-change-entry-type), a bodyWidgets section on ModuleDetail: it
	// previews the change through stackiq's type-change route before confirming,
	// which no built-in widget does.
	ChangeEntryTypePanel,

	// End of support of the version a usage runs and the vulnerabilities of its
	// application, next to the risk score on the usage page
	// (lifecycle-application-value-assessment): it joins the usage, its version
	// and the vulnerabilities, which no built-in widget does.
	UsageRiskSignals,
	UsageClassificationPanel,
	ModuleClassificationSummary,

	// The technology components an application in use runs on, with each one's
	// end of support and the worst support state one level down
	// (operations-technology-components D5): a state derived per row is not
	// something a built-in object list shows.
	UsageRunsOnPanel,

	// The connections suggested for a usage (connections-derived-dependencies D3):
	// derived on the server from the organisation's landscape and earlier
	// usages, with accept and dismiss, which no built-in widget does.
	ConnectionSuggestionsPanel,

	// The Participants card of a shared application in use: the owner and each
	// participant (shared-usage-visible-to-participants REQ-DLG-010).
	UsageParticipantsCard,

	// The name of a contact person on its detail page (landscape-usage-registration):
	// the name lives in Nextcloud Contacts, read through /api/contact-names,
	// which no built-in widget does.
	ContactPersonName,

	// AI Act evidence per tag on the AI system page (landscape-ai-system-inventory):
	// it reads the object's files and their tags, which no built-in widget lists per tag.
	AiActChecklist,

	// --- Admin-triggered organisation-merge (VNG Softwarecatalogus #141). ---
	// Dry-run preview + confirm dialog + execute for folding a source
	// organisation into a target (gemeentelijke herindeling /
	// leveranciersovername). Rendered as an OrganisatieDetail bodyWidget.
	// No built-in widget expresses a cross-object relation re-pointing action.
	OrganisationMergePanel,

	// A cooperation's participant organisations, each linked
	// (shared-usage-visible-to-participants REQ-DLG-012).
	OrganisationParticipantsList,
	// Claimed usage of a supplier's offering, with Accept and Decline (supplier-accepts-claimed-usage).
	ClaimedUsageList,
	// On a usage page, the supplier's decline of the claim (supplier-accepts-claimed-usage).
	ClaimAnswerNotice,

	// The Merged into banner on a merged application's page
	// (operations-record-reconciliation D4): it shows the survivor only when
	// `recordStatus` is Merged, which no built-in widget conditions on.
	MergedRecordBanner,
	// OrganisatieDetail's actions slot: Export as ArchiMate for the organisation
	// on the page, shown when the server says the caller may export it
	// (insight-exports-and-custom-reports, REQ-CER-003).
	OrganisationExportAction,

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

	// --- Portfolio license posture (SAM overview). ---
	// Open-source vs closed-source share of the in-production portfolio +
	// per-vendor rollup (deployments + licence mix + cost CONSUMED from
	// contract-administration) + per-organisation open-source-first report — all
	// derived at query time, weighted by in-production deployment. A read-only
	// aggregation dashboard no built-in index/detail type expresses; stays custom
	// until the lib grows a declarative aggregation/rollup widget.
	LicensePostureView,

	// Ask the catalogue (insight-assistant-answers-and-action-trail D2): one
	// question answered by hermiq from the entries OpenRegister finds for the
	// caller, with the cited entries linked. A conversation, not a list, form
	// or detail: no built-in page type expresses it.
	AskView,

	// Assistant actions (insight-assistant-answers-and-action-trail D3): the
	// assistant tool calls on catalogue records, read from OpenRegister's
	// cursor-paged audit trail filtered server side on `mcp.` / `stackiq.`.
	// The audit list is not an OpenRegister object collection, so no index
	// page type can read it.
	AssistantActionsView,

	// --- Lib gap: live GEMMA-dimension facet counts on the module/dienst index pages. ---
	// CnIndexPage's own embedded `sidebar.enabled` facet machinery treats every
	// active-filter key as a directly-filterable schema field and applies it
	// verbatim to the self-fetch object-list query. `domein`/`applicatieservice`
	// are not module/dienst fields at all (they live on the linked `element`
	// object) and `referentiecomponent`/`standaard` are exposed here by display
	// NAME, not the identifiers the schema stores — feeding them through that
	// path would break the list. Stays a custom page (wrapping `CnFacetSidebar`
	// + a standalone `CnIndexPage` narrowed via `{ _ids: matchedObjectIds }`) until
	// the lib grows a facet-sidebar mode whose counts/narrowing are computed by
	// an external, non-schema-field aggregation (see FacetedCatalogIndexView.vue).
	FacetedCatalogIndexView,
	// --- Lib gap: composed backend-aggregate rationalization report. ---
	// GET /api/portfolio-report (PortfolioReportService) reads TIME quadrant
	// counts + EOL exposure + cloud-transition share + annualised cost as a
	// SINGLE bounded, organisation-scoped, RBAC-checked JSON payload plus a
	// CSV variant. Unlike LicensePostureView/LifecycleRoadmapView (client-side
	// derivation over full collections) this page is a thin renderer over
	// that endpoint — no built-in index/detail/dashboard type expresses a
	// fetched, pre-aggregated multi-metric report with a CSV export button.
	// @spec openspec/changes/portfolio-rationalization-time/specs/portfolio-rationalization-time/spec.md
	PortfolioReportView,
	// The Custom reports page (insight-exports-and-custom-reports, REQ-CER-004):
	// the caller's OpenRegister export profiles over the catalogue register.
	CustomReportsView,
	// --- Lib gap: the organisation-scoped data quality report. ---
	// GET /api/data-quality evaluates the usage schema's declared quality
	// rules over one organisation's usages, bounded like the portfolio report.
	// @spec openspec/changes/landscape-completeness-score/specs/catalogue-data-quality/spec.md#requirement-req-cdq-003-a-data-quality-page-shows-how-complete-and-current-an-organisation-s-register-is
	DataQualityReportView,
	// --- Lib gap: the GEMMA plate. ---
	// GET /api/reference-component-coverage joins one organisation's usages
	// with the GEMMA reference components; a component nobody uses has no
	// facet bucket, so no index or dashboard page can show the gaps.
	// @spec openspec/changes/architecture-reference-component-coverage/specs/reference-component-coverage/spec.md#requirement-req-rcc-002-a-gemma-plate-shall-show-the-components-with-their-applications-with-filters-for-gaps-and-overlaps
	GemmaPlateView,
	// --- SBOM (Software Bill of Materials) import — Components tab. ---
	// ModuleversieDetail sidebar tab: the imported sbomComponent list
	// (name/version/purl/licenses), summary counts, an upload control, and a
	// render-time vulnerability-match join (sbomVulnerabilityMatch.js) vs the
	// kwetsbaarheid register. No built-in detail widget expresses an upload
	// flow + cross-schema read-time match, so it stays a custom tab component.
	SbomComponentsPanel,

	// --- Lib gap: the guided suite-creation wizard's action button. ---
	// A declarative `type: index` page has no slot to inject the multi-step
	// "New suite" wizard trigger alongside the generic single-form create
	// button, so this stays a custom page. `CnIndexPage` itself still
	// self-fetches (register="stackiq" schema="suite") exactly as a
	// declarative type:index page would.
	// @spec openspec/specs/suite-wizard/spec.md
	SuitesIndexView,
	// --- The Connections page's Add opens the guided entry wizard. ---
	// Rendered in the declarative index page's actions slot
	// (`actionsComponent`), so the page keeps its columns and filters.
	// @spec openspec/changes/capability-requirements-from-the-matrix/specs/guided-entry-wizard/spec.md#requirement-req-gew-001-applications-services-and-connections-are-added-through-a-step-by-step-wizard
	ConnectionWizardAction,
	// --- Ratings & reviews (stackiq#375). ---
	// Approved-only aggregate (average + count) + a bounded approved-review
	// list, computed server-side by ReviewService, plus the "Write a review"
	// action opening SubmitReviewModal.vue. No built-in body widget
	// expresses a moderated, authored, cross-schema aggregate + submit
	// flow, so it stays a custom body-widget component (ModuleDetail's
	// bodyWidgets, same escape hatch as ContractApprovalPanel).
	// @spec openspec/specs/catalog-ratings/spec.md#requirement-module-and-dienst-detail-pages-must-display-an-aggregate-rating-computed-only-from-approved-reviews
	ReviewsPanel,

	// --- Publish and withdraw on an entry's page (REQ-ODP-011). ---
	// ModuleDetail's `actionsComponent`: the publication badge plus Publish
	// (now or on a date) and Withdraw, calling stackiq's guarded publication
	// endpoint. No built-in header action can call a non-object-service
	// endpoint per record and show its refusal, so it stays custom.
	// @spec openspec/changes/publish-and-lock-from-the-list/specs/open-data-publishing/spec.md#requirement-an-entrys-page-publishes-or-withdraws-that-entry-req-odp-011
	PublicationAction,

	// --- Features and roadmap with its Extensions tab (features-roadmap-page). ---
	// The library's roadmap and store pages are one page type each; this puts
	// both under one page as two tabs, so the footer Store entry and the
	// roadmap are one place. Both halves stay the library components.
	// @spec openspec/changes/features-roadmap-page/specs/features-roadmap/spec.md#requirement-the-uitbreidingen-tab-lists-the-extensions-from-the-store-req-frm-002
	FeaturesRoadmapTabs,
}
