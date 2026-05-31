# email-delivery Specification

## Purpose
TBD - created by archiving change retrofit-2026-05-26-email-delivery. Update Purpose after archive.
## Requirements
### Requirement: The system SHALL send the app's transactional notification emails (REQ-001)

`sendOrganizationRegistrationEmail`, `sendOrganizationActivationEmail`, `sendUserCreationEmail`, `sendUserUpdateEmail`, `sendUserPasswordEmail`, and `sendTestEmail` MUST render the corresponding template and dispatch the message via the configured transport, returning whether the send succeeded. Sends are skipped when the corresponding notification type is disabled.

#### Scenario: REQ-001 case 1
- WHEN `sendUserPasswordEmail(user, password, org)` is called and the type is enabled
- THEN the password email MUST be dispatched and true returned

#### Scenario: REQ-001 case 2
- WHEN a notification type is disabled and its send method is called
- THEN the email MUST NOT be sent

### Requirement: The system SHALL expose and persist the mail transport configuration (REQ-002)

`getSenderEmail`/`setSenderEmail`, `getSenderName`/`setSenderName`, `getEmailSettings`, `getAvailableTransports`, `setTransportConfiguration`, `setEnabled`, `setTestReceiverOverride`, the per-type enable toggles (`setOrganizationRegistrationEnabled`, `setOrganizationActivationEnabled`, `setUserCreationEnabled`, `setUserPasswordEnabled`), and `isEmailSystemConfigured()` MUST read/persist the transport + sender + per-notification configuration and report whether the system is fully configured.

#### Scenario: REQ-002 case 1
- WHEN `setTransportConfiguration('smtp', cfg)` is called
- THEN the SMTP transport configuration MUST be persisted

#### Scenario: REQ-002 case 2
- WHEN `isEmailSystemConfigured()` is called with a sender and transport set
- THEN it MUST report configured

