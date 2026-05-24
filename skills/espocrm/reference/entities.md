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
- `source` (enum, REQUIRED) — `Call`, `Email`, `Existing Customer`, `Partner`, `Public Relations`, `Web Site`, `Campaign`, `Other`
- `industry`, `website`, `phoneNumber`, `emailAddress`, `description`
- `assignedUserId`, `teamsIds`

When a Lead converts (deal closed), use the lead conversion flow (`mcp__espocrm__convert_lead` or `POST /Lead/<id>/convert`) which creates Account + Contact + optionally Opportunity from the Lead.

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
- Tasks: `parentType` + `parentId` to link to whatever entity is responsible (Lead, Account, Contact, Opportunity).
- Cases: `accountId`, `contactId`, `priority`, `status`, `type`.

### User

EspoCRM has a `type` discriminator on User:

- `admin` — full rights, password auth, sees everything
- `regular` — normal user, password auth, scoped by Roles + Teams
- `api` — service account, X-Api-Key auth (and HMAC), no UI login
- `portal` — customer portal user

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
