# Primary filter templates for the Records dashlet

Use these when you need to filter a `Records` dashlet by a status or any custom where clause.

The Records dashlet's `getSearchData()` only reads `primaryFilter` and `boolFilterList`. To define a new named primary filter you need three files (PHP class + 2 JSON metadata files) in the espoCRM container's `custom/Espo/Custom/` tree, then a rebuild. None of this is settable via REST API; the files have to land on the filesystem of the host running the container.

## How to apply

1. Open this folder. You will see template files for a single filter (`InDevelopment` on `CSubscription`). Adapt them to your case by changing:
   - The entity name (`CSubscription` → your entity)
   - The filter name (`InDevelopment` → `Pending`, `InProcess`, etc.)
   - The where clause inside `apply()`
   - The `style` in `clientDefs.filterList` (`info` / `success` / `warning` / `danger`)
2. SSH to the server running the espoCRM container.
3. Place the files at the paths documented in `paths.txt` of this folder.
4. Run the post-edit commands listed in `apply.sh`.
5. Update the user's `Preferences.dashletsOptions.<dashletId>.primaryFilter` to the filter name (this part you can do via REST API).
6. User does Ctrl+Shift+R in browser.

## Important gotchas

- **Native entity filterList is REPLACED, not merged**. If you add a custom filter to a native entity (Lead, Account, Contact, Opportunity), the `clientDefs.filterList` in your custom file must list the native filters too, or they disappear. `selectDefs.primaryFilterClassNameMap` does merge per-key. See the `clientDefs-Lead.json` template in this folder for the correct full-list override.
- **Custom entity filterList is a fresh override**. For CSubscription/CInvoice/CPayment, the file may not exist yet, so you create the full `filterList` array.
- **PSR-4 autoload**: `Espo\Custom\Classes\` maps to `custom/Espo/Custom/Classes/`. The directory structure must match the namespace exactly.
- **Permissions after editing**: `chown -R www-data:www-data /var/www/html/custom/` — otherwise PHP can't read the new files.
- **Rebuild and clear-cache**: both required. `rebuild` regenerates schema mappings; `clear-cache` flushes the metadata cache.

## Verified working

This template pattern was tested on espoCRM v9.3.6, defining three filters as examples:

- A custom recurring-service entity → `inDevelopment` (status = "InDevelopment")
- A custom invoice entity → `pending` (status in PartiallyPaid, Sent, Draft)
- Lead (native) → `inProcess` (status = "In Process")

After rebuild, dashboards using these primary filter names produced filtered counts as expected.
