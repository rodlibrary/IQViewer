# Chart builder

A page for every signed-in user (**Charts** in the top menu, `/charts`) for drawing
charts of an imported table, or of two tables of the same collection combined,
with matplotlib: bar, ranked bar, line over time,
stacked bar, histogram and heatmap. It ships with ready-made charts for IQ SCDIF
tables (communications per month by type, top topic codes, days to close,
workflow starts, addresses by state...) and lets you build others by choosing
fields, a measure, filters and a split. Charts can be saved and reopened.

## How it works

```
browser  --(JSON chart spec)-->  ChartController (auth middleware, throttled)
                                   ChartQueryBuilder: validate spec, build ONE aggregate SQL query
                                   MySQL (read-only connection `mysql_charts`)
                                   ChartQueryBuilder::shape: pivot rows, hide small cells
                                   ChartRenderClient --(aggregated series)--> charts service (FastAPI + matplotlib)
browser  <--(PNG + the plotted numbers)--
```

* Who can use it: any signed-in user whose account is not restricted. They see
  only tables an administrator has left enabled, in enabled collections, and
  only counts and totals (small groups hidden, see below). Set
  `CHARTS_ADMIN_ONLY=true` to limit the page to administrators. Saved charts
  belong to the user who saved them; administrators can see and delete all.
* The browser never sends SQL. A spec names columns, a measure and filters;
  every column must exist in the table, every value is a bound parameter.
* The charts service only ever receives aggregated numbers, never records. It
  is not published to the host; only the app container can reach it.
* `app/Libraries/Charts/ChartQueryBuilder.php` has no Laravel dependency and is
  covered by `tests/Unit/Charts/ChartQueryBuilderUnitTest.php`.
  `charts-service/tests/` covers the renderer and the HTTP API.

## Setup

1. Run the new migrations (create `saved_charts` and `chart_summaries`):
   `docker exec -it ccdt_php php artisan migrate`
2. Rebuild and start the new service and the app:
   `docker-compose up -d --build charts app`
3. Tables imported from now on get indexes on their ID columns automatically
   (see "Combining two tables"). For tables imported earlier, run once:
   `docker exec -it ccdt_php php artisan table:create:index --all`
   (`--dry-run` lists what it would add; a table name instead of `--all` does one table).
4. Optional, for tables imported before this change, build their stored chart
   results once (see "Stored results"):
   `docker exec -it ccdt_php php artisan table:create:summary --all`
