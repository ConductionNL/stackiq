# Retest: Critical Fixes -- Bezoeker (Anonymous)

**Date:** 2026-03-10
**Environment:** Frontend: http://localhost:3000, Backend: http://localhost:8080

## #447: Concept organisations publicly visible
**Previous Status:** FAIL
**Current Status:** PASS
**Evidence:**

1. **API verification:** `curl 'http://localhost:8080/index.php/apps/opencatalogi/api/softwarecatalogus?_schema=organisatie&_limit=5&status=Concept'` returns `total: 0` -- no Concept organisations are accessible via the public API.

2. **Facet verification:** The Status facet on the public search page (`/zoeken?_schema=organisatie`) shows only **Actief (977)**. No "Concept" status value appears in the facet sidebar at all.

3. **Full status facet from API:** Querying all status values returns: In gebruik (8168), Einde ondersteuning (2120), in gebruik (1146), Actief (977), In ontwikkeling (83), Teruggetrokken (70). "Concept" is completely absent -- the RBAC rule requiring `status: Actief` for public read access on the organisatie schema is working correctly.

4. **Visual confirmation:** All results shown on the Organisaties page are legitimate organisations with no Concept-status entries visible. Screenshot: `screenshot-447-org-search.png`

## #453: Facet counts don't re-scope when filters are active
**Previous Status:** FAIL
**Current Status:** PASS
**Evidence:**

1. **Baseline (no filter):** The unfiltered search at `/zoeken?_schema=module` shows **2,056 resultaten** with facets:
   - Type: Applicatie (1,063), Dienst (16), Organisatie (977)
   - Geregistreerd door: Gemeente (345), Leverancier (1,403), Samenwerking (91)
   - Organisatietype: Gemeente (446), Leverancier (340), Samenwerking (191)

2. **After filtering by `geregistreerdDoor=Gemeente`:** The result count drops to **345 resultaten** and ALL facet counts re-scope correctly:
   - Type: now shows only **Organisatie (345)** -- Applicatie and Dienst removed (0 matches)
   - Status: now shows **Actief (345)** -- down from 977
   - Geregistreerd door: now shows only **Gemeente (345)** (the active filter)
   - Organisatietype: now shows only **Gemeente (345)** -- down from 3 values
   - Facets with 0 matching values (Samenwerkingstype, Leverancier, Licentievorm, Referentiecomponenten, Standaardversies, Diensttype) are hidden entirely.

3. **Consistency check:** The total (345) matches across all visible facet counts, confirming the "intelligent fallback" that was stripping object field filters has been correctly disabled. Screenshots: `screenshot-453-org-type-filter.png` and `screenshot-453-filtered-gemeente.png`
