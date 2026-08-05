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
| `statamic/cms` | `^6.6` |
| `laravel/mcp` | `^0.6 || ^0.7 || ^0.8 || ^0.9` |
| `symfony/yaml` | `^7.0 || ^8.0` |

## Laravel

Laravel is a transitive requirement via `statamic/cms`, which requires
`laravel/framework: ^12.40 || ^13.0`. Both majors are exercised in CI (the test
matrix runs `orchestra/testbench` `^10.0` and `^11.0`), so support for either is
verified rather than assumed.

## PHP

The package requires `^8.3`. CI runs the suite on PHP 8.3, 8.4, and 8.5
against both Laravel majors.

## Database

No database is required by default — tokens and audit logs use flat-file storage.
The Eloquent storage drivers need a database connection; see
[Configuration](configuration/_index.md) for how to select a driver.
