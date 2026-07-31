# Running more than one instance — cloning, and living with the result

Splitting one shared EspoCRM into two (per company, per brand, per business unit) is a clone-then-prune operation, not a rebuild. This file covers the clone itself and the operational hazards that follow it.

## Cloning: where the database config actually lives

**The trap that can corrupt data.** The database connection is **NOT** in `data/config.php` — it is in **`data/config-internal.php`**:

```php
'database' => [
  'host' => '<postgres-host>',
  'port' => '5432',
  'dbname' => 'espocrm',
  'user' => 'espocrm',
  'password' => '<db-password>',
  'platform' => 'Postgresql'
],
```

And the container's `ESPOCRM_DATABASE_*` env vars are **only read during installation**. An already-installed instance ignores them completely. Consequence: copy the data dir into a new container and change only the env vars, and **the clone connects to the original's database — two EspoCRM instances writing the same DB.**

`config-internal.php` must be edited by hand, before the first boot.

**Recommended safety net:** put each instance on its own container network with `isolate=true` (podman/docker). Then the clone *physically cannot* reach the original's database, by DNS or by IP — verifiable with `nc -z <original-postgres-ip> 5432` from a throwaway container on the new network. With that in place, the worst case of a mis-written config is that the clone won't start, not that it corrupts the original.

## The entrypoint decision tree — read it before booting a clone

`docker-entrypoint.sh`, function `start()`:

```
if data/config.php exists:
    isInstalled = getConfigParamFromFile("isInstalled")   # state.php -> config-internal.php -> config.php
    if isInstalled == 1:
        if ESPOCRM_VERSION (image) > installed version:  -> actionUpgrade
        else:                                            -> return, no-op    <-- what you want
    -> actionReinstall     <-- DESTRUCTIVE
-> actionInstall
```

Before starting a clone, confirm both keys:

- `isInstalled => true` lives in **`data/config-internal.php`**
- `version` is read from **`data/state.php`** (not from config.php)

If `state.php`'s `version` equals the image's `ESPOCRM_VERSION`, the boot is a no-op. If `isInstalled` is missing, it **reinstalls** — wiping the instance.

## What actually changes in the clone

In `config.php` (identity):
`siteUrl`, `outboundEmailFromAddress`, `outboundEmailFromName`, `smtpUsername`.

In `config-internal.php` (connection):
`database.host`, `database.password`.

Careful with `sed` and nested quotes: a pattern like `'password' => .*` can match more than one line of the file. Safer is a small Python pass with `re.subn` that reports how many replacements it made per key, followed by a `diff` against the backup copy to confirm that **only** the intended lines changed.

`smtpPassword` is encrypted with `cryptKey` — never hand-write it. Set it with `PUT /Settings {"smtpPassword": "<value>"}` once the instance is up and EspoCRM encrypts it.

After copying the data dir: delete `data/cache/` so the clone rebuilds it.

## Migrating data: clone and prune, don't rebuild

Recreating custom entities, fields, links, layouts and roles by hand is where the time goes and where the mistakes happen. The short path:

1. `pg_dump -Fc` the original → restore into the new database
2. copy the whole data dir
3. fix `config-internal.php`
4. boot and verify
5. **delete from each instance what doesn't belong to it** — via the API, which is a soft delete and therefore reversible

Schema, layouts, roles, custom fields and users all survive untouched. Credentials survive too: see the hash-portability note in [forensics.md](forensics.md).

For backing up before any of this, see [backup-and-restore.md](backup-and-restore.md).

## Pruning by Team — the records with no team are the ones that bite

Step 5 of the clone-and-prune flow is a purge driven by `teams`. Listing syntax (both directions) is in [api-endpoints.md](api-endpoints.md) → "Querying with `where[]`".

**The finding**: a purge that walks the entities deleting "everything tagged team X" leaves behind **everything that has no team at all**, and those records are not noise. In one split, the untagged leftovers were few — and *each one belonged to the opposite side from the instance it was sitting in*: a payment from one company's customer living in the other company's instance, and a lead whose description named the partner on the other side as the one handling the negotiation.

