# Future-state scenarios

An information manager compares the application landscape of today with the landscape
the plan leads to on a later date. They can also write a scenario, a set of changes on top
of the plan, and compare that with today. Once a scenario is adopted, they apply it, and the
changes become part of the landscape.

## Compare today with the plan

The Portfolio roadmap has a **Compare with the plan** button. Choose an organisation on the
roadmap first. The button opens the comparison page (`/landscape-comparison`) for that
organisation. Pick a date there.

Stackiq works out both landscapes from the dates on the applications in use:

- An application in use is in the landscape on a date when its lifecycle phase on that date is
  In production or To be phased out.
- A planned replacement takes effect on its replacement date. The application in use leaves the
  landscape, and the successor takes its place for the same reference components.
- An application in use with no dates keeps its current status. When that status is In
  production or To be phased out, it is in today's landscape and in every later one.
- An application in use with no dates and no status is in neither landscape. The page gives the
  number of these entries, so you can see why an application is missing.

The page shows:

- the applications of today next to those on the chosen date
- the applications that change. Each is marked Added, Removed or Replaced by, with the source
  (Plan or Scenario) and the date.
- the reference components whose coverage changes. Each reads gap closed, gap opened, overlap
  resolved or overlap created.

Every state is written out in words, so the page does not rely on colour.

## Write a scenario

Under **Architecture**, **Scenarios** lists the scenarios, with quick filters for draft,
proposed and adopted ones. A scenario has:

- a name and a description
- the organisation whose landscape it changes
- a target date

On the scenario page, **Add change** adds a change. A change has one of three actions:

| Action | What it needs | What it does |
|---|---|---|
| add | an application and the reference components it will be used for | puts the application in the landscape |
| phase out | an application in use | takes it out of the landscape |
| replace | an application in use and the application that replaces it | puts the successor in its place |

A change takes effect on its effective date. Leave the effective date empty to use the target
date.

The scenario page compares today with the scenario landscape on the target date. That is the
plan on that date with the scenario's changes applied in order of effective date. When a
scenario change and the plan touch the same application in use, the scenario wins, and the
difference names both sources.

## The lifecycle of a scenario

A scenario starts as draft. The actions on its page move it on:

| Action | From | To |
|---|---|---|
| Propose | draft | proposed |
| Adopt | proposed | adopted |
| Reject | proposed | rejected |
| Rework | proposed or rejected | draft |

The History tab shows each step.

## Apply an adopted scenario

An adopted scenario that has not been applied offers **Apply to landscape**. It writes each change
to the register as the signed-in user, so the usual permissions apply:

- **add** creates an application in use for the scenario's organisation. It has status Planned,
  and its production start is the effective date.
- **phase out** sets the phased out date of the application in use to the effective date.
- **replace** sets the planned replacement and the planned replacement date of the application
  in use.

Each applied change records the application in use it wrote. When an apply stops because a write
fails, the notice names the change it stopped at. The changes before it are already written. Apply
again to continue: a change that was already applied is skipped. The scenario records when it was
applied only once every change is in. After that, the button is gone.

The Portfolio roadmap and the plan comparison read the same fields, so they show the applied
changes straight away.

## The API

`GET /api/landscape-comparison?organisation=<uuid>&date=<Y-m-d>` returns the comparison of today
with the plan on the date. Add `&scenario=<uuid>` to compare a scenario instead. Its target date
is then used. The endpoint refuses a user who may not read the organisation's reports before it
reads anything. The answer holds:

- both landscapes (`landscapes.today`, `landscapes.future`)
- the application differences (`applications`)
- the coverage differences (`coverage`)
- the number of entries that are not dated (`notDated`)
