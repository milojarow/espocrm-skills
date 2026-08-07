# Auth — two paths, two purposes

EspoCRM 9.x supports multiple authentication mechanisms but for a single-operator setup with one MCP integration plus admin tooling, you only need two. Match the auth to the work.

## Path 1 — `X-Api-Key` (api user, daily CRUD)

**Suitable user type**: `api`. Only this type accepts `X-Api-Key` and HMAC. Regular and admin users authenticate the regular way (Basic + session token).

**Role**: a role with `read=all` / `edit=all` / `create=yes` / `delete=all` on whatever entities the api user needs to operate on (Account, Contact, Lead, Opportunity, Meeting, Call, Task, Case, Note, Document, plus your custom entities). The role should NOT have scope on `Role`, `User edit`, `Settings`, `Metadata`, `EntityManager` — those are admin operations and the api user must NOT be able to escalate itself.

**Header:**
```
X-Api-Key: <api-key-value>
```

**Suggested storage**: an env file outside the repo (e.g. `~/.secrets/environment.d/<name>.conf`) loaded by your shell or systemd user environment. The MCP server wrapper script reads it from env via `exec` inheritance.

**Creation gotcha**: on `POST /User`, set `"authMethod": "ApiKey"` (case-sensitive — `"apiKey"` 400s). Omitting it lets the user create successfully and even returns an `apiKey`, but that key then 401s on every call; fix with `PUT /User/<id> {"authMethod":"ApiKey"}` (no key regeneration). Details: [common-errors.md](common-errors.md) → `authMethod valid`.

**Test it works:**
```bash
curl -sS -H "X-Api-Key: $ESPOCRM_API_KEY" "$ESPOCRM_URL/api/v1/App/user" | head -c 200
```

If it returns 401, the api user was deleted or the key was rotated. Open admin path to recreate the api user and update the key.

**What this path can NOT do**: anything in Administration scope. Specifically:

- Create/edit Teams (`POST /Team` → 403)
- Create/edit Roles
- Create/edit/remove custom entities (EntityManager)
- Create/edit/remove custom fields (Admin/fieldManager)
- Modify Settings or Metadata writes
- Read or write the `Role` scope

For any of those, switch to Path 2.

### Pattern — ACL-scoped read-only mirror key

For a public-facing read-only surface (e.g. a dashboard or widget that queries the CRM), don't reuse the full-CRUD api user. Mint a **dedicated api user whose role physically can't do harm**, so security doesn't depend on the consumer app getting its `where[]` filter right:

- **Role**: `read=team`, and `create=no` / `edit=no` / `delete=no` on every scope. With write disabled, any `POST`/`PUT`/`DELETE` returns 403 — the key can only read.
- **Membership**: put the user in **exactly one team**. With `read=team`, the key can only see records belonging to that team. Records with no team are invisible to it too.

This is defense in depth: even if the consumer app's query filter is wrong or bypassed, the key still cannot read other tenants' records or mutate anything. Round-trip to verify: tag a record with the team → it becomes visible to the key; untag it → it disappears.

### Runbook — provision a scoped api-user for server-side writes

When a new host/service needs to **create/edit records** (never via admin — see the hard rule in SKILL.md), provision it its own scoped api-user entirely over the API, no WUI:

```
# 1. Scoped role (admin auth). assignmentPermission:"all" is REQUIRED if the
#    api-user will set assignedUserId on records — without it, 403 on assign.
POST /Role
{"name":"<Host> Ops — <domain>","assignmentPermission":"all",
 "data":{"<Entity1>":{"create":"yes","read":"all","edit":"all","delete":"no"},
         "<Entity2>":{...},
         "Account":{"create":"no","read":"all","edit":"no","delete":"no"},
         "User":{"read":"all"},"Team":{"read":"all"}}}

# 2. Api user bound to the role. authMethod:"ApiKey" EXPLICIT — if left null, 401 forever.
POST /User
{"userName":"<host>-ops","lastName":"<Host> Ops","type":"api",
 "authMethod":"ApiKey","isActive":true,"rolesIds":["<roleId>"]}

# 3. Retrieve the key WITHOUT regenerating it: GET /User/<id> as admin → apiKey field.
#    Store it in the host secret store (600 root), never in the repo.
```

Notes:
- `delete:"no"` is deliberate: a per-machine ops key (billing/registration/etc.) doesn't need to delete; deletes stay with admin under human confirmation.
- Records it creates are attributed to the api-user (`createdByName: <host>-ops`) — consistent attribution by origin (one api-user per machine/pipeline), which is the whole point of the never-via-admin rule.
- `User`/`Team` `read:all` in the role is what lets the key resolve `assignedUserId`/`teamsIds` at create time.

## Path 2 — `Espo-Authorization: Basic` (admin user, schema work)

**Suitable user type**: `admin`. Authenticates with username + password, exchanges for an AccessToken on first call, then uses the token on subsequent calls.

**Why Basic Auth and not X-Api-Key**: EspoCRM 9.x restricts `X-Api-Key` and HMAC auth to users of `type=api` (verified in `Espo\Core\Authentication\Helper\UserFinder::findApiApiKey`). Admin users have to authenticate the regular way.

