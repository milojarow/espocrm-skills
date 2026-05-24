# espocrm-skills

**Expert Claude Code skills for operating self-hosted [EspoCRM](https://www.espocrm.com/)**

## What is this?

This repository contains a Claude Code skill that teaches AI assistants how to operate a self-hosted **EspoCRM 9.x** instance via its REST API or an MCP server — without re-hitting the discovery walls that have already been mapped.

### Why this skill exists

Driving EspoCRM through its API has non-obvious traps:
- Two separate auth paths (api-user `X-Api-Key` vs admin `Basic`+Token) with different permitted operations
- Schema work (custom entities, fields, links, layouts) lives on endpoints that don't follow the obvious paths
- Records created by the wrong user pollute streams, audit logs, and reporting
- Custom entities need 7+ layouts populated or the UI silently breaks
- A long tail of `validationFailure` / 403 / 404 / 405 walls

This skill encodes the verified endpoints, the two auth flows, sound customer-modeling defaults, and the walls already hit — so sessions go straight to what works.

## The skill

| Skill | Description |
|-------|-------------|
| **espocrm** | EspoCRM 9.x operating manual — auth, native + custom entities, verified endpoints, customer modeling, and common errors |

## Installation

Add this marketplace in Claude Code:

```
/plugin → Marketplaces → Add Marketplace → milojarow/espocrm-skills
```

Then install:

```
/plugin → Discover → espocrm-skills → Install
```

## Requirements

- A self-hosted EspoCRM 9.x instance
- An `api`-type user with an API key (daily CRUD) and an `admin` user (schema work)
- Optionally, an EspoCRM MCP server for tool-based access

## License

MIT
