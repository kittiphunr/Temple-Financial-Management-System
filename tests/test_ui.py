"""ชุดที่ 6: การแจ้งเตือนด้วย SweetAlert2 (แถบข้อความ/กล่องยืนยัน) และหน้าต่าง "เกี่ยวกับ"
ทดสอบฝั่งเซิร์ฟเวอร์ (HTML ที่ส่งออก + ไฟล์ที่ต้องโหลด) — พฤติกรรมในเบราว์เซอร์ตรวจด้วยตา"""
import glob
import re

from support import App, Client, notice


def run(db, R):
    name = db.new_database('ui')
    q = db.q(name)
    app = App(env={'TFMS_DB_HOST': db.host, 'TFMS_DB_PORT': db.port, 'TFMS_DB_USER': db.user, 'TFMS_DB_PASS': db.password,
                   'TFMS_DB_NAME': name, 'TFMS_MAIL_DRIVER': 'log'})
    try:
        _run(R, q, app)
    finally:
        problems = app.php_problems()
        R.check('Z1 ไม่มี PHP warning/notice/fatal ใน log ของเซิร์ฟเวอร์', not problems, '\n'.join(problems[:3]))
        app.close()


def _run(R, q, app):
    check = R.check
    app.php('setup.php', 'Test12345pw')
    base = app.serve()
    g = Client(base)
    _, _, b = g.req('/login.php')
    check('U1 ทุกหน้าโหลด SweetAlert2 และสคริปต์แจ้งเตือนจากเซิร์ฟเวอร์เอง (ไม่พึ่ง CDN)',
          'assets/vendor/sweetalert2.all.min.js' in b and 'assets/js/app.js' in b and 'cdn' not in b.lower())
    for path, ctype in (('/assets/vendor/sweetalert2.all.min.js', b'sweetalert2'), ('/assets/js/app.js', b'Swal'), ('/assets/img/developer.jpg', b''), ('/assets/img/logo.svg', b'<svg')):
        c, h, body = g.raw(path)
        check(f'U2 ไฟล์ {path} เปิดได้', c == 200 and len(body) > 1000 and ctype in body, str(c))
    g.post('/login.php', {'username': 'admin', 'password': 'wrong-password'})
    _, _, b = g.req('/login.php')
    c, _, b = g.post('/login.php', {'username': 'admin', 'password': 'wrong-password'}, '/login.php')
    check('U3 ข้อผิดพลาดตอนเข้าสู่ระบบแสดงผ่าน SweetAlert2 (data-swal="err") และยังอ่านข้อความได้เมื่อไม่มี JS',
          'data-swal="err"' in b and 'ไม่ถูกต้อง' in notice(b))
    adm = Client(base)
    adm.post('/login.php', {'username': 'admin', 'password': 'Test12345pw'})
    adm.post('/transactions.php?type=income', {'action': 'save', 'id': '0', 'temple_id': '1', 'txn_date': '2026-01-05', 'category_id': '1',
                                                'amount': '100', 'doc_no': '', 'description': 'x'}, '/transactions.php?type=income')
    _, _, b = adm.req('/transactions.php?type=income')
    check('U4 ข้อความสำเร็จ (flash) แสดงเป็น data-swal="ok" ในหน้าถัดไป', 'data-swal="ok"' in b and 'บันทึก' in notice(b))
    check('U5 ปุ่มลบใช้ data-confirm (กล่องยืนยันของ SweetAlert2) ไม่ใช้ confirm() ของเบราว์เซอร์', 'data-confirm="ยืนยันการลบรายการนี้?"' in b and 'confirm(' not in b)
    _, _, b = adm.req('/transactions.php?type=income')
    check('U6 ข้อความ flash แสดงครั้งเดียว (รีเฟรชแล้วไม่ซ้ำ)', 'data-swal="ok"' not in b)

    # ห้ามเหลือ confirm() หรือ alert() ดิบของเบราว์เซอร์ในซอร์ส
    bad = []
    for f in glob.glob(str(app.dir / '*.php')) + glob.glob(str(app.dir / 'admin' / '*.php')) + glob.glob(str(app.dir / 'includes' / '*.php')):
        s = open(f, encoding='utf-8').read()
        if re.search(r'\bconfirm\(|\balert\(', s):
            bad.append(f.split('/')[-1])
    check('U7 ไม่มี confirm()/alert() ดิบเหลือในโค้ดหน้าเว็บ', not bad, ','.join(bad))

    # หน้าต่างเกี่ยวกับ
    check('U8 ท้ายหน้ามีปุ่ม "เกี่ยวกับระบบ · ผู้พัฒนา" และ template ของหน้าต่าง', 'data-about' in b and 'id="about-template"' in b)
    tpl = re.search(r'<template id="about-template">(.*?)</template>', b, re.S).group(1)
    check('U9 หน้าต่างเกี่ยวกับมีผู้พัฒนา อีเมล โทร GitHub เว็บไซต์ และคำอธิบายเว็บไซต์',
          all(x in tpl for x in ('ผู้พัฒนา', 'Kittiphun Ruttanakorn', 'mailto:kittiphun.rut@mcu.ac.th', 'tel:0899554194', 'github.com/kittiphunr', 'kittiphunr.github.io', 'เกี่ยวกับเว็บไซต์')))
    check('U9b หัวหน้าต่างเป็นชื่อระบบและตราคณะสงฆ์จังหวัดแพร่ (ไม่ใช่ชื่อหน่วยงานของผู้พัฒนา)', 'เกี่ยวกับ ระบบบัญชีรายรับ-รายจ่ายวัด' in tpl and 'assets/img/logo.svg' in tpl and 'เกี่ยวกับ มหาวิทยาลัย' not in tpl)
    check('U10 ลิงก์ภายนอกเปิดแท็บใหม่แบบปลอดภัย (noopener noreferrer)', len(re.findall(r'target="_blank" rel="noopener noreferrer"', tpl)) == 2)
    check('U11 หน้าสาธารณะ (ก่อนเข้าสู่ระบบ) ก็มีหน้าต่างเกี่ยวกับ', 'id="about-template"' in Client(base).req('/')[2] or 'id="about-template"' in Client(base).req('/login.php')[2])
