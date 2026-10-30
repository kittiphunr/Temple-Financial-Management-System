#!/usr/bin/env python3
"""สร้าง CREDITS.md และบล็อกเครดิตในเว็บประชาสัมพันธ์จาก data/credits.json (แหล่งข้อมูลเดียว)

ใช้:  python3 tools/sync_credits.py          # เขียนไฟล์ใหม่
      python3 tools/sync_credits.py --check  # ตรวจว่าไฟล์ตรงกับ credits.json (ชุดทดสอบ credits เรียกใช้)
นโยบาย: ทุกครั้งที่นำโปรเจค/ไลบรารี/ฟอนต์/ข้อมูลของผู้อื่นมาใช้ ต้องเพิ่มรายการใน data/credits.json แล้วรันสคริปต์นี้ (ดู CREDITS.md)
"""
import html
import json
import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parent.parent
START, END = '<!-- credits:start -->', '<!-- credits:end -->'


def load():
    return json.loads((ROOT / 'data' / 'credits.json').read_text(encoding='utf-8'))


def render_md(items):
    rows = []
    for c in items:
        name = f"[{c['name']}]({c['url']})" if c['url'] else c['name']
        lic = f"{c['license']}" + (f" ([ไฟล์สัญญาอนุญาต]({c['license_file']}))" if c['license_file'] else '')
        rows.append(f"| {name} | {c['use']} | {c['author']} | {lic} |")
    return f"""# เครดิตและสัญญาอนุญาตของส่วนประกอบที่ใช้

ระบบนี้สร้างขึ้นโดยใช้ผลงานของผู้อื่นตามรายการด้านล่าง ขอขอบคุณเจ้าของผลงานทุกท่าน
โค้ดของระบบเองเผยแพร่ภายใต้ [MIT License](LICENSE) (ไม่รวมส่วนประกอบในตารางนี้ ตราสัญลักษณ์ และรูปผู้พัฒนา)

## นโยบายการให้เครดิต
**ทุกครั้งที่นำโปรเจค ไลบรารี ฟอนต์ ข้อมูล หรือโค้ดของผู้อื่นมาใช้ ต้องให้เครดิตเสมอ** โดยในการเปลี่ยนแปลงเดียวกันที่นำของนั้นเข้ามา ต้อง:

1. เพิ่มรายการใน `data/credits.json` (ชื่อ ผู้สร้าง สัญญาอนุญาต ลิงก์ ใช้ทำอะไร และไฟล์ที่นำเข้ามา)
2. แนบไฟล์สัญญาอนุญาตต้นฉบับของเจ้าของผลงานมาด้วย และระบุใน `license_file` (คงข้อความลิขสิทธิ์ตามที่สัญญากำหนด)
3. รัน `python3 tools/sync_credits.py` เพื่อปรับไฟล์นี้และบล็อกเครดิตในเว็บประชาสัมพันธ์ (`website/index.html`) — รายการเดียวกันแสดงในหน้าต่าง "เกี่ยวกับ" ของระบบโดยอัตโนมัติ
4. ตรวจก่อนนำเข้าว่าสัญญาอนุญาตของผลงานนั้นอนุญาตให้ใช้และแจกจ่ายต่อได้ ถ้าไม่ได้ระบุสัญญาอนุญาต ให้ระบุข้อเท็จจริงนั้นตรง ๆ ในช่อง `license` (ห้ามเดา)

ชุดทดสอบ `python3 tests/run_all.py credits` ตรวจให้อัตโนมัติ: ไฟล์ใน `assets/vendor/` และ `assets/fonts/` ทุกไฟล์ต้องมีรายการเครดิต, ไฟล์สัญญาอนุญาตที่ระบุต้องมีอยู่จริง, และ CREDITS.md / เว็บประชาสัมพันธ์ต้องตรงกับ `data/credits.json`

## รายการ
| ส่วนประกอบ | ใช้ทำอะไร | ผู้สร้าง | สัญญาอนุญาต |
|---|---|---|---|
""" + '\n'.join(rows) + '\n\n_ไฟล์นี้สร้างจาก `data/credits.json` ด้วย `tools/sync_credits.py` — แก้ที่ต้นทาง ไม่แก้ที่นี่_\n'


def render_web(items):
    lis = []
    for c in items:
        name = f'<a href="{html.escape(c["url"])}" target="_blank" rel="noopener">{html.escape(c["name"])}</a>' if c['url'] else html.escape(c['name'])
        lis.append(f'      <li>{name} — {html.escape(c["author"])} · {html.escape(c["license"].split(" — ")[0])}</li>')
    return (START + '\n  <details class="credits"><summary>เครดิตและส่วนประกอบที่ใช้</summary>\n    <ul>\n' + '\n'.join(lis)
            + '\n    </ul>\n    <p class="small">รายละเอียดและนโยบายการให้เครดิต: <a href="https://github.com/kittiphunr/Temple-Financial-Management-System/blob/main/CREDITS.md" target="_blank" rel="noopener">CREDITS.md</a></p>\n  </details>\n  ' + END)


def render_index(text, items):
    a, b = text.index(START), text.index(END) + len(END)
    return text[:a] + render_web(items) + text[b:]


def main():
    items = load()
    md = render_md(items)
    idx_path = ROOT / 'website' / 'index.html'
    idx = idx_path.read_text(encoding='utf-8')
    new_idx = render_index(idx, items)
    if '--check' in sys.argv:
        ok = (ROOT / 'CREDITS.md').read_text(encoding='utf-8') == md and idx == new_idx
        print('ตรงกับ data/credits.json' if ok else 'ไม่ตรงกับ data/credits.json — รัน python3 tools/sync_credits.py')
        sys.exit(0 if ok else 1)
    (ROOT / 'CREDITS.md').write_text(md, encoding='utf-8')
    idx_path.write_text(new_idx, encoding='utf-8')
    print(f'เขียน CREDITS.md และ website/index.html ({len(items)} รายการ)')


if __name__ == '__main__':
    main()
