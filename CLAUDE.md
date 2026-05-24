# CLAUDE.md

This file provides guidance to Claude Code when working with this repository.

## Project Overview

This is the **espocrm-skills** repository — a Claude Code skill for operating self-hosted EspoCRM 9.x.

**Repository**: https://github.com/milojarow/espocrm-skills

**Purpose**: an operating manual that provides expert guidance on driving EspoCRM through its REST API or an MCP server — the two auth flows, entity schemas, custom entities, verified endpoints, customer-modeling defaults, and the common API walls.

## Repository Structure

```
espocrm-skills/
├── .claude-plugin/          # Claude Code plugin configuration (marketplace.json, plugin.json)
├── CLAUDE.md                # This file
├── README.md                # Project overview
├── LICENSE                  # MIT License
├── evaluations/             # Test scenarios for the skill
│   └── espocrm/
└── skills/
    └── espocrm/             # The EspoCRM operating manual
        ├── SKILL.md         # Entry point: auth, modeling, endpoints, walls
        ├── reference/       # Depth: api-endpoints, auth-patterns, entities, customer-modeling, common-errors
        └── scripts/         # Custom-entity checklist + primary-filter templates
```

## The skill

### espocrm
EspoCRM 9.x operating manual. Covers the two auth paths (api-user `X-Api-Key` for CRUD, admin `Basic`+Token for schema work), native + custom entity schemas, verified endpoints (the ones that work vs the 404/405 traps), customer-modeling defaults (Teams for multi-tenancy, Subscription-style custom entities for recurring revenue), and a catalog of `validationFailure` / 403 / 404 / 405 walls.

## Skill Activation

Activates automatically when a query matches its description triggers — interacting with EspoCRM via REST API or MCP, creating/updating records, modifying schema, building `where[]` queries, or troubleshooting API errors.

## Updating this skill

After any session that discovers a new endpoint, hits a new error, or decides a new modeling pattern. Keep entries **generic** — patterns and examples, never real names, IDs, URLs, or credentials. The git log of this repo is the diary.
