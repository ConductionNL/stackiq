---
status: done
---

# email-delivery Specification

## Purpose
Sends the app's transactional notification emails — organisation registration and activation, user creation and update, and test messages — rendering each template and dispatching it via the configured transport, skipping any disabled notification type. Exposes and persists the mail transport, sender, and per-notification configuration and reports whether the email system is fully configured.

@e2e exclude PHP email-delivery backend (IMailer message build/send, template rendering, connection test) — no UI surface; covered by PHPUnit service tests. The email-config UI is covered under fe-settings-ui's settings page.

## Requirements

### Requirement: The system SHALL send the app's transactional notification emails (REQ-001)

`sendOrganizationRegistrationEmail`, `sendOrganizationActivationEmail`, `sendUserCreationEmail`, `sendUserUpdateEmail`, and `sendTestEmail` MUST render the corresponding template and dispatch the message via the configured transport, returning whether the send succeeded. Sends are skipped when the corresponding notification type is disabled.

#### Scenario: REQ-001 case 1
- WHEN `sendUserCreationEmail(user, org)` is called and the type is enabled
- THEN the user-creation email MUST be dispatched and true returned

#### Scenario: REQ-001 case 2
- WHEN a notification type is disabled and its send method is called
- THEN the email MUST NOT be sent

### Requirement: The system SHALL expose and persist the mail transport configuration (REQ-002)

`getSenderEmail`/`setSenderEmail`, `getSenderName`/`setSenderName`, `getEmailSettings`, `getAvailableTransports`, `setTransportConfiguration`, `setEnabled`, `setTestReceiverOverride`, the per-type enable toggles (`setOrganizationRegistrationEnabled`, `setOrganizationActivationEnabled`, `setUserCreationEnabled`), and `isEmailSystemConfigured()` MUST read/persist the transport + sender + per-notification configuration and report whether the system is fully configured.

#### Scenario: REQ-002 case 1
- WHEN `setTransportConfiguration('smtp', cfg)` is called
- THEN the SMTP transport configuration MUST be persisted

#### Scenario: REQ-002 case 2
- WHEN `isEmailSystemConfigured()` is called with a sender and transport set
- THEN it MUST report configured

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
