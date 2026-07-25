# Errors — what they mean and how to avoid them

Every entry here corresponds to a real wall hit on the live instance. The pattern: **error symptom → root cause → fix**.

## 400 validationFailure — common cases

The body looks like:
```json
{"messageTranslation":{"label":"validationFailure","scope":null,"data":{"field":"<fieldName>","type":"<validationType>"}}}
```

Look at `data.field` and `data.type` to diagnose.

### `phoneNumber` `valid`

The instance has `phoneNumberInternational=true` in Settings. Phone numbers must include `+<country>`.

```
Wrong:  "phoneNumber": "555 123 4567"
Right:  "phoneNumber": "+52 555 123 4567"
```

Spaces are accepted; the backend normalizes to `+525551234567`.

### `industry` `valid`

`industry` on Account/Lead is a **predefined enum** with options like `Apparel`, `Banking`, `Manufacturing`. Not free-form. Free strings like `"Lottery"` or `"Restaurant"` get rejected.

Fix options:
1. Leave `industry` null and put the descriptive info in `description`.
2. Create a custom enum field like `cIndustryDetail` with your own option list.
3. Check available options first: `GET /Metadata?key=entityDefs.Account.fields.industry` and pick from `options`.

### `<customField>` `required`

A required custom field exists on the entity but is missing in the payload.

Real-world example: a previous instance had a custom enum field `cBusinessUnit` marked required on multiple entities, used as a multi-tenancy hack before someone realized Teams existed. Removing it left some layouts with stale references; new POSTs failed with "field required". Always grep current required fields before composing payloads:

```bash
<your-admin-helper> GET '/Metadata?key=entityDefs.<Entity>.fields' | python3 -c "
import sys, json
for f, defn in json.load(sys.stdin).items():
    if defn.get('required'):
        print(f, defn.get('type'))"
```

### `assignedUser` `required`

Several entities (notably Meeting) require an assigned user. If you're creating from a script and don't know who to assign, assign to the api user that's authenticating:

```json
{ "assignedUserId": "<id-of-the-api-user>" }
```

For your api user, look up the id once and store it in env (e.g. `ESPOCRM_API_USER_ID`).

### `<field>` `pattern`

The field expects a specific format and the value doesn't match. Common cases:
- A link field (e.g. `invoiceId`) given an empty string `""` because of an upstream variable expansion failure. Always check that ID variables are populated before constructing payloads.
- A datetime given in a format the regex doesn't accept. EspoCRM dates accept `YYYY-MM-DD` and datetimes accept both `YYYY-MM-DD HH:mm:ss` (space) and `YYYY-MM-DDTHH:mm:ss` (ISO). The MCP Zod validator only accepts the `T` form.

### `authMethod` `valid` (creating an api user)

`authMethod` on `POST /User` is a **case-sensitive enum**. The value for API-key auth is `"ApiKey"` (capital A, capital K).

```
Wrong:  "authMethod": "apiKey"   → 400 validationFailure {field: authMethod, type: valid}
Right:  "authMethod": "ApiKey"
```

**Silent trap — omitting `authMethod` entirely.** Creating an api user *without* `authMethod` succeeds (HTTP 200, the record is created and the response carries an `apiKey`), but every subsequent request with that key returns **401**. The key is real, just not wired to an auth method. Fix without regenerating the key:

```
PUT /User/<id>  {"authMethod": "ApiKey"}
```

The same key starts working immediately — no key rotation needed.

**Safe create flow:** `POST /User` (with `"authMethod":"ApiKey"`) → if you omitted it, `PUT /User/<id> {"authMethod":"ApiKey"}` → use the `apiKey` returned by the create response.

### `<customField>` `maxLength` — custom varchar too long

Custom `varchar` fields are created with **`maxLength: 100` by default**. A value longer than that does **not** truncate — it **rejects the entire POST/PUT** with `validationFailure`. When a create fails and the payload carries free-text fields (a note, a reference, a label), check the field's limit against metadata *before* blindly retrying:

```
GET /Metadata?key=entityDefs.<Entity>.fields.<field>
→ {"type":"varchar","maxLength":100}
```

