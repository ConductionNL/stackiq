---
kind: code
depends_on:
  - sharing-itsm-exchange
---

# Publish the landscape without its contacts, costs and internal judgements

## Summary

OpenCatalogi publishes stackiq's landscape (opencatalogi#1708, change `publish-from-stackiq`). OpenRegister then answers anonymous readers with whole objects, so a published application also shows its contact person, its DPIA document, and the organisations that use it. This change ships per-field read rules in stackiq, so those fields reach only signed-in users. It makes an organisation's applications in use public only from their publication date. It also stops a version of an unpublished application from being public.

## Why

Lane oc-pub found it live on the second Rotterdam stack (`oc-pub-STATE.md`). Anonymous search returned contact persons and internal fields of published applications. It also returned a version of an application that was not published.

The fields belong to stackiq's schemas, so the rules ship in stackiq.

## What stackiq has today

- Schema read rules: `module` and `catalogService` are public from their `publicationDate`. `module` is also public when a supplier registered it. `moduleVersion`, `suite`, `compliancy` and the GEMMA schemas read `public` without a condition. `usage` and `connection` are readable by their organisations only. `catalogContract`, `contactPerson` and `aiSystem` are never public.
- No property carries a read rule, except `usage.interneAnnotation` and `organization.contactpersonen`.
- `moduleVersion` has no field that says whether its application is published. OpenRegister read rules match only fields of the object itself.
- Fragments merge in file name order, and the last one decides a schema's `version` even when it is lower. A fragment that sorts early can then lose its version bump to one that sorts later, and OpenRegister skips the change (`sharing-itsm-exchange` design D1 met this).

## What this change builds

1. `lib/Settings/register.d/publication-field-rules.json`: `authorization.read: ["authenticated"]` on 52 properties of `module`, `moduleVersion`, `suite`, `catalogService`, `connection`, `usage` and `organization`. It covers contacts, owners, the service desk reference, internal assessments, planned replacement and the DPIA and processing register references.
2. On `usage`, a public read rule from `publicationDate`. A fragment replaces an authorization list, so the fragment repeats `usage`'s existing read rules in full.
3. On `moduleVersion`, two mirrored fields, `modulePublicationDate` and `moduleRegisteredBy`. A listener copies them from the module whenever either is saved, and a repair step backfills existing versions. The version's read rule becomes "signed in", or "public" under the same two conditions as its module.
4. `SettingsService` keeps the highest schema version across fragments, whatever the file names.

## Out of scope

- OpenRegister still puts property-ruled values in `@self.relations` and `@self.description`, and in explicit `_facets` buckets. Lane or-gh fixes that in OpenRegister. These rules are proven on the object body.
- `usage.interneAnnotation` and `organization.contactpersonen` keep their own rules. A fragment rule would replace them with a wider one.
