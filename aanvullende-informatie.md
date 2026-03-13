# Aanvullende Informatie — IGS Issues Softwarecatalogus

> Dit document bevat alle issues met Onderdeel-van: IGS uit het [VNG-Realisatie/Softwarecatalogus](https://github.com/VNG-Realisatie/Softwarecatalogus) project, inclusief analyse per issue.
> Alle issues zijn ook beschikbaar als individuele markdown bestanden in de [issues/](issues/) map (met beschrijving, reacties en afbeeldingen).
> Zie ook: [issues.md](issues.md) voor de volledige lijst met acceptatiecriteria per issue.

**Totaal: 160 issues** | Open: 207 | Gesloten: 233
**Laatste sync:** 2026-03-09 | +1 nieuw issue (#457)

---

## Databronnen

De volgende bestanden zijn beschikbaar voor het verifiëren van datakwaliteit-issues:

### CSV Importbestanden (client-aangeleverd)
Deze CSV-bestanden bevatten de data die door de klant is aangeleverd voor import in de Softwarecatalogus. Ze worden gebruikt om claims over datakwaliteit te onderbouwen (ontbrekende relaties, orphaned references, etc.).

| Bestand | Omschrijving | Pad |
|---------|-------------|-----|
| `module.csv` | Applicaties/modules (6100+ records) | [data/module.csv](data/module.csv) |
| `koppeling.csv` | Koppelingen tussen modules (3400+ records) | [data/koppeling.csv](data/koppeling.csv) |
| `organisatie.csv` | Organisaties (gemeenten, leveranciers) | [data/organisatie.csv](data/organisatie.csv) |
| `contactpersoon.csv` | Contactpersonen per organisatie | [data/contactpersoon.csv](data/contactpersoon.csv) |
| `compliancy.csv` | Compliance-status per standaard | [data/compliancy.csv](data/compliancy.csv) |
| `gebruik.csv` | Gebruik van applicaties door gemeenten | [data/gebruik.csv](data/gebruik.csv) |
| `gebruik_2.csv` | Gebruik (vervolg) | [data/gebruik_2.csv](data/gebruik_2.csv) |
| `gebruik_3.csv` | Gebruik (vervolg) | [data/gebruik_3.csv](data/gebruik_3.csv) |
| `moduleversie.csv` | Versies van modules | [data/moduleversie.csv](data/moduleversie.csv) |

### GEMMA ArchiMate Model (AMEF)
Het GEMMA ArchiMate Exchange Format bestand bevat het volledige architectuurmodel dat wordt geïmporteerd in het AMEF-register.

| Bestand | Omschrijving | Pad |
|---------|-------------|-----|
| `GEMMA release.xml` | Volledig GEMMA ArchiMate model (13.3 MB) | `softwarecatalog/data/GEMMA release.xml` |
| `GEMMA_release.xml` | Kopie in Settings directory (13.4 MB) | `softwarecatalog/lib/Settings/GEMMA_release.xml` |
| Turfbrug test model | VNG Realisatie test-export (15 MB) | `Softwarecatalogus/docs/examples/02-04-2025_GEMMA 2_Turfbrug (test VNG Realisatie)_ameff_model.xml` |

### Analyse: Orphaned buitengemeentelijkVoorziening referenties in koppelingen

Van de 3.406 koppelingen in het importbestand hebben **876** (25,7%) een `buitengemeentelijkVoorziening` waarde (alle met `type=extern`). Deze verwijzen naar **39 unieke** element-UUIDs uit het GEMMA ArchiMate model. De elementen worden apart geïmporteerd via de GEMMA XML — ze staan **niet** in de CSV-importdata.

**Import-afhankelijkheid**: Alle 39 BGV-UUIDs zijn afwezig in de CSV-bestanden. Als de GEMMA XML-import niet is uitgevoerd, hebben alle 876 koppelingen hangende referenties. De `_buitengemeentelijkVoorzieningNaam` kolom in de CSV biedt een gedenormaliseerde fallback voor weergave.

**5 werkelijk orphaned referenties** (20 koppelingen):

| CSV UUID | Naam | Reden | Koppelingen |
|----------|------|-------|-------------|
| `a0ce4c62-9619-4d60-bb27-7eabb5a9005e` | DSO-LV | Niet in GEMMA XML — nieuwere voorziening | 15 |
| `49b7255f-0217-4a0c-b23f-125c97252948` | LVBB | Niet in GEMMA XML — nieuwere voorziening | 2 |
| `4f4ebcbe-8af0-412a-b32d-721544b23cb1` | Softwarecatalogus.nl | Andere UUID in GEMMA XML (`c32b8ee1...`) | 1 |
| `762ed5d8-c2a2-45eb-94fc-953dd0ab2136` | Werkgeversinstrumentgids | Andere UUID in GEMMA XML (`f3bd5b40...`) | 1 |
| `e814e6c0-966a-4fee-af6d-249603e7c850` | Werkzoekendeninstrumentgids | Andere UUID in GEMMA XML (`08f55ef1...`) | 1 |

**Oorzaak**: DSO-LV en LVBB zijn nieuwere landelijke voorzieningen die (nog) niet in het GEMMA ArchiMate model zijn opgenomen. De overige 3 bestaan wel in de XML maar onder een ander UUID-formaat (hex-only vs. gehypheneerd).

**Top 10 meest gekoppelde BGV's** (correct in GEMMA XML):

| Naam | Koppelingen |
|------|-------------|
| GBA-V (Verstrekkingsvoorziening) | 99 |
| NHR - Handelsregister | 77 |
| OLO - OmgevingsLoket Online | 68 |
| BRK - Basisregistratie Kadaster | 60 |
| MijnOverheid.nl | 56 |
| GGK - Gemeentelijk Gegevensknooppunt | 53 |
| DigiD | 49 |
| LV-BAG | 45 |
| LV-WOZ | 45 |
| JUBES | 44 |

### Postman/Newman API Tests
| Bestand | Omschrijving | Pad |
|---------|-------------|-----|
| Collection | 134 requests, 150 assertions, 11 folders | [postman/softwarecatalogus-tests.json](postman/softwarecatalogus-tests.json) |
| Environment (local) | Variabelen voor localhost:8080 | [postman/environment-local.json](postman/environment-local.json) |

---

## Agent Workflow — Issue-per-Issue Reacties Voorbereiden

Dit hoofdstuk beschrijft hoe sub-agents elke open issue moeten verwerken om een GitHub-reactie met bewijs voor te bereiden.

### Doel
Voor elke **open** issue bereiden we een markdown-reactie voor die:
1. De oorzaak uitlegt (bug, datakwaliteit, tekstueel, wens, of functionaliteit)
2. Bewijs levert (screenshots, data-analyse, code-referenties)
3. De huidige status aantoont (opgelost, onderzocht, of buiten scope)

> **BELANGRIJK: Alleen voorbereiden, NIET plaatsen!**
> Agents mogen NOOIT reacties plaatsen op GitHub issues. Alle reacties worden lokaal opgeslagen in `Softwarecatalogus/reacties/{nummer}.md` zodat ze eerst handmatig gereviewd kunnen worden voordat ze worden geplaatst.

### Categorie-templates

#### Template A: Bug — Opgelost
Gebruik voor issues gecategoriseerd als **Bug** die inmiddels zijn opgelost.

```markdown
## Status: Opgelost

Dit issue is opgelost. Hieronder het bewijs:

### Situatie voor de fix
{Beschrijf kort wat het probleem was}

### Huidige situatie
{Beschrijf wat er nu gebeurt}

### Bewijs
{Screenshot(s) die aantonen dat het probleem is opgelost}

Getest op: {datum}
Omgeving: {URL}
```

#### Template B: Bug — In behandeling
Gebruik voor issues gecategoriseerd als **Bug** die nog niet zijn opgelost.

```markdown
## Status: In behandeling

Dit issue is in onderzoek. Bevindingen tot nu toe:

### Analyse
{Beschrijf de root cause}

### Huidige situatie
{Screenshot(s) van de huidige staat — toont het probleem nog steeds of is het deels opgelost}

### Verwachte oplossing
{Beschrijf de geplande aanpak}

### Voortgang
- [ ] Root cause geïdentificeerd
- [ ] Fix geïmplementeerd
- [ ] Getest
```

#### Template C: Datakwaliteit — Importdata
Gebruik voor issues gecategoriseerd als **Datakwaliteit**.

```markdown
## Status: Datakwaliteit importbestanden

Dit gedrag wordt veroorzaakt door ontbrekende of foutieve data in de aangeleverde importbestanden, niet door een fout in de applicatie.

### Oorzaak
{Beschrijf welk CSV-bestand en welke velden het probleem veroorzaken}

### Data-analyse
{Toon specifieke voorbeelden uit de CSV-bestanden}
- Bestand: `{bestandsnaam}`
- Aantal records met ontbrekende referenties: {aantal}
- Voorbeeld: `{rij uit CSV}`

### Applicatiegedrag
De applicatie verwerkt de data correct volgens het ontwerp:
{Leg uit hoe de applicatie omgaat met ontbrekende referenties}

### Aanbeveling
{Optioneel: suggestie voor dataopschoning of validatie bij import}
```

#### Template D: Tekstueel — Doorgevoerd
Gebruik voor issues gecategoriseerd als **Tekstueel** die zijn doorgevoerd.

```markdown
## Status: Doorgevoerd

De gevraagde tekstuele aanpassing is doorgevoerd.

### Wijziging
- **Was**: "{oude tekst}"
- **Is nu**: "{nieuwe tekst}"

### Bewijs
{Screenshot van de huidige situatie}

Getest op: {datum}
Omgeving: {URL}
```

#### Template E: Wens — Buiten scope
Gebruik voor issues gecategoriseerd als **Wens**.

```markdown
## Status: Wens (buiten oorspronkelijke scope)

Dit verzoek betreft nieuwe functionaliteit die niet is opgenomen in het oorspronkelijke Programma van Eisen.

### Toelichting
{Leg uit waarom dit een wens is en niet een bug of ontbrekende functionaliteit}

### Huidige werking
{Beschrijf hoe de applicatie nu werkt op dit punt}

### Bewijs
{Screenshot(s) van de huidige werking — toont aan dat de applicatie correct functioneert binnen de oorspronkelijke scope}

### Mogelijke vervolgstap
{Optioneel: suggestie voor backlog of doorontwikkeling}
```

#### Template F: Functionaliteit — Uitleg werkwijze
Gebruik voor issues waar de gemelde situatie correct gedrag is maar verkeerd begrepen.

```markdown
## Status: Werkt conform ontwerp

Het beschreven gedrag is correct en werkt zoals ontworpen.

### Werking
{Leg uit hoe de functionaliteit werkt en waarom dit het verwachte gedrag is}

### Configuratie
{Optioneel: verwijs naar RBAC-regels, register-configuratie, of andere instellingen}

### Bewijs
{Screenshot(s) die de correcte werking aantonen}
```

### Agent-instructies per issue

**Elke sub-agent verwerkt één issue per keer.** Dit houdt het contextvenster klein en de resultaten beheersbaar.

#### Stap 1: Issue lezen
```
Lees het issue-bestand: Softwarecatalogus/issues/{nummer}.md
```
Dit bevat de volledige beschrijving, alle reacties, en afbeeldingsreferenties.

#### Stap 2: Categorie bepalen
Zoek het issue op in de samenvattingstabel hierboven. De categorie (Bug/Datakwaliteit/Tekstueel/Wens/Nog te bepalen) bepaalt welk template wordt gebruikt.

#### Stap 3: Onderzoek uitvoeren

Afhankelijk van de categorie:

| Categorie | Acties |
|-----------|--------|
| **Bug** | 1. Reproduceer in browser (navigeer naar relevante pagina, voer stappen uit)<br>2. Controleer of het probleem is opgelost<br>3. Maak screenshot(s) als bewijs |
| **Datakwaliteit** | 1. Lees het relevante CSV-bestand (`Softwarecatalogus/data/{bestand}.csv`)<br>2. Zoek naar de specifieke data die het probleem veroorzaakt<br>3. Tel ontbrekende referenties of foutieve waarden<br>4. Toon voorbeelden uit de CSV |
| **Tekstueel** | 1. Navigeer naar de pagina/wizard waar de tekst staat<br>2. Controleer of de tekst is aangepast<br>3. Maak screenshot als bewijs |
| **Wens** | 1. Lees het PvE (issues.md) om te bevestigen dat dit buiten scope valt<br>2. Beschrijf de huidige werking<br>3. Maak screenshot(s) van de huidige werking als bewijs |
| **Nog te bepalen** | 1. Analyseer het issue grondig<br>2. Bepaal de juiste categorie<br>3. Volg de instructies voor die categorie |

#### Stap 4: Reactie schrijven
Schrijf de reactie in markdown volgens het bijbehorende template. Sla op als:
```
Softwarecatalogus/reacties/{nummer}.md
```

#### Stap 5: Screenshots opslaan
Sla screenshots op in:
```
Softwarecatalogus/reacties/screenshots/{nummer}-{beschrijving}.png
```

### Omgeving voor agents

| Instelling | Waarde |
|------------|--------|
| Frontend URL | http://localhost:3000 |
| Backend URL | http://localhost:8080 |
| Admin credentials | admin / admin |
| Test-gebruikers | Zie `softwarecatalog/.claude/skills/test-softwarecatalog.md` |

### Browsergebruik
- Gebruik de toegewezen browser (zie browser-pool in CLAUDE.md)
- Login via Frontend URL + `/login`
- Voer `localStorage.clear()` uit voor het inloggen
- Voor publieke issues: test zonder in te loggen

### Data-verificatie commando's

Gebruik deze bash-commando's voor snelle data-verificatie:

```bash
# Tel orphaned koppelingen (verwijzen naar niet-bestaande modules)
# Haal moduleA/moduleB UUIDs uit koppeling.csv en check tegen module.csv IDs
grep -c "moduleA" Softwarecatalogus/data/koppeling.csv

# Zoek een specifieke organisatie in importdata
grep -i "centric" Softwarecatalogus/data/organisatie.csv

# Tel modules per aanbieder
cut -d',' -f2 Softwarecatalogus/data/module.csv | sort | uniq -c | sort -rn | head -20

# Zoek contactpersonen zonder e-mailadres
awk -F',' '{if ($NF == "" || $NF == "\"\"") print}' Softwarecatalogus/data/contactpersoon.csv | wc -l
```

### RBAC-referentie voor agents
De RBAC-regels staan in: `softwarecatalog/lib/Settings/softwarecatalogus_register.json`
Raadpleeg dit bestand wanneer een issue gaat over zichtbaarheid, toegang, of organisatie-scoping.

### Voortgang bijhouden
Na het verwerken van elk issue, update deze tabel:

| # | Status reactie | Agent | Datum |
|---|---------------|-------|-------|
| {nummer} | Concept / Klaar / Geplaatst | {agent-id} | {datum} |

## Samenvatting

| Categorie | Totaal | Open | Gesloten | Omschrijving |
|-----------|--------|------|----------|-------------|
| Bug | 92 | 45 | 47 | Daadwerkelijke fouten in de applicatie |
| Datakwaliteit | 13 | 10 | 3 | Veroorzaakt door ontbrekende of foutieve data in client-importbestanden |
| Tekstueel | 20 | 7 | 13 | Inconsistente labels, schrijfwijze, terminologie |
| Wens | 21 | 11 | 10 | Nieuwe functionaliteit buiten oorspronkelijke scope |
| Nog te bepalen | 4 | 3 | 1 | Combinatie van factoren, nader onderzoek nodig |

**86 van 144 issues (59%) zijn daadwerkelijke bugs.** De overige 54 issues (37%) zijn datakwaliteit (13), tekstueel (20), of wensen (21).

---

## Open issues
*77 issues*

### Bug (46)

| # | Issue | Analyse | GitHub | Checked |
|---|-------|---------|--------|--------|
| [15](issues/15.md) | Als aanbod- en gebruik-beheerder wil ik data vanuit de softwarecatalogus kunnen exporteren | Export toont verkeerde kolommen en UUID's; geimporteerde data mist diensten | [#15](https://github.com/VNG-Realisatie/Softwarecatalogus/issues/15) | [ ] |
| [65](issues/65.md) | Als aanbod- en gebruik-beheerder van een organisatie wil ik mijn collega's toegang kunnen geven tot de softwarecatalogus | Gebruikersbeheer en uitnodigen collega's werkt niet goed, diverse UI-fouten | [#65](https://github.com/VNG-Realisatie/Softwarecatalogus/issues/65) | [ ] |
| [73](issues/73.md) | Als aanbod-beheerder wil ik meerdere contactpersonen kunnen registreren en deze aan specifieke pakketten kunnen koppelen | Contactpersonen aanmaken/bewerken werkt niet goed, wijzigingen niet opgeslagen | [#73](https://github.com/VNG-Realisatie/Softwarecatalogus/issues/73) | [ ] |
| [144](issues/144.md) | Als gebruiker van de Softwarecatalogus wil ik een overzicht met zoek- en filteropties van alle organisaties die pakketten of diensten aanbieden | Zoekfilters werken niet goed, ontbrekende filters en UUID's bij organisatienamen | [#144](https://github.com/VNG-Realisatie/Softwarecatalogus/issues/144) | [ ] |
| [169](issues/169.md) | Rest issues van Organisatie en Configuratie | Restpunten organisatie: registratieformulier niet gekoppeld aan Mijn Account | [#169](https://github.com/VNG-Realisatie/Softwarecatalogus/issues/169) | [ ] |
| [263](issues/263.md) | Niet ingelogd: onder een applicatie staat in het tabje gebruik de gemeenten | Niet-ingelogde gebruiker ziet gebruik-tab met gemeentenamen bij applicaties | [#263](https://github.com/VNG-Realisatie/Softwarecatalogus/issues/263) | [ ] |
| [280](issues/280.md) | Zoeken: sorteren gaat niet goed. | Sorteren op zoekresultaten werkt niet goed, filter Type ontbreekt | [#280](https://github.com/VNG-Realisatie/Softwarecatalogus/issues/280) | [ ] |
| [314](issues/314.md) | Wizard Koppeling publiceren vind zelf aangemaakte applicaties niet | Wizard koppeling publiceren vindt eigen applicaties niet | [#314](https://github.com/VNG-Realisatie/Softwarecatalogus/issues/314) | [ ] |
| [344](issues/344.md) | Zoeken: Geen resultaten bij het selecteren van het Gravenbeheercomponent. Niet ingelogd. | Geen zoekresultaten bij Gravenbeheercomponent door uitgezet schema-filter | [#344](https://github.com/VNG-Realisatie/Softwarecatalogus/issues/344) | [ ] |
| [345](issues/345.md) | Zoeken: toegevoegde dienst verschijnt niet in filters | Zoekfilters werkten niet voor nieuwe diensten, technische fout in applicatie | [#345](https://github.com/VNG-Realisatie/Softwarecatalogus/issues/345) | [ ] |
| [346](issues/346.md) | Zoeken: paginering werkt niet | Paginering toonde dezelfde resultaten door fout in performance refactor | [#346](https://github.com/VNG-Realisatie/Softwarecatalogus/issues/346) | [ ] |
| [347](issues/347.md) | Zoeken: Dienstkaartje toont array | Diensttypen werden als array getoond, grafische weergavefout | [#347](https://github.com/VNG-Realisatie/Softwarecatalogus/issues/347) | [ ] |
| [348](issues/348.md) | Het aantal standaarden komen niet overeen bij Centric Begraven tussen de huidige softwarecatalogus en de nieuwe | Aantal standaarden klopte niet door weergavefout in applicatie | [#348](https://github.com/VNG-Realisatie/Softwarecatalogus/issues/348) | [ ] |
| [351](issues/351.md) | Het laden van de tabbladen gaat ongelijk | Tabbladen laden ongelijk door configuratiefout op acceptatieomgeving | [#351](https://github.com/VNG-Realisatie/Softwarecatalogus/issues/351) | [ ] |
| [352](issues/352.md) | Mijn account - Contactpersoon bij applicatie publiceren is niet veranderd ondanks aanpassing zojuist. | Contactpersoon niet bijgewerkt na wijziging account door backend-logica oversight | [#352](https://github.com/VNG-Realisatie/Softwarecatalogus/issues/352) | [ ] |
| [354](issues/354.md) | Diensten - incomplete lijst applicaties | Zoeken naar applicaties in diensten-dropdown werkt onvoorspelbaar | [#354](https://github.com/VNG-Realisatie/Softwarecatalogus/issues/354) | [ ] |
| [364](issues/364.md) | Contactpersonen: e-mailadres is leeg | E-mailadres leeg bij contactpersonen ondanks opgave, zelfde oorzaak als #352 | [#364](https://github.com/VNG-Realisatie/Softwarecatalogus/issues/364) | [ ] |
| [365](issues/365.md) | Contactpersonen: error bij het opslaan van een contactpersoon | 400-error bij opslaan contactpersoon na bewerken | [#365](https://github.com/VNG-Realisatie/Softwarecatalogus/issues/365) | [ ] |
| [367](issues/367.md) | Contactpersonen: Tussenvoegsel wordt niet getoond | Tussenvoegsel niet getoond in kolom Naam bij contactpersonen | [#367](https://github.com/VNG-Realisatie/Softwarecatalogus/issues/367) | [ ] |
| [371](issues/371.md) | Applicatie: UUID onder compliance | UUID getoond onder Compliance-kolom, weergavefout door naamloos compliance-object | [#371](https://github.com/VNG-Realisatie/Softwarecatalogus/issues/371) | [ ] |
| [373](issues/373.md) | Applicatie: Gekoppelde diensten worden niet getoond | Gekoppelde diensten worden niet getoond in applicatie-overzicht | [#373](https://github.com/VNG-Realisatie/Softwarecatalogus/issues/373) | [ ] |
| [375](issues/375.md) | Applicaties: versie voor SaaS applicaties? | SaaS-applicatie krijgt geen default versie bij aanmaken | [#375](https://github.com/VNG-Realisatie/Softwarecatalogus/issues/375) | [ ] |
| [377](issues/377.md) | Applicaties: tabel toont diensten niet | Diensten worden niet getoond in kolom Diensten bij applicaties | [#377](https://github.com/VNG-Realisatie/Softwarecatalogus/issues/377) | [ ] |
| [378](issues/378.md) | Applicatie: Standaarden na wijzigen veranderd | Standaardwaarden wijzigen naar "Ondersteund" na opslaan zonder wijziging | [#378](https://github.com/VNG-Realisatie/Softwarecatalogus/issues/378) | [ ] |
| [379](issues/379.md) | Applicatie: verschillende manier van tonen compliancy | Compliancy tabel inconsistent weergegeven op verschillende pagina's | [#379](https://github.com/VNG-Realisatie/Softwarecatalogus/issues/379) | [ ] |
| [392](issues/392.md) | Back-end: geimporteerde gebruiker geeft error bij omzetten naar user | Geimporteerde gebruiker omzetten naar user geeft 400-error bij opslaan | [#392](https://github.com/VNG-Realisatie/Softwarecatalogus/issues/392) | [ ] |
| [393](issues/393.md) | Backend: fouten in voorzieningenregister | Backend fouten: exports en schema-opvragen werken niet correct | [#393](https://github.com/VNG-Realisatie/Softwarecatalogus/issues/393) | [ ] |
| [394](issues/394.md) | Contactpersonen van gemeenten publiekelijke zichtbaar | Contactpersonen van gemeenten onterecht publiek zichtbaar (RBAC-regressie) | [#394](https://github.com/VNG-Realisatie/Softwarecatalogus/issues/394) | [ ] |
| [399](issues/399.md) | Versies: een versie van een applicatie van een andere leverancier levert een foutmelding | Versie van applicatie andere leverancier geeft foutmelding (RBAC) | [#399](https://github.com/VNG-Realisatie/Softwarecatalogus/issues/399) | [ ] |
| [404](issues/404.md) | Regelmatig witte schermen | Regelmatig witte schermen in Edge browser | [#404](https://github.com/VNG-Realisatie/Softwarecatalogus/issues/404) | [ ] |
| [407](issues/407.md) | Toegevoegde standaarden verwijzen naar id-id-.... | Standaard-links bevatten dubbel "id-id-" in URL | [#407](https://github.com/VNG-Realisatie/Softwarecatalogus/issues/407) | [ ] |
| [430](issues/430.md) | Issue #430 | Kolom Compliancy toont applicatienamen in plaats van compliancy-waarden | [#430](https://github.com/VNG-Realisatie/Softwarecatalogus/issues/430) | [ ] |
| [431](issues/431.md) | Issue #431 | Veld "Tussenvoegsel" ontbreekt in aanmeldproces (regressie) | [#431](https://github.com/VNG-Realisatie/Softwarecatalogus/issues/431) | [ ] |
| [434](issues/434.md) | Issue #434 | Eerste contactpersoon van nieuwe leverancier niet beschikbaar voor applicatie | [#434](https://github.com/VNG-Realisatie/Softwarecatalogus/issues/434) | [ ] |
| [436](issues/436.md) | Issue #436 | Error bij ophalen applicatie-overzicht | [#436](https://github.com/VNG-Realisatie/Softwarecatalogus/issues/436) | [ ] |
| [437](issues/437.md) | Geimporteerde leverancier: nieuwe koppeling opslaan geeft foutmelding | Nieuwe koppeling opslaan geeft 400-error bij geimporteerde leverancier | [#437](https://github.com/VNG-Realisatie/Softwarecatalogus/issues/437) | [ ] |
| [438](issues/438.md) | Zoeken: verschillende vormgeving Diensten na filteren | Zoekresultaten diensten tonen inconsistent diensttype afhankelijk van filter | [#438](https://github.com/VNG-Realisatie/Softwarecatalogus/issues/438) | [ ] |
| [439](issues/439.md) | Error na het openen van Applicatie-overzicht | Error en PHP-warnings bij openen applicatie-overzicht nieuwe leverancier | [#439](https://github.com/VNG-Realisatie/Softwarecatalogus/issues/439) | [ ] |
| [442](issues/442.md) | Applicaties: opgevoerd document wijzigt van naam naar bewijs_<uniek getal> | Documentnaam wijzigt naar "bewijs_<nummer>" na uploaden bij standaard | [#442](https://github.com/VNG-Realisatie/Softwarecatalogus/issues/442) | [ ] |
| [451](issues/451.md) | Koppeling: UUID's zichtbaar bij standaardversies | Standaardversies tonen UUID's i.p.v. namen bij koppelingen | [#451](https://github.com/VNG-Realisatie/Softwarecatalogus/issues/451) | [ ] |
| [452](issues/452.md) | Applicaties overzicht: toont niet alle koppelingen | Applicatie-overzicht toont niet alle koppelingen in kolom | [#452](https://github.com/VNG-Realisatie/Softwarecatalogus/issues/452) | [ ] |
| [453](issues/453.md) | Zoeken: filters van slag met filter Type=Koppeling | Filters raken van slag bij Type=Koppeling; facets niet correct gescooped | [#453](https://github.com/VNG-Realisatie/Softwarecatalogus/issues/453) | [ ] |
| [454](issues/454.md) | Wizard koppelingen: Reeds bestaande koppelingen niet gevonden | Cross-supplier koppelingen niet zichtbaar in wizard | [#454](https://github.com/VNG-Realisatie/Softwarecatalogus/issues/454) | [ ] |
| [455](issues/455.md) | Tabblad koppelingen en contactpersonen publiekelijk niet getoond | Koppelingen en contactpersonen tabs niet zichtbaar voor publiek (RBAC) | [#455](https://github.com/VNG-Realisatie/Softwarecatalogus/issues/455) | [ ] |
| [456](issues/456.md) | Consistentie in werking van wizards | Wizard afsluiting inconsistent qua tekst, knoppen en flow | [#456](https://github.com/VNG-Realisatie/Softwarecatalogus/issues/456) | [ ] |
| [457](issues/457.md) | Koppeling: verwijderen geeft een 400-error | DELETE koppeling retourneert 400 bij zowel geimporteerde als nieuwe koppelingen | [#457](https://github.com/VNG-Realisatie/Softwarecatalogus/issues/457) | [ ] |

### Datakwaliteit (10)

| # | Issue | Analyse | GitHub | Checked |
|---|-------|---------|--------|--------|
| [23](issues/23.md) | Als aanbod- en gebruik-beheerder van de huidige Softwarecatalogus wil ik mijn reeds geregistreerde gegevens weer zien in de nieuwe Softwarecatalogus | Datamigratie vanuit oude softwarecatalogus; importkwaliteit en ontbrekende relaties | [#23](https://github.com/VNG-Realisatie/Softwarecatalogus/issues/23) | [ ] |
| [186](issues/186.md) | Koppelingen | Koppelingen verwijzen naar niet-bestaande applicaties uit importdata | [#186](https://github.com/VNG-Realisatie/Softwarecatalogus/issues/186) | [ ] |
| [312](issues/312.md) | Koppeling heeft verplicht een naam | Koppelingen zonder naam door ontbrekende naamvelden in importdata | [#312](https://github.com/VNG-Realisatie/Softwarecatalogus/issues/312) | [ ] |
| [349](issues/349.md) | Zoeken: UUID’s onder standaarden filter. | UUID's in standaardenfilter door verouderde verwijzingen in GEMMA-importdata | [#349](https://github.com/VNG-Realisatie/Softwarecatalogus/issues/349) | [ ] |
| [401](issues/401.md) | Koppeling - geïmporteerde koppelingen kaartjes zijn leeg | Geimporteerde koppelingen leeg door ontbrekende modules en standaardversies in importdata | [#401](https://github.com/VNG-Realisatie/Softwarecatalogus/issues/401) | [ ] |
| [432](issues/432.md) | Issue #432 | Naamgeving koppelingen inconsistent door importproblemen en ontbrekende velden | [#432](https://github.com/VNG-Realisatie/Softwarecatalogus/issues/432) | [ ] |
| [433](issues/433.md) | Issue #433 | Import koppelingen vult verkeerde velden, modules niet correct gekoppeld | [#433](https://github.com/VNG-Realisatie/Softwarecatalogus/issues/433) | [ ] |
| [435](issues/435.md) | Issue #435 | Niet alle geimporteerde applicaties zichtbaar, Centric mist 7 van 39 pakketten | [#435](https://github.com/VNG-Realisatie/Softwarecatalogus/issues/435) | [ ] |
| [440](issues/440.md) | Zoeken: Organisatietype teveel aan opties | Facet Organisatietype toont teveel opties uit geimporteerde data | [#440](https://github.com/VNG-Realisatie/Softwarecatalogus/issues/440) | [ ] |
| [441](issues/441.md) | Applicaties: mapping van de versies gaat niet goed bij geimporteerde applicaties | Versie-mapping geimporteerde applicaties toont geen status en startdatum | [#441](https://github.com/VNG-Realisatie/Softwarecatalogus/issues/441) | [ ] |

### Tekstueel (7)

| # | Issue | Analyse | GitHub | Checked |
|---|-------|---------|--------|--------|
| [248](issues/248.md) | Titels van de tabs in orde maken | Tabbladen missen titels, alleen iconen zonder tekst | [#248](https://github.com/VNG-Realisatie/Softwarecatalogus/issues/248) | [ ] |
| [274](issues/274.md) | Wizard dienst: tekst dient nog aangepast te worden naar nieuwe benamingen | Wizard dienst bevat oude benamingen, tekst moet aangepast worden | [#274](https://github.com/VNG-Realisatie/Softwarecatalogus/issues/274) | [ ] |
| [278](issues/278.md) | Filterteksten aanpassen | Filterteksten aanpassen: "Objecttype" moet anders, handleiding klopt niet | [#278](https://github.com/VNG-Realisatie/Softwarecatalogus/issues/278) | [ ] |
| [357](issues/357.md) | Diensten: Diensttype en Type wordt door elkaar gebruikt | Termen Diensttype en Type inconsistent gebruikt, configuratiefout in weergave | [#357](https://github.com/VNG-Realisatie/Softwarecatalogus/issues/357) | [ ] |
| [376](issues/376.md) | Applicaties: labels wizard en tabel zijn anders | Labels in beheertabel wijken af van wizard en powerpoint | [#376](https://github.com/VNG-Realisatie/Softwarecatalogus/issues/376) | [ ] |
| [381](issues/381.md) | Applicaties: non-compliant vervangen door niet ondersteund | Tekst "non-compliant" moet "niet ondersteund" worden | [#381](https://github.com/VNG-Realisatie/Softwarecatalogus/issues/381) | [ ] |
| [410](issues/410.md) | Dashboard schrijfwijze softwarecatalogus | Schrijfwijze "softwarecatalogus" inconsistent, moet zonder hoofdletter | [#410](https://github.com/VNG-Realisatie/Softwarecatalogus/issues/410) | [ ] |

### Wens (11)

| # | Issue | Analyse | GitHub | Checked |
|---|-------|---------|--------|--------|
| [6](issues/6.md) | Als aanbod-beheerder wil ik kunnen registreren welke standaarden door mijn pakket worden ondersteund en eventueel testrapporten beschikbaar stellen | Registreren standaarden bij pakketten en testrapporten beschikbaar stellen | [#6](https://github.com/VNG-Realisatie/Softwarecatalogus/issues/6) | [ ] |
| [85](issues/85.md) | (VNGR) Als ontwikkelaar wil ik via een veilige, publieke API toegang hebben tot aanbodinformatie uit de Softwarecatalogus ID-104 | Publieke API voor aanbodinformatie; documentatie en endpoints ontbreken nog | [#85](https://github.com/VNG-Realisatie/Softwarecatalogus/issues/85) | [ ] |
| [141](issues/141.md) | Als functioneel beheerder wil ik, naar aanleiding van gemeentelijke herindeling of een leveranciersovername, organisaties en al hun relaties (aanbod en/of gebruik) kunnen samenvoegen met een bestaande of nieuwe organisatie | Organisaties samenvoegen bij herindeling of overname, handleiding ontbreekt | [#141](https://github.com/VNG-Realisatie/Softwarecatalogus/issues/141) | [ ] |
| [148](issues/148.md) | (VNGR) De GEMMA-architectuur is opvraagbaar met een API | GEMMA-architectuur API verbeteren: meerdere modellen, minder id's, documentatie | [#148](https://github.com/VNG-Realisatie/Softwarecatalogus/issues/148) | [ ] |
| [155](issues/155.md) | (VNGR) Definities worden weergegeven via een interactieve optie binnen de softwarecatalogus | Glossary/begrippenlijst interactief weergeven binnen de softwarecatalogus | [#155](https://github.com/VNG-Realisatie/Softwarecatalogus/issues/155) | [ ] |
| [160](issues/160.md) | (VNGR) Performance plotten views tbv ID-77 | Performance verbeteren bij het plotten van grote ArchiMate views | [#160](https://github.com/VNG-Realisatie/Softwarecatalogus/issues/160) | [ ] |
| [306](issues/306.md) | Dienst: Overzicht controleren verbeteren | Overzicht controleren van dienst verbeteren, overbodige velden verwijderen | [#306](https://github.com/VNG-Realisatie/Softwarecatalogus/issues/306) | [ ] |
| [332](issues/332.md) | Voorpagina inrichten | Voorpagina inrichten met aanpasbare CMS-content | [#332](https://github.com/VNG-Realisatie/Softwarecatalogus/issues/332) | [ ] |
| [343](issues/343.md) | Zoeken: Filter 'Type koppeling' toevoegen. | Filter "Type koppeling" toevoegen aan zoekpagina | [#343](https://github.com/VNG-Realisatie/Softwarecatalogus/issues/343) | [ ] |
| [366](issues/366.md) | Contactpersonen: veld Rollen niet consistent | Veld Rollen verbergen bij leveranciers, tonen bij gemeenten met juiste waarden | [#366](https://github.com/VNG-Realisatie/Softwarecatalogus/issues/366) | [ ] |
| [370](issues/370.md) | Applicatie: teveel kolommen worden getoond | Technische kolommen verbergen in applicatie-overzicht | [#370](https://github.com/VNG-Realisatie/Softwarecatalogus/issues/370) | [ ] |

### Nog te bepalen (3)

| # | Issue | Analyse | GitHub | Checked |
|---|-------|---------|--------|--------|
| [340](issues/340.md) | Bevindingen op tussenoplevering Zoeken | Meerdere bevindingen zoeken: performance, sortering, filters, tekst | [#340](https://github.com/VNG-Realisatie/Softwarecatalogus/issues/340) | [ ] |
| [391](issues/391.md) | Testen met een gebruiker van een bestaande organisatie | Testen met geimporteerde gebruiker/organisatie nog niet volledig mogelijk | [#391](https://github.com/VNG-Realisatie/Softwarecatalogus/issues/391) | [ ] |
| [402](issues/402.md) | Verschil tussen Edge en Chrome bij laden applicaties | Verschil tussen Edge en Chrome niet reproduceerbaar | [#402](https://github.com/VNG-Realisatie/Softwarecatalogus/issues/402) | [ ] |

---

## Gesloten issues
*74 issues*

### Bug (47)

| # | Issue | Analyse | GitHub | Checked |
|---|-------|---------|--------|--------|
| [66](issues/66.md) | Als aanbod-beheerder wil ik aanvullende informatie over mijn organisatie kunnen delen, een overzicht van de diensten, en links naar ondersteunende pagina's (zoals het support-portaal en handleidingen) | Organisatieformulier bevat fouten: verkeerde navigatie, publiceren werkt niet | [#66](https://github.com/VNG-Realisatie/Softwarecatalogus/issues/66) | [ ] |
| [172](issues/172.md) | Testresultaten Jeroen de Ruig 5/9/2025 acceptatietest | Diverse bugs bij wizard: cache-problemen, velden niet gevuld, verkeerde data | [#172](https://github.com/VNG-Realisatie/Softwarecatalogus/issues/172) | [ ] |
| [185](issues/185.md) | Detailpagina's | Detailpagina's missen beschrijvingen, tabs en naam; inconsistente vormgeving | [#185](https://github.com/VNG-Realisatie/Softwarecatalogus/issues/185) | [ ] |
| [189](issues/189.md) | Organisatie | Organisatiebeheer: te veel velden, logo-upload fout, gebruikers niet zichtbaar | [#189](https://github.com/VNG-Realisatie/Softwarecatalogus/issues/189) | [ ] |
| [190](issues/190.md) | Applicatie en diensten | Applicatie aanmelden geeft lege pagina, licentievorm-selectie klopt niet | [#190](https://github.com/VNG-Realisatie/Softwarecatalogus/issues/190) | [ ] |
| [191](issues/191.md) | Back-End | Gebruiker toevoegen via backend geeft foutmelding | [#191](https://github.com/VNG-Realisatie/Softwarecatalogus/issues/191) | [ ] |
| [264](issues/264.md) | Tekst aanleveren: 404-melding bij Niet ingelogd: onder een applicatie staat in het tabje gebruik de gemeenten | 404-melding bij klikken op gebruik-tab als niet-ingelogde bezoeker | [#264](https://github.com/VNG-Realisatie/Softwarecatalogus/issues/264) | [ ] |
| [265](issues/265.md) | Nieuwe gebruiker heeft software-catalog-users | Nieuwe gebruiker krijgt verkeerde rol (software-catalog-users ipv aanbod-beheerder) | [#265](https://github.com/VNG-Realisatie/Softwarecatalogus/issues/265) | [ ] |
| [266](issues/266.md) | Na inloggen: Mijn account & persoonlijke gegevens leeg? | Mijn account en persoonlijke gegevens leeg na inloggen | [#266](https://github.com/VNG-Realisatie/Softwarecatalogus/issues/266) | [ ] |
| [273](issues/273.md) | Wizard Dienst: Een zojuist opgevoerde applicatie wordt niet direct gevonden | Zojuist opgevoerde applicatie niet direct vindbaar in wizard Dienst | [#273](https://github.com/VNG-Realisatie/Softwarecatalogus/issues/273) | [ ] |
| [283](issues/283.md) | Zoeken > Applicatie: Tab gebruik zichtbaar & versies | Tab gebruik onterecht zichtbaar, versies tonen "onbekend" | [#283](https://github.com/VNG-Realisatie/Softwarecatalogus/issues/283) | [ ] |
| [284](issues/284.md) | Applicatie: toont standaarden ipv standaardversies | Standaarden getoond ipv standaardversies bij applicatie | [#284](https://github.com/VNG-Realisatie/Softwarecatalogus/issues/284) | [ ] |
| [285](issues/285.md) | Zoeken: zojuist aangemaakte organisatie wordt niet gevonden | Zojuist aangemaakte organisatie niet vindbaar in zoekresultaten | [#285](https://github.com/VNG-Realisatie/Softwarecatalogus/issues/285) | [ ] |
| [286](issues/286.md) | Aanmelden organisatie: 500-error bij wachtwoord wijzigen | 500-error bij wachtwoord wijzigen van gebruiker | [#286](https://github.com/VNG-Realisatie/Softwarecatalogus/issues/286) | [ ] |
| [287](issues/287.md) | Leverancier: tab met grafiek toont overige applicaties die onder Applicatie horen | Grafiek-tab toonde verkeerde applicaties door limiet van 30 resultaten | [#287](https://github.com/VNG-Realisatie/Softwarecatalogus/issues/287) | [ ] |
| [288](issues/288.md) | Beheer: Wizards voor een leverancier | Wizards voor leverancier werden niet correct getoond | [#288](https://github.com/VNG-Realisatie/Softwarecatalogus/issues/288) | [ ] |
| [289](issues/289.md) | Beheer: tabelvoorkeuren worden niet bewaard | Tabelvoorkeuren werden niet bewaard na sessie | [#289](https://github.com/VNG-Realisatie/Softwarecatalogus/issues/289) | [ ] |
| [290](issues/290.md) | Beheer: Contactpersonen zoeken werkt niet | Zoeken op contactpersonen werkte niet in beheer | [#290](https://github.com/VNG-Realisatie/Softwarecatalogus/issues/290) | [ ] |
| [291](issues/291.md) | Beheer: Organisatie bewerken via contactpersoon | Organisatie bewerken via contactpersoon werkte niet correct | [#291](https://github.com/VNG-Realisatie/Softwarecatalogus/issues/291) | [ ] |
| [294](issues/294.md) | Applicatie publiceren: uitlijning rechthoek om op te voeren. | Uitlijning rechthoek gaat niet goed bij referentiecomponent selectie | [#294](https://github.com/VNG-Realisatie/Softwarecatalogus/issues/294) | [ ] |
| [295](issues/295.md) | Applicatie publiceren: Koppeling veld is smal | Koppeling veld te smal in wizard applicatie publiceren | [#295](https://github.com/VNG-Realisatie/Softwarecatalogus/issues/295) | [ ] |
| [297](issues/297.md) | Applicatie publiceren: koppeling applicatie B niet te selecteren | Applicatie B niet selecteerbaar bij koppeling publiceren | [#297](https://github.com/VNG-Realisatie/Softwarecatalogus/issues/297) | [ ] |
| [299](issues/299.md) | Beheer: Applicatiedetail Diensten tab kaartje zonder tekst | Diensten tab toonde kaartje zonder tekst | [#299](https://github.com/VNG-Realisatie/Softwarecatalogus/issues/299) | [ ] |
| [300](issues/300.md) | Beheer: overzicht applicaties teveel applicaties | Beheer toonde teveel applicaties door ontbrekende RBAC-filtering | [#300](https://github.com/VNG-Realisatie/Softwarecatalogus/issues/300) | [ ] |
| [301](issues/301.md) | Beheer: overzicht applicaties sorteren werkt niet | Sorteren in applicatie-overzicht werkte niet | [#301](https://github.com/VNG-Realisatie/Softwarecatalogus/issues/301) | [ ] |
| [302](issues/302.md) | Beheer: applicatie bewerken (ophalen van gegevens is traag) | Ophalen applicatiegegevens in beheer was te traag | [#302](https://github.com/VNG-Realisatie/Softwarecatalogus/issues/302) | [ ] |
| [303](issues/303.md) | Beheer: applicatie bewerken dienst toevoegen ontbreekt | Knop "dienst toevoegen" ontbrak in applicatie bewerken | [#303](https://github.com/VNG-Realisatie/Softwarecatalogus/issues/303) | [ ] |
| [304](issues/304.md) | Dienst bewerken: formulier teveel velden | Formulier dienst bewerken toonde teveel velden | [#304](https://github.com/VNG-Realisatie/Softwarecatalogus/issues/304) | [ ] |
| [305](issues/305.md) | Dienst: multiselect diensttypen + label aanpassen | Diensttypen multiselect ontbrak, label was onjuist | [#305](https://github.com/VNG-Realisatie/Softwarecatalogus/issues/305) | [ ] |
| [307](issues/307.md) | Diensten overzicht: meer dienst bij organisatie dan er horen | Diensten overzicht toonde meer diensten dan bij organisatie horen | [#307](https://github.com/VNG-Realisatie/Softwarecatalogus/issues/307) | [ ] |
| [330](issues/330.md) | /Beheer pagina's rerouten | Beheer pagina's automatisch gegenereerd en ongewenst vindbaar | [#330](https://github.com/VNG-Realisatie/Softwarecatalogus/issues/330) | [ ] |
| [337](issues/337.md) | Applicatie melden wizard | Wizard applicatie melden toonde verkeerde data en laadde traag | [#337](https://github.com/VNG-Realisatie/Softwarecatalogus/issues/337) | [ ] |
| [353](issues/353.md) | Mijn account – Je “functie” wordt niet aangepast na bewerken en opslaan. Cache legen werkt ook niet | Functie niet opgeslagen na bewerken, zelfde oorzaak als #352 | [#353](https://github.com/VNG-Realisatie/Softwarecatalogus/issues/353) | [ ] |
| [356](issues/356.md) | Diensten: geen tussenvoegsel bij namen | Tussenvoegsel ontbreekt in contactpersoon-weergave | [#356](https://github.com/VNG-Realisatie/Softwarecatalogus/issues/356) | [ ] |
| [368](issues/368.md) | Applicatie publiceren: Zonder een richting aan te geven is de koppeling op te voeren | Koppeling kon worden aangemaakt zonder verplicht veld Richting, validatie ontbrak | [#368](https://github.com/VNG-Realisatie/Softwarecatalogus/issues/368) | [ ] |
| [369](issues/369.md) | Applicatie publiceren: de aangemaakte koppeling is niet zichtbaar | Aangemaakte koppeling niet zichtbaar door RBAC-bug | [#369](https://github.com/VNG-Realisatie/Softwarecatalogus/issues/369) | [ ] |
| [372](issues/372.md) | Applicaties: Kolom Contactpersoon toont geen tussenvoegsel | Tussenvoegsel ontbreekt in kolom Contactpersoon bij applicaties | [#372](https://github.com/VNG-Realisatie/Softwarecatalogus/issues/372) | [ ] |
| [380](issues/380.md) | Applicatie: compliance aantallen komen niet overeen | Compliance aantallen wizard en beheerpagina kwamen niet overeen | [#380](https://github.com/VNG-Realisatie/Softwarecatalogus/issues/380) | [ ] |
| [382](issues/382.md) | Applicatie: compliancy link werkt niet | Compliancy link zonder protocol wordt als relatieve URL behandeld | [#382](https://github.com/VNG-Realisatie/Softwarecatalogus/issues/382) | [ ] |
| [383](issues/383.md) | Applicatie: selectie vakken werken niet | Selectievakjes in applicatieoverzicht werkten niet meer (regressie) | [#383](https://github.com/VNG-Realisatie/Softwarecatalogus/issues/383) | [ ] |
| [389](issues/389.md) | Applicaties – Uw applicatie publiceren: link verdwijnt na klikken (2) | Link-onderstreping verdwijnt tijdelijk na klikken (CSS-gedrag) | [#389](https://github.com/VNG-Realisatie/Softwarecatalogus/issues/389) | [ ] |
| [395](issues/395.md) | Menu linkerkant verdwijnt | Linkermenu verdwijnt na F5/pagina herladen | [#395](https://github.com/VNG-Realisatie/Softwarecatalogus/issues/395) | [ ] |
| [397](issues/397.md) | Pagina aanmaken via CMS | CMS pagina's bewerken werkt niet meer (regressie door performance-wijziging) | [#397](https://github.com/VNG-Realisatie/Softwarecatalogus/issues/397) | [ ] |
| [400](issues/400.md) | Koppeling - Opslaan van een koppeling geeft een foutmelding | Opslaan van een koppeling geeft foutmelding | [#400](https://github.com/VNG-Realisatie/Softwarecatalogus/issues/400) | [ ] |
| [408](issues/408.md) | Tabblad beschrijving bij Dienst | Onterecht tabblad "Beschrijving" met getal bij nieuw aangemaakte dienst | [#408](https://github.com/VNG-Realisatie/Softwarecatalogus/issues/408) | [ ] |
| [409](issues/409.md) | Footer anders: inlog of uitgelogd | Footer verschilt tussen ingelogd en uitgelogd, links wijzen naar andere pagina's | [#409](https://github.com/VNG-Realisatie/Softwarecatalogus/issues/409) | [ ] |
| [225](issues/225.md) | Testresultaten 29-10-2025 | Ingelogde gebruiker ziet eigen applicaties niet, publiceren werkt niet correct | [#225](https://github.com/VNG-Realisatie/Softwarecatalogus/issues/225) | [ ] |

### Datakwaliteit (3)

| # | Issue | Analyse | GitHub | Checked |
|---|-------|---------|--------|--------|
| [3](issues/3.md) | Als aanbod-raadpleger wil ik pakketten kunnen zoeken en filteren op ondersteuning van verplichte en aanbevolen standaarden | UUID's in standaarden-filter door niet-bestaande standaarden in importdata | [#3](https://github.com/VNG-Realisatie/Softwarecatalogus/issues/3) | [ ] |
| [292](issues/292.md) | Applicatie publiceren: lijst met onbekende contactpersonen | Onbekende contactpersonen in wizard door geimporteerde data | [#292](https://github.com/VNG-Realisatie/Softwarecatalogus/issues/292) | [ ] |
| [315](issues/315.md) | Hoge prioriteit: Zoekpagina toont deel van gemeentelijk applicatielandschap | Zoekpagina toont gemeenten als leverancier door fout in importdata | [#315](https://github.com/VNG-Realisatie/Softwarecatalogus/issues/315) | [ ] |

### Tekstueel (13)

| # | Issue | Analyse | GitHub | Checked |
|---|-------|---------|--------|--------|
| [254](issues/254.md) | Beheer pagina's dashboard: wizard benamingen | Wizard benamingen op dashboard kloppen niet | [#254](https://github.com/VNG-Realisatie/Softwarecatalogus/issues/254) | [ ] |
| [267](issues/267.md) | Naam is softwarecatalogus i.p.v. VNG softwarecatalogus | Naam is "softwarecatalogus" in plaats van "VNG softwarecatalogus" | [#267](https://github.com/VNG-Realisatie/Softwarecatalogus/issues/267) | [ ] |
| [277](issues/277.md) | Beheer: Applicaties overzicht teksten aanpassen | Beheer applicaties overzicht bevat verkeerde teksten | [#277](https://github.com/VNG-Realisatie/Softwarecatalogus/issues/277) | [ ] |
| [359](issues/359.md) | Diensten wizard: Uw dienst publiceren - tekst aanpassen | Informatieteksten in wizard komen niet overeen met afgestemde powerpoint | [#359](https://github.com/VNG-Realisatie/Softwarecatalogus/issues/359) | [ ] |
| [360](issues/360.md) | Diensten wizard – Uw dienst publiceren: Meerdere i komen niet overeen met ppt | Informatieteksten stap 2 wizard komen niet overeen met powerpoint | [#360](https://github.com/VNG-Realisatie/Softwarecatalogus/issues/360) | [ ] |
| [361](issues/361.md) | Diensten wizard – Uw dienst publiceren: inconsistentie in labels | Labels controleformulier wijken af van invoervelden in wizard | [#361](https://github.com/VNG-Realisatie/Softwarecatalogus/issues/361) | [ ] |
| [362](issues/362.md) | Diensten wizard – Uw dienst publiceren: onlogische tekst bovenaan de aanmeld-stap | Onlogische tekst "Uw diensten publiceren" na succesvolle aanmelding | [#362](https://github.com/VNG-Realisatie/Softwarecatalogus/issues/362) | [ ] |
| [363](issues/363.md) | Diensten wizard – Uw dienst publiceren: catalogus i.p.v. softwarecatalogus | "Catalogus" moet "softwarecatalogus" zijn in bevestigingstekst | [#363](https://github.com/VNG-Realisatie/Softwarecatalogus/issues/363) | [ ] |
| [374](issues/374.md) | Applicaties: Standaarden, Standaarden GEMMA en Standaardversies? | Kolommen Standaarden/Standaarden GEMMA/Standaardversies verwarrend en dubbel | [#374](https://github.com/VNG-Realisatie/Softwarecatalogus/issues/374) | [ ] |
| [386](issues/386.md) | Applicaties – Uw applicatie publiceren: andere labels | Labels in wizard komen niet overeen met ontwerp (ppt) | [#386](https://github.com/VNG-Realisatie/Softwarecatalogus/issues/386) | [ ] |
| [387](issues/387.md) | Applicaties – Uw applicatie publiceren: i niet aanwezig | Informatie-iconen ontbreken bij labels in versie-wizard | [#387](https://github.com/VNG-Realisatie/Softwarecatalogus/issues/387) | [ ] |
| [390](issues/390.md) | Applicaties – Uw applicatie publiceren: labels komen niet overeen | Labels wizard en controleformulier komen niet overeen | [#390](https://github.com/VNG-Realisatie/Softwarecatalogus/issues/390) | [ ] |
| [403](issues/403.md) | Tekst verwijderen aanpassen | Verwijdertekst per objecttype moet aangepast worden | [#403](https://github.com/VNG-Realisatie/Softwarecatalogus/issues/403) | [ ] |

### Wens (10)

| # | Issue | Analyse | GitHub | Checked |
|---|-------|---------|--------|--------|
| [4](issues/4.md) | Als aanbod-beheerder wil ik mijn pakketten eenmalig registreren en classificeren op basis van de referentiearchitecturen van de voor mij relevante sector(en) | Classificeren op meerdere sectorale referentiearchitecturen, buiten scope | [#4](https://github.com/VNG-Realisatie/Softwarecatalogus/issues/4) | [ ] |
| [298](issues/298.md) | Applicatie publiceren: Buitengemeentelijke voorzieningen herkenbaarder maken tussen applicaties | Buitengemeentelijke voorzieningen visueel onderscheiden van applicaties | [#298](https://github.com/VNG-Realisatie/Softwarecatalogus/issues/298) | [ ] |
| [308](issues/308.md) | Diensten overzicht: default kolommen + kolom verwijderen | Default kolommen aanpassen en kolom koppelingen verbergen bij diensten | [#308](https://github.com/VNG-Realisatie/Softwarecatalogus/issues/308) | [ ] |
| [350](issues/350.md) | De link achter de gebruikersnaam laten verwijzen naar Mij account | Link achter gebruikersnaam naar Mijn Account laten verwijzen | [#350](https://github.com/VNG-Realisatie/Softwarecatalogus/issues/350) | [ ] |
| [355](issues/355.md) | Diensten: Export geeft allerlei UUID's | Export toont UUID's, wens voor leesbare variant naast technische export | [#355](https://github.com/VNG-Realisatie/Softwarecatalogus/issues/355) | [ ] |
| [358](issues/358.md) | Diensten:  De status "Concept" wordt nog op verschillende plekken getoond | Status "Concept" verwijderen, niet in PvE maar wel in datamodel | [#358](https://github.com/VNG-Realisatie/Softwarecatalogus/issues/358) | [ ] |
| [384](issues/384.md) | Applicaties: eenduidige manier van bewerken | Overal wizards gebruiken voor bewerken, niet meerdere manieren | [#384](https://github.com/VNG-Realisatie/Softwarecatalogus/issues/384) | [ ] |
| [385](issues/385.md) | Applicatie: Geen huidige versie in gebruik | "Huidige versie" verwijderen uit grijze blok, staat al in tabblad Versies | [#385](https://github.com/VNG-Realisatie/Softwarecatalogus/issues/385) | [ ] |
| [396](issues/396.md) | Verouderde NextCloud versie | Nextcloud versie upgraden naar ondersteunde versie | [#396](https://github.com/VNG-Realisatie/Softwarecatalogus/issues/396) | [ ] |
| [406](issues/406.md) | SiteImprove verwijderen | SiteImprove tracking script verwijderen uit template | [#406](https://github.com/VNG-Realisatie/Softwarecatalogus/issues/406) | [ ] |

### Nog te bepalen (1)

| # | Issue | Analyse | GitHub | Checked |
|---|-------|---------|--------|--------|
| [334](issues/334.md) | Zoeken | Meerdere zoek-bevindingen: UUIDs, filters, tekstueel, RBAC-gevolgen | [#334](https://github.com/VNG-Realisatie/Softwarecatalogus/issues/334) | [ ] |

