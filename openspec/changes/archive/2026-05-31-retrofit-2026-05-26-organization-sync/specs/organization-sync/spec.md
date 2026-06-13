---
status: draft
retrofit: true
---

# Organization Sync Specification

## Purpose

Captures observed behavior of OrganizationSyncService — the batch synchronisation pipeline that mirrors SoftwareCatalog organisations, contacts and users into OpenRegister, including scheduled/manual/optimised variants, single-record processing, status reporting, and sync-time bookkeeping.

## ADDED Requirements

### Requirement: The system SHALL run batched organisation/contact/user synchronisation in multiple modes (REQ-001)

`performOrganizationsSync`, `performContactSync`, `performUserSync`, and `performFullSync(minutesBack)` MUST sync the respective entity type from SoftwareCatalog into OpenRegister in batches with an execution-time budget, returning sync statistics. The orchestration variants `performScheduledSync`, `performManualSync`, and `performOptimizedManualSync` MUST drive the full pipeline for cron / on-demand / multi-round optimised execution.

#### Scenario: REQ-001 case 1
- WHEN `performFullSync(10)` is called
- THEN organisations, contacts and users changed in the last 10 minutes MUST be synced and stats returned

#### Scenario: REQ-001 case 2
- WHEN `performOptimizedManualSync(maxRounds, batchSize)` is called
- THEN it MUST run repeated rounds until no further work remains or the round cap is reached

### Requirement: The system SHALL process individual organisation/contact records and ensure their OpenRegister entity (REQ-002)

`processSpecificOrganization(obj)` and `processSpecificContactPerson(obj)` MUST sync a single record; `ensureOrganisationEntityPublic(obj,&stats,sendEmails)` MUST guarantee an OpenRegister organisation entity exists for the SoftwareCatalog organisation (optionally sending notification emails) and update the running stats.

#### Scenario: REQ-002 case 1
- WHEN `processSpecificOrganization(obj)` is called
- THEN that one organisation MUST be synced to OpenRegister

#### Scenario: REQ-002 case 2
- WHEN `ensureOrganisationEntityPublic(obj, stats, true)` is called for a new organisation
- THEN the OR entity MUST be created and notification emails sent

### Requirement: The system SHALL report synchronisation status and record sync timestamps (REQ-003)

`getSyncStatus(minutesBack)` and `getSyncStatusWithErrorHandling(minutesBack)` MUST report what would/did change in the window (the latter never throwing); `recordSyncTime()` MUST persist the last-sync timestamp used to scope incremental syncs.

#### Scenario: REQ-003 case 1
- WHEN `getSyncStatus(10)` is called
- THEN it MUST report the records changed in the last 10 minutes

#### Scenario: REQ-003 case 2
- WHEN `recordSyncTime()` is called after a sync
- THEN the last-sync timestamp MUST be persisted
