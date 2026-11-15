---
kind: code
depends_on: []
---

# The mail template editor saves, and the settings say when no mail goes out

## Summary

stackiq sends its registration, activation and account mails from templates. The backend can store an edited template (`POST /api/email/templates/{templateName}`, `SettingsController::updateEmailTemplate`, admin only). The admin editor's Save button does not call it: `saveTemplate()` in `src/views/settings/sections/EmailConfiguration.vue` logs "not yet implemented" and shows a success message anyway, so an administrator believes a change was saved that was not. The transport defaults to `null`, which sends nothing until configured, and the settings do not say so. Board SkBeheerToegang draws the mail templates as editable. The board is the canon (decision 130), so the row moves from built to building with this plan.

## Rows

- `org-activation-mail` "Send registration, activation and account mails from templates an administrator can edit."

## Screens

See `screens.md`: SkBeheerToegang.
