# Retrofit — aangeboden-gebruik-api

Describes observed behavior of 15 methods as 3 REQ(s) under the `aangeboden-gebruik-api` capability. Code already exists — this change retroactively specifies it (strict coverage policy: every method gets a spec).

## Affected code units

- lib/Controller/AangebodenGebruikController.php::getGebruiksWhereAfnemer
- lib/Controller/AangebodenGebruikController.php::getAllGebruiksForAmbtenaar
- lib/Controller/AangebodenGebruikController.php::getGebruiksWhereDeelnemers
- lib/Controller/AangebodenGebruikController.php::getSingleGebruikForAmbtenaar
- lib/Controller/AangebodenGebruikController.php::getKoppelingenGebruikByUuid
- lib/Controller/AangebodenGebruikController.php::setGebruikSelfToActiveOrg
- lib/Controller/AangebodenGebruikController.php::deleteGebruikAsAfnemer
- lib/Controller/AangebodenGebruikController.php::getApiDocumentation
- lib/Service/AangebodenGebruikService.php::getGebruiksWhereAfnemer
- lib/Service/AangebodenGebruikService.php::getAllGebruiksForAmbtenaar
- lib/Service/AangebodenGebruikService.php::getGebruiksWhereDeelnemers
- lib/Service/AangebodenGebruikService.php::getSingleGebruikForAmbtenaar
- lib/Service/AangebodenGebruikService.php::getKoppelingenGebruikByUuid
- lib/Service/AangebodenGebruikService.php::setGebruikSelfToActiveOrg
- lib/Service/AangebodenGebruikService.php::deleteGebruikAsAfnemer

## Approach

- Describe observed inputs/outputs/side-effects of each method.
- Group methods implementing the same observable behavior under one REQ.

Source: openspec/coverage-report (csc.py --mode report) generated 2026-05-26. Umbrella: ConductionNL/softwarecatalog#294.
