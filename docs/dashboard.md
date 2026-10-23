# Data dashboard

**Dashboard** in the top menu (`/dashboard`) shows what has been imported, as counts, for every table you may see:

* **Headline numbers:** records, collections, tables and distinct people.
* **Records per table**, as bars.
* For each table, **how many distinct IDs** it holds (people, addresses, communications, cases, households...).
* **Breakdowns:** the most common values of its fields, in three groups:
  * *Who and where*: gender, city, state, county, zip, district, race, language, age...
  * *Types, statuses and codes*: type, status, flag, staff, topic, source, response, agency...
  * *Other fields with few values* (any other short field with a handful of distinct values, such as a prefix or a title).
* **Trends over time:** records per year (or per month for short spans) for each date field, drawn with the same
  hover-and-zoom chart as the chart builder.

Open a table's card and press **Show ... breakdowns** to see them. Each value shows its count and its percent of all
records in the table.

## How the numbers are made

Nothing is counted when you open the page. The page only reads a small stored result per table (`dashboard_snapshots`).
The counting happens in the background:

* after every import (`import:large-dataset`, the import wizard), a queued job on the `low` queue counts the table
  once its import jobs have finished (about two minutes after the import, like the stored chart results);
* an administrator can press **Refresh counts** on a table, or **Refresh all counts**, or run
  `docker exec -it ccdt_php php artisan dashboard:refresh --all` (add `--queue` to run it in the background, or give
  one table name).

A table whose data changed after it was counted is marked *Imported since counted*; a table never counted says *Not
counted yet*. Counting stops after `dashboard_budget_seconds` (15 minutes) for one table and marks it *Partly counted*.

### Which fields are counted

The dashboard decides from each table's own columns and their names, with no list to maintain:

* **ID columns** (names ending `_id`) get a distinct count.
* **Date columns** (a name with `date`, `tickler`, `deadline`, `received`, `opened` or `closed`, holding `YYYYMMDD`
  values) get a trend. Only valid dates between 1900 and 2099 count.
* **Short text and code columns** are looked at in a sample of up to 100,000 rows. A column is listed when it has two or more
  distinct values and is not nearly unique (so identifiers and free text drop out). Fields named for who/where
  (city, state, zip...) may have thousands of values; other fields must have at most 200.
* At most 10 *who and where*, 10 *types and codes* and 6 *other* breakdowns, up to 4 trends and 8 ID counts per table, 15 values per breakdown.
  Change the limits in `config/charts.php` (`dashboard`).

Distinct people is the largest distinct count of a `person_id` or `constituent_id` column among the tables you can see
(shown with the table it came from). It is not a sum across tables, because the same person appears in several.

### Privacy

The dashboard runs every count through the chart builder's own rules (`ChartQueryBuilder`) on the read-only chart
connection:

* names, email addresses, phone numbers, street addresses, birth dates, notes, subjects and other free text
  are never offered (see `blocked_columns` in `config/charts.php`);
* a value shared by fewer than 10 records (`min_cell_size`) is hidden, and the card says how many groups were hidden;
* only aggregated numbers are stored, never records.

The same access rules as the chart builder apply: any signed-in user, but only tables an administrator has left
enabled in enabled collections. `CHARTS_ADMIN_ONLY=true` restricts the dashboard to administrators too. Only
administrators can refresh counts.

## Setup

1. Run the migration: `docker exec -it ccdt_php php artisan migrate` (creates `dashboard_snapshots`).
2. Count the tables that are already imported:
   `docker exec -it ccdt_php php artisan dashboard:refresh --all`
   (several minutes per table with millions of rows; tables imported from now on are counted automatically).
3. Optional: `CHARTS_DASHBOARD_ON_IMPORT=false` in the env file stops the automatic counting after imports.

The queue workers must be running (they are started with the app container); see the README.

## How long counting takes

Measured on MariaDB with the typed tables the importer creates: about 20 seconds for a table of 950,000 rows with 11
breakdowns and 28 seconds for 1.75 million rows with 4 breakdowns, 3 trends and 5 ID counts. Time grows with the number of rows, so a
15-million-row table takes in the order of five minutes; the ID counts use the columns' indexes (added at import).

## Troubleshooting

| Symptom | Cause and fix |
|---|---|
| "Not counted yet" for every table | The migration has not run, or the counting jobs have not finished. Run `php artisan migrate`, then `php artisan dashboard:refresh --all`. Check `docker exec -it ccdt_php php artisan queue:failed` and `data/logs/laravel.log` (look for "Dashboard"). |
| A table stays "Imported since counted" | The count job is waiting for the table's import jobs to finish (it retries every two minutes for up to six hours), or the queue workers are not running. |
| "Partly counted" | The 15-minute limit was reached. Raise `dashboard_budget_seconds` in `config/charts.php`, or lower the number of breakdowns. |
| A field you expect is missing | Fields with personal data are never offered; a field with one value, or nearly unique values, is skipped; names that match none of the patterns are only listed when they have 200 or fewer distinct values. A table loaded without a layout has generic `Field#` names. |
| "Distinct people" is empty | No table has a `person_id` or `constituent_id` column counted yet. |
