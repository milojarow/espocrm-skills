---
name: espocrm
description: EspoCRM 9.x patterns for self-hosted instances. Use when interacting with EspoCRM via REST API or an MCP server — creating or updating Accounts/Contacts/Leads/Opportunities and custom entities, modifying schema (custom fields, links, layouts, roles, teams), authenticating to the API (choosing between api-user X-Api-Key and admin Basic Auth + Token), building queries with the where[] syntax, configuring dashlets and primary filters, or troubleshooting validationFailure / 403 / 404 / 405 responses. Not for other CRMs (HubSpot, Salesforce, Zoho), raw SQL against the EspoCRM database, or generic REST work unrelated to EspoCRM.
---

# EspoCRM — operating manual

> **💼 ACTIVE-SKILL MARKER:** Prefix your reply with 💼 **only on turns where the work touches the `espocrm` domain** — EspoCRM via REST API or MCP, entity modeling, `where[]` queries, `validationFailure`/403/404 troubleshooting — regardless of the layer/project (frontend, backend, a local script — all count); what matters is whether *this turn* touches the domain. On turns that do NOT touch it (typecheck, build, deploy, git ops, editing or curl in other domains), **omit 💼** even if the skill loaded earlier in the session. If other active skills also apply to the same turn, **stack their emojis** in the prefix.

You are operating against a self-hosted EspoCRM **9.x** instance. This skill exists so you don't repeat discovery walls that have been mapped already.

## Two auth paths — match the path to the work

| Use this | When you are doing | How |
|---|---|---|
| **`X-Api-Key`** (api user) via the **EspoMCP MCP server** | Day-to-day CRUD on records: search, create, update, delete Accounts/Contacts/Leads/Opportunities/Tasks/Calls/Meetings/Cases and custom entities. The 47 MCP tools cover this. | `mcp__espocrm__*` tools, or `curl -H "X-Api-Key: $ESPOCRM_API_KEY"` |
| **`Espo-Authorization: Basic <user:tokenOrPassword>`** (admin user) via the helper script | Schema/admin work: create Teams, edit Roles, create or remove custom entities, create or remove custom fields, modify layouts, Settings, Metadata. The api user has 403 on these by design. | An admin auth helper script (typically using login + token cache + retry on 401) |

Detail: [reference/auth-patterns.md](reference/auth-patterns.md).

### HARD RULE — never create or update records via admin auth

When you create or update **records** (Account, Contact, Lead, Opportunity, Meeting, Call, Task, Case, custom entities), **always use the api user via MCP / X-Api-Key**. Never the admin user via the helper script, even when faster or more convenient.

Why this matters — and the failure mode you'll repeat if you ignore it:

- `createdBy` is the api user, which is consistent attribution across the system. Records created by the admin show up as the admin user and look orphaned from operational reports.
- The `Stream` of every user (the home page activity feed) depends on `assignedUser` + `followers` + `createdBy`. Records made by admin without explicit `assignedUser` end up invisible to operational users' streams.
- Workflows, triggers, and audit logs that depend on `createdBy` / `modifiedBy` won't fire correctly when admin is the actor.
- The api user is the "service account" that does the day-to-day work. Bypassing it pollutes the audit trail.

The admin path is **only for schema and structure**: creating Teams, editing Roles, creating/removing custom entities, custom fields, link management, layouts, settings, metadata. It is **not** for record CRUD even when the api user has not yet been granted scope on a new custom entity. In that case, **add the scope to the role first**, then create records via api user. Don't take the admin shortcut.

Common cause of this regression: when a new custom entity is created, the existing role does NOT auto-update to include scope on it. The api user gets 403 on records of the new entity. Tempting to fall back to admin auth for the initial seeding — don't. Update the role first.

## Recommended modeling decisions

These are defaults that have proven sound across multiple use cases. Re-litigate only with reason:

- **Multi-tenancy is via Teams**, not via a custom enum on every entity. Teams are native, multi-valued (a record can belong to several teams at once), hierarchical, with built-in ACL semantics. Avoid creating a custom `cBusinessUnit` enum or similar — Teams already do this job better.
- **Recurring services live in a custom entity** (e.g. `CSubscription`), not in `Opportunity`. Opportunity is for the sales pipeline (prospecting → closed won/lost). Once a deal is signed and you're delivering recurring service, that becomes a Subscription with its own status (Active / Paused / Cancelled / InDevelopment).
- **Monthly billing aggregates** can use a custom entity like `CInvoice` that links many Subscriptions. When an invoice covers multiple Accounts (e.g. a customer with multiple businesses), leave `accountId` null and reconstruct via the linked subscriptions.
- **Each cash event is one Payment record** (custom entity, e.g. `CPayment`), linked to one Invoice. Fragmented payments produce multiple Payments against the same Invoice.
- **Expenses do NOT live in EspoCRM**. CRM is customer-facing data. Expenses, ledger, P&L belong in dedicated accounting tools (Firefly III, Akaunting, etc.). Don't create `Expense` custom entities here.

