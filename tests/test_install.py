"""ชุดที่ 2: ตัวช่วยติดตั้งบนเว็บ (install.php) และการบังคับเปลี่ยนรหัสผ่านครั้งแรก

เริ่มจากสำเนาโปรเจกต์ที่ "ยังไม่ติดตั้ง" (ไม่มี config.local.php / installed.lock / ตัวแปรสภาพแวดล้อม TFMS_*)
"""
import os
import re
import time

from support import (Client, FakeSMTP, App, age_otps, free_port, hash_password, notice, parse_creds_csv)

KEY = 'key-abc-123'
ADMIN_PW = 'Str0ngPassw0rd!'


def run(db, R):
    smtp = FakeSMTP()
    wiz = db.new_database('wiz', create=False)   # ตัวช่วยติดตั้งต้องสร้างฐานข้อมูลนี้เอง
    full = db.new_database('full')               # ฐานข้อมูลที่มีข้อมูลระบบอยู่แล้ว (ต้องไม่ถูกเขียนทับ)
    db.run('CREATE TABLE users (id INT PRIMARY KEY)', full)
    db.run('INSERT INTO users VALUES (1)', full)
    app = App()
    try:
        _run(db, R, smtp, wiz, full, app)
    finally:
        problems = app.php_problems()
        R.check('Z1 ไม่มี PHP warning/notice/fatal ใน log ของเซิร์ฟเวอร์', not problems, '\n'.join(problems[:3]))
        app.close()
        try:
            _provinces(db, R)
        finally:
            smtp.stop()


