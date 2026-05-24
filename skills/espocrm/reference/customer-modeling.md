# Customer modeling — patterns and decisions

These are sound defaults. Adopt them unless you have a specific reason not to.

## Account vs Contact — the rule

- **Account** = the **organization that pays**. Always.
- **Contact** = the **person** with whom you talk. Always.

A business has Accounts; a business does NOT have Contacts. People are Contacts who happen to be linked to one or more Accounts.

When in doubt: would you write a check to this entity? → Account. Could you call them on the phone? → Contact.

## Customer with multiple businesses

When **one person** owns **multiple businesses**, model it as:

- One **Contact** for the person (the alias/nickname goes in `description` or a custom `cAlias` field if you want it searchable)
- One **Account** per business
- The Contact's `accountsIds` includes ALL the businesses; the `accountId` (primary) is whichever you consider main
- Each business's services and billing live on **its own Account**

Concrete example pattern:

- Contact "Person Name" (with optional alias) → `accountsIds = [Business A, Business B]`
- Contact "Their co-decision-maker" (e.g. spouse, partner, child) → same `accountsIds`
- Account "Business A" — receives services and billing for that business
- Account "Business B" — receives services and billing for the other business

When billing covers multiple businesses (single-customer-multi-business invoice), see the **Invoice with no Account** pattern below.

**Don't create a parent "Holdings" Account** for the human just to glue the businesses. Two reasons:

1. The Account hierarchy in espoCRM is for actual corporate parent-child structures (subsidiaries, divisions). A loose ownership relationship between businesses owned by the same person is not that.
2. The Contact-with-multi-Account pattern already expresses "same person owns these" cleanly, without a phantom Account.

When DO use Account parent-child? When the businesses are actual subsidiaries with consolidated financial reporting and different legal entities all under one umbrella corporation (e.g. Acme Corp parent of Acme Mexico, Acme USA, Acme EU). Not for an individual who happens to run two unrelated small businesses.

## Invoice with no Account (multi-business customer)

When a single billing cycle covers multiple Accounts (because one customer pays one consolidated bill for services across their businesses):

- `CInvoice.accountId` = null
- `CInvoice.description` = explicit list of businesses covered + customer name
- `CInvoice.subscriptions` = the full list of Subscriptions across all the businesses

The customer identity is reconstructed via the linked Subscriptions and their respective Accounts.

If the customer wants separate invoices per business (legal/fiscal reasons), then create **one CInvoice per Account** and split. Decide based on the customer's preference, not modeling purity.

## Multi-tenancy via Teams (the holding model)

When one EspoCRM instance serves multiple companies / brands / regions / departments under one operator, use Teams to segment. Each Team represents one slice. Records belong to one or more Teams via `teamsIds`. ACLs at the Role level can be set to `team` to scope visibility per user.

Patterns:

- **Account-team mapping**: every Account belongs to the Team that sells to it. If a customer is shared between two slices (e.g. Slice A sells software, Slice B sells insurance, same customer buys both), the Account has both team ids.
- **Default team for users**: each user has a `defaultTeamId`. New records they create auto-include that team.
- **Cross-team customers**: share the Account; don't duplicate. The Subscription/Policy specific to each slice is what differentiates revenue per team in reporting.
- **A Team is not always a company**. It can also represent a department, region, customer tier, or any orthogonal grouping. Records can be in multiple Teams without conflict (`teamsIds` is multi-value).

## Subscription vs Opportunity — when to use which

| Use Opportunity for | Use a `CSubscription` (custom) for |
|---|---|
| Tracking a sales pipeline (prospecting → negotiation → closed won) | The recurring service that's already running |
| One-off sales (a project with a fixed scope and end) | Monthly/annual recurring services |
| Pre-customer state (still proposing) | Post-customer state (already paying) |
| Tracking probability and forecast revenue | Tracking active service status and next billing |

It's NOT either/or — they coexist for the same customer over time:

- Day -30: someone is interested → create **Lead**
- Day -10: lead qualifies, you send a proposal → convert Lead to **Opportunity** at stage Proposal
- Day 0: customer signs, money arrives → close Opportunity at "Closed Won", create **Account** if not from lead conversion, create one **CSubscription** per recurring service, create the first **CInvoice + CPayment**
- Day 30, 60, 90: each month → create new CInvoice, link the (still-Active) CSubscriptions, register CPayments

The Opportunity record stays around as historical evidence of "we won this deal". You don't update it monthly — that's what CInvoice is for.

## Pricing model — package vs per-service

When a customer pays a **package price** but the services have individual prices internally, model both:

- **Each service is its own CSubscription** with its own `monthlyAmount`. Sum of the active CSubscriptions = the package price.
- **The CInvoice** carries the package total in `totalAmount`. The Subscriptions linked confirm the breakdown.
- This way you can pause one service (e.g. "they don't want the chatbot anymore") without re-pricing everything. Change CSubscription.status to Paused, exclude from next CInvoice.

Don't try to model the package as a single CSubscription with `monthlyAmount = total` and stuff the breakdown in description — you'll regret it the first time the customer wants to drop one service.

## Fragmented payments

When a customer pays in halves (or thirds, or whatever):

- One CInvoice with `totalAmount = full amount`, `amountPaid` updated as payments come in, `status = PartiallyPaid` until all received.
- One CPayment per cash event, each linked to the CInvoice.
- The customer's spending pattern lives in the sequence of CPayments; the contractual obligation lives in the CInvoice.

`amountPaid` is typically maintained manually unless you set up a workflow that auto-sums new CPayments and updates the parent CInvoice. Worth automating once you have more than a handful of clients.

## Tax / formal invoicing

EspoCRM does NOT generate tax-compliant invoices natively (electronic invoices for fiscal authorities, CFDI in Mexico, etc.). CInvoice is an **internal tracking record**, not a fiscal document.

When a customer provides their tax ID and you need formal invoicing:

- Add the tax ID to the Account in a custom field (e.g. `cTaxId`, `cRfc`).
- Generate the actual fiscal invoice in a dedicated system (Facturama, SW, Pagero, etc.) and store the resulting UUID/PAC reference in CInvoice.description or a custom field.
- Don't try to make EspoCRM do tax-compliant invoicing — wrong tool for the job.

Until the customer provides their tax ID, document the request in Account.description.

## What goes in CRM vs elsewhere

| Goes in EspoCRM | Goes elsewhere |
|---|---|
| Customer identity (Account, Contact) | Internal partner accounting / cost-sharing arrangements — accounting tools or spreadsheet |
| Services contracted (CSubscription) | Operational expenses / vendor bills — accounting tool (Firefly III, Akaunting, etc.) |
| Money received from customers (CPayment) | Money paid out to vendors — accounting tool |
| Client-facing notes ("they want X next month") | Internal team decisions ("we won't pay ourselves until profit") — partner notes, not the CRM |
| Service deployment status (CSubscription.status) | Code deployment status — wherever your CI/CD is |

Rule: if the question is "what does the customer think we owe each other", it's CRM. If the question is "how do we run our company internally", it's not CRM.
