# Instructions

- Following Playwright test failed.
- Explain why, be concise, respect Playwright best practices.
- Provide a snippet of code with the fix, if possible.

# Test info

- Name: manifest-pages.spec.ts >> manifest settings: version information section renders
- Location: tests/e2e/manifest-pages.spec.ts:161:5

# Error details

```
Test timeout of 60000ms exceeded.
```

```
Error: page.waitForFunction: Test timeout of 60000ms exceeded.
```

# Page snapshot

```yaml
- generic [active] [ref=e1]:
  - generic [ref=e4]:
    - generic [ref=e5]: Keyboard navigation help
    - generic [ref=e6]:
      - button "Skip to app navigation" [ref=e7] [cursor=pointer]:
        - generic [ref=e9]: Skip to app navigation
      - button "Skip to main content" [ref=e10] [cursor=pointer]:
        - generic [ref=e12]: Skip to main content
    - img [ref=e13]:
      - img [ref=e15]
  - banner [ref=e36]:
    - generic [ref=e37]:
      - link "Go to Dashboard" [ref=e38] [cursor=pointer]:
        - /url: /
      - navigation "Applications menu" [ref=e40]:
        - list "Apps" [ref=e41]:
          - listitem [ref=e42]:
            - link "Dashboard" [ref=e43] [cursor=pointer]:
              - /url: /apps/dashboard/
              - img [ref=e44]
              - generic [ref=e45]: Dashboard
          - listitem [ref=e46]:
            - link "LaunchPad" [ref=e47] [cursor=pointer]:
              - /url: /apps/launchpad/
              - img [ref=e48]
              - generic [ref=e49]: LaunchPad
          - listitem [ref=e50]:
            - link "Files" [ref=e51] [cursor=pointer]:
              - /url: /apps/files/
              - img [ref=e52]
              - generic [ref=e53]: Files
          - listitem [ref=e54]:
            - link "Photos" [ref=e55] [cursor=pointer]:
              - /url: /apps/photos/
              - img [ref=e56]
              - generic [ref=e57]: Photos
          - listitem [ref=e58]:
            - link "Activity" [ref=e59] [cursor=pointer]:
              - /url: /apps/activity/
              - img [ref=e60]
              - generic [ref=e61]: Activity
          - listitem [ref=e62]:
            - link "Procest" [ref=e63] [cursor=pointer]:
              - /url: /apps/procest
              - img [ref=e64]
              - generic [ref=e65]: Procest
          - listitem [ref=e66]:
            - link "Pipelinq" [ref=e67] [cursor=pointer]:
              - /url: /apps/pipelinq
              - img [ref=e68]
              - generic [ref=e69]: Pipelinq
          - listitem [ref=e70]:
            - link "PetStore" [ref=e71] [cursor=pointer]:
              - /url: /apps/petstore/
              - img [ref=e72]
              - generic [ref=e73]: PetStore
          - listitem [ref=e74]:
            - link "Register" [ref=e75] [cursor=pointer]:
              - /url: /apps/openregister/
              - img [ref=e76]
              - generic [ref=e77]: Register
          - listitem [ref=e78]:
            - link "Catalogi" [ref=e79] [cursor=pointer]:
              - /url: /apps/opencatalogi
              - img [ref=e80]
              - generic [ref=e81]: Catalogi
          - listitem [ref=e82]:
            - link "Larping" [ref=e83] [cursor=pointer]:
              - /url: /apps/larpingapp/
              - img [ref=e84]
              - generic [ref=e85]: Larping
          - listitem [ref=e86]:
            - link "Doriath" [ref=e87] [cursor=pointer]:
              - /url: /apps/doriath/
              - img [ref=e88]
              - generic [ref=e89]: Doriath
          - listitem [ref=e90]:
            - link "DocuDesk" [ref=e91] [cursor=pointer]:
              - /url: /apps/docudesk/
              - img [ref=e92]
              - generic [ref=e93]: DocuDesk
          - listitem [ref=e94]:
            - link "Decidesk" [ref=e95] [cursor=pointer]:
              - /url: /apps/decidesk/
              - img [ref=e96]
              - generic [ref=e97]: Decidesk
          - listitem [ref=e98]:
            - link "Software Catalogs" [ref=e99] [cursor=pointer]:
              - /url: /apps/softwarecatalog
              - img [ref=e100]
              - generic [ref=e101]: Software Catalogs
          - listitem [ref=e102]:
            - link "Zaak Afhandel App" [ref=e103] [cursor=pointer]:
              - /url: /apps/zaakafhandelapp/
              - img [ref=e104]
              - generic [ref=e105]: Zaak Afhandel App
          - listitem [ref=e106]:
            - link "OpenBuild" [ref=e107] [cursor=pointer]:
              - /url: /apps/openbuild/
              - img [ref=e108]
              - generic [ref=e109]: OpenBuild
    - generic [ref=e110]:
      - button "Unified search" [ref=e113] [cursor=pointer]:
        - img [ref=e116]:
          - img [ref=e117]
      - generic "Notifications" [ref=e120]:
        - button "Notifications" [ref=e121] [cursor=pointer]:
          - img [ref=e125]
      - button "Search contacts" [ref=e129] [cursor=pointer]:
        - img [ref=e132]:
          - img [ref=e133]
      - navigation "Settings menu" [ref=e135]:
        - button "Settings menu" [ref=e136] [cursor=pointer]
        - generic [ref=e140]: Avatar of admin
  - generic [ref=e141]:
    - generic [ref=e142]:
      - navigation [ref=e143]:
        - list [ref=e144]:
          - listitem [ref=e145]:
            - link "Dashboard" [ref=e147] [cursor=pointer]:
              - /url: /apps/softwarecatalog/
              - generic [ref=e149]: Dashboard
          - listitem [ref=e150]:
            - link "Organisations" [ref=e152] [cursor=pointer]:
              - /url: /apps/softwarecatalog/organisaties
              - generic [ref=e154]: Organisations
          - listitem [ref=e155]:
            - link "Contacts" [ref=e157] [cursor=pointer]:
              - /url: /apps/softwarecatalog/contactpersonen
              - generic [ref=e159]: Contacts
          - listitem [ref=e160]:
            - link "Contracts" [ref=e162] [cursor=pointer]:
              - /url: /apps/softwarecatalog/contracten
              - generic [ref=e164]: Contracts
          - listitem [ref=e165]:
            - link "Standards" [ref=e167] [cursor=pointer]:
              - /url: /apps/softwarecatalog/standaarden
              - generic [ref=e169]: Standards
          - listitem [ref=e170]:
            - link "Reviews" [ref=e172] [cursor=pointer]:
              - /url: /apps/softwarecatalog/reviews
              - generic [ref=e174]: Reviews
          - listitem [ref=e175]:
            - link "Compliance" [ref=e177] [cursor=pointer]:
              - /url: /apps/softwarecatalog/komplianties
              - generic [ref=e179]: Compliance
          - listitem [ref=e180]:
            - link "Module versions" [ref=e182] [cursor=pointer]:
              - /url: /apps/softwarecatalog/moduleversies
              - generic [ref=e184]: Module versions
        - list [ref=e185]:
          - listitem [ref=e186]:
            - link "Documentation" [ref=e188] [cursor=pointer]:
              - /url: "#"
              - generic [ref=e190]: Documentation
          - listitem [ref=e191]:
            - link "Settings" [ref=e193] [cursor=pointer]:
              - /url: /apps/softwarecatalog/settings
              - generic [ref=e195]: Settings
      - button "Close navigation" [expanded] [ref=e197] [cursor=pointer]:
        - img [ref=e200]:
          - img [ref=e201]
    - main [ref=e203]:
      - generic [ref=e205]:
        - generic [ref=e206]:
          - heading "SoftwareCatalog" [level=4] [ref=e207]:
            - generic [ref=e208]: SoftwareCatalog
          - generic [ref=e210]:
            - generic [ref=e211]:
              - heading "Version Information" [level=2] [ref=e212]
              - paragraph [ref=e213]: Information about the current Software Catalogus installation
              - button "Up to date" [disabled] [ref=e215]:
                - generic [ref=e216]:
                  - img [ref=e218]:
                    - img [ref=e219]
                  - generic [ref=e221]: Up to date
              - generic [ref=e224]:
                - heading "Application information" [level=4] [ref=e225]
                - generic [ref=e226]:
                  - generic [ref=e227]:
                    - generic [ref=e228]: "Application Name:"
                    - generic [ref=e229]: Software Catalogus
                  - generic [ref=e230]:
                    - generic [ref=e231]: "Version:"
                    - generic [ref=e232]: Unknown
                - generic [ref=e233]:
                  - heading "Support" [level=4] [ref=e234]
                  - paragraph [ref=e235]:
                    - text: For support, contact us at
                    - link "support@conduction.nl" [ref=e236] [cursor=pointer]:
                      - /url: mailto:support@conduction.nl
                  - paragraph [ref=e237]:
                    - text: For a Service Level Agreement (SLA), contact
                    - link "sales@conduction.nl" [ref=e238] [cursor=pointer]:
                      - /url: mailto:sales@conduction.nl
            - generic [ref=e239]:
              - heading "Software Catalog Configuration External documentation for Software Catalog Configuration" [level=2] [ref=e240]:
                - text: Software Catalog Configuration
                - link "External documentation for Software Catalog Configuration" [ref=e241] [cursor=pointer]:
                  - /url: https://docs.opencatalogi.nl
                  - img [ref=e242]:
                    - img [ref=e243]
              - paragraph [ref=e245]: Configure OpenRegister schema mappings for Software Catalog objects
            - generic [ref=e246]:
              - heading "Version Information" [level=2] [ref=e247]
              - paragraph [ref=e248]: Current application and configuration versions
              - generic [ref=e250]:
                - button "Show information about Version Information" [ref=e251] [cursor=pointer]:
                  - img [ref=e254]:
                    - img [ref=e255]
                - button "Force Update" [ref=e257] [cursor=pointer]:
                  - generic [ref=e259]: Force Update
                - button "Reset Auto-Config" [ref=e260] [cursor=pointer]:
                  - generic [ref=e262]: Reset Auto-Config
              - generic [ref=e267]:
                - generic [ref=e268]:
                  - strong [ref=e269]: "Application:"
                  - text: SoftwareCatalog v0.2.4
                - generic [ref=e270]:
                  - strong [ref=e271]: "Configured Version:"
                  - generic [ref=e272]: Not configured
                - generic [ref=e273]:
                  - strong [ref=e274]: "Status:"
                  - generic [ref=e275]: ⚠ Update needed
                - generic [ref=e276]:
                  - strong [ref=e277]: "Open Register:"
                  - generic [ref=e278]: ✓ Installed and active
            - generic [ref=e279]:
              - heading "Object Statistics" [level=2] [ref=e280]
              - paragraph [ref=e281]: Overview of objects stored in configured registers
              - generic [ref=e282]:
                - generic [ref=e284]: "Last updated: 6/6/2026, 12:39:50 PM"
                - table [ref=e286]:
                  - rowgroup [ref=e287]:
                    - row "Register Object Type Count Status Actions" [ref=e288]:
                      - columnheader "Register" [ref=e289]
                      - columnheader "Object Type" [ref=e290]
                      - columnheader "Count" [ref=e291]
                      - columnheader "Status" [ref=e292]
                      - columnheader "Actions" [ref=e293]
                  - rowgroup [ref=e294]:
                    - row "Voorzieningen Organisatie 0 Configured" [ref=e295]:
                      - cell "Voorzieningen" [ref=e296]
                      - cell "Organisatie" [ref=e297]
                      - cell "0" [ref=e298]
                      - cell "Configured" [ref=e299]:
                        - generic [ref=e300]: Configured
                      - cell [ref=e301]
                    - row "Voorzieningen Contactpersoon 0 Configured" [ref=e302]:
                      - cell "Voorzieningen" [ref=e303]
                      - cell "Contactpersoon" [ref=e304]
                      - cell "0" [ref=e305]
                      - cell "Configured" [ref=e306]:
                        - generic [ref=e307]: Configured
                      - cell [ref=e308]
                    - row "Voorzieningen Voorziening 0 Configured" [ref=e309]:
                      - cell "Voorzieningen" [ref=e310]
                      - cell "Voorziening" [ref=e311]
                      - cell "0" [ref=e312]
                      - cell "Configured" [ref=e313]:
                        - generic [ref=e314]: Configured
                      - cell [ref=e315]
                    - row "Voorzieningen Voorziening Aanbod 0 Configured" [ref=e316]:
                      - cell "Voorzieningen" [ref=e317]
                      - cell "Voorziening Aanbod" [ref=e318]
                      - cell "0" [ref=e319]
                      - cell "Configured" [ref=e320]:
                        - generic [ref=e321]: Configured
                      - cell [ref=e322]
                    - row "Voorzieningen Voorziening Versie 0 Configured" [ref=e323]:
                      - cell "Voorzieningen" [ref=e324]
                      - cell "Voorziening Versie" [ref=e325]
                      - cell "0" [ref=e326]
                      - cell "Configured" [ref=e327]:
                        - generic [ref=e328]: Configured
                      - cell [ref=e329]
                    - row "Voorzieningen Kwetsbaarheid 0 Configured" [ref=e330]:
                      - cell "Voorzieningen" [ref=e331]
                      - cell "Kwetsbaarheid" [ref=e332]
                      - cell "0" [ref=e333]
                      - cell "Configured" [ref=e334]:
                        - generic [ref=e335]: Configured
                      - cell [ref=e336]
                    - row "Voorzieningen Contract 0 Configured" [ref=e337]:
                      - cell "Voorzieningen" [ref=e338]
                      - cell "Contract" [ref=e339]
                      - cell "0" [ref=e340]
                      - cell "Configured" [ref=e341]:
                        - generic [ref=e342]: Configured
                      - cell [ref=e343]
                    - row "Voorzieningen Standaard 0 Configured" [ref=e344]:
                      - cell "Voorzieningen" [ref=e345]
                      - cell "Standaard" [ref=e346]
                      - cell "0" [ref=e347]
                      - cell "Configured" [ref=e348]:
                        - generic [ref=e349]: Configured
                      - cell [ref=e350]
                    - row "Voorzieningen Review 0 Configured" [ref=e351]:
                      - cell "Voorzieningen" [ref=e352]
                      - cell "Review" [ref=e353]
                      - cell "0" [ref=e354]
                      - cell "Configured" [ref=e355]:
                        - generic [ref=e356]: Configured
                      - cell [ref=e357]
                    - row "Voorzieningen Koppeling 0 Configured" [ref=e358]:
                      - cell "Voorzieningen" [ref=e359]
                      - cell "Koppeling" [ref=e360]
                      - cell "0" [ref=e361]
                      - cell "Configured" [ref=e362]:
                        - generic [ref=e363]: Configured
                      - cell [ref=e364]
                    - row "Voorzieningen Beoordeeling 0 Configured" [ref=e365]:
                      - cell "Voorzieningen" [ref=e366]
                      - cell "Beoordeeling" [ref=e367]
                      - cell "0" [ref=e368]
                      - cell "Configured" [ref=e369]:
                        - generic [ref=e370]: Configured
                      - cell [ref=e371]
                    - row "Voorzieningen Voorziening Module 0 Configured" [ref=e372]:
                      - cell "Voorzieningen" [ref=e373]
                      - cell "Voorziening Module" [ref=e374]
                      - cell "0" [ref=e375]
                      - cell "Configured" [ref=e376]:
                        - generic [ref=e377]: Configured
                      - cell [ref=e378]
                    - row "Voorzieningen Verklaring 0 Configured" [ref=e379]:
                      - cell "Voorzieningen" [ref=e380]
                      - cell "Verklaring" [ref=e381]
                      - cell "0" [ref=e382]
                      - cell "Configured" [ref=e383]:
                        - generic [ref=e384]: Configured
                      - cell [ref=e385]
                    - row "Voorzieningen Koppeling Gebruik 0 Configured" [ref=e386]:
                      - cell "Voorzieningen" [ref=e387]
                      - cell "Koppeling Gebruik" [ref=e388]
                      - cell "0" [ref=e389]
                      - cell "Configured" [ref=e390]:
                        - generic [ref=e391]: Configured
                      - cell [ref=e392]
                    - row "Voorzieningen Compliancy 0 Configured Sync Standards" [ref=e393]:
                      - cell "Voorzieningen" [ref=e394]
                      - cell "Compliancy" [ref=e395]
                      - cell "0" [ref=e396]
                      - cell "Configured" [ref=e397]:
                        - generic [ref=e398]: Configured
                      - cell "Sync Standards" [ref=e399]:
                        - button "Sync Standards" [ref=e400] [cursor=pointer]:
                          - generic [ref=e401]:
                            - img [ref=e403]:
                              - img [ref=e404]
                            - generic [ref=e406]: Sync Standards
                    - row "Voorzieningen Module Gebruik 0 Configured" [ref=e407]:
                      - cell "Voorzieningen" [ref=e408]
                      - cell "Module Gebruik" [ref=e409]
                      - cell "0" [ref=e410]
                      - cell "Configured" [ref=e411]:
                        - generic [ref=e412]: Configured
                      - cell [ref=e413]
                    - row "Voorzieningen Module Versie 0 Configured" [ref=e414]:
                      - cell "Voorzieningen" [ref=e415]
                      - cell "Module Versie" [ref=e416]
                      - cell "0" [ref=e417]
                      - cell "Configured" [ref=e418]:
                        - generic [ref=e419]: Configured
                      - cell [ref=e420]
                    - row "Voorzieningen Sector 0 Configured" [ref=e421]:
                      - cell "Voorzieningen" [ref=e422]
                      - cell "Sector" [ref=e423]
                      - cell "0" [ref=e424]
                      - cell "Configured" [ref=e425]:
                        - generic [ref=e426]: Configured
                      - cell [ref=e427]
                    - row "AMEF Elements 0 Configured" [ref=e428]:
                      - cell "AMEF" [ref=e429]
                      - cell "Elements" [ref=e430]
                      - cell "0" [ref=e431]
                      - cell "Configured" [ref=e432]:
                        - generic [ref=e433]: Configured
                      - cell [ref=e434]
                    - row "AMEF Organizations 0 Configured" [ref=e435]:
                      - cell "AMEF" [ref=e436]
                      - cell "Organizations" [ref=e437]
                      - cell "0" [ref=e438]
                      - cell "Configured" [ref=e439]:
                        - generic [ref=e440]: Configured
                      - cell [ref=e441]
                    - row "AMEF Relationships 0 Configured" [ref=e442]:
                      - cell "AMEF" [ref=e443]
                      - cell "Relationships" [ref=e444]
                      - cell "0" [ref=e445]
                      - cell "Configured" [ref=e446]:
                        - generic [ref=e447]: Configured
                      - cell [ref=e448]
                    - row "AMEF Views 0 Configured" [ref=e449]:
                      - cell "AMEF" [ref=e450]
                      - cell "Views" [ref=e451]
                      - cell "0" [ref=e452]
                      - cell "Configured" [ref=e453]:
                        - generic [ref=e454]: Configured
                      - cell [ref=e455]
                    - row "AMEF Models 0 Configured" [ref=e456]:
                      - cell "AMEF" [ref=e457]
                      - cell "Models" [ref=e458]
                      - cell "0" [ref=e459]
                      - cell "Configured" [ref=e460]:
                        - generic [ref=e461]: Configured
                      - cell [ref=e462]
                    - row "AMEF Properties 0 Configured" [ref=e463]:
                      - cell "AMEF" [ref=e464]
                      - cell "Properties" [ref=e465]
                      - cell "0" [ref=e466]
                      - cell "Configured" [ref=e467]:
                        - generic [ref=e468]: Configured
                      - cell [ref=e469]
            - generic [ref=e470]:
              - heading "General Settings" [level=2] [ref=e471]
              - paragraph [ref=e472]: Configure basic application settings
              - generic [ref=e474]:
                - button "Save General Settings" [disabled] [ref=e475]:
                  - generic [ref=e476]:
                    - img [ref=e478]:
                      - img [ref=e479]
                    - generic [ref=e481]: Save General Settings
                - button "Refresh" [ref=e482] [cursor=pointer]:
                  - generic [ref=e483]:
                    - img [ref=e485]:
                      - img [ref=e486]
                    - generic [ref=e488]: Refresh
              - generic [ref=e492]:
                - heading "Software Catalog Location" [level=3] [ref=e493]
                - paragraph [ref=e494]: Set the base URL for your software catalog interface
                - generic [ref=e496]:
                  - textbox "Software Catalog Location URL" [ref=e497] [cursor=pointer]:
                    - /placeholder: https://catalog.example.com
                  - generic: Software Catalog Location URL
                  - img [ref=e499]:
                    - img [ref=e500]
                - paragraph [ref=e503]: This URL will be used for external links to your software catalog. The system will append "/beheer" to this URL for management interfaces.
            - generic [ref=e504]:
              - heading "OpenRegister Integration" [level=2] [ref=e505]
              - paragraph [ref=e506]: Configure which schemas to use for organizations, contacts, and users
              - generic [ref=e508]:
                - button "Save Configuration" [disabled] [ref=e509]:
                  - generic [ref=e510]:
                    - img [ref=e512]:
                      - img [ref=e513]
                    - generic [ref=e515]: Save Configuration
                - button "Refresh" [ref=e516] [cursor=pointer]:
                  - generic [ref=e517]:
                    - img [ref=e519]:
                      - img [ref=e520]
                    - generic [ref=e522]: Refresh
              - generic [ref=e528]:
                - generic [ref=e529]:
                  - button "General Configuration" [ref=e530] [cursor=pointer]
                  - button "Voorzieningen" [ref=e531] [cursor=pointer]
                  - button "AMEF" [ref=e532] [cursor=pointer]
                - generic [ref=e536]:
                  - generic [ref=e538]:
                    - generic [ref=e539] [cursor=pointer]: Select Voorzieningen Register
                    - generic [ref=e540]:
                      - combobox "Select Voorzieningen Register" [ref=e542] [cursor=pointer]
                      - button [ref=e544] [cursor=pointer]:
                        - img [ref=e546]
                  - generic [ref=e549]:
                    - generic [ref=e550] [cursor=pointer]: Select AMEF Register
                    - generic [ref=e551]:
                      - combobox "Select AMEF Register" [ref=e553] [cursor=pointer]
                      - button [ref=e555] [cursor=pointer]:
                        - img [ref=e557]
            - generic [ref=e559]:
              - heading "User Groups Configuration" [level=2] [ref=e560]
              - paragraph [ref=e561]: Configure user groups for different access levels and permissions
              - generic [ref=e563]:
                - button "Save User Groups" [ref=e564] [cursor=pointer]:
                  - generic [ref=e565]:
                    - img [ref=e567]:
                      - img [ref=e568]
                    - generic [ref=e570]: Save User Groups
                - button "Refresh" [ref=e571] [cursor=pointer]:
                  - generic [ref=e572]:
                    - img [ref=e574]:
                      - img [ref=e575]
                    - generic [ref=e577]: Refresh
                - button "Show information about User Groups Configuration" [ref=e578] [cursor=pointer]:
                  - img [ref=e581]:
                    - img [ref=e582]
              - generic [ref=e588]:
                - button "Generic Groups" [ref=e589] [cursor=pointer]
                - button "Organization Admin Groups" [ref=e590] [cursor=pointer]
                - button "Super User Groups" [ref=e591] [cursor=pointer]
            - generic [ref=e593]:
              - heading "Organization Synchronization" [level=2] [ref=e594]
              - paragraph [ref=e595]: Synchronize organization data between OpenRegister and external systems
              - generic [ref=e597]:
                - button "Save Configuration" [disabled] [ref=e598]:
                  - generic [ref=e599]:
                    - img [ref=e601]:
                      - img [ref=e602]
                    - generic [ref=e604]: Save Configuration
                - button "Refresh Status" [ref=e605] [cursor=pointer]:
                  - generic [ref=e606]:
                    - img [ref=e608]:
                      - img [ref=e609]
                    - generic [ref=e611]: Refresh Status
                - button "Show information about Organization Synchronization" [ref=e612] [cursor=pointer]:
                  - img [ref=e615]:
                    - img [ref=e616]
                - button "Incremental Sync Now" [ref=e618] [cursor=pointer]:
                  - generic [ref=e619]:
                    - img [ref=e621]:
                      - img [ref=e622]
                    - generic [ref=e624]: Incremental Sync Now
              - generic [ref=e629]:
                - paragraph [ref=e630]: Monitor the status of organization and contact person synchronization
                - generic [ref=e631]:
                  - heading "Incremental Sync Time Window" [level=4] [ref=e632]
                  - paragraph [ref=e633]: Configure how far back to look for updated organizations during incremental synchronization
                  - generic [ref=e634]:
                    - generic [ref=e636]:
                      - generic [ref=e637] [cursor=pointer]: Time Window
                      - generic [ref=e638]:
                        - generic [ref=e639]:
                          - generic "10 minutes" [ref=e641]:
                            - generic [ref=e642]: 10 mi
                            - generic [ref=e643]: nutes
                          - combobox "Time Window" [ref=e644] [cursor=pointer]
                        - generic [ref=e645]:
                          - button "Clear selected" [ref=e646] [cursor=pointer]:
                            - img [ref=e647]:
                              - img [ref=e648]
                          - button [ref=e650] [cursor=pointer]:
                            - img [ref=e652]
                    - generic [ref=e654]:
                      - button "Refresh Configuration" [ref=e655] [cursor=pointer]:
                        - generic [ref=e656]:
                          - img [ref=e658]:
                            - img [ref=e659]
                          - generic [ref=e661]: Refresh Configuration
                      - button "Refresh Status" [ref=e662] [cursor=pointer]:
                        - generic [ref=e663]:
                          - img [ref=e665]:
                            - img [ref=e666]
                          - generic [ref=e668]: Refresh Status
                  - generic [ref=e669]: Incremental synchronization will process organizations updated within the last 10 minutes.
                - generic [ref=e671]:
                  - generic [ref=e672]:
                    - generic [ref=e673]:
                      - generic [ref=e674]: "Configuration:"
                      - generic [ref=e675]: ✓ Configured
                    - generic [ref=e676]:
                      - generic [ref=e677]:
                        - generic [ref=e678]: "Sync Mode:"
                        - generic [ref=e679]: incremental
                      - generic [ref=e680]:
                        - generic [ref=e681]: "Time Window:"
                        - generic [ref=e682]: 10 minutes
                      - generic [ref=e683]:
                        - generic [ref=e684]: "Total Organizations:"
                        - generic [ref=e685]: "5"
                      - generic [ref=e686]:
                        - generic [ref=e687]: "Organizations to Process:"
                        - generic [ref=e688]: "0"
                      - generic [ref=e689]:
                        - generic [ref=e690]: "Contact Persons to Process:"
                        - generic [ref=e691]: "0"
                      - generic [ref=e692]:
                        - generic [ref=e693]: "Efficiency Improvement:"
                        - generic [ref=e694]: 100%
                      - generic [ref=e695]:
                        - generic [ref=e696]: "Organization Entities:"
                        - generic [ref=e697]: "23"
                      - generic [ref=e698]:
                        - generic [ref=e699]: "Contact Schema:"
                        - generic [ref=e700]: ✓ Configured
                      - generic [ref=e701]:
                        - generic [ref=e702]: "Last Sync:"
                        - generic [ref=e703]: 6/6/2026, 10:33:44 AM
                  - generic [ref=e704]: No organizations to process in the current time window
                - generic [ref=e705]:
                  - heading "Sync Organisations to Voorzieningen Register" [level=4] [ref=e706]
                  - paragraph [ref=e707]: Synchronize OpenRegister organisations to the voorzieningen register as organisatie objects.
                  - generic [ref=e708]:
                    - generic [ref=e709]:
                      - generic [ref=e711] [cursor=pointer]:
                        - checkbox "Dry Run (preview only)" [checked] [ref=e712]
                        - text: Dry Run (preview only)
                      - generic [ref=e713]:
                        - generic [ref=e714] [cursor=pointer]: "Batch Size:"
                        - spinbutton "Batch Size:" [ref=e715] [cursor=pointer]: "500"
                    - button "Preview Sync" [ref=e717] [cursor=pointer]:
                      - generic [ref=e718]:
                        - img [ref=e720]:
                          - img [ref=e721]
                        - generic [ref=e723]: Preview Sync
                  - generic [ref=e724]:
                    - paragraph [ref=e725]:
                      - strong [ref=e726]: "What this does:"
                      - text: This sync ensures that all organisations from OpenRegister exist as organisatie objects in the voorzieningen register. This is needed for cross-organisation workflows like leverancier-gemeente gebruik suggestions.
                    - paragraph [ref=e727]:
                      - strong [ref=e728]: "Performance:"
                      - text: Uses bulk operations for optimal performance with large numbers of organisations (1000+).
                    - paragraph [ref=e729]:
                      - strong [ref=e730]: "Safety:"
                      - text: Only creates missing organisations - existing ones are skipped. Use dry run to preview changes.
                - generic [ref=e731]:
                  - heading "About Synchronization" [level=4] [ref=e732]
                  - paragraph [ref=e733]: "The synchronization process ensures that:"
                  - list [ref=e734]:
                    - listitem [ref=e735]:
                      - strong [ref=e736]: "Organization entities:"
                      - text: Every organization object has a corresponding organization entity
                    - listitem [ref=e737]:
                      - strong [ref=e738]: "User accounts:"
                      - text: Contact persons have Nextcloud user accounts
                    - listitem [ref=e739]:
                      - strong [ref=e740]: "Relationships:"
                      - text: Organization entities maintain correct user lists
                    - listitem [ref=e741]:
                      - strong [ref=e742]: "Status consistency:"
                      - text: Organization active status reflects the 'beoordeling' field
                  - paragraph [ref=e743]:
                    - strong [ref=e744]: "Time-based filtering:"
                    - text: Organizations remain in the sync queue based on their last update time in OpenRegister, not when they were last processed. An organization will naturally "age out" of the time window once it hasn't been updated for longer than the selected time period.
                  - paragraph [ref=e745]:
                    - strong [ref=e746]: "Automatic synchronization:"
                    - text: This process runs every 5 minutes in the background using incremental sync (10-minute window by default). Use manual sync for immediate updates or troubleshooting.
            - generic [ref=e747]:
              - heading "ArchiMate Import/Export" [level=2] [ref=e748]
              - paragraph [ref=e749]: Import and export ArchiMate models to/from OpenRegister
              - button "Show information about ArchiMate Import/Export" [ref=e752] [cursor=pointer]:
                - img [ref=e755]:
                  - img [ref=e756]
              - generic [ref=e761]:
                - generic [ref=e762]:
                  - generic [ref=e763]:
                    - button "Choose ArchiMate XML file" [ref=e764] [cursor=pointer]
                    - generic [ref=e765] [cursor=pointer]:
                      - img [ref=e766]:
                        - img [ref=e767]
                      - generic [ref=e769]: Choose ArchiMate XML file
                  - button "Import" [disabled] [ref=e771]:
                    - generic [ref=e772]:
                      - img [ref=e774]:
                        - img [ref=e775]
                      - generic [ref=e777]: Import
                - generic [ref=e778]:
                  - heading "Export" [level=4] [ref=e779]
                  - paragraph [ref=e780]: Export ArchiMate models filtered by organization
                  - generic [ref=e781]:
                    - generic [ref=e782]:
                      - generic [ref=e783] [cursor=pointer]: "Organization:"
                      - generic [ref=e784]:
                        - generic [ref=e785] [cursor=pointer]: Select Organization
                        - generic [ref=e786]:
                          - combobox "Select Organization" [ref=e788] [cursor=pointer]
                          - button [ref=e790] [cursor=pointer]:
                            - img [ref=e792]
                    - button "Export Base" [ref=e794] [cursor=pointer]:
                      - generic [ref=e795]:
                        - img [ref=e797]:
                          - img [ref=e798]
                        - generic [ref=e800]: Export Base
                    - button "Organization Export" [disabled] [ref=e801]:
                      - generic [ref=e802]:
                        - img [ref=e804]:
                          - img [ref=e805]
                        - generic [ref=e807]: Organization Export
            - generic [ref=e808]:
              - heading "Email Configuration" [level=2] [ref=e809]
              - paragraph [ref=e810]: Configure email settings for notifications and user management
              - generic [ref=e812]:
                - button "Save Email Settings" [ref=e813] [cursor=pointer]:
                  - generic [ref=e814]:
                    - img [ref=e816]:
                      - img [ref=e817]
                    - generic [ref=e819]: Save Email Settings
                - button "Show information about Email Configuration" [ref=e820] [cursor=pointer]:
                  - img [ref=e823]:
                    - img [ref=e824]
              - generic [ref=e829]:
                - generic [ref=e830]:
                  - button "Settings" [ref=e831] [cursor=pointer]
                  - button "Email Types" [ref=e832] [cursor=pointer]
                  - button "Testing" [ref=e833] [cursor=pointer]
                  - button "Templates" [ref=e834] [cursor=pointer]
                - generic [ref=e837]:
                  - heading "Email Settings" [level=3] [ref=e838]
                  - paragraph [ref=e839]: Configure email notifications for organization and user events
                  - generic [ref=e840]:
                    - generic [ref=e841]:
                      - checkbox "Enable Email Notifications" [ref=e842] [cursor=pointer]
                      - generic [ref=e843] [cursor=pointer]:
                        - img [ref=e845]:
                          - img [ref=e847]
                        - generic [ref=e850]: Enable Email Notifications
                    - paragraph [ref=e851]: Enable or disable all email notifications from the system
                  - generic [ref=e852]:
                    - heading "Sender Information" [level=4] [ref=e853]
                    - generic [ref=e855]:
                      - textbox "Sender Email" [disabled] [ref=e856]:
                        - /placeholder: noreply@example.com
                        - text: noreply@softwarecatalogus.nl
                      - generic: Sender Email
                    - generic [ref=e858]:
                      - textbox "Sender Name" [disabled] [ref=e859]:
                        - /placeholder: Software Catalog
                        - text: Software Catalogus
                      - generic: Sender Name
                  - generic [ref=e860]:
                    - heading "Test Configuration" [level=4] [ref=e861]
                    - generic [ref=e863]:
                      - textbox "Test Receiver Override" [disabled] [ref=e864]:
                        - /placeholder: test@example.com
                      - generic: Test Receiver Override
                    - paragraph [ref=e865]: If set, all emails will be sent to this address instead of the intended recipients (useful for testing)
                  - generic [ref=e866]:
                    - heading "Email Transport" [level=4] [ref=e867]
                    - generic [ref=e868]:
                      - generic [ref=e869] [cursor=pointer]: Transport Type
                      - generic [ref=e870]:
                        - generic [ref=e871]:
                          - generic "smtp" [ref=e873]:
                            - generic [ref=e874]: smtp
                          - combobox "Transport Type" [disabled] [ref=e875]
                        - button [ref=e877]:
                          - img [ref=e879]
                  - generic [ref=e881]:
                    - heading "SMTP Configuration" [level=4] [ref=e882]
                    - generic [ref=e883]:
                      - generic [ref=e885]:
                        - textbox "SMTP Host" [disabled] [ref=e886]:
                          - /placeholder: smtp.gmail.com
                          - text: localhost
                        - generic: SMTP Host
                      - generic [ref=e888]:
                        - textbox "SMTP Port" [disabled] [ref=e889]:
                          - /placeholder: "587"
                          - text: "587"
                        - generic: SMTP Port
                      - generic [ref=e890]:
                        - generic [ref=e891] [cursor=pointer]: Encryption
                        - generic [ref=e892]:
                          - generic [ref=e893]:
                            - generic "tls" [ref=e895]:
                              - generic [ref=e896]: tls
                            - combobox "Encryption" [disabled] [ref=e897]
                          - button [ref=e899]:
                            - img [ref=e901]
                      - generic [ref=e904]:
                        - textbox "SMTP Username" [disabled] [ref=e905]:
                          - /placeholder: your-email@gmail.com
                        - generic: SMTP Username
                      - generic [ref=e907]:
                        - textbox "SMTP Password" [disabled] [ref=e908]:
                          - /placeholder: Your app password
                        - generic: SMTP Password
                        - button "Show password" [disabled] [ref=e909]:
                          - img [ref=e912]:
                            - img [ref=e913]
            - generic [ref=e915]:
              - heading "Background Jobs Configuration" [level=2] [ref=e916]
              - paragraph [ref=e917]: Configure user and organisation context for background jobs to enable proper authorization
              - button "Refresh" [ref=e920] [cursor=pointer]:
                - generic [ref=e921]:
                  - img [ref=e923]:
                    - img [ref=e924]
                  - generic [ref=e926]: Refresh
              - generic [ref=e930]:
                - note [ref=e931]:
                  - img [ref=e932]:
                    - img [ref=e933]
                  - generic [ref=e935]: Background jobs (cronjobs) need a user and organisation context to properly access data with correct permissions. Configure each job below to set which user and organisation it should run as.
                - generic [ref=e937]:
                  - generic [ref=e938]:
                    - generic [ref=e939]:
                      - heading "Organization Contact Sync" [level=4] [ref=e940]
                      - generic [ref=e941]:
                        - checkbox "Enabled" [checked] [ref=e942] [cursor=pointer]
                        - generic [ref=e943] [cursor=pointer]:
                          - img [ref=e945]:
                            - img [ref=e947]
                          - generic [ref=e950]: Enabled
                    - paragraph [ref=e951]: Syncs organizations and contacts between SoftwareCatalog and OpenRegister.
                    - generic [ref=e952]:
                      - img [ref=e953]:
                        - img [ref=e954]
                      - text: Runs every 5 minutes
                  - generic [ref=e956]:
                    - generic [ref=e957]:
                      - generic [ref=e958]:
                        - generic [ref=e959] [cursor=pointer]: Run as User
                        - generic [ref=e960]:
                          - generic [ref=e961] [cursor=pointer]: Select a user
                          - generic [ref=e962]:
                            - combobox "Select a user" [ref=e964] [cursor=pointer]
                            - button [ref=e966] [cursor=pointer]:
                              - img [ref=e968]
                      - generic [ref=e970]:
                        - generic [ref=e971] [cursor=pointer]: Run in Organisation
                        - generic [ref=e972]:
                          - generic [ref=e973] [cursor=pointer]: Select an organisation
                          - generic [ref=e974]:
                            - combobox "Select an organisation" [ref=e976] [cursor=pointer]
                            - button [ref=e978] [cursor=pointer]:
                              - img [ref=e980]
                    - generic [ref=e982]:
                      - button "Save Configuration" [disabled] [ref=e983]:
                        - generic [ref=e984]:
                          - img [ref=e986]:
                            - img [ref=e987]
                          - generic [ref=e989]: Save Configuration
                      - button "Run Now" [disabled] [ref=e990]:
                        - generic [ref=e991]:
                          - img [ref=e993]:
                            - img [ref=e994]
                          - generic [ref=e996]: Run Now
                      - generic [ref=e997]:
                        - img [ref=e998]:
                          - img [ref=e999]
                        - generic [ref=e1001]: Not configured - Job may encounter RBAC errors
        - button "Save" [disabled] [ref=e1003]:
          - generic [ref=e1004]:
            - img [ref=e1006]:
              - img [ref=e1007]
            - generic [ref=e1009]: Save
    - button "Open AI chat" [ref=e1011] [cursor=pointer]:
      - img [ref=e1013]:
        - img [ref=e1014]
```

