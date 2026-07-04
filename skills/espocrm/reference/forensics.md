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

## Complementary note

The native assignment email leaves the daemon ~6–8 s after the record POST — so when correlating with mail logs, the send shows up seconds after the create, not minutes. See [notifications.md](notifications.md).
