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
