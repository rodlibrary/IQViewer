#!/usr/bin/env python3
"""
apply_policy.py - apply the "redact" rows of iq_field_policy.csv to SCDIF files.

  python3 apply_policy.py --policy iq_field_policy.csv --data-dir ./scdif_data --out-dir ./scdif_redacted

Reads every *.dat file in --data-dir (tab-delimited, latin-1, like the IQ
exports) and writes a copy to --out-dir with the full-replace ("redact") fields
swapped for their label (for example <PERSON>). It does not change the input.

Fields marked "scan" in the policy are free text that needs a PII detector
(Presidio) and are NOT changed here. The report lists:
  * record types in the data that the policy does not cover
  * rows with more or fewer fields than the policy lists
  * how many cells were replaced, per field

Use it on synthetic data (tools/scdif) to test the policy, or on real exports
only inside an approved environment. Never commit real or unredacted data.
"""
import argparse
import collections
import csv
import glob
import os
import re
import sys

ap = argparse.ArgumentParser(description="Apply the redact actions of the field policy to SCDIF files.")
ap.add_argument("--policy", required=True, help="iq_field_policy.csv")
ap.add_argument("--data-dir", required=True, help="folder of *.dat files")
ap.add_argument("--out-dir", required=True, help="folder for the redacted copies (created if missing)")
args = ap.parse_args()
policy_csv, data_dir, out_dir = args.policy, args.data_dir, args.out_dir
os.makedirs(out_dir, exist_ok=True)
pol = collections.defaultdict(dict); wildcard=None
for r in csv.DictReader(open(policy_csv, newline='', encoding='utf-8')):
    if r['record_type']=='*': wildcard=r; continue
    pol[r['record_type']][int(r['field_no'])] = (r['action'], r['label'], r['field_name'])
print("policy record types:", len(pol), "| actions:",
      dict(collections.Counter(a for d in pol.values() for a,_,_ in d.values())))
unlisted_rt = collections.Counter(); extra = collections.defaultdict(lambda: collections.Counter())
extra_nonblank = collections.defaultdict(list); short = collections.Counter(); changed = collections.Counter()
for f in sorted(glob.glob(os.path.join(data_dir,'*.dat'))):
    raw = open(f,'rb').read().decode('latin-1')
    if not raw: continue
    parts = re.split(r'(\r?\n)', raw); out=[]
    for seg in parts:
        if seg in ('\r\n','\n') or not seg.strip() or '\t' not in seg:
            out.append(seg); continue
        t = seg.split('\t'); rt = t[0].strip(); n=len(t)
        if rt not in pol:
            unlisted_rt[(rt, os.path.basename(f), n)] += 1
        else:
            maxf = max(pol[rt])
            if n > maxf:
                extra[rt][n-maxf]+=1
                for i in range(maxf, n):
                    if t[i].strip(): extra_nonblank[rt].append((i+1, t[i][:50]))
            if n < maxf: short[(rt,n,maxf)]+=1
            for i,tok in enumerate(t):
                a = pol[rt].get(i+1)
                if a and a[0]=='redact' and tok.strip():
                    t[i] = a[1]; changed[(rt,i+1,a[2])]+=1
        out.append('\t'.join(t))
    open(os.path.join(out_dir, os.path.basename(f)),'wb').write(''.join(out).encode('latin-1'))
print("\nRECORD TYPES IN DATA BUT NOT IN POLICY (fall to wildcard scan, <REDACTED>):")
for (rt,fn,n),c in unlisted_rt.items(): print(f"  {rt} in {fn}: {c} rows, {n} fields each")
print("\nDATA HAS MORE FIELDS THAN POLICY:")
for rt,c in extra.items(): print(f"  {rt}: {dict(c)} extra field(s); non-blank extras: {extra_nonblank[rt][:3] or 'none (just trailing empty)'}")
print("\nDATA HAS FEWER FIELDS THAN POLICY:", dict(short) or "none")
print("\nREDACTED CELLS (full-replace fields):")
for (rt,fn,name),c in sorted(changed.items()): print(f"  {rt} field {fn:>2} {name}: {c}")
