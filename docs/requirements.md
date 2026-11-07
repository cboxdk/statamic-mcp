---
title: "Requirements"
description: "PHP, Statamic, and Laravel versions this package resolves against"
weight: 3
---

# Requirements

These are the constraints the Composer resolver actually enforces, taken straight
from this package's `composer.json`. Nothing here is aspirational — if the
resolver allows it, it is listed; if it does not, it is not.

| Package | Constraint |
|---------|------------|
| `php` | `^8.3` |
| `statamic/cms` | `^6.35.1` |
| `laravel/mcp` | `^1.1.2` |
| `symfony/yaml` | `^7.0 || ^8.0` |
| `opis/json-schema` | `^2.6.1` |

## Laravel

Laravel is a transitive requirement via `statamic/cms`, which requires
`laravel/framework: ^12.40 || ^13.0`. CI is configured to exercise both majors (the test
matrix runs `orchestra/testbench` `^10.0` and `^11.0`). A green run of that matrix on the release commit is required before release.

## PHP

The package requires `^8.3`. CI runs the suite on PHP 8.3, 8.4, and 8.5
against both Laravel majors. An additional SQLite job pins Statamic to the declared
minimum, 6.35.1. These jobs also run on the v4 release branch; local verification
on one PHP/Statamic version does not substitute for that matrix.

## Database

No database is required by default — tokens and audit logs use flat-file storage.
The Eloquent storage drivers need a database connection; see
[Configuration](configuration/_index.md) for how to select a driver.
