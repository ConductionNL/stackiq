# Design: security-baseline-classification

Read at development 49e65cb4, with OpenRegister development 4fee776 and the lead's merged changes on development 9a5ece6a.

## Where it fits

| Part | File and line | What changes |
|---|---|---|
| Fragment | new `lib/Settings/register.d/security-baseline-classification.json` (ADR-037) | nine properties on `usage` (`lib/Settings/softwarecatalogus_register.json:2654`), each with the property read and update rule `usage.interneAnnotation` uses; a higher `usage` version |
| Subscriber | new `lib/EventListener/UsageClassificationSubscriber.php`, registered in `lib/AppInfo/Application.php` next to `ModuleComplianceSubscriber` (`:802` and `:803`) | on create and update of a `usage`, sets `bbnLevel` to the highest aspect and stamps `classifiedAt` and `classifiedBy` when an aspect changed |
| Service | new `lib/Service/UsageClassificationService.php` | the GEMMA suggestion and the cross-organisation summary |
| Controller and routes | new `lib/Controller/UsageClassificationController.php`; `GET /api/gebruik/{usageId}/classification-suggestion` and `GET /api/modules/{moduleId}/classification-summary` in `appinfo/routes.php` | |
| Page | `GebruikDetail` in `src/manifest.d/usages.json` (from `landscape-usage-registration`) | a body widget `UsageClassificationPanel` |
| Page | `Gebruik` in `src/manifest.d/usages.json` | a `bbnLevel` column and quick filters BBN1, BBN2, BBN3 and Not classified |
| Page | `src/manifest.json:491` `ModuleDetail` | a body widget `ModuleClassificationSummary` |
| Components | new `src/components/usages/UsageClassificationPanel.vue`, `src/components/modules/ModuleClassificationSummary.vue`, registered in `src/customComponents.js` | `CnWidgetWrapper` and `CnProgressBar` from the library |

## The properties

| Property | Type | Notes |
|---|---|---|
| `availabilityLevel`, `integrityLevel`, `confidentialityLevel` | enum BBN1, BBN2, BBN3 | the BIO baseline level per aspect |
| `availabilityReason`, `integrityReason`, `confidentialityReason` | string | the main reason, as GEMMA records one per aspect |
| `bbnLevel` | enum BBN1, BBN2, BBN3 | derived, the highest of the three; read-only in the form |
| `classifiedAt` | date-time | stamped when an aspect changes |
| `classifiedBy` | string | the user id that changed it |

Each property carries `authorization` `read` and `update` of `{"group": "public", "match": {"_organisation": "$organisation"}}`, the rule `usage.interneAnnotation` already carries (`lib/Settings/softwarecatalogus_register.json:2901`). A supplier who may read the usage of its product (the `aanbod-beheerder` read rule on `provider`) does not see the classification.

## Decisions

### D1. The classification belongs to the organisation that uses the application

The request is a CISO classifying "de pakketten in mijn pakketoverzicht": the applications their organisation uses. BIO classification depends on the data an organisation puts in an application, so two municipalities can rightly classify one product differently. `usage` is that organisation's record of the application. `module.bbnLevel` stays as the catalogue's level for the product.

Rejected: splitting `module.bbnLevel` into three. It would stay one answer for every municipality, which is the half the row says is missing.

### D2. Three aspects, and the overall level derived from them

GEMMA records Beschikbaarheid, Integriteit and Vertrouwelijkheid per reference component, each on a scale of 1 to 3 with a main reason, next to its BBN (`lib/Settings/GEMMA_release.xml` property definitions `propid-20` to `propid-27`). The usage takes the same shape. `bbnLevel` is the highest of the three, computed by `UsageClassificationSubscriber` on every create and update, so an API write keeps it right too. The subscriber writes only when the derived value or the stamp differs, so its own save does not trigger it again.

Rejected: an `x-openregister-*` rule for the derived field. ADR-031's declarative dialects cover lifecycle, aggregation, notifications and relations; none sets one property from others on the same object.

### D3. The suggestion comes from GEMMA

Suggest from GEMMA reads the reference components the usage names in `usedForReferenceComponents` (`:2982`), or the application's `referenceComponents` (`:6992`) when the usage names none, and takes the highest `element.availability`, `element.integrity` and `element.confidentiality` per aspect, mapping 1 to 3 onto BBN1 to BBN3. It fills the form and does not save; the CISO decides. Components without a score are skipped, and the panel says how many had one.

### D4. Sharing is a count, withheld below three

Other municipalities cannot read another organisation's usages, and OpenRegister's aggregation filters rows by the reader's rights before counting (`AggregateVisibility`), so a shared view needs a server-side summary. `GET /api/modules/{moduleId}/classification-summary` reads the classified usages of that application across organisations with a bounded query, counts distinct consuming organisations, and returns per aspect the number of organisations per level. Below three organisations it returns only the count of organisations and no levels. It answers Nextcloud admins, members of `ambtenaar`, and users whose active organisation has type Municipality or Collaboration; others get 403. It never returns an organisation name or id.

Rejected: showing each organisation's classification by name. The issue asks to share knowledge, and a named security level of one municipality is information an attacker can use.

## Declarative versus imperative

- The properties and their property-level read rules are declarative.
- The derived `bbnLevel` is imperative (D2), in one subscriber.
- The summary is a cross-organisation aggregate with a minimum group size, which OpenRegister's aggregation does not offer (D4), so it is one stackiq endpoint. The proposal names the OpenRegister rule that would replace it.

## Seed data

`usage` gains nine properties. The demo descriptor `lib/Settings/stackiq_mock_register.json` classifies one of its usages in the `stackiq` register, and adds one municipality and two usages of the same application so the summary has three consuming organisations to show:

| `@self.slug` | `consumer` | `module` | availability | integrity | confidentiality | derived |
|---|---|---|---|---|---|---|
| usage-usage-1-1 | `@ref:organization-voorbeeld-name-1-1` | `@ref:module-voorbeeld-name-1-1` | BBN1 | BBN2 | BBN2 | BBN2 |
| usage-classified-2 | `@ref:organization-voorbeeld-name-3-3` | `@ref:module-voorbeeld-name-1-1` | BBN2 | BBN2 | BBN3 | BBN3 |
| usage-classified-3 | `@ref:organization-classification-4` (new, Gemeente Voorbeeldstad, type Municipality) | `@ref:module-voorbeeld-name-1-1` | BBN1 | BBN2 | BBN2 | BBN2 |
| usage-usage-2-2 and usage-usage-3-3 | as seeded | as seeded | empty | empty | empty | empty |

`ModuleDetail` for Voorbeeld Name 1 then shows three organisations: BBN2 twice and BBN3 once overall.

## Risks

- **Disclosure through counts.** Withheld below three organisations, counts only, municipal readers only (D4). The threshold is a constant with a unit test, not a setting, so nobody lowers it by accident.
- **Schema version.** The fragment raises the `usage` version; without it the import skips the new properties.
