"""ชุดที่ 10: นโยบายเครดิต — ใช้ผลงานของใครต้องให้เครดิตเสมอ (ดู CREDITS.md)

ไม่ใช้ฐานข้อมูล: ตรวจว่า data/credits.json ครบ ไฟล์ที่นำเข้ามาทุกไฟล์มีเครดิต ไฟล์สัญญาอนุญาตมีจริง และ CREDITS.md / เว็บประชาสัมพันธ์ / หน้าต่างเกี่ยวกับตรงกับต้นทาง
"""
import json
import subprocess
import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parent.parent


def run(db, R):
    check = R.check
    items = json.loads((ROOT / 'data' / 'credits.json').read_text(encoding='utf-8'))
    need = ('id', 'name', 'use', 'author', 'license', 'url', 'license_file', 'files')
    check('C1 ทุกรายการมีช่องครบ (id/name/use/author/license/url/license_file/files)', all(all(k in c for k in need) for c in items) and len({c['id'] for c in items}) == len(items))
    check('C2 ทุกรายการระบุผู้สร้างและสัญญาอนุญาต (ไม่ว่าง)', all(c['author'].strip() and c['license'].strip() and c['name'].strip() for c in items))
    listed = {f for c in items for f in c['files']}
    check('C3 ไฟล์ที่ระบุในรายการมีอยู่จริง', all((ROOT / f).is_file() for f in listed), str([f for f in listed if not (ROOT / f).is_file()]))
    check('C4 ไฟล์สัญญาอนุญาตที่ระบุมีอยู่จริง', all(not c['license_file'] or (ROOT / c['license_file']).is_file() for c in items))
    # ผลงานที่ฝังมากับระบบต้องมีเครดิต: ไลบรารี/ฟอนต์ที่ฝังไว้ทุกไฟล์
    bundled = [p.relative_to(ROOT).as_posix() for d, exts in (('assets/vendor', {'.js', '.css', '.map'}), ('assets/fonts', {'.ttf', '.otf', '.woff', '.woff2'})) for p in (ROOT / d).glob('*') if p.suffix.lower() in exts]
    check('C5 ไฟล์ใน assets/vendor และ assets/fonts ทุกไฟล์มีรายการเครดิต', bundled and all(b in listed for b in bundled), str([b for b in bundled if b not in listed]))
    check('C6 ข้อมูล/ไลบรารีที่นำเข้ามาแบบอื่นมีเครดิต (data/thai_geo.json, data/temples_phrae.csv, assets/css/tailwind.css)',
          all(f in listed for f in ('data/thai_geo.json', 'data/temples_phrae.csv', 'assets/css/tailwind.css')))
    p = subprocess.run([sys.executable, str(ROOT / 'tools' / 'sync_credits.py'), '--check'], capture_output=True, text=True)
    check('C7 CREDITS.md และบล็อกเครดิตในเว็บประชาสัมพันธ์ตรงกับ data/credits.json', p.returncode == 0, p.stdout + p.stderr)
    md = (ROOT / 'CREDITS.md').read_text(encoding='utf-8')
    check('C8 CREDITS.md มีนโยบายและชื่อทุกรายการ', 'นโยบายการให้เครดิต' in md and all(c['name'] in md for c in items))
    readme = (ROOT / 'README.md').read_text(encoding='utf-8')
    check('C9 README ลิงก์นโยบายเครดิต และ CLAUDE.md ระบุนโยบายสำหรับเอเจนต์', 'CREDITS.md' in readme and 'นโยบายเครดิต' in (ROOT / 'CLAUDE.md').read_text(encoding='utf-8'))
    php = subprocess.run(['php', '-r', "require 'includes/bootstrap.php';"], cwd=ROOT, capture_output=True, text=True)
    out = subprocess.run(['php', '-r', "function e($s){return htmlspecialchars((string)$s);} require 'includes/about.php'; echo credits_html();"], cwd=ROOT, capture_output=True, text=True).stdout
    check('C10 หน้าต่างเกี่ยวกับแสดงเครดิตครบทุกรายการ', all(c['author'].replace('&', '&amp;') in out for c in items) and out.count('<li>') == len(items), out[:200])
    build = (ROOT / 'deploy' / 'build.sh').read_text(encoding='utf-8')
    check('C11 ชุดติดตั้งแจกไฟล์เครดิตไปด้วย (data/credits.json, CREDITS.md)', 'credits.json' in build and 'CREDITS.md' in build)
