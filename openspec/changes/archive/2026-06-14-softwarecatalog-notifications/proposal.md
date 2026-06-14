---
kind: config
depends_on: []
---

# softwarecatalog — schema-declared notifications

## Why

Softwarecatalog is the GEMMA software catalogue used by municipal architects,
VNG, and suppliers. The headline notification needs are: **a reported
vulnerability** (urgent — suppliers + catalogue admins), **contract expiry**
(scheduled reminder), **a new module version**, and **a new review**. None of
softwarecatalog's schemas currently declare `x-openregister-notifications`, so the
OpenRegister notification engine has nothing to dispatch on. This change declares
schema-level notification rules for those four events.

All rules use trigger types that work **today** (`created`, `scheduled`). No rule
depends on the unshipped `updated`-field-change engine condition, so this change
carries **no** `depends_on`.

> The register file is `lib/Settings/softwarecatalogus_register.json` (note the
> `-us` suffix — it does **not** match the app slug).

## What Changes

Add a top-level `x-openregister-notifications` key to the relevant schemas in
`lib/Settings/softwarecatalogus_register.json`, using the verified engine dialect.

### `kwetsbaarheid` (vulnerability) — reported (created) — URGENT

`kwetsbaarheid` carries `naam`, `cveCode`, `cvssScore`, and `modules` (array of
module references). There is **no** direct supplier/owner field on the schema
(see Caveats), so recipients use a catalogue-admin `groups` recipient plus the
record's manage-ACL.

```jsonc
"x-openregister-notifications": {
  "vulnerability-reported": {
    "trigger": {"type": "created"},
    "enabled": true,
    "channels": ["nc-notification", "email"],
    "recipients": [
      {"kind": "groups", "groups": ["softwarecatalog-admins"]},
      {"kind": "object-acl", "permission": "manage"}
    ],
    "subject": {
      "nl": "Kwetsbaarheid gemeld: {{naam}} ({{cveCode}}, CVSS {{cvssScore}})",
      "en": "Vulnerability reported: {{naam}} ({{cveCode}}, CVSS {{cvssScore}})"
    }
  }
}
```

### `contract` — expiry reminder (scheduled)

`contract` carries `eindDatum` (end date), `status` (enum: `Actief` / `Verlopen` /
`In onderhandeling`), and nested `contactpersoonAanbieder` /
`contactpersoonGebruiker` objects. A `scheduled` rule periodically checks active
contracts whose `eindDatum` is approaching.

```jsonc
"x-openregister-notifications": {
  "contract-expiry": {
    "trigger": {"type": "scheduled", "intervalSec": 86400, "filter": {"status": {"op": "equals", "value": "Actief"}}},
    "enabled": false,
    "channels": ["nc-notification", "email"],
    "recipients": [
      {"kind": "groups", "groups": ["softwarecatalog-admins"]},
      {"kind": "object-acl", "permission": "manage"}
    ],
    "subject": {
      "nl": "Contract verloopt: {{contractNummer}} (einddatum {{eindDatum}})",
      "en": "Contract expiring: {{contractNummer}} (end date {{eindDatum}})"
    }
  }
}
```

Ships **disabled by default** because the `scheduled` filter needs a
date-window comparison on `eindDatum` (e.g. "within 30 days") whose engine
support must be confirmed; see Caveats.

### `moduleVersie` (module version) — published (created)

`moduleVersie` carries `module`, `versie`, `status`, and `geregistreerdDoor`. A
new version row is a `created` event.

```jsonc
"x-openregister-notifications": {
  "module-version-published": {
    "trigger": {"type": "created"},
    "enabled": true,
    "channels": ["nc-notification"],
    "recipients": [
      {"kind": "object-acl", "permission": "manage"},
      {"kind": "groups", "groups": ["softwarecatalog-admins"]}
    ],
    "subject": {
      "nl": "Nieuwe moduleversie: {{versie}}",
      "en": "New module version: {{versie}}"
    }
  }
}
```

### `beoordeeling` (review) — submitted (created)

`beoordeeling` carries `naam`, `waardering` (rating), and `modules`. A new review
is a `created` event.

```jsonc
"x-openregister-notifications": {
  "review-submitted": {
    "trigger": {"type": "created"},
    "enabled": true,
    "channels": ["nc-notification"],
    "recipients": [
      {"kind": "object-acl", "permission": "manage"},
      {"kind": "groups", "groups": ["softwarecatalog-admins"]}
    ],
    "subject": {
      "nl": "Nieuwe beoordeling: {{naam}} (waardering {{waardering}})",
      "en": "New review: {{naam}} (rating {{waardering}})"
    }
  }
}
```

## Capabilities

### New Capabilities
- `softwarecatalog-notifications`: declarative schema-level notification rules on
  `kwetsbaarheid`, `contract`, `moduleVersie`, and `beoordeeling`, consumed by the
  OpenRegister notification engine, surfacing reported vulnerabilities (urgent),
  approaching contract expiry, newly published module versions, and submitted
  reviews to catalogue admins and record managers.

## Impact

- **File:** `lib/Settings/softwarecatalogus_register.json` — adds
  `x-openregister-notifications` blocks to `kwetsbaarheid`, `contract`,
  `moduleVersie`, `beoordeeling`.
- The OpenRegister notification engine (shipped in OR change
  `notification-schema-rules-and-userconfig-prefs`) consumes these blocks at
  runtime. No PHP/Vue changes in softwarecatalog.
- Users **opt out** per `(schema, rule)` via override-only user-config prefs;
  schema `enabled` is only the default.
- `contract-expiry` ships **disabled by default** — see Caveats.

## Caveats

- **Recipient fields are inferred, not confirmed (plan flag).** `kwetsbaarheid`
  has **no** supplier/owner uid field — only `modules` (module references). To
  reach the actual supplier you would have to traverse `kwetsbaarheid → module →
  aanbieder (organisatie) → contactpersoon`, which the engine's `field`/`relation`
  resolver cannot do in one hop today. So vulnerability + module-version + review
  rules fall back to an `object-acl` `manage` recipient plus a `groups` recipient
  pointed at a `softwarecatalog-admins` group. **That group must exist** (or be
  remapped to a real NC group) for the `groups` recipients to resolve. The plan's
  "urgent → suppliers + admins" reaches **admins** reliably; **supplier** delivery
  needs either a relation-traversal recipient resolver or a structured supplier-uid
  field on the schemas.
- **`contract` contactpersoon fields are nested objects, not uid strings.**
  `contactpersoonAanbieder` / `contactpersoonGebruiker` are nested `object`
  properties, so a `field:` recipient will not resolve an NC uid from them. The
  contract rule therefore uses `object-acl` + `groups` instead of those fields.
- **`scheduled` date-window filtering** ("eindDatum within 30 days") engine
  support must be confirmed before `contract-expiry` is enabled; it ships disabled.
- **No named lifecycle/`transition` actions and no `updated`-field-change support.**
  A "vulnerability status changed" or "contract status → Verlopen" rule is not
  expressible today; it would need transition actions on the schema or the
  unshipped `notification-updated-field-change-condition` engine change. The
  vulnerability rule therefore fires on **creation** of the kwetsbaarheid record
  (the report event), which matches the plan's "vulnerability reported" headline.
- **External-recipient email** (suppliers held as email strings / external
  contacts) is out of scope — the `field` resolver resolves NC uids only. The
  `contactpersoon` schema's `e-mailadres` is an email string, not a uid.

See `hydra/openspec/fleet-notification-plan.md` (softwarecatalog row + cross-cutting
engine-gap section) for the full analysis.
