# Checklist: creating a new custom entity

When creating a new custom entity (e.g. `CPolicy`, `CProperty`, `CCommission`), do **all** of these in order. Skipping any of them leaves UI points broken — and the user discovers it the hard way.

## 1. Create the entity

```bash
<your-admin-helper> POST /EntityManager/action/createEntity \
  '{"name":"YourEntity","type":"BasePlus","labelSingular":"...","labelPlural":"...","stream":true,"sortBy":"createdAt","sortDirection":"desc"}'
```

Note: name gets `C` prefix automatically (`YourEntity` → `CYourEntity`).

## 2. Define fields

```bash
# Per field:
<your-admin-helper> PUT /Admin/fieldManager/CYourEntity/<fieldName> \
  '{"type":"<type>","required":<bool>,...}'
```

PUT (not POST) for creation. Idempotent.

**Set `maxLength` explicitly on every `varchar` you expect to hold more than a short code.** The default is `maxLength: 100`, and an over-long value doesn't truncate — it rejects the whole record with `validationFailure` (see `reference/common-errors.md`). Decide the limit here; discovering it when the first real record fails costs a field edit plus another rebuild. Long free text belongs in a `text` field (`description`), not a wide varchar.

## 3. Define links to other entities

```bash
<your-admin-helper> POST /EntityManager/action/createLink \
  '{"entity":"CYourEntity","entityForeign":"Account","link":"account","linkForeign":"yourEntities","linkType":"manyToOne"}'
```

## 4. **Update the role's scope** so the api user can CRUD on it

This is critical and easy to forget. Without it, the api user gets 403 on any record operation.

```bash
ROLE_ID="<your-role-id>"
ROLE=$(<your-admin-helper> GET /Role/$ROLE_ID)
PAYLOAD=$(echo "$ROLE" | python3 -c "
import sys, json
d = json.load(sys.stdin)
data = d.get('data', {})
data['CYourEntity'] = {'read':'all','edit':'all','create':'yes','delete':'all'}
print(json.dumps({'data': data}))
")
<your-admin-helper> PUT /Role/$ROLE_ID "$PAYLOAD"
```

## 5. Configure ALL the layouts (the part that's most often forgotten)

Custom entities need at minimum 7 layouts populated. Skipping any leaves a UI surface broken:

| Layout | Where it shows | What breaks if missing |
|---|---|---|
| `list` | Records list page (columns) | Lists show only `name` |
| `listSmall` | Related panels (e.g. CSubscriptions on Account) | Related panels show only `name` |
| `detail` | Full detail page main panel | Detail page shows only `name` |
| `detailSmall` | Quick-view modal | Quick view modal empty |
| `edit` | Full edit page | Edit page shows only `name` |
| `editSmall` | **Edit modal popup** | Edit popup shows only `name` (cliked from list rows or dashlets) |
| `filters` | "Add Field" dropdown in the kebab `⋮` next to search bar | **Cursor `not-allowed`** on the kebab |
| `searchFilters` | Filter chips next to search bar | No filter chips visible |
| `mass` | Mass-update form | Mass-update only allows updating `name` |

For each, PUT to `/<Entity>/layout/<layoutName>` with the appropriate body shape (see `reference/api-endpoints.md` for shape details).

```bash
# Standard 7-layout setup pattern (adjust field names for your entity):
DETAIL_LIKE='[{"label":"Overview","rows":[
  [{"name":"name"},{"name":"status"}],
  [{"name":"<field2>"},{"name":"<field3>"}],
  [{"name":"description","fullWidth":true}]
]}]'

LIST_LIKE='[
  {"name":"name","link":true},
  {"name":"<field2>"},
  {"name":"<field3>"},
  {"name":"status"}
]'

FILTERS_LIKE='["name","status","<field2>","assignedUser","teams","createdAt","createdBy","modifiedAt","modifiedBy"]'

SEARCHFILTERS_LIKE='["status","<field2>","teams"]'

# Apply the same shape to detail family
for L in detail detailSmall edit editSmall; do
  <your-admin-helper> PUT /CYourEntity/layout/$L "$DETAIL_LIKE"
done

# List shapes
for L in list listSmall; do
  <your-admin-helper> PUT /CYourEntity/layout/$L "$LIST_LIKE"
done

# Filter shapes
<your-admin-helper> PUT /CYourEntity/layout/filters "$FILTERS_LIKE"
<your-admin-helper> PUT /CYourEntity/layout/searchFilters "$SEARCHFILTERS_LIKE"

# Mass update — usually a subset of editable fields
<your-admin-helper> PUT /CYourEntity/layout/mass '["status","assignedUser","teams"]'
```

## 6. Configure visual identity (icon + color)

```bash
<your-admin-helper> POST /EntityManager/action/updateEntity \
  '{"name":"CYourEntity","iconClass":"fas fa-<name>","color":"#<hex>"}'
```

Color palette matching native entities (pastel): `#edc755` (yellow), `#a4c5e0` (blue), `#d6a2c9` (pink), `#9fc77e` (green), `#a3b8d8` (lavender), `#e8c082` (peach), `#82d6b0` (mint).

## 7. Configure full-text search

Without this, searching by team name doesn't work even after setting up `searchFilters`. Use **dot notation** for relationship fields, NOT bare relationship names:

```bash
<your-admin-helper> POST /EntityManager/action/updateEntity \
  '{"name":"CYourEntity","textFilterFields":["name","description","teams.name"]}'
```

(`teams.name` not `teams` — bare causes 500 errors at search time.)

## 8. Add to sidebar tab list (if it should be visible)

Modify `Settings.tabList` to include the entity in a logical position:

```bash
# Read current tabList
SETTINGS=$(<your-admin-helper> GET /Settings)
# Compute new tabList with the entity inserted at the right position
# (use python to manipulate the array — see existing tabList for shape)
<your-admin-helper> PUT /Settings '{"tabList": [...new array...]}'
```

## 9. (Optional but recommended) Define primary filters via SSH

If the entity needs filterable dashlets or pre-defined filter chips, see `scripts/primary-filter-templates/`. Requires SSH to the container.

## 10. Rebuild + clearCache

```bash
<your-admin-helper> POST /Admin/rebuild '{}'
<your-admin-helper> POST /Admin/action/clearCache '{}'
```

## 11. User does Ctrl+Shift+R in browser

The frontend caches metadata aggressively. Hard refresh, not just F5.

## 12. Verify visually

- Open the entity from the sidebar — list shows ✓
- Click a record — detail page shows all configured fields ✓
- Click Edit on a row — modal shows all fields ✓
- Click the kebab `⋮` next to search — dropdown opens (no `not-allowed` cursor) ✓
- Type a team name in search — records filter ✓
- Click on the entity from a related panel of another entity — quick view shows ✓

If any of these is broken, the corresponding layout is missing. Re-apply.

---

## Why this exists

Original sin: when CSubscription/CInvoice/CPayment were created, only `list` + `detail` layouts were configured. The other ~5 layouts were left empty. Each missing layout was discovered the hard way over the course of a session — first the kebab cursor, then the edit modal showing only `name`, then the quick view, etc. The fix took multiple back-and-forth rounds with the user.

This checklist exists so future custom entity creations don't repeat that pain.
