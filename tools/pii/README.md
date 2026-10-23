# PII review and redaction (Microsoft Presidio)

Scripts that find and remove personal information from IQ / SCDIF exports, controlled field by field by a policy
file. **All files in this folder must stay together**: the scripts import each other and look for
`iq_field_policy.csv` next to themselves.

The data you run these on contains PII. Keep it out of this repository, and out of folders synced to OneDrive or
other cloud storage. Only `tools/sample-data` (invented data) belongs in the repo.

## Files

| File | What it is |
|---|---|
| `iq_field_policy.csv` | **Edit this.** One row per field of every record type: `redact`, `scan`, `keep` or `keep_all` (see below) |
| `iq_policy.py` | Reads and checks the policy |
| `iq_analyze_pipeline.py` / `iq_analyze.script` | IQ analysis using the policy (the `.script` is the SLURM job) |
| `iq_anonymize_pipeline.py` / `iq_anonymize.script` | IQ anonymization using the policy (SLURM job) |
| `analyze_pipeline.py`, `anonymize_pipeline.py` / `anonymize.script` | The general pipeline (PDFs, images, emails, CSVs, text, SCDIF). The IQ scripts use these too |
| `convert_to_scdif.py` | Turns a pipe-delimited or header-row IQ export into standard tab-delimited SCDIF (Python only, nothing to install) |
| `apply_policy.py` | Quick check without Presidio: applies only the `redact` rows to a folder of `.dat` files and reports policy gaps. Handy for testing the policy on `tools/sample-data` |

## Policy actions

| Action | Effect |
|---|---|
| `redact` | the whole value is replaced with the label, e.g. `<FIRST_NAME>` |
| `scan` | only the PII Presidio detects in the field is replaced |
| `keep` | the field is shown, but ID numbers (SSNs, phone numbers, emails, ...) are still removed |
| `keep_all` | the field is shown unchanged |

Record type `*` and field `*` rows cover record types or fields the file does not list (for example custom fields
an office adds at the end of a record). Presidio does not detect street addresses inside free text.

## Workflow (SLURM cluster)

The `.script` files are written for a cluster: they use `/data/hartwigd/pii` as the working folder and
`/data/hartwigd/pve` as the Python virtual environment. **Change those paths (`INPUT_DIR`, `OUTPUT_DIR`, `VENV` and
the `#SBATCH --output/--error` lines) for your own account before use.** Put all the files in one folder on the
cluster and run `sbatch` from it.

1. If the export is not standard tab-delimited SCDIF (for example it starts `Record Type|...`), keep the original
   outside the working folder and convert it:
   ```
   python convert_to_scdif.py --inspect original.txt        # masked; safe to share
   python convert_to_scdif.py original.txt --output_dir /data/hartwigd/pii
   ```
   Read the report in `pii_conversion_reports`. Rejected records (`*_rejected.txt`) are not converted or
   anonymized and contain PII.
2. Analyze:
   ```
   sbatch iq_analyze.script                              # every file in the working folder
   sbatch iq_analyze.script file=NAME_scdif.dat          # one file
   sbatch iq_analyze.script policy=my_policy.csv         # another policy
   ```
   Results: `iq_results/pii_results_<job>.csv`.
3. Anonymize:
   ```
   sbatch iq_anonymize.script /path/to/iq_results/pii_results_<job>.csv
   sbatch iq_anonymize.script /path/to/pii_results_<job>.csv names,dates   # keep these types in "scan" fields
   sbatch iq_anonymize.script /path/to/pii_results_<job>.csv none my_policy.csv
   ```
   Check `anonymization_summary_<job>.csv` and `iq_policy_report_<job>.csv`.

Changing a field to `redact` or `keep_all`: re-run step 3 only. Changing a field from `redact`/`keep_all` to
`scan`/`keep`: re-run steps 2 and 3 (until then the field shows as `<UNSCANNED>`).

For email alerts: `sbatch --mail-type=END,FAIL --mail-user=you@uni.edu iq_analyze.script`.

The Python scripts also run on a local computer (for example in PowerShell); the IQ scripts then use a single
process.

## Testing the policy without Presidio

```
python3 tools/scdif/generate_scdif.py --out-dir ./scdif_data --total-records 20000
python3 tools/pii/apply_policy.py --policy tools/pii/iq_field_policy.csv --data-dir ./scdif_data --out-dir ./scdif_redacted
```
`validate_scdif.py --policy` also checks field counts against the policy.
