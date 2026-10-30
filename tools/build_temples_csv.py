#!/usr/bin/env python3
"""แปลง Report_Temple.xlsx (ทะเบียนวัดจาก พศ.) เป็น data/temples_phrae.csv
ใช้: python3 tools/build_temples_csv.py Report_Temple.xlsx data/temples_phrae.csv"""
import csv, re, sys, openpyxl

src, dst = sys.argv[1], sys.argv[2]


def founded_year(v):
    """ปี พ.ศ. 4 หลักท้ายของค่า (ทะเบียนบางแถวเป็นวันที่เต็ม เช่น 10/6/2556)"""
    m = re.search(r'(\d{4})\s*$', v)
    return m.group(1) if m else ''

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
                s('รหัสวัด'), s('ประเภทวัด'), s('นิกาย'),
                s('ชื่อหมู่บ้าน'), s('บ้านเลขที่'), s('ซอย'), s('ถนน'), s('รหัสไปรษณีย์'), founded_year(s('วันที่ตั้งวัด'))])
out.sort(key=lambda x: (x[0], x[2], x[1]))
with open(dst, 'w', newline='', encoding='utf-8-sig') as f:
    w = csv.writer(f)
    w.writerow(['อำเภอ', 'ชื่อวัด', 'ตำบล', 'หมู่ที่', 'เจ้าอาวาส', 'โทรศัพท์', 'รหัสวัด', 'ประเภทวัด', 'นิกาย',
                'ชื่อหมู่บ้าน', 'บ้านเลขที่', 'ซอย', 'ถนน', 'รหัสไปรษณีย์', 'ปีที่ตั้งวัด(พ.ศ.)'])
    w.writerows(out)
print(len(out), 'วัด')
