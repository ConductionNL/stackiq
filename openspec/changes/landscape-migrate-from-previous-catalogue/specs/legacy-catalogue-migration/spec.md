# legacy-catalogue-migration Specification (delta)

## Purpose

How an organisation brings its registrations from the previous VNG Softwarecatalogus into stackiq. Covers row land-migrate-legacy.

## ADDED Requirements

### Requirement: An organisation imports its export from the previous catalogue (REQ-LCM-001)

The Applications page actions menu SHALL offer "Bring over from the previous Softwarecatalogus" to users who may register usages for the active organisation. It SHALL accept the previous catalogue's export as xlsx or csv within the import limits, read it with a dedicated import profile, and SHALL NOT write anything before the user confirms the preview.

#### Scenario: Opening the import
- GIVEN a usage manager of Gemeente Zuiddrecht on the Applications page
- WHEN they choose "Bring over from the previous Softwarecatalogus" and pick the export file
- THEN the preview step opens and no object has been created

#### Scenario: A file that is not an export
- GIVEN a spreadsheet without the expected columns
- WHEN it is uploaded
- THEN the import says which required columns are missing and stops

### Requirement: The preview shows the mapping before anything is written (REQ-LCM-002)

The preview SHALL show, per row of the export, the supplier, product, version and usage it maps to and whether each will be matched to an existing object or created as a concept. Matching SHALL use the KvK number, then the exact name, for suppliers; the supplier and name for products; the version number for versions. The user SHALL be able to leave rows out before confirming.

#### Scenario: A known supplier and product
- GIVEN the export lists Centric, Key2Financiën 2026.1, and stackiq holds Centric and Key2Financiën
- WHEN the preview renders
- THEN the row shows "matched" for supplier and product and "create" for the usage

#### Scenario: An unknown product
- GIVEN the export lists a product stackiq does not hold
- WHEN the preview renders
- THEN the row shows the product will be created as a concept

### Requirement: The result says what was created, updated and skipped (REQ-LCM-003)

After confirming, the import SHALL create or update the usages of the active organisation with the matched or created products and versions, link their reference components and connections where they resolve, and store the previous catalogue's id as `legacyId`. A second run of the same export SHALL update, not duplicate. The result step SHALL count created, updated and skipped rows and list each skipped row with its reason.

#### Scenario: The first run
- GIVEN a preview with 120 rows, 4 left out
- WHEN the user confirms
- THEN 116 usages exist for the organisation and the result reads created 116, updated 0, skipped 4

#### Scenario: Running it again
- GIVEN the same export a week later with one changed version
- WHEN the import runs
- THEN no usage is duplicated and the result reads updated 1
