---
kind: code
---

# An anonymous caller reads the published aanbod

## Why

Two specs disagreed. `open-data-publishing` ("Legacy @PublicPage read endpoints serve only published data to anonymous callers") says an anonymous `GET /api/aanbod` returns the published entries. `vendor-visibility-rbac` REQ-009 says the endpoint rejects every anonymous caller. Ruben decided on 12 Nov 2026 (decision 392): published entries are publicly readable without login, and everything else needs login.

The code follows the old REQ-009 today. `AanbodController::getAanbod()` (`lib/Controller/AanbodController.php:161-163`) returns `rejectUnauthenticated` for any anonymous session. So the anonymous scenario in `open-data-publishing` is not built. Anonymous reads through OpenRegister's public read rules (`share-public-browse`, `share-public-api`) are not affected.

## What changes

- `vendor-visibility-rbac` REQ-009 is replaced. The controller still decides explicitly, before the service runs. An anonymous caller now gets the published aanbod entries in the sanitized open-data projection. Unpublished entries and organisation-scoped fields keep needing login.
- Code: `AanbodController::getAanbod()` answers an anonymous caller with published entries only (task 1.1).
