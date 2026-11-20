---
description: Run the Stackiq unit tests (PHPUnit and Vitest)
---

Run the unit tests from the repo root. Requires `composer install` and `npm ci`.

1. PHP unit tests (`phpunit-unit.xml`, tests in `tests/Unit/`):

   ```bash
   composer test:unit
   ```

   To run one test file: `vendor/bin/phpunit -c phpunit-unit.xml <path-to-test>`.

2. JavaScript unit tests (Vitest, `vitest.config.js`, specs in `tests/vitest/`):

   ```bash
   npm test
   ```

   To run one spec: `npx vitest run <path-to-spec>`.

Report the totals and, for each failure, the test name and assertion message. If `$ARGUMENTS` names a file, run only that file with the matching single-file command above.
