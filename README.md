# RealSeed

[![Latest Version on Packagist](https://img.shields.io/packagist/v/ayangzy/real-seed.svg?style=flat-square)](https://packagist.org/packages/ayangzy/real-seed)
[![Total Downloads](https://img.shields.io/packagist/dt/ayangzy/real-seed.svg?style=flat-square)](https://packagist.org/packages/ayangzy/real-seed)
[![Tests](https://img.shields.io/github/actions/workflow/status/ayangzy/real-seed/tests.yml?branch=main&label=tests&style=flat-square)](https://github.com/ayangzy/real-seed/actions/workflows/tests.yml)
[![PHP Version](https://img.shields.io/packagist/dependency-v/ayangzy/real-seed/php.svg?style=flat-square)](https://packagist.org/packages/ayangzy/real-seed)
[![License](https://img.shields.io/packagist/l/ayangzy/real-seed.svg?style=flat-square)](LICENSE)

**Realistic, relational, time-coherent synthetic data for your Laravel app, generated from your app's actual structure.**

```bash
php artisan real:seed        # aliases: php artisan realseed, php artisan ai:seed
```

## The problem

You've built your application. Now you need it to *look* like a real application: for local development, UI work, demos, QA and staging.

Laravel gives you factories, Faker and seeders, and they make it easy to create data. But they work one model and one field at a time. They don't know how your application's records relate, what order things happen in, or what your data means. So you get data that is technically valid but obviously fake:

```text
Task #412    "Voluptatem quia et"     assigned to a user from a different company
Invoice #88  paid on 2024-03-02       for a subscription created on 2025-11-19
Order #15    status: delivered        delivered_at: null
User #3      2 comments               User #4: 2 comments       User #5: 2 comments ...
```

The database is full, but the dashboards, tables, reports and activity feeds still feel empty and artificial. The records don't tell a coherent story.

To fix that, developers end up hand-writing and maintaining large seeders: wiring up relationships, ordering dates, choosing statuses, inventing believable text. It's slow, it breaks whenever the schema changes, and it has to be redone for every project.

**RealSeed does that work for you.** It reads your application's actual structure and generates data that is coherent across the whole application:

```text
Task #412    "Fix invoice PDF rounding"   assigned to a teammate in the same company
Invoice #88  paid 2026-02-03              for a subscription created 2026-01-12
Order #15    status: delivered            delivered_at: 2026-04-18 14:02
User #3      41 comments                  User #4: 6 comments       User #5: 0 comments ...
```

RealSeed's engine handles relationships, timelines, statuses and activity patterns, and [AI planning](#ai-planning) writes the quantities, proportions and text for *your* domain.

## What RealSeed does

RealSeed reads your migrated database, models, enums and factories. It then fills local, development and staging databases with data that looks like real usage:

- Organizations have members.
- A task's assignee works in the same organization as the task's project.
- Invoices come after subscriptions, and `completed_at` is only set on completed records.
- A few users are very active and most are occasional.

It is **not** a Faker wrapper. Faker generates values; RealSeed generates *an application's worth of data* that makes sense together.

> [!IMPORTANT]
> RealSeed is for **local development, development environments, staging, demos, and QA/UAT done on staging**.
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
- [Scenarios](#scenarios)
- [Existing factories](#existing-factories)
- [Locales](#locales)
- [Configuration and overrides](#configuration-and-overrides)
- [Extending RealSeed](#extending-realseed)
- [Protecting real data](#protecting-real-data)
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
Plan                    baseline → your analyzers → AI plan → scenario → config overrides
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
- Laravel 12 or 13
- SQLite, MySQL/MariaDB or PostgreSQL (SQL Server is best-effort)
- An AI provider key: OpenAI, Gemini, Anthropic and others through the [Laravel AI SDK](https://github.com/laravel/ai), which RealSeed installs for you. [Ollama](https://ollama.com) works locally with no key.

## Installation

```bash
composer require --dev ayangzy/real-seed   # local development only
composer require ayangzy/real-seed         # also on staging (deploys often skip dev packages)
```

Installing it outside `require-dev` is safe: the environment guard, not the install location, keeps it out of production.

Publish the config if you want to change the defaults:

```bash
php artisan vendor:publish --tag=realseed-config
```

Then add your AI provider's key to `.env` (see [Setting it up](#setting-it-up)):

```env
OPENAI_API_KEY=sk-...
```

## Quick start

```bash
php artisan migrate
php artisan real:seed --dry-run      # see what would be generated
php artisan real:seed                # generate it
```

```text
RealSeed

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
✓ AI plan saved to storage/realseed/plans/3f2c9a….json
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

RealSeed runs **only** when `APP_ENV` is exactly one of:

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
RealSeed

Environment: production

✗ RealSeed cannot run in this environment.

RealSeed only supports:
- local
- dev
- development
- staging

No database changes were made.
```

The environment name only protects you if it's accurate, so RealSeed also checks the database it will write to. These checks can only add friction, never remove it:

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
| `--replan` | Ask the AI for a new plan instead of reusing the saved one. |
| `--show-prompt` | Print exactly what would be sent to the AI. |
| `--fresh` | Delete existing rows in affected tables first (typed confirmation). |

Options combine where they make sense, e.g. `php artisan real:seed --only=appointments --locale=ng --seed=42 --dry-run`.

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
php artisan real:seed --only=appointments
```

If appointments need patients and doctors, RealSeed reuses the rows already in those tables. Only when a required parent table is empty does it generate the minimum needed (the `small` preset). It never generates the whole database.

```bash
php artisan real:seed --except=users
```

This skips `users`, but fails clearly if other tables require users and the table is empty.

## Reproducibility

```bash
php artisan real:seed --seed=12345
```

The same schema, plan, locale and seed produce identical data:
- every table has its own seeded random stream, so `--only` doesn't shift other tables' values
- Faker is reseeded for every row
- AI plans are saved to files (see below)

Two caveats:
- **Password hashes** differ between runs, because bcrypt salts are random by design. The password itself (`password`) is always the same.
- **Dates** come from the saved AI plan, which pins the timeline, so the same plan reproduces exact dates on any day. `--replan` starts a new timeline.

## AI planning

RealSeed always plans with AI, using your configured model (OpenAI, Gemini, Anthropic, Ollama and others). The AI returns:

- a one-line understanding of what the application does
- realistic row counts and history length
- realistic proportions for statuses and other enums
- 8–15 believable example values for visible text (task titles, product names, notes) that fit your domain and scenario
- workflow rules, such as `cancelled_at` only when `status = cancelled`
- factory state mixes

### Setting it up

The Laravel AI SDK comes with RealSeed; you only add a key:

```env
# .env
OPENAI_API_KEY=sk-...              # required: read by laravel/ai, not by RealSeed

# Optional RealSeed settings (leave them out to use the defaults)
REALSEED_AI_PROVIDER=openai        # anthropic, gemini, ollama, ... (default: laravel/ai's default, openai)
REALSEED_AI_MODEL=gpt-5-mini       # default: the provider's default model
REALSEED_AI_TIMEOUT=300            # seconds to wait for the AI's answer (default 300)
```

Using another provider means setting its key and the provider name, e.g. `ANTHROPIC_API_KEY=…` with `REALSEED_AI_PROVIDER=anthropic`. [Ollama](https://ollama.com) runs models locally for free with no key: `REALSEED_AI_PROVIDER=ollama` and `REALSEED_AI_MODEL=llama3.1`.

Run `php artisan config:clear` after changing `.env`. The command reports the AI status on every run (`✓ AI planning: …` or `! AI planning: …`) and repeats it as "Planned by" in the plan and the summary.

The matching config, for reference:

```php
// config/realseed.php
'ai' => [
    'enabled' => env('REALSEED_AI_ENABLED', true),
    'driver' => 'laravel-ai',        // or your own AIProviderInterface class
    'provider' => env('REALSEED_AI_PROVIDER'),   // anthropic, openai, gemini, ollama, ... (null = laravel/ai default)
    'model' => env('REALSEED_AI_MODEL'),
    'timeout' => 120,
],
```

**What is sent:** only structure. That means table and column names, types, nullability, uniqueness, foreign keys, enum values, cast types, column comments, model class names, factory state names and the baseline plan. **Row data is never read for the prompt**, so a staging database restored from production can't leak through RealSeed. To see the exact prompt:

```bash
php artisan real:seed --dry-run --show-prompt
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

**Saved plans:** AI suggestions are saved as JSON in `storage/realseed/plans` (or `plans_path`) and reused while the schema and options don't change. Re-runs are therefore free, fast and reproducible. Commit the folder to share datasets with your team, and use `--replan` to get a fresh plan. Saved files are re-validated on every use.

If the AI can't be reached or the request fails, RealSeed stops before writing anything and shows the provider's own explanation (missing credit, rate limit, timeout, …) with a hint.

## Scenarios

Free text (requires AI):

```bash
php artisan real:seed --scenario="busy hospital with six months of appointment history"
php artisan real:seed --scenario="property marketplace with active listings"
```

Named scenarios are reusable code; their description is given to the AI. See [ScenarioProvider](#scenario-providers).

```bash
php artisan real:seed --scenario=demo
```

## Existing factories

```bash
php artisan real:seed --strategy=factory   # factory values for every column a factory defines
php artisan real:seed --strategy=hybrid    # factories for basic values; RealSeed for distributions and timelines
```

In both modes RealSeed owns keys, relationships, counts and ordering. In `hybrid` mode it also keeps enum distributions, timestamps and anything refined by AI or config. Tables without a factory are generated normally.

Factories are arbitrary code, so RealSeed runs them defensively:
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
php artisan real:seed --locale=ng
```

| Code | Faker locale | Currency |
|---|---|---|
| `ng` | `en_NG` + real states and cities, Nigerian mobile formats, six-digit postcodes | NGN |
| `us`, `gb`, `ca`, `au`, `ie`, `nz`, `za`, `in` | `en_*` | local |
| `de`, `fr`, `es`, `it`, `nl`, `br`, `mx`, `jp` | native | local |
| any Faker locale, e.g. `pt_BR` | as given | local |

With `--locale=ng`, a contact in `Ikeja` is in `Lagos`, phones look like `0803 123 4567` or `+234 803 123 4567`, and currency columns hold `NGN`.

All data is synthetic. Emails use reserved domains (`example.com`, `example.org`, `example.net`), and RealSeed never looks up or imports real people's information.

## Configuration and overrides

Your configuration beats AI suggestions, but never safety:

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
| `semantic` | What the column holds (`person.first_name`, `number.money`, `text.title`, …; see `Ayangzy\RealSeed\Semantics\Semantic`) |
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
| `protected_tables` | Tables with real data: never generated into or deleted, still used as parents. See [Protecting real data](#protecting-real-data). |
| `morph_targets` | What a polymorphic relation can point to, when no model declares it: `'bank_accounts.account' => [User::class, Company::class]` |
| `size`, `strategy`, `locale`, `currency` | Defaults for the CLI options |
| `chunk_size`, `existing_rows_limit`, `max_rows` | Performance and safety limits |

## Extending RealSeed

Register extensions in `config/realseed.php`. Classes are resolved from the container and checked before any database work. Use the `$random` and `$faker` you're given to keep output reproducible.

### Field generators

```php
use Ayangzy\RealSeed\Extension\FieldContext;
use Ayangzy\RealSeed\Extension\FieldGenerator;

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

Row generators work like a factory definition for one table. Keys and references always stay with RealSeed.

```php
'row_generators' => ['products' => ProductRowGenerator::class],
```

### Reference pickers

A reference picker chooses which parent a reference points to, among candidates that are already scoped to the same tenant:

```php
use Ayangzy\RealSeed\Extension\ReferenceContext;
use Ayangzy\RealSeed\Extension\ReferencePicker;

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
use Ayangzy\RealSeed\Extension\ScenarioProvider;

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

// 'scenarios' => ['demo' => DemoScenario::class]   →   php artisan real:seed --scenario=demo
```

### Application analyzers

Application analyzers add your domain knowledge to every plan, before the AI sees it:

```php
'analyzers' => [BillingConventions::class],
```

### Locales

Nigeria is built in (`--locale=ng`). To go further, write your own locale. For example, an app that only serves Lagos can use real Lagos neighbourhoods, local government areas and streets:

```php
use Ayangzy\RealSeed\Extension\FieldContext;
use Ayangzy\RealSeed\Locale\FakerLocale;
use Ayangzy\RealSeed\Semantics\Semantic;

class LagosLocale extends FakerLocale
{
    /** Neighbourhood => local government area */
    private const AREAS = [
        'Lekki' => 'Eti-Osa', 'Victoria Island' => 'Eti-Osa', 'Ikoyi' => 'Eti-Osa', 'Ajah' => 'Eti-Osa',
        'Ikeja' => 'Ikeja', 'Allen' => 'Ikeja', 'Maryland' => 'Kosofe', 'Gbagada' => 'Kosofe',
        'Yaba' => 'Lagos Mainland', 'Surulere' => 'Surulere', 'Festac' => 'Amuwo-Odofin', 'Ikorodu' => 'Ikorodu',
    ];

    private const STREETS = ['Admiralty Way', 'Allen Avenue', 'Awolowo Road', 'Herbert Macaulay Way', 'Adeola Odeku Street', 'Ogunlana Drive'];

    public function __construct()
    {
        parent::__construct('en_NG', 'NGN'); // Nigerian names from Faker, prices in naira
    }

    public function value(string $semantic, FieldContext $context): mixed
    {
        return match ($semantic) {
            Semantic::CITY => $context->random->pick(array_keys(self::AREAS)),
            Semantic::STATE => 'Lagos',
            Semantic::STREET => $context->random->int(1, 120).' '.$context->random->pick(self::STREETS),
            Semantic::PHONE => '0'.$context->random->pick(['803', '806', '813', '816', '703', '903', '905']).' '
                .$context->random->string(3, '0123456789').' '.$context->random->string(4, '0123456789'),
            default => null, // everything else: Faker's en_NG defaults
        };
    }
}

// config/realseed.php: 'locales' => ['lagos' => LagosLocale::class]
// php artisan real:seed --locale=lagos
```

Return `null` for anything you don't want to change, and RealSeed falls back to the Faker locale you passed to the constructor. Use `$context->random` for randomness so `--seed` stays reproducible.

### AI providers

Implement `Ayangzy\RealSeed\AI\AIProviderInterface` (instructions + prompt + JSON Schema in, decoded object out) and set `ai.driver` to your class to use any model without `laravel/ai`.

## Protecting real data

Some tables hold real data your app depends on: roles and permissions, plans, your product catalogue, the admin account. List them in `protected_tables`:

```php
// config/realseed.php
'protected_tables' => ['roles', 'permissions', 'plans', 'products'],
```

RealSeed then:

- **never adds rows to them,** and refuses `--only` on them
- **never deletes from them,** not even with `--fresh`. If `--fresh` would have to (because a protected table points at a table being regenerated), it stops and tells you instead.
- **still links new data to their existing rows,** so generated users get your real roles and generated orders use your real products

Normal runs never change or delete existing rows in any table; they only add new ones. Protection matters for `--fresh`, and for keeping fake rows out of tables that should only hold real data.

## Staging and destructive operations

On staging, RealSeed adds to existing data by default. Non-interactive runs (`--no-interaction`) are allowed because they only add rows.

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

Because inserts bypass Eloquent, **model events and observers don't fire**. RealSeed applies the storage side of casts itself: JSON, dates, and enums, plus encrypted casts using your `APP_KEY`.

## Database support

The schema is read through Laravel's schema builder, so RealSeed works with every driver Laravel supports. Enum-style `CHECK` constraints (created by `$table->enum()`) are read per driver: SQLite and PostgreSQL through their catalogs, MySQL/MariaDB from the column type, and SQL Server on a best-effort basis. Database-specific behaviour, such as PostgreSQL sequence resets and SQL Server identity inserts, is isolated in the executor.

## Limitations

- **Composite foreign keys** aren't generated. Nullable ones are left null, and tables that require one are skipped with a note.
- **Polymorphic detection:** a `{name}_type` + `{name}_id` pair counts as polymorphic only when a model declares it (`morphTo`, `morphMany`, …) or nothing contradicts it. A `_type` column with fixed values like `checking`/`savings`, or an `_id` column that is a foreign key, is treated as ordinary data.
- **Links by convention:** an `_id` column without a foreign key or relation (`account_id`) is linked to the matching table (`accounts.id`) when one exists.
- **Polymorphic relations** need to know their target types. RealSeed finds them from `morphMany`/`morphOne`/`morphToMany` relations on your models, from types already stored in the table, or from `morph_targets` in the config. Without any of these, the table (and tables that require it) is skipped with a note before anything is written.
- **A cycle made only of required foreign keys** can't be inserted by anyone. RealSeed names the tables and stops.
- **Observers and model events** don't fire (see [Performance](#performance)).
- **Generated users' password** is `password`.

## Troubleshooting

| Message | What to do |
|---|---|
| `RealSeed cannot run in this environment` | Set `APP_ENV` to `local`, `dev`, `development` or `staging`. There is no override. |
| `looks like a production database` | You're pointing at something named like production. Point at a non-production database. |
| `N of M migrations have not been run` | Run `php artisan migrate`. RealSeed reads the migrated schema. |
| `Cannot skip [x]: other tables require it` | Seed `x` first, or don't `--except` it. |
| `Tables [a, b] reference each other through required foreign keys` | Make one of the columns nullable, or exclude a table. |
| `no AI provider is available` | Add your provider's key to `.env` (e.g. `OPENAI_API_KEY`), then `php artisan config:clear`. |
| `AI planning failed: ...` | Read the provider's explanation in the message: add API credit, wait out a rate limit, or raise `REALSEED_AI_TIMEOUT`. Nothing was written. |
| `... was not used for [table]: its definition writes to the database` | That factory creates records while it's being defined. Fix it, or use `--strategy=ai`. |
| `RealSeed can't tell what [x] can point to` | Add a `morphMany`/`morphOne` relation named `x` to each owning model, or set `'morph_targets' => ['table.x' => [Model::class]]` in the config. |
| `Skipping [a]: it needs rows in [b]` | `b` is skipped (see the note above it) and empty; fix `b` or seed it first. |
| `rows skipped after repeated unique-constraint collisions` | A unique column has too few possible values; give it `samples` or a custom generator. |

For more detail, run with `-v`.

## Testing and contributing

```bash
composer install
vendor/bin/pest                                  # SQLite in memory
REALSEED_TEST_DRIVER=mysql DB_PASSWORD=secret vendor/bin/pest
REALSEED_TEST_DRIVER=pgsql DB_USERNAME=postgres DB_PASSWORD=secret vendor/bin/pest
vendor/bin/pest --group=benchmark                # 100k rows
```

The suite never calls a real AI provider. AI behaviour is tested with fakes, including hostile AI output.

## Releasing (maintainers)

Pushing to `main` doesn't publish anything by itself. To release, put `[release]` in the commit message; after the tests pass, GitHub tags the next version and Packagist publishes it:

| Commit message contains | Version |
|---|---|
| `[release]` | patch: v0.2.4 → v0.2.5 |
| `[release] [minor]` | minor: v0.2.5 → v0.3.0 |
| `[release] [major]` | major: v0.3.0 → v1.0.0 |

## License

RealSeed is open-source software licensed under the [MIT license](LICENSE).

Copyright © 2026 ayangzy
