# Changelog

## v0.2.5

- New: `--per-table=N` gives each table (or each `--only` table) exactly N rows, instead of `--count`'s realistic split of a total.

## v0.2.4

- RealSeed always plans with AI. `--no-ai` and the `ai.enabled` setting are removed. When no AI provider can be used, or the AI request fails, RealSeed stops before writing anything and explains why.
- `laravel/ai` is now a required dependency (installed with RealSeed); only a provider key is needed in `.env`.
- Requires Laravel 12 or 13 (`laravel/ai` doesn't support Laravel 11).
- Fix: `--only=invoices --count=5` could generate fewer invoices (e.g. 1), because `--count` was spread over the parents added for them. `--count` now applies to the selected tables only.
- Parents generated only because selected tables require them get no more rows than needed (5 tasks → at most 5 projects, not 27).
- Fix: `--count` scaling after AI planning could give currency/country tables more rows than real entries exist.
- "Could not connect to AI provider" now shows the real cause (timeout, DNS, SSL) with a hint. Timeouts are the usual cause: large schemas need long answers.
- AI timeout is configurable with `REALSEED_AI_TIMEOUT` and defaults to 300 seconds (was 120).
- Smaller, faster AI answers: 8–15 samples per text column instead of 15–40, and nothing for tables that aren't being generated.

## v0.2.3

- Google and Anthropic error details are read correctly; Gemini free-tier limits are no longer mistaken for missing credit.

## v0.2.2

- AI failures now include the provider's own explanation. `laravel/ai` reports every HTTP 429 as "rate limited", but OpenAI also uses 429 for accounts without API credit; RealSeed now shows which it is, with a hint (add billing, or wait and retry with the prompt's size in tokens).

## v0.2.1

- The AI planning status is now highlighted (✓ or !), repeated in the plan ("Planned by: …") and in the final summary.
- When an AI provider key such as `OPENAI_API_KEY` is set but `laravel/ai` isn't installed, RealSeed says so and shows the install command, instead of silently using built-in heuristics.

## v0.2.0

- New: `protected_tables` config. Protected tables are never generated into or deleted (not even by `--fresh`) but are still used as parents for generated data. Skip notes mark protected tables.
- No-AI text is now readable. Common tables (tasks, projects, products, posts, comments, events, courses, categories, teams, properties, …) get realistic built-in values; `*_name` columns use their own entity (`bank_name` gives real banks for the locale); everything else gets neutral wording built from the table name. Faker's `bs()`, `catchPhrase()`, `realText()` (Alice in Wonderland) and lorem words are no longer used.
- Fix: a required polymorphic link failed when nothing matched the row's scope (e.g. a commenter's organization with no projects). It now falls back to any valid target.

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

## v0.2.5

Initial release.

- `php artisan real:seed` with a fail-closed environment allowlist (local, dev, development, staging) and production-looking database detection.
- Analysis of the live schema, models, enums, factories and migrations; relationship graph with dependency ordering and cycle handling.
- Deterministic, reproducible generation: scope-consistent relationships, coherent timelines, workflow-aware timestamps, unique-safe values, parent coverage.
- AI planning through the Laravel AI SDK or any `AIProviderInterface`, with validated, cached plans and structure-only prompts.
- `--dry-run`, `--seed`, `--size`, `--count`, `--only`, `--except`, `--fresh`, `--scenario`, `--no-ai`, `--replan`, `--show-prompt`, `--locale`, `--strategy`.
- Factory and hybrid strategies with write detection.
- Locales (including Nigeria), configuration overrides, and extension points for fields, rows, references, scenarios, analyzers, locales and AI providers.
