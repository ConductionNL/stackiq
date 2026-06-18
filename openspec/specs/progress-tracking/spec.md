---
status: done
---

# progress-tracking Specification

## Purpose
Tracks the progress of long-running sync and import operations: it starts a tracked operation with a unique id, updates phase, processed items and statistics, and persists each snapshot to the session. It collects errors and warnings without aborting, finalises an operation at 100 percent, exposes snapshots for the active or a referenced operation, and cleans up stale progress records.

@e2e exclude PHP sync/import progress-tracking backend (job progress state, percentage/counter updates) — no UI surface; covered by PHPUnit service tests.

## Requirements
### Requirement: The system SHALL start a tracked operation and return a unique operation id (REQ-001)

`startOperation(operationType, options)` MUST initialise the in-memory progress state with phase `initializing`, `processed_items = 0`, `start_time = time()`, and `total_items` / `statistics` from `options` when supplied. It MUST generate a unique operation id of the form `{operationType}_{uniqid-with-entropy}` and persist the initial snapshot to the session before returning.

#### Scenario: New operation receives a unique id
- GIVEN no operation is currently being tracked
- WHEN `startOperation('import', ['total_items' => 42])` is called
- THEN the returned id MUST match the pattern `import_<entropy>`
- AND `getProgress()` MUST return a snapshot whose `phase` equals `'initializing'`
- AND whose `total_items` equals `42`
- AND whose `start_time` is set to the current Unix timestamp

#### Scenario: Snapshot is persisted to session immediately
- GIVEN `startOperation('export')` has been called
- WHEN the session is inspected for key `progress_<operationId>`
- THEN the stored value MUST equal the in-memory progress state

### Requirement: The system SHALL update phase, items, and statistics for the active operation (REQ-002)

`setPhase(phase, data)`, `updateProgress(processedItems, currentItem, itemType)`, `incrementProgress(currentItem, itemType)`, and `updateStatistics(statistics)` MUST mutate the in-memory progress state and persist the new snapshot to the session on each call. `setPhase` MUST ignore unknown phase identifiers (log a warning, no state change). `updateStatistics` MUST array-merge the supplied stats into the existing `statistics` map.

#### Scenario: Unknown phase is rejected
- GIVEN an operation is in phase `initializing`
- WHEN `setPhase('not-a-real-phase')` is called
- THEN the progress state's `phase` MUST remain `'initializing'`
- AND a warning MUST be logged with the unknown phase identifier

#### Scenario: incrementProgress advances by one
- GIVEN an operation with `processed_items = 5`
- WHEN `incrementProgress('Element-X', 'element')` is called
- THEN `processed_items` MUST equal `6`
- AND `current_item_name` MUST equal `'Element-X'`

#### Scenario: Statistics are merged not replaced
- GIVEN an operation with `statistics = { elements: 10 }`
- WHEN `updateStatistics(['relationships' => 5])` is called
- THEN `statistics` MUST equal `{ elements: 10, relationships: 5 }`

### Requirement: The system SHALL collect operation errors and warnings without aborting (REQ-003)

`addError(message, context)` and `addWarning(message, context)` MUST append a `{ message, context, timestamp }` record to the `errors` or `warnings` array respectively and persist the snapshot. Errors MUST also be logged at error level; warnings are not auto-logged. Neither method MUST throw — callers can continue the operation after recording.

#### Scenario: Error is recorded and logged
- GIVEN an in-flight operation
- WHEN `addError('Parse failed', ['file' => 'a.xml'])` is called
- THEN the progress snapshot's `errors` array MUST contain one entry with `message = 'Parse failed'` and `context.file = 'a.xml'`
- AND an error-level log entry MUST have been emitted with `operation_id`, `message`, and `context`

### Requirement: The system SHALL finalise an operation and snapshot its terminal state (REQ-004)

`completeOperation(finalStatistics)` MUST set `phase = 'completed'`, `percentage = 100`, `processed_items = total_items`, and `estimated_completion = time()`. If `finalStatistics` is non-empty it MUST be merged into `statistics`. The terminal snapshot MUST be persisted and an info-level log entry emitted with duration, total items, error count, and warning count.

#### Scenario: Completion forces 100% and merges final stats
- GIVEN an in-flight operation with `percentage = 50` and `statistics = { processed: 10 }`
- WHEN `completeOperation(['skipped' => 2])` is called
- THEN `phase` MUST equal `'completed'`
- AND `percentage` MUST equal `100`
- AND `statistics` MUST equal `{ processed: 10, skipped: 2 }`
- AND an info-level log entry MUST include `operation_id` and `duration`

### Requirement: The system SHALL expose progress snapshots for the active or a referenced operation (REQ-005)

`getProgress(operationId)` MUST return the in-memory snapshot when `operationId` is null and the current operation has a non-null id. When `operationId` is supplied and does not match the in-memory operation, it MUST look up the snapshot from session key `progress_{operationId}`. It MUST return `null` when neither match yields a snapshot.

#### Scenario: Active operation snapshot
- GIVEN an in-flight operation with id `import_abc`
- WHEN `getProgress()` is called with no argument
- THEN it MUST return the current progress array

#### Scenario: Cross-session lookup by id
- GIVEN session key `progress_import_xyz` holds a previously-persisted snapshot
- WHEN `getProgress('import_xyz')` is called
- THEN it MUST return the stored snapshot

#### Scenario: Unknown id returns null
- GIVEN no in-flight operation and no matching session key
- WHEN `getProgress('does-not-exist')` is called
- THEN it MUST return `null`

### Requirement: The system SHALL clean up stale progress-tracking records (REQ-006)

`ProgressTracker::cleanupOldProgress(maxAge)` MUST remove progress records older than `maxAge` seconds (default 3600), preventing unbounded accumulation of completed/abandoned operation state.

#### Scenario: REQ-006 case 1
- WHEN `cleanupOldProgress(3600)` is called
- THEN progress records older than one hour MUST be removed

