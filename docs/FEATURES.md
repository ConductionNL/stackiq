---
sidebar_position: 1
---

# Features

Software Catalogus is a structured software portfolio management app for Nextcloud. It enables organizations to register, track, and publish their software landscape in an open, standardized way.

## Core Features

### Software Registration
Register applications with full metadata: name, description, organization, repository links, and licence. Maintain a single source of truth for your entire software portfolio.

### Module Tracking
Break down applications into functional modules. Track each module's purpose, dependencies, and integration points with other systems.

### Connection Mapping
Map connections (koppelingen) between applications and modules. Visualize your software landscape and understand system dependencies at a glance.

### Contract Administration
Track contracts (period, cost, status) with a submit-and-approve workflow for new contracts and renewals, quick filters for active/expiring/in-negotiation contracts, and a background job that keeps contract status current.

### Standards, Compliance Matrix, and ArchiMate
Maintain a register of standards (including GEMMA), record compliance claims with supporting evidence per module, and cross-check modules against standard versions in a compliance matrix. Import and export the underlying architecture model as ArchiMate (AMEF) XML.

### Application Lifecycle & Portfolio Roadmap
Derive each application's lifecycle phase from its in-use dates and surface end-of-support / end-of-life warnings. A per-organisation portfolio roadmap groups applications in use by phase and urgency.

### Reviews
Record ratings and assessments of modules, services, connections, and usage records, with supporting evidence.

### Federated Synchronization
When [OpenCatalogi](https://apps.nextcloud.com/apps/opencatalogi) is installed, synchronize organisation profiles with peer Software Catalogus instances via OpenCatalogi's directory network (add a peer, pull on demand). Degrades gracefully to a single-instance catalogue when OpenCatalogi is not present.

### Automatic User Provisioning
Automatically create Nextcloud user accounts and group memberships for registered organizations' contact persons. Keeps access control in sync with your catalogue data.

### Open Data Publishing & Moderated Self-Registration
Publish selected catalogue entries as open data for transparency and reuse. Anonymous organisations can submit a self-registration request, which lands in an admin moderation queue for approval or rejection before it is published.

### GEMMA Compliance
Aligned with the GEMMA (Gemeentelijk Model Architectuur) reference architecture for Dutch municipalities, via the standards register, compliance matrix, and ArchiMate import/export above.

### OpenRegister Integration
All objects are stored as flexible OpenRegister objects, enabling full audit trails, versioning, and cross-app data sharing.
