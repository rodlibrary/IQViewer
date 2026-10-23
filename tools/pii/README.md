# PII policy

`iq_field_policy.csv` says what to do with every field of every IQ record type (43 record types, one row per
field).

| Column | Meaning |
|---|---|
| `record_type`, `field_no`, `field_name` | which field (1-based position in the row). A `*` record type is the rule for types not listed |
| `action` | `keep_all` (IDs and codes; never changed), `keep` (kept for research value), `redact` (replaced whole with `label`, e.g. `<PERSON>`), `scan` (free text: run a PII detector such as Presidio) |
| `label` | the placeholder used for `redact` |
| `notes` | why |

## apply_policy.py

Applies the `redact` rows to a folder of `*.dat` files and writes redacted copies. It does not change the input
and it does not touch `scan` fields (those need a detector).

```
python3 tools/pii/apply_policy.py --policy tools/pii/iq_field_policy.csv --data-dir ./scdif_data --out-dir ./scdif_redacted
```

The report lists record types the policy does not cover, rows with more or fewer fields than the policy, and the
number of cells replaced per field. Use it first on synthetic data from `tools/scdif`. Run it on real exports only
inside an approved environment, and never commit real or redacted real data.

When the layouts change, update the CSV first, then rerun the check on freshly generated data
(`validate_scdif.py --policy` also compares field counts with the policy).
