# Instructions

- Following Playwright test failed.
- Explain why, be concise, respect Playwright best practices.
- Provide a snippet of code with the fix, if possible.

# Test info

- Name: org-archimate-export.spec.ts >> swc-fix user-triggers-organization-export-with-toggles: selecting an org reveals toggles and org-export fires the request
- Location: tests/e2e/org-archimate-export.spec.ts:183:5

# Error details

```
TimeoutError: locator.waitFor: Timeout 5000ms exceeded.
Call log:
  - waiting for locator('.vs__dropdown-option, [role="option"]').filter({ hasText: 'Generic' }).first() to be visible

```

# Page snapshot

```yaml
- generic [ref=e1]:
  - generic [ref=e2]:
    - link "Skip to main content" [ref=e3] [cursor=pointer]:
      - /url: "#app-content"
    - link "Skip to navigation of app" [ref=e4] [cursor=pointer]:
      - /url: "#app-navigation"
  - banner [ref=e5]:
    - generic [ref=e6]:
      - link "Go to Dashboard" [ref=e7] [cursor=pointer]:
        - /url: /
      - navigation "Applications menu" [ref=e9]:
        - list "Apps" [ref=e10]:
          - listitem [ref=e11]:
            - link "Dashboard" [ref=e12] [cursor=pointer]:
              - /url: /apps/dashboard/
              - img [ref=e13]
              - generic [ref=e14]: Dashboard
          - listitem [ref=e15]:
            - link "LaunchPad" [ref=e16] [cursor=pointer]:
              - /url: /apps/launchpad/
              - img [ref=e17]
              - generic [ref=e18]: LaunchPad
          - listitem [ref=e19]:
            - link "Files" [ref=e20] [cursor=pointer]:
              - /url: /apps/files/
              - img [ref=e21]
              - generic [ref=e22]: Files
          - listitem [ref=e23]:
            - link "Photos" [ref=e24] [cursor=pointer]:
              - /url: /apps/photos/
              - img [ref=e25]
              - generic [ref=e26]: Photos
          - listitem [ref=e27]:
            - link "Activity" [ref=e28] [cursor=pointer]:
              - /url: /apps/activity/
              - img [ref=e29]
              - generic [ref=e30]: Activity
          - listitem [ref=e31]:
            - link "Procest" [ref=e32] [cursor=pointer]:
              - /url: /apps/procest
              - img [ref=e33]
              - generic [ref=e34]: Procest
          - listitem [ref=e35]:
            - link "Pipelinq" [ref=e36] [cursor=pointer]:
              - /url: /apps/pipelinq
              - img [ref=e37]
              - generic [ref=e38]: Pipelinq
          - listitem [ref=e39]:
            - link "PetStore" [ref=e40] [cursor=pointer]:
              - /url: /apps/petstore/
              - img [ref=e41]
              - generic [ref=e42]: PetStore
          - listitem [ref=e43]:
            - link "Register" [ref=e44] [cursor=pointer]:
              - /url: /apps/openregister/
              - img [ref=e45]
              - generic [ref=e46]: Register
          - listitem [ref=e47]:
            - link "Catalogi" [ref=e48] [cursor=pointer]:
              - /url: /apps/opencatalogi
              - img [ref=e49]
              - generic [ref=e50]: Catalogi
          - listitem [ref=e51]:
            - link "Larping" [ref=e52] [cursor=pointer]:
              - /url: /apps/larpingapp/
              - img [ref=e53]
              - generic [ref=e54]: Larping
          - listitem [ref=e55]:
            - link "Doriath" [ref=e56] [cursor=pointer]:
              - /url: /apps/doriath/
              - img [ref=e57]
              - generic [ref=e58]: Doriath
          - listitem [ref=e59]:
            - link "DocuDesk" [ref=e60] [cursor=pointer]:
              - /url: /apps/docudesk/
              - img [ref=e61]
              - generic [ref=e62]: DocuDesk
          - listitem [ref=e63]:
            - link "Decidesk" [ref=e64] [cursor=pointer]:
              - /url: /apps/decidesk/
              - img [ref=e65]
              - generic [ref=e66]: Decidesk
          - listitem [ref=e67]:
            - link "Software Catalogs" [ref=e68] [cursor=pointer]:
              - /url: /apps/softwarecatalog
              - img [ref=e69]
              - generic [ref=e70]: Software Catalogs
          - listitem [ref=e71]:
            - link "Zaak Afhandel App" [ref=e72] [cursor=pointer]:
              - /url: /apps/zaakafhandelapp/
              - img [ref=e73]
              - generic [ref=e74]: Zaak Afhandel App
          - listitem [ref=e75]:
            - link "OpenBuild" [ref=e76] [cursor=pointer]:
              - /url: /apps/openbuild/
              - img [ref=e77]
              - generic [ref=e78]: OpenBuild
    - generic [ref=e79]:
      - button "Unified search" [ref=e82] [cursor=pointer]:
        - img [ref=e85]:
          - img [ref=e86]
      - generic "Notifications" [ref=e89]:
        - button "Notifications" [ref=e90] [cursor=pointer]:
          - img [ref=e94]
      - button "Search contacts" [ref=e98] [cursor=pointer]:
        - img [ref=e101]:
          - img [ref=e102]
      - navigation "Settings menu" [ref=e104]:
        - button "Settings menu" [ref=e105] [cursor=pointer]
        - generic [ref=e109]: Avatar of admin
  - generic [ref=e110]:
    - 'heading "Administration settings: Software Catalog" [level=1] [ref=e111]'
    - generic [ref=e112]:
      - generic: Personal
      - navigation "Personal" [ref=e113]:
        - list [ref=e114]:
          - listitem [ref=e115]:
            - link "Personal info" [ref=e116] [cursor=pointer]:
              - /url: /settings/user
          - listitem [ref=e117]:
            - link "Security" [ref=e118] [cursor=pointer]:
              - /url: /settings/user/security
          - listitem [ref=e119]:
            - link "Notifications" [ref=e120] [cursor=pointer]:
              - /url: /settings/user/notifications
          - listitem [ref=e121]:
            - link "Mobile & desktop" [ref=e122] [cursor=pointer]:
              - /url: /settings/user/sync-clients
          - listitem [ref=e123]:
            - link "Sharing" [ref=e124] [cursor=pointer]:
              - /url: /settings/user/sharing
          - listitem [ref=e125]:
            - link "Appearance and accessibility" [ref=e126] [cursor=pointer]:
              - /url: /settings/user/theming
          - listitem [ref=e127]:
            - link "Availability" [ref=e128] [cursor=pointer]:
              - /url: /settings/user/availability
          - listitem [ref=e129]:
            - link "Flow" [ref=e130] [cursor=pointer]:
              - /url: /settings/user/workflow
          - listitem [ref=e131]:
            - link "Privacy" [ref=e132] [cursor=pointer]:
              - /url: /settings/user/privacy
      - generic: Administration
      - navigation "Administration" [ref=e133]:
        - list [ref=e134]:
          - listitem [ref=e135]:
            - link "Overview" [ref=e136] [cursor=pointer]:
              - /url: /settings/admin/overview
          - listitem [ref=e137]:
            - link "Quick presets" [ref=e138] [cursor=pointer]:
              - /url: /settings/admin/presets
          - listitem [ref=e139]:
            - link "Support" [ref=e140] [cursor=pointer]:
              - /url: /settings/admin/support
          - listitem [ref=e141]:
            - link "Basic settings" [ref=e142] [cursor=pointer]:
              - /url: /settings/admin
          - listitem [ref=e143]:
            - link "Sharing" [ref=e144] [cursor=pointer]:
              - /url: /settings/admin/sharing
          - listitem [ref=e145]:
            - link "Security" [ref=e146] [cursor=pointer]:
              - /url: /settings/admin/security
          - listitem [ref=e147]:
            - link "Theming" [ref=e148] [cursor=pointer]:
              - /url: /settings/admin/theming
          - listitem [ref=e149]:
            - link "Assistant" [ref=e150] [cursor=pointer]:
              - /url: /settings/admin/ai
          - listitem [ref=e151]:
            - link "Groupware" [ref=e152] [cursor=pointer]:
              - /url: /settings/admin/groupware
          - listitem [ref=e153]:
            - link "AppAPI" [ref=e154] [cursor=pointer]:
              - /url: /settings/admin/app_api
          - listitem [ref=e155]:
            - link "Administration privileges" [ref=e156] [cursor=pointer]:
              - /url: /settings/admin/admindelegation
          - listitem [ref=e157]:
            - link "Activity" [ref=e158] [cursor=pointer]:
              - /url: /settings/admin/activity
          - listitem [ref=e159]:
            - link "LarpingApp" [ref=e160] [cursor=pointer]:
              - /url: /settings/admin/larpingapp
          - listitem [ref=e161]:
            - link "Notifications" [ref=e162] [cursor=pointer]:
              - /url: /settings/admin/notifications
          - listitem [ref=e163]:
            - link "Flow" [ref=e164] [cursor=pointer]:
              - /url: /settings/admin/workflow
          - listitem [ref=e165]:
            - link "Decidesk" [ref=e166] [cursor=pointer]:
              - /url: /settings/admin/decidesk
          - listitem [ref=e167]:
            - link "Doriath" [ref=e168] [cursor=pointer]:
              - /url: /settings/admin/doriath
          - listitem [ref=e169]:
            - link "OpenBuild" [ref=e170] [cursor=pointer]:
              - /url: /settings/admin/openbuild
          - listitem [ref=e171]:
            - link "App Template" [ref=e172] [cursor=pointer]:
              - /url: /settings/admin/petstore
          - listitem [ref=e173]:
            - link "Procest" [ref=e174] [cursor=pointer]:
              - /url: /settings/admin/procest
          - listitem [ref=e175]:
            - link "Pipelinq" [ref=e176] [cursor=pointer]:
              - /url: /settings/admin/pipelinq
          - listitem [ref=e177]:
            - link "LaunchPad" [ref=e178] [cursor=pointer]:
              - /url: /settings/admin/launchpad
          - listitem [ref=e179]:
            - link "Usage survey" [ref=e180] [cursor=pointer]:
              - /url: /settings/admin/survey_client
          - listitem [ref=e181]:
            - link "Logging" [ref=e182] [cursor=pointer]:
              - /url: /settings/admin/logging
          - listitem [ref=e183]:
            - link "System" [ref=e184] [cursor=pointer]:
              - /url: /settings/admin/serverinfo
          - listitem [ref=e185]:
            - link "DocuDesk" [ref=e186] [cursor=pointer]:
              - /url: /settings/admin/docudesk
          - listitem [ref=e187]:
            - link "Open Catalogi" [ref=e188] [cursor=pointer]:
              - /url: /settings/admin/opencatalogi
          - listitem [ref=e189]:
            - link "Open Register" [ref=e190] [cursor=pointer]:
              - /url: /settings/admin/openregister
          - listitem [ref=e191]:
            - link "Software Catalog" [ref=e192] [cursor=pointer]:
              - /url: /settings/admin/softwarecatalog
          - listitem [ref=e193]:
            - link "Zaak Afhandelapp" [ref=e194] [cursor=pointer]:
              - /url: /settings/admin/zaakafhandelapp
    - main [ref=e195]:
      - generic [ref=e196]:
        - generic [ref=e197]:
          - heading "Version Information" [level=2] [ref=e198]
          - paragraph [ref=e199]: Information about the current Software Catalogus installation
          - button "Up to date" [disabled] [ref=e201]:
            - generic [ref=e202]:
              - img [ref=e204]:
                - img [ref=e205]
              - generic [ref=e207]: Up to date
          - generic [ref=e210]:
            - heading "Application information" [level=4] [ref=e211]
            - generic [ref=e212]:
              - generic [ref=e213]:
                - generic [ref=e214]: "Application Name:"
                - generic [ref=e215]: Software Catalogus
              - generic [ref=e216]:
                - generic [ref=e217]: "Version:"
                - generic [ref=e218]: Unknown
            - generic [ref=e219]:
              - heading "Support" [level=4] [ref=e220]
              - paragraph [ref=e221]:
                - text: For support, contact us at
                - link "support@conduction.nl" [ref=e222] [cursor=pointer]:
                  - /url: mailto:support@conduction.nl
              - paragraph [ref=e223]:
                - text: For a Service Level Agreement (SLA), contact
                - link "sales@conduction.nl" [ref=e224] [cursor=pointer]:
                  - /url: mailto:sales@conduction.nl
        - generic [ref=e225]:
          - heading "Software Catalog Configuration External documentation for Software Catalog Configuration" [level=2] [ref=e226]:
            - text: Software Catalog Configuration
            - link "External documentation for Software Catalog Configuration" [ref=e227] [cursor=pointer]:
              - /url: https://docs.opencatalogi.nl
              - img [ref=e228]:
                - img [ref=e229]
          - paragraph [ref=e231]: Configure OpenRegister schema mappings for Software Catalog objects
        - generic [ref=e232]:
          - heading "Version Information" [level=2] [ref=e233]
          - paragraph [ref=e234]: Current application and configuration versions
          - generic [ref=e236]:
            - button "Show information about Version Information" [ref=e237] [cursor=pointer]:
              - img [ref=e240]:
                - img [ref=e241]
            - button "Force Update" [ref=e243] [cursor=pointer]:
              - generic [ref=e245]: Force Update
            - button "Reset Auto-Config" [ref=e246] [cursor=pointer]:
              - generic [ref=e248]: Reset Auto-Config
          - generic [ref=e253]:
            - generic [ref=e254]:
              - strong [ref=e255]: "Application:"
              - text: SoftwareCatalog v0.2.4
            - generic [ref=e256]:
              - strong [ref=e257]: "Configured Version:"
              - generic [ref=e258]: Not configured
            - generic [ref=e259]:
              - strong [ref=e260]: "Status:"
              - generic [ref=e261]: ⚠ Update needed
            - generic [ref=e262]:
              - strong [ref=e263]: "Open Register:"
              - generic [ref=e264]: ✓ Installed and active
        - generic [ref=e265]:
          - heading "Object Statistics" [level=2] [ref=e266]
          - paragraph [ref=e267]: Overview of objects stored in configured registers
          - generic [ref=e268]:
            - generic [ref=e270]: "Last updated: 6/6/2026, 12:40:59 PM"
            - table [ref=e272]:
              - rowgroup [ref=e273]:
                - row "Register Object Type Count Status Actions" [ref=e274]:
                  - columnheader "Register" [ref=e275]
                  - columnheader "Object Type" [ref=e276]
                  - columnheader "Count" [ref=e277]
                  - columnheader "Status" [ref=e278]
                  - columnheader "Actions" [ref=e279]
              - rowgroup [ref=e280]:
                - row "Voorzieningen Organisatie 0 Configured" [ref=e281]:
                  - cell "Voorzieningen" [ref=e282]
                  - cell "Organisatie" [ref=e283]
                  - cell "0" [ref=e284]
                  - cell "Configured" [ref=e285]:
                    - generic [ref=e286]: Configured
                  - cell [ref=e287]
                - row "Voorzieningen Contactpersoon 0 Configured" [ref=e288]:
                  - cell "Voorzieningen" [ref=e289]
                  - cell "Contactpersoon" [ref=e290]
                  - cell "0" [ref=e291]
                  - cell "Configured" [ref=e292]:
                    - generic [ref=e293]: Configured
                  - cell [ref=e294]
                - row "Voorzieningen Voorziening 0 Configured" [ref=e295]:
                  - cell "Voorzieningen" [ref=e296]
                  - cell "Voorziening" [ref=e297]
                  - cell "0" [ref=e298]
                  - cell "Configured" [ref=e299]:
                    - generic [ref=e300]: Configured
                  - cell [ref=e301]
                - row "Voorzieningen Voorziening Aanbod 0 Configured" [ref=e302]:
                  - cell "Voorzieningen" [ref=e303]
                  - cell "Voorziening Aanbod" [ref=e304]
                  - cell "0" [ref=e305]
                  - cell "Configured" [ref=e306]:
                    - generic [ref=e307]: Configured
                  - cell [ref=e308]
                - row "Voorzieningen Voorziening Versie 0 Configured" [ref=e309]:
                  - cell "Voorzieningen" [ref=e310]
                  - cell "Voorziening Versie" [ref=e311]
                  - cell "0" [ref=e312]
                  - cell "Configured" [ref=e313]:
                    - generic [ref=e314]: Configured
                  - cell [ref=e315]
                - row "Voorzieningen Kwetsbaarheid 0 Configured" [ref=e316]:
                  - cell "Voorzieningen" [ref=e317]
                  - cell "Kwetsbaarheid" [ref=e318]
                  - cell "0" [ref=e319]
                  - cell "Configured" [ref=e320]:
                    - generic [ref=e321]: Configured
                  - cell [ref=e322]
                - row "Voorzieningen Contract 0 Configured" [ref=e323]:
                  - cell "Voorzieningen" [ref=e324]
                  - cell "Contract" [ref=e325]
                  - cell "0" [ref=e326]
                  - cell "Configured" [ref=e327]:
                    - generic [ref=e328]: Configured
                  - cell [ref=e329]
                - row "Voorzieningen Standaard 0 Configured" [ref=e330]:
                  - cell "Voorzieningen" [ref=e331]
                  - cell "Standaard" [ref=e332]
                  - cell "0" [ref=e333]
                  - cell "Configured" [ref=e334]:
                    - generic [ref=e335]: Configured
                  - cell [ref=e336]
                - row "Voorzieningen Review 0 Configured" [ref=e337]:
                  - cell "Voorzieningen" [ref=e338]
                  - cell "Review" [ref=e339]
                  - cell "0" [ref=e340]
                  - cell "Configured" [ref=e341]:
                    - generic [ref=e342]: Configured
                  - cell [ref=e343]
                - row "Voorzieningen Koppeling 0 Configured" [ref=e344]:
                  - cell "Voorzieningen" [ref=e345]
                  - cell "Koppeling" [ref=e346]
                  - cell "0" [ref=e347]
                  - cell "Configured" [ref=e348]:
                    - generic [ref=e349]: Configured
                  - cell [ref=e350]
                - row "Voorzieningen Beoordeeling 0 Configured" [ref=e351]:
                  - cell "Voorzieningen" [ref=e352]
                  - cell "Beoordeeling" [ref=e353]
                  - cell "0" [ref=e354]
                  - cell "Configured" [ref=e355]:
                    - generic [ref=e356]: Configured
                  - cell [ref=e357]
                - row "Voorzieningen Voorziening Module 0 Configured" [ref=e358]:
                  - cell "Voorzieningen" [ref=e359]
                  - cell "Voorziening Module" [ref=e360]
                  - cell "0" [ref=e361]
                  - cell "Configured" [ref=e362]:
                    - generic [ref=e363]: Configured
                  - cell [ref=e364]
                - row "Voorzieningen Verklaring 0 Configured" [ref=e365]:
                  - cell "Voorzieningen" [ref=e366]
                  - cell "Verklaring" [ref=e367]
                  - cell "0" [ref=e368]
                  - cell "Configured" [ref=e369]:
                    - generic [ref=e370]: Configured
                  - cell [ref=e371]
                - row "Voorzieningen Koppeling Gebruik 0 Configured" [ref=e372]:
                  - cell "Voorzieningen" [ref=e373]
                  - cell "Koppeling Gebruik" [ref=e374]
                  - cell "0" [ref=e375]
                  - cell "Configured" [ref=e376]:
                    - generic [ref=e377]: Configured
                  - cell [ref=e378]
                - row "Voorzieningen Compliancy 0 Configured Sync Standards" [ref=e379]:
                  - cell "Voorzieningen" [ref=e380]
                  - cell "Compliancy" [ref=e381]
                  - cell "0" [ref=e382]
                  - cell "Configured" [ref=e383]:
                    - generic [ref=e384]: Configured
                  - cell "Sync Standards" [ref=e385]:
                    - button "Sync Standards" [ref=e386] [cursor=pointer]:
                      - generic [ref=e387]:
                        - img [ref=e389]:
                          - img [ref=e390]
                        - generic [ref=e392]: Sync Standards
                - row "Voorzieningen Module Gebruik 0 Configured" [ref=e393]:
                  - cell "Voorzieningen" [ref=e394]
                  - cell "Module Gebruik" [ref=e395]
                  - cell "0" [ref=e396]
                  - cell "Configured" [ref=e397]:
                    - generic [ref=e398]: Configured
                  - cell [ref=e399]
                - row "Voorzieningen Module Versie 0 Configured" [ref=e400]:
                  - cell "Voorzieningen" [ref=e401]
                  - cell "Module Versie" [ref=e402]
                  - cell "0" [ref=e403]
                  - cell "Configured" [ref=e404]:
                    - generic [ref=e405]: Configured
                  - cell [ref=e406]
                - row "Voorzieningen Sector 0 Configured" [ref=e407]:
                  - cell "Voorzieningen" [ref=e408]
                  - cell "Sector" [ref=e409]
                  - cell "0" [ref=e410]
                  - cell "Configured" [ref=e411]:
                    - generic [ref=e412]: Configured
                  - cell [ref=e413]
                - row "AMEF Elements 0 Configured" [ref=e414]:
                  - cell "AMEF" [ref=e415]
                  - cell "Elements" [ref=e416]
                  - cell "0" [ref=e417]
                  - cell "Configured" [ref=e418]:
                    - generic [ref=e419]: Configured
                  - cell [ref=e420]
                - row "AMEF Organizations 0 Configured" [ref=e421]:
                  - cell "AMEF" [ref=e422]
                  - cell "Organizations" [ref=e423]
                  - cell "0" [ref=e424]
                  - cell "Configured" [ref=e425]:
                    - generic [ref=e426]: Configured
                  - cell [ref=e427]
                - row "AMEF Relationships 0 Configured" [ref=e428]:
                  - cell "AMEF" [ref=e429]
                  - cell "Relationships" [ref=e430]
                  - cell "0" [ref=e431]
                  - cell "Configured" [ref=e432]:
                    - generic [ref=e433]: Configured
                  - cell [ref=e434]
                - row "AMEF Views 0 Configured" [ref=e435]:
                  - cell "AMEF" [ref=e436]
                  - cell "Views" [ref=e437]
                  - cell "0" [ref=e438]
                  - cell "Configured" [ref=e439]:
                    - generic [ref=e440]: Configured
                  - cell [ref=e441]
                - row "AMEF Models 0 Configured" [ref=e442]:
                  - cell "AMEF" [ref=e443]
                  - cell "Models" [ref=e444]
                  - cell "0" [ref=e445]
                  - cell "Configured" [ref=e446]:
                    - generic [ref=e447]: Configured
                  - cell [ref=e448]
                - row "AMEF Properties 0 Configured" [ref=e449]:
                  - cell "AMEF" [ref=e450]
                  - cell "Properties" [ref=e451]
                  - cell "0" [ref=e452]
                  - cell "Configured" [ref=e453]:
                    - generic [ref=e454]: Configured
                  - cell [ref=e455]
        - generic [ref=e456]:
          - heading "General Settings" [level=2] [ref=e457]
          - paragraph [ref=e458]: Configure basic application settings
          - generic [ref=e460]:
            - button "Save General Settings" [disabled] [ref=e461]:
              - generic [ref=e462]:
                - img [ref=e464]:
                  - img [ref=e465]
                - generic [ref=e467]: Save General Settings
            - button "Refresh" [ref=e468] [cursor=pointer]:
              - generic [ref=e469]:
                - img [ref=e471]:
                  - img [ref=e472]
                - generic [ref=e474]: Refresh
          - generic [ref=e478]:
            - heading "Software Catalog Location" [level=3] [ref=e479]
            - paragraph [ref=e480]: Set the base URL for your software catalog interface
            - generic [ref=e482]:
              - textbox "Software Catalog Location URL" [ref=e483] [cursor=pointer]:
                - /placeholder: https://catalog.example.com
              - generic: Software Catalog Location URL
              - img [ref=e485]:
                - img [ref=e486]
            - paragraph [ref=e489]: This URL will be used for external links to your software catalog. The system will append "/beheer" to this URL for management interfaces.
        - generic [ref=e490]:
          - heading "OpenRegister Integration" [level=2] [ref=e491]
          - paragraph [ref=e492]: Configure which schemas to use for organizations, contacts, and users
          - generic [ref=e494]:
            - button "Save Configuration" [disabled] [ref=e495]:
              - generic [ref=e496]:
                - img [ref=e498]:
                  - img [ref=e499]
                - generic [ref=e501]: Save Configuration
            - button "Refresh" [ref=e502] [cursor=pointer]:
              - generic [ref=e503]:
                - img [ref=e505]:
                  - img [ref=e506]
                - generic [ref=e508]: Refresh
          - generic [ref=e514]:
            - generic [ref=e515]:
              - button "General Configuration" [ref=e516] [cursor=pointer]
              - button "Voorzieningen" [ref=e517] [cursor=pointer]
              - button "AMEF" [ref=e518] [cursor=pointer]
            - generic [ref=e522]:
              - generic [ref=e524]:
                - generic [ref=e525] [cursor=pointer]: Select Voorzieningen Register
                - generic [ref=e526]:
                  - combobox "Select Voorzieningen Register" [ref=e528] [cursor=pointer]
                  - button [ref=e530] [cursor=pointer]:
                    - img [ref=e532]
              - generic [ref=e535]:
                - generic [ref=e536] [cursor=pointer]: Select AMEF Register
                - generic [ref=e537]:
                  - combobox "Select AMEF Register" [ref=e539] [cursor=pointer]
                  - button [ref=e541] [cursor=pointer]:
                    - img [ref=e543]
        - generic [ref=e545]:
          - heading "User Groups Configuration" [level=2] [ref=e546]
          - paragraph [ref=e547]: Configure user groups for different access levels and permissions
          - generic [ref=e549]:
            - button "Save User Groups" [ref=e550] [cursor=pointer]:
              - generic [ref=e551]:
                - img [ref=e553]:
                  - img [ref=e554]
                - generic [ref=e556]: Save User Groups
            - button "Refresh" [ref=e557] [cursor=pointer]:
              - generic [ref=e558]:
                - img [ref=e560]:
                  - img [ref=e561]
                - generic [ref=e563]: Refresh
            - button "Show information about User Groups Configuration" [ref=e564] [cursor=pointer]:
              - img [ref=e567]:
                - img [ref=e568]
          - generic [ref=e574]:
            - button "Generic Groups" [ref=e575] [cursor=pointer]
            - button "Organization Admin Groups" [ref=e576] [cursor=pointer]
            - button "Super User Groups" [ref=e577] [cursor=pointer]
        - generic [ref=e579]:
          - heading "Organization Synchronization" [level=2] [ref=e580]
          - paragraph [ref=e581]: Synchronize organization data between OpenRegister and external systems
          - generic [ref=e583]:
            - button "Save Configuration" [disabled] [ref=e584]:
              - generic [ref=e585]:
                - img [ref=e587]:
                  - img [ref=e588]
                - generic [ref=e590]: Save Configuration
            - button "Refresh Status" [ref=e591] [cursor=pointer]:
              - generic [ref=e592]:
                - img [ref=e594]:
                  - img [ref=e595]
                - generic [ref=e597]: Refresh Status
            - button "Show information about Organization Synchronization" [ref=e598] [cursor=pointer]:
              - img [ref=e601]:
                - img [ref=e602]
            - button "Incremental Sync Now" [ref=e604] [cursor=pointer]:
              - generic [ref=e605]:
                - img [ref=e607]:
                  - img [ref=e608]
                - generic [ref=e610]: Incremental Sync Now
          - generic [ref=e615]:
            - paragraph [ref=e616]: Monitor the status of organization and contact person synchronization
            - generic [ref=e617]:
              - heading "Incremental Sync Time Window" [level=4] [ref=e618]
              - paragraph [ref=e619]: Configure how far back to look for updated organizations during incremental synchronization
              - generic [ref=e620]:
                - generic [ref=e622]:
                  - generic [ref=e623] [cursor=pointer]: Time Window
                  - generic [ref=e624]:
                    - generic [ref=e625]:
                      - generic "10 minutes" [ref=e627]:
                        - generic [ref=e628]: 10 mi
                        - generic [ref=e629]: nutes
                      - combobox "Time Window" [ref=e630] [cursor=pointer]
                    - generic [ref=e631]:
                      - button "Clear selected" [ref=e632] [cursor=pointer]:
                        - img [ref=e633]:
                          - img [ref=e634]
                      - button [ref=e636] [cursor=pointer]:
                        - img [ref=e638]
                - generic [ref=e640]:
                  - button "Refresh Configuration" [ref=e641] [cursor=pointer]:
                    - generic [ref=e642]:
                      - img [ref=e644]:
                        - img [ref=e645]
                      - generic [ref=e647]: Refresh Configuration
                  - button "Refresh Status" [ref=e648] [cursor=pointer]:
                    - generic [ref=e649]:
                      - img [ref=e651]:
                        - img [ref=e652]
                      - generic [ref=e654]: Refresh Status
              - generic [ref=e655]: Incremental synchronization will process organizations updated within the last 10 minutes.
            - generic [ref=e657]:
              - generic [ref=e658]:
                - generic [ref=e659]:
                  - generic [ref=e660]: "Configuration:"
                  - generic [ref=e661]: ✓ Configured
                - generic [ref=e662]:
                  - generic [ref=e663]:
                    - generic [ref=e664]: "Sync Mode:"
                    - generic [ref=e665]: incremental
                  - generic [ref=e666]:
                    - generic [ref=e667]: "Time Window:"
                    - generic [ref=e668]: 10 minutes
                  - generic [ref=e669]:
                    - generic [ref=e670]: "Total Organizations:"
                    - generic [ref=e671]: "5"
                  - generic [ref=e672]:
                    - generic [ref=e673]: "Organizations to Process:"
                    - generic [ref=e674]: "0"
                  - generic [ref=e675]:
                    - generic [ref=e676]: "Contact Persons to Process:"
                    - generic [ref=e677]: "0"
                  - generic [ref=e678]:
                    - generic [ref=e679]: "Efficiency Improvement:"
                    - generic [ref=e680]: 100%
                  - generic [ref=e681]:
                    - generic [ref=e682]: "Organization Entities:"
                    - generic [ref=e683]: "23"
                  - generic [ref=e684]:
                    - generic [ref=e685]: "Contact Schema:"
                    - generic [ref=e686]: ✓ Configured
                  - generic [ref=e687]:
                    - generic [ref=e688]: "Last Sync:"
                    - generic [ref=e689]: 6/6/2026, 10:33:44 AM
              - generic [ref=e690]: No organizations to process in the current time window
            - generic [ref=e691]:
              - heading "Sync Organisations to Voorzieningen Register" [level=4] [ref=e692]
              - paragraph [ref=e693]: Synchronize OpenRegister organisations to the voorzieningen register as organisatie objects.
              - generic [ref=e694]:
                - generic [ref=e695]:
                  - generic [ref=e697] [cursor=pointer]:
                    - checkbox "Dry Run (preview only)" [checked] [ref=e698]
                    - text: Dry Run (preview only)
                  - generic [ref=e699]:
                    - generic [ref=e700] [cursor=pointer]: "Batch Size:"
                    - spinbutton "Batch Size:" [ref=e701] [cursor=pointer]: "500"
                - button "Preview Sync" [ref=e703] [cursor=pointer]:
                  - generic [ref=e704]:
                    - img [ref=e706]:
                      - img [ref=e707]
                    - generic [ref=e709]: Preview Sync
              - generic [ref=e710]:
                - paragraph [ref=e711]:
                  - strong [ref=e712]: "What this does:"
                  - text: This sync ensures that all organisations from OpenRegister exist as organisatie objects in the voorzieningen register. This is needed for cross-organisation workflows like leverancier-gemeente gebruik suggestions.
                - paragraph [ref=e713]:
                  - strong [ref=e714]: "Performance:"
                  - text: Uses bulk operations for optimal performance with large numbers of organisations (1000+).
                - paragraph [ref=e715]:
                  - strong [ref=e716]: "Safety:"
                  - text: Only creates missing organisations - existing ones are skipped. Use dry run to preview changes.
            - generic [ref=e717]:
              - heading "About Synchronization" [level=4] [ref=e718]
              - paragraph [ref=e719]: "The synchronization process ensures that:"
              - list [ref=e720]:
                - listitem [ref=e721]:
                  - strong [ref=e722]: "Organization entities:"
                  - text: Every organization object has a corresponding organization entity
                - listitem [ref=e723]:
                  - strong [ref=e724]: "User accounts:"
                  - text: Contact persons have Nextcloud user accounts
                - listitem [ref=e725]:
                  - strong [ref=e726]: "Relationships:"
                  - text: Organization entities maintain correct user lists
                - listitem [ref=e727]:
                  - strong [ref=e728]: "Status consistency:"
                  - text: Organization active status reflects the 'beoordeling' field
              - paragraph [ref=e729]:
                - strong [ref=e730]: "Time-based filtering:"
                - text: Organizations remain in the sync queue based on their last update time in OpenRegister, not when they were last processed. An organization will naturally "age out" of the time window once it hasn't been updated for longer than the selected time period.
              - paragraph [ref=e731]:
                - strong [ref=e732]: "Automatic synchronization:"
                - text: This process runs every 5 minutes in the background using incremental sync (10-minute window by default). Use manual sync for immediate updates or troubleshooting.
        - generic [ref=e733]:
          - heading "ArchiMate Import/Export" [level=2] [ref=e734]
          - paragraph [ref=e735]: Import and export ArchiMate models to/from OpenRegister
          - button "Show information about ArchiMate Import/Export" [ref=e738] [cursor=pointer]:
            - img [ref=e741]:
              - img [ref=e742]
          - generic [ref=e747]:
            - generic [ref=e748]:
              - generic [ref=e749]:
                - button "Choose ArchiMate XML file" [ref=e750] [cursor=pointer]
                - generic [ref=e751] [cursor=pointer]:
                  - img [ref=e752]:
                    - img [ref=e753]
                  - generic [ref=e755]: Choose ArchiMate XML file
              - button "Import" [disabled] [ref=e757]:
                - generic [ref=e758]:
                  - img [ref=e760]:
                    - img [ref=e761]
                  - generic [ref=e763]: Import
            - generic [ref=e764]:
              - heading "Export" [level=4] [ref=e765]
              - paragraph [ref=e766]: Export ArchiMate models filtered by organization
              - generic [ref=e767]:
                - generic [ref=e768]:
                  - generic [ref=e769] [cursor=pointer]: "Organization:"
                  - generic [ref=e770]:
                    - generic [ref=e771] [cursor=pointer]: Select Organization
                    - generic [ref=e772]:
                      - combobox "Select Organization" [expanded] [active] [ref=e774] [cursor=pointer]:
                        - listbox "Options" [ref=e775]:
                          - option "Test Ge meente" [ref=e776]:
                            - generic "Test Gemeente" [ref=e777]:
                              - generic [ref=e778]: Test Ge
                              - generic [ref=e779]: meente
                          - option "Test Leve rancier 2" [ref=e780]:
                            - generic "Test Leverancier 2" [ref=e781]:
                              - generic [ref=e782]: Test Leve
                              - generic [ref=e783]: rancier 2
                          - option "Test Lever ancier BV" [ref=e784]:
                            - generic "Test Leverancier BV" [ref=e785]:
                              - generic [ref=e786]: Test Lever
                              - generic [ref=e787]: ancier BV
                          - option "Test Lever ancier BV" [ref=e788]:
                            - generic "Test Leverancier BV" [ref=e789]:
                              - generic [ref=e790]: Test Lever
                              - generic [ref=e791]: ancier BV
                          - option "Test Same nwerking" [ref=e792]:
                            - generic "Test Samenwerking" [ref=e793]:
                              - generic [ref=e794]: Test Same
                              - generic [ref=e795]: nwerking
                      - button [expanded] [ref=e797] [cursor=pointer]:
                        - img [ref=e799]
                - button "Export Base" [ref=e801] [cursor=pointer]:
                  - generic [ref=e802]:
                    - img [ref=e804]:
                      - img [ref=e805]
                    - generic [ref=e807]: Export Base
                - button "Organization Export" [disabled] [ref=e808]:
                  - generic [ref=e809]:
                    - img [ref=e811]:
                      - img [ref=e812]
                    - generic [ref=e814]: Organization Export
        - generic [ref=e815]:
          - heading "Email Configuration" [level=2] [ref=e816]
          - paragraph [ref=e817]: Configure email settings for notifications and user management
          - generic [ref=e819]:
            - button "Save Email Settings" [ref=e820] [cursor=pointer]:
              - generic [ref=e821]:
                - img [ref=e823]:
                  - img [ref=e824]
                - generic [ref=e826]: Save Email Settings
            - button "Show information about Email Configuration" [ref=e827] [cursor=pointer]:
              - img [ref=e830]:
                - img [ref=e831]
          - generic [ref=e836]:
            - generic [ref=e837]:
              - button "Settings" [ref=e838] [cursor=pointer]
              - button "Email Types" [ref=e839] [cursor=pointer]
              - button "Testing" [ref=e840] [cursor=pointer]
              - button "Templates" [ref=e841] [cursor=pointer]
            - generic [ref=e844]:
              - heading "Email Settings" [level=3] [ref=e845]
              - paragraph [ref=e846]: Configure email notifications for organization and user events
              - generic [ref=e847]:
                - generic [ref=e848]:
                  - checkbox "Enable Email Notifications" [ref=e849] [cursor=pointer]
                  - generic [ref=e850] [cursor=pointer]:
                    - img [ref=e852]:
                      - img [ref=e854]
                    - generic [ref=e857]: Enable Email Notifications
                - paragraph [ref=e858]: Enable or disable all email notifications from the system
              - generic [ref=e859]:
                - heading "Sender Information" [level=4] [ref=e860]
                - generic [ref=e862]:
                  - textbox "Sender Email" [disabled] [ref=e863]:
                    - /placeholder: noreply@example.com
                    - text: noreply@softwarecatalogus.nl
                  - generic: Sender Email
                - generic [ref=e865]:
                  - textbox "Sender Name" [disabled] [ref=e866]:
                    - /placeholder: Software Catalog
                    - text: Software Catalogus
                  - generic: Sender Name
              - generic [ref=e867]:
                - heading "Test Configuration" [level=4] [ref=e868]
                - generic [ref=e870]:
                  - textbox "Test Receiver Override" [disabled] [ref=e871]:
                    - /placeholder: test@example.com
                  - generic: Test Receiver Override
                - paragraph [ref=e872]: If set, all emails will be sent to this address instead of the intended recipients (useful for testing)
              - generic [ref=e873]:
                - heading "Email Transport" [level=4] [ref=e874]
                - generic [ref=e875]:
                  - generic [ref=e876] [cursor=pointer]: Transport Type
                  - generic [ref=e877]:
                    - generic [ref=e878]:
                      - generic "smtp" [ref=e880]:
                        - generic [ref=e881]: smtp
                      - combobox "Transport Type" [disabled] [ref=e882]
                    - button [ref=e884]:
                      - img [ref=e886]
              - generic [ref=e888]:
                - heading "SMTP Configuration" [level=4] [ref=e889]
                - generic [ref=e890]:
                  - generic [ref=e892]:
                    - textbox "SMTP Host" [disabled] [ref=e893]:
                      - /placeholder: smtp.gmail.com
                      - text: localhost
                    - generic: SMTP Host
                  - generic [ref=e895]:
                    - textbox "SMTP Port" [disabled] [ref=e896]:
                      - /placeholder: "587"
                      - text: "587"
                    - generic: SMTP Port
                  - generic [ref=e897]:
                    - generic [ref=e898] [cursor=pointer]: Encryption
                    - generic [ref=e899]:
                      - generic [ref=e900]:
                        - generic "tls" [ref=e902]:
                          - generic [ref=e903]: tls
                        - combobox "Encryption" [disabled] [ref=e904]
                      - button [ref=e906]:
                        - img [ref=e908]
                  - generic [ref=e911]:
                    - textbox "SMTP Username" [disabled] [ref=e912]:
                      - /placeholder: your-email@gmail.com
                    - generic: SMTP Username
                  - generic [ref=e914]:
                    - textbox "SMTP Password" [disabled] [ref=e915]:
                      - /placeholder: Your app password
                    - generic: SMTP Password
                    - button "Show password" [disabled] [ref=e916]:
                      - img [ref=e919]:
                        - img [ref=e920]
        - generic [ref=e922]:
          - heading "Background Jobs Configuration" [level=2] [ref=e923]
          - paragraph [ref=e924]: Configure user and organisation context for background jobs to enable proper authorization
          - button "Refresh" [ref=e927] [cursor=pointer]:
            - generic [ref=e928]:
              - img [ref=e930]:
                - img [ref=e931]
              - generic [ref=e933]: Refresh
          - generic [ref=e937]:
            - note [ref=e938]:
              - img [ref=e939]:
                - img [ref=e940]
              - generic [ref=e942]: Background jobs (cronjobs) need a user and organisation context to properly access data with correct permissions. Configure each job below to set which user and organisation it should run as.
            - generic [ref=e944]:
              - generic [ref=e945]:
                - generic [ref=e946]:
                  - heading "Organization Contact Sync" [level=4] [ref=e947]
                  - generic [ref=e948]:
                    - checkbox "Enabled" [checked] [ref=e949] [cursor=pointer]
                    - generic [ref=e950] [cursor=pointer]:
                      - img [ref=e952]:
                        - img [ref=e954]
                      - generic [ref=e957]: Enabled
                - paragraph [ref=e958]: Syncs organizations and contacts between SoftwareCatalog and OpenRegister.
                - generic [ref=e959]:
                  - img [ref=e960]:
                    - img [ref=e961]
                  - text: Runs every 5 minutes
              - generic [ref=e963]:
                - generic [ref=e964]:
                  - generic [ref=e965]:
                    - generic [ref=e966] [cursor=pointer]: Run as User
                    - generic [ref=e967]:
                      - generic [ref=e968] [cursor=pointer]: Select a user
                      - generic [ref=e969]:
                        - combobox "Select a user" [ref=e971] [cursor=pointer]
                        - button [ref=e973] [cursor=pointer]:
                          - img [ref=e975]
                  - generic [ref=e977]:
                    - generic [ref=e978] [cursor=pointer]: Run in Organisation
                    - generic [ref=e979]:
                      - generic [ref=e980] [cursor=pointer]: Select an organisation
                      - generic [ref=e981]:
                        - combobox "Select an organisation" [ref=e983] [cursor=pointer]
                        - button [ref=e985] [cursor=pointer]:
                          - img [ref=e987]
                - generic [ref=e989]:
                  - button "Save Configuration" [disabled] [ref=e990]:
                    - generic [ref=e991]:
                      - img [ref=e993]:
                        - img [ref=e994]
                      - generic [ref=e996]: Save Configuration
                  - button "Run Now" [disabled] [ref=e997]:
                    - generic [ref=e998]:
                      - img [ref=e1000]:
                        - img [ref=e1001]
                      - generic [ref=e1003]: Run Now
                  - generic [ref=e1004]:
                    - img [ref=e1005]:
                      - img [ref=e1006]
                    - generic [ref=e1008]: Not configured - Job may encounter RBAC errors
```