def _run(db, R, smtp, wiz, full, app):
    check = R.check
    q = db.q(wiz)
    base = app.serve()
    base_key = app.serve({'TFMS_INSTALL_KEY': KEY})

    # ===== รหัสป้องกันการติดตั้ง (TFMS_INSTALL_KEY) =====
    k = Client(base_key)
    c, _, b = k.req('/install.php')
    check('K1 ตั้งรหัสติดตั้ง → ถามรหัสก่อน', 'รหัสติดตั้ง' in b and 'ตรวจความพร้อมของเซิร์ฟเวอร์' not in b)
    k.post('/install.php', {'key': 'wrong'})
    c, _, b = k.req('/install.php?step=1')
    check('K2 รหัสผิด → ยังไม่ผ่าน', 'ตรวจความพร้อมของเซิร์ฟเวอร์' not in b)
    k.post('/install.php', {'key': KEY})
    c, _, b = k.req('/install.php?step=1')
    check('K3 รหัสถูก → เข้าขั้นที่ 1', 'ตรวจความพร้อมของเซิร์ฟเวอร์' in b)

    # ===== ตัวช่วยติดตั้ง =====
    w = Client(base)
    c, loc, b = w.req('/index.php')
    check('W0 ยังไม่ติดตั้ง → หน้าเว็บเด้งไป install.php', c == 302 and 'install.php' in loc)
    c, loc, b = w.req('/admin/index.php')
    check('W0b หน้า admin ก็เด้งไป install.php', c == 302 and 'install.php' in loc)
    c, loc, b = w.req('/install.php')
    check('W1 หน้าตรวจความพร้อมแสดงรายการ', 'PHP 8.1 ขึ้นไป' in b and 'pdo_mysql' in b)
    c, loc, b = w.req('/install.php?step=3')
    check('W1b ข้ามขั้นไม่ได้ (ถูกดึงกลับขั้น 1)', 'ตรวจความพร้อมของเซิร์ฟเวอร์' in b)
    c, loc, b = w.req('/install.php', {'action': 'requirements'})
    check('W1c POST ไม่มี CSRF ถูกปฏิเสธ (400)', c == 400)
    c, loc, b = w.post('/install.php', {'action': 'requirements'})
    check('W1d ผ่านตรวจความพร้อม → ขั้น 2', c == 302 and loc == 'install.php?step=2')

    # --- ขั้น 2: ฐานข้อมูล
    def dbpost(**kw):
        d = {'action': 'db', 'db_host': db.host, 'db_port': db.port, 'db_name': wiz, 'db_user': db.user, 'db_pass': db.password}
        d.update(kw)
        w.post('/install.php', d, '/install.php?step=2')
        return w.req('/install.php?step=2')

    c, _, b = dbpost(db_pass='definitely-wrong-password')
    check('W2a รหัสฐานข้อมูลผิด → แจ้งเชื่อมต่อไม่ได้', 'เชื่อมต่อเซิร์ฟเวอร์ฐานข้อมูลไม่ได้' in notice(b), notice(b))
    c, _, b = dbpost(db_name='bad name!')
    check('W2b ชื่อฐานข้อมูลไม่ถูกต้อง → ปฏิเสธ', 'ข้อมูลไม่ถูกต้อง' in notice(b), notice(b))
    c, _, b = dbpost(db_name=full)
    check('W2c ฐานข้อมูลที่มีข้อมูลอยู่แล้ว → ไม่เขียนทับ', 'มีข้อมูลผู้ใช้ของระบบอยู่แล้ว' in notice(b), notice(b))
    w.post('/install.php', {'action': 'db', 'db_host': db.host, 'db_port': db.port, 'db_name': wiz, 'db_user': db.user, 'db_pass': db.password}, '/install.php?step=2')
    c, loc, b = w.req('/install.php?step=3')
    check('W2d ฐานข้อมูลใหม่ (ถูกสร้างให้) → ไปขั้น 3', 'จังหวัดที่ดูแล และข้อมูลเบื้องต้น' in b)
    check('W2e ฐานข้อมูลที่ระบุถูกสร้างขึ้น',
          db.run(f"SELECT SCHEMA_NAME FROM information_schema.SCHEMATA WHERE SCHEMA_NAME='{wiz}'") == wiz)

    # --- ขั้น 3: เว็บไซต์และแอดมินหลัก
    def sitepost(**kw):
        d = {'action': 'site', 'site_name': 'ระบบวัดแพร่ทดสอบ', 'site_sub': 'คณะสงฆ์จังหวัดแพร่', 'admin_user': 'mainadmin',
             'admin_name': 'แอดมิน หลัก', 'admin_pass': ADMIN_PW, 'admin_pass2': ADMIN_PW, 'import_temples': '1', 'make_accounts': '1'}
        d.update(kw)
        return w.post('/install.php', d, '/install.php?step=3')

    for label, kw, msg in [
        ('weak', dict(admin_pass='abc', admin_pass2='abc'), 'อย่างน้อย 10'),
        ('nodigit', dict(admin_pass='abcdefghijkl', admin_pass2='abcdefghijkl'), 'ตัวอักษรและตัวเลข'),
        ('mismatch', dict(admin_pass2='Different1234'), 'ไม่ตรงกัน'),
        ('wat-prefix', dict(admin_user='watboss'), 'สงวนไว้'),
        ('password-contains-username', dict(admin_pass='mainadmin12345', admin_pass2='mainadmin12345'), 'ชื่อผู้ใช้อยู่ในนั้น'),
    ]:
        sitepost(**kw)
        c, _, b = w.req('/install.php?step=3')
        check(f'W3 ข้อมูลแอดมินไม่ผ่านเงื่อนไข: {label}', msg in notice(b), notice(b))
    c, loc, b = sitepost()
    check('W3ok ข้อมูลเว็บไซต์ถูกต้อง → ขั้น 4', c == 302 and loc == 'install.php?step=4')

    # --- ขั้น 4: อีเมล
    def mailpost(action, **kw):
        d = {'action': action, 'mail_driver': 'smtp', 'smtp_host': '127.0.0.1', 'smtp_port': str(smtp.port), 'smtp_secure': 'none',
             'smtp_user': 'smtpuser', 'smtp_pass': 'smtppass', 'mail_from': 'no-reply@wat.test', 'test_to': 'admin@example.org'}
        d.update(kw)
        w.post('/install.php', d, '/install.php?step=4')
        return w.req('/install.php?step=4')

    n = len(smtp.messages)
    c, _, b = mailpost('mail_test')
    check('W4a ส่งอีเมลทดสอบสำเร็จ (SMTP ปลอมรับได้)',
          'ส่งอีเมลทดสอบแล้ว' in notice(b) and len(smtp.messages) == n + 1 and 'admin@example.org' in smtp.messages[-1]['rcpt'][0], notice(b))
    c, _, b = mailpost('mail_test', smtp_port=str(free_port()))
    check('W4b SMTP ผิด → แจ้งส่งไม่สำเร็จ', 'ไม่สำเร็จ' in notice(b), notice(b))
    c, _, b = mailpost('mail_test', test_to='nope')
    check('W4c อีเมลปลายทางผิดรูปแบบ → ปฏิเสธ', 'ให้ถูกต้อง' in notice(b), notice(b))
    w.post('/install.php', {'action': 'mail_save', 'mail_driver': 'smtp', 'smtp_host': '127.0.0.1', 'smtp_port': str(smtp.port),
                            'smtp_secure': 'none', 'smtp_user': 'smtpuser', 'smtp_pass': 'smtppass', 'mail_from': 'no-reply@wat.test'}, '/install.php?step=4')
    c, _, b = w.req('/install.php?step=5')
    check('W4d บันทึกอีเมล → ไปขั้น 5', 'ตรวจสอบและติดตั้ง' in b)

    # --- ขั้น 5: ติดตั้ง
    check('W5a หน้าสรุปแสดงผู้ใช้/ฐานข้อมูล แต่ไม่โชว์รหัสผ่านใด ๆ',
          wiz in b and 'mainadmin' in b and 'Str0ngPassw0rd' not in b and 'smtppass' not in b
          and (not db.password or db.password not in b))
    t0 = time.time()
    c, loc, b = w.post('/install.php', {'action': 'install'}, '/install.php?step=5')
    dt = time.time() - t0
    check(f'W5b ติดตั้ง ({dt:.0f} วินาที) → ไปหน้าเสร็จสิ้น', c == 302 and loc == 'install.php?step=done', f'{c} {loc}')
    c, _, b = w.req('/install.php?step=done')
    check('W6a หน้าเสร็จสิ้นมีปุ่มดาวน์โหลดรหัสผ่านเริ่มต้น', 'ติดตั้งเสร็จเรียบร้อย' in b and 'download=creds' in b and '381' in b)
    c, _, csvb = w.req('/install.php?download=creds')
    rows = parse_creds_csv(csvb)
    check('W6b ดาวน์โหลด CSV ได้ 381 บัญชี', c == 200 and len(rows) == 381, str(len(rows)))
    c, _, b = w.req('/install.php?download=creds')
    check('W6c ดาวน์โหลดซ้ำไม่ได้ (ครั้งเดียว)', 'text/csv' not in str(w.headers.get('Content-Type')))

    # --- ผลการติดตั้ง
    check('W7a สร้างไฟล์ล็อก installed.lock', (app.dir / 'storage' / 'installed.lock').is_file())
    cfg = app.dir / 'config.local.php'
    txt = cfg.read_text()
    check('W7b config.local.php สิทธิ์ 0640, มีค่าฐานข้อมูล, ไม่มีรหัสผ่านแอดมินหลัก',
          (cfg.stat().st_mode & 0o777) == 0o640 and f"'db_name' => '{wiz}'" in txt and 'Str0ngPassw0rd' not in txt, oct(cfg.stat().st_mode & 0o777))
    check('W7c รหัสผ่านแอดมินหลักเก็บเป็นแฮช', q("SELECT password_hash FROM users WHERE username='mainadmin'").startswith('$2y$'))
    check('W7d ทะเบียนวัด 381 + บัญชีวัด 381 + บังคับเปลี่ยนรหัสทุกบัญชี',
          q("SELECT COUNT(*) FROM temples") == '381' and q("SELECT COUNT(*) FROM users WHERE role='temple'") == '381'
          and q("SELECT COUNT(*) FROM users WHERE role='temple' AND must_change_password=1") == '381')
    check('W7e แอดมินหลักไม่ถูกบังคับเปลี่ยนรหัส', q("SELECT must_change_password FROM users WHERE username='mainadmin'") == '0')
    c, loc, b = w.req('/install.php')
    check('W8a ติดตั้งแล้ว → install.php ใช้ไม่ได้ (403)', c == 403 and 'ติดตั้งเรียบร้อยแล้ว' in b)
    c, loc, b = Client(base).req('/install.php?step=2')
    check('W8b คนอื่นเปิด install.php หลังติดตั้ง → 403', c == 403)
    c, _, _ = Client(base).post('/install.php', {'action': 'install'}, '/install.php')
    check('W8c POST ติดตั้งซ้ำหลังติดตั้ง → ไม่ทำงาน', c in (400, 403))
    c, _, b = Client(base).req('/index.php')
    check('W8d หน้าเว็บใช้งานปกติ และใช้ชื่อระบบที่ตั้งไว้', c == 200 and 'ระบบวัดแพร่ทดสอบ' in b)

    # ===== บังคับเปลี่ยนรหัสผ่านครั้งแรก =====
    adm = Client(base)
    c, loc, _ = adm.post('/login.php', {'username': 'mainadmin', 'password': ADMIN_PW})
    check('F0 แอดมินหลักเข้าสู่ระบบ (ไม่ต้อง 2FA ไม่บังคับเปลี่ยน)', c == 302 and loc == 'index.php')
    check('F0b แอดมินหลักเข้า บัญชีวัด ได้', adm.req('/admin/temple_accounts.php')[0] == 200)
    code, _, tname, u, p = rows[0]
    tid = q(f"SELECT id FROM temples WHERE temple_code='{code}'")

    t = Client(base)
    c, loc, b = t.post('/login.php', {'username': u, 'password': p})
    check('F1 วัดที่ยังไม่มีอีเมล เข้าไม่ได้', c == 200 and 'ยังไม่ได้ลงทะเบียนอีเมล' in b)
    adm.post('/admin/temple_accounts.php', {'action': 'save_email', 'temple_id': tid, 'email': 'first@wat.test'}, '/admin/temple_accounts.php')
    t = Client(base)
    c, loc, b = t.post('/login.php', {'username': u, 'password': p})
    check('F2 มีอีเมลแล้ว → ต้องยืนยัน 2FA ก่อน', c == 302 and loc == 'login_verify.php')
    code6 = smtp.last_code()
    c, loc, _ = t.req('/account.php')
    check('F2b ระหว่างรอ 2FA ยังเข้าหน้าอื่นไม่ได้', c == 302 and 'login.php' in (loc or ''))
    c, loc, b = t.post('/login_verify.php', {'action': 'verify', 'code': code6})
    check('F3 ยืนยันอีเมลผ่าน → สร้าง session', c == 302)
    c, loc, _ = t.req('/index.php')
    check('F3b หน้าแรกถูกเด้งไป account.php (ต้องเปลี่ยนรหัสก่อน)', c == 302 and loc == 'account.php', f'{c} {loc}')
    for path in ['/dashboard.php', '/transactions.php?type=income', '/reports.php', '/reports.php?export=csv', '/temples.php', '/admin/index.php',
                 '/admin/temple_accounts.php?download=email_template', '/login_verify.php']:
        c, loc, _ = t.req(path)
        # ทุกหน้า (รวมหน้าใน admin/ ที่เส้นทางเป็น ../account.php) ต้องถูกเด้งไปหน้าเปลี่ยนรหัสผ่านทันที
        check(f'F4 {path} ถูกเด้งไปหน้าเปลี่ยนรหัสผ่าน', c == 302 and (loc or '').endswith('account.php'), f'{c} {loc}')
    c, _, b = t.req('/account.php')
    check('F4b หน้า account.php เปิดได้ (หน้าเปลี่ยนรหัสผ่าน)', c == 200)
    check('F5 โหมดบังคับ: มีข้อความอธิบาย ไม่มีเมนูอื่น ไม่มีกล่องข้อมูลบัญชี',
          'ตั้งรหัสผ่านใหม่ก่อนเริ่มใช้งาน' in b and 'transactions.php?type=income' not in b and 'ออกจากระบบ' in b and 'ข้อมูลบัญชี' not in b)

    def chg(cur, new, conf=None):
        t.post('/account.php', {'current_password': cur, 'new_password': new, 'confirm_password': new if conf is None else conf}, '/account.php')
        return notice(t.req('/account.php')[2])

    for label, args, msg in [
        ('รหัสเดิมผิด', ('wrongpass', 'NewPassw0rd!x'), 'รหัสผ่านเดิมไม่ถูกต้อง'),
        ('สั้นเกินไป', (p, 'abc12'), 'อย่างน้อย 8'),
        ('ยืนยันไม่ตรง', (p, 'NewPassw0rd!x', 'Other12345'), 'ไม่ตรงกัน'),
        ('ซ้ำกับรหัสเริ่มต้น', (p, p), 'ไม่ซ้ำกับรหัสเดิม'),
        ('เหมือนชื่อผู้ใช้', (p, u), 'ไม่เหมือนชื่อผู้ใช้'),
    ]:
        n_ = chg(*args)
        check(f'F6 เปลี่ยนรหัสไม่ผ่าน: {label}', msg in n_ and q(f"SELECT must_change_password FROM users WHERE username='{u}'") == '1', n_)
    new_pw = 'MyOwnPassw0rd#1'
    c, loc, b = t.post('/account.php', {'current_password': p, 'new_password': new_pw, 'confirm_password': new_pw}, '/account.php')
    check('F7 ตั้งรหัสผ่านใหม่สำเร็จ → ไปหน้าใส่โลโก้วัดต่อ (ยังไม่มีโลโก้)', c == 302 and loc == 'profile.php#logo', f'{c} {loc}')
    check('F7b ธงบังคับถูกล้างในฐานข้อมูล', q(f"SELECT must_change_password FROM users WHERE username='{u}'") == '0')
    for path in ['/index.php', '/transactions.php?type=income', '/reports.php']:
        check(f'F8 หลังเปลี่ยนรหัส {path} ใช้งานได้', t.req(path)[0] == 200)
    t.req('/logout.php')
    c, loc, b = Client(base).post('/login.php', {'username': u, 'password': p})
    check('F9 รหัสเริ่มต้นใช้ไม่ได้อีกแล้ว', c == 200 and 'ไม่ถูกต้อง' in b)
    uid = q(f"SELECT id FROM users WHERE username='{u}'")
    age_otps(q, f'user_id={uid}')
    t3 = Client(base)
    c, loc, b = t3.post('/login.php', {'username': u, 'password': new_pw})
    check('F10 รหัสใหม่ใช้ได้ (ยังต้อง 2FA)', c == 302 and loc == 'login_verify.php')
    t3.post('/login_verify.php', {'action': 'verify', 'code': smtp.last_code()})
    check('F11 เข้าสู่ระบบครั้งถัดไป ไม่ถูกบังคับเปลี่ยนอีก',
          t3.req('/index.php')[0] == 200 and t3.req('/transactions.php?type=income')[0] == 200)

    # แอดมินหลักรีเซ็ตรหัสผ่านให้ → บังคับเปลี่ยนอีกครั้ง
    adm.post('/admin/temple_accounts.php', {'action': 'reset_password', 'user_id': uid}, '/admin/temple_accounts.php')
    c, _, b = adm.req('/admin/temple_accounts.php')
    pw2 = re.search(r'คือ\s+(\S+)\s+—', b).group(1)
    check('F12 แอดมินหลักรีเซ็ตรหัสผ่าน → ตั้งธงบังคับเปลี่ยนอีกครั้ง',
          q(f"SELECT must_change_password FROM users WHERE id={uid}") == '1' and 'ตั้งรหัสผ่านใหม่เองตอนเข้าสู่ระบบครั้งแรก' in b)
    age_otps(q, f'user_id={uid}')
    t4 = Client(base)
    t4.post('/login.php', {'username': u, 'password': pw2})
    t4.post('/login_verify.php', {'action': 'verify', 'code': smtp.last_code()})
    c, loc, _ = t4.req('/transactions.php?type=income')
    check('F13 หลังรีเซ็ต → ถูกบังคับเปลี่ยนรหัสอีกครั้ง', c == 302 and loc == 'account.php')
    c, loc, _ = t4.req('/logout.php')
    c2, loc2, _ = t4.req('/account.php')
    check('F13b ระหว่างถูกบังคับ ยังออกจากระบบได้', c == 302 and c2 == 302 and 'login.php' in (loc2 or ''))

    # วัดใหม่หลังติดตั้ง / บทบาทอื่น
    adm.post('/admin/temples.php', {'action': 'save', 'id': '0', 'district_id': q("SELECT id FROM districts WHERE name='สอง'"),
                                    'name': 'วัดใหม่หลังติดตั้ง', 'subdistrict': 'ทดสอบ'}, '/admin/temples.php')
    check('F14 วัดใหม่หลังติดตั้ง → ได้บัญชี + ต้องเปลี่ยนรหัส',
          q("SELECT u.must_change_password FROM users u JOIN temples t ON t.id=u.temple_id WHERE t.name='วัดใหม่หลังติดตั้ง'") == '1')
    q("INSERT INTO users (username,password_hash,full_name,role,district_id) "
      f"SELECT 'dist1','{hash_password(app, 'DistPass12345')}','เจ้าหน้าที่','district',id FROM districts WHERE name='เด่นชัย'")
    d = Client(base)
    c, loc, _ = d.post('/login.php', {'username': 'dist1', 'password': 'DistPass12345'})
    check('F15 เจ้าหน้าที่อำเภอไม่ถูกบังคับเปลี่ยนรหัส', loc == 'index.php' and d.req('/transactions.php?type=income')[0] == 200)