Detail: [reference/customer-modeling.md](reference/customer-modeling.md) · [reference/entities.md](reference/entities.md).

- **Assignment notifications**: the native assignment email is generic and only fires on assignment *change* (an upsert/dedup update stays silent). When the business wants branded, re-contact-aware notifications, the backend sends its own and that one entity is dropped from the native list. See [reference/notifications.md](reference/notifications.md).
- **Forensics** ("who/what created or deleted this record, and when?"): cross-reference `AuthLogRecord` (who authenticated) with the container access log (method/path/User-Agent), anchoring the container TZ first. See [reference/forensics.md](reference/forensics.md).
- **Roles / ACL**: a `type=admin` user ignores every Role — permissions only bite on `type=regular`. The two settings most often left open are `userPermission` (dropdowns enumerate every user) and `exportPermission` (read access ≠ the right to download the database). Verify by authenticating *as* the user, never by reading the Role JSON. See [reference/roles-and-acl.md](reference/roles-and-acl.md).
- **Cloning an instance** (splitting one CRM into two): the database connection lives in `data/config-internal.php`, **not** `config.php`, and the container's `ESPOCRM_DATABASE_*` env vars are read only at install time — copy a data dir without editing that file and the clone writes to the original's database. See [reference/multi-instance.md](reference/multi-instance.md).
- **Backups**: `job` + `scheduled_job_log_record` are cron bookkeeping and routinely account for most of the database — exclude their *data* from the dump and it shrinks by orders of magnitude. Attachments live in `data/upload/`, not in the DB. See [reference/backup-and-restore.md](reference/backup-and-restore.md).

## The endpoints that work (the rest will give 404/405)

- **Custom entity create/remove**: `POST /api/v1/EntityManager/action/createEntity` (NOT `/Admin/entityManager/createEntity` which is 404). Names get a `C` prefix automatically (Subscription → CSubscription).
- **Custom field create/edit**: `PUT /api/v1/Admin/fieldManager/<scope>/<fieldName>` (POST gives 405 — endpoint is idempotent on PUT).
- **Custom field delete**: `DELETE /api/v1/Admin/fieldManager/<scope>/<fieldName>`.
- **Link (relationship) create**: `POST /api/v1/EntityManager/action/createLink`.
- **Layout write**: `PUT /api/v1/<Entity>/layout/<layoutName>` (NOT `/Layout/<Entity>/<name>` which is 405 for writes — that path is GET-only).
- **Schema rebuild after changes**: `POST /api/v1/Admin/rebuild`.
- **Standard records**: `GET/POST/PUT/PATCH/DELETE /api/v1/<EntityType>[/<id>]`. PATCH and PUT both work for updates.
- **Many-to-many link assignment**: NOT via PUT on the parent (gives 403). Use the relationship endpoint: `POST /api/v1/<Entity>/<id>/<linkName>` with body `{"id":"<related_id>"}` per relation. **Exception — `teams`**: the opposite holds. `PUT /<Entity>/<id> {"teamsIds":[...]}` works; the relationship endpoint 403s for api users. See the teams exception in [reference/common-errors.md](reference/common-errors.md).
- **Lead conversion**: there is NO single `convert` endpoint. It's an orchestration — create Account/Contact/Opportunity from the Lead, then `PUT /Lead/<id>` with `status: Converted`. Use `mcp__espocrm__convert_lead` or replicate the sequence. Full verified flow: [reference/api-endpoints.md](reference/api-endpoints.md) → "Lead conversion".

Detail with payloads and response shapes: [reference/api-endpoints.md](reference/api-endpoints.md).

## The walls already hit (don't re-hit them)

