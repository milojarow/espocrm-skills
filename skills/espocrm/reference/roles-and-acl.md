# Roles and ACL — closing down a regular user, and proving it's closed

Verified on EspoCRM 9.3.x while onboarding the first `type=regular` user of an instance.

## First: an admin ignores the entire ACL

If the user is `type=admin`, no Role constrains them. Permissions only apply to `type=regular`. Obvious once said, and the #1 mistake in small instances where "everyone is an admin".

## Exact permission names (9.x)

From `Resources/metadata/entityDefs/Role.json`:

| Field | Values |
|---|---|
| `assignmentPermission` | not-set / all / team / no |
| `userPermission` | not-set / all / team / no |
| `messagePermission` | not-set / all / team / no |
| `groupEmailAccountPermission` | not-set / all / team / no |
| `followerManagementPermission` | not-set / all / team / no |
| `mentionPermission` | not-set / all / team / no |
| `userCalendarPermission` | not-set / all / team / no |
| `portalPermission` | not-set / yes / no |
| `exportPermission` | not-set / yes / no |
| `massUpdatePermission` | not-set / yes / no |
| `dataPrivacyPermission` | not-set / yes / no |
| `auditPermission` | not-set / yes / no |

Per-entity scopes live in `data`, with levels `all` / `team` / `own` / `no` — or `false` to **hide the entity entirely** (no tab, and the API returns 403).

## The two everyone forgets

- **`userPermission: team`** — without it, the user enumerates every user in the instance in every dropdown. With `team`, a user who is alone on their team gets exactly **1 result — themselves** — from `GET /User`.
- **`exportPermission: no`** — "can see the book of business" and "can download it as CSV" are different things. A commission-based salesperson with export enabled walks out with the database the day they leave.

## Testing export for real (and the control that prevents a false positive)

The real endpoint is **`POST /api/v1/Export`** with `{"entityType": "...", "format": "csv"}`.

Probing blind walks into a trap: `POST /<Entity>/action/massExport` and `POST /<Entity>/action/export` **return 404 even for an admin**. A 404 for the restricted user *looks* like a block and proves nothing.

**Always run the same request with an admin account as a control.** A good result looks like this:

```
POST /Export                       admin=200   restricted=403   <- the permission works
POST /<Entity>/action/massExport   admin=404   restricted=404   <- endpoint doesn't exist, proves nothing
```

Same discipline for any claim that something is blocked: if the admin fails too, what's broken is the test.

## A shape that works for a salesperson-style role

```
Lead, Opportunity, Task, Meeting, Call   read=own   edit=own   create=yes   delete=no
Account, Contact                         read=all   edit=own   create=yes   delete=no
CSubscription, CInvoice, CPayment        read=all   edit=no    create=no    delete=no
Document                                 read=all   edit=no    create=no
Entities of another business unit        false
Team, Import, EmailTemplate, Template    false
exportPermission = no · userPermission = team · massUpdatePermission = no
```

The reasoning behind `read=all` on Account/Contact: if the salesperson **can't see which accounts are already customers**, sooner or later they cold-call one that's already signed, which burns the owner in front of their own client. Seeing the names is operational prevention; `edit=own` is what actually protects the data.

## Verify by authenticating AS them, not by reading the config

The only proof that counts is a real session with their credentials (`Espo-Authorization` Basic header, see [auth-patterns.md](auth-patterns.md)) walking the endpoints:

```
GET /App/user             -> confirm userName, type=regular, teams
GET /<Entity>?maxSize=1   -> 200 with a total, or 403
PUT /<Entity>/<not-theirs> -> expect 403
POST /Export              -> expect 403
```

Reading the Role's JSON only tells you what was saved, not what the ACL engine does with it.
