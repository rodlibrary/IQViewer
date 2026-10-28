IQViewer is a fork of West Virginia's [Constituent Correspondence Data Tool](https://github.com/wvulibraries/ccdt), developed to transform **congressional data** into **information** and **information** into **insights.** The project aims to provide a holistic interface for importing `flat-files` and provide tools to **research and visualize.**


## Contents

1. [What is in this repository](#what-is-in-this-repository)
2. [Requirements](#requirements)
3. [Installation](#installation)
4. [Everyday use of the containers](#everyday-use-of-the-containers)
5. [Configuration](#configuration)
6. [Importing data](#importing-data)
7. [Chart builder](#chart-builder)
8. [Data dashboard](#data-dashboard)
9. [Testing](#testing)
10. [Troubleshooting](#troubleshooting)
11. [Production notes](#production-notes)
12. [Tools and test data](#tools-and-test-data)
13. [Contributing](#contributing)

## What is in this repository

| Path | What it is |
|---|---|
| `ccdt/` | The Laravel application (PHP). Run `artisan` and `phpunit` from here, or inside the `ccdt_php` container where this folder appears as `/var/www` |
| `charts-service/` | A small Python (FastAPI + matplotlib) service that draws the charts. See [docs/charts.md](docs/charts.md) |
| `docker-compose.dev.yml` | Development setup: MySQL, Apache Tika, the chart service and the app |
| `docker-compose.yml` | Production-style setup (see [Production notes](#production-notes)) |
| `Dockerfile.dev`, `Dockerfile`, `Dockerfile.web` | Images for the app (development, production) and the web server |
| `env/` | Environment files (`.env.dev`, `.env.prod`, `.env.mysql`, `.env.testing`, ...) read by the containers |
| `scripts/` | Shell scripts the containers run: `startup-dev.sh`, `startup.sh`, `setup.sh`, `update.sh`, `cleanup-dev.sh` |
| `mysql-files/setup.sql` | Creates the `css` and `css_testing` databases the first time MySQL starts |
| `tools/` | Standalone Python tools: synthetic SCDIF test data, sample data and the Presidio PII pipeline |
| `docs/` | Documentation, including [docs/charts.md](docs/charts.md) |

The `data/` folder (created on first start, not committed) holds what the app container shares with your computer:
`data/flatfiles` (files to import), `data/exports`, `data/logs` and `data/vendor` (PHP packages).

## Requirements

* **Docker with Compose.** Docker Desktop, or any engine that supports `docker-compose` / `docker compose`
  (for example Rancher Desktop). On Windows this needs WSL 2 and virtualization turned on in the BIOS.
* **Git.**
* About **8 GB of free disk space** and **4 GB of memory for Docker** are comfortable for development. Large imports
  need more disk (the database is the main consumer).
* Free ports on your computer: **9000** (the app), **3306** (MySQL) and **9998** (Tika). If one is taken, see
  [Troubleshooting](#troubleshooting).

You do not need PHP, Composer, Node or MySQL installed on your computer; the app container has them (PHP 8.2,
Composer, Node 18). The chart service has its own container (Python 3.12). The Python tools in `tools/` run on any
Python 3 and need nothing from the app.

## Installation

These steps are for a development computer. Commands are the same in PowerShell, Terminal and Bash unless noted.
If your Docker only has the newer syntax, type `docker compose` (with a space) wherever `docker-compose` appears.

1. **Get the code**
   ```
   git clone https://github.com/rodlibrary/IQViewer.git
   cd IQViewer
   ```
   **Windows:** run `git config core.autocrlf false` *before* cloning (or clone with
   `git clone -c core.autocrlf=false ...`). Git otherwise changes line endings in the `.sh` scripts, and the
   container then fails to start (see Troubleshooting).

2. **Look at the environment files.** The development files in `env/` work as they are. They define the database
   name `css`, user `mountaineer`, the host names the containers use for each other (`database`, `tika_service`)
   and the queue driver (`database`). Change passwords before putting the system anywhere other people can reach.
   See [Configuration](#configuration).

3. **Build and start everything**
   ```
   docker-compose -f docker-compose.dev.yml up -d --build
   ```
   The first build downloads images and takes several minutes. Then the app container installs the PHP packages
   with Composer (a few more minutes the first time), starts the queue workers and starts the web server. Watch it:
   ```
   docker logs -f ccdt_php
   ```
   When the log says the development server is running on port 9000, press `Ctrl+C` (the container keeps running).
   Check that all four containers are up:
   ```
   docker ps
   ```
   You should see `ccdt_php`, `ccdt_charts`, `tika_service` and the database container.

4. **Create the database tables** (once, and again after you pull code that adds migrations):
   ```
   docker exec -it ccdt_php sh ./scripts/setup.sh
   ```
   This runs `php artisan migrate --seed --database=mysql`: it creates every table and the starting data
   (record-type layouts, allowed file types and two user accounts). Running it again is safe for migrations;
   seeding again can add duplicates, so use `docker exec -it ccdt_php php artisan migrate` for later updates.

5. **Open the app** at <http://localhost:9000> and sign in. The seeded accounts are `admin@admin.com`
   (an administrator) and `test@test.com` (a regular user); their passwords are in
   `ccdt/database/seeds/UsersTableSeeder.php`. **Change both passwords, or delete the accounts, on any computer other
   people can reach.**

6. **Try it with the sample data** (see [Importing data](#importing-data)): create a collection, import
   `tools/sample-data`, then open **Charts** in the menu.

## Everyday use of the containers

| I want to | Command |
|---|---|
| Start (after a restart of the computer) | `docker-compose -f docker-compose.dev.yml up -d` |
| Stop, keeping the database | `docker-compose -f docker-compose.dev.yml stop` |
| See the app log | `docker logs -f ccdt_php` (Laravel's own log is `data/logs/laravel.log`) |
| Run an `artisan` command | `docker exec -it ccdt_php php artisan <command>` |
| Open a shell in the app | `docker exec -it ccdt_php bash` |
| Rebuild after changing a Dockerfile or `charts-service/` | `docker-compose -f docker-compose.dev.yml up -d --build` |
| Apply new migrations after `git pull` | `docker exec -it ccdt_php php artisan migrate` |
| Clear cached configuration after editing an env file | `docker exec -it ccdt_php php artisan config:clear` |

Changes to PHP files in `ccdt/` take effect immediately because the folder is mounted into the container.
Restarting the app container (`docker restart ccdt_php`) re-runs the start-up script: it relinks the environment
files, runs Composer, generates a new application key (so you may be signed out) and starts the queue workers again.

**Starting over.** `docker-compose -f docker-compose.dev.yml down -v` removes the containers *and the database
volume*: every imported table and user is deleted. Then repeat the installation from step 3.
`scripts/cleanup-dev.sh` is more drastic: it runs `docker volume prune --all` (which deletes **every** unused Docker
volume on your computer, not just this project's) and empties `./data`. Do not run it on a computer where other
projects keep data in Docker volumes.

## Configuration

* **Which env file is used.** In development the start-up script links `env/.env.dev` as the app's `.env`; in
  production it links `env/.env.prod`. Tests use `env/.env.testing` (database `css_testing`).
  After editing an env file, run `php artisan config:clear` in the container, or restart it.
* **Database.** `.env.mysql` sets the MySQL root password, the `css` database and the `mountaineer` user. `.env.dev`
  must use the same user and password (`DB_USERNAME`, `DB_PASSWORD`) and `DB_HOST=database`.
  `mysql-files/setup.sql` creates `css` and `css_testing` **only the first time MySQL starts with an empty volume.**
* **Queues.** `QUEUE_DRIVER=database`: imports run as queued jobs. Four workers start with the container (queues
  `high`, `default` and `low`; time limit 24 hours per job; three tries). Without them, imports never finish.
* **Tika** (`TIKA_HOST`, `TIKA_PORT`) extracts text from uploaded documents.
* **Chart builder.** Optional settings (`CHARTS_SERVICE_URL`, `CHARTS_SERVICE_TOKEN`, a read-only database user,
  `CHARTS_ADMIN_ONLY`, `CHARTS_INDEX_ON_IMPORT`, `CHARTS_SUMMARY_ON_IMPORT`) are listed in
  [docs/charts.md](docs/charts.md#setup).
* **Do not commit real passwords.** The files in `env/` hold development values. For production, use your own
  secrets and keep them out of the repository.

## Importing data

**In the app.** Sign in as an administrator, create a **collection**, then use the import wizard (upload a file, or
pick one already in the flat-files folder). Collections flagged as CMS use the record-type layouts so columns get
their real names.

**The flat-files folder.** Files for the command-line imports go in `data/flatfiles/` on your computer; inside the
container the same folder is `storage/app/flatfiles/`. Create `data/flatfiles` if it does not exist.

**One mixed, pipe-delimited export** (all record types in one file):
```
docker exec -it ccdt_php php artisan import:large-dataset export.txt "Collection name"
```
The collection must already exist (name or ID). Each record type becomes its own typed table, so it can be searched
and charted. Options: `--delimiter=tab` (default is `|`), `--chunk=10000` (rows per queue job) and `--replace`
(drop and recreate tables that exist). Details are in [docs/charts.md](docs/charts.md#importing-a-pipe-delimited-export-importlarge-dataset).
Progress is in `data/logs/laravel.log`; the tables appear in the app when the queue has finished.

**Sample data.** [`tools/sample-data`](tools/sample-data/) has a small invented data set that is safe to commit and
share:
```
cp tools/sample-data/scdif-small-mixed-pipe.txt data/flatfiles/
docker exec -it ccdt_php php artisan import:large-dataset scdif-small-mixed-pipe.txt "Sample collection"
```
Bigger synthetic sets (even millions of rows) can be generated with `tools/scdif/generate_scdif.py`.
Real constituent data contains personal information: keep it out of this repository and out of folders synced to
cloud storage.

## Chart builder

Signed-in users can draw charts of imported tables (matplotlib), including charts that combine two tables of a
collection, save them, and zoom and hover in an interactive view. After `git pull`, run the migrations and rebuild so
the chart service is running:
```
docker exec -it ccdt_php php artisan migrate
docker-compose -f docker-compose.dev.yml up -d --build charts app
```
Tables imported before the chart builder existed need their indexes built once
(`php artisan table:create:index --all`). See [docs/charts.md](docs/charts.md) for how it works, what a chart may and may not
use, performance and the optional settings.

## Data dashboard

**Dashboard** in the menu shows counts of everything imported: records per table, distinct people and IDs, the most
common values of fields such as gender, city, state, type and status, and date trends. The numbers are counted in the
background after each import, so the page opens instantly. After `git pull`, run the migration; for tables imported
earlier, count them once:
```
docker exec -it ccdt_php php artisan migrate
docker exec -it ccdt_php php artisan dashboard:refresh --all
```
See [docs/dashboard.md](docs/dashboard.md) for what is counted, the privacy rules and troubleshooting.

## Testing

The PHP tests run inside the app container and use the separate `css_testing` database, so they do not touch your data:
```
docker exec -it ccdt_php vendor/bin/phpunit                          # everything
docker exec -it ccdt_php vendor/bin/phpunit --filter Chart           # chart builder tests only
docker exec -it ccdt_php vendor/bin/phpunit --filter LargeImport     # import:large-dataset helpers
docker exec -it ccdt_php vendor/bin/phpunit --filter Dashboard       # data dashboard
docker exec -it ccdt_php vendor/bin/phpunit tests/Unit               # unit tests only
```
A green run ends with `OK (N tests, ...)`. If many tests fail with database errors, see Troubleshooting
("Tests cannot connect or `css_testing` is missing").

**Chart service tests** (Python; run on your computer, no Docker needed):
```
cd charts-service
pip install -r requirements.txt -r requirements-dev.txt
pytest
```

**Test data tools** (Python 3, standard library):
```
python3 tools/scdif/generate_scdif.py --out-dir ./scdif_data --total-records 20000 --seed 1
python3 tools/scdif/validate_scdif.py --data-dir ./scdif_data --policy tools/pii/iq_field_policy.csv
```
The validator prints `N checks passed, 0 failed` and exits with status 1 if anything fails.

**A quick manual check after installing or updating**
1. Sign in as the admin. The home page loads.
2. Create a collection and import the sample data (above). Wait for the queue to finish (watch
   `data/logs/laravel.log`); the tables should list under the collection with their row counts.
3. Open **Charts**, choose a table, pick a ready-made chart. A picture appears. Try the Hover and zoom view.
   Open **Dashboard**: the tables show their counts once the background counting has finished (a couple of minutes after the import).
4. Choose a second table of the same collection ("combine with") and draw a chart across the two.

## Troubleshooting

First, always: `docker ps` (is everything running?), `docker logs ccdt_php` (the app) and `data/logs/laravel.log`
(Laravel's own errors; the file may be named `laravel-<date>.log`). Quote the exact error text when asking for help.

**Installation and start-up**

| Symptom | Likely cause and fix |
|---|---|
| `docker-compose: command not found` | Newer Docker has no hyphenated command. Use `docker compose` (with a space). |
| "port is already allocated" / "address already in use" | Another program uses 9000, 3306 or 9998 (often a local MySQL on 3306). Stop it, or change the *left-hand* number under `ports:` in `docker-compose.dev.yml` (for example `"3307:3306"`). |
| `ccdt_php` exits at once; log shows `bad interpreter`, `\r: command not found` or `exec format error` | Windows line endings in the `.sh` scripts. Run `git config core.autocrlf false`, then `git rm --cached -r scripts && git checkout -- scripts` (or re-clone), then `docker-compose -f docker-compose.dev.yml up -d --build`. |
| `ccdt_php` keeps restarting; log shows a Composer error | The container could not download packages (no network, proxy, or a rate limit). Check your connection and run `docker restart ccdt_php`. |
| Browser cannot reach <http://localhost:9000> just after start | Composer is still installing on the first start. Wait and watch `docker logs -f ccdt_php`. |
| `setup.sh` says "Connection refused" or "Unknown database 'css'" | MySQL is still starting (wait a minute and retry), or the database volume was created before `setup.sql` existed. If the second, and you have nothing to lose, `docker-compose -f docker-compose.dev.yml down -v` and start again. |
| "No application encryption key has been generated" or a blank 500 page | Run `docker exec -it ccdt_php php artisan key:generate` then `php artisan config:clear`. Check `data/logs/laravel.log` for the real error. |
| "Class ... not found" after `git pull` | New PHP packages: `docker exec -it ccdt_php composer install`, or restart the container. |
| A page says a table such as `saved_charts` does not exist | Pending migrations: `docker exec -it ccdt_php php artisan migrate`. |
| Signed out after restarting | The start-up script generates a new application key each time. Sign in again. |

**Imports**

| Symptom | Likely cause and fix |
|---|---|
| An import never finishes; nothing appears | The queue workers are not running or a job failed. See what is waiting: `docker exec -it ccdt_php mysql -h database -u mountaineer -p css -e "SELECT queue, COUNT(*) FROM jobs GROUP BY queue"` (the password is `DB_PASSWORD` in `env/.env.dev`). If the numbers do not go down, list the workers: `docker exec ccdt_php sh -c 'for p in /proc/[0-9]*; do tr "\0" " " < $p/cmdline; echo; done' | grep queue:work`. If none, `docker restart ccdt_php`. Look for failed jobs: `docker exec -it ccdt_php php artisan queue:failed`, then `php artisan queue:retry all`. |
| `import:large-dataset`: "File not found" | The file must be in `data/flatfiles/` on your computer (`storage/app/flatfiles/` in the container). Give only the file name. |
| `Collection "..." was not found` | Create the collection in the app first, or pass its numeric ID. Names must match exactly. |
| "No records were found" | Wrong delimiter. Tab-delimited files need `--delimiter=tab`. Lines must start with a record type such as `1A`. |
| "table ... already exists, record type ... was not imported" | The tables exist from an earlier import. Add `--replace` to drop and recreate them. |
| Columns are named `Field0`, `Field1`, ... | The record type has no layout in the app's record-types table, or the file has more fields than the layout. Names beyond the layout are generated. Rename columns in the table editor or add the layout. |
| Numbers or zip codes look wrong | A column is numeric only if every value is digits without a leading zero; otherwise it stays text on purpose. A single bad value anywhere in the file makes the column text. |
| A very large import is slow or the machine runs out of disk | Imports are limited by disk and database speed. Give Docker more memory/disk, use `--chunk` between 5,000 and 20,000, and check free space (`docker system df`). |

**Charts**

| Symptom | Likely cause and fix |
|---|---|
| "The chart service is unavailable" | The `ccdt_charts` container is not running or not reachable. `docker ps`, `docker logs ccdt_charts`, then `docker-compose -f docker-compose.dev.yml up -d --build charts app`. |
| Chart service answers "bad token" | `CHARTS_SERVICE_TOKEN` differs between the app (`env/.env.*`) and the charts container (a `.env` file next to the compose file). Make them the same or leave both empty. |
| "The query took too long" | Add a filter (for example a date range) or choose a coarser grouping. Queries are limited to 30 seconds. |
| The Dashboard says "Not counted yet" | Run `docker exec -it ccdt_php php artisan dashboard:refresh --all` (after `php artisan migrate`). More in [docs/dashboard.md](docs/dashboard.md#troubleshooting). |
| A table is missing from the Charts list | Tables disabled by an administrator, tables in disabled collections, and users without access are hidden. |
| No "combine with" choice, or a join is refused | Both tables must be in the same collection and share an indexed ID column. Run `docker exec -it ccdt_php php artisan table:create:index --all` for tables imported before the chart builder. |
| Bars or points are missing | Groups with fewer than 10 records are hidden on purpose (privacy); the chart footnote says how many. |

**Tests**

| Symptom | Likely cause and fix |
|---|---|
| Tests cannot connect, or `css_testing` is missing | `setup.sql` only runs when MySQL starts with an empty volume. Create it: `docker-compose -f docker-compose.dev.yml exec database mysql -u root -p -e "CREATE DATABASE IF NOT EXISTS css_testing"` (the root password is in `env/.env.mysql`). The tests create their own tables in it. |
| Tests pass in the container but `phpunit` is not found on your computer | PHP is only installed in the container. Run tests with `docker exec`. |

**Getting more help.** Collect: the exact command you ran, the last 30 lines of `docker logs ccdt_php`, the last
errors in `data/logs/laravel.log`, and the output of `docker ps` and `git log --oneline -3`.

## Production notes

* `docker-compose.yml` is the production-style setup. It still pins **MySQL 5.7.27**, while the development setup uses
  **MySQL 8**. Test on the version you will run, and plan the upgrade (5.7 is past end of life).
* Use `env/.env.prod` with your own secrets; run `docker exec -it ccdt_php php artisan migrate --force` after each deploy.
* Give the chart builder a read-only database user and a shared service token (see [docs/charts.md](docs/charts.md#setup)).
* Back up the MySQL data and the flat files before upgrades.

## Tools and test data

[`tools/`](tools/) holds standalone Python scripts that need no Docker or database:
* [`tools/scdif`](tools/scdif/): generate and validate synthetic SCDIF test files (any size, from a few thousand
  rows to millions).
* [`tools/sample-data`](tools/sample-data/): a small invented data set to try the importers and charts.
* [`tools/pii`](tools/pii/): the Microsoft Presidio pipeline and field policy for removing personal information from
  real exports.

See [tools/README.md](tools/README.md).

## Contributing

1. Create a branch from `main`: `git checkout main && git pull && git checkout -b my-change`.
2. Make the change and run the tests (above). Add tests for new behavior.
3. Commit with a clear message, push, and open a pull request into `main`.
4. Never commit real constituent data, passwords or files from the PII pipeline's output.
