# EspoCRM 9.3 — Verified Endpoints

Every endpoint here has been called against the live instance and the result documented. If it's not here, treat it as unverified.

Base URL: `<your-instance-url>/api/v1` (referred to as `<base>` below). Set `ESPOCRM_URL` in your env to the instance host.

## Authentication

Every request needs one of:

```
X-Api-Key: <key>                                    (api user — daily CRUD)
Espo-Authorization: Basic <base64(user:tokenOrPwd)> (admin user — schema work)
```

See [auth-patterns.md](auth-patterns.md) for the full flow.

## Standard CRUD on records

| Operation | Method + Path | Body | Returns |
|---|---|---|---|
| List | `GET <base>/<Entity>?maxSize=N&offset=M&select=f1,f2&orderBy=f&order=asc` | — | `{total, list:[...]}` |
| Read one | `GET <base>/<Entity>/<id>` | — | record |
| Create | `POST <base>/<Entity>` | record | created record (with `id`) |
| Update | `PUT <base>/<Entity>/<id>` or `PATCH <base>/<Entity>/<id>` | partial record | updated record |
| Delete | `DELETE <base>/<Entity>/<id>` | — | `true` |

For `<Entity>` use the technical name: `Account`, `Contact`, `Lead`, `Opportunity`, `Meeting`, `Call`, `Task`, `Case`, `User`, `Team`, `Role`, `CSubscription`, `CInvoice`, `CPayment`.

> **`/Settings` is not a record endpoint** — `PATCH /api/v1/Settings` returns **404**; global settings only accept `PUT`. The `PATCH`-works rule above applies to entity *records*, not to `/Settings`.

## Querying with `where[]`

The query param `where[]` lets you filter. Each clause is an object with `type`, `attribute`, and `value` (or `value[]` for arrays). URL-encoded.

```bash
# Accounts whose name starts with "Acme"
GET <base>/Account?where[0][type]=startsWith&where[0][attribute]=name&where[0][value]=Acme

# Subscriptions linked to a specific account (linkedWith on a hasMany relationship)
GET <base>/CSubscription?where[0][type]=linkedWith&where[0][attribute]=account&where[0][value][]=<accountId>
```

Common `type` values: `equals`, `notEquals`, `contains`, `startsWith`, `endsWith`, `linkedWith`, `notLinkedWith`, `isNull`, `isNotNull`, `between`, `today`, `currentMonth`.

Two more that matter when auditing data by Team — `linkedWith` / `isNotLinked` against the `teams` link:

```bash
# records belonging to a team
GET <base>/<Entity>?where[0][type]=linkedWith&where[0][attribute]=teams&where[0][value][]=<teamId>

# records with NO team at all — the ones every team-based sweep misses
GET <base>/<Entity>?where[0][type]=isNotLinked&where[0][attribute]=teams
```

With `maxSize=1`, the response's `total` gives the real count without fetching the records.

### `select` silently drops the `*Name` companions

`GET /<Entity>?select=name,assignedUserId` does **not** return `assignedUserName`. Code that prints that field gets an empty string for every record and reads as "nothing is assigned to anyone" — a false negative that can trigger a needless mass reassignment. Either select `assignedUserId` and resolve names separately, or drop `select` and take the full response.

## Lead conversion