Fix: raise the limit on the field (admin: `PUT /Admin/fieldManager/<Entity>/<field>` with a larger `maxLength` in the full def) or shorten the value.

### Diagnosing a failed 4xx — don't let `jq` hide the error

An anti-pattern that turns a rejected create into a phantom "empty record": piping the create response straight into `jq '{id, name, ...}'`. If the response was an **error** body rather than a record, `jq` prints every requested field as `null` and the real `messageTranslation` is lost — it looks like "it created a blank record" when nothing was created at all.

Correct way to debug a 4xx — keep headers *and* body:

```
curl -sS -D - -o body.json ...
```

then read the **HTTP status line**, the `x-status-reason` header, and the raw `body.json`. Confirm whether the record actually exists (`GET` with a filter / total count) before retrying, so you don't create a duplicate.

**Isolate by subtraction:** re-send the same payload *without* the suspect field. If it now succeeds, that field was the cause — confirmed in a single retry.

## 403 Forbidden — common cases

### PUT with a `<linkName>Ids` array on a manyToMany relationship

```
PUT /CInvoice/<id>  with body containing  "subscriptionsIds": ["a","b","c"]
→ HTTP 403
```

EspoCRM's API doesn't accept many-to-many link assignment via the parent record's PUT. Use the **relationship endpoint** instead, one POST per relation:

```bash
for sid in "$SUB_1" "$SUB_2" "$SUB_3"; do
  curl -X POST -H "$AUTH" -H "Content-Type: application/json" \
    -d "{\"id\":\"$sid\"}" \
    "$BASE/CInvoice/<inv_id>/subscriptions"
done
```

Or pass `{"ids": ["a","b","c"]}` in a single POST to the relationship endpoint.

For manyToOne, the simple `<linkName>Id` field DOES work in PUT — that's only the multi-link case that breaks.

#### Exception — the special `teams` link is the OPPOSITE: PUT the field, not the relationship endpoint

For `teams`, the rule above inverts. Assigning teams to a record via the relationship endpoint **403s** for an api user, while PUT-ing the `teamsIds` field on the record **works**:

```
POST /Lead/<id>/teams  {"id":"<teamId>"}     → 403   (for an api user with Lead edit=all but Team edit=no)
PUT  /Lead/<id>        {"teamsIds":["<teamId>"]}  → 200
PUT  /Lead/<id>        {"teamsIds":[]}            → 200  (unlinks cleanly)
```

**Why the inversion.** `teams` is a special link exposed as an editable link-multiple **field** on the record, gated by the role's `assignmentPermission` (which was `all` here). The generic relationship endpoint, by contrast, appears to check `edit` on the **foreign** entity too — so an api user without Team edit is refused. The deciding factor: if the link is an editable field on the record and the user can edit the foreign scope as needed, PUT the `<linkName>Ids` field; otherwise use the relationship endpoint. Entity↔entity custom links (e.g. CSubscription↔CInvoice) follow the relationship-endpoint rule above; `teams` is the notable exception.

### Admin operation via api user

Trying to do schema/admin work with `X-Api-Key` returns 403:
- POST /Team
- POST /Role / PUT /Role/<id>
- POST /EntityManager/action/createEntity
- PUT /Admin/fieldManager/...
- DELETE /Admin/fieldManager/...

Switch to the admin path (`<your-admin-helper>`).

### 403 with an EMPTY body → the role lacks the scope (native entities too, not just custom)

The role-scope 403 is **not** limited to custom entities. A native entity (`Account`, `Contact`, `Lead`, `Opportunity`…) that the role holds read-only blocks writes exactly the same way — and it surprises more, because a "normal" api user is assumed to be able to create Accounts.

```
POST /api/v1/Account   →  HTTP 403, body EMPTY
```

No `messageTranslation`, no `label`, nothing. Two consequences worth knowing before you debug the wrong layer:

- A script that parses the response as JSON dies with `Expecting value: line 1 column 1` — a JSON error masking an ACL error.
- If the (never-assigned) id is used in a following step, the cascade fails with errors that don't point at the cause — e.g. `validationFailure … {field: account, type: pattern}`, because the link field got an empty string. See `<field>` `pattern` above.

