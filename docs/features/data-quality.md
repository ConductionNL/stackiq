# Data quality

Every application and every usage of an application gets a data quality score. The
score shows how complete and how current an entry is, so an information manager knows
what to fix first.

## The rules

OpenRegister scores an entry each time it is saved, from rules the schema declares in
`x-openregister-quality`. Stackiq declares them in
`lib/Settings/register.d/data-quality.json`. Each rule has a weight.

| Kind | What it checks |
|---|---|
| required | The field is filled in. |
| format | The value has the right shape, such as a URL. |
| freshness | How recently someone confirmed the entry is current. Its weight halves every 365 days after `lastConfirmedAt`. |

An application is scored on its name, supplier, licence and long description, and on
freshness. A usage is scored on its application, version, status, business owner,
technical owner, the reference components it is used for, and freshness.

The score runs from 0 to 1. From 0.8 the status is good, from 0.5 it is fair, below
that it is poor. OpenRegister writes the score to `qualityScore` and the status to
`qualityStatus`. Neither field is on the edit form, because the platform overwrites it
on every save.

## Where you see it

- The Applications list and the Applications in use list have a Data quality column.
- The application page and the application in use page have a Data quality card with
  the status, the score and when the entry was last confirmed.
- Reports and compliance has a Data quality page per organisation. It shows the
  register score, the average completeness, how many entries were never confirmed or
  not confirmed within a year, how many entries meet each rule, and the least complete
  entries first with what is missing.

## Confirm this entry is current

The Actions menu of the application page and of the application in use page has
Confirm this entry is current. It sets `lastConfirmedAt` to now and changes nothing
else. The save rescores the entry, so its freshness goes back to full.

Only a user who may change the entry can confirm it. Anyone else gets a refusal and
nothing is stored. The action calls `POST /api/data-quality/confirm/{module|usage}/{id}`.

## Entries from before the rules

An entry saved before the rules existed has no score. The repair step
`RescoreDataQuality` runs after the register import on upgrade. It saves every module
and usage without a score once, so OpenRegister scores it, and logs how many it scored.
It leaves scored entries alone. When a save fails, the next upgrade tries again.
