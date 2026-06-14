# softwarecatalog-notifications Specification

## Purpose
TBD - created by archiving change softwarecatalog-notifications. Update Purpose after archive.
## Requirements
### Requirement: Catalogue schemas declare notification rules

The `kwetsbaarheid`, `contract`, `moduleVersie`, and `beoordeeling` schemas SHALL
declare `x-openregister-notifications` rules so the OpenRegister
notification engine dispatches notifications on reported vulnerabilities,
approaching contract expiry, newly published module versions, and submitted
reviews. Every rule SHALL use a trigger type that works today (`created` or
`scheduled`), reference only an existing schema property, the record manage-ACL,
or a named group (never a `field:` recipient pointing at a nested-object or
non-existent property), and provide both `nl` and `en` subject strings.

#### Scenario: Reported vulnerability urgently notifies admins and record managers

- **WHEN** a `kwetsbaarheid` record is created
- **THEN** the engine dispatches `nc-notification` + `email` to the `softwarecatalog-admins` group and the record's manage-ACL holders
- **AND** the subject includes the vulnerability name, CVE code, and CVSS score in the recipient's locale (nl/en)

#### Scenario: New review notifies record managers

- **WHEN** a `beoordeeling` record is created
- **THEN** the engine dispatches an `nc-notification` to the record's manage-ACL holders and the `softwarecatalog-admins` group

#### Scenario: Disabled-by-default contract expiry does not fire until confirmed

- **WHEN** the `contract` `contract-expiry` rule (`enabled: false`) would match
- **THEN** no notification is dispatched until the scheduled date-window filter is confirmed and an admin enables the rule

