# Instructions

- Following Playwright test failed.
- Explain why, be concise, respect Playwright best practices.
- Provide a snippet of code with the fix, if possible.

# Test info

- Name: manifest-pages.spec.ts >> manifest settings: version information section renders
- Location: tests/e2e/manifest-pages.spec.ts:179:5

# Error details

```
Error: expect(locator).toBeVisible() failed

Locator:  getByText('Version').first()
Expected: visible
Received: hidden
Timeout:  30000ms

Call log:
  - Expect "toBeVisible" with timeout 30000ms
  - waiting for getByText('Version').first()
    61 × locator resolved to <span class="subject" data-v-58882784="">Schema "Application Version" was updated</span>
       - unexpected value "hidden"

```

```yaml
- text: Keyboard navigation help
- button "Skip to app navigation"
- button "Skip to main content"
- banner:
  - link "Go to Dashboard":
    - /url: /
  - navigation "Applications menu":
    - list "Apps":
      - listitem:
        - link "Dashboard":
          - /url: /apps/dashboard/
      - listitem:
        - link "LaunchPad":
          - /url: /apps/launchpad/
      - listitem:
        - link "Files":
          - /url: /apps/files/
      - listitem:
        - link "Photos":
          - /url: /apps/photos/
      - listitem:
        - link "Activity":
          - /url: /apps/activity/
      - listitem:
        - link "Procest":
          - /url: /apps/procest
      - listitem:
        - link "Pipelinq":
          - /url: /apps/pipelinq
      - listitem:
        - link "PetStore":
          - /url: /apps/petstore/
      - listitem:
        - link "Register":
          - /url: /apps/openregister/
      - listitem:
        - link "Catalogi":
          - /url: /apps/opencatalogi
      - listitem:
        - link "Larping":
          - /url: /apps/larpingapp/
      - listitem:
        - link "Doriath":
          - /url: /apps/doriath/
      - listitem:
        - link "DocuDesk":
          - /url: /apps/docudesk/
      - listitem:
        - link "Decidesk":
          - /url: /apps/decidesk/
      - listitem:
        - link "Software Catalogs":
          - /url: /apps/softwarecatalog
      - listitem:
        - link "Zaak Afhandel App":
          - /url: /apps/zaakafhandelapp/
      - listitem:
        - link "OpenBuild":
          - /url: /apps/openbuild/
  - button "Unified search"
  - button "Notifications":
    - img
  - button "Search contacts"
  - navigation "Settings menu":
    - button "Settings menu"
    - text: Avatar of admin
- navigation:
  - list:
    - listitem:
      - link "Dashboard":
        - /url: /apps/softwarecatalog/
    - listitem:
      - link "Organisations":
        - /url: /apps/softwarecatalog/organisaties
    - listitem:
      - link "Contacts":
        - /url: /apps/softwarecatalog/contactpersonen
    - listitem:
      - link "Contracts":
        - /url: /apps/softwarecatalog/contracten
    - listitem:
      - link "Standards":
        - /url: /apps/softwarecatalog/standaarden
    - listitem:
      - link "Reviews":
        - /url: /apps/softwarecatalog/reviews
    - listitem:
      - link "Compliance":
        - /url: /apps/softwarecatalog/komplianties
    - listitem:
      - link "Module versions":
        - /url: /apps/softwarecatalog/moduleversies
  - list:
    - listitem:
      - link "Documentation":
        - /url: "#"
    - listitem:
      - link "Settings":
        - /url: /apps/softwarecatalog/settings
- button "Close navigation" [expanded]
- main:
  - heading "SoftwareCatalog" [level=4]
  - heading "Version Information" [level=2]
  - paragraph: Information about the current Software Catalogus installation
  - button "Up to date" [disabled]
  - heading "Application information" [level=4]
  - text: "Application Name: Software Catalogus Version: Unknown"
  - heading "Support" [level=4]
  - paragraph:
    - text: For support, contact us at
    - link "support@conduction.nl":
      - /url: mailto:support@conduction.nl
  - paragraph:
    - text: For a Service Level Agreement (SLA), contact
    - link "sales@conduction.nl":
      - /url: mailto:sales@conduction.nl
  - heading "Software Catalog Configuration External documentation for Software Catalog Configuration" [level=2]:
    - text: Software Catalog Configuration
    - link "External documentation for Software Catalog Configuration":
      - /url: https://docs.opencatalogi.nl
  - paragraph: Configure OpenRegister schema mappings for Software Catalog objects
  - heading "Version Information" [level=2]
  - paragraph: Current application and configuration versions
  - button "Show information about Version Information"
  - button "Force Update"
  - button "Reset Auto-Config"
  - strong: "Application:"
  - text: SoftwareCatalog v0.2.4
  - strong: "Configured Version:"
  - text: Not configured
  - strong: "Status:"
  - text: ⚠ Update needed
  - strong: "Open Register:"
  - text: ✓ Installed and active
  - heading "Object Statistics" [level=2]
  - paragraph: Overview of objects stored in configured registers
  - text: "Last updated: 6/6/2026, 1:13:05 PM"
  - table:
    - rowgroup:
      - row "Register Object Type Count Status Actions":
        - columnheader "Register"
        - columnheader "Object Type"
        - columnheader "Count"
        - columnheader "Status"
        - columnheader "Actions"
    - rowgroup:
      - row "Voorzieningen Organisatie 0 Configured":
        - cell "Voorzieningen"
        - cell "Organisatie"
        - cell "0"
        - cell "Configured"
        - cell
      - row "Voorzieningen Contactpersoon 0 Configured":
        - cell "Voorzieningen"
        - cell "Contactpersoon"
        - cell "0"
        - cell "Configured"
        - cell
      - row "Voorzieningen Voorziening 0 Configured":
        - cell "Voorzieningen"
        - cell "Voorziening"
        - cell "0"
        - cell "Configured"
        - cell
      - row "Voorzieningen Voorziening Aanbod 0 Configured":
        - cell "Voorzieningen"
        - cell "Voorziening Aanbod"
        - cell "0"
        - cell "Configured"
        - cell
      - row "Voorzieningen Voorziening Versie 0 Configured":
        - cell "Voorzieningen"
        - cell "Voorziening Versie"
        - cell "0"
        - cell "Configured"
        - cell
      - row "Voorzieningen Kwetsbaarheid 0 Configured":
        - cell "Voorzieningen"
        - cell "Kwetsbaarheid"
        - cell "0"
        - cell "Configured"
        - cell
      - row "Voorzieningen Contract 0 Configured":
        - cell "Voorzieningen"
        - cell "Contract"
        - cell "0"
        - cell "Configured"
        - cell
      - row "Voorzieningen Standaard 0 Configured":
        - cell "Voorzieningen"
        - cell "Standaard"
        - cell "0"
        - cell "Configured"
        - cell
      - row "Voorzieningen Review 0 Configured":
        - cell "Voorzieningen"
        - cell "Review"
        - cell "0"
        - cell "Configured"
        - cell
      - row "Voorzieningen Koppeling 0 Configured":
        - cell "Voorzieningen"
        - cell "Koppeling"
        - cell "0"
        - cell "Configured"
        - cell
      - row "Voorzieningen Beoordeeling 0 Configured":
        - cell "Voorzieningen"
        - cell "Beoordeeling"
        - cell "0"
        - cell "Configured"
        - cell
      - row "Voorzieningen Voorziening Module 0 Configured":
        - cell "Voorzieningen"
        - cell "Voorziening Module"
        - cell "0"
        - cell "Configured"
        - cell
      - row "Voorzieningen Verklaring 0 Configured":
        - cell "Voorzieningen"
        - cell "Verklaring"
        - cell "0"
        - cell "Configured"
        - cell
      - row "Voorzieningen Koppeling Gebruik 0 Configured":
        - cell "Voorzieningen"
        - cell "Koppeling Gebruik"
        - cell "0"
        - cell "Configured"
        - cell
      - row "Voorzieningen Compliancy 0 Configured Sync Standards":
        - cell "Voorzieningen"
        - cell "Compliancy"
        - cell "0"
        - cell "Configured"
        - cell "Sync Standards":
          - button "Sync Standards"
      - row "Voorzieningen Module Gebruik 0 Configured":
        - cell "Voorzieningen"
        - cell "Module Gebruik"
        - cell "0"
        - cell "Configured"
        - cell
      - row "Voorzieningen Module Versie 0 Configured":
        - cell "Voorzieningen"
        - cell "Module Versie"
        - cell "0"
        - cell "Configured"
        - cell
      - row "Voorzieningen Sector 0 Configured":
        - cell "Voorzieningen"
        - cell "Sector"
        - cell "0"
        - cell "Configured"
        - cell
      - row "AMEF Elements 0 Configured":
        - cell "AMEF"
        - cell "Elements"
        - cell "0"
        - cell "Configured"
        - cell
      - row "AMEF Organizations 0 Configured":
        - cell "AMEF"
        - cell "Organizations"
        - cell "0"
        - cell "Configured"
        - cell
      - row "AMEF Relationships 0 Configured":
        - cell "AMEF"
        - cell "Relationships"
        - cell "0"
        - cell "Configured"
        - cell
      - row "AMEF Views 0 Configured":
        - cell "AMEF"
        - cell "Views"
        - cell "0"
        - cell "Configured"
        - cell
      - row "AMEF Models 0 Configured":
        - cell "AMEF"
        - cell "Models"
        - cell "0"
        - cell "Configured"
        - cell
      - row "AMEF Properties 0 Configured":
        - cell "AMEF"
        - cell "Properties"
        - cell "0"
        - cell "Configured"
        - cell
  - heading "General Settings" [level=2]
  - paragraph: Configure basic application settings
  - button "Save General Settings" [disabled]
  - button "Refresh"
  - heading "Software Catalog Location" [level=3]
  - paragraph: Set the base URL for your software catalog interface
  - textbox "Software Catalog Location URL":
    - /placeholder: https://catalog.example.com
  - text: Software Catalog Location URL
  - paragraph: This URL will be used for external links to your software catalog. The system will append "/beheer" to this URL for management interfaces.
  - heading "OpenRegister Integration" [level=2]
  - paragraph: Configure which schemas to use for organizations, contacts, and users
  - button "Save Configuration" [disabled]
  - button "Refresh"
  - button "General Configuration"
  - button "Voorzieningen"
  - button "AMEF"
  - text: Select Voorzieningen Register
  - combobox "Select Voorzieningen Register"
  - button
  - text: Select AMEF Register
  - combobox "Select AMEF Register"
  - button
  - heading "User Groups Configuration" [level=2]
  - paragraph: Configure user groups for different access levels and permissions
  - button "Save User Groups"
  - button "Refresh"
  - button "Show information about User Groups Configuration"
  - button "Generic Groups"
  - button "Organization Admin Groups"
  - button "Super User Groups"
  - heading "Organization Synchronization" [level=2]
  - paragraph: Synchronize organization data between OpenRegister and external systems
  - button "Save Configuration" [disabled]
  - button "Refresh Status"
  - button "Show information about Organization Synchronization"
  - button "Incremental Sync Now"
  - paragraph: Monitor the status of organization and contact person synchronization
  - heading "Incremental Sync Time Window" [level=4]
  - paragraph: Configure how far back to look for updated organizations during incremental synchronization
  - text: Time Window 10 mi nutes
  - combobox "Time Window"
  - button "Clear selected"
  - button
  - button "Refresh Configuration"
  - button "Refresh Status"
  - text: "Incremental synchronization will process organizations updated within the last 10 minutes. Configuration: ✓ Configured Sync Mode: incremental Time Window: 10 minutes Total Organizations: 5 Organizations to Process: 0 Contact Persons to Process: 0 Efficiency Improvement: 100% Organization Entities: 23 Contact Schema: ✓ Configured Last Sync: 6/6/2026, 11:06:00 AM No organizations to process in the current time window"
  - heading "Sync Organisations to Voorzieningen Register" [level=4]
  - paragraph: Synchronize OpenRegister organisations to the voorzieningen register as organisatie objects.
  - checkbox "Dry Run (preview only)" [checked]
  - text: "Dry Run (preview only) Batch Size:"
  - spinbutton "Batch Size:": "500"
  - button "Preview Sync"
  - paragraph:
    - strong: "What this does:"
    - text: This sync ensures that all organisations from OpenRegister exist as organisatie objects in the voorzieningen register. This is needed for cross-organisation workflows like leverancier-gemeente gebruik suggestions.
  - paragraph:
    - strong: "Performance:"
    - text: Uses bulk operations for optimal performance with large numbers of organisations (1000+).
  - paragraph:
    - strong: "Safety:"
    - text: Only creates missing organisations - existing ones are skipped. Use dry run to preview changes.
  - heading "About Synchronization" [level=4]
  - paragraph: "The synchronization process ensures that:"
  - list:
    - listitem:
      - strong: "Organization entities:"
      - text: Every organization object has a corresponding organization entity
    - listitem:
      - strong: "User accounts:"
      - text: Contact persons have Nextcloud user accounts
    - listitem:
      - strong: "Relationships:"
      - text: Organization entities maintain correct user lists
    - listitem:
      - strong: "Status consistency:"
      - text: Organization active status reflects the 'beoordeling' field
  - paragraph:
    - strong: "Time-based filtering:"
    - text: Organizations remain in the sync queue based on their last update time in OpenRegister, not when they were last processed. An organization will naturally "age out" of the time window once it hasn't been updated for longer than the selected time period.
  - paragraph:
    - strong: "Automatic synchronization:"
    - text: This process runs every 5 minutes in the background using incremental sync (10-minute window by default). Use manual sync for immediate updates or troubleshooting.
  - heading "ArchiMate Import/Export" [level=2]
  - paragraph: Import and export ArchiMate models to/from OpenRegister
  - button "Show information about ArchiMate Import/Export"
  - button "Choose ArchiMate XML file"
  - text: Choose ArchiMate XML file
  - button "Import" [disabled]
  - heading "Export" [level=4]
  - paragraph: Export ArchiMate models filtered by organization
  - text: "Organization: Select Organization"
  - combobox "Select Organization"
  - button
  - button "Export Base"
  - button "Organization Export" [disabled]
  - heading "Email Configuration" [level=2]
  - paragraph: Configure email settings for notifications and user management
  - button "Save Email Settings"
  - button "Show information about Email Configuration"
  - button "Settings"
  - button "Email Types"
  - button "Testing"
  - button "Templates"
  - heading "Email Settings" [level=3]
  - paragraph: Configure email notifications for organization and user events
  - checkbox "Enable Email Notifications"
  - text: Enable Email Notifications
  - paragraph: Enable or disable all email notifications from the system
  - heading "Sender Information" [level=4]
  - textbox "Sender Email" [disabled]:
    - /placeholder: noreply@example.com
    - text: noreply@softwarecatalogus.nl
  - text: Sender Email
  - textbox "Sender Name" [disabled]:
    - /placeholder: Software Catalog
    - text: Software Catalogus
  - text: Sender Name
  - heading "Test Configuration" [level=4]
  - textbox "Test Receiver Override" [disabled]:
    - /placeholder: test@example.com
  - text: Test Receiver Override
  - paragraph: If set, all emails will be sent to this address instead of the intended recipients (useful for testing)
  - heading "Email Transport" [level=4]
  - text: Transport Type smtp
  - combobox "Transport Type" [disabled]
  - button
  - heading "SMTP Configuration" [level=4]
  - textbox "SMTP Host" [disabled]:
    - /placeholder: smtp.gmail.com
    - text: localhost
  - text: SMTP Host
  - textbox "SMTP Port" [disabled]:
    - /placeholder: "587"
    - text: "587"
  - text: SMTP Port Encryption tls
  - combobox "Encryption" [disabled]
  - button
  - textbox "SMTP Username" [disabled]:
    - /placeholder: your-email@gmail.com
  - text: SMTP Username
  - textbox "SMTP Password" [disabled]:
    - /placeholder: Your app password
  - text: SMTP Password
  - button "Show password" [disabled]
  - heading "Background Jobs Configuration" [level=2]
  - paragraph: Configure user and organisation context for background jobs to enable proper authorization
  - button "Refresh"
  - note: Background jobs (cronjobs) need a user and organisation context to properly access data with correct permissions. Configure each job below to set which user and organisation it should run as.
  - heading "Organization Contact Sync" [level=4]
  - checkbox "Enabled" [checked]
  - text: Enabled
  - paragraph: Syncs organizations and contacts between SoftwareCatalog and OpenRegister.
  - text: Runs every 5 minutes Run as User Select a user
  - combobox "Select a user"
  - button
  - text: Run in Organisation Select an organisation
  - combobox "Select an organisation"
  - button
  - button "Save Configuration" [disabled]
  - button "Run Now" [disabled]
  - text: Not configured - Job may encounter RBAC errors
  - button "Save" [disabled]
- button "Open AI chat"
```

