---
status: done
---

# concept-organizations-widget Specification

## Purpose
Registers the Concept Organisations dashboard widget, loading its frontend assets (runtime, vendor, nc-vue, and widget chunks plus stylesheet) in correct dependency order through the Nextcloud asset framework under the softwarecatalog application namespace.

@e2e exclude PHP ConceptOrganisatiesWidget::load() asset-registration backend (Util::addScript/addStyle ordering with Application::APP_ID namespace) — server-side dashboard-widget registration with no driveable UI surface; asserted by PHPUnit (mocked Util). The rendered widget's data behaviour is specified under fe-organizations REQ-FE-206 and covered by the manifest dashboard-page test.

## Requirements
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

