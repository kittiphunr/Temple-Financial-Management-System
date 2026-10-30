"""ชุดที่ 6: การแจ้งเตือนด้วย SweetAlert2 (แถบข้อความ/กล่องยืนยัน) และหน้าต่าง "เกี่ยวกับ"
ทดสอบฝั่งเซิร์ฟเวอร์ (HTML ที่ส่งออก + ไฟล์ที่ต้องโหลด) — พฤติกรรมในเบราว์เซอร์ตรวจด้วยตา"""
import glob
import re
from datetime import date

from support import App, Client, be_date, notice


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
    app.php('setup.php', 'Test12345pw', '--no-staff')
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
    adm.post('/transactions.php?type=income', {'action': 'save', 'id': '0', 'temple_id': '1', **be_date('txn_date', date(2026, 1, 5)), 'category_id': '1',
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
    check('U10 ลิงก์ภายนอกเปิดแท็บใหม่แบบปลอดภัย (noopener noreferrer)', len(re.findall(r'target="_blank" rel="noopener noreferrer"', tpl)) >= 3 and len(re.findall(r'target="_blank"', tpl)) == len(re.findall(r'target="_blank" rel="noopener noreferrer"', tpl)))
    check('U10c หน้าต่างเกี่ยวกับแสดงเครดิตส่วนประกอบที่ใช้ (SweetAlert2, thai-province-data ฯลฯ)', 'เครดิตส่วนประกอบที่ใช้' in tpl and 'thai-province-data' in tpl and 'SweetAlert2' in tpl)
    check('U10b หน้าต่างเกี่ยวกับระบุฟอนต์ JS Chanok + GPL v2 และลิงก์ไฟล์สัญญาอนุญาต',
          'JS Chanok' in tpl and 'GPL v2' in tpl and 'assets/fonts/GPL-License-JS.pdf' in tpl and Client(base).raw('/assets/fonts/GPL-License-JS.pdf')[0] == 200)
    check('U11 หน้าสาธารณะ (ก่อนเข้าสู่ระบบ) ก็มีหน้าต่างเกี่ยวกับ', 'id="about-template"' in Client(base).req('/')[2] or 'id="about-template"' in Client(base).req('/login.php')[2])

    # ================= วันที่ทุกจุดเป็น พ.ศ. =================
    today = date.today()
    be = today.year + 543
    _, _, b = adm.req('/transactions.php?type=income')
    years = [int(x) for x in re.findall(r'<option value="(\d{4})"', re.search(r'name="txn_date_y".*?</select>', b, re.S).group(0))]
    check('D1 ช่องปีของฟอร์มรายการบัญชีเป็น พ.ศ. ทั้งหมด (ไม่มี ค.ศ.) และเลือกปีปัจจุบันเป็นค่าเริ่มต้น',
          years and min(years) > 2500 and be in years and f'<option value="{be}" selected>' in b, str(years))
    check('D2 ไม่มี <input type="date"> (แสดงปีตามเบราว์เซอร์/ค.ศ.) เหลือในโค้ดหน้าเว็บ', not [f for f in glob.glob(str(app.dir / '*.php')) + glob.glob(str(app.dir / 'admin' / '*.php'))
                                                                              if 'type="date"' in open(f, encoding='utf-8').read()])
    save = lambda **kw: adm.post('/transactions.php?type=income', {'action': 'save', 'id': '0', 'temple_id': '1', 'category_id': '1', 'amount': '77', 'doc_no': '', 'description': 'วันที่', **kw}, '/transactions.php?type=income')
    n0 = q("SELECT COUNT(*) FROM transactions")
    save(**{'txn_date_d': '31', 'txn_date_m': '2', 'txn_date_y': str(be)})
    save(**{'txn_date_d': '5', 'txn_date_m': '1', 'txn_date_y': '2026'})
    save(**{'txn_date_d': '5', 'txn_date_m': '1'})
    save(**{'txn_date_d': 'x', 'txn_date_m': '1', 'txn_date_y': str(be)})
    check('D3 วันที่ไม่ถูกต้องถูกปฏิเสธ: 31 กุมภาพันธ์, ปี ค.ศ. (2026) ที่ใส่ผิดช่อง, ไม่ครบ, ไม่ใช่ตัวเลข', q("SELECT COUNT(*) FROM transactions") == n0)
    save(**{'txn_date_d': '5', 'txn_date_m': '1', 'txn_date_y': '2569'})
    check('D4 ปี พ.ศ. 2569 ถูกแปลงเก็บเป็น ค.ศ. 2026 ในฐานข้อมูล', q("SELECT txn_date FROM transactions WHERE description='วันที่' ORDER BY id DESC LIMIT 1") == '2026-01-05')
    c, _, b = adm.req('/transactions.php?type=income&year=2569')
    check('D5 รายการแสดงวันที่เป็น พ.ศ. (5 มกราคม 2569) และไม่มีวันที่ ค.ศ. รูปแบบ 2026-01-05 หลุดในหน้า', '5 มกราคม 2569' in b and not re.search(r'\b20[0-4]\d-\d\d-\d\d', b))
    rid = q("SELECT id FROM transactions WHERE description='วันที่' ORDER BY id DESC LIMIT 1")
    _, _, b = adm.req(f'/transactions.php?type=income&year=2569&edit={rid}')
    check('D6 แก้ไขรายการ: ฟอร์มแสดงวัน/เดือน/ปี พ.ศ. ของรายการเดิม', '<option value="5" selected>' in b and '<option value="1" selected>' in b and '<option value="2569" selected>' in b)
    c, h, body = adm.raw('/reports.php?export=csv&year=2569')
    check('D7 ส่งออก CSV: คอลัมน์วันที่เป็น พ.ศ. (05/01/2569)', '05/01/2569' in body.decode('utf-8-sig') and '2026-01-05' not in body.decode('utf-8-sig'))
    _, _, b = adm.req('/admin/audit.php')
    check('D8 ประวัติการใช้งานแสดงเวลาเป็น พ.ศ. (ไม่ใช่ 2026-xx-xx)', f' {be} ' in b and not re.search(r'\b20[0-4]\d-\d\d-\d\d \d\d:\d\d', b))
    for page in ('/dashboard.php', '/reports.php', '/receipts.php', '/account.php', '/admin/temple_accounts.php', '/temples.php'):
        _, _, b = adm.req(page)
        check(f'D9 {page} ไม่มีวันที่ ค.ศ. หลุดในหน้า', not re.search(r'\b20[0-4]\d-\d\d-\d\d', b))
    _, _, b = adm.req(f'/receipt.php?txn={q("SELECT id FROM transactions ORDER BY id LIMIT 1")}')
    check('D10 ฟอร์มวันที่ออกอนุโมทนาบัตรเป็นช่อง พ.ศ. (วัน/เดือน/ปี)', 'name="receipt_date_y"' in b and 'type="date"' not in b)
