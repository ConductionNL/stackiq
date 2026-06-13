---
status: draft
retrofit: true
---

# Concept Organizations Widget Specification

## Purpose

Captures the asset-loading contract for the Concept Organisaties dashboard widget. The widget is mounted via Nextcloud's `IWidget` interface and contributes a Vue bundle to the dashboard page; its `load()` method MUST add the four split-chunk scripts in dependency order plus the shared widget stylesheet. Reverse-spec'd from existing code 2026-05-24.

## ADDED Requirements

### Requirement: The widget SHALL load runtime, vendor, nc-vue, widget, and stylesheet assets in dependency order (REQ-001)

`ConceptOrganisatiesWidget::load()` MUST register, in this order: (1) the webpack runtime chunk `softwarecatalog-runtime`, (2) the shared vendor chunk `softwarecatalog-shared-vendor`, (3) the shared nc-vue chunk `softwarecatalog-shared-nc-vue`, (4) the widget entry chunk `softwarecatalog-conceptOrganisatiesWidget`. It MUST also register the `dashboardWidgets` stylesheet. All registrations MUST go through `OCP\Util::addScript` / `OCP\Util::addStyle` using `Application::APP_ID` as the application namespace.

#### Scenario: All four scripts are registered in order
- GIVEN the widget is invoked by the dashboard framework
- WHEN `load()` executes
- THEN `Util::addScript` MUST be called four times with file arguments `"<APP_ID>-runtime"`, `"<APP_ID>-shared-vendor"`, `"<APP_ID>-shared-nc-vue"`, `"<APP_ID>-conceptOrganisatiesWidget"` in that order
- AND `Util::addStyle` MUST be called once with file argument `"dashboardWidgets"`

#### Scenario: Application namespace is used
- GIVEN `Application::APP_ID = "softwarecatalog"`
- WHEN `load()` executes
- THEN every `Util::addScript` / `Util::addStyle` call MUST use `"softwarecatalog"` as the `application` argument

## Notes

- **Dependency-order is load-bearing.** Runtime must come before vendor, vendor before nc-vue, nc-vue before the widget entry — this matches webpack's `splitChunks` + `runtimeChunk` output and the page would error on any other order.
- **IWidget metadata getters** (`getId`, `getTitle`, `getOrder`, `getIconClass`, `getUrl`) are framework getters and are bucketed as `plumbing` in the coverage report. They are not part of this REQ.
- **Acceptance Criteria:** Verified by loading the dashboard in a browser and confirming the widget renders (no JS errors in console). No unit coverage at retrofit time.