**One-command diagnosis** — an api user can read its own effective permissions:

```bash
curl -s -H "X-Api-Key: $KEY" "$URL/api/v1/App/user" \
 | python3 -c "import json,sys; d=json.load(sys.stdin); t=d['acl']['table']; \
   print({k:t.get(k) for k in ('Account','Contact','Lead','Opportunity')})"
```

Each scope returns `{'create':'no','read':'all','edit':'no','delete':'no'}` — and **`None` when the role has no scope at all** for that entity. The same response carries `user.rolesIds` / `rolesNames`, which names the exact role to edit.

**The fix is the same hard rule: widen the role with admin auth, then create the record with the api key.** Never create the record as admin, even when it's one call away — `createdBy` lands on admin, the record misses the operational Streams, and triggers that key off `createdBy` never fire.

```
GET  /api/v1/Role/<roleId>          # -> .data = { "Account": {...}, "CInvoice": {...} }
PUT  /api/v1/Role/<roleId>  {"data": { ...previous..., "Account": {"create":"yes","read":"all","edit":"all","delete":"no"} }}
```

- **Merge, don't replace.** GET the role and modify only the keys you need. A PUT with a partial `data` drops every scope you didn't send.
- **Set `delete: "no"` explicitly** unless deletion is actually required — least privilege, and it keeps a service key from erasing records.
- **Clear cache afterwards** (`php clear_cache.php` in the container) and **re-check with the `App/user` call above** that the permission is live, before retrying the POST.
- **A 403 can be a deliberate design boundary, not a bug.** An api user may be scoped to one function on purpose. Confirm with the instance owner before widening a role rather than assuming the permission was missing.

### Custom entity created AFTER the role exists → api user can't edit/create/delete on it

When you create a new custom entity via `EntityManager/action/createEntity`, **the existing roles do NOT auto-update** to include scopes for the new entity. The api user can typically still LIST/READ from frontend defaults, but `POST`/`PUT`/`PATCH`/`DELETE` on records of that entity return **403**.

Symptom:
```
mcp__espocrm__update_entity({entityType:"CSubscription", entityId:"...", data:{...}})
→ MCP error: Access forbidden - insufficient permissions for PUT CSubscription/...
```

But the same record is editable when you go through `<your-admin-helper>` (admin auth bypasses the role).

**Fix**: update the role's `data` field to include the new entity scope. This is admin-only.

```bash
# Read current role
ROLE=$(<your-admin-helper> GET /Role/<roleId>)

# Add CSubscription/CInvoice/CPayment to data with full perms
PAYLOAD=$(echo "$ROLE" | python3 -c "
import sys, json
d = json.load(sys.stdin)
data = d.get('data', {})
for ent in ['CSubscription', 'CInvoice', 'CPayment']:
    data[ent] = {'read':'all','edit':'all','create':'yes','delete':'all'}
print(json.dumps({'data': data}))
")

# PUT it back
<your-admin-helper> PUT /Role/<roleId> "$PAYLOAD"
```

**Habit to form**: every time you run `EntityManager/action/createEntity` for a new custom entity, immediately update the api user's role to include that entity's scope. Otherwise the api user (and the MCP) will be blocked from CRUD on it, and you won't notice until you try to update something via the MCP.

### Deleting a custom field leaves residual references in layouts

`DELETE /Admin/fieldManager/<scope>/<fieldName>` removes the field definition from `entityDefs` but **does NOT clean it out of the layouts** that referenced it. The frontend still shows the field name in dropdowns like "Add Field" because layouts like `list`, `detail`, `filters`, `searchFilters` retain the entry.

After deleting any custom field, sweep each layout of the same entity:

