<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Database Connection
    |--------------------------------------------------------------------------
    |
    | The connection AI Seeder writes to. Null uses the application's default.
    |
    | Note: the supported environments (local, dev, development, staging) are
    | enforced by the package itself and intentionally cannot be configured.
    |
    */

    'connection' => env('AI_SEEDER_CONNECTION'),

    /*
    |--------------------------------------------------------------------------
    | Model Paths
    |--------------------------------------------------------------------------
    |
    | Directories scanned for Eloquent models. Any layout works; add module or
    | domain directories here if your models live outside app/.
    |
    */

    'model_paths' => [
        app_path(),
    ],

    /*
    |--------------------------------------------------------------------------
    | Excluded Tables and Columns
    |--------------------------------------------------------------------------
    |
    | Tables and columns AI Seeder never generates data for. Wildcards are
    | supported. Columns use "table.column" or "*.column". Required columns
    | cannot be excluded because inserts would fail without them.
    |
    */

    'excluded_tables' => [
        'migrations',
        'cache',
        'cache_locks',
        'sessions',
        'jobs',
        'job_batches',
        'failed_jobs',
        'password_reset_tokens',
        'personal_access_tokens',
        'telescope_*',
        'pulse_*',
    ],

    'excluded_columns' => [],

    /*
    |--------------------------------------------------------------------------
    | Generation Defaults
    |--------------------------------------------------------------------------
    |
    | size:     small, medium, or large (overridden by --size)
    | locale:   Faker locale for names, addresses, and phone numbers
    | currency: ISO code used for currency columns
    |
    */

    'size' => 'medium',

    'locale' => 'en_US',

    'currency' => 'USD',

    /*
    |--------------------------------------------------------------------------
    | Performance
    |--------------------------------------------------------------------------
    |
    | chunk_size:          maximum rows per INSERT statement
    | existing_rows_limit: existing rows loaded per table to connect new data to
    |
    */

    'chunk_size' => 500,

    'existing_rows_limit' => 100000,

];
