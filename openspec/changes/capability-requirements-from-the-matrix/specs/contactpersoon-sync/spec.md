## ADDED Requirements

### Requirement: Converting a contact person takes the email address from its linked Nextcloud contact

When a contact person is turned into a user account, manually or automatically, stackiq SHALL read the email address from the Nextcloud contact its `contactsUid` names. The account SHALL get the role group that matches the organisation's type.

#### Scenario: A contact made with the current form becomes a user

- **GIVEN** a contact person of a supplier whose `contactsUid` names a Nextcloud contact with email anna@leverancier.nl
- **WHEN** a beheerder chooses Convert to user
- **THEN** a Nextcloud account exists for anna@leverancier.nl
- **AND** the account is in the role group for suppliers

#### Scenario: A contact without an email address is reported

- **GIVEN** a contact person whose linked contact has no email address
- **WHEN** a beheerder chooses Convert to user
- **THEN** stackiq answers with a message that the contact has no email address
- **AND** no account is created
