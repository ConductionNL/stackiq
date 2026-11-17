# email-delivery Specification (delta)

## ADDED Requirements

### Requirement: An administrator edits and saves a mail template (REQ-010)

The mail settings SHALL let an administrator open a template, edit it, see the variables it may use, save it through `POST /api/email/templates/{templateName}`, and reset it to the default. Save SHALL report success only when the backend confirms it, and SHALL show the backend's error otherwise. The next mail of that kind SHALL use the saved template.

#### Scenario: Saving an edited activation mail
- GIVEN an administrator edits the activation template
- WHEN they choose Save
- THEN the template is stored, a success message shows, and reopening the template shows the edit

#### Scenario: The backend refuses
- GIVEN the template content is empty
- WHEN the administrator chooses Save
- THEN the error "Template content is required" shows and no success message appears

#### Scenario: Reset to default
- GIVEN an edited template
- WHEN the administrator chooses Reset to default and saves
- THEN the default text from `GET /api/email/templates/{templateName}/default` is stored

### Requirement: The settings say when mail is not being sent (REQ-011)

When the mail transport is `null` or not configured, the mail settings and the user and group settings SHALL show a warning that registration and activation mails are not sent until a transport is set, with a link to the transport settings.

#### Scenario: A fresh install
- GIVEN the transport is the default `null`
- WHEN an administrator opens the mail settings
- THEN a warning says no mail is sent until a transport is configured
