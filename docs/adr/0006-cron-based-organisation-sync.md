# 6. Synchronise organisations and contacts with a background job

## Status

Accepted

## Context

Organisations and their contact persons in the register are turned into Nextcloud organisation entities, users and group memberships. The earlier event-driven processing suffered from race conditions between listeners, inconsistent state when events fired out of order, and was hard to trace.

## Decision

Organisation and contact synchronisation runs as a scheduled background job with a single execution path, every 5 minutes, with detailed logging of each step. Admins can trigger the same logic on demand through `POST /apps/stackiq/api/settings/sync`.

## Consequences

- Execution is predictable and easier to test and debug, at the cost of changes taking effect at the next run rather than immediately, unless a manual sync is triggered.
- The job is `lib/BackgroundJob/OrganizationContactSyncJob.php` (interval from `lib/Kernel/OrganizationContactSyncSchedule.php`), registered in `appinfo/info.xml`; the shared logic is in `lib/Service/OrganizationSyncService.php`.

Source: `docs/ARCHITECTURE.md` (Synchronization Architecture, "Why Cron-Based Instead of Event-Driven?")
