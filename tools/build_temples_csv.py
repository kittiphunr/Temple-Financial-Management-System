#!/usr/bin/env python3
"""แปลง Report_Temple.xlsx (ทะเบียนวัดจาก พศ.) เป็น data/temples_phrae.csv
ใช้: python3 tools/build_temples_csv.py Report_Temple.xlsx data/temples_phrae.csv"""
import csv, sys, openpyxl

src, dst = sys.argv[1], sys.argv[2]
rows = list(openpyxl.load_workbook(src, read_only=True, data_only=True).active.iter_rows(values_only=True))
head = rows[0]
out = []
for r in rows[1:]:
    if not any(r):
        continue
    d = dict(zip(head, r))
    s = lambda k: (str(d[k]).strip() if d.get(k) not in (None, '') else '')
    name = s('ชื่อวัด')
    if not name.startswith('วัด'):
        name = 'วัด' + name
    out.append([s('เขต/อำเภอ'), name, s('แขวง/ตำบล'), s('หมู่'), '', s('เบอร์โทรศัพท์'),
                s('รหัสวัด'), s('ประเภทวัด'), s('นิกาย')])
out.sort(key=lambda x: (x[0], x[2], x[1]))
with open(dst, 'w', newline='', encoding='utf-8-sig') as f:
    w = csv.writer(f)
    w.writerow(['อำเภอ', 'ชื่อวัด', 'ตำบล', 'หมู่ที่', 'เจ้าอาวาส', 'โทรศัพท์', 'รหัสวัด', 'ประเภทวัด', 'นิกาย'])
    w.writerows(out)
print(len(out), 'วัด')
