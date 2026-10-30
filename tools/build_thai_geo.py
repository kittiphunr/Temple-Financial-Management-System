#!/usr/bin/env python3
"""สร้าง data/thai_geo.json (จังหวัด/อำเภอ/ตำบลทั้งประเทศ) จากโปรเจค kongvut/thai-province-data (MIT)

ใช้:  git clone --depth 1 https://github.com/kongvut/thai-province-data /tmp/tpd
      python3 tools/build_thai_geo.py /tmp/tpd

อ่าน api/latest/province_with_district_and_sub_district.json แล้วเขียนเป็นรูปแบบกะทัดรัดที่ระบบอ่านตอนติดตั้ง (includes/thai_geo.php)
รหัสอำเภอ 4 หลัก (เช่น 5401) และรหัสตำบล 6 หลัก (เช่น 540201) เป็นรหัสท้องถิ่นของกรมการปกครอง ใช้ตั้งชื่อผู้ใช้อัตโนมัติ
"""
import json
import subprocess
import sys
from pathlib import Path

src = Path(sys.argv[1] if len(sys.argv) > 1 else '/tmp/tpd')
out = Path(__file__).resolve().parent.parent / 'data' / 'thai_geo.json'
data = json.loads((src / 'api' / 'latest' / 'province_with_district_and_sub_district.json').read_text(encoding='utf-8'))
try:
    commit = subprocess.run(['git', '-C', str(src), 'log', '-1', '--format=%h %cs'], capture_output=True, text=True).stdout.strip()
except Exception:
    commit = ''

provinces = []
for p in data:
    ds = []
    for d in sorted(p['districts'], key=lambda x: x['id']):
        subs = [[str(s['id']), s['name']['th'], str(s['zip_code']) if s.get('zip_code') else ''] for s in sorted(d['sub_districts'], key=lambda x: x['id'])]
        ds.append({'code': str(d['id']), 'name': d['name']['th'], 'prefix': d['prefix']['th'], 'subs': subs})
    if not ds:
        continue
    code = str(ds[0]['code'])[:-2].zfill(2)
    provinces.append({'code': code, 'name': p['name']['th'], 'en': p['name']['en'], 'districts': ds})
provinces.sort(key=lambda p: p['name'])
doc = {'source': 'https://github.com/kongvut/thai-province-data (MIT License, Copyright (c) 2025 Kongvut Sangkla) api/latest ' + commit, 'provinces': provinces}
out.write_text(json.dumps(doc, ensure_ascii=False, separators=(',', ':')) + '\n', encoding='utf-8')
n_d = sum(len(p['districts']) for p in provinces)
n_s = sum(len(d['subs']) for p in provinces for d in p['districts'])
print(f'{out}: {len(provinces)} จังหวัด {n_d} อำเภอ/เขต {n_s} ตำบล/แขวง ({out.stat().st_size // 1024} KB)')
