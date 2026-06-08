# fe-organizations Specification

## Purpose
TBD - created by archiving change retrofit-2026-05-26-fe-organizations. Update Purpose after archive.
## Requirements
### Requirement: Add contact person (REQ-FE-201)

The add-contact-person modal SHALL let the user enter a new contact person for an organisation, validate the form, generate an identifier where needed, and dispatch the create action.

`AddContactpersoonModal.vue` validates the form, generates a UUID for new entries, dispatches the save and closes on success.

@e2e exclude Vue add-contact-person modal — form validation + UUID generation + dispatch-create interaction over a mocked store; tested by vitest component tests. Not a navigable manifest-page render.

#### Scenario: Add a valid contact person
- WHEN the user submits a valid contact-person form
- THEN the modal MUST dispatch the create action and close

### Requirement: Contact persons list & user management (REQ-FE-202)

The contact-persons list SHALL display an organisation's contact persons with their linked Nextcloud-user details and SHALL allow per-person user-management actions (convert to user, change password, enable/disable, update groups).

`ContactpersonenList.vue` fetches contact persons and bulk user info from the store, renders status per person, and dispatches user-management actions with confirmation and result feedback.

@e2e exclude Vue contact-persons list component — list rendering + per-person user-management actions (convert/password/enable-disable/groups) require live contact + linked-user data and dispatch store actions; tested by vitest component tests with a mocked store. The contactpersonen manifest page render is covered separately; the linked-user REST contract is covered by Newman (contactpersonen-api).

#### Scenario: List contact persons with user details
- WHEN the list is opened for an organisation
- THEN it MUST display each contact person with their linked-user status

#### Scenario: Run a user-management action
- WHEN the user triggers a per-person action (e.g. enable/disable, change password)
- THEN the list MUST dispatch the action and reflect the updated state

### Requirement: Create/edit organisation (REQ-FE-203)

The organisation modal SHALL let the user create or edit an organisation, validate the form and dispatch a save to the store.

`OrganisationModal.vue` builds the organisation form, validates it, and dispatches a create/update with success/error feedback.

@e2e exclude Vue organisation create/edit modal — form build + validation + dispatch-save interaction over a mocked store; tested by vitest component tests. Not a navigable manifest-page render.

#### Scenario: Save an organisation
- WHEN the user submits a valid organisation form
- THEN the modal MUST dispatch the save and report the result

### Requirement: Organisation card display & actions (REQ-FE-204)

The organisation card SHALL display an organisation summary and SHALL expose contextual actions and, when its current view is contact persons, refresh the linked user data on view switch.

`OrganisatieCard.vue` formats the organisation summary and website URL, executes object actions, and reloads user data when switching to the contact-persons view.

#### Scenario: Display an organisation card
- WHEN a card renders for an organisation
- THEN it MUST show the formatted summary and available actions

### Requirement: Change organisation status (REQ-FE-205)

The change-status dialog SHALL let the user move an organisation between lifecycle statuses (e.g. concept → published), confirm the change and dispatch it.

`ChangeOrganisatieStatusDialog.vue` confirms the target status and dispatches the status change.

@e2e exclude Vue change-status dialog — confirm + dispatch-status-change interaction over a mocked store; tested by vitest component tests. Not a navigable manifest-page render.

#### Scenario: Change organisation status
- WHEN the user confirms a new status
- THEN the dialog MUST dispatch the status change and report the result

### Requirement: Concept organisations widget (REQ-FE-206)

The concept-organisations dashboard widget SHALL list organisations still in concept status and link to them.

`ConceptOrganisatiesWidget.vue` fetches concept organisations and renders them with navigation links.

#### Scenario: Show concept organisations
- WHEN the dashboard widget loads
- THEN it MUST display the organisations currently in concept status