**The flow** (Basic + AccessToken):

1. **Login**: Basic-encode `<user>:<password>` into the `Espo-Authorization` header. The response includes a `token` field.

```bash
LOGIN=$(curl -sS \
  -H "Espo-Authorization: $(printf '%s:%s' "$ESPOCRM_ADMIN_USER" "$ESPOCRM_ADMIN_PASSWORD" | base64 -w0)" \
  "$ESPOCRM_URL/api/v1/App/user")
TOKEN=$(printf '%s' "$LOGIN" | grep -oP '"token":"\K[a-f0-9]+(?=")' | head -1)
```

2. **Subsequent calls**: same header, same Basic encoding, but with the **token** replacing the password.

```bash
curl -H "Espo-Authorization: $(printf '%s:%s' "$ESPOCRM_ADMIN_USER" "$TOKEN" | base64 -w0)" \
  "$ESPOCRM_URL/api/v1/Team"
```

3. **On 401**: token was invalidated server-side (e.g. `authTokenLifetime` expired or the `auth_token` row was manually deleted, e.g. when password was rotated). Re-login.

**Token TTL**: governed by Settings `authTokenLifetime` (absolute) and `authTokenMaxIdleTime` (idle). Both default to non-zero. If both are set to `0`, tokens never expire — convenient for automation but means a leaked token is valid until manually revoked.

**Suggested credential storage**: env vars `ESPOCRM_ADMIN_USER`, `ESPOCRM_ADMIN_PASSWORD` in your secrets env file. Cache the token at a path with `chmod 600`.

## A helper script for the admin path

Strongly recommended to wrap the login + token cache + auto-relogin in a small shell script, e.g. `espocrm-admin`. Behavior:

```bash
espocrm-admin GET    /Team
espocrm-admin GET    '/Account?maxSize=5&select=id,name'
espocrm-admin POST   /Team '{"name":"New Team"}'
espocrm-admin PUT    /Admin/fieldManager/Account/cSomething '{"type":"varchar","required":false}'
espocrm-admin DELETE /Admin/fieldManager/Account/cSomething
espocrm-admin login        # force re-login, prints token, exits
espocrm-admin whoami       # quick identity check
```

Internal behavior to implement:

- Reads `ESPOCRM_URL`, `ESPOCRM_ADMIN_USER`, `ESPOCRM_ADMIN_PASSWORD` from env
- Caches token at a per-user path (chmod 600)
- On HTTP 401, automatically re-logins once and retries
- Writes the **HTTP status line to stderr**, body to **stdout**. The caller should NOT grep across both at once.
- Exits 0 on 2xx, 1 otherwise.

## When to use which

| Task | Path |
|---|---|
| List Accounts/Contacts/etc. | api-user (MCP or curl) |
| Create a Lead, update an Opportunity | api-user (MCP) |
| Link a custom-entity record to another via many-to-many | api-user — use the `/<Entity>/<id>/<linkName>` relationship endpoint via curl, not the MCP specialized tools (those don't expose linked endpoints for custom entities) |
| Create a Team | admin (helper script) |
| Add a custom field | admin (helper script) |
| Remove a custom field | admin (helper script) |
| Define a new custom entity | admin (helper script) |
| Edit a Role's permissions | admin (helper script) |
| Modify Settings / sidebar tabList | admin (helper script) |
| Read Metadata | works on both paths (admin sees more) |

## Never conclude "this instance is open" from a 200 on `/Settings`

`GET /api/v1/Settings` answers **200 without any credentials** — that is documented, intentional behavior (the frontend needs it before login). It is the single easiest way to talk yourself into believing the whole instance is unauthenticated, or that a request of yours was authenticated when it wasn't.

Before drawing either conclusion, run the explicit **negative case**. Measured against an instance where every entity requires a session:

```
no header at all      → 401
X-Api-Key: (empty)    → 401
X-Api-Key: garbage    → 401
```

Two rules follow:

- A 200 on `/Settings` proves **nothing** about your session — neither that you have one, nor that the instance is exposed. Probe an actual entity (`GET /api/v1/Account?maxSize=1`) to test authentication.
- When you claim an endpoint needs no auth, you must have *tried it without auth and with a bad key* and seen them differ. An assertion about access control is only worth what its negative test is worth.

## Rotation playbook

### If the api-user key leaks

```bash
# Generate a new key (admin path)
espocrm-admin POST /User/<api-user-id> '{"apiKey":""}'
# Response contains the regenerated key. Save it in your env file
# and re-set in your shell or systemd user environment.
```

### If the admin password leaks

This requires shell access to the EspoCRM container's host. From outside:

```bash
sudo podman exec -i <container-name> php /var/www/html/command.php set-password <admin-username>
# Update your secrets env file with the new password.
```

### To revoke all active admin tokens

```bash
sudo podman exec <postgres-container> psql -U espocrm -d espocrm \
  -c "DELETE FROM auth_token WHERE user_id = (SELECT id FROM \"user\" WHERE user_name='<admin-username>');"
# Also wipe the local token cache so the next call forces re-login
rm ~/.cache/<helper-script-token-cache>
```
