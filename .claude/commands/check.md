---
description: Run the Stackiq quality suite (PHP lint, static analysis, frontend lint and translation checks)
---

Run this repo's quality suite from the repo root and report each failure with its file and line. Do not change baselines or suppression files to make a check pass.

Requires `composer install` and `npm ci` to have been run.

1. PHP suite (lint, phpcs, phpmd, psalm, phpstan, then the full PHPUnit run; `test:all` skips itself outside a Nextcloud server tree):

   ```bash
   composer check:strict
   ```

   For a faster pass use `composer check` (lint, phpcs, psalm, unit tests).

2. Frontend lint and formatting:

   ```bash
   npm run lint
   npm run stylelint
   npm run format
   ```

3. Translation and manifest checks:

   ```bash
   npm run test:l10n
   npm run check:manifest
   ```

Summarise which commands passed and which failed. If a failure is fixable automatically, the fix commands are `composer phpcs:fix` and `npm run lint-fix`; only run them when asked.
