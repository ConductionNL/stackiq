# settings-service Specification (delta)

## MODIFIED Requirements

### Requirement: The system SHALL run auto-configuration, import and configuration maintenance (REQ-003)

`autoConfigure`, `autoConfigureAfterImport`, `configureOpenCatalogi`, `initialize`, `loadSettings`, `performConsolidatedAutoConfiguration`, `manualImport`, `forceUpdate`, `resetAutoConfiguration`, `compactToJsonConfiguration`, `cleanupOldConfiguration`, and `clearConfigurationCache` MUST create/repair the register-schema configuration in OpenRegister, import seed data, and maintain the cached configuration, returning a result summary. `loadSettings` MUST compute the import version passed to `importFromApp` from the content of both the monolith register file and any merged ADR-037 fragment files, so that a change to either one produces a different version and forces a re-import rather than being silently skipped by the version gate. `initialize` MUST NOT rely on any comparison between this app's own semantic version and the register-content version string stored by a previous `importFromApp` call to decide whether to invoke `loadSettings` at all — those are two unrelated versioning schemes on the same stored value, and comparing them can permanently prevent `loadSettings` from ever running again regardless of subsequent register changes. After a successful import, `loadSettings`/`initialize` MUST verify that every schema slug present in the effective (monolith + fragments) merged register resolves in OpenRegister, and that every schema id this app resolves via its own object-type lookups is non-null, recording any mismatch as a warning rather than allowing a partial or no-op import to be reported as full success.

(Previously: the import version was derived only from the register JSON's own `info.version` field plus a hash of the fragment files — a monolith edit that did not also bump `info.version` produced a byte-identical version string and `importFromApp` silently skipped the import. `initialize` additionally gated entry into `loadSettings` on comparing this app's own semver against the stored register-content version, which could permanently block `loadSettings` from running again at all. No post-import verification existed.)

#### Scenario: REQ-003 case 1

- WHEN `autoConfigure(force=true)` is called
- THEN the registers/schemas MUST be (re)configured and a result summary returned

#### Scenario: REQ-003 case 2

- WHEN `clearConfigurationCache()` is called
- THEN the cached configuration MUST be invalidated

#### Scenario: REQ-003 case 3 — monolith edit alone changes the computed version

- GIVEN the monolith `softwarecatalogus_register.json` content changes but its `info.version` field and all `register.d/*.json` fragment files are unchanged
- WHEN `loadSettings()` computes the import version
- THEN the computed version string MUST differ from the version computed before the monolith content changed
- AND `importFromApp` MUST therefore be invoked with a version that is not already stored for this app, triggering a re-import

#### Scenario: REQ-003 case 4 — no-op import surfaces a warning instead of silent success

- GIVEN an import completes where a schema slug present in the effective merged register does not resolve in OpenRegister
- WHEN `loadSettings()` runs its post-import verification
- THEN a WARNING MUST be logged identifying the unresolved schema slug
- AND the mismatch MUST be included in the result payload returned by `loadSettings()`/`initialize()`

#### Scenario: REQ-003 case 5 — a prior import never blocks re-attempting a later one

- GIVEN a prior successful import stored a register-content version on the app's `Configuration` entity, and this app's own semantic version has since been bumped for an upgrade
- WHEN `initialize()` runs during that upgrade
- THEN `loadSettings()` MUST be invoked regardless of how the stored register-content version compares to the app's own semantic version
- AND the decision of whether an actual re-import occurs MUST come only from `importFromApp`'s comparison of the newly computed content-derived version against the stored one

### Requirement: The system SHALL read and persist every configuration domain (REQ-002)

The service MUST expose get/set (and focused get/update) pairs for voorzieningen, AMEF, email, ArchiMate, user-groups (generic/org-admin/super), cronjob, and catalog location, plus aggregate readers (`getSettings`, `getAllSettings`, `getConsolidatedConfiguration`, `getConfigurationStatus`, `isFullyConfigured`) and writers (`updateSettings`). Each MUST read from / write to app config and return the current or updated values. `getConfigurationStatus` MUST include the outcome of the most recent register-import verification (whether the live schema set matched the effective register, and which schema slugs or object-type lookups, if any, did not resolve) so a no-op or partial import is visible to an admin inspecting settings status rather than looking identical to a fully successful one.

(Previously: `getConfigurationStatus` reported general configuration completeness only; it carried no information about whether the most recent register import had actually reached OpenRegister.)

#### Scenario: REQ-002 case 1

- WHEN `setVoorzieningenConfig(config)` then `getVoorzieningenConfig()` is called
- THEN the persisted config MUST be returned

#### Scenario: REQ-002 case 2

- WHEN `isFullyConfigured()` is called with all required config present
- THEN it MUST return true

#### Scenario: REQ-002 case 3 — status payload surfaces a register verification mismatch

- GIVEN the most recent `loadSettings()` run recorded a schema slug that failed to resolve in OpenRegister
- WHEN `getConfigurationStatus()` is called
- THEN the returned payload MUST include that mismatch so it is visible without inspecting server logs

## ADDED Requirements

### Requirement: The system SHALL call its own OpenRegister configuration import deterministically and account for any duplicate rows found (REQ-006)

The service MUST call `importFromApp` from a single call site with the same, stable app-identifying key on every invocation, so this app's own code can never itself be the cause of multiple `Configuration` rows existing for it. Row-level resolution of "the" configuration for a given `appId` (matching an existing row versus creating a new one) is performed by OpenRegister's `ConfigurationService`, outside this app's control. Where duplicate configuration rows are found to already exist for this app, the service's documentation MUST record how those rows were characterized (root-cause analysis, not just their existence) and either resolve them from this app's side, if the app's own call site is conclusively the cause, or reference a filed upstream issue against the owning system when the true fix belongs there.

#### Scenario: REQ-006 case 1 — this app's own call site is single and stable

- GIVEN `loadSettings()` runs an import
- WHEN the call to `getConfigurationService()->importFromApp()` is inspected
- THEN it MUST always pass this app's own constant `Application::APP_ID` as the `appId` argument from the same single call site, so no duplication can originate from this app varying its own identity across calls

#### Scenario: REQ-006 case 2 — duplicate rows are documented, not silently ignored

- GIVEN more than one configuration row is found to already exist for this app's title/appId
- WHEN the duplication is investigated
- THEN the root-cause finding MUST be recorded (in code comments and docs) rather than left unexplained
- AND WHEN the true fix is determined to belong in OpenRegister rather than this app
- THEN an issue MUST be filed against the owning repository documenting the mechanism, and referenced from this app's code and docs