There is **no single `convert` endpoint**. Lead conversion is an *orchestration* of the standard CRUD calls above. (An earlier version of this skill claimed `POST /Lead/<id>/convert` — that path is unverified and is not what any working tool uses. Don't rely on it.) Both the EspoCRM UI and the EspoMCP `convert_lead` tool do the same thing: create the target records, then flip the Lead's status to `Converted`.

### Via MCP (preferred)

`mcp__espocrm__convert_lead` — params:

| Param | Default | Notes |
|---|---|---|
| `leadId` | (required) | The Lead to convert |
| `createAccount` | `true` | Fires **only if the Lead has `accountName` set** — no accountName → no Account, silently |
| `createContact` | `true` | Creates a Contact from the Lead's person fields |
| `createOpportunity` | `false` | Fires **only if** an Account was created **and** `opportunityName` is provided |
| `opportunityName` | — | Required for the Opportunity to be created |
| `opportunityAmount` | — | Optional amount |

### The orchestration (verified call sequence)

What `convert_lead` actually does — replicate this if you go through raw REST instead of the MCP:

1. `GET <base>/Lead/<id>` — read the Lead.
2. **Account** (if `createAccount` and `lead.accountName`): `POST <base>/Account`
   `{ "name": lead.accountName, "website": ..., "industry": ..., "assignedUserId": ... }` → keep `account.id`.
3. **Contact** (if `createContact`): `POST <base>/Contact`
   `{ "firstName", "lastName", "emailAddress", "phoneNumber", "accountId": <from step 2>, "assignedUserId", "description" }`.
4. **Opportunity** (if `createOpportunity` and step 2 produced an account and `opportunityName`): `POST <base>/Opportunity`
   `{ "name": opportunityName, "accountId", "stage": "Prospecting", "amount": opportunityAmount, "closeDate": <today+30d>, "assignedUserId" }`.
5. `PUT <base>/Lead/<id>` with `{ "status": "Converted" }`.

### Gotchas

- **No `accountName` on the Lead → no Account, and therefore no Opportunity.** Step 2 is gated on `lead.accountName`; step 4 is gated on an account having been created. A Lead captured without an account name converts to a bare Contact only, even if you requested an Opportunity. Set `accountName` on the Lead first if you want the full chain.
- **The Contact ends up account-less** in that same case — step 3 runs with `accountId` undefined.
- **`stage` is hardcoded `Prospecting`** and `closeDate` defaults to **today + 30 days**. Fix afterward with `PUT <base>/Opportunity/<id>` if you need real values.
- **The Lead's native conversion links are NOT populated.** The tool only flips `status` to `Converted`; it does not link the new Account/Contact/Opportunity back onto the Lead. The new records exist and are cross-linked (Contact → Account), but the *Lead* won't show them in its native conversion panel. Set those links explicitly after conversion if you need them.

## Custom Entity management (admin auth required)

### Create entity

```http
POST <base>/EntityManager/action/createEntity
Content-Type: application/json

{
  "name": "Subscription",
  "type": "BasePlus",
  "labelSingular": "Subscription",
  "labelPlural": "Subscriptions",
  "stream": true,
  "sortBy": "createdAt",
  "sortDirection": "desc"
}
```

Returns `{"name": "CSubscription"}`. EspoCRM prefixes custom entity names with `C` automatically.

**The prefix is unconditional** — it is prepended even when the submitted name already starts with `C`. Sending `{"name": "CProbe"}` creates **`CCProbe`**, so `GET /CProbe` 404s and the entity looks like it was never created. Read the response's `name`, or list `GET /Metadata` → `scopes` filtered by `isCustom`, to find what actually landed; remove it with the doubled name (`POST /EntityManager/action/removeEntity {"name": "CCProbe"}`).

`type` options: `Base` (no stream), `BasePlus` (with stream — recommended), `Person` (firstName/lastName fields), `Company`, `Hierarchy`, `Event`.

WRONG endpoints (give 404 or are not registered):
- `POST <base>/Admin/entityManager/createEntity`
- `POST <base>/EntityManager` (path collides with record id `EntityManager`)

### Update entity metadata

```http
POST <base>/EntityManager/action/updateEntity
Content-Type: application/json

{
  "name": "CSubscription",
  "labelPlural": "Suscripciones"
}
```

### Remove entity

```http
POST <base>/EntityManager/action/removeEntity
Content-Type: application/json

{ "name": "CSubscription" }
```

## Custom Field management (admin auth required)

### Create or edit a field — PUT (idempotent)

POST gives **405**. The endpoint is PUT for both create and edit.

```http
PUT <base>/Admin/fieldManager/<scope>/<fieldName>
Content-Type: application/json
```

The path itself names the field; the body defines its shape.

**The PUT is a full replace, not a partial patch.** When editing an existing field (e.g. changing an enum's `options`), the body must carry the **complete** def — `type` included (and `isCustom: true` for custom fields). A partial body that omits `type` returns **500**. Safe pattern: `GET /Metadata?key=entityDefs.<Scope>.fields.<field>`, mutate the `options` array, PUT the whole object back, then `POST /Admin/rebuild`. See the 500 case in [common-errors.md](common-errors.md).

The body shapes below are full field defs:

**Enum:**
```json
{
  "type": "enum",
  "options": ["A", "B", "C"],
  "default": "A",
  "required": true,
  "audited": true,
  "style": {"A": "success", "B": "warning", "C": "danger"}
}
```

**Currency:**
```json
{ "type": "currency", "required": true, "default": 0, "audited": true }
```

**Date / DateTime:**
```json
{ "type": "date", "required": false }
{ "type": "datetime", "required": false }
```

**Varchar / Text:**
```json
{ "type": "varchar", "maxLength": 100, "required": true }
{ "type": "text", "required": false }
```

**Phone / Email:** these are special multi-value fields with their own data types.

### Delete a field

```http
DELETE <base>/Admin/fieldManager/<scope>/<fieldName>
```

Returns 200 on success. Field is gone immediately. **Field data on existing records is dropped** — make sure you don't need it before deleting.

## Link (relationship) management (admin auth required)

### Create a link

```http
POST <base>/EntityManager/action/createLink
Content-Type: application/json

{
  "entity": "CSubscription",
  "entityForeign": "Account",
  "link": "account",
  "linkForeign": "subscriptions",
  "label": "Account",
  "labelForeign": "Subscriptions",
  "linkType": "manyToOne"
}
```

`linkType` options: `manyToOne`, `oneToMany`, `manyToMany`, `oneToOne`.

For `manyToMany`, optionally include `linkMultipleField: true` and `linkMultipleFieldForeign: true` so the linked records show as multi-select widgets in the UI.

### Remove a link

```http
POST <base>/EntityManager/action/removeLink
Content-Type: application/json

{ "entity": "CSubscription", "link": "account" }
```

## Setting relationships on records

### Single (manyToOne / oneToOne)

Set the foreign key field directly in the record payload at create or update time.

```json
{ "name": "Service A — Customer X", "accountId": "<account_id>" }
```

The field is `<linkName>Id`. This works on `POST /CSubscription` and on `PUT /CSubscription/<id>`.

### Many-to-many — DO NOT use `subscriptionsIds: [...]` on PUT

Setting array link fields on a PUT to the parent record returns **403** for many-to-many relationships. Use the **relationship endpoint** instead, one POST per relation:

```http
POST <base>/CInvoice/<invoiceId>/subscriptions
Content-Type: application/json

{ "id": "<subscriptionId>" }
```

Or in one call with multiple ids:

```json
{ "ids": ["<id1>", "<id2>", "<id3>"] }
```

To unlink:

```http
DELETE <base>/CInvoice/<invoiceId>/subscriptions?id=<subscriptionId>
```

To list related records:

```http
GET <base>/CInvoice/<invoiceId>/subscriptions
```

This endpoint accepts the same `where[]`, `select`, `maxSize`, `offset` query params as the standard list endpoint.

## Layout management (admin auth required)

**Layouts control how fields are arranged in the UI** — list columns, detail panels, side panels, etc. Custom entities and custom fields **do NOT auto-add to layouts**. After creating, you must manually edit the layout or fields will be invisible from the UI even though they exist in the records.

**Atypical path** — the endpoint is `/<Entity>/layout/<name>`, NOT `/Layout/<Entity>/<name>`. The latter exists for `GET` only; for writes you have to use the entity-prefixed form.

### Read layout

```
GET <base>/Layout/<Entity>/<layoutName>
GET <base>/<Entity>/layout/<layoutName>
```

Both paths work for read.

### Write/replace layout

```
PUT <base>/<Entity>/layout/<layoutName>
```

`PUT` to `/Layout/<Entity>/<name>` returns 405. `POST` to either form returns 405 or 404. The only writing endpoint is `PUT` on the entity-prefixed form.

**It replaces, it does not merge, and there is no history.** The body you send becomes the layout for every user; an empty array `[]` is accepted with a 200 and silently blanks the layout. EspoCRM keeps no layout versioning, so the only recovery is `POST /Layout/action/resetToDefault {"scope": "<Entity>", "name": "<layoutName>"}`, which restores the **stock** layout — any hand-arrangement the operator had made is gone for good. **Read and stash the current layout before writing one**, and never aim a layout `PUT` at a real entity as a throwaway test (see the write-control warning in [roles-and-acl.md](roles-and-acl.md)).

### Layout names of interest

| name | Where it shows | Format |
|---|---|---|
| `list` | Records list view (columns) | Array of `{name, link?}` |
| `listSmall` | Related panels in other entities (when an Account shows its CSubscriptions, etc) | Same as list |
| `detail` | Full detail page main panel | Array of panels: `[{label, rows: [[cell, cell], ...]}]` |
| `detailSmall` | Quick-view modal (clicking a record from a related panel) | Same as detail |
| `edit` | Full edit page main panel | Same as detail |
| `editSmall` | **Edit modal** (the popup that opens when you click "Edit" on a row from a list, or from a dashlet). Without this, the modal only shows `name` and the user can't edit anything else from there. | Same as detail |
| `searchFilters` | Filter chips that appear by default next to search bar (the "Teams ▼ Status ▼" persistent filters) | Array of field names: `["teams","status","account"]` |
| `filters` | **Add Field dropdown options in the 3-dot kebab menu** next to the search bar. This is the list of fields the user can ADD as a temporary filter on the fly. Critical: **without this, the kebab menu is dead (cursor not-allowed) on custom entities.** | Array of field names |
| `mass` | Mass-update form (which fields can be bulk-updated) | Array of field names |
| `defaultSidePanel` | Side panel on detail page (Assigned User, Teams, etc.) | Array of `{name}` |

For a fresh custom entity to be **fully usable from the UI**, configure at minimum: `list` + `detail` + `detailSmall` + `edit` + `editSmall` + `filters` + `searchFilters`. Skipping `editSmall` makes Edit modals show only `name`. Skipping `detailSmall` makes quick-view modals empty. Skipping `filters` makes the kebab cursor "prohibited". Skipping `searchFilters` makes the filter chips disappear.
| `relationships` | Bottom-of-detail relationship panels | Array of link names |

### Detail layout body shape

```json
[
  {
    "label": "Overview",
    "rows": [
      [{"name": "name"}, {"name": "status"}],
      [{"name": "amount"}, false],
      [{"name": "description", "fullWidth": true}]
    ]
  }
]
```

- Each panel has `label` and `rows`
- Each row is an array of cells; each cell is `{"name": "<fieldName>"}` or `false` for empty
- `"fullWidth": true` makes the cell span both columns

### List layout body shape

```json
[
  {"name": "name", "link": true},
  {"name": "amount"},
  {"name": "createdAt"}
]
```

- Plain array of column definitions
- `"link": true` makes that column clickable (linking to the record's detail page)

### After changing layouts

Rebuild is recommended (sometimes layout cache lingers):

```
POST <base>/Admin/rebuild
```

And the user has to refresh the browser (F5) — the frontend caches the layout client-side.

## Full-text search across relationships — `textFilterFields`

The search bar at the top of every list view does full-text search over the fields listed in `textFilterFields`. By default this is just `["name"]`. To make typing a team name (or any related entity's name) match records, **add the link name** to `textFilterFields` for that entity.

### How to set

Use `EntityManager/action/updateEntity` with `textFilterFields` in the body:

```bash
<your-admin-helper> POST /EntityManager/action/updateEntity \
  '{"name":"CPayment","textFilterFields":["name","description","reference","teams.name"]}'
```

Works for both custom entities (`CPayment`, etc.) and native ones (`Account`, `Contact`, `Lead`).

**Use dot notation for relationship traversal** — `"teams.name"`, NOT just `"teams"`. The bare link name (`"teams"`) returns HTTP 200 from the metadata write but **crashes the search SQL with HTTP 500 ("Internal server error")** when the user types into the search bar. The dot-notation form `"teams.name"` works cleanly. Same applies to any relationship: `accounts.name`, `assignedUser.userName`, etc.

### Where it gets stored (gotcha)

Returns HTTP 200 but `GET /Metadata?key=entityDefs.<X>.textFilterFields` returns empty. This is misleading — the value DOES persist, just at a different metadata path. Look here instead:

```bash
GET /Metadata?key=entityDefs.<X>.collection.textFilterFields
```

The actual storage is `entityDefs.<X>.collection.textFilterFields`, not the top-level `entityDefs.<X>.textFilterFields`. Both paths are part of the legitimate metadata tree but only the `.collection.` one is used by the search engine.

### After changes

`POST /Admin/rebuild` and refresh browser (F5). Cache lingers otherwise.

### `searchFilters` vs `textFilterFields` — distinction

These do different things and you usually want both:

- **`textFilterFields`**: defines which fields the search bar's full-text search looks into. Typing "solutions" in the box triggers a search across these fields.
- **`searchFilters`**: defines which fields appear as **dropdown filter buttons** next to the search bar. Click → pick from a list (e.g. "Teams ▼" → "<some team>").

Both are configured per entity. `searchFilters` via `PUT /<Entity>/layout/searchFilters` with array of field names. `textFilterFields` via `EntityManager/action/updateEntity` as above.

## Primary filters for the Records dashlet (filesystem edit on container)

The `Records` dashlet ignores `searchData.advanced` and arbitrary `where` clauses. Verified in `client/src/views/dashlets/records.js` — its `getSearchData()` only reads `primaryFilter` and `boolFilterList` from `getOption()`. Anything else is silently dropped.

To filter a dashlet you must define a **named primary filter** pre-registered in metadata, which requires three artifacts:

1. PHP class implementing `Espo\Core\Select\Primary\Filter`
2. `selectDefs/<Entity>.json` mapping the filter name to the PHP class FQN
3. `clientDefs/<Entity>.json` with a `filterList` entry

**None settable via REST API.** They live in PHP and JSON files inside the container's `custom/Espo/Custom/` tree, then a `clear-cache + rebuild` pass.

### Templates ready to apply

The skill ships ready-to-use templates at:

```
scripts/primary-filter-templates/
├── README.md                              ← apply instructions + gotchas
├── paths.txt                              ← where each file goes
├── apply.sh                               ← chown + clear-cache + rebuild
├── InDevelopment.php                      ← simple equals filter (CSubscription)
├── Pending.php                            ← in-list filter (CInvoice)
├── InProcess.php                          ← string with space (Lead)
├── selectDefs-CSubscription.json          ← merge into selectDefs/CSubscription.json
├── clientDefs-CSubscription.json          ← merge into clientDefs/CSubscription.json
├── clientDefs-Lead.json                   ← native entity warning, full filterList override
```

To use: copy a template, adapt entity/filter/where, place files in the container at the paths in `paths.txt`, run `apply.sh <container>`. Then update the dashlet's `Preferences.dashletsOptions.<dashletId>.primaryFilter` to the filter name (this part IS via REST API).

### Style values for filterList entries

`info` (blue), `success` (green), `warning` (yellow), `danger` (red), `default` (gray). Same palette as enum field styles.

### `filterList` merge gotcha for native entities

For **custom entities** (CSubscription, CInvoice, CPayment): `clientDefs.filterList` in the custom file merges into whatever didn't exist before. Adding new entries works.

For **native entities** (Lead, Account, Contact, Opportunity): `clientDefs.filterList` is **replaced wholesale, NOT merged**. Custom file MUST relist the native filters or they disappear from the UI. Lead has `actual` and `converted` natively — see `clientDefs-Lead.json` template for the correct full-list override.

`selectDefs.<X>.primaryFilterClassNameMap` does merge per-key — you can add new map entries without relisting native ones. Only the `clientDefs.filterList` array is whole-replaced.

### Records dashlet does NOT accept ad-hoc where clauses

Re-emphasis (this cost a session detour): putting `searchData.advanced` or `where` keys into `dashletsOptions` does nothing. The Records dashlet's `getSearchData()` builds search data fresh from only `primaryFilter` + `boolFilterList`. No JSON-only shortcut exists; the named-filter PHP path is required.

## Schema rebuild

After custom entity / field / link changes, rebuild so the changes register:

```http
POST <base>/Admin/rebuild
Content-Type: application/json

{}
```

Returns 200. Side effect: clears caches, regenerates ORM mappers.

## Phone number format

Required prefix: `+<country code>`. Without it, **400 validationFailure phoneNumber valid**.

```json
{ "phoneNumber": "+52 555 123 4567" }
```

Spaces are accepted; the backend normalizes to `+525551234567`.

When stored, the field becomes a phoneNumberData array with a `type` (Mobile/Office/etc.) and `primary` flag. To set multiple numbers explicitly:

```json
{
  "phoneNumberData": [
    {"phoneNumber": "+52 555 123 4567", "type": "Mobile", "primary": true},
    {"phoneNumber": "+52 555 765 4321", "type": "Office", "primary": false}
  ]
}
```

## Date / DateTime format

- `date` field: `YYYY-MM-DD`. Example: `"startDate": "2026-01-15"`.
- `datetime` field: `YYYY-MM-DD HH:mm:ss` (server timezone). Example: `"dateStart": "2026-01-15 16:00:00"`. ISO `T` format also works in most cases but the EspoMCP MCP rejects the space form via Zod, so prefer `T` when going through MCP and space when going through curl/admin script.

## Currency fields

Each currency field expands to three columns:
- `<field>` — numeric amount
- `<field>Currency` — ISO code (default for the instance is `MXN`)
- `<field>Converted` — auto-calculated against base currency rates

When creating, set both `<field>` and `<field>Currency`:

```json
{ "monthlyAmount": 900, "monthlyAmountCurrency": "MXN" }
```

## Quick reference: useful read-only endpoints

| Path | Returns |
|---|---|
| `GET /App/user` | Current user profile + ACL + settings + appParams. Useful first call to verify auth and discover instance config. |
| `GET /Settings` | Global settings (admin only). |
| `GET /Metadata?key=entityDefs.<Entity>.fields` | All fields of an entity, with their definitions. |
| `GET /Metadata?key=entityDefs.<Entity>.fields.<field>` | Just one field's definition. |
| `GET /Metadata?key=entityDefs.<Entity>.links` | All relationships of an entity. |
| `GET /<Entity>?maxSize=0` | Just the `total` count, no records. |
