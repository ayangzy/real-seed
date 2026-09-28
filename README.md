# AI Seeder

**Realistic, relational, time-coherent synthetic data for your Laravel app, generated from your app's actual structure.**

```bash
php artisan ai:seed
```

AI Seeder reads your migrated database, models, enums and factories. It then fills local, development and staging databases with data that looks like real usage:

- Organizations have members.
- A task's assignee works in the same organization as the task's project.
- Invoices come after subscriptions, and `completed_at` is only set on completed records.
- A few users are very active and most are occasional.

It is **not** a Faker wrapper. Faker generates values; AI Seeder generates *an application's worth of data* that makes sense together.

> [!IMPORTANT]
> AI Seeder is for **local development, development environments, staging, demos, and QA/UAT done on staging**.
> It is not a production data tool and refuses to run anywhere except `local`, `dev`, `development` and `staging`, with no override.

---

## Contents

- [How it works](#how-it-works)
- [Requirements](#requirements)
- [Installation](#installation)
- [Quick start](#quick-start)
- [Environment safety](#environment-safety)
- [Command reference](#command-reference)
- [Dataset size](#dataset-size)
- [Selective seeding](#selective-seeding)
- [Reproducibility](#reproducibility)
- [AI planning](#ai-planning)
- [No-AI mode](#no-ai-mode)
- [Scenarios](#scenarios)
- [Existing factories](#existing-factories)
- [Locales](#locales)
- [Configuration and overrides](#configuration-and-overrides)
- [Extending AI Seeder](#extending-ai-seeder)
- [Staging and destructive operations](#staging-and-destructive-operations)
- [Performance](#performance)
- [Database support](#database-support)
- [Limitations](#limitations)
- [Troubleshooting](#troubleshooting)
- [Testing and contributing](#testing-and-contributing)

---

## How it works

```text
EnvironmentGuard        refuse anything but local/dev/development/staging, before any other step
      ↓
Analysis                live schema (tables, columns, keys, indexes, enum/check constraints),
                        models (relations, casts), enums, factories, migrations status
      ↓
Relationship graph      foreign keys + model relations, pivots, polymorphic targets,
                        dependency order, cycle detection
      ↓
Plan                    heuristics → your analyzers → AI suggestions → scenario → config overrides
                        (every layer validated against the schema)
      ↓
Deterministic engine    seeded generation of every row: scope-consistent relationships,
                        coherent timelines, unique-safe values, locale-aware formats
      ↓
Executor                chunked bulk inserts in one transaction, cycle back-fill,
                        integrity verification, rollback on any failure
```

AI is used for what it is good at: understanding what your application is, choosing realistic quantities and proportions, and writing believable example text. It never writes rows, SQL or code, and everything it suggests is validated like untrusted input. The same plan and seed always produce the same data.

## Requirements

- PHP 8.3+
- Laravel 13
- SQLite, MySQL/MariaDB or PostgreSQL (SQL Server is best-effort)
- Optional: [`laravel/ai`](https://github.com/laravel/ai) for AI planning

## Installation

```bash
composer require --dev aiseeder/laravel-ai-seeder   # local development only
composer require aiseeder/laravel-ai-seeder         # also on staging (deploys often skip dev packages)
```

Installing it outside `require-dev` is safe: the environment guard, not the install location, keeps it out of production.

Publish the config if you want to change the defaults:

```bash
php artisan vendor:publish --tag=ai-seeder-config
```

To enable AI planning, install and configure the Laravel AI SDK (see its docs for API keys):

```bash
composer require laravel/ai
```

## Quick start

```bash
php artisan migrate
php artisan ai:seed --dry-run      # see what would be generated
php artisan ai:seed                # generate it
```

```text
AI Seeder

Environment: local
Database: my_application
Connection: mysql (mysql)
Host: 127.0.0.1

✓ Environment supported
✓ Production protection active

✓ 24 migrations detected
✓ 18 models detected
✓ 31 tables detected
✓ 46 relationships detected
✓ 7 enums detected
✓ 5 factories detected

Detected application structures:

Comments
Organizations
Projects
Tasks
Users

AI planning: laravel-ai:anthropic/claude-sonnet-5

Asking the AI to plan realistic data...
✓ AI plan saved to storage/ai-seeder/plans/3f2c9a….json
Application: A project management tool where agencies track client projects and tasks.

Generation Plan

Organizations:        10
Users:                50
Projects:            250
Tasks:             1,250
Comments:          2,500

Relationships validated: ✓
Circular dependencies:   1 resolved by back-filling (organizations.owner_id)
Seed:                    482913
Timeline:                2025-09-28 → 2026-09-28
Strategy:                ai

This operation will generate synthetic data. Continue? (yes/no) [yes]
```

## Environment safety

AI Seeder runs **only** when `APP_ENV` is exactly one of:

| Environment   | Allowed |
|---------------|---------|
| `local`       | ✓ |
| `dev`         | ✓ |
| `development` | ✓ |
| `staging`     | ✓ |
| anything else, including `production`, `prod`, `testing`, `qa`, `uat`, empty or unknown | ✗ |

- **The check fails closed.** An unknown, empty or unreadable environment is rejected.
- **It runs first**, before analysis, database access or AI calls.
- **It cannot be bypassed.** There is no `--force` or config setting. The list is a constant in the package, and adding an environment is a package release, not a runtime choice.

```text
AI Seeder

Environment: production

✗ AI Seeder cannot run in this environment.

AI Seeder only supports:
- local
- dev
- development
- staging

No database changes were made.
```

The environment name only protects you if it's accurate, so AI Seeder also checks the database it will write to. These checks can only add friction, never remove it:

- It **refuses** databases or hosts whose names look like production (`myapp_production`, `db.prod.internal`, `shop-live`).
- If `APP_ENV=local` but the database host isn't local, it asks you to **type the database name**, and refuses in non-interactive mode.
- It always shows the environment, database, connection and host before doing anything.

## Command reference

| Option | Description |
|---|---|
| `--dry-run` | Analyse and plan; change nothing. |
| `--seed=N` | Reproduce a previous run exactly. A random seed is used and shown otherwise. |
| `--size=small\|medium\|large` | Dataset size preset (default `medium`). |
| `--count=N` | Approximate total rows, distributed realistically across tables. |
| `--only=a,b` | Generate only these tables; missing required parents are added. |
| `--except=a,b` | Generate everything except these tables. |
| `--scenario="..."` | Describe the data you want (needs AI), or use a named scenario. |
| `--locale=ng` | Locale for names, addresses, phones and currency. |
| `--strategy=ai\|factory\|hybrid` | Whether to use your model factories. |
| `--no-ai` | Plan with built-in heuristics only. |
| `--replan` | Ask the AI for a new plan instead of reusing the saved one. |
| `--show-prompt` | Print exactly what would be sent to the AI. |
| `--fresh` | Delete existing rows in affected tables first (typed confirmation). |

Options combine where they make sense, e.g. `php artisan ai:seed --only=appointments --locale=ng --seed=42 --dry-run`.

## Dataset size

Sizes are derived from your relationship graph, not applied per table. Root tables get a base number of rows, each level of children fans out from its parents, reference tables (tags, roles, categories) stay small, and one-to-one tables never exceed their parent.

| Preset | Root rows | Fan-out | Per-table cap | History |
|---|---|---|---|---|
| `small` | 3 | ×3 | 150 | 6 months |
| `medium` | 10 | ×5 | 2,500 | 12 months |
| `large` | 40 | ×8 | 50,000 | 24 months |

`--count=5000` keeps these proportions and scales the total to about 5,000 rows. With AI planning, the AI adjusts proportions to your domain, for example `1 organization, 20 users, 100 projects, 2,000 tasks, 8,000 comments`. `max_rows` in the config (250,000 by default) caps every run.

## Selective seeding

```bash
php artisan ai:seed --only=appointments
```

If appointments need patients and doctors, AI Seeder reuses the rows already in those tables. Only when a required parent table is empty does it generate the minimum needed (the `small` preset). It never generates the whole database.

```bash
php artisan ai:seed --except=users
```

This skips `users`, but fails clearly if other tables require users and the table is empty.

## Reproducibility

```bash
php artisan ai:seed --seed=12345
```

The same schema, plan, locale and seed produce identical data:
- every table has its own seeded random stream, so `--only` doesn't shift other tables' values
- Faker is reseeded for every row
- AI plans are saved to files (see below)

Two caveats:
- **Password hashes** differ between runs, because bcrypt salts are random by design. The password itself (`password`) is always the same.
- **Dates without AI** are anchored to the current hour, so no-AI runs on different days shift dates. Saved AI plans pin the timeline, so AI runs reproduce exact dates on any day.

## AI planning

With `laravel/ai` installed, AI Seeder asks your configured model (Anthropic, OpenAI, Gemini, Ollama and others) to plan the data. The AI returns:

- a one-line understanding of what the application does
- realistic row counts and history length
- realistic proportions for statuses and other enums
- 15–40 believable example values for visible text (task titles, product names, notes) that fit your domain and scenario
- workflow rules, such as `cancelled_at` only when `status = cancelled`
- factory state mixes

```php
// config/ai-seeder.php
'ai' => [
    'enabled' => env('AI_SEEDER_AI_ENABLED', true),
    'driver' => 'laravel-ai',        // or your own AIProviderInterface class
    'provider' => env('AI_SEEDER_AI_PROVIDER'),   // anthropic, openai, gemini, ollama, ... (null = laravel/ai default)
    'model' => env('AI_SEEDER_AI_MODEL'),
    'timeout' => 120,
],
```

**What is sent:** only structure. That means table and column names, types, nullability, uniqueness, foreign keys, enum values, cast types, column comments, model class names, factory state names and the baseline plan. **Row data is never read for the prompt**, so a staging database restored from production can't leak through AI Seeder. To see the exact prompt:

```bash
php artisan ai:seed --dry-run --show-prompt
```

To keep a column out of the prompt entirely, add it to `excluded_columns` (it must be nullable or have a default).

**How AI output is handled:** it's treated as untrusted suggestions.
- Unknown tables and columns are dropped.
- Keys, references and polymorphic columns can't be changed.
- Suggested meanings must fit the column type.
- Enum weights must use allowed values.
- Example values are sanitised and always inserted as bound parameters.
- Counts are clamped to 10× the baseline, the timeline to 60 months, and the total to `max_rows`.

Run with `-v` to see what was ignored.

**Saved plans:** AI suggestions are saved as JSON in `storage/ai-seeder/plans` (or `plans_path`) and reused while the schema and options don't change. Re-runs are therefore free, fast and reproducible. Commit the folder to share datasets with your team, and use `--replan` to get a fresh plan. Saved files are re-validated on every use.

If the AI call fails, AI Seeder says so and continues with heuristics. The exception is a free-text `--scenario`, which can't be honoured without the AI, so the run stops.

## No-AI mode

```bash
php artisan ai:seed --no-ai
```

This mode needs no AI package or network access. It uses schema analysis, relationships, field inference (`first_name`, `email`, `price`, `*_at`, `status`, …), enum-aware distributions, workflow linking (`completed_at` ↔ `status`), your factories, and your configuration. It's also what runs when no AI provider is available.

## Scenarios

Free text (requires AI):

```bash
php artisan ai:seed --scenario="busy hospital with six months of appointment history"
php artisan ai:seed --scenario="property marketplace with active listings"
```

Named scenarios are reusable code that works without AI. See [ScenarioProvider](#scenario-providers).

```bash
php artisan ai:seed --scenario=demo
```

## Existing factories

```bash
php artisan ai:seed --strategy=factory   # factory values for every column a factory defines
php artisan ai:seed --strategy=hybrid    # factories for basic values; AI Seeder for distributions and timelines
```

In both modes AI Seeder owns keys, relationships, counts and ordering. In `hybrid` mode it also keeps enum distributions, timestamps and anything refined by AI or config. Tables without a factory are generated normally.

Factories are arbitrary code, so AI Seeder runs them defensively:
- **Nested factories are never expanded.** Every reference column is overridden before `raw()` runs, so `'team_id' => Team::factory()` is replaced, not executed.
- **Hooks never run.** Only `raw()` is called, so `afterMaking` and `afterCreating` callbacks are skipped.
- **Writes are caught.** The first row is probed inside a savepoint that is always rolled back. If the factory writes to the database, it's reported and not used for that table.
- **Late writes fail the run.** A write after the probe rolls back the whole run.

Factory states are discovered, and the AI or your config can mix them:

```php
'overrides' => ['users' => ['states' => ['admin' => 1, 'unverified' => 3, 'default' => 20]]],
```

## Locales

```bash
php artisan ai:seed --locale=ng
```

| Code | Faker locale | Currency |
|---|---|---|
| `ng` | `en_NG` + real states and cities, Nigerian mobile formats, six-digit postcodes | NGN |
| `us`, `gb`, `ca`, `au`, `ie`, `nz`, `za`, `in` | `en_*` | local |
| `de`, `fr`, `es`, `it`, `nl`, `br`, `mx`, `jp` | native | local |
| any Faker locale, e.g. `pt_BR` | as given | local |

With `--locale=ng`, a contact in `Ikeja` is in `Lagos`, phones look like `0803 123 4567` or `+234 803 123 4567`, and currency columns hold `NGN`.

All data is synthetic. Emails use reserved domains (`example.com`, `example.org`, `example.net`), and AI Seeder never looks up or imports real people's information.

## Configuration and overrides

Your configuration beats heuristics and AI, but never safety:

```php
'overrides' => [
    'users' => [
        'count' => 200,
        'fields' => [
            'status' => ['weights' => ['active' => 90, 'suspended' => 10]],
            'bio' => ['samples' => ['Coffee first.', 'Runner and reader.']],
            'phone' => ['semantic' => 'phone', 'null_rate' => 0.2],
            'manager_id' => ['null_rate' => 0.5, 'selection' => 'uniform', 'scope' => false],
            'suspended_at' => ['present_when' => ['status' => ['suspended']]],
        ],
    ],
],
```

| Field rule | Meaning |
|---|---|
| `semantic` | What the column holds (`person.first_name`, `number.money`, `text.title`, …; see `AISeeder\Semantics\Semantic`) |
| `weights` | Value proportions for enum-like columns |
| `samples` | Example values to draw from |
| `null_rate` / `true_rate` | Share of nulls / true values |
| `min` / `max` | Numeric bounds |
| `present_when` | Only set the value when another column has one of these values |
| `selection` | `skewed` (a few parents get most children; the default) or `uniform` |
| `scope` | For references: which shared ancestor to stay within, or `false` for any row |

Other settings:

| Setting | Purpose |
|---|---|
| `connection` | Connection to seed (default connection if null) |
| `model_paths` | Directories scanned for models (default `app/`); add module paths here |
| `excluded_tables` / `excluded_columns` | Never generated; wildcards allowed (framework tables are excluded by default) |
| `size`, `strategy`, `locale`, `currency` | Defaults for the CLI options |
| `chunk_size`, `existing_rows_limit`, `max_rows` | Performance and safety limits |

## Extending AI Seeder

Register extensions in `config/ai-seeder.php`. Classes are resolved from the container and checked before any database work. Use the `$random` and `$faker` you're given to keep output reproducible.

### Field generators

```php
use AISeeder\Extension\FieldContext;
use AISeeder\Extension\FieldGenerator;

class InvoiceNumberGenerator implements FieldGenerator
{
    public function generate(FieldContext $context): mixed
    {
        return 'INV-'.$context->time()->format('Y').'-'.$context->random->int(10000, 99999);
    }
}

// 'generators' => ['invoices.number' => InvoiceNumberGenerator::class]   // or '*.number', or 'semantic:phone'
```

### Row generators

Row generators work like a factory definition for one table. Keys and references always stay with AI Seeder.

```php
'row_generators' => ['products' => ProductRowGenerator::class],
```

### Reference pickers

A reference picker chooses which parent a reference points to, among candidates that are already scoped to the same tenant:

```php
use AISeeder\Extension\ReferenceContext;
use AISeeder\Extension\ReferencePicker;

class SeniorReviewerPicker implements ReferencePicker
{
    public function pick(ReferenceContext $context): int|string|null
    {
        return min($context->candidates); // the earliest member reviews everything
    }
}

// 'reference_pickers' => ['pull_requests.reviewer_id' => SeniorReviewerPicker::class]
```

### Scenario providers

```php
use AISeeder\Extension\ScenarioProvider;

class DemoScenario implements ScenarioProvider
{
    public function description(): ?string
    {
        return 'A polished sales demo: one flagship customer with a year of healthy activity.';
    }

    public function suggestions($analysis, $plan): array
    {
        return ['tables' => [['table' => 'organizations', 'count' => 1, 'fields' => []]]];
    }
}

// 'scenarios' => ['demo' => DemoScenario::class]   →   php artisan ai:seed --scenario=demo
```

### Application analyzers

Application analyzers add your domain knowledge to every plan, before the AI sees it:

```php
'analyzers' => [BillingConventions::class],
```

### Locales

```php
use AISeeder\Locale\FakerLocale;

class KenyaLocale extends FakerLocale
{
    public function __construct() { parent::__construct('en_US', 'KES'); }

    public function value(string $semantic, \AISeeder\Extension\FieldContext $context): mixed
    {
        return $semantic === \AISeeder\Semantics\Semantic::CITY
            ? $context->random->pick(['Nairobi', 'Mombasa', 'Kisumu', 'Nakuru'])
            : null;
    }
}

// 'locales' => ['ke' => KenyaLocale::class]   →   php artisan ai:seed --locale=ke
```

### AI providers

Implement `AISeeder\AI\AIProviderInterface` (instructions + prompt + JSON Schema in, decoded object out) and set `ai.driver` to your class to use any model without `laravel/ai`.

## Staging and destructive operations

On staging, AI Seeder adds to existing data by default. Non-interactive runs (`--no-interaction`) are allowed because they only add rows.

`--fresh` deletes before generating:

1. It checks the environment. Production never gets this far.
2. It lists every affected table with its row count, including tables that reference the regenerated ones, since deleting parents would orphan them.
3. It requires you to **type the database name**, and refuses in non-interactive mode.
4. It deletes children first, using `DELETE` rather than `TRUNCATE ... CASCADE`, so no table you didn't confirm is touched.
5. Deletion and generation run in one transaction. If anything fails, nothing changes.

## Performance

- Rows are generated in chunks and bulk inserted, never one `Model::create()` at a time.
- Keys are pre-assigned, so IDs never have to be read back.
- Only the columns other rows depend on are kept in memory.
- Chunk sizes respect each driver's parameter limits.

Measured with the included benchmark (`vendor/bin/pest --group=benchmark`), about 100k rows across 8 related tables:

| Database | Time | Peak memory |
|---|---|---|
| SQLite | ~11 s | 72 MB |
| MySQL 8.0 | ~16 s | 72 MB |

Because inserts bypass Eloquent, **model events and observers don't fire**. AI Seeder applies the storage side of casts itself: JSON, dates, and enums, plus encrypted casts using your `APP_KEY`.

## Database support

The schema is read through Laravel's schema builder, so AI Seeder works with every driver Laravel supports. Enum-style `CHECK` constraints (created by `$table->enum()`) are read per driver: SQLite and PostgreSQL through their catalogs, MySQL/MariaDB from the column type, and SQL Server on a best-effort basis. Database-specific behaviour, such as PostgreSQL sequence resets and SQL Server identity inserts, is isolated in the executor.

## Limitations

- **Composite foreign keys** aren't generated. Nullable ones are left null, and tables that require one are skipped with a note.
- **Polymorphic relations** need a model that declares `morphMany`/`morphOne`/`morphToMany`, so AI Seeder knows the target types.
- **A cycle made only of required foreign keys** can't be inserted by anyone. AI Seeder names the tables and stops.
- **Observers and model events** don't fire (see [Performance](#performance)).
- **Generated users' password** is `password`.

## Troubleshooting

| Message | What to do |
|---|---|
| `AI Seeder cannot run in this environment` | Set `APP_ENV` to `local`, `dev`, `development` or `staging`. There is no override. |
| `looks like a production database` | You're pointing at something named like production. Point at a non-production database. |
| `N of M migrations have not been run` | Run `php artisan migrate`. AI Seeder reads the migrated schema. |
| `Cannot skip [x]: other tables require it` | Seed `x` first, or don't `--except` it. |
| `Tables [a, b] reference each other through required foreign keys` | Make one of the columns nullable, or exclude a table. |
| `--scenario needs AI planning` | Install and configure `laravel/ai`, or use a named scenario. |
| `AI planning failed (...)` | Check your `laravel/ai` credentials and model. AI Seeder continued with heuristics. |
| `... was not used for [table]: its definition writes to the database` | That factory creates records while it's being defined. Fix it, or use `--strategy=ai`. |
| `rows skipped after repeated unique-constraint collisions` | A unique column has too few possible values; give it `samples` or a custom generator. |

For more detail, run with `-v`.

## Testing and contributing

```bash
composer install
vendor/bin/pest                                  # SQLite in memory
AI_SEEDER_TEST_DRIVER=mysql DB_PASSWORD=secret vendor/bin/pest
AI_SEEDER_TEST_DRIVER=pgsql DB_USERNAME=postgres DB_PASSWORD=secret vendor/bin/pest
vendor/bin/pest --group=benchmark                # 100k rows
```

The suite never calls a real AI provider. AI behaviour is tested with fakes, including hostile AI output.

## License

MIT
