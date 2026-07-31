# Backup and restore — most of the database is cron bookkeeping

Verified on EspoCRM 9.3.x over PostgreSQL 15.

## Where the size goes

An instance holding a few dozen business records can weigh hundreds of megabytes. A representative breakdown of a 730 MB database with ~60 business records:

```
job                        358 MB   (1,084,634 rows)
scheduled_job_log_record   351 MB
email                      1.2 MB
auth_log_record            800 kB
note                       448 kB
...everything else, kilobytes
```

`job` and `scheduled_job_log_record` are the cron daemon's internal bookkeeping. They contain nothing of the business. EspoCRM prunes them per `cleanupJobPeriod` (default `1 month`), which is plainly not enough on an instance with an active daemon.

Check any instance with:

```sql
SELECT relname, pg_size_pretty(pg_total_relation_size(relid))
FROM pg_catalog.pg_statio_user_tables
ORDER BY pg_total_relation_size(relid) DESC LIMIT 10;
```

## The useful dump

Exclude the **data** (not the schema) of those tables. On restore the daemon repopulates `job` from the definitions in `scheduled_job`, which *is* backed up.

```bash
pg_dump -U espocrm -d espocrm -Fc -Z6 \
  --exclude-table-data=job \
  --exclude-table-data=scheduled_job_log_record \
  --exclude-table-data=action_history_record \
  --exclude-table-data=auth_log_record \
  --exclude-table-data=auth_token \
  > espocrm.dump
```

Measured effect on the instance above: **47 MB → 688 KB** compressed. Restored into a clean database: zero errors, every business table with an identical row count.

Note: excluding `auth_token` means everyone has to sign in with a password after a restore. That is the right call for a backup, but know it going in — and see the note in [forensics.md](forensics.md) about users who haven't typed their password in months.

## Attachments are not in the database

They live in `data/upload/` inside the data dir. A DB-only backup leaves the files behind and the records pointing at nothing. Mirror that directory (`rsync -a --delete`) alongside the dump.

Back up `data/config.php` and `data/config-internal.php` too — the latter carries `cryptKey` and `hashSecretKey`, without which stored passwords and encrypted fields are unusable. Keep it `chmod 600`.

## A dump you never tested is not a backup

Two levels of verification.

**Cheap, inside the backup script** — throw the file away if it can't even be read:

```bash
pg_restore --list < espocrm.dump >/dev/null || { rm -f espocrm.dump; exit 1; }
```

(This closes the pipe early and makes `podman exec` emit `Broken pipe` warnings into the journal. Noise, not failure.)

**Real, before any migration** — restore into a disposable database and compare row counts table by table against the live one:

```sql
CREATE DATABASE verifytest OWNER espocrm;
-- pg_restore -d verifytest ...
SELECT count(*) FROM account;  -- etc., against both databases
```

Confusing detail: `job` will **never** match, because cron writes to it every second. Any other difference is a real signal.

Creating the test database needs a role with `CREATEDB`. The `espocrm` role normally does **not** have it; the superuser is usually whatever `POSTGRES_USER` the container was initialized with, which may not be named `postgres`:

```sql
SELECT rolname, rolsuper, rolcreatedb FROM pg_roles WHERE rolcanlogin;
```
