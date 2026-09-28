# Changelog

## v0.1.2

- Fix: short unique code columns (e.g. `currencies.code` as `char(3)`) failed with "value is longer than 3 characters". Duplicate repair now respects column length, and short code columns get compact unique codes.
- Currency and country tables are filled with real, matching reference data (`NGN | Nigerian Naira | ₦`), with the locale's own entry first.

## v0.1.1

- Support Laravel 11 and 12 as well as 13. AI planning through `laravel/ai` needs Laravel 12+.

## Unreleased

Initial release.

- `php artisan real:seed` with a fail-closed environment allowlist (local, dev, development, staging) and production-looking database detection.
- Analysis of the live schema, models, enums, factories and migrations; relationship graph with dependency ordering and cycle handling.
- Deterministic, reproducible generation: scope-consistent relationships, coherent timelines, workflow-aware timestamps, unique-safe values, parent coverage.
- AI planning through the Laravel AI SDK or any `AIProviderInterface`, with validated, cached plans and structure-only prompts.
- `--dry-run`, `--seed`, `--size`, `--count`, `--only`, `--except`, `--fresh`, `--scenario`, `--no-ai`, `--replan`, `--show-prompt`, `--locale`, `--strategy`.
- Factory and hybrid strategies with write detection.
- Locales (including Nigeria), configuration overrides, and extension points for fields, rows, references, scenarios, analyzers, locales and AI providers.
