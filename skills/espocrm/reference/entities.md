# Entities — schemas and relationships

This documents the entities you'll touch most. For the full metadata of any entity at runtime:

```bash
<your-admin-helper> GET '/Metadata?key=entityDefs.<Entity>.fields'
<your-admin-helper> GET '/Metadata?key=entityDefs.<Entity>.links'
```

## Native entities — what's important

### Account

The **organization/business that pays**. NOT the person.

Key fields:

- `name` (varchar, required) — the business name
- `type` (enum, optional) — `Customer`, `Investor`, `Partner`, `Reseller` (default options)
- `industry` (enum, optional) — **predefined enum** (Apparel, Banking, Manufacturing, etc.). Free-text values like `"Lottery"` or `"Restaurant"` get rejected with `validationFailure industry valid`. Either leave null and put info in `description`, or add a custom enum field if you need structured data.
- `phoneNumber` (varchar with multi-value support) — needs `+<country>` prefix when `phoneNumberInternational=true` in Settings.
- `emailAddress` (multi-value)
- `website` (varchar)
- `description` (text)
- `billingAddressStreet`, `billingAddressCity`, `billingAddressState`, `billingAddressCountry`, `billingAddressPostalCode`
- `shippingAddressStreet`, etc. (mirror of billing)
- `assignedUserId` (link to User) — who in your team owns this account
- `teamsIds` (linkMultiple to Team) — which Teams (slices in your multi-tenant model) the account belongs to
- Native parent-child via `parentId` / `parentName` for hierarchical Accounts (use sparingly — only when consolidation reporting matters)

Native links of interest: `contacts` (hasMany), `opportunities` (hasMany), `tasks`, `meetings`, `calls`, `cases`, `documents`. Custom links you might add per setup: `subscriptions`, `invoices`, etc.

### Contact

The **person** you talk to. Linked to one or more Accounts.

Key fields:

- `firstName`, `lastName`, `salutationName` (`Mr.`, `Mrs.`, `Ms.`, `Dr.`, `Prof.`)
- `accountId` (link to one **primary** Account)
- `accountsIds` (linkMultiple — a Contact can belong to MANY Accounts)
- `title` (varchar) — role/job title
- `phoneNumber`, `emailAddress`, `description`
- `assignedUserId`, `teamsIds`

A contact's `accountsIds` is the field you usually want when the same person operates multiple businesses (e.g. an owner with two stores). Set both `accountId` (primary) and `accountsIds` (full list).

### Lead

A prospect that has NOT bought yet.

Key fields:

