---
kind: code
depends_on: []
---

# Share DPIAs, processing agreements and pentest reports between organisations

## Summary

A supplier or a municipality publishes a compliance document about a product (a DPIA, a processing agreement, a pentest report, an assurance report or a certificate) and says who may read it: everyone, every government organisation in the catalogue, or named organisations. Other municipalities find the documents on the product's page and reuse them instead of asking the supplier for the same paperwork again. Each document carries its type, its date and how long it stays valid.

## Why

Row from the stackiq matrix:

- `stackiq:share-compliance-documents`, "Share documents such as DPIAs, processing agreements and pentest reports with other organisations." Rated partial, built. Feature request: https://github.com/VNG-Realisatie/Softwarecatalogus/issues/41 (VNG Softwarecatalogus). No competitor rates yes; the feature request is the demand that makes the missing half matter under the rule for partial rows. The missing half: sharing processing agreements and pentest reports with other organisations as such.

## What stackiq has today

- A compliance claim (`compliancy`, `lib/Settings/softwarecatalogus_register.json:7406` schema) links a product to a standard or BIO measure with an evidence URL and files (`allowFiles`, tag `testraport`), read by everyone (`authorization.read: public`), shown on `KompliantieDetail` (`src/manifest.json:878`).
- The product carries its DPIA as a status, dates and a Nextcloud Files reference (`module.dpiaStatus`, `dpiaDate`, `dpiaNextAssessment`, `dpiaDocumentRef`) and a reference to the processing register entry (`verwerkingsregisterRef`).
- Nothing records a processing agreement, a pentest report or an assurance report, and nothing lets a publisher choose who reads a document: a claim is public or it does not exist.
- OpenRegister read rules can match array membership (`$contains`, `openregister lib/Db/MagicMapper/MagicRbacHandler.php:1085` onwards), which a named-organisations share needs.

## What this change builds

1. A `complianceDocument` schema: the product, the type (DPIA, processing agreement, pentest report, assurance report such as ISAE 3402 or SOC 2, certificate such as ISO 27001, ENSIA statement, other), issued on, valid until, the publishing organisation, a summary, the file, and the audience (public, government organisations, named organisations).
2. Read rules that follow the audience, so a pentest report shared with three municipalities is invisible to everyone else.
3. A Compliance documents section on the product page, with the type, validity and publisher, and Add for the supplier and for a municipality that did its own assessment.
4. A Compliance documents list with filters on type and on documents that expire within 90 days.

## Out of scope

- Verifying that a document is authentic or reviewing its content (`stackiq:comp-verified-vs-claimed`, deferred).
- Generating a processing register from the catalogue (`stackiq:comp-processing-register-generate`, deferred).
- Sharing with parties outside the catalogue without an account: portaliq's contribution contract (open change `portal-contribution`).

## Risks

- A pentest report shared publicly by mistake exposes findings. Public is not the default: a new document starts at named organisations with the publisher only, and the form warns before public is chosen for a pentest report.