```bash
for layout in list detail listSmall detailSmall filters massUpdate searchFilters; do
  current=$(<your-admin-helper> GET /<Entity>/layout/$layout)
  cleaned=$(echo "$current" | python3 -c "
import sys, json
def strip(obj, target='cFieldName'):
    if isinstance(obj, dict):
        if obj.get('name') == target: return False
        return {k: strip(v, target) for k, v in obj.items() if k != target}
    if isinstance(obj, list):
        return [strip(x, target) for x in obj if not (isinstance(x, str) and x == target) and strip(x, target) is not False]
    return obj
print(json.dumps(strip(json.load(sys.stdin))))
")
  if echo "$current" | grep -q '<fieldName>'; then
    <your-admin-helper> PUT /<Entity>/layout/$layout "$cleaned"
  fi
done
```

Then `Admin/rebuild` + `Admin/action/clearCache`. User does hard refresh in browser (Ctrl+Shift+R, not just F5).

### "Add Field" dropdown menu shows cursor "prohibited" on custom entities (3-dot kebab next to search bar)

In list view, the 3-dot kebab `⋮` next to the search bar opens an "Add Field" dropdown for adding temporary filters. On freshly created **custom entities**, that button shows cursor `not-allowed` and doesn't open.

**Cause**: the entity is missing the `filters` layout. Native entities ship with one populated by default; custom entities are created without it.

**Important distinction** — `filters` is NOT the same as `searchFilters`:
- `searchFilters` → fields shown as **filter chips by default** (the "Teams ▼ Status ▼" persistent buttons). Configurable via `PUT /<Entity>/layout/searchFilters`.
- `filters` → fields offered in the **"Add Field" dropdown** of the kebab. Without this, the kebab is dead. Configurable via `PUT /<Entity>/layout/filters`.

**The fix** (no SSH needed, just admin auth):

```bash
<your-admin-helper> PUT /CSubscription/layout/filters \
  '["name","account","serviceType","monthlyAmount","status","startDate","nextBillingDate","assignedUser","teams","createdAt","createdBy","modifiedAt","modifiedBy"]'

<your-admin-helper> POST /Admin/rebuild '{}'
<your-admin-helper> POST /Admin/action/clearCache '{}'
# user does Ctrl+Shift+R in browser
```

Apply to every custom entity at creation time. The list should include `name`, all the entity's own fields, plus the standard `assignedUser`, `teams`, `createdAt`, `createdBy`, `modifiedAt`, `modifiedBy`.

