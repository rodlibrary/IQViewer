# Sample data

Small, invented SCDIF data sets for trying the importers and the chart builder. Nothing here comes from real
constituent data (made with `tools/scdif/generate_scdif.py`; names, `example.com` addresses and fictional
555-01xx phone numbers). **Do not add real or redacted-real data to this folder.**

| Item | What it is |
|---|---|
| `scdif-small/` | 30 tab-delimited files, one per record type, about 6,000 rows in all, no header row, CRLF endings, latin-1. Includes a few deliberately messy rows (short rows, blank lines, separator lines, quoted fields, LF endings). `manifest.json` has the expected row counts |
| `scdif-small-mixed-pipe.txt` | The same rows as one pipe-delimited file with all record types mixed, for `import:large-dataset` |

Made with: `python3 tools/scdif/generate_scdif.py --out-dir scdif-small --total-records 6000 --seed 20261023 --quirk-rate 0.01`
(the output is the same for the same seed). Check a set with `python3 tools/scdif/validate_scdif.py --data-dir tools/sample-data/scdif-small`.

## Try the mixed pipe file

Copy it into the flat-files folder (`data/flatfiles/`; create it if it is missing) and run the import in the app container:

```
cp tools/sample-data/scdif-small-mixed-pipe.txt data/flatfiles/
docker exec -it ccdt_php php artisan import:large-dataset scdif-small-mixed-pipe.txt "Sample collection"
```

The collection must already exist (create it in the app first). Each record type becomes its own table, for
example `2a_sample_collection`. Add `--replace` to import again.

## Larger sets

Make them on demand rather than committing them, for example 200,000 rows:

```
python3 tools/scdif/generate_scdif.py --out-dir ./scdif_data --total-records 200000 --seed 1
```

`scdif_data/` is ignored by git.

Column names come from the app's record-type layouts, so after an import check that they look right for your
collection (types the app has no layout for get `Field0`, `Field1`, ... names).