5. Optional but recommended, in `env/.env.prod`:
   * `CHARTS_DB_USERNAME` / `CHARTS_DB_PASSWORD` - a MySQL user with only
     `SELECT` on the CCDT database (without them the app's own login is used):
     `CREATE USER 'ccdt_charts'@'%' IDENTIFIED BY '...'; GRANT SELECT ON ccdt.* TO 'ccdt_charts'@'%';`
   * `CHARTS_SERVICE_TOKEN` - a shared secret. Set the same value in a `.env`
     file next to `docker-compose.yml` so the charts container receives it.
   * `CHARTS_SERVICE_URL` - only if the service is not reachable as `http://charts:8000`.
   * `CHARTS_ADMIN_ONLY` - `true` restricts the page to administrators (default `false`).
   * `CHARTS_INDEX_ON_IMPORT` - `false` stops the import adding ID indexes (default `true`).
   * `CHARTS_SUMMARY_ON_IMPORT` - `false` stops the import queueing the stored chart results (default `true`).

MySQL strict mode (`'strict' => false` in `config/database.php`) is deliberately
left as it is; nothing in the chart builder depends on it.

Local development without Docker: `cd charts-service && pip install -r requirements.txt && uvicorn app:app --port 8000`,
and set `CHARTS_SERVICE_URL=http://127.0.0.1:8000`.

## What a chart may and may not use

* Never offered, and refused if sent: `id`, `srchindex`, timestamps; free-text
  column types (`text`, `mediumtext`, `longtext`); and columns whose name matches
  `blocked_columns` in `config/charts.php` (names, e-mail, phone, address lines,
  subject, text, salutation, birth date, notes, descriptions...). The list
  applies to dimensions, measures and filters alike, so a blocked field cannot
  be probed through filter counts either. Extend the list to match your Presidio
  field policy.
* **Small-cell suppression:** a bar, point or cell built from fewer than
  `min_cell_size` (default 10) records is hidden and counted in the footnote.
* Limits (config/charts.php): 50 categories, 8 series (the rest become
  "Other"), 2,000 time buckets, 8 filters, 30 s per query.
* Dates are expected as `YYYYMMDD` numbers (the SCDIF format). "Group dates by
  month/year" ignores values outside 1900-2099.

## Combining two tables

Choose **Combine with another table** to chart across two tables of the same
collection, for example communications by the state held in the address table,
or topics by month. Then fields of the second table appear in every field
list as "Other table: ...", and can be used for the X axis, the split, the
measure and filters.

* The tables are linked on an ID field both have (for example `address_id`,
  `communication_id`, `workflow_id`). Only records with a matching, non-blank ID
  in both tables are counted, and counts are of matched rows. One combination
  per chart.
* Only ID fields (names ending in `_id`, except `user_id` and
  `workflow_person_id`) can be linked, and only when both sides are indexed;
  otherwise MySQL scans both tables and the query never finishes. A link that is
  not indexed is shown greyed out with the command to run.
* **Indexes at import:** when a file import finishes, `FileImport` adds a
  `idx_<field>` index on each ID field of the new table (integer fields, or text
  of at most 150 characters; at most `index_max`, default 10, per table) in one
  `ALTER TABLE`. A failure is logged and never fails the import. Settings:
  `index_on_import`, `index_columns`, `index_exclude`, `index_max` in
  `config/charts.php`. `php artisan table:create:index` does the same for
  existing tables and is safe to repeat.
* Text and integer ID fields can be linked (for example a text `address_id` in
  the communications table to an integer one in the address table); MySQL uses
  the integer side's index.
* Ready-made combined charts: communications by state, communication type by
  state, topics by month, communications by workflow type. They are offered when
  the two tables have the fields they need, whichever table is chosen first.

## Stored results (summary tables)

A chart is an aggregate query; the numbers it returns are small. They are kept
in the `chart_summaries` table so the same chart opens without running the
query again, for every user:

* **After an import** the import job moves the table's `updated_at` and queues
  `RefreshChartSummaries` on the `low` queue (after a two minute delay; it keeps
  waiting while another file for the table is still queued). That job builds the
  ready-made charts of the table, alone and combined with each other table of
  its collection (the table as the main table), and logs how many it built and
  skipped. Combined charts whose ID fields are not indexed are skipped.
* **Slow charts keep themselves.** Any chart whose query took `summary_min_ms`
  (default 3,000) or longer is stored the first time it is drawn.
* Stored results are tied to the tables' `updated_at`, so an import makes them
  unreachable and the refresh replaces them. The page says "stored result" under
  a chart that came from one. Only aggregated numbers are stored, never records.
* `php artisan table:create:summary {table}` / `--all` builds them by hand
  (it can take several minutes per table on millions of rows).

For counts of everything that has been imported (not charts you build yourself), see the
[data dashboard](dashboard.md).

## Hover and zoom

Under **Hover and zoom** (next to **Image** above the chart) the same numbers
are drawn in the browser as SVG (`public/js/chart-interactive.js`, no library):
hovering or touching shows the values at that position, and dragging across
the chart zooms to that range (double-click or **Reset zoom** to go back).
Horizontal bar charts have hover but no zoom. The choice is remembered in the
browser. The image view (matplotlib) is unchanged and is what **Download PNG**
saves.

## Importing a pipe-delimited export (`import:large-dataset`)

    php artisan import:large-dataset export.txt "Collection name" [--delimiter=tab] [--chunk=10000] [--replace]

The file goes in `data/flatfiles/` on your computer (the folder the app sees as `storage/app/flatfiles/`). The command reads the whole file twice:

1. **Scan.** For each record type (the first field: `1A`, `2A`, ...) it finds the field names in the
   `recordtypes` layouts (the collection's own CMS layout first) and works out the type of every
   column with the same rules as the tab-delimited import. Digits-only columns without a leading zero
   become `INT` (or `BIGINT` from 10 digits); any other value makes the column text, so zip codes and
   dates with blanks stay text.
2. **Load.** It creates one table per record type, named `<type>_<name>` (for example `2a_mycases`),
   with one typed column per field, adds them to the collection, and sends the rows to the queue in
   chunks (`ProcessChunkedFile`). When the inserts are done each table gets its ID indexes and its
   chart results are queued, exactly as for the tab-delimited import.

Rows shorter than the layout are padded with blanks; rows with more fields have the extra text folded
into the last column. If the layout has fewer fields than the rows, the extra trailing fields are named
`Field#`. A record type with no layout gets generated `Field#` names. Lines that are blank or are not
records are skipped and counted in the log. Lines that are not UTF-8 are read as Latin-1.
An existing table is left alone unless you pass `--replace`, which drops and recreates it (and removes
its entry from the collection). The 8 queue workers the command starts now start after all chunks are
queued, so the jobs are not picked up while the file is still being read.

The search text (`srchindex`) leaves out the record type, flags, dates, CMS and sequence numbers and
any `ID` field except Constituent ID; `ID` is matched as a whole word, so "Individual" is not dropped.

## Performance

Measured on synthetic SCDIF data (1.7M communication rows, 2.2M topic rows,
0.95M address rows) in MariaDB 10.11 with a 1 GB buffer pool: a single-table
chart takes about 0.5-4 s of query time (the first one after a cold start took
12 s) and 0.1-1.4 s to draw. Combined charts with indexed ID fields:
communications by workflow type 0.7 s, by state 3.6 s, communication type by
state 12 s, topics by month (two big tables, nine series) 22 s, so under the
30 s limit but slow; filtering by date range makes it much faster. Without the
indexes the same joins did not finish.

With a stored result (above) the query time is zero; only the 0.1-1.4 s of
drawing remains. Rendered charts are also cached for `cache_minutes` (default 10), keyed on the spec
and the `updated_at` of the tables used. Re-importing into an existing table can
therefore show stale charts for up to that long.

## Adding a ready-made chart

Add an entry to `StarterCharts::definitions()`: the roles it needs (column name
candidates for the IQ v1 and v2 layouts are listed in `$roles`) and a spec using
`{role}` placeholders. It is offered only for tables that have every role.
`ChartQueryBuilderUnitTest::testEveryStarterProducesAValidSpecForItsTable`
checks the specs.

## Tests

* `tests/Unit/Charts` - spec validation, SQL building, starters, indexing (plain PHPUnit).
* `tests/Feature/Controllers/ChartControllerTest.php` - routes, access rules and
  saved charts, with the chart service mocked (runs with the rest of the suite).
* `charts-service/tests` - the matplotlib service (`pytest`).

## Not included in this prototype

* More than one combination per chart, or links on fields that are not IDs
  (for example topic descriptions from 8A by code).
* Sharing saved charts between users.
* Stored results for combined charts with the other table as the main table, and
  for hand-built charts that run in under `summary_min_ms`; they are computed on
  demand.
