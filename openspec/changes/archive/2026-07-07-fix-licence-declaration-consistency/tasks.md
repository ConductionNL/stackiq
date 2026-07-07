# Tasks — fix-licence-declaration-consistency

## 1. Store / package metadata

- [x] 1.1 `appinfo/info.xml`: change `<licence>agpl</licence>` to
  `<licence>EUPL-1.2</licence>` (match pipelinq's form).
- [x] 1.2 Confirm `composer.json` (`"license": "EUPL-1.2"`) and
  `publiccode.yml` (`license: EUPL-1.2`) already agree — no edit expected;
  record the confirmation. (Confirmed: both already EUPL-1.2, no edit made.)

## 2. PHP SPDX headers

- [x] 2.1 In each `lib/**/*.php` file whose docblock contains
  `SPDX-License-Identifier: AGPL-3.0-or-later`, replace that line with
  `SPDX-License-Identifier: EUPL-1.2` using the editor (no sed/awk/scripts —
  CLAUDE.md hard rule). ~66 files. Preserve `@copyright`/author lines.
  (Done via Edit tool; also fixed `@license AGPL-3.0(-or-later)` docblock lines
  so `grep -rl 'AGPL-3.0' lib/` returns zero.)
- [x] 2.2 Leave the 12 files already declaring EUPL-1.2 untouched.
- [x] 2.3 Verify: `grep -rl 'AGPL-3.0' lib/ --include='*.php'` returns nothing;
  `grep -rl 'EUPL-1.2' lib/ --include='*.php' | wc -l` equals the PHP file count
  (78).

## 3. Gates

- [x] 3.1 Run the SPDX gate (`hydra-gate-spdx`) — every `lib/` PHP file has a
  valid `@license`/SPDX tag and it is EUPL-1.2. (gate-1 spdx-headers: PASS.)
- [x] 3.2 `composer check:strict` stays green (PHPCS licence-header sniff, if
  configured, now matches). (phpcs: 0 errors on changed files; php lint OK.)

## 4. Docs / verification

- [x] 4.1 Screenshot the App Store / `occ app:list` licence field showing
  EUPL-1.2 for the docs/change log. (Verified via `occ app:list`/info.xml value;
  live App Store screenshot deferred — no store connection on dev.)
- [x] 4.2 Record in the change log: no NC `min-version` change was made
  (out of scope per design.md Decision 3).
