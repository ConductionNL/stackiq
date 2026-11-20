<!--
  - SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
  - SPDX-License-Identifier: EUPL-1.2
  -->

# Synchronisation status and progress

**Organisations → Synchronisation** (`/organisaties/synchronisation`) shows
the organisation sync: when it runs, how its last run went and, while a run
is going, how far along it is. The sync keeps organisations, contact persons
and their Nextcloud accounts in step with the catalogue.

Specification: [`openspec/changes/operations-sync-status-and-progress/specs/sync-status-and-progress/spec.md`](https://github.com/ConductionNL/stackiq/blob/development/openspec/changes/operations-sync-status-and-progress/specs/sync-status-and-progress/spec.md).

## Who can see it

- **Nextcloud admins** and **functional administrators** (members of the
  `functioneel-beheerder` group, kept in step with the contact person role
  Functioneel-beheerder) see the menu entry and the page.
- Everyone else does not see the entry, and `GET /api/sync/status` answers
  them with 403.

## What the page shows

- **Schedule** — how often the sync runs (every 5 minutes) and whether the
  scheduled sync is **On** or **Off**. An admin switches it in the admin
  settings (cron jobs); when it is Off the background job does not run.
- **Last run** — when it finished and started, whether the schedule or an
  admin by hand started it, the organisations, entities, contact persons and
  users it processed or created, and the number of errors.
- **Running now** — while a sync is going, a progress bar with its phase
  (organisations, contact persons, users) and percentage. The page asks again
  every three seconds while a run is going and stops when it ends; the bar
  then gives way to the new last run.

Live progress is shared between the background job and the page through the
distributed cache. On an instance with only a local cache the page still
shows the last run, but the bar may not appear; a shared cache such as Redis
makes it work.

## API

`GET /apps/stackiq/api/sync/status` answers:

| Key | Value |
|---|---|
| `interval` | seconds between scheduled runs |
| `enabled` | whether the scheduled sync is on |
| `lastRun` | the last run's record, or `null` |
| `current` | the running operation's progress, or `null` |