# Test source

```ts
  1   | // SPDX-License-Identifier: EUPL-1.2
  2   | // SPDX-FileCopyrightText: 2026 Conduction B.V.
  3   | /**
  4   |  * Real UI smoke coverage for the manifest-driven SoftwareCatalog SPA pages.
  5   |  *
  6   |  * src/manifest.json declares the rendering pages (index / detail / dashboard /
  7   |  * roadmap / settings). The app shell (CnAppRoot) uses vue-router in *history*
  8   |  * mode with base `/apps/softwarecatalog`, so every page is a real deep-linkable
  9   |  * path. Each test drives the real UI by navigating to the page route and
  10  |  * asserting the Vue shell mounted (the `#softwarecatalog` mount node renders
  11  |  * content) and the page-specific title text is visible — no Vue-internals
  12  |  * patching.
  13  |  *
  14  |  * GATE-19 COVERAGE
  15  |  * ----------------
  16  |  * The `fe-*` FE specs have been promoted into `openspec/specs/`, so the
  17  |  * *render/load* scenarios of those specs are now gate-visible and are covered
  18  |  * by the navigation tests below via `// @e2e <spec>::<slug>` annotations:
  19  |  *  - the dashboard page covers fe-shell-navigation "Open the dashboard" and
  20  |  *    fe-organizations "Show concept organisations" (the concept-orgs widget);
  21  |  *  - the settings page covers fe-settings-ui "Open settings" / "View
  22  |  *    statistics" / "View version information" (the settings shell renders all
  23  |  *    of its sections including statistics + version);
  24  |  *  - the organisaties page covers fe-organizations "Display an organisation
  25  |  *    card".
  26  |  * The remaining `fe-*` scenarios describe store actions, modal interactions
  27  |  * and presentational-component behaviour driven by live object data (save /
  28  |  * merge / migrate / upload / mass-ops / heartbeat / theme / pagination /
  29  |  * collapsible toggle / per-icon publication state). Those are exercised by the
  30  |  * Vue component + Pinia store unit tests (vitest), not by Playwright UI smoke,
  31  |  * and carry standalone `@e2e exclude` directives in their spec blocks.
  32  |  */
  33  | 
  34  | import { test, expect, type Page } from '@playwright/test'
  35  | 
  36  | const APP_BASE = '/apps/softwarecatalog'
  37  | 
  38  | /**
  39  |  * Navigate to an in-app SPA route and wait for the Vue shell to mount.
  40  |  * Returns once the `#softwarecatalog` mount node has rendered child content.
  41  |  */
  42  | async function gotoAppRoute(page: Page, route: string): Promise<void> {
  43  | 	await page.goto(`${APP_BASE}${route}`, { waitUntil: 'networkidle' })
  44  | 	// The Vue app mounts into <div id="softwarecatalog"></div>; once mounted it
  45  | 	// contains the rendered NcAppContent tree. Wait for non-empty content.
> 46  | 	await page.waitForFunction(
      |             ^ Error: page.waitForFunction: Test timeout of 60000ms exceeded.
  47  | 		() => {
  48  | 			const root = document.getElementById('softwarecatalog')
  49  | 			return !!root && root.children.length > 0
  50  | 		},
  51  | 		{ timeout: 30000 },
  52  | 	)
  53  | }
  54  | 
  55  | /**
  56  |  * Assert the app shell rendered (app-content present) and the given title text
  57  |  * is visible somewhere in the rendered page. Uses .first() because the manifest
  58  |  * title can appear both in the nav and the page header.
  59  |  */
  60  | async function expectPageRendered(page: Page, title: string): Promise<void> {
  61  | 	const root = page.locator('#softwarecatalog')
  62  | 	await expect(root).toBeVisible()
  63  | 	await expect(page.getByText(title, { exact: false }).first()).toBeVisible({ timeout: 30000 })
  64  | }
  65  | 
  66  | // ---------------------------------------------------------------------------
  67  | // Dashboard (type: dashboard) — widget grid
  68  | // ---------------------------------------------------------------------------
  69  | // @e2e fe-shell-navigation::open-the-dashboard
  70  | // @e2e fe-organizations::show-concept-organisations
  71  | test('manifest dashboard: dashboard page renders the widget grid', async ({ page }) => {
  72  | 	await gotoAppRoute(page, '/')
  73  | 	await expectPageRendered(page, 'Dashboard')
  74  | })
  75  | 
  76  | // ---------------------------------------------------------------------------
  77  | // Index pages (type: index) — object list surfaces
  78  | // ---------------------------------------------------------------------------
  79  | // The organisaties index renders the organisation cards (OrganisatieCard);
  80  | // asserting the page renders covers fe-organizations "Display an organisation
  81  | // card" — the card list is the page body.
  82  | // @e2e fe-organizations::display-an-organisation-card
  83  | test('manifest index organisaties: list page renders the organisation cards', async ({ page }) => {
  84  | 	await gotoAppRoute(page, '/organisaties')
  85  | 	await expectPageRendered(page, 'Organisations')
  86  | })
  87  | 
  88  | const INDEX_PAGES: Array<{ route: string; title: string; name: string }> = [
  89  | 	{ route: '/contactpersonen', title: 'Contacts', name: 'contactpersonen' },
  90  | 	{ route: '/contracten', title: 'Contracts', name: 'contracten' },
  91  | 	{ route: '/standaarden', title: 'Standards', name: 'standaarden' },
  92  | 	{ route: '/reviews', title: 'Reviews', name: 'reviews' },
  93  | 	{ route: '/komplianties', title: 'Compliance', name: 'komplianties' },
  94  | 	{ route: '/moduleversies', title: 'Module versions', name: 'moduleversies' },
  95  | ]
  96  | 
  97  | for (const p of INDEX_PAGES) {
  98  | 	test(`manifest index ${p.name}: list page renders`, async ({ page }) => {
  99  | 		await gotoAppRoute(page, p.route)
  100 | 		await expectPageRendered(page, p.title)
  101 | 	})
  102 | }
  103 | 
  104 | // ---------------------------------------------------------------------------
  105 | // Roadmap page (type: roadmap)
  106 | // ---------------------------------------------------------------------------
  107 | test('manifest roadmap features-roadmap: roadmap page renders', async ({ page }) => {
  108 | 	await gotoAppRoute(page, '/features-roadmap')
  109 | 	await expectPageRendered(page, 'Features')
  110 | })
  111 | 
  112 | // ---------------------------------------------------------------------------
  113 | // Detail pages (type: detail) — deep-link with a synthetic id.
  114 | // The detail renderer mounts even when the object id resolves to nothing
  115 | // (empty data / 404 from the OR slug endpoint): we assert the shell mounted,
  116 | // not that a specific object loaded, so the test stays green against an empty
  117 | // dev dataset. This proves the detail route is wired and the SPA renders it.
  118 | // ---------------------------------------------------------------------------
  119 | const DETAIL_PAGES: Array<{ route: string; name: string }> = [
  120 | 	{ route: '/contactpersonen/smoke-id', name: 'contactpersoon-detail' },
  121 | 	{ route: '/contracten/smoke-id', name: 'contract-detail' },
  122 | 	{ route: '/standaarden/smoke-id', name: 'standaard-detail' },
  123 | 	{ route: '/reviews/smoke-id', name: 'review-detail' },
  124 | 	{ route: '/komplianties/smoke-id', name: 'kompliantie-detail' },
  125 | 	{ route: '/moduleversies/smoke-id', name: 'moduleversie-detail' },
  126 | ]
  127 | 
  128 | for (const p of DETAIL_PAGES) {
  129 | 	test(`manifest detail ${p.name}: detail route mounts the SPA shell`, async ({ page }) => {
  130 | 		await gotoAppRoute(page, p.route)
  131 | 		// Shell mounted; the app-content container is rendered regardless of
  132 | 		// whether the synthetic id resolves to an object.
  133 | 		await expect(page.locator('#softwarecatalog')).toBeVisible()
  134 | 	})
  135 | }
  136 | 
  137 | // ---------------------------------------------------------------------------
  138 | // Settings page (type: settings) — in-app settings surface
  139 | // ---------------------------------------------------------------------------
  140 | // The settings shell (SoftwareCatalogSettings.vue) renders its section
  141 | // navigation and the configuration status — fe-settings-ui "Open settings".
  142 | // @e2e fe-settings-ui::open-settings
  143 | test('manifest settings: in-app settings page renders', async ({ page }) => {
  144 | 	await gotoAppRoute(page, '/settings')
  145 | 	await expect(page.locator('#softwarecatalog')).toBeVisible()
  146 | 	await expect(page.getByText('SoftwareCatalog', { exact: false }).first()).toBeVisible({ timeout: 30000 })
```