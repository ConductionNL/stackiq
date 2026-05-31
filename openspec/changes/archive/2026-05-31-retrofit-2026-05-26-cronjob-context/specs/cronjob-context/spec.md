---
status: draft
retrofit: true
---

# Cronjob Context Specification

## Purpose

Captures observed behavior of the cronjob user/organisation context trait and the scheduled organisation-contact sync background job.

## ADDED Requirements

### Requirement: The system SHALL set and clear a per-job user/organisation context for cronjob execution (REQ-001)

`CronjobContextTrait::setCronjobContext(jobId)` MUST resolve the configured cronjob user/organisation from app config and establish it for the duration of the job, returning whether the context was successfully set. `clearCronjobContext(jobId)` MUST tear that context down afterwards. The trait is documented as deprecated (sync now runs with `_rbac:false`) but the methods remain wired.

#### Scenario: REQ-001 case 1
- WHEN `setCronjobContext('org-sync')` is called with a configured user
- THEN it MUST return a boolean indicating whether context was established

#### Scenario: REQ-001 case 2
- WHEN `clearCronjobContext('org-sync')` is called after a job
- THEN it MUST reset the previously-set user/organisation context

### Requirement: The system SHALL run the scheduled organisation-contact synchronisation as a background job (REQ-002)

`OrganizationContactSyncJob::run(argument)` MUST execute the organisation + contact synchronisation pipeline on its timed interval, operating as a system-level (non-RBAC) sync.

#### Scenario: REQ-002 case 1
- WHEN the background job interval elapses
- THEN `run()` MUST trigger the organisation/contact sync pipeline
