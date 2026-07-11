# Notifications — native assignment email vs a custom backend email

EspoCRM's native assignment email (`assignmentEmailNotifications`) is a generic template ("X assigned Y to you" + link) with a structural gap: **it only fires when the assignment changes**. If a backend does upsert-with-dedup (updates an existing record because the same contact wrote in again), EspoCRM emits nothing — the re-contact (a hot sales signal) goes silent. The native email also does **not** fire on self-assignment (creator == assignee) — by design, not a bug.

## Pattern — the backend sends its own HTML email (verified E2E in production)

When the business wants branded, context-rich notifications, have the backend that creates/updates the records send its own email instead of relying on the native one:

1. **Two variants from one builder:** `created` (new record) and `recontact` (upsert by dedup). Keep the builders pure and testable; **always escape user input** (`& < > " '`) since it lands in email HTML.
2. **Deep link straight to the record:** `<siteUrl>/#<Entity>/view/<id>` — the same URL shape the native email uses.
3. **Avoid duplicate notifications** by removing *only that one entity* from the native list, leaving the master switch and every other entity intact:
   ```
   PUT /api/v1/Settings
   {"assignmentEmailNotificationsEntityList":[ ...list without that entity... ]}   # admin auth
   ```
   Fully reversible with another PUT.
4. **Caveat of step 3:** records of that entity created **by hand in the WUI** and assigned to another user will no longer send a native email either (the in-app bell still fires — it's a separate list). Surface this tradeoff to the operator before flipping it.

## Per-user opt-out (`Preferences.assignmentEmailNotificationsIgnoreEntityTypeList`)

The per-user counterpart to the global Settings list (step 3 above). Each user's `Preferences` accepts `assignmentEmailNotificationsIgnoreEntityTypeList` — a list of entity types whose assignment emails **that user** won't receive, without affecting anyone else.

Use case: an operator already receives custom branded notifications from the backend and doesn't want the plain native duplicate → add `["Lead","Meeting"]` to **their** ignore-list; every other user on the team keeps receiving theirs normally. It composes with the global list: the global list defines which entity types notify at all; the per-user ignore-list subtracts individuals.

## Timing of the native email

Measured on a healthy instance: the daemon processes the native assignment email in **~6–8 s after the record POST**, not the "up to 1 minute" often assumed. Useful when correlating a send against mail logs — the email appears seconds after the create, not minutes.
