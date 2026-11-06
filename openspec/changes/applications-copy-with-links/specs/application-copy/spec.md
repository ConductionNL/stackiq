# application-copy Specification (delta)

## Purpose

Starting a new application or service entry from an existing one, links included. Covers row land-copy-entry.

## ADDED Requirements

### Requirement: An application is copied with the links the user picks (REQ-ACP-001)

The Applications and Services pages SHALL offer Copy for one entry and for a selection. The copy dialog SHALL list the source's links grouped by kind (relation rows such as connections and reference components, objects that point at the source such as services, and attached files) and SHALL let the user tick which kinds the copy takes along. The copy SHALL link to the same objects as the source and SHALL NOT duplicate them. A copy SHALL be created in one request, so a failure leaves no half-linked copy, and SHALL start unpublished.

#### Scenario: Copying an application with its connections
- GIVEN the application "Mozard 2026.1" with three connections and two reference components
- WHEN a user chooses Copy, names the copy "Mozard 2026.2" and ticks connections and reference components
- THEN "Mozard 2026.2" exists with the same three connections and two reference components, unpublished, and the source is unchanged

#### Scenario: Copying fields only
- GIVEN the same dialog
- WHEN the user ticks nothing
- THEN the copy has the source's fields and no links

#### Scenario: The copy endpoint is not available
- GIVEN an OpenRegister without the object copy endpoint
- WHEN the dialog opens
- THEN the links are listed read only with a note that they are not copied yet, and Copy creates a fields-only copy