- `firstName`, `lastName`, `accountName` (string, not a link — when there's no Account record yet)
- `status` (enum) — `New`, `Assigned`, `In Process`, `Converted`, `Recycled`, `Dead`
- `source` (enum, **optional** — not required) — `Call`, `Email`, `Existing Customer`, `Partner`, `Public Relations`, `Web Site`, `Campaign`, `Other`. Verified against `entityDefs.Lead.fields.source`: `required` is unset and empty `''` is a valid option. Leave it blank when the inbound channel is unknown instead of guessing a value.
- `industry`, `website`, `phoneNumber`, `emailAddress`, `description`
- `assignedUserId`, `teamsIds`

When a Lead converts (deal closed), use the lead conversion flow — the MCP tool `mcp__espocrm__convert_lead`, or replicate its orchestration over standard REST. It is **not** a single endpoint (there is no verified `POST /Lead/<id>/convert`): it creates Account + Contact + optionally Opportunity from the Lead, then flips the Lead's `status` to `Converted`. Verified call sequence, field mapping, and gotchas: [api-endpoints.md](api-endpoints.md) → "Lead conversion".

### Opportunity

A **deal in negotiation**. Has stages (pipeline). Use for one-off sales or the initial "we got the contract" moment, not for tracking ongoing recurring services (those go in a custom entity like `CSubscription`).

Key fields:

- `name`
- `accountId`
- `amount` (currency)
- `closeDate` (date) — when this deal will close
- `stage` (enum) — `Prospecting`, `Qualification`, `Proposal`, `Negotiation`, `Closed Won`, `Closed Lost`
- `probability` (int 0-100)
- `leadSource` (enum, same options as Lead.source)

### Meeting / Call / Task / Case

Activity records. Each has its own native fields. Common requirements:

- `assignedUserId` is **required** on Meeting (likely on most). If absent: `validationFailure assignedUser required`.
- Meetings: `dateStart`, `dateEnd` (datetime), `status` (`Planned`/`Held`/`Not Held`), `usersIds`, `contactsIds`, `leadsIds`.
  - **No `Canceled` status.** The stock enum is exactly `Planned` / `Held` / `Not Held` — there is no "Canceled" value. Auditable cancel-without-destroy workaround: `PUT` status → `"Not Held"` + a marker prefix in `name` (e.g. `[CANCELLED] Call with…`), paired with a **role that DENIES delete** to the bot's api user — so the record survives as an audit trail and the bot can't destroy history even if instructed. The triple (readable status + name marker + delete denied) makes the cancellation reversible and auditable without customizing the enum.
- Tasks: `parentType` + `parentId` to link to whatever entity is responsible (Lead, Account, Contact, Opportunity).
- Cases: `accountId`, `contactId`, `priority`, `status`, `type`.

### User

EspoCRM has a `type` discriminator on User:

- `admin` — full rights, password auth, sees everything
- `regular` — normal user, password auth, scoped by Roles + Teams
- `api` — service account, X-Api-Key auth (and HMAC), no UI login
- `portal` — customer portal user
- `super-admin` — a distinct, **more powerful `type`** than `admin` — but one you'll rarely see instantiated. The full `User.type` enum is `regular, admin, portal, system, super-admin, api` (verified against `entityDefs.User.fields.type`; there is no separate `isSuperAdmin` field — it's not in the metadata and `select=isSuperAdmin` returns null even under admin auth). The code reserves real powers to `super-admin` that a plain `admin` lacks (verified in source):
  - A regular admin **cannot see, edit, or delete super-admin users** — `Classes/Acl/User/AccessChecker` denies read/edit/delete when the target `isSuperAdmin()` and the actor isn't.
  - A regular admin **cannot grant the super-admin type** — `Classes/Record/User/InputFilter` strips it from the input payload for non-super-admins.
  - Super-admin **bypasses the user-count limits** that block a regular admin — `Classes/RecordHooks/User/BeforeUpdate`.
  - In **restricted mode**, a reserved param set + parts of the admin panel become super-admin-only — `Tools/App/SettingsService::getSuperAdminParamList`.
  **In practice it's almost never present.** A normal install runs *every* administrator as plain `admin` — verified in the field: instances where all human users are `type=admin` and zero `super-admin` users exist. `admin` already has full unrestricted access to records, schema, and settings; a `super-admin` user is usually created only via CLI/config, not the UI. So day to day you operate as `admin` and the distinction never bites — until a `super-admin` user actually exists, at which point a plain `admin` can't touch it or grant that type. Don't assume any admin is super-admin: `type` reads `admin` for ordinary administrators.

  **Wielding the power (actionable):** for any heavy structural work — creating entities, fields, roles, teams, layouts, settings — you do **not** need `super-admin`. The lever is the **admin auth path** (Basic auth as a `type=admin` user; see "Two auth paths" in SKILL.md and [auth-patterns.md](auth-patterns.md)), which already has full access to all of that. The four super-admin-only gates above are **unreachable via REST**: a regular admin can't self-promote, and a `super-admin` user must be created server-side (CLI/config on the host), not through the API — so trying to invoke them over the API just returns 403. Reach for the admin path for heavy work; treat a genuine super-admin-only need as a deliberate infra step on the server, not an API call.
- `system` — internal framework user type, not a human login

### Team

Organizational unit. Multi-valued — records can belong to several teams at once via `teamsIds`. A user with role-level `team` ACL only sees records in their Teams. Admins see everything regardless.

Use cases for Teams:

- Multi-tenant operator (different brands / companies / regions sharing one instance)
- Departments inside one company (Sales, Marketing, Support)
- Customer tiers (Top, Standard, Trial)
- Geographic regions

A record can be in multiple teams simultaneously — useful for cross-cutting groupings.

### Role

Permission set. Assignment of `Role`s to `User`s controls who can do what. Don't edit Roles via the api user (it shouldn't have scope on `Role` — would let it self-escalate). Use admin path.

## Custom entity patterns for recurring revenue

Concrete patterns that work well when you need to track recurring services and billing.

### `CSubscription` — recurring service sold to one Account

