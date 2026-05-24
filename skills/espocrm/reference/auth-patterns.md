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
