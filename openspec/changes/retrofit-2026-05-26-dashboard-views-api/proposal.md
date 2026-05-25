# Retrofit — dashboard-views-api

Describes observed behavior of 9 methods as 3 REQ(s) under the `dashboard-views-api` capability. Code already exists — this change retroactively specifies it (strict coverage policy: every method gets a spec).

## Affected code units

- lib/Controller/DashboardController.php::page
- lib/Controller/DashboardController.php::index
- lib/Controller/ViewController.php::getAllViews
- lib/Controller/ViewController.php::getView
- lib/Controller/ViewController.php::getApiDocumentation
- lib/Service/ViewService.php::getAllViews
- lib/Service/ViewService.php::getView
- lib/Controller/PreferencesController.php::getPreference
- lib/Controller/PreferencesController.php::setPreference

## Approach

- Describe observed inputs/outputs/side-effects of each method.
- Group methods implementing the same observable behavior under one REQ.

Source: openspec/coverage-report (csc.py --mode report) generated 2026-05-26. Umbrella: ConductionNL/softwarecatalog#294.
