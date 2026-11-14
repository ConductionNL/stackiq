## ADDED Requirements

### Requirement: Using the catalogue asks for no payment

Stackiq SHALL be distributed under the EUPL-1.2 licence. Reading the published catalogue and signing an organisation up SHALL ask for no payment, licence key or subscription.

#### Scenario: A municipality uses the catalogue without paying

- **GIVEN** an instance with stackiq installed from the app store
- **WHEN** a municipality signs up and its users browse the catalogue
- **THEN** no step asks for payment, a licence key or a subscription

#### Scenario: The licence is EUPL-1.2

- **GIVEN** the stackiq repository
- **WHEN** a reader opens `LICENSE` and `appinfo/info.xml`
- **THEN** both name EUPL-1.2
