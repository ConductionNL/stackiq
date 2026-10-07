# Realtime Updates UI (leaf adoption)

## ADDED Requirements

### Requirement: Store-rendered views MUST subscribe to live updates for their scope

Views that render from Softwarecatalog's `createObjectStore`-based object store MUST subscribe to
live updates for the data they display: collection-scoped views subscribe to
`or-collection-{register-slug}-{schema-slug}` per rendered object type, object-scoped views
subscribe to `or-object-{uuid}`. Subscriptions MUST be re-scoped when the viewed scope
changes and released when the view is destroyed. Events are refetch HINTS only: views MUST
refetch through their existing fetch paths and MUST NOT patch rendered state from an event
payload.

@e2e exclude Requires a second concurrent authenticated session plus a notify_push (or poll-tick) round-trip; covered by the shared library's transport tests and manual two-browser verification.

#### Scenario: Module view refreshes when a rendered collection changes elsewhere

- **GIVEN** a module view (vulnerabilities, compliance matrix, license posture, lifecycle
  roadmap, or organisaties index) is open
- **WHEN** another user creates, updates or deletes an object of a type the view renders
- **THEN** the view receives the `or-collection-{register}-{schema}` hint, the plugin
  re-runs `fetchCollection` with the last-used params, and the view's `getCollection`-backed
  computeds re-render the fresh data without a manual refresh

#### Scenario: Subscription waits for lazy type registration

- **GIVEN** a module view mounts before its `loadData()` has registered its object types
- **WHEN** the registration lands in the store's `objectTypeRegistry`
- **THEN** the reactive `enabled` gate flips and the subscription attaches — no subscribe
  call is attempted against an unregistered type

#### Scenario: Subscription released on destroy

- **GIVEN** live subscriptions are active for a module view
- **WHEN** the user navigates away and the component scope is disposed
- **THEN** every subscription is released via the composable's scope-bound lifecycle
