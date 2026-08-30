---
status: done
---

# cronjob-context Specification

## Purpose
Runs the organisation and contact synchronisation pipeline as a scheduled Nextcloud background job, operating with a system-level (non-RBAC) context on its timed interval.

@e2e exclude PHP background-job context backend (system-user/session context setup for scheduled jobs) — runtime plumbing with no UI surface; covered by PHPUnit tests.

## Requirements
### Requirement: The system SHALL run the scheduled organisation-contact synchronisation as a background job (REQ-002)

`OrganizationContactSyncJob::run(argument)` MUST execute the organisation + contact synchronisation pipeline on its timed interval, operating as a system-level (non-RBAC) sync.

#### Scenario: REQ-002 case 1
- WHEN the background job interval elapses
- THEN `run()` MUST trigger the organisation/contact sync pipeline

