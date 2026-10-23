# Tools

Standalone Python 3 scripts for testing and data handling (the Presidio pipeline needs Presidio and spaCy). They are not part of the Laravel app and need nothing
from it: no Docker, composer or database. The SCDIF scripts use the standard library only.

| Folder | What it is for |
|---|---|
| [`scdif/`](scdif/) | Make synthetic SCDIF files for import testing, and check them |
| [`pii/`](pii/) | The Presidio PII pipeline, the field-by-field policy it follows, and a quick policy check |
| [`sample-data/`](sample-data/) | A small invented data set (tab-delimited, and one mixed pipe-delimited file) to try the importers |

## Make test data and import it

```
python3 tools/scdif/generate_scdif.py --out-dir ./scdif_data --total-records 200000 --seed 1
python3 tools/scdif/validate_scdif.py --data-dir ./scdif_data --policy tools/pii/iq_field_policy.csv
```

`--total-records` is the number of rows across all record types (default 15,000,000, which takes a while and
needs a lot of disk space; start small). The same `--seed` and `--total-records` always produce the same files.

The files are tab-delimited with no header row. To import a single file with the tab-delimited importer, add it
to a collection flagged as CMS. To import one mixed file with `import:large-dataset`, put the files in
`ccdt/storage/app/flatfiles/` and use `--delimiter=tab` (see [docs/charts.md](../docs/charts.md)).

## Test the PII policy

```
python3 tools/pii/apply_policy.py --policy tools/pii/iq_field_policy.csv --data-dir ./scdif_data --out-dir ./scdif_redacted
```

That is a quick check without Presidio. The full Presidio pipeline (for real exports, on the cluster) is described in
[`pii/README.md`](pii/README.md).

## Rules for this folder

* Everything the generator makes is invented (generic names, example.com addresses, fictional 555-01xx phone
  numbers). Keep it that way.
* **Never commit real constituent data, or any file derived from it**, including redacted copies. Generated
  folders (`scdif_data/`, `scdif_redacted/`) are ignored by git.
