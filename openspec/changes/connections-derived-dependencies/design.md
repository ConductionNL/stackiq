# Design: connections-derived-dependencies

Read at development `49e65cb4`.

## Context

A connection lives between two applications (`connection.moduleA`, `moduleB`, `lib/Settings/softwarecatalogus_register.json:3689`, `:3705`). What an organisation runs is a usage (`usage` schema, `register.json:2656`) with `module`, `moduleVersion`, `koppelingen` (the connections this usage uses) and `plannedReplacement` (a successor application). `landscape-usage-registration` gives usages their own index and detail pages; this change adds a panel to that detail page.

## D1. A suggestion service, computed on demand

New `lib/Service/ConnectionSuggestionService.php` (ADR-008: controller, service, OpenRegister's ObjectService as the mapper). `suggestFor(string $usageUuid): array` does:

1. Load the usage and its organisation (`consumer`).
2. Load the organisation's other usages and collect their applications: the organisation's landscape.
3. Load the connections where `moduleA` or `moduleB` is the usage's application, that the caller may read (OpenRegister RBAC stays on).
4. Keep the connections whose other end is in the landscape, or is a national provision, and that are not already in `usage.koppelingen` and not dismissed for this usage.
5. Return each with a reason: `shared-landscape`.

Carry-over (`land-version-carry-connections`) adds a second source in the same method:

- If another usage of the organisation has the same `module` and an older `moduleVersion`, or has this usage's `module` as its `plannedReplacement`, its `koppelingen` are offered with reason `carry-over`.
- For a successor, each offered connection is a draft: same other end, same type and direction, application A or B set to the successor, status `in development`, and `longDescription` naming the connection it came from.

Rejected: storing suggestions as objects. They go stale the moment someone adds a usage; computing them on each open is cheap because every query is scoped to one organisation.

## D2. Endpoints

In `appinfo/routes.php`, next to the offer routes (:202-204):

| verb | url | controller method |
|---|---|---|
| GET | `/api/usages/{uuid}/connection-suggestions` | `ConnectionSuggestionController::index` |
| POST | `/api/usages/{uuid}/connection-suggestions/accept` | `::accept` (body: suggestion ids) |
| POST | `/api/usages/{uuid}/connection-suggestions/dismiss` | `::dismiss` (body: suggestion ids) |

All `#[NoAdminRequired]` with a per-object guard: the caller must be allowed to update the usage (the usage's `consumer` or a participant is the active organisation), the same rule `AanbodService` applies before it changes a usage. Accept appends existing connections to `usage.koppelingen`, and for a draft creates the connection first. Dismiss records the connection id in a new usage property `dismissedConnectionSuggestions` (array of uuid, `hideOnForm: true`).

Rejected: reusing the offer endpoints (`/api/aanbod/{uuid}/accept`). An offer is an object a supplier made; a suggestion is derived and has no owner to accept from.

## D3. The panel

A custom component `ConnectionSuggestionsPanel` (`src/components/connections/ConnectionSuggestionsPanel.vue`), registered in `src/customComponents.js`, placed on the usage detail page as a body widget. It lists suggestions with the other application, type, direction and reason, and offers Accept, Dismiss and Accept all. Empty state: "No suggestions. Every known connection of this application is already in your usage."

## Declarative versus imperative

Imperative: deriving suggestions joins three queries and a rule, which no `x-openregister-*` block describes (ADR-031 allows code where the dialect has no construct). The accepted result is plain data on the usage.

## Seed data

The usage schema gains `dismissedConnectionSuggestions` (array, hidden on the form), added in `lib/Settings/register.d/derived-connections.json`. The demo register gets two usages of one organisation whose applications share a demo connection, so the panel shows one suggestion.

## Risks

- The per-object guard must match the usage's update rule exactly; `hydra-gate-no-admin-idor` checks each method has one.
- Draft connections created for a successor are real objects; a dismissed draft is never created, only an accepted one.