- `phoneNumber` requires `+<country>` prefix when the instance has `phoneNumberInternational=true` (typical default). Without prefix → `validationFailure phoneNumber valid`.
- `industry` on Account is a predefined enum (Apparel, Banking, Manufacturing, etc.). Custom strings get rejected. Leave null and put descriptive info in `description`, or build a custom enum field.
- The MCP's specialized tools (`create_meeting`, `create_contact`, etc.) only accept the keys in their Zod schema and reject names with tildes/dashes/parentheses and phone numbers with `+` and spaces. The backend accepts all of these — fall back to the generic `create_entity` / `update_entity` MCP tools, which pass through with lighter validation.
- The MCP returns generic "Invalid request data" for any 4xx. The actual `messageTranslation` field with the offending field name is in the raw response body — fall back to admin script or curl when the MCP message is insufficient.
- **`search_entity` prints `undefined undefined` for custom entities *and* for `Account`** — it finds and counts the rows correctly; only its per-type label template falls short. `select` doesn't fix it, and it is neither credentials nor permissions. Use `get_entity` for one row, plain REST (`GET /api/v1/<Entity>`) to list or filter. See [reference/common-errors.md](reference/common-errors.md).
- **A 200 on `GET /Settings` without a key proves nothing** — that endpoint is unauthenticated by design. Every entity still requires a session (no header / empty key / garbage key all → 401). Test access control with an explicit negative case, not by inference. See [reference/auth-patterns.md](reference/auth-patterns.md).
- **In a chain of creates, the error surfaces on the *child*, not on the parent that failed.** `jq -r '.id'` on an error body returns `null`, the script keeps going, and the child fails with `cannotRelateNonExisting` naming the *parent's* entity type — sending you to audit the wrong scope. Guard the id before using it. See [reference/common-errors.md](reference/common-errors.md).
- The api user does NOT see itself in `search_users` results — expected, not a bug.
- An admin auth helper script that prints HTTP status to stderr and body to stdout requires you to NOT grep across both at once.
- **When more than one instance exists, `200 OK` with `total: 0` is indistinguishable from "no records"** — and the same API key authenticates against all of them. Before concluding anything is missing, confirm the target with `GET /Settings` → `siteUrl`. See [reference/multi-instance.md](reference/multi-instance.md).
- **Records dashlets ignore `where` / `searchData.advanced`** — they silently drop everything except `primaryFilter` and `boolFilterList`. Filtering a dashlet needs a **named primary filter** (PHP class + JSON on the container filesystem — not settable via REST). See the primary-filters section of [reference/api-endpoints.md](reference/api-endpoints.md) and the ready templates in [scripts/primary-filter-templates/](scripts/primary-filter-templates/).

Full catalog with response excerpts: [reference/common-errors.md](reference/common-errors.md).

## Creating a new custom entity — follow the checklist, do NOT improvise

When you create a new custom entity, follow [scripts/create-custom-entity-checklist.md](scripts/create-custom-entity-checklist.md) end to end. Skipping steps leaves UI surfaces broken — kebab menu prohibited, edit modal empty, search not filtering, dashlets unfilterable, etc. — and the user discovers each one the hard way.

The checklist covers all 12 steps from creation to verification, including the 7+ layouts that need to be populated (not just `list` + `detail`), the role scope update, the icon/color, the textFilterFields with dot-notation, and the sidebar tab list.

**Recurring failure pattern (do not repeat)**: configure only `list` + `detail` because those are what shows up first when opening the entity. Other layouts (`detailSmall`, `edit`, `editSmall`, `filters`, `searchFilters`, `mass`) are then left empty and break later when the user clicks Edit, opens a quick view, tries to filter, etc. The user shouldn't be the integration test of the setup.

## Quick map

```
SKILL.md                         (this file)
reference/
├── api-endpoints.md             verified endpoints with payloads
├── auth-patterns.md             two auth flows in detail
├── entities.md                  schemas: native + custom patterns
├── customer-modeling.md         Account vs Contact, multi-business, Teams vs custom enum
├── common-errors.md             validationFailure cases, 403/404/405/500 patterns
├── notifications.md             native assignment email vs a custom backend email
├── forensics.md                 attribute a record's create/delete via AuthLogRecord + access log
├── backup-and-restore.md        what to exclude from the dump, and how to prove it restores
├── multi-instance.md            cloning an instance, and operating safely with several
└── roles-and-acl.md             permission field names, what to close, how to prove it's closed
scripts/
├── create-custom-entity-checklist.md   12-step end-to-end create flow
└── primary-filter-templates/           PHP + JSON templates for filterable dashlets
```

## What this skill does NOT contain

This skill is the **generic operations manual for EspoCRM 9.x**. It does not contain:

- Names, emails, phone numbers, or any data of real users, customers, or businesses
- Specific instance URLs, container names, server hostnames
- Specific record IDs, user IDs, role IDs, team IDs
- Specific passwords, API keys, or any credential
- Specific business model details (pricing, services, internal arrangements)

Anything operator-specific belongs in the operator's own configuration and memory layers (e.g. project memory, secrets, environment files), not in this skill. The skill should remain copyable to any EspoCRM 9.x environment without leaking another operator's setup.

## When to update this skill

After every session where you discover a new endpoint, hit a new error, decide a new modeling pattern, or correct an old one. Keep entries generic — patterns and examples, not real names. The git log of the skill repo is the diary.
