# Changelog

## [Unreleased]
### Changed
- Legacy quality cleanup: fixed the single surfacing PHPCS alignment error, captured a `phpmd.baseline.xml` and wired `--baseline-file` into the `composer phpmd` gate (matching the fleet pattern) so the unified quality gate runs green while legacy mess is burned down incrementally. Documented the baseline posture for PHPMD and PHPStan in the README.
- i18n: re-authored 158 Dutch schema property `title` values across the `softwarecatalogus_register.json` register to English (property keys unchanged, no API impact). Added matching `l10n/en.json`/`l10n/en.js` and `l10n/nl.json`/`l10n/nl.js` translation keys so Dutch users continue to see Dutch labels via the app's l10n layer instead of the register.

## 0.1.6 – 2025-06-18
### Added
- New features for this release

### Changed
- Changes in existing functionality for this release

### Fixed
- Bug fixes for this release

## 0.1.5 – 2024-09-07
### Added
- New features for this release

### Changed
- Changes in existing functionality for this release

### Fixed
- Bug fixes for this release

### Added
- Initial release