**Original misdiagnosis (kept here as a warning)**: I initially claimed this required SSH and editing `custom/Espo/Custom/Resources/metadata/clientDefs/<X>.json`. That was wrong — the `filters` layout endpoint exists and works fine via admin auth. The confusion came from emptying `searchFilters` as a test (which didn't help) and concluding the cause was elsewhere. The two layouts are distinct and `filters` is the one that controls the kebab. **Never confuse them again.**

### Editing your own user via api user

`PUT /User/<own-id>` with the api user gives 403 even on basic field edits. The api user does NOT have write permission on User (read=all, edit=no) — by design, prevents self-escalation.

To edit the api user's profile (e.g. assign teams, change name, rotate api key), use the admin path.

### `cannotRelateForbidden` on create/assign with `assignedUserId`

Exact signature → cause (the underlying requirement is already documented in [auth-patterns.md](auth-patterns.md); this is just the string to recognize it on the fly):

```
403  body: cannotRelateForbidden {foreignEntityType: "User", action: "read"}
```

on creating/assigning a record with `assignedUserId` set means the api user's role has **no User read access**. It is NOT a problem with the record or the team. Fix: the role needs `User: {"read":"all"}` + `assignmentPermission: "all"` (the exact role shape is in auth-patterns.md, Path 1).

## 404 Not Found — common cases

### `Record <id> not found`

Look at the response header `x-status-reason`:
```
x-status-reason: Record <some-id> not found.
```

The id you're using points to a deleted record. Common during instance upgrades: api users can be wiped and recreated with new ids. Always verify ids before composing payloads:

```bash
<your-admin-helper> GET '/User?select=id,userName,type'
```

If your records reference a user id that no longer exists (e.g. `assignedUserId`, `createdById`), you'll get this error on subsequent updates. Always re-discover ids after any major instance change (upgrades, restores) before reusing them in payloads.

### Endpoint exists but not at the path you expect

`POST /Admin/entityManager/createEntity` returns 404 — the right path is `POST /EntityManager/action/createEntity`. The pattern in espoCRM 9.x for action methods on a controller is:

```
POST /<Controller>/action/<methodName>
```

(The controller's PHP method name is `postAction<MethodName>`.)

Other valid examples:
- `POST /EntityManager/action/createLink`
- `POST /EntityManager/action/updateEntity`
- `POST /EntityManager/action/removeEntity`
- `POST /Admin/rebuild` (this one is special — no `action/` prefix)

## 405 Method Not Allowed — common cases

The response includes an `allow:` header listing valid methods.

### `POST /Admin/fieldManager/<scope>/<field>`

Returns:
```
HTTP/2 405
allow: GET, DELETE, PUT, PATCH
```

Field create/update is **PUT** (idempotent, same payload whether creating or editing), not POST. POST to a path that includes both the scope and field name doesn't make sense in REST terms — that's why it's blocked.

### `POST /User/<id>` (any user, not relationship-related)

```
HTTP/2 405
allow: GET, DELETE, PUT, PATCH
```

To create a User, POST to `/User` (collection). To update a User, PUT/PATCH to `/User/<id>` (record).

This is the standard REST distinction; the 405 is correct.

## 500 Internal Server Error — common cases

### `PUT /Admin/fieldManager/<scope>/<field>` without `type` in the body

Editing an **existing** field (e.g. changing an enum's `options`) by PUT-ing a partial body that omits `"type"` returns **500**, not a clean 4xx:

```
FAILS (500):  {"options":[...],"default":"C","required":true,"audited":true}
WORKS (200):  {"type":"enum","options":[...],"default":"C","required":true,"audited":true,"isCustom":true}
```

The fieldManager PUT is **not a partial patch** — it rebuilds the field def from the body, and a def without `type` is invalid, which surfaces as a 500. Always include `type` (and `isCustom: true` for custom fields).

**Safe edit-options pattern** — don't hand-build a partial body. Read the full current def, mutate the piece you care about, send the whole thing back:

```bash
# 1. Read the complete field def
GET /Metadata?key=entityDefs.<Scope>.fields.<field>
# 2. Modify the `options` array (and `style`/`default` if needed) in that object
# 3. PUT the COMPLETE def back — type, options, isCustom, everything
PUT /Admin/fieldManager/<Scope>/<field>  '<full def with edited options>'
# 4. Rebuild
POST /Admin/rebuild
```

**Sequencing gotcha — rebuild is not retroactive.** If a PUT fails and `Admin/rebuild` already ran earlier in the same batch, you must re-run the rebuild *after* the successful PUT. The rebuild only registers schema state as it exists at the moment it runs; it does not pick up a later successful write.

## 502 Bad Gateway from Caddy

```
HTTP/2 502
server: Caddy
content-length: 0
```

Caddy is the reverse proxy in front of the espoCRM container. A 502 with `content-length: 0` immediately (no delay) means the backend either crashed mid-request, the PHP-FPM workers are saturated, or there's a transient issue.

Recovery:
1. Wait 5-10 seconds and retry. Most 502s are transient.
2. If repeated, check if backend GET requests still work. If GET works but POST keeps 502'ing, the backend is having trouble with state writes specifically — not a network issue.
3. If both GET and POST 502 persistently, the container may be down. Check from the espoCRM host: `sudo podman ps | grep espocrm`.

Don't retry tight-loops on 502 — exponential backoff or wait-then-once.

## MCP-specific errors (when going through `mcp__espocrm__*` tools)

### `Bad request in POST <Entity>: Invalid request data`

Generic. The MCP doesn't surface the actual `validationFailure` field. Re-run the same payload directly via curl or `<your-admin-helper> POST /<Entity> '<json>'` to get the real `messageTranslation` body with the offending field name.

### `Connection test failed` from `health_check`

The MCP server's API key isn't valid. Two common causes:
1. The api user was deleted (e.g. instance upgrade wiped it) — see 401 cases below.
2. `ESPOCRM_API_KEY` was rotated in `~/.secrets/` but Claude Code wasn't restarted — the MCP server has the old key in process memory. **Restart Claude Code.**

### Tool arguments rejected by Zod schema even when the API would accept them

The MCP tools have their own input schemas that are **MUCH stricter** than what EspoCRM accepts. Cases observed:

- **Datetime**: `dateStart`/`dateEnd` only accept `YYYY-MM-DDTHH:mm:ss` (ISO `T`), not the space-separated form. The actual API accepts both.
- **Names with non-ASCII characters**: `firstName`/`lastName` reject anything with tildes, accents, dashes, parentheses, or unusual characters. Common Spanish/Portuguese/French names with accented letters get rejected (`Name contains invalid characters`). Strings with spaces and prepositions (compound surnames) also fail. Placeholder text with em-dashes or parentheses fails. Backend accepts all of these — it's the Zod regex of the MCP tool that's overly strict.
- **Phone numbers**: `phoneNumber` regex of the MCP tool rejects values with `+` and spaces (the standard E.164-friendly format). Backend requires `+<country>` prefix and accepts spaces. The MCP regex is incompatible with what the backend requires.

**Default fallback**: when any specialized tool (`create_lead`, `create_contact`, `create_meeting`, `update_<entity>`, etc.) rejects fields you know the backend would accept, switch to the generic `mcp__espocrm__create_entity` / `mcp__espocrm__update_entity`. Those pass through to the backend with much lighter validation. Examples:

```javascript
// Specialized tool → MCP rejects names with accents with regex error
mcp__espocrm__create_lead({ firstName: "<accented name>", lastName: "<compound surname>", ... })

// Generic tool → backend accepts and creates the record
mcp__espocrm__create_entity({
  entityType: "Lead",
  data: {
    firstName: "<accented name>",
    lastName: "<compound surname>",
    phoneNumber: "+<country> <number>",
    ...
  }
})
```

The generic `create_entity` is the default for any record with non-trivial content (Spanish names, accented strings, unusual chars, custom fields). Only use specialized tools when you know all values are ASCII-clean and within their schema.

## 401 Unauthorized

### `X-Api-Key` returns 401

The api user doesn't exist (deleted during upgrade or manually) or the key was rotated.

Check from admin path:
```bash
<your-admin-helper> GET '/User?select=id,userName,type&where[0][type]=equals&where[0][attribute]=type&where[0][value]=api'
```

If the api user is missing, recreate it via admin path:
```bash
<your-admin-helper> POST /User '{
  "userName": "<api-user-name>",
  "lastName": "MCP Claude Code",
  "type": "api",
  "isActive": true,
  "authMethod": "ApiKey",
  "rolesIds": ["<role-id>"],
  "teamsIds": ["<t1>","<t2>","<t3>"],
  "defaultTeamId": "<default-team-id>"
}'
```

The response includes the new `apiKey`. Update your secrets env file with `ESPOCRM_API_KEY=<new>` and re-set in systemd. Restart Claude Code so the MCP picks up the new key.

### Basic Auth returns 401

The admin password is wrong, or the user `claude` doesn't exist.

To rotate password (requires shell on the espoCRM host):
```bash
sudo podman exec -i <espocrm-container-name> php /var/www/html/command.php set-password <admin-username>
```

## Bash / shell gotchas (not espoCRM, but seen during work)

- The `<your-admin-helper>` helper writes the HTTP status line to **stderr** and the body to **stdout**. `grep -oP 'HTTP \K[0-9]+'` on stdout returns nothing. Use either `2>&1 | tee` or pipe stderr explicitly.
- Within a `bash <<'EOF' ... EOF` heredoc, `for path in "...";` expansion sometimes gets parsed by the user's outer shell (zsh) when the script runs through a wrapper. If you see `zsh: command not found: tail` or similar inside what should be bash, wrap the whole block in an explicit `bash -c '...'` or run the heredoc with `bash -s <<EOF`.
- When constructing JSON in bash with embedded variables, prefer Python's `json.dumps` over shell concatenation for any payload with newlines, quotes, or special chars in `description`. Single-quote heredocs prevent variable expansion; double-quote heredocs expand but conflict with embedded JSON strings.
