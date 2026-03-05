# Issue Responses

Prepared responses for VNG-Realisatie/Softwarecatalogus issues.

---

## #312 — Koppeling heeft verplicht een naam

We hebben de suggestie van @markbacker geïmplementeerd: het naamveld is verwijderd uit de koppeling wizard en de naam wordt nu **altijd automatisch gegenereerd** op basis van:

`[Applicatie A naam] [richting pijl] [Applicatie B naam / buitengemeentelijke voorziening naam]`

Voorbeelden:
- `Key2Financiën → Burgerzaken`
- `Key2Betalen ↔ iNlichtingen`
- `Centric Leefomgeving ← Basisregistratie`

De richting-pijlen zijn: `→` (A naar B), `←` (B naar A), `↔` (bi-directioneel).

Dit is doorgevoerd in zowel de "Koppeling publiceren" wizard (aanbod-beheerder) als de "Koppeling toevoegen" wizard (gebruik-beheerder). De naam is niet meer aanpasbaar door de gebruiker.

Daarnaast zijn URL-bugs gefixed die ervoor zorgden dat module-namen niet correct konden worden opgehaald (wat leidde tot UUID's in de naam in plaats van leesbare namen).

**Commit:** `09cdbba3` on `hotfix/other-issues` (tilburg-woo-ui)

---

## #432 — Koppeling: Naamgeving van koppeling niet consistent

De inconsistente naamgeving is aangepakt als onderdeel van #312. De koppeling-naam wordt nu altijd automatisch gegenereerd als `[App A] [richting] [App B]` en is niet meer handmatig aanpasbaar.

Daarnaast zijn API URL-bugs gefixed die ervoor zorgden dat modulenamen niet correct werden opgehaald, wat leidde tot UUID's of "undefined" in de weergave.

Voor het import-probleem (verkeerde velden) verwijs ik naar #433.

**Commit:** `09cdbba3` on `hotfix/other-issues` (tilburg-woo-ui)

---

## #342 — Referentiecomponenten kaartjes tonen te veel items

Gebruik-kaartjes tonen nu maximaal 2 referentiecomponenten met een "+N meer" indicator voor de rest. Dit is consistent met het patroon op organisatie/applicatie kaartjes.

**Commit:** `09cdbba3` on `hotfix/other-issues` (tilburg-woo-ui)