def _provinces(db, R):
    """ติดตั้งสำหรับจังหวัดอื่น: เลือกจังหวัด + อัปโหลดไฟล์ Excel ทะเบียนวัด / นำเข้าภายหลัง"""
    from test_import import HEAD, make_xlsx, trow
    check = R.check

    def temple(i, district, code, province='น่าน'):
        return trow(**{'ลำดับ': i, 'รหัสวัด': code, 'ชื่อวัด': f'วัดทดสอบ{i}', 'แขวง/ตำบล': 'ในเวียง', 'เขต/อำเภอ': district, 'จังหวัด': province, 'ประเภทวัด': 'วัดราษฎร์'})
    rows = [HEAD] + [temple(1, 'เมืองน่าน', '05550101001'), temple(2, 'เมืองน่าน', '05550101002'), temple(3, 'ปัว', '05550201001'), temple(4, 'ท่าวังผา', '05550301001')]
    xlsx = make_xlsx(rows)

    def start(app):
        """ผ่านขั้น 1–2 แล้วคืน (client, ชื่อฐานข้อมูล)"""
        name = db.new_database('prov', create=False)
        w = Client(app.serve())
        w.post('/install.php', {'action': 'requirements'}, '/install.php?step=1')
        w.post('/install.php', {'action': 'db', 'db_host': db.host, 'db_port': db.port, 'db_name': name, 'db_user': db.user, 'db_pass': db.password}, '/install.php?step=2')
        return w, name

    def finish(w):
        w.post('/install.php', {'action': 'mail_save', 'mail_driver': 'log', 'smtp_port': '587', 'smtp_secure': 'none'}, '/install.php?step=4')
        return w.post('/install.php', {'action': 'install'}, '/install.php?step=5')

    def site(province, **kw):
        d = {'action': 'site', 'province': province, 'site_name': 'ระบบวัด', 'site_sub': 'สำหรับคณะสงฆ์จังหวัด' + province, 'admin_user': 'mainadmin',
             'admin_name': 'แอดมิน หลัก', 'admin_pass': ADMIN_PW, 'admin_pass2': ADMIN_PW, 'temple_source': 'later', 'make_accounts': '1'}
        d.update(kw)
        return d

    # ---- น่าน + ไฟล์ Excel
    app = App()
    try:
        w, name = start(app)
        q = db.q(name)
        w.upload('/install.php', site('น่าน', temple_source='builtin'), None, b'', '/install.php?step=3')
        c, _, b = w.req('/install.php?step=3')
        check('P1 ทะเบียนที่มากับชุดใช้ได้เฉพาะแพร่ → จังหวัดอื่นถูกปฏิเสธ', 'เป็นของจังหวัดแพร่เท่านั้น' in notice(b), notice(b))
        w.upload('/install.php', site('น่าน', temple_source='file'), None, b'', '/install.php?step=3')
        c, _, b = w.req('/install.php?step=3')
        check('P2 เลือกอัปโหลดไฟล์แต่ไม่แนบ → แจ้งให้เลือกไฟล์', 'กรุณาเลือกไฟล์ทะเบียนวัด' in notice(b), notice(b))
        w.upload('/install.php', site('แพร่', temple_source='file'), 'nan.xlsx', xlsx, '/install.php?step=3', field='temple_file')
        c, _, b = w.req('/install.php?step=3')
        check('P3 ไฟล์ของน่านแต่เลือกจังหวัดแพร่ → แจ้งไม่ตรงกัน', 'ไม่ตรงกับ' in notice(b) or 'แต่เลือกจังหวัดแพร่' in notice(b), notice(b))
        w.upload('/install.php', site('น่าน', temple_source='file'), 'bad.xlsx', b'not a zip', '/install.php?step=3', field='temple_file')
        c, _, b = w.req('/install.php?step=3')
        check('P3b ไฟล์ .xlsx เสีย → แจ้งข้อผิดพลาด ไม่ล้ม', 'ไม่ใช่ไฟล์ Excel' in notice(b) or 'อ่านไฟล์' in notice(b), notice(b))
        c, loc, _ = w.upload('/install.php', site('น่าน', temple_source='file', make_staff='1'), 'nan.xlsx', xlsx, '/install.php?step=3', field='temple_file')
        check('P4 ไฟล์น่าน + จังหวัดน่าน → ผ่านไปขั้น 4', c == 302 and loc == 'install.php?step=4', f'{c} {loc}')
        staged = [f for f in os.listdir(os.path.join(app.dir, 'storage')) if f.startswith('install_temples_')]
        check('P4b ไฟล์ที่อัปโหลดถูกเก็บชั่วคราวใน storage/ (1 ไฟล์)', len(staged) == 1, str(staged))
        w.post('/install.php', {'action': 'mail_save', 'mail_driver': 'log', 'smtp_port': '587', 'smtp_secure': 'none'}, '/install.php?step=4')
        c, _, b = w.req('/install.php?step=5')
        check('P5 หน้าสรุปแสดงจังหวัดน่านและไฟล์ที่นำเข้า', 'น่าน' in b and 'nan.xlsx' in b and '4 วัด 3 อำเภอ' in b)
        c, loc, _ = w.post('/install.php', {'action': 'install'}, '/install.php?step=5')
        check('P6 ติดตั้งสำเร็จ', c == 302 and loc == 'install.php?step=done', f'{c} {loc}')
        c, _, b = w.req('/install.php?step=done')
        check('P6b หน้าเสร็จสิ้นแสดงจังหวัดน่าน 3 อำเภอ + เตือนเปลี่ยนตราระบบ', 'น่าน' in b and '3 อำเภอ' in b and 'assets/img/logo.svg' in b)
        check('P6c หน้าเสร็จสิ้นมีปุ่มดาวน์โหลดรหัสผ่านผู้ใช้จังหวัด/อำเภอ/ตำบล', 'download=staff' in b and '7 บัญชี' in b)
        c, _, sb = w.req('/install.php?download=staff')
        c2, _, _ = w.req('/install.php?download=staff')
        check('P6d ดาวน์โหลด CSV ผู้ใช้จังหวัด/อำเภอ/ตำบล ได้ครั้งเดียว (7 บัญชี)', c == 200 and sb.count('\n') == 8 and 'เจ้าคณะจังหวัดน่าน' in sb and 'province' in sb and 'tambon' in sb and 'amphoe' in sb and 'download=staff' not in w.req('/install.php?step=done')[2], str(sb[:150]))
        check('P6e สร้างผู้ใช้เจ้าคณะจังหวัด 1 · แอดมินอำเภอ 3 · เจ้าคณะตำบล 3 (ตามไฟล์) และบังคับเปลี่ยนรหัส',
              q("SELECT COUNT(*) FROM users WHERE role='province'") == '1' and q("SELECT COUNT(*) FROM users WHERE role='district'") == '3'
              and q("SELECT COUNT(*) FROM users WHERE role='subdistrict'") == '3' and q("SELECT COUNT(*) FROM users WHERE role IN ('province','district','subdistrict') AND must_change_password=0") == '0')
        check('P7 ตั้งชื่อจังหวัดในตาราง settings', q("SELECT v FROM settings WHERE k='province_name'") == 'น่าน')
        check('P8 อำเภอมีเฉพาะของน่าน ไม่มีอำเภอตัวอย่างของแพร่',
              sorted(q("SELECT name FROM districts ORDER BY name").split('\n')) == sorted(['เมืองน่าน', 'ปัว', 'ท่าวังผา']), q("SELECT GROUP_CONCAT(name) FROM districts"))
        check('P9 นำเข้าวัด 4 วัด + รหัสอำเภอจากรหัสวัด', q("SELECT COUNT(*) FROM temples") == '4' and q("SELECT code FROM districts WHERE name='ปัว'") == '5502')
        check('P10 สร้างบัญชีแอดมินวัด 4 บัญชี', q("SELECT COUNT(*) FROM users WHERE role='temple'") == '4')
        check('P11 ไฟล์ชั่วคราวถูกลบหลังติดตั้ง', not [f for f in os.listdir(os.path.join(app.dir, 'storage')) if f.startswith('install_temples_')])
        c, _, b = Client(app.serve()).req('/index.php')
        check('P12 หน้าแรกแสดงข้อความของจังหวัดน่าน', c == 200 and 'จังหวัดน่าน' in b and 'จังหวัดแพร่' not in b, b[:0])
        check('P12b ค่า province_name ถูกเขียนใน config.local.php', "'province_name' => 'น่าน'" in open(os.path.join(app.dir, 'config.local.php'), encoding='utf8').read())
    finally:
        problems = app.php_problems()
        check('P13 ไม่มี PHP warning ในการติดตั้งจังหวัดอื่น', not problems, '\n'.join(problems[:3]))
        app.close()

    # ---- เชียงใหม่ + นำเข้าภายหลัง
    app = App()
    try:
        w, name = start(app)
        q = db.q(name)
        c, loc, _ = w.upload('/install.php', site('เชียงใหม่'), None, b'', '/install.php?step=3')
        check('Q1 เลือกนำเข้าภายหลัง → ผ่านขั้น 3', c == 302 and loc == 'install.php?step=4', f'{c} {loc}')
        c, loc, _ = finish(w)
        check('Q2 ติดตั้งสำเร็จ', c == 302 and loc == 'install.php?step=done', f'{c} {loc}')
        c, _, b = w.req('/install.php?step=done')
        check('Q3 หน้าเสร็จสิ้นบอกให้นำเข้าทะเบียนวัดภายหลัง', 'นำเข้าทะเบียนวัด' in b and 'เชียงใหม่' in b)
        check('Q3b ไม่ได้ติ๊กสร้างผู้ใช้จังหวัด/อำเภอ/ตำบล → ไม่สร้าง', q("SELECT COUNT(*) FROM users WHERE role IN ('province','district','subdistrict')") == '0')
        check('Q4 ไม่มีอำเภอตัวอย่างของแพร่ และไม่มีวัด', q("SELECT COUNT(*) FROM districts") == '0' and q("SELECT COUNT(*) FROM temples") == '0')
        cl = Client(app.serve())
        check('Q5 หน้าสาธารณะใช้ได้แม้ยังไม่มีอำเภอ', all(cl.req(p)[0] == 200 for p in ['/index.php', '/temples.php', '/login.php']))
        check('Q5b หน้าแรกแสดงจังหวัดเชียงใหม่', 'จังหวัดเชียงใหม่' in cl.req('/index.php')[2])
    finally:
        problems = app.php_problems()
        check('Q6 ไม่มี PHP warning (ติดตั้งแบบนำเข้าภายหลัง)', not problems, '\n'.join(problems[:3]))
        app.close()
