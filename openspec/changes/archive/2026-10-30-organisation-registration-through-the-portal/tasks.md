# Tasks: organisation-registration-through-the-portal

- [x] **T1**: `lib/EventListener/AnonymousRegistrationListener.php` on OpenRegister's `ObjectCreatingEvent`: an organisation created with no signed-in user gets `registrationStatus: pending` and no `publicationDate`. Registered in `lib/AppInfo/Application.php`. Test: PHPUnit `tests/Unit/EventListener/AnonymousRegistrationListenerTest.php`.
- [x] **T2**: `lib/Settings/register.d/organisation-registration.json`: `website`, `registrationNumber`, `registrationContactName`, `registrationContactEmail` on `organization`, the number and contact fields readable by signed-in users only, schema version raised. Test: PHPUnit `tests/Unit/Settings/OrganisationRegistrationFragmentTest.php`.
