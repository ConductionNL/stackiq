# Retrofit — fe-organizations

Describes observed behavior of the frontend organization & contact-person UI as REQ(s) under the `fe-organizations` capability. Code already exists — this change retroactively specifies it (strict coverage policy: every non-trivial frontend method gets a spec).

## Affected code units

- src/components/AddContactpersoonModal.vue
- src/components/ContactpersonenList.vue
- src/modals/OrganisationModal.vue
- src/components/cards/OrganisatieCard.vue
- src/modals/object/ChangeOrganisatieStatusDialog.vue
- src/views/widgets/ConceptOrganisatiesWidget.vue

## Approach

- Describe the observed UI behavior for adding/listing contact persons, editing organisations, and changing organisation status.
- Group methods implementing the same observable behavior under one REQ.
- Annotate each method with `@spec` pointing at the matching task.

## Impact

- Documentation only. No runtime behavior change.
