# Software Catalogus — Overheidsfunctionaliteiten

> Functiepagina voor Nederlandse overheidsorganisaties.
> Gebruik deze checklist om te toetsen aan uw Programma van Eisen.

**Product:** Software Catalogus
**Categorie:** Software-portfoliobeheer & GEMMA-compliance
**Licentie:** AGPL (vrije open source)
**Leverancier:** Conduction B.V.
**Platform:** Nextcloud + Open Register (self-hosted / on-premise / cloud)

## Legenda

| Status | Betekenis |
|--------|-----------|
| Beschikbaar | Functionaliteit is beschikbaar in de huidige versie |
| Gepland | Functionaliteit staat op de roadmap |
| Via platform | Functionaliteit wordt geleverd door Nextcloud / OpenRegister |
| Op aanvraag | Beschikbaar als maatwerk |
| N.v.t. | Niet van toepassing voor dit product |

---

## 1. Functionele eisen

### Software-registratie

| # | Eis | Status | Toelichting |
|---|-----|--------|-------------|
| F-01 | Applicaties registreren met volledige metadata | Beschikbaar | Naam, beschrijving, organisatie, repo, licentie |
| F-02 | Module-tracking (functionele modules per applicatie) | Beschikbaar | Doel, afhankelijkheden, integratiepunten |
| F-03 | Koppelingsmapping (connections tussen applicaties) | Beschikbaar | Systeemafhankelijkheden visualiseren |
| F-04 | GEMMA-categorisering | Beschikbaar | Gemeentelijk Model Architectuur classificatie |
| F-05 | Repository-links en licentie-informatie | Beschikbaar | Broncode- en licentietracking |
| F-05a | Standaarden-compliance assessment | Beschikbaar | Compliancematrix (modules × standaardversies) met drie celstatussen: geverifieerd (met bewijs), geclaimd (zonder bewijs), geen. De catalogus registreert claims en bewijs; het is geen certificeringsautoriteit. Bewijs via Nextcloud Files (`bewijsReferentie`) of legacy base64. |

### Synchronisatie & Federatie

| # | Eis | Status | Toelichting |
|---|-----|--------|-------------|
| F-06 | Gefedereerde synchronisatie | Beschikbaar | Catalogusdata delen tussen organisaties |
| F-07 | Import/merge van externe bronnen | Beschikbaar | Automatisch externe listings importeren |
| F-08 | Open data publicatie via API | Beschikbaar | Gestandaardiseerde API voor publieke consumptie |

### Gebruikersbeheer

| # | Eis | Status | Toelichting |
|---|-----|--------|-------------|
| F-09 | Automatische gebruikersprovisioning | Beschikbaar | Nextcloud-accounts aanmaken voor geregistreerde organisaties |
| F-10 | Organisatie-gebaseerde toegang | Beschikbaar | Rechten per organisatie |

---

## 2. Technische eisen

| # | Eis | Status | Toelichting |
|---|-----|--------|-------------|
| T-01 | On-premise / self-hosted | Beschikbaar | Nextcloud-app |
| T-02 | Open source | Beschikbaar | AGPL, GitHub |
| T-03 | RESTful API | Via platform | OpenRegister REST API |
| T-04 | Database-onafhankelijkheid | Via platform | PostgreSQL, MySQL, SQLite |
| T-05 | Containerisatie (Docker) | Beschikbaar | Docker Compose |
| T-06 | OpenRegister-integratie | Beschikbaar | Alle data als OpenRegister objecten |

---

## 3. Beveiligingseisen

| # | Eis | Status | Toelichting |
|---|-----|--------|-------------|
| B-01 | RBAC | Via platform | OpenRegister RBAC |
| B-02 | Audit trail | Via platform | OpenRegister mutatie-historie |
| B-03 | BIO-compliance | Via platform | Nextcloud BIO |
| B-04 | 2FA | Via platform | Nextcloud 2FA |
| B-05 | SSO / SAML / LDAP | Via platform | Nextcloud SSO |

---

## 4. Privacyeisen (AVG/GDPR)

| # | Eis | Status | Toelichting |
|---|-----|--------|-------------|
| P-01 | Geen persoonsgegevens in catalogus | Beschikbaar | Alleen software-metadata |
| P-02 | Data minimalisatie | Beschikbaar | Schema-gebaseerd |
| P-03 | Gebruikersprovisioning AVG-conform | Beschikbaar | Alleen noodzakelijke account-gegevens |

---

## 5. Toegankelijkheidseisen

| # | Eis | Status | Toelichting |
|---|-----|--------|-------------|
| A-01 | WCAG 2.1 AA | Beschikbaar | Nextcloud-componenten |
| A-02 | EN 301 549 | Beschikbaar | Via WCAG AA |
| A-03 | Toetsenbordnavigatie | Beschikbaar | Volledig navigeerbaar |
| A-04 | NL Design System | Beschikbaar | Via NL Design app |
| A-05 | Meertalig (NL/EN) | Beschikbaar | Volledige vertaling |

---

## 6. Integratiestandaarden

| # | Eis | Status | Toelichting |
|---|-----|--------|-------------|
| I-01 | GEMMA (Gemeentelijk Model Architectuur) | Beschikbaar | Standaard-categorisering voor gemeenten |
| I-02 | Common Ground architectuur | Beschikbaar | Past in Common Ground ecosysteem |
| I-03 | OpenRegister data-opslag | Beschikbaar | Volledige audit trail en versiebeheer |
| I-04 | Gefedereerde synchronisatie | Beschikbaar | Cross-organisatie uitwisseling |
| I-05 | Open data API | Beschikbaar | Publieke API-endpoints |

---

## 7. Archivering

| # | Eis | Status | Toelichting |
|---|-----|--------|-------------|
| AR-01 | Versiebeheer van software-registraties | Via platform | OpenRegister mutatie-historie |
| AR-02 | Historische landschapdocumentatie | Beschikbaar | Wijzigingen in software-portfolio bijhouden |

---

## 8. Beheer en onderhoud

| # | Eis | Status | Toelichting |
|---|-----|--------|-------------|
| BO-01 | Nextcloud App Store | Beschikbaar | Installatie via App Store |
| BO-02 | Automatische updates | Beschikbaar | Via Nextcloud app-updater |
| BO-03 | Beheerderspaneel | Beschikbaar | Nextcloud admin settings |
| BO-04 | Documentatie | Beschikbaar | Docusaurus docs |
| BO-05 | Open source community | Beschikbaar | GitHub Issues |
| BO-06 | Professionele ondersteuning (SLA) | Op aanvraag | Via Conduction B.V. |

---

## 9. Onderscheidende kenmerken

| Kenmerk | Toelichting |
|---------|-------------|
| **GEMMA-native** | Gebouwd rondom het Gemeentelijk Model Architectuur |
| **Koppelingsmapping** | Visualiseer systeemafhankelijkheden in uw software-landschap |
| **Gefedereerd** | Software-catalogi delen tussen organisaties |
| **Auto-provisioning** | Automatisch Nextcloud-accounts voor geregistreerde organisaties |
| **Open data** | Publiceer uw software-catalogus als open data |
| **Data-hergebruik** | OpenRegister-gebaseerd — data herbruikbaar door andere apps |
