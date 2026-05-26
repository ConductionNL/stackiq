---
retrofit_extensions:
  - REQ-006
---

# Progress Tracking Specification

## ADDED Requirements

### REQ-006: The system SHALL clean up stale progress-tracking records

`ProgressTracker::cleanupOldProgress(maxAge)` MUST remove progress records older than `maxAge` seconds (default 3600), preventing unbounded accumulation of completed/abandoned operation state.

#### Scenario: REQ-006 case 1
- WHEN `cleanupOldProgress(3600)` is called
- THEN progress records older than one hour MUST be removed