# Test source

```ts
  82  | }
  83  | 
  84  | // ---------------------------------------------------------------------------
  85  | // Dashboard (type: dashboard) — widget grid
  86  | // ---------------------------------------------------------------------------
  87  | // @e2e fe-shell-navigation::open-the-dashboard
  88  | // @e2e fe-organizations::show-concept-organisations
  89  | test('manifest dashboard: dashboard page renders the widget grid', async ({ page }) => {
  90  | 	await gotoAppRoute(page, '/')
  91  | 	await expectPageRendered(page, 'Dashboard')
  92  | })
  93  | 
  94  | // ---------------------------------------------------------------------------
  95  | // Index pages (type: index) — object list surfaces
  96  | // ---------------------------------------------------------------------------
  97  | // The organisaties index renders the organisation cards (OrganisatieCard);
  98  | // asserting the page renders covers fe-organizations "Display an organisation
  99  | // card" — the card list is the page body.
  100 | // @e2e fe-organizations::display-an-organisation-card
  101 | test('manifest index organisaties: list page renders the organisation cards', async ({ page }) => {
  102 | 	await gotoAppRoute(page, '/organisaties')
  103 | 	await expectPageRendered(page, 'Organisations')
  104 | })
  105 | 
  106 | const INDEX_PAGES: Array<{ route: string; title: string; name: string }> = [
  107 | 	{ route: '/contactpersonen', title: 'Contacts', name: 'contactpersonen' },
  108 | 	{ route: '/contracten', title: 'Contracts', name: 'contracten' },
  109 | 	{ route: '/standaarden', title: 'Standards', name: 'standaarden' },
  110 | 	{ route: '/reviews', title: 'Reviews', name: 'reviews' },
  111 | 	{ route: '/komplianties', title: 'Compliance', name: 'komplianties' },
  112 | 	{ route: '/moduleversies', title: 'Module versions', name: 'moduleversies' },
  113 | ]
  114 | 
  115 | for (const p of INDEX_PAGES) {
  116 | 	test(`manifest index ${p.name}: list page renders`, async ({ page }) => {
  117 | 		await gotoAppRoute(page, p.route)
  118 | 		await expectPageRendered(page, p.title)
  119 | 	})
  120 | }
  121 | 
  122 | // ---------------------------------------------------------------------------
  123 | // Roadmap page (type: roadmap)
  124 | // ---------------------------------------------------------------------------
  125 | test('manifest roadmap features-roadmap: roadmap page renders', async ({ page }) => {
  126 | 	await gotoAppRoute(page, '/features-roadmap')
  127 | 	await expectPageRendered(page, 'Features')
  128 | })
  129 | 
  130 | // ---------------------------------------------------------------------------
  131 | // Detail pages (type: detail) — deep-link with a synthetic id.
  132 | // The detail renderer mounts even when the object id resolves to nothing
  133 | // (empty data / 404 from the OR slug endpoint): we assert the shell mounted,
  134 | // not that a specific object loaded, so the test stays green against an empty
  135 | // dev dataset. This proves the detail route is wired and the SPA renders it.
  136 | // ---------------------------------------------------------------------------
  137 | const DETAIL_PAGES: Array<{ route: string; name: string }> = [
  138 | 	{ route: '/contactpersonen/smoke-id', name: 'contactpersoon-detail' },
  139 | 	{ route: '/contracten/smoke-id', name: 'contract-detail' },
  140 | 	{ route: '/standaarden/smoke-id', name: 'standaard-detail' },
  141 | 	{ route: '/reviews/smoke-id', name: 'review-detail' },
  142 | 	{ route: '/komplianties/smoke-id', name: 'kompliantie-detail' },
  143 | 	{ route: '/moduleversies/smoke-id', name: 'moduleversie-detail' },
  144 | ]
  145 | 
  146 | for (const p of DETAIL_PAGES) {
  147 | 	test(`manifest detail ${p.name}: detail route mounts the SPA shell`, async ({ page }) => {
  148 | 		await gotoAppRoute(page, p.route)
  149 | 		// Shell mounted; the app-content container is rendered regardless of
  150 | 		// whether the synthetic id resolves to an object.
  151 | 		await expect(page.locator(APP_MAIN).first()).toBeVisible()
  152 | 	})
  153 | }
  154 | 
  155 | // ---------------------------------------------------------------------------
  156 | // Settings page (type: settings) — in-app settings surface
  157 | // ---------------------------------------------------------------------------
  158 | // The settings shell (SoftwareCatalogSettings.vue) renders its section
  159 | // navigation and the configuration status — fe-settings-ui "Open settings".
  160 | // @e2e fe-settings-ui::open-settings
  161 | test('manifest settings: in-app settings page renders', async ({ page }) => {
  162 | 	await gotoAppRoute(page, '/settings')
  163 | 	await expect(page.locator(APP_MAIN).first()).toBeVisible()
  164 | 	await expect(page.getByText('SoftwareCatalog', { exact: false }).first()).toBeVisible({ timeout: 30000 })
  165 | })
  166 | 
  167 | // The settings shell renders the Statistics overview section (StatisticsOverview.vue),
  168 | // which loads and displays aggregate object counts — fe-settings-ui "View statistics".
  169 | // @e2e fe-settings-ui::view-statistics
  170 | test('manifest settings: statistics section renders', async ({ page }) => {
  171 | 	await gotoAppRoute(page, '/settings')
  172 | 	await expect(page.locator(APP_MAIN).first()).toBeVisible()
  173 | 	await expect(page.getByText('Statistics', { exact: false }).first()).toBeVisible({ timeout: 30000 })
  174 | })
  175 | 
  176 | // The settings shell renders the Version information section (VersionInformation.vue),
  177 | // which loads and displays the app version — fe-settings-ui "View version information".
  178 | // @e2e fe-settings-ui::view-version-information
  179 | test('manifest settings: version information section renders', async ({ page }) => {
  180 | 	await gotoAppRoute(page, '/settings')
  181 | 	await expect(page.locator(APP_MAIN).first()).toBeVisible()
> 182 | 	await expect(page.getByText('Version', { exact: false }).first()).toBeVisible({ timeout: 30000 })
      |                                                                    ^ Error: expect(locator).toBeVisible() failed
  183 | })
  184 | 
```