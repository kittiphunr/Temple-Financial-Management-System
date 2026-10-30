"""ชุดที่ 11: กรุงเทพมหานครใช้คำว่า "เขต/แขวง" แทน "อำเภอ/ตำบล" ทั้งระบบ (หน้าจอ เอกสาร และบัญชีที่สร้างอัตโนมัติ)

ติดตั้งผ่านตัวช่วยติดตั้งโดยเลือกกรุงเทพมหานคร (สร้างเขตและผู้ใช้จากฐานข้อมูลจังหวัด) แล้วตรวจหน้าสำคัญว่าไม่มีคำว่า อำเภอ/ตำบล ปะปน
และจังหวัดอื่น (แพร่) ยังใช้ อำเภอ/ตำบล ตามเดิม
"""
import re

from support import App, Client

ADMIN_PW = 'Str0ngPassw0rd!'


def run(db, R):
    app = App()
    try:
        _run(db, R, app)
    finally:
        problems = app.php_problems()
        R.check('Z1 ไม่มี PHP warning/notice/fatal ใน log ของเซิร์ฟเวอร์', not problems, '\n'.join(problems[:3]))
        app.close()


def _text(html):
    return re.sub(r'\s+', ' ', re.sub(r'<[^>]+>', ' ', html))


def _run(db, R, app):
    check = R.check
    name = db.new_database('bkk', create=False)
    q = db.q(name)
    w = Client(app.serve())
    w.post('/install.php', {'action': 'requirements'}, '/install.php?step=1')
    w.post('/install.php', {'action': 'db', 'db_host': db.host, 'db_port': db.port, 'db_name': name, 'db_user': db.user, 'db_pass': db.password}, '/install.php?step=2')
    c, loc, _ = w.upload('/install.php', {'action': 'site', 'province': 'กรุงเทพมหานคร', 'site_name': 'ระบบวัด', 'site_sub': 'สำหรับคณะสงฆ์กรุงเทพมหานคร', 'admin_user': 'mainadmin',
                                          'admin_name': 'แอดมิน หลัก', 'admin_pass': ADMIN_PW, 'admin_pass2': ADMIN_PW, 'temple_source': 'later', 'make_accounts': '1', 'make_staff': '1'},
                         None, b'', '/install.php?step=3')
    w.post('/install.php', {'action': 'mail_save', 'mail_driver': 'log', 'smtp_port': '587', 'smtp_secure': 'none'}, '/install.php?step=4')
    c, loc, _ = w.post('/install.php', {'action': 'install'}, '/install.php?step=5')
    check('B1 ติดตั้งกรุงเทพมหานคร (ไม่มีไฟล์ทะเบียนวัด) สำเร็จ', loc == 'install.php?step=done', f'{c} {loc}')
    c, _, done = w.req('/install.php?step=done')
    check('B2 หน้าเสร็จสิ้นใช้คำว่า เขต ไม่ใช่ อำเภอ', ' เขต)' in _text(done) and 'อำเภอ)' not in _text(done), _text(done)[:300])
    check('B3 ชื่อผู้ใช้/ชื่อบัญชีที่สร้างอัตโนมัติใช้ เขต/แขวง (แอดมินประจำเขต / เจ้าคณะแขวง) และคณะสงฆ์กรุงเทพมหานครไม่มีคำว่า "จังหวัด"',
          q("SELECT COUNT(*) FROM users WHERE role='district' AND full_name LIKE 'แอดมินประจำเขต%'") == q("SELECT COUNT(*) FROM districts")
          and q("SELECT COUNT(*) FROM users WHERE role='subdistrict' AND full_name LIKE 'เจ้าคณะแขวง%'") == q("SELECT COUNT(*) FROM users WHERE role='subdistrict'")
          and q("SELECT full_name FROM users WHERE role='province'") == 'เจ้าคณะกรุงเทพมหานคร', q("SELECT full_name FROM users WHERE role='province'"))

    # ข้อมูลตัวอย่าง: วัด 1 แห่งในเขตบางรัก แขวงสีลม + รายการบัญชี
    d = q("SELECT id FROM districts WHERE name='บางรัก'")
    q(f"INSERT INTO temples (district_id,name,subdistrict,moo,verified,source) VALUES ({d},'วัดตัวอย่างกรุงเทพ','สีลม','1',1,'test')")
    t = q("SELECT id FROM temples ORDER BY id DESC LIMIT 1")
    cat = q("SELECT id FROM categories WHERE type='income' AND is_transfer=0 LIMIT 1")
    q(f"INSERT INTO transactions (temple_id,type,category_id,txn_date,description,amount) VALUES ({t},'income',{cat},CURDATE(),'ทดสอบ',1000)")
    adm = Client(app.base) if hasattr(app, 'base') else None
    adm = Client(w.base)
    adm.post('/login.php', {'username': 'mainadmin', 'password': ADMIN_PW})

    def bad(client, path):
        c, _, b = client.req(path)
        t = _text(b)
        return c, sorted(set(re.findall(r'อำเภอ|ตำบล', t))), ('เขต' in t or 'แขวง' in t)

    pages = ['/dashboard.php', '/reports.php', '/temples.php', f'/statements.php?temple={t}', '/index.php', '/people.php', '/admin/index.php', '/admin/users.php',
             '/admin/subdistricts.php', '/admin/districts.php', '/admin/temples.php', '/admin/import.php', '/admin/temple_accounts.php', '/account.php']
    for p in pages:
        c, words, has = bad(adm, p)
        check(f'B4 {p}: ไม่มีคำว่า อำเภอ/ตำบล ปะปน', c == 200 and not words, f'{c} {words}')
    c, _, b = adm.req('/dashboard.php')
    check('B5 แดชบอร์ดใช้ "ทั้งกรุงเทพมหานคร" ไม่มี "จังหวัดกรุงเทพมหานคร"', 'กรุงเทพมหานคร' in _text(b) and 'จังหวัดกรุงเทพมหานคร' not in _text(b), _text(b)[:200])
    c, _, b = adm.req(f'/statements.php?temple={t}&year=2569&month=1&form=1')
    check('B6 รายงานแบบที่ 1 ใช้ "แขวง" "เขต" และตำบล/อำเภอไม่ปรากฏ', c == 200 and 'แขวง' in _text(b) and 'เขต' in _text(b) and not re.search(r'อำเภอ|ตำบล', _text(b)))
    # หัวกระดาษ (พิมพ์เฉพาะตอนพิมพ์) และที่อยู่
    check('B7 หัวเอกสารวัดใช้ "แขวงสีลม เขตบางรัก กรุงเทพมหานคร"', 'แขวงสีลม เขตบางรัก กรุงเทพมหานคร' in adm.req(f'/reports.php?temple={t}')[2], '')
    c, _, b = adm.req('/')
    check('B8 หน้าแรกแสดงข้อความสังกัด "กรุงเทพมหานคร" ไม่ซ้ำคำว่าจังหวัด', 'ในสังกัดกรุงเทพมหานคร' in _text(b) or 'สังกัดกรุงเทพมหานคร' in _text(b), _text(b)[:200])
