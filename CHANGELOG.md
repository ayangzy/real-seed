# Changelog

## Unreleased

Initial release.

- `php artisan realseed` with a fail-closed environment allowlist (local, dev, development, staging) and production-looking database detection.
- Analysis of the live schema, models, enums, factories and migrations; relationship graph with dependency ordering and cycle handling.
- Deterministic, reproducible generation: scope-consistent relationships, coherent timelines, workflow-aware timestamps, unique-safe values, parent coverage.
- AI planning through the Laravel AI SDK or any `AIProviderInterface`, with validated, cached plans and structure-only prompts.
- `--dry-run`, `--seed`, `--size`, `--count`, `--only`, `--except`, `--fresh`, `--scenario`, `--no-ai`, `--replan`, `--show-prompt`, `--locale`, `--strategy`.
- Factory and hybrid strategies with write detection.
- Locales (including Nigeria), configuration overrides, and extension points for fields, rows, references, scenarios, analyzers, locales and AI providers.
