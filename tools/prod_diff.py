# -*- coding: utf-8 -*-
"""本番(さくら)とローカル(develop)の静的ファイル全量diff。読み取り専用。"""
import sys
import difflib
import urllib.request
from pathlib import Path

sys.stdout.reconfigure(encoding="utf-8", errors="replace")

PROD = "https://uraraka.moe/fit24-order-budget/"
LOCAL = Path(r"C:\Users\ssasa\kaikatsu-system")
OUT = Path(__file__).parent / "prod_files"
OUT.mkdir(exist_ok=True)

targets = []
for pat in ("*.html", "js/*.js", "css/*.css"):
    for f in sorted(LOCAL.glob(pat)):
        if ".bak" in f.name or "コピー" in f.name:
            continue
        targets.append(f.relative_to(LOCAL).as_posix())

identical, differs, missing = [], {}, []
for rel in targets:
    url = PROD + rel
    try:
        req = urllib.request.Request(url, headers={"User-Agent": "Mozilla/5.0"})
        with urllib.request.urlopen(req, timeout=20) as r:
            prod_bytes = r.read()
    except Exception as e:
        missing.append(f"{rel}  ({e})")
        continue
    (OUT / rel.replace("/", "__")).write_bytes(prod_bytes)
    prod_text = prod_bytes.decode("utf-8", errors="replace").replace("\r\n", "\n")
    local_text = (LOCAL / rel).read_text(encoding="utf-8", errors="replace").replace("\r\n", "\n")
    if prod_text == local_text:
        identical.append(rel)
    else:
        diff = list(difflib.unified_diff(
            local_text.splitlines(), prod_text.splitlines(),
            fromfile=f"local/{rel}", tofile=f"prod/{rel}", lineterm="", n=1))
        differs[rel] = diff

print(f"=== 一致 {len(identical)} / 差分あり {len(differs)} / 本番に無い・取得失敗 {len(missing)} ===\n")
if missing:
    print("--- 本番に無い/取得失敗 ---")
    for m in missing:
        print(" ", m)
    print()
for rel, diff in differs.items():
    print(f"--- 差分: {rel} ({len(diff)}行) ---")
    for line in diff[:60]:
        print(line)
    if len(diff) > 60:
        print(f"  ...（他 {len(diff)-60} 行は {OUT} の保存ファイル参照）")
    print()
