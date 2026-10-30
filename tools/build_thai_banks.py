#!/usr/bin/env python3
"""สร้างรายชื่อธนาคารและโลโก้ (data/thai_banks.json + assets/banks/*.png) จากโปรเจค casperstack/thai-banks-logo

ใช้:  python3 tools/build_thai_banks.py /path/to/thai-banks-logo      (โฟลเดอร์ที่ git clone มา)
- คัดลอกไฟล์โลโก้ PNG ต้นฉบับโดยไม่แก้ไข (ข้าม PromptPay/TrueMoney ที่ไม่ใช่ธนาคาร)
- คัดลอก package.json ต้นฉบับเป็นหลักฐานสัญญาอนุญาต (ที่เก็บต้นทางไม่มีไฟล์ LICENSE ระบุเพียง "license": "ISC" ใน package.json)
- สร้าง data/thai_banks.json: ชื่อสั้น ชื่อเต็ม ชื่ออังกฤษ สี และชื่อไฟล์โลโก้ (ชื่อและสีดึงจาก index.ts ของต้นทาง)
นโยบายเครดิตของโปรเจค: ดู data/credits.json (รายการ thai-banks-logo) และ CREDITS.md
"""
import json
import re
import shutil
import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parent.parent
SKIP = {'PromptPay', 'TrueMoney'}


def main(src: Path):
    ts = (src / 'index.ts').read_text(encoding='utf-8')
    banks = []
    for m in re.finditer(r'(\w+):\s*\{(.*?)\n  \}', ts, re.S):
        code, body = m.group(1), m.group(2)
        if code in SKIP or code in ('bankLists',):
            continue
        get = lambda k: (re.search(k + r':\s*"([^"]*)"', body) or [None, ''])[1]
        icon = src / 'icons' / f'{code}.png'
        if not icon.is_file():
            continue
        banks.append({'code': code, 'name': get('name'), 'name_long': get('fullname'), 'name_en': get('nameEN'), 'color': get('color'), 'logo': f'assets/banks/{code}.png'})
    banks.sort(key=lambda b: b['code'])
    out = ROOT / 'assets' / 'banks'
    out.mkdir(parents=True, exist_ok=True)
    for b in banks:
        shutil.copyfile(src / 'icons' / f"{b['code']}.png", ROOT / b['logo'])
    shutil.copyfile(src / 'package.json', out / 'thai-banks-logo.package.json')
    (ROOT / 'data' / 'thai_banks.json').write_text(json.dumps(banks, ensure_ascii=False, indent=2) + '\n', encoding='utf-8')
    print(f'ธนาคาร {len(banks)} แห่ง → data/thai_banks.json และ assets/banks/')


if __name__ == '__main__':
    if len(sys.argv) != 2 or not (Path(sys.argv[1]) / 'index.ts').is_file():
        sys.exit(__doc__)
    main(Path(sys.argv[1]))
