// SPDX-License-Identifier: EUPL-1.2
// SPDX-FileCopyrightText: 2026 Conduction B.V.
/**
 * E2e coverage file for openspec/specs/org-archimate-export/spec.md
 *
 * Coverage status
 * ---------------
 * All 49 backend/XML-generation scenarios (Requirements 1–13) are excluded
 * from Playwright coverage: they are pure server-side contracts verified by
 * PHPUnit and Newman/Postman tests.
 *
 * The 4 frontend scenarios (Requirement 14: "Frontend MUST provide organization
 * export with data layer toggles") are excluded because the softwarecatalog SPA
 * does not mount: the webpack runtime chunk is not loaded by the PHP templates,
 * leaving <div id="softwarecatalog"> and <div id="settings"> permanently empty.
 * See GH issue #322 for the fix.
 *
 * Excluded scenarios (backend – 49 total):
 * @e2e org-archimate-export::organization-with-mapped-applications-exports-successfully
 * @e2e org-archimate-export::organization-with-no-mapped-applications
 * @e2e org-archimate-export::export-preserves-all-base-gemma-data
 * @e2e org-archimate-export::export-xml-is-well-formed-and-schema-valid
 * @e2e org-archimate-export::large-organization-export-completes-within-timeout
 * @e2e org-archimate-export::application-element-has-correct-structure
 * @e2e org-archimate-export::application-element-has-unique-swc-identifier
 * @e2e org-archimate-export::application-element-identifier-is-deterministic
 * @e2e org-archimate-export::application-element-name-handles-special-xml-characters
 * @e2e org-archimate-export::application-mapped-to-one-referentiecomponent
 * @e2e org-archimate-export::application-mapped-to-multiple-referentiecomponenten
 * @e2e org-archimate-export::relationship-identifiers-are-deterministic
 * @e2e org-archimate-export::relationship-source-and-target-reference-valid-elements
 * @e2e org-archimate-export::view-with-applications-plotted-on-referentiecomponenten
 * @e2e org-archimate-export::multiple-applications-stacked-inside-one-referentiecomponent
 * @e2e org-archimate-export::application-appears-in-multiple-referentiecomponenten-across-views
 * @e2e org-archimate-export::view-without-any-matching-referentiecomponenten
 * @e2e org-archimate-export::original-gemma-views-are-preserved-unchanged
 * @e2e org-archimate-export::view-has-titel-view-swc-property
 * @e2e org-archimate-export::view-without-titel-view-swc-property
 * @e2e org-archimate-export::view-name-handles-long-organization-names
 * @e2e org-archimate-export::organisation-folders-created-with-typed-subfolders
 * @e2e org-archimate-export::empty-folders-are-omitted
 * @e2e org-archimate-export::only-deelnames-enabled-produces-only-deelnames-folder
 * @e2e org-archimate-export::folder-item-references-are-valid
 * @e2e org-archimate-export::file-name-includes-date-and-organization
 * @e2e org-archimate-export::model-name-includes-organization
 * @e2e org-archimate-export::file-name-sanitizes-special-characters-in-organization-name
 * @e2e org-archimate-export::valid-organization-uuid-provided
 * @e2e org-archimate-export::valid-organization-uuid-with-query-parameters
 * @e2e org-archimate-export::non-existent-organization-uuid
 * @e2e org-archimate-export::unauthenticated-request-is-rejected
 * @e2e org-archimate-export::non-admin-user-is-rejected
 * @e2e org-archimate-export::bron-property-definition-does-not-already-exist
 * @e2e org-archimate-export::bron-property-definition-already-exists
 * @e2e org-archimate-export::bron-property-references-are-valid
 * @e2e org-archimate-export::connection-links-application-node-to-referentiecomponent-node
 * @e2e org-archimate-export::connection-identifiers-are-unique
 * @e2e org-archimate-export::connection-without-matching-relationship-is-not-created
 * @e2e org-archimate-export::organisation-has-deelname-gebruik
 * @e2e org-archimate-export::organisation-has-no-deelname-gebruik
 * @e2e org-archimate-export::deelnames-parameter-is-not-set
 * @e2e org-archimate-export::deelname-applications-have-distinct-identifiers
 * @e2e org-archimate-export::deelname-query-filters-on-deelnemers-field
 * @e2e org-archimate-export::deelname-query-handles-no-results-gracefully
 * @e2e org-archimate-export::all-parameters-enabled
 * @e2e org-archimate-export::no-parameters-provided-default-behavior
 * @e2e org-archimate-export::only-deelnames-enabled
 * @e2e org-archimate-export::boolean-parameters-accept-various-truthy-values
 *
 * Excluded scenarios (SPA not mounted – 4 total, GH issue #322):
 * @e2e org-archimate-export::user-triggers-organization-export-with-toggles
 * @e2e org-archimate-export::no-organization-selected
 * @e2e org-archimate-export::default-checkbox-state
 * @e2e org-archimate-export::export-button-shows-loading-state-during-download
 */

// No runnable tests: all scenarios are excluded from Playwright coverage.
// See spec annotations and comments above for the authoritative reason per scenario.
export {}
