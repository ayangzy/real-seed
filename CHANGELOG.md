# Changelog

## v0.1.6

- Fix: pivots declared with `morphedByMany` (e.g. spatie/laravel-permission's `Permission::users()` over `model_has_permissions`) were read in the wrong direction: `model_id` was linked to the wrong table and treated both as a polymorphic id and a plain link, failing with "rows reference missing [users] rows". Both directions are now understood, and a polymorphic id column is never also treated as a plain link.

## v0.1.5

- Fix: any `{name}_type` + `{name}_id` pair was assumed to be polymorphic. A `bank_accounts` table with an `account_type` enum (checking, savings, …) was skipped, and with `morph_targets` set its enum column received a model class name. Pairs now need evidence: a declared morph relation, or no contradiction (fixed non-model values in the `_type` column, or a foreign key/`belongsTo` on the `_id` column).
- Unconstrained `_id` columns are linked by Laravel's naming convention (`account_id` → `accounts.id`) when the table exists.
- `morph_targets` entries for non-polymorphic pairs are ignored with a visible note.

## v0.1.4

- Fix: skipped tables were only propagated one level, so deeper dependents (e.g. `reconciliation_items` needing `bank_transactions` needing `bank_accounts`) still failed mid-run. Skips now propagate to any depth, and the final plan is re-checked before the confirmation prompt.
- When every table is skipped, the reasons are shown instead of "nothing to generate".
- New randomized test: 40 generated schemas must never fail mid-run.

## v0.1.3

- Fix: a table that requires a skipped table (e.g. `reconciliations` needing `bank_accounts`) failed mid-run. It is now skipped while planning, with a note, and the rest of the data is generated.
- Polymorphic targets are also learned from types already stored in the table, or from the new `morph_targets` config option.
- The note for unresolved polymorphic relations explains how to fix them.

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
