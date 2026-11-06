# module-versions Specification (delta)

## Purpose

How stackiq records the released versions of an application and where each version is in its lifecycle. Covers row land-module-versions.

## ADDED Requirements

### Requirement: A released version of an application is recorded with its lifecycle dates (REQ-MVR-001)

stackiq SHALL record each released version of an application as a module version linked to its application, with a version number, a status and the dates it entered development, came into use, leaves support and was withdrawn. The Module versions page SHALL list the versions with application, version, in-development date, in-use date and status, and SHALL offer a quick filter for planned releases. A version SHALL open on its own detail page with its data, its application and usages, its compliance claims and its release files.

#### Scenario: A supplier registers a new version
- GIVEN an application "OpenRegister" offered by the supplier
- WHEN the supplier adds version 1.5.0 with in-use date 6 October 2026
- THEN the Module versions page lists 1.5.0 for OpenRegister with that date

#### Scenario: Planned releases
- GIVEN a version with status "in development"
- WHEN a user picks the quick filter "Planned releases"
- THEN only versions in development are listed

#### Scenario: A version opens on its own page
- GIVEN version 1.4.2 of OpenRegister
- WHEN a user opens it from the list
- THEN its detail page shows the version data, the application and its usages, the compliance claims and the release files
