---
status: draft
retrofit: true
---

# Cronjob Context Specification

## Purpose

Captures observed behavior of the cronjob user/organisation context trait and the scheduled organisation-contact sync background job.

## ADDED Requirements

### Requirement: The system SHALL run the scheduled organisation-contact synchronisation as a background job (REQ-002)

`OrganizationContactSyncJob::run(argument)` MUST execute the organisation + contact synchronisation pipeline on its timed interval, operating as a system-level (non-RBAC) sync.

#### Scenario: REQ-002 case 1
- WHEN the background job interval elapses
- THEN `run()` MUST trigger the organisation/contact sync pipeline
