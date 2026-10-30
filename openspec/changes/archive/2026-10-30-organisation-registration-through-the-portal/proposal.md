---
kind: code
---

# Register your organisation from the municipality website

## Summary

Board SkPubliek has, beside the public catalogue, a form "Uw organisatie
aanmelden": a supplier or a government organisation registers without an
account, an administrator reviews it, and only then is the organisation active.
The decision (coordinator, 23 Oct) is that this form is portaliq's: a form on
the portal page that saves into stackiq's organisation schema with
`registrationStatus: pending` fixed on the server. This change is stackiq's half.

## What changes

- An organisation created without a signed-in user is always stored with
  `registrationStatus: pending` and without a publication date, whatever the
  request says. A save listener on OpenRegister's creating event enforces it, so
  the rule holds for portaliq's form, for stackiq's own intake endpoint and for a
  direct call to OpenRegister's objects API alike. Until now only stackiq's
  intake endpoint set it, and the organisation schema already allows an
  anonymous create.
- The organisation schema gets what the form asks: `website`, the
  `registrationNumber` (KvK number or OIN), and the registering person's `registrationContactName` and `registrationContactEmail`,
  readable only by signed-in users, like `registrationStatus` itself.
- The existing moderation queue (`ModerationService`, `registrationStatus`
  pending to active) reviews these registrations unchanged.

## Out of scope

- The form itself, its page and its fields: portaliq change
  `site-organisation-registration-form`.
- The activation mail after approval.

## Board

SkPubliek.
