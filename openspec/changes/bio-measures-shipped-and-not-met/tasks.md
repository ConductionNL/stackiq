# Tasks: bio-measures-shipped-and-not-met

## Implementation tasks

### Task 1: Level column on the BIO measures page (board SkStandaarden)
- **spec_ref**: openspec/changes/bio-measures-shipped-and-not-met/specs/bio-compliance-assessment/spec.md#requirement-the-bio-20-measures-ship-with-the-app-and-show-their-level-req-bio-010
- **files**: `src/manifest.json`, `tests/vitest/bioMeasuresPage.spec.js`
- **acceptance_criteria**:
  - GIVEN the BioMaatregelen page WHEN read THEN its columns are code, name, thema, bioVersion and bbnLevel, and the detail `_note` names `bbnLevel`
- [ ] Implement
- [ ] Test (vitest `bioMeasuresPage.spec.js`)

### Task 2: Ship the BIO 2.0 measures
- **spec_ref**: openspec/changes/bio-measures-shipped-and-not-met/specs/bio-compliance-assessment/spec.md#requirement-the-bio-20-measures-ship-with-the-app-and-show-their-level-req-bio-010
- **files**: `lib/Settings/register.d/bio-measures.json`, `lib/Settings/register.d/README.md`, `tests/Unit/Settings/BioMeasuresFragmentTest.php`
- **acceptance_criteria**:
  - GIVEN the fragment WHEN read THEN every BIO 2.0 measure has code, name, thema, bbnLevel, bioVersion "2.0" and bron with the source URL
  - GIVEN an existing measure with the same code WHEN the register imports THEN it is not duplicated or overwritten (objects keyed on code)
- [ ] Implement
- [ ] Test (PHPUnit `BioMeasuresFragmentTest`)

### Task 3: A result on the compliance claim
- **spec_ref**: openspec/changes/bio-measures-shipped-and-not-met/specs/bio-compliance-assessment/spec.md#requirement-a-compliance-claim-says-whether-the-application-meets-the-measure-req-bio-011
- **files**: `lib/Settings/softwarecatalogus_register.json`, `lib/Repair/DefaultComplianceResultToMeets.php`, `appinfo/info.xml`, `tests/Unit/Repair/DefaultComplianceResultToMeetsTest.php`
- **acceptance_criteria**:
  - GIVEN the compliancy schema WHEN read THEN it has `result` with enum meets, does-not-meet and default meets
  - GIVEN claims without a result WHEN the repair step runs THEN each gets "meets", and a second run changes nothing
- [ ] Implement
- [ ] Test (PHPUnit `DefaultComplianceResultToMeetsTest`)

### Task 4: Four states in the matrix and the compliance list (board SkComplianceMatrix)
- **spec_ref**: openspec/changes/bio-measures-shipped-and-not-met/specs/bio-compliance-assessment/spec.md#requirement-a-compliance-claim-says-whether-the-application-meets-the-measure-req-bio-011
- **files**: `src/views/ComplianceMatrixView.vue`, `src/manifest.json`, `tests/vitest/complianceMatrixStates.spec.js`, `l10n/en.json`, `l10n/nl.json`
- **acceptance_criteria**:
  - GIVEN claims with and without evidence and with result does-not-meet WHEN the matrix renders THEN cells show Verified, Claimed, Does not meet or Unknown with the legend, and the summary counts Does not meet
  - GIVEN the md-compliance list WHEN it renders THEN it has a Result column, and the matrix reads `bioMeasure`, not `bioMaatregel`
- [ ] Implement
- [ ] Test (vitest `complianceMatrixStates.spec.js`)