# Test source

```ts
  5   |  *
  6   |  * Coverage status
  7   |  * ---------------
  8   |  * All 49 backend/XML-generation scenarios (Requirements 1–13) are excluded
  9   |  * from Playwright coverage: they are pure server-side contracts verified by
  10  |  * PHPUnit and Newman/Postman tests (postman/ holds the 518-entry collection).
  11  |  *
  12  |  * The 4 frontend scenarios (Requirement 14: "Frontend MUST provide organization
  13  |  * export with data layer toggles") are covered below by driving the REAL DOM
  14  |  * (NcSelect combobox, NcCheckboxRadioSwitch toggles, NcButton clicks) — no Vue
  15  |  * `$data` patching / `__vue__` walking. On an empty dev dataset the OR org
  16  |  * endpoint yields only the built-in "Generic" option (value null); selecting it
  17  |  * still makes `selectedOrganization` truthy, so the checkbox group renders and
  18  |  * we assert on the rendered controls.
  19  |  *
  20  |  * Excluded scenarios (backend – 49 total):
  21  |  * @e2e org-archimate-export::organization-with-mapped-applications-exports-successfully
  22  |  * @e2e org-archimate-export::organization-with-no-mapped-applications
  23  |  * @e2e org-archimate-export::export-preserves-all-base-gemma-data
  24  |  * @e2e org-archimate-export::export-xml-is-well-formed-and-schema-valid
  25  |  * @e2e org-archimate-export::large-organization-export-completes-within-timeout
  26  |  * @e2e org-archimate-export::application-element-has-correct-structure
  27  |  * @e2e org-archimate-export::application-element-has-unique-swc-identifier
  28  |  * @e2e org-archimate-export::application-element-identifier-is-deterministic
  29  |  * @e2e org-archimate-export::application-element-name-handles-special-xml-characters
  30  |  * @e2e org-archimate-export::application-mapped-to-one-referentiecomponent
  31  |  * @e2e org-archimate-export::application-mapped-to-multiple-referentiecomponenten
  32  |  * @e2e org-archimate-export::relationship-identifiers-are-deterministic
  33  |  * @e2e org-archimate-export::relationship-source-and-target-reference-valid-elements
  34  |  * @e2e org-archimate-export::view-with-applications-plotted-on-referentiecomponenten
  35  |  * @e2e org-archimate-export::multiple-applications-stacked-inside-one-referentiecomponent
  36  |  * @e2e org-archimate-export::application-appears-in-multiple-referentiecomponenten-across-views
  37  |  * @e2e org-archimate-export::view-without-any-matching-referentiecomponenten
  38  |  * @e2e org-archimate-export::original-gemma-views-are-preserved-unchanged
  39  |  * @e2e org-archimate-export::view-has-titel-view-swc-property
  40  |  * @e2e org-archimate-export::view-without-titel-view-swc-property
  41  |  * @e2e org-archimate-export::view-name-handles-long-organization-names
  42  |  * @e2e org-archimate-export::organisation-folders-created-with-typed-subfolders
  43  |  * @e2e org-archimate-export::empty-folders-are-omitted
  44  |  * @e2e org-archimate-export::only-deelnames-enabled-produces-only-deelnames-folder
  45  |  * @e2e org-archimate-export::folder-item-references-are-valid
  46  |  * @e2e org-archimate-export::file-name-includes-date-and-organization
  47  |  * @e2e org-archimate-export::model-name-includes-organization
  48  |  * @e2e org-archimate-export::file-name-sanitizes-special-characters-in-organization-name
  49  |  * @e2e org-archimate-export::valid-organization-uuid-provided
  50  |  * @e2e org-archimate-export::valid-organization-uuid-with-query-parameters
  51  |  * @e2e org-archimate-export::non-existent-organization-uuid
  52  |  * @e2e org-archimate-export::unauthenticated-request-is-rejected
  53  |  * @e2e org-archimate-export::non-admin-user-is-rejected
  54  |  * @e2e org-archimate-export::bron-property-definition-does-not-already-exist
  55  |  * @e2e org-archimate-export::bron-property-definition-already-exists
  56  |  * @e2e org-archimate-export::bron-property-references-are-valid
  57  |  * @e2e org-archimate-export::connection-links-application-node-to-referentiecomponent-node
  58  |  * @e2e org-archimate-export::connection-identifiers-are-unique
  59  |  * @e2e org-archimate-export::connection-without-matching-relationship-is-not-created
  60  |  * @e2e org-archimate-export::organisation-has-deelname-gebruik
  61  |  * @e2e org-archimate-export::organisation-has-no-deelname-gebruik
  62  |  * @e2e org-archimate-export::deelnames-parameter-is-not-set
  63  |  * @e2e org-archimate-export::deelname-applications-have-distinct-identifiers
  64  |  * @e2e org-archimate-export::deelname-query-filters-on-deelnemers-field
  65  |  * @e2e org-archimate-export::deelname-query-handles-no-results-gracefully
  66  |  * @e2e org-archimate-export::all-parameters-enabled
  67  |  * @e2e org-archimate-export::no-parameters-provided-default-behavior
  68  |  * @e2e org-archimate-export::only-deelnames-enabled
  69  |  * @e2e org-archimate-export::boolean-parameters-accept-various-truthy-values
  70  |  */
  71  | 
  72  | import { test, expect, type Page } from '@playwright/test'
  73  | 
  74  | // ---------------------------------------------------------------------------
  75  | // Helpers
  76  | // ---------------------------------------------------------------------------
  77  | 
  78  | /**
  79  |  * Navigate to the ArchiMate settings section and wait for the Vue SPA to mount.
  80  |  * Auth is injected from storageState (see playwright.config.ts).
  81  |  */
  82  | async function goToArchiMateSettings(page: Page): Promise<void> {
  83  | 	await page.goto('/settings/admin/softwarecatalog', { waitUntil: 'networkidle' })
  84  | 	await expect(
  85  | 		page.getByRole('heading', { name: 'ArchiMate Import/Export' }),
  86  | 	).toBeVisible({ timeout: 30000 })
  87  | }
  88  | 
  89  | /**
  90  |  * Select an organisation in the real NcSelect combobox by visible option text.
  91  |  * Opens the combobox, waits for the listbox, and clicks the matching option.
  92  |  * Returns true if the option was found and clicked.
  93  |  */
  94  | async function selectOrganization(page: Page, optionLabel: string): Promise<boolean> {
  95  | 	const orgSelect = page.locator('#organization-select')
  96  | 	await expect(orgSelect).toBeVisible()
  97  | 	// NcSelect renders a vue-select combobox; clicking opens the dropdown.
  98  | 	await orgSelect.click()
  99  | 	const option = page.locator('.vs__dropdown-option, [role="option"]').filter({ hasText: optionLabel }).first()
  100 | 	try {
  101 | 		await option.waitFor({ state: 'visible', timeout: 5000 })
  102 | 	} catch {
  103 | 		// Dropdown may not have opened on first click (focus race) — retry once.
  104 | 		await orgSelect.click()
> 105 | 		await option.waitFor({ state: 'visible', timeout: 5000 })
      |                ^ TimeoutError: locator.waitFor: Timeout 5000ms exceeded.
  106 | 	}
  107 | 	await option.click()
  108 | 	return true
  109 | }
  110 | 
  111 | // ---------------------------------------------------------------------------
  112 | // Scenario: SPA mounts on main app route (smoke test for fix #322)
  113 | // ---------------------------------------------------------------------------
  114 | test(
  115 | 	'swc-fix spa-mounts: main app dashboard renders without white-screen',
  116 | 	async ({ page }) => {
  117 | 		await page.goto('/apps/softwarecatalog', { waitUntil: 'networkidle' })
  118 | 		// Two headings named "Dashboard" exist (widget + page title) — both prove
  119 | 		// Vue mounted. Using .first() avoids the strict-mode violation.
  120 | 		await expect(
  121 | 			page.getByRole('heading', { name: 'Dashboard' }).first(),
  122 | 		).toBeVisible({ timeout: 30000 })
  123 | 	},
  124 | )
  125 | 
  126 | // ---------------------------------------------------------------------------
  127 | // Scenario: Default checkbox state
  128 | // @e2e org-archimate-export::default-checkbox-state
  129 | //
  130 | // Drives the real DOM: with no org selected the checkbox group is hidden
  131 | // (v-if), and the "Organization Export" button is disabled. No $data reads.
  132 | // ---------------------------------------------------------------------------
  133 | test(
  134 | 	'swc-fix default-checkbox-state: checkbox group hidden and org-export disabled until an org is chosen',
  135 | 	async ({ page }) => {
  136 | 		await goToArchiMateSettings(page)
  137 | 
  138 | 		// Organization select is present.
  139 | 		const orgSelect = page.locator('#organization-select')
  140 | 		await expect(orgSelect).toBeVisible()
  141 | 
  142 | 		// The checkbox group is hidden (v-if="selectedOrganization") until an org
  143 | 		// is selected — scoped to the export section to avoid sync-table matches.
  144 | 		const exportSection = page.locator('.export-section')
  145 | 		await expect(exportSection.getByText('Modules', { exact: true })).toHaveCount(0)
  146 | 		await expect(exportSection.getByText('Deelnames', { exact: true })).toHaveCount(0)
  147 | 		await expect(exportSection.getByText('Gebruik', { exact: true })).toHaveCount(0)
  148 | 
  149 | 		// "Organization Export" button must be present but disabled.
  150 | 		const orgExportBtn = page.getByRole('button', { name: 'Organization Export' })
  151 | 		await expect(orgExportBtn).toBeVisible()
  152 | 		await expect(orgExportBtn).toBeDisabled()
  153 | 	},
  154 | )
  155 | 
  156 | // ---------------------------------------------------------------------------
  157 | // Scenario: No organization selected
  158 | // @e2e org-archimate-export::no-organization-selected
  159 | // ---------------------------------------------------------------------------
  160 | test(
  161 | 	'swc-fix no-organization-selected: Organization Export button is disabled when no org chosen',
  162 | 	async ({ page }) => {
  163 | 		await goToArchiMateSettings(page)
  164 | 
  165 | 		// Must be disabled when no org is selected.
  166 | 		const orgExportBtn = page.getByRole('button', { name: 'Organization Export' })
  167 | 		await expect(orgExportBtn).toBeVisible()
  168 | 		await expect(orgExportBtn).toBeDisabled()
  169 | 
  170 | 		// The generic "Export Base" button is present (no org required).
  171 | 		const exportBaseBtn = page.getByRole('button', { name: 'Export Base' })
  172 | 		await expect(exportBaseBtn).toBeVisible()
  173 | 	},
  174 | )
  175 | 
  176 | // ---------------------------------------------------------------------------
  177 | // Scenario: User triggers organization export with toggles
  178 | // @e2e org-archimate-export::user-triggers-organization-export-with-toggles
  179 | //
  180 | // Drives the real combobox + real checkbox toggles + real button click; asserts
  181 | // the rendered control state and the outgoing API request shape. No $data patch.
  182 | // ---------------------------------------------------------------------------
  183 | test(
  184 | 	'swc-fix user-triggers-organization-export-with-toggles: selecting an org reveals toggles and org-export fires the request',
  185 | 	async ({ page }) => {
  186 | 		await goToArchiMateSettings(page)
  187 | 
  188 | 		// Select the always-present built-in "Generic" option through the real
  189 | 		// combobox. This makes selectedOrganization truthy → checkbox group shows.
  190 | 		await selectOrganization(page, 'Generic')
  191 | 
  192 | 		// After an org is selected, the checkbox group renders in the export section.
  193 | 		const exportSection = page.locator('.export-section')
  194 | 		await expect(exportSection.getByText('Modules', { exact: true })).toBeVisible({ timeout: 8000 })
  195 | 		await expect(exportSection.getByText('Deelnames', { exact: true })).toBeVisible()
  196 | 		await expect(exportSection.getByText('Gebruik', { exact: true })).toBeVisible()
  197 | 
  198 | 		// Toggle "Deelnames" on via the real checkbox switch and assert it checks.
  199 | 		const deelnamesSwitch = exportSection
  200 | 			.locator('.checkbox-radio-switch, .checkbox-group label')
  201 | 			.filter({ hasText: 'Deelnames' })
  202 | 			.first()
  203 | 		await deelnamesSwitch.click()
  204 | 
  205 | 		// Intercept the outgoing export GET so we can assert URL shape without
```