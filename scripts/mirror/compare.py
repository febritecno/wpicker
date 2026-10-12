#!/usr/bin/env python3
"""Compare two normalised dumps written by dump.php. Exit 1 on any difference."""
import json
import sys

src, dst = (json.load(open(p)) for p in sys.argv[1:3])
problems = 0


def report(line):
    global problems
    problems += 1
    if problems <= 25:
        print("  " + line)


print(f"source {src['site']}  ->  target {dst['site']}")
for side, dump in (("source", src), ("target", dst)):
    for value in dump["broken"]:
        report(f"BROKEN serialized value on {side}: {value}")

if src["name"] != dst["name"]:
    report(f"site name differs: {src['name']!r} vs {dst['name']!r}")

for table in sorted(set(src["tables"]) | set(dst["tables"])):
    a = src["tables"].get(table)
    b = dst["tables"].get(table)
    if a is None:
        report(f"{table}: only on target ({len(b)} rows)")
        continue
    if b is None:
        report(f"{table}: missing on target ({len(a)} rows)")
        continue
    if a == b:
        continue
    missing = sorted(set(a) - set(b))
    extra = sorted(set(b) - set(a))
    report(f"{table}: {len(a)} rows vs {len(b)}; {len(missing)} not reproduced, {len(extra)} unexpected")
    for row in missing[:3]:
        report(f"    source only: {row[:300]}")
    for row in extra[:3]:
        report(f"    target only: {row[:300]}")

rows = sum(len(r) for r in src["tables"].values())
if problems:
    print(f"DATABASE: {problems} difference(s) in {len(src['tables'])} tables / {rows} rows")
    sys.exit(1)
print(f"DATABASE: identical — {len(src['tables'])} tables, {rows} rows, 0 broken values")