Neither could be classified from metadata. Both were resolved by **reading the record** — its name, its description, which account it hangs off. The description almost always says whose it is.

Procedure: after the team-based purge, sweep `isNotLinked teams` across **every** entity, print each record in full, and decide one at a time. Tag them while you're there, so they aren't orphans again at the next split.

### Guards worth building into the purge script

1. **Confirm the target before deleting anything.** Read `GET /Settings` and abort if `siteUrl` isn't exactly the expected instance. A purge script pointed at the wrong instance is catastrophic — see the section below.
2. **Dry-run by default, `--apply` explicit.** Print what would go and how many, before touching anything.
3. **Skip what also belongs to the other side.** If a record carries both the team being deleted *and* a team being kept, it's shared: don't delete it, report it. It may well come back zero — the guard is what lets you say so with evidence instead of assuming.
4. **Delete via the API, not SQL.** `DELETE /<Entity>/<id>` is a soft delete (`deleted = true`) — reversible, and it lets EspoCRM handle the relationships.
5. **Before deleting a Team**, move the `defaultTeamId` of any user pointing at it, or you leave dangling references.

### Deactivating a user leaves records with no live owner

Deactivate someone and everything assigned to them keeps pointing at an account that can no longer sign in. Nothing warns you. After any deactivation, sweep:

```
GET /<Entity>?maxSize=200   →  filter assignedUserId == <deactivated user> or empty
```

and reassign. In one observed case this left 18 orphaned records (accounts, contacts, subscriptions, invoices, payments and tasks — an entire book of business) with nothing reporting it.

Reading the owner has its own trap: `select` drops the `*Name` companion fields. See [api-endpoints.md](api-endpoints.md) → "`select` silently drops the `*Name` companions".

## After the split: the default instance must be explicit

**The failure mode.** `ESPOCRM_URL` — consumed by the admin helper, by scripts, and by the MCP server — points at *one* instance. After a split, the one left as default may be the one that no longer holds your data. Then:

```
GET /Account?maxSize=1   →  200 OK,  total: 0
```

**A 200 with `total: 0` is indistinguishable from "there are no customers".** No error, no 403, nothing that reveals you are reading the wrong CRM. A fresh agent session can report "the CRM has no customers" with total confidence while talking to a different database.

Worse, the **same API key works on both instances** — users are cloned along with the database, so credentials give away nothing:

```
same key → instance A:  total 5
same key → instance B:  total 0
```

### The rule

**Before drawing any conclusion about what exists or is missing in the CRM, confirm which instance you are talking to.** It costs one request:

```
GET /api/v1/Settings   →  siteUrl field
```

If `siteUrl` is not the instance you assumed, everything you just read does not apply. And when a count comes back zero where you expected data, **the first hypothesis is the wrong instance, not data loss.**

### Making it survive fresh sessions

1. **The default points at the day-to-day instance**, not the historical one. Documentation alone does not prevent the mistake — the variable is what decides.
2. **Every other instance is explicit opt-in** via its own variable (`ESPOCRM_URL_<WHATEVER>`), prefixed onto the command:
   `ESPOCRM_URL=$ESPOCRM_URL_OTHER <helper> GET /Account`
3. **Every destructive script opens with a `siteUrl` guard** and aborts if it doesn't match the expected target. Without it, a purge script aimed at the wrong instance deletes the wrong data.
4. **Watch the token cache.** A helper that caches its token at a fixed path shares it across instances; tokens are per-instance, so alternating forces a re-login (401 → retry). It works, but it isn't free — cache per host instead.
5. **The MCP server inherits the variable from the login environment.** Changing it in a running shell does not reconfigure an already-running MCP: it takes effect next session. Until then the MCP keeps talking to the old instance while the shell talks to the new one — a silent divergence between two tools inside the same session.

**Credentials after a split**: password hashes, API keys, roles and record IDs are **identical** on both instances when one was cloned from the other. Convenient (everyone can sign in to both) and dangerous (nothing in the credential tells you which one you reached). The only reliable discriminator is `siteUrl`.
