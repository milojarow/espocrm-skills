# Forensics — attributing "who/what created or deleted this record, and when?"

Answerable in minutes by combining two sources that already exist — no extra tooling.

## 1. `AuthLogRecord` (admin) — who authenticated

```
GET /api/v1/AuthLogRecord?maxSize=N&orderBy=createdAt&order=desc
```

Each authentication carries `username`, `createdAt`, `ipAddress`, and `isDenied`. This separates actors at a glance: the backend's api-user, an MCP running on another machine, the human admin. Internal namespace IPs give away the origin (a container on the same network vs the host/gateway).

## 2. Container webserver access log — what request, from which client

```
podman logs <espocrm-container> --since <UTC> --until <UTC>
```

Each request shows method, path, query, status, and **User-Agent** — and the UA discriminates the real client:

- `Bun/x.y.z` → the backend
- `axios/x.y` → an MCP/pipeline
- `curl/x.y` → a human or a script

Here you see the exact `POST /api/v1/Lead`, the `DELETE /api/v1/Lead/<id>` of a cleanup, or the dedup-check (`GET ...where[0][attribute]=emailAddress...`) that precedes an upsert.

## Correlation gotcha — anchor the timezone first

The access log prints time with the **container's local offset** (e.g. `[dd/Mon/yyyy:HH:MM:SS -0500]`), while `podman logs --since/--until` interprets its arguments as **UTC**. Anchor the TZ before you build a window — `podman exec <container> date` — or the windows are shifted by the offset and you "find nothing" that was actually there.

## Retention window — an empty auth log does NOT prove "this user never signed in"

`auth_log_record` is purged periodically by the cleanup job. Reading **0 successes and 0 denials** for a user and concluding "that account was never used" is an invalid inference: the login you're looking for may simply predate the window.

Cleanup parameters (in `data/config.php`, with stock defaults):

```
'cleanupJobPeriod'           => '1 month'
'cleanupActionHistoryPeriod' => '15 days'
'cleanupAuthTokenPeriod'     => '1 month'
'cleanupAuditPeriod'         => '3 months'
```

Before asserting "never", bound the window first:

```sql
SELECT MIN(created_at), MAX(created_at), COUNT(*) FROM auth_log_record;
```

If the range does not cover the account's lifetime, the honest answer is **"unknown"**, not "never". Observed case: the log's full range began weeks *after* the login under investigation — the evidence had already been cleaned up.

## `auth_token` is the trace that persists — use it as positive evidence

Rows for still-active tokens survive the log cleanup, and they prove in the positive who holds a live session and since when:

```sql
SELECT t.created_at, t.last_access, t.is_active, t.ip_address
FROM auth_token t JOIN "user" u ON u.id = t.user_id
WHERE u.user_name = '<username>' ORDER BY t.last_access DESC;
```

This answers "has this account been in use?" instantly, where the auth log can only fail to answer it. A token created months back with a recent `last_access` settles the question.

### Corollary that bites when migrating users to a new domain

With `authTokenLifetime = 0` and `authTokenMaxIdleTime = 0` (non-expiring tokens — a common setting on internal instances), a user can go **months signing in without ever typing their password**. Moving them to a new host then breaks for two reasons at once:

- Browsers store passwords **per origin** — theirs is filed under the old domain and won't autofill on the new one.
- Session cookies don't cross domains either, so the cloned token is useless there.

**Plan a password reset as part of any domain migration.** Don't assume users remember a credential they haven't typed in months. If they want the same password on both sides, the place to recover it from is the browser's password manager, stored under the old host.

## Password hashes are portable between cloned instances

Cloning by itself never requires a password reset. EspoCRM 9.x verifies with `password_verify()` against self-contained bcrypt hashes (`$2y$`, 60 chars). As long as `hashSecretKey`, `cryptKey` and `passwordSalt` from `config-internal.php` are copied verbatim, the same password works on both instances.

Verifiable without knowing the password — compare the hash digest on both databases:

```sql
SELECT md5(password), is_active, type FROM "user" WHERE user_name = '<username>';
```

If the digests match, the credential is identical on both sides. So when a user fails on one instance and succeeds on the other with matching hashes, **the clone is not the problem** — what they're typing, or where they're typing it, is. See [multi-instance.md](multi-instance.md).

## Complementary note

The native assignment email leaves the daemon ~6–8 s after the record POST — so when correlating with mail logs, the send shows up seconds after the create, not minutes. See [notifications.md](notifications.md).
