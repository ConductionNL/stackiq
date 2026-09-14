---
kind: code
---

# Proposal: adopt-connection-registry

## Why

Stackiq talks to three outside systems, and an admin can only tell whether they work by reading three settings sections and a log.

- **Email.** Mail goes out through Symfony Mailer. `email_transport_type` picks smtp, sendmail, native, null, sendgrid, mailgun, postmark, ses or mailjet. On `null`, every mail is dropped without a sound.
- **Catalog federation.** OpenCatalogi announces this catalog to directory.opencatalogi.nl and pulls entries from peer catalogs. It needs OpenCatalogi installed and `federation_enabled` on, and a peer can fail for hours before anyone notices.
- **End-of-life feed.** Integriq ingests endoflife.date, and stackiq matches the cycles to module versions. A missing register or a switched-off sync shows only inside its own section.

Hydra change `connection-registry` (hydra#667, amended in hydra#673 and hydra#674) gives every app one page of its connections, backed by integriq.

## What changes

- New `lib/Settings/connections.json` with three connections: `email`, `federation` and `eol-feed`.
- `email` names `email_transport_type` as its adapter key, and only `null` reads Simulated. An empty value is not simulated: stackiq falls back to SMTP.
- `federation` and `eol-feed` are `reportedOnly`. Only stackiq can see OpenCatalogi, `federation_enabled` (a boolean key) and the sync outcome.
- `eol-feed` offers integriq's `endoflife-date` source as its template.
- The three settings sections get stable ids: `section-email`, `section-federation` and `section-eol-sync`.
- An email settings save, a peer add or remove, and an EOL sync settings save send `ConnectionRefreshRequestedEvent` for that connection, then report what stackiq can see.
- A federation pull and an EOL sync run report their outcome. Both run on a schedule or on the admin's button, never on a page request.
- An Integrations page under the settings gear, over integriq's `app_connection` schema, preset to `app=stackiq`, admin only, and only shown when integriq is installed.
- Add integration opens `/apps/integriq/connections?app=stackiq&link=1`.
- Local `connectionStatus` and `connectionSettingsLabel` formatters with all six statuses, and the strings in English and Dutch.

## Depends on

- hydra `openspec/changes/connection-registry`, design D2, D4, D6, D8, D9 and D12, and hydra#674 (a refresh retires older observations).
- integriq on `development`: the `app_connection` schema, the declaration sync, both events, the Connections overview and the `endoflife-date` source.

Without integriq the menu entry is hidden, a deep link shows the missing-dependency screen, and nothing is sent.

## Out of scope

- The stackiq register schema `connection` (softwarecatalogus). It describes a catalogue item and is unrelated to integriq's `app_connection`.
- The email test buttons. The store posts `testEmail` and `settings`, and the controller reads `email` and `emailSettings`, so neither test reaches a real send today. A report from them would describe unsaved settings.
- The directory announce. Its result is logged, and the row speaks for the pull.

## Rollback

Revert the change. Stackiq writes no rows of its own. Integriq removes the rows without a linked source on its next sync.
