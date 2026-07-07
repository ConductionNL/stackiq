# Tasks — fix-licence-declaration-consistency

## 1. Store / package metadata

- [ ] 1.1 `appinfo/info.xml`: change `<licence>agpl</licence>` to
  `<licence>EUPL-1.2</licence>` (match pipelinq's form).
- [ ] 1.2 Confirm `composer.json` (`"license": "EUPL-1.2"`) and
  `publiccode.yml` (`license: EUPL-1.2`) already agree — no edit expected;
  record the confirmation.

## 2. PHP SPDX headers

- [ ] 2.1 In each `lib/**/*.php` file whose docblock contains
  `SPDX-License-Identifier: AGPL-3.0-or-later`, replace that line with
  `SPDX-License-Identifier: EUPL-1.2` using the editor (no sed/awk/scripts —
  CLAUDE.md hard rule). ~66 files. Preserve `@copyright`/author lines.
- [ ] 2.2 Leave the 12 files already declaring EUPL-1.2 untouched.
- [ ] 2.3 Verify: `grep -rl 'AGPL-3.0' lib/ --include='*.php'` returns nothing;
  `grep -rl 'EUPL-1.2' lib/ --include='*.php' | wc -l` equals the PHP file count.

## 3. Gates

- [ ] 3.1 Run the SPDX gate (`hydra-gate-spdx`) — every `lib/` PHP file has a
  valid `@license`/SPDX tag and it is EUPL-1.2.
- [ ] 3.2 `composer check:strict` stays green (PHPCS licence-header sniff, if
  configured, now matches).

## 4. Docs / verification

- [ ] 4.1 Screenshot the App Store / `occ app:list` licence field showing
  EUPL-1.2 for the docs/change log.
- [ ] 4.2 Record in the change log: no NC `min-version` change was made
  (out of scope per design.md Decision 3).