| Field | Type | Notes |
|---|---|---|
| `name` | varchar (required) | Human-readable, e.g. "Service X — Customer Y" |
| `account` | link manyToOne → Account | `accountId` in payloads. The Account that pays for this service. |
| `serviceType` | enum (required) | The catalog of services your operator offers, e.g. `Website`, `Hosting`, `Consulting`, `Other` |
| `monthlyAmount` | currency | Always set the currency too: `monthlyAmountCurrency` |
| `status` | enum (required) | `Active` (success), `Paused` (warning), `Cancelled` (danger), `InDevelopment` (info). Default `InDevelopment`. |
| `startDate` | date | When the service became Active (or when billing started) |
| `nextBillingDate` | date | Next billing cycle |
| `description` | text | Free-form notes |
| `teamsIds` | linkMultiple → Team | Which slice (company/dept/region) is selling this |

Used for: any recurring service. NOT for: one-off sales (use Opportunity), the actual cash event (use CPayment), or the monthly aggregate bill (use CInvoice).

### `CInvoice` — monthly billing aggregator

| Field | Type | Notes |
|---|---|---|
| `name` | varchar | E.g. "Period — Customer" |
| `account` | link manyToOne → Account, **optional** | When the invoice covers a single Account, set `accountId`. When it covers multiple Accounts (a customer with multiple businesses), leave null and document the customer in `description`. |
| `subscriptions` | link manyToMany → CSubscription | Set with `POST /CInvoice/<id>/subscriptions {id: ...}` per relation. **NOT** with `subscriptionsIds: [...]` on PUT — that's 403. |
| `payments` | link hasMany → CPayment (reverse of CPayment.invoice) | Read-only on this side; CPayment.invoice is what writes the relationship. |
| `totalAmount` | currency (required) | The full bill |
| `amountPaid` | currency | Sum of linked Payments. Maintained manually unless you set up a workflow. |
| `status` | enum (required) | `Draft`, `Sent`, `PartiallyPaid` (warning), `Paid` (success), `Cancelled` (danger). Default `Draft`. |
| `billingPeriod` | varchar | "Mayo 2026" or whatever string makes sense |
| `issueDate` | date (required) | When the invoice was issued |
| `dueDate` | date | When the customer should have paid |

Used for: every billing cycle for a customer. Aggregates Subscriptions + Payments.

### `CPayment` — single cash-in event

| Field | Type | Notes |
|---|---|---|
| `name` | varchar | E.g. "PAY-<date> — <customer description>" |
| `invoice` | link manyToOne → CInvoice | `invoiceId` in payloads. Required by intent — every payment goes against an invoice. |
| `amount` | currency (required) | With `amountCurrency` always set |
| `paymentDate` | date (required) | Actual receipt date |
| `paymentMethod` | enum (required) | `Cash`, `Transfer`, `Card`, `Other` |
| `reference` | varchar | Transaction number, receipt number, freeform |
| `description` | text | Notes |

Used for: each individual cash event. Multiple Payments per Invoice for fragmented payments.

## Relationships at a glance

```
Account
  ├─ contacts (hasMany)            ← Contact.accountsIds
  ├─ opportunities (hasMany)       ← Opportunity.accountId
  ├─ subscriptions (hasMany)       ← CSubscription.accountId
  └─ invoices (hasMany)            ← CInvoice.accountId (optional)

CInvoice
  ├─ account (manyToOne, optional) ← CInvoice.accountId
  ├─ subscriptions (manyToMany)    ← join table
  └─ payments (hasMany, reverse)   ← CPayment.invoiceId

CPayment
  └─ invoice (manyToOne)           ← CPayment.invoiceId

Contact
  ├─ account (manyToOne, primary)  ← Contact.accountId
  └─ accounts (manyToMany)         ← Contact.accountsIds (full list)
```

## Other custom entity patterns by industry

If you serve insurance, real estate, or any deferred-revenue business, these patterns scale:

- **Policy** for an insurance broker — link to Contact (the insured), Account (the customer), `premium` (currency), `coverageType` (enum), `policyNumber`, `startDate`, `renewalDate`
- **Property** for real estate — address fields, `salePrice`, `seller` (Contact), `buyer` (Contact when closed), `listingDate`, `closedDate`, `status` (Listed/Pending/Sold/Withdrawn)
- **Commission** for any deferred-revenue model — link to whatever the commission applies to (Property/Opportunity/etc.), `expectedAmount`, `expectedDate`, `actualReceiptDate`, `status` (Pending/Received/Forfeited)

Don't create these speculatively. Wait until the operator actually needs them, then design with the same care: schema → fields → links → 7 layouts → role scope → tab list. See `scripts/create-custom-entity-checklist.md`.
