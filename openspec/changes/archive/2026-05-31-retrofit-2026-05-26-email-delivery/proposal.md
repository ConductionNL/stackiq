# Retrofit — email-delivery

Describes observed behavior of 20 methods as 2 REQ(s) under the `email-delivery` capability. Code already exists — this change retroactively specifies it (strict coverage policy: every method gets a spec).

## Affected code units

- lib/Service/SymfonyEmailService.php::sendOrganizationRegistrationEmail
- lib/Service/SymfonyEmailService.php::sendOrganizationActivationEmail
- lib/Service/SymfonyEmailService.php::sendUserCreationEmail
- lib/Service/SymfonyEmailService.php::sendUserUpdateEmail
- lib/Service/SymfonyEmailService.php::sendUserPasswordEmail
- lib/Service/SymfonyEmailService.php::sendTestEmail
- lib/Service/SymfonyEmailService.php::getSenderEmail
- lib/Service/SymfonyEmailService.php::getSenderName
- lib/Service/SymfonyEmailService.php::getEmailSettings
- lib/Service/SymfonyEmailService.php::getAvailableTransports
- lib/Service/SymfonyEmailService.php::setSenderEmail
- lib/Service/SymfonyEmailService.php::setSenderName
- lib/Service/SymfonyEmailService.php::setTransportConfiguration
- lib/Service/SymfonyEmailService.php::setEnabled
- lib/Service/SymfonyEmailService.php::setTestReceiverOverride
- lib/Service/SymfonyEmailService.php::setOrganizationRegistrationEnabled
- lib/Service/SymfonyEmailService.php::setOrganizationActivationEnabled
- lib/Service/SymfonyEmailService.php::setUserCreationEnabled
- lib/Service/SymfonyEmailService.php::setUserPasswordEnabled
- lib/Service/SymfonyEmailService.php::isEmailSystemConfigured

## Approach

- Describe observed inputs/outputs/side-effects of each method.
- Group methods implementing the same observable behavior under one REQ.

Source: openspec/coverage-report (csc.py --mode report) generated 2026-05-26. Umbrella: ConductionNL/softwarecatalog#294.
