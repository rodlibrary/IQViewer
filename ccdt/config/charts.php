<?php

/*
|--------------------------------------------------------------------------
| Chart builder settings
|--------------------------------------------------------------------------
|
| Settings for the chart builder (app/Libraries/Charts, ChartController)
| and the matplotlib render service (charts-service/).
|
*/

return [

    /*
    | Base URL of the matplotlib render service (docker-compose service
    | "charts"). Only reachable from inside the compose network.
    */
    'service_url' => env('CHARTS_SERVICE_URL', 'http://charts:8000'),

    /*
    | Shared secret sent to the render service in the X-Charts-Token header.
    | Leave empty to disable the check (the service is not published to the
    | host, so this is defence in depth).
    */
    'service_token' => env('CHARTS_SERVICE_TOKEN', ''),

    'service_timeout' => 30,

    /*
    | Database connection used for chart queries. Defaults to a connection that
    | falls back to the application's own credentials; point
    | CHARTS_DB_USERNAME / CHARTS_DB_PASSWORD at a SELECT-only MySQL user to
    | make sure the chart builder can never change data.
    */
    'connection' => env('CHARTS_DB_CONNECTION', 'mysql_charts'),

    /*
    | Per-query execution limit, in milliseconds. Applied with the
    | MAX_EXECUTION_TIME optimizer hint (MySQL 5.7.8+); MariaDB gets
    | max_statement_time set on the session instead.
    */
    'timeout_ms' => 30000,

    /*
    | Small-cell suppression. A bar / point / cell built from fewer than this
    | many records is removed from the chart and counted in "suppressed".
    | Set to 0 or 1 to turn it off.
    */
    'min_cell_size' => 10,

    /*
    | Columns whose name matches any of these patterns can never be used as a
    | dimension, measure or filter (names, contact details, free text...).
    | Free-text column TYPES (text, mediumtext, longtext, blob) are always
    | refused regardless of this list.
    */
    'blocked_columns' => [
        '/name/i',
        '/email/i',
        '/phone/i',
        '/address_line/i',
        '/street/i',
        '/subject/i',
        '/text/i',
        '/salutation/i',
        '/birth|dob/i',
        '/ssn|social_security/i',
        '/note/i',
        '/comment/i',
        '/description/i',
        '/^field_?value|merge_data|fill_in_data/i',
    ],

    /*
    | Hard limits on what one chart may ask for.
    */
    'max_categories' => 50,   // bars / heatmap columns
    'max_series' => 8,        // series before the rest are folded into "Other"
    'max_buckets' => 2000,    // time buckets / histogram bins
    'max_filters' => 8,
    'max_in_values' => 50,

    /*
    | Who may use the chart builder. false = every signed-in user whose account
    | has access (the data pages are open to them too); true = administrators
    | only. Charts only ever show counts and totals of tables an administrator
    | has left enabled, with small groups hidden (min_cell_size).
    */
    'admin_only' => env('CHARTS_ADMIN_ONLY', false),

    /*
    | ID indexes. Charts that combine two tables join them on an ID column and
    | need an index on it. After each import the import job adds an index to
    | every column whose name matches index_columns (and not index_exclude), up
    | to index_max per table. Set CHARTS_INDEX_ON_IMPORT=false to turn it off;
    | tables imported earlier can be indexed with
    |     php artisan table:create:index --all
    */
    'index_on_import' => env('CHARTS_INDEX_ON_IMPORT', true),
    'index_columns' => ['/_id$/'],
    'index_exclude' => ['/^user_id$/', '/^workflow_person_id$/'],
    'index_max' => 10,

    /*
    | How long a rendered chart is cached (minutes). The cache key includes the
    | table's updated_at, so re-importing data invalidates it.
    */
    'cache_minutes' => 10,

    /*
    | Stored results ("summary tables", table chart_summaries). A chart whose
    | query took at least summary_min_ms keeps its aggregated result until the
    | table changes, so it opens instantly the next time (for every user). After
    | an import the ready-made charts of the table are built in the background
    | (queue "low"); set CHARTS_SUMMARY_ON_IMPORT=false to turn that off. Build
    | them for existing tables with:  php artisan table:create:summary --all
    */
    'summary_min_ms' => 3000,
    'summary_on_import' => env('CHARTS_SUMMARY_ON_IMPORT', true),
];
