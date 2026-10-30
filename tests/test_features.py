"""ชุดที่ 1: ฟีเจอร์หลัก — ติดตั้งด้วย setup.php (CLI) แล้วทดสอบบัญชีวัด/อีเมล/2FA/ล็อกบัญชี/สิทธิ์

หมายเหตุ: หลังติดตั้ง จะล้างธง must_change_password ของบัญชีวัดทั้งหมดก่อน เพื่อให้ชุดนี้ทดสอบการใช้งานปกติได้ทันที
ส่วน "บังคับเปลี่ยนรหัสผ่านครั้งแรก" ทดสอบในชุดที่ 2 (test_install.py)
"""
import datetime
import re

from support import Client, FakeSMTP, App, age_otps, hash_password, notice, parse_creds_csv


def run(db, R):
    smtp = FakeSMTP()
    name = db.new_database('features')
    q = db.q(name)
    app = App(env={
        'TFMS_DB_HOST': db.host, 'TFMS_DB_PORT': db.port, 'TFMS_DB_USER': db.user, 'TFMS_DB_PASS': db.password,
        'TFMS_DB_NAME': name,
        'TFMS_MAIL_DRIVER': 'smtp', 'TFMS_SMTP_HOST': '127.0.0.1', 'TFMS_SMTP_PORT': str(smtp.port),
        'TFMS_SMTP_SECURE': 'none', 'TFMS_SMTP_USER': 'smtpuser', 'TFMS_SMTP_PASS': 'smtppass',
        'TFMS_MAIL_FROM': 'no-reply@wat.test',
    })
    try:
        _run(db, R, smtp, name, q, app)
    finally:
        problems = app.php_problems()
        R.check('Z1 ไม่มี PHP warning/notice/fatal ใน log ของเซิร์ฟเวอร์', not problems, '\n'.join(problems[:3]))
        app.close()
        smtp.stop()


def _run(db, R, smtp, name, q, app):
    check = R.check

    # ---------- ติดตั้งด้วย CLI ----------
    p = app.php('setup.php', 'Test12345pw')
    check('S1 setup.php ติดตั้งสำเร็จ (381 วัด / 381 บัญชี)',
          p.returncode == 0 and 'นำเข้าทะเบียนวัด 381 วัด' in p.stdout and 'สร้างบัญชีวัดอัตโนมัติ 381 บัญชี' in p.stdout, p.stdout + p.stderr)
    check('S2 สร้างไฟล์ล็อก storage/installed.lock', (app.dir / 'storage' / 'installed.lock').is_file())
    csv_files = sorted((app.dir / 'storage').glob('temple_accounts_*.csv'))
    check('S3 ไฟล์รหัสผ่านเริ่มต้นสิทธิ์ 0600', len(csv_files) == 1 and (csv_files[0].stat().st_mode & 0o777) == 0o600)
    creds = parse_creds_csv(csv_files[0].read_text(encoding='utf-8'))
    check('S4 CSV มี 381 บัญชี', len(creds) == 381)
    check('S5 ฐานข้อมูลกันบัญชีที่สองของวัดเดียวกัน (unique)', _dup_rejected(q, creds[0][0]))
    q("UPDATE users SET must_change_password = 0 WHERE role = 'temple'")

    base = app.serve()

    def cred(i):
        code, _, temple, user, pw = creds[i]
        return user, pw, code, temple

    def tid_of(code):
        return q(f"SELECT id FROM temples WHERE temple_code = '{code}'")

    # ---------- แอดมินหลัก ----------
    adm = Client(base)
    c, loc, _ = adm.post('/login.php', {'username': 'admin', 'password': 'Test12345pw'})
    check('admin login (ไม่ต้อง 2FA)', c == 302 and loc == 'index.php')
    c, _, b = adm.req('/admin/temple_accounts.php')
    check('หน้า บัญชีวัด เปิดได้', c == 200 and 'บัญชีวัด (1 วัด = 1 บัญชี)' in b)
    check('สถิติ: ยังไม่มีอีเมล 381 วัด',
          re.search(r'ยังไม่มีอีเมล \(เข้าสู่ระบบไม่ได้\)</div><div class="text-2xl font-bold text-red-700">381<', b) is not None)

    # ---------- A: วัดที่ยังไม่มีอีเมล เข้าสู่ระบบไม่ได้ ----------
    u, p, code, tname = cred(0)
    t = Client(base)
    c, loc, b = t.post('/login.php', {'username': u, 'password': p})
    check('A1 ไม่มีอีเมล → เข้าไม่ได้ (ไม่ redirect)', c == 200 and 'ยังไม่ได้ลงทะเบียนอีเมล' in b)
    c, loc, _ = t.req('/transactions.php?type=income')
    check('A2 ไม่มี session (เด้งกลับ login)', c == 302 and 'login.php' in loc)
    check('A3 ไม่มีการส่งอีเมล', len(smtp.messages) == 0)
    c, loc, b = t.post('/login.php', {'username': u, 'password': 'wrongpass'})
    check('A4 รหัสผิด → ข้อความทั่วไป (ไม่บอกเรื่องอีเมล)', c == 200 and 'ไม่ถูกต้อง' in b and 'ยังไม่ได้ลงทะเบียนอีเมล' not in b)

    # ---------- B: แอดมินหลักลงอีเมล ----------
    tid = tid_of(code)
    adm.post('/admin/temple_accounts.php', {'action': 'save_email', 'temple_id': tid, 'email': 'not-an-email'}, '/admin/temple_accounts.php')
    check('B1 อีเมลรูปแบบผิดถูกปฏิเสธ', q(f"SELECT IFNULL(email,'') FROM temples WHERE id={tid}") == '')
    adm.post('/admin/temple_accounts.php', {'action': 'save_email', 'temple_id': tid, 'email': '  Wat.Test1@Example.org '}, '/admin/temple_accounts.php')
    check('B2 บันทึกอีเมล (lowercase/trim)', q(f"SELECT email FROM temples WHERE id={tid}") == 'wat.test1@example.org')

    # ---------- C–E: ล็อกอิน 2FA ----------
    t = Client(base)
    c, loc, b = t.post('/login.php', {'username': u, 'password': p})
    check('C1 รหัสผ่านถูก → ไปหน้ายืนยัน', c == 302 and loc == 'login_verify.php')
    c, loc, _ = t.req('/transactions.php?type=income')
    check('C2 ยังไม่ผ่าน 2FA → ยังใช้งานไม่ได้', c == 302)
    m = smtp.messages
    check('C3 ส่งอีเมลถึงอีเมลวัด', len(m) == 1 and 'wat.test1@example.org' in m[0]['rcpt'][0])
    code6 = smtp.last_code()
    check('C4 รหัส 6 หลัก + SMTP AUTH', len(code6) == 6 and m[0]['auth'] == ['smtpuser', 'smtppass'], str(m[0]['auth']))
    check('C5 หัวเรื่อง/UTF-8 ถูกต้อง', 'รหัสยืนยัน' in m[0]['subject'])
    c, _, b = t.req('/login_verify.php')
    check('C6 หน้ายืนยันแสดงอีเมลแบบปิดบัง', 'w***' in b and 'wat.test1@example.org' not in b)
    wrong = '000000' if code6 != '000000' else '111111'
    c, _, b = t.post('/login_verify.php', {'action': 'verify', 'code': wrong})
    check('D1 รหัสผิด → ไม่ผ่าน', c == 200 and 'รหัสไม่ถูกต้อง' in b)
    t.post('/login_verify.php', {'action': 'resend'})
    check('D2 ขอรหัสใหม่ทันที → ถูกจำกัด (cooldown) ไม่ส่งอีเมลเพิ่ม', len(smtp.messages) == 1)
    c, loc, b = t.post('/login_verify.php', {'action': 'verify', 'code': code6})
    check('E1 รหัสถูก → เข้าสู่ระบบ', c == 302 and loc == 'index.php')
    c, _, b = t.req('/transactions.php?type=income')
    check('E2 ใช้งานบัญชีรายรับของวัดได้', c == 200)
    check('E3 เห็นเฉพาะวัดตัวเอง', tname in b and 'วัดพระธาตุช่อแฮ' not in b)
    c, _, b = t.req('/admin/temple_accounts.php')
    check('E4 แอดมินวัดเข้าหน้า admin ไม่ได้ (403)', c == 403)
    c, _, b = t.post('/admin/temple_accounts.php', {'action': 'save_email', 'temple_id': tid, 'email': 'evil@x.org'}, '/account.php')
    check('E5 แอดมินวัดแก้อีเมลเองไม่ได้ (403)', c == 403 and q(f"SELECT email FROM temples WHERE id={tid}") == 'wat.test1@example.org')
    t.post('/account.php', {'username': 'hacker', 'email': 'evil@x.org', 'current_password': 'zzz', 'new_password': 'x', 'confirm_password': 'x'})
    check('E6 ชื่อผู้ใช้/อีเมลไม่เปลี่ยนจากหน้าบัญชี',
          q(f"SELECT username FROM users WHERE temple_id={tid}") == u and q(f"SELECT email FROM temples WHERE id={tid}") == 'wat.test1@example.org')
    c, _, b = t.req('/account.php')
    check('E7 หน้าบัญชีไม่มีช่องแก้ชื่อผู้ใช้/อีเมล', 'name="username"' not in b and 'name="email"' not in b)

    def chg(cur, new, conf=None):
        t.post('/account.php', {'current_password': cur, 'new_password': new, 'confirm_password': new if conf is None else conf}, '/account.php')
        return notice(t.req('/account.php')[2])

    check('E8 เปลี่ยนรหัส: รหัสเดิมผิด → ปฏิเสธ', 'รหัสผ่านเดิมไม่ถูกต้อง' in chg('wrong', 'NewPassw0rd!'))
    check('E8b เปลี่ยนรหัส: สั้นเกินไป → ปฏิเสธ', 'อย่างน้อย 8' in chg(p, 'short'))
    check('E8c เปลี่ยนรหัส: ยืนยันไม่ตรง → ปฏิเสธ', 'ไม่ตรงกัน' in chg(p, 'NewPassw0rd!', 'Mismatch12'))
    chg(p, 'NewPassw0rd!')
    t2 = Client(base)
    c, loc, b = t2.post('/login.php', {'username': u, 'password': p})
    check('E9 รหัสเดิมใช้ไม่ได้หลังเปลี่ยน', c == 200 and 'ไม่ถูกต้อง' in b)
    age_otps(q, f"user_id=(SELECT id FROM users WHERE temple_id={tid})")
    t3 = Client(base)
    c, loc, b = t3.post('/login.php', {'username': u, 'password': 'NewPassw0rd!'})
    check('E10 รหัสใหม่ใช้ได้ (ต้อง 2FA อีกครั้ง)', c == 302 and loc == 'login_verify.php')

    # ---------- F: ใช้รหัสซ้ำ / หมดอายุ ----------
    codeF = smtp.last_code()
    c, loc, b = t3.post('/login_verify.php', {'action': 'verify', 'code': codeF})
    check('F1 รหัสถูก (รอบสอง)', c == 302)
    t4 = Client(base)
    age_otps(q, f"user_id=(SELECT id FROM users WHERE temple_id={tid})")
    t4.post('/login.php', {'username': u, 'password': 'NewPassw0rd!'})
    c, loc, b = t4.post('/login_verify.php', {'action': 'verify', 'code': codeF})
    check('F2 รหัสเก่า (ที่ใช้แล้ว) ใช้ซ้ำไม่ได้', c == 200 and ('ไม่ถูกต้อง' in b or 'หมดอายุ' in b))
    q(f"UPDATE login_otps SET expires_at = NOW() - INTERVAL 1 MINUTE WHERE user_id=(SELECT id FROM users WHERE temple_id={tid}) AND used_at IS NULL")
    c, loc, b = t4.post('/login_verify.php', {'action': 'verify', 'code': smtp.last_code()})
    check('F3 รหัสหมดอายุ → ปฏิเสธ', c == 200 and 'หมดอายุ' in b)

    # ---------- G: จำกัดจำนวนครั้งที่กรอกรหัสผิด ----------
    u2, p2, code2, _ = cred(1)
    adm.post('/admin/temple_accounts.php', {'action': 'save_email', 'temple_id': tid_of(code2), 'email': 'wat2@example.org'}, '/admin/temple_accounts.php')
    g = Client(base)
    g.post('/login.php', {'username': u2, 'password': p2})
    real = smtp.last_code()
    bad = '999999' if real != '999999' else '888888'
    for _ in range(4):
        g.post('/login_verify.php', {'action': 'verify', 'code': bad})
    c, loc, b = g.post('/login_verify.php', {'action': 'verify', 'code': bad})
    check('G1 ผิดครั้งที่ 5 → ล็อกรหัส เด้งกลับ login', c == 302 and 'login.php' in loc)
    c, loc, b = g.req('/login_verify.php')
    check('G2 หลังถูกล็อก หน้ายืนยันใช้ต่อไม่ได้ (ต้องเริ่มล็อกอินใหม่)', c == 302 and 'login.php' in loc)
    c, _, _ = g.req('/transactions.php?type=income')
    check('G3 และยังไม่มี session', c == 302)

    # ---------- H: ล็อกบัญชีเมื่อรหัสผ่านผิดซ้ำ ----------
    u3, p3, code3, _ = cred(2)
    tid3 = tid_of(code3)
    adm.post('/admin/temple_accounts.php', {'action': 'save_email', 'temple_id': tid3, 'email': 'wat3@example.org'}, '/admin/temple_accounts.php')
    for _ in range(5):
        Client(base).post('/login.php', {'username': u3, 'password': 'bad-password'})
    before = len(smtp.messages)
    c, loc, b = Client(base).post('/login.php', {'username': u3, 'password': p3})
    check('H1 รหัสผิด 5 ครั้ง → บัญชีล็อกชั่วคราว แม้รหัสถูก', c == 200 and 'ล็อกชั่วคราว' in b and len(smtp.messages) == before)
    uid3 = q(f"SELECT id FROM users WHERE temple_id={tid3}")
    adm.post('/admin/temple_accounts.php', {'action': 'reset_password', 'user_id': uid3}, '/admin/temple_accounts.php')
    c, _, b = adm.req('/admin/temple_accounts.php')
    mm = re.search(r'คือ\s+(\S+)\s+—', b)
    newpw = mm.group(1) if mm else None
    check('H2 แอดมินรีเซ็ตรหัสผ่าน (แสดงรหัสใหม่ครั้งเดียว)', bool(newpw))
    c, loc, b = Client(base).post('/login.php', {'username': u3, 'password': newpw})
    check('H3 รหัสใหม่ใช้ได้และปลดล็อกแล้ว', c == 302 and loc == 'login_verify.php')

    # ---------- I: เปลี่ยนอีเมล (เฉพาะแอดมินหลัก) ----------
    age_otps(q, f"user_id={uid3}")
    pending = Client(base)
    pending.post('/login.php', {'username': u3, 'password': newpw})
    codeOld = smtp.last_code()
    adm.post('/admin/temple_accounts.php', {'action': 'save_email', 'temple_id': tid3, 'email': 'new4@example.org'}, '/admin/temple_accounts.php')
    c, loc, b = pending.post('/login_verify.php', {'action': 'verify', 'code': codeOld})
    check('I1 เปลี่ยนอีเมล → รหัสที่ส่งไปอีเมลเก่าใช้ไม่ได้', not (c == 302 and loc == 'index.php'))
    age_otps(q, f"user_id={uid3}")
    n = len(smtp.messages)
    Client(base).post('/login.php', {'username': u3, 'password': newpw})
    check('I2 รหัสใหม่ส่งไปอีเมลใหม่', len(smtp.messages) == n + 1 and 'new4@example.org' in smtp.messages[-1]['rcpt'][0])
    adm.post('/admin/temple_accounts.php', {'action': 'save_email', 'temple_id': tid3, 'email': ''}, '/admin/temple_accounts.php')
    c, loc, b = Client(base).post('/login.php', {'username': u3, 'password': newpw})
    check('I3 ลบอีเมล → เข้าสู่ระบบไม่ได้อีก', c == 200 and 'ยังไม่ได้ลงทะเบียนอีเมล' in b)

    # ---------- J: นำเข้าอีเมลจาก CSV ----------
    u4, p4, code4, _ = cred(3)
    bad_code = cred(4)[2]
    data = f'รหัสวัด,อีเมล\n{code4},Imp4@Example.org\n99999999999,x@y.org\n{bad_code},bad-mail\n'.encode('utf-8')
    adm.upload('/admin/temple_accounts.php', {'action': 'import_emails'}, 'e.csv', data, '/admin/temple_accounts.php')
    check('J1 นำเข้าอีเมล CSV (ถูก 1, ข้ามผิด 2)',
          q(f"SELECT email FROM temples WHERE temple_code='{code4}'") == 'imp4@example.org'
          and q(f"SELECT IFNULL(email,'') FROM temples WHERE temple_code='{bad_code}'") == '')
    c, _, b = adm.req('/admin/temple_accounts.php?download=email_template')
    check('J2 แม่แบบอีเมล CSV ดาวน์โหลดได้', c == 200 and 'รหัสวัด' in b and code4 in b)

    # ---------- K: 1 วัด = 1 บัญชี / สร้างอัตโนมัติ ----------
    adm.post('/admin/temple_accounts.php', {'action': 'provision'}, '/admin/temple_accounts.php')
    c, _, b = adm.req('/admin/temple_accounts.php')
    check('K1 สร้างบัญชีซ้ำ → ไม่มีบัญชีเพิ่ม', q("SELECT COUNT(*) FROM users WHERE role='temple'") == '381' and 'ทุกวัดมีบัญชีครบแล้ว' in b)
    district = q("SELECT id FROM districts WHERE name='สอง'")
    adm.post('/admin/temples.php', {'action': 'save', 'id': '0', 'district_id': district, 'name': 'วัดทดสอบใหม่', 'subdistrict': 'ทดสอบ'}, '/admin/temples.php')
    c, _, b = adm.req('/admin/temples.php')
    check('K3 เพิ่มวัดใหม่ → สร้างบัญชีอัตโนมัติ',
          q("SELECT COUNT(*) FROM users u JOIN temples t ON t.id=u.temple_id WHERE t.name='วัดทดสอบใหม่'") == '1' and 'สร้างบัญชีให้อัตโนมัติ' in b)
    newuser = q("SELECT u.username FROM users u JOIN temples t ON t.id=u.temple_id WHERE t.name='วัดทดสอบใหม่'")
    check('K4 ชื่อผู้ใช้วัดใหม่ตามรูปแบบ', newuser.startswith('watm'))
    nid = q("SELECT id FROM temples WHERE name='วัดทดสอบใหม่'")
    adm.post('/admin/temples.php', {'action': 'save', 'id': nid, 'district_id': district, 'name': 'วัดทดสอบเปลี่ยนชื่อ'}, '/admin/temples.php')
    check('K5 เปลี่ยนชื่อวัด → ชื่อผู้ใช้ไม่เปลี่ยน', q(f"SELECT username FROM users WHERE temple_id={nid}") == newuser)
    c, _, b = adm.req('/admin/users.php')
    check('K6 หน้า ผู้ใช้งาน ไม่มีตัวเลือกบทบาทวัด/ไม่แสดงบัญชีวัด', 'temple_id' not in b and 'wat0354' not in b)

    # ---------- L0: เตรียมเจ้าหน้าที่อำเภอเด่นชัย ----------
    q("INSERT INTO users (username,password_hash,full_name,role,district_id) "
      f"SELECT 'dist1','{hash_password(app, 'DistPass12345')}','เจ้าหน้าที่','district',id FROM districts WHERE name='เด่นชัย'")
    d = Client(base)
    c, loc, _ = d.post('/login.php', {'username': 'dist1', 'password': 'DistPass12345'})

    # ---------- M: แดชบอร์ดรายอำเภอ ----------
    today = datetime.date.today()
    be = today.year + 543
    d_denchai = q("SELECT id FROM districts WHERE name='เด่นชัย'")
    d_mueang = q("SELECT id FROM districts WHERE name='เมืองแพร่'")
    temple_dc = q(f"SELECT id FROM temples WHERE district_id={d_denchai} ORDER BY id LIMIT 1")
    own_tid = tid_of(creds[0][0])   # วัดของแอดมินวัดที่ล็อกอินอยู่ (t3) — อยู่ในเมืองแพร่
    inc_cat = q("SELECT id FROM categories WHERE type='income' ORDER BY id LIMIT 1")
    exp_cat = q("SELECT id FROM categories WHERE type='expense' ORDER BY id LIMIT 1")

    def add_txn(kind, temple, amount):
        adm.post(f'/transactions.php?type={kind}', {'action': 'save', 'id': '0', 'temple_id': temple, 'txn_date': today.isoformat(),
                                                    'category_id': inc_cat if kind == 'income' else exp_cat, 'amount': amount,
                                                    'doc_no': '', 'description': 'ทดสอบแดชบอร์ด'}, f'/transactions.php?type={kind}')

    add_txn('income', temple_dc, '1500.50')
    add_txn('expense', temple_dc, '400.25')
    add_txn('income', own_tid, '700.00')
    check('M0 บันทึกรายการทดสอบครบ 3 รายการ', q("SELECT COUNT(*) FROM transactions WHERE description='ทดสอบแดชบอร์ด'") == '3')

    c, _, b = Client(base).req('/dashboard.php')
    check('M1 ผู้เยี่ยมชมที่ยังไม่ล็อกอินเปิดแดชบอร์ดไม่ได้', c == 302)
    c, _, b = adm.req(f'/dashboard.php?district={d_denchai}&year={be}')
    check('M2 แดชบอร์ดอำเภอเด่นชัย: การ์ดสถิติ รายรับ/รายจ่าย/คงเหลือ/จำนวนวัด ถูกต้อง',
          c == 200 and 'แดชบอร์ดอำเภอเด่นชัย' in b and '1,500.50' in b and '400.25' in b and '1,100.25' in b
          and 'ในสังกัดอำเภอเด่นชัย' in b and re.search(r'จำนวนวัดในระบบ</span><span[^>]*>31 ', b) is not None)
    check('M3 แดชบอร์ดอำเภอเด่นชัย ไม่รวมข้อมูลของอำเภออื่น (700.00 ของเมืองแพร่)', '700.00' not in b)
    check('M3b มีกราฟรายเดือน (SVG) และตารางวัดในอำเภอพร้อมสถานะ "ยังไม่มีรายการ"', '<svg viewBox' in b and 'ยังไม่มีรายการ' in b and 'บันทึกบัญชีแล้ว <strong>1</strong> จาก 31 วัด' in b)
    c, _, b = adm.req(f'/dashboard.php?district={d_mueang}&year={be}')
    check('M4 แดชบอร์ดอำเภอเมืองแพร่: เห็นเฉพาะ 700.00 ไม่เห็นยอดของเด่นชัย', '700.00' in b and '1,500.50' not in b and '95 ' in b)
    c, _, b = adm.req(f'/dashboard.php?year={be}')
    check('M5 มุมมองทั้งจังหวัด รวมทุกอำเภอ (2,200.50) + ตารางเปรียบเทียบ 8 อำเภอ',
          'แดชบอร์ดทั้งจังหวัดแพร่' in b and '2,200.50' in b and 'เปรียบเทียบรายอำเภอ' in b and b.count('dashboard.php?district=') >= 8 and 'ในสังกัดจังหวัดแพร่' in b)
    c, _, b = adm.req(f'/dashboard.php?district={d_denchai}&year={be - 1}')
    check('M6 เปลี่ยนปี → ไม่มีรายการของปีนี้', c == 200 and '1,500.50' not in b and 'ยังไม่มีรายการบัญชีในปีที่เลือก' in b)
    c, _, b = adm.req('/dashboard.php?district=9999&year=abc')
    check('M7 อำเภอ/ปีที่ไม่ถูกต้อง → ใช้ค่าเริ่มต้น (ทั้งจังหวัด) ไม่ error', c == 200 and 'แดชบอร์ดทั้งจังหวัดแพร่' in b)
    c, _, b = d.req(f'/dashboard.php?district={d_mueang}&year={be}')
    check('M8 เจ้าหน้าที่อำเภอเด่นชัยขอดูอำเภออื่น → ถูกบังคับให้เห็นเฉพาะอำเภอตนเอง',
          c == 200 and 'แดชบอร์ดอำเภอเด่นชัย' in b and '1,500.50' in b and '700.00' not in b and 'aria-label="เลือกอำเภอ"' not in b)
    c, _, b = t3.req(f'/dashboard.php?district={d_denchai}&year={be}')
    check('M9 แอดมินวัดเห็นเฉพาะข้อมูลวัดของตนเอง (ไม่เห็นอำเภออื่น/วัดอื่นในอำเภอเดียวกัน)',
          c == 200 and 'เฉพาะวัดของท่าน' in b and '700.00' in b and '1,500.50' not in b and 'aria-label="เลือกอำเภอ"' not in b)
    c, _, b = adm.req(f'/index.php?year={be}')
    check('M10 หน้าแรกใช้การ์ดสถิติแบบเดียวกัน และลิงก์อำเภอไปแดชบอร์ด', 'ยอดคงเหลือ' in b and f'dashboard.php?year={be}&amp;district={d_denchai}' in b)

    # ---------- L: บทบาทอื่น และการตรวจว่าติดตั้งแล้ว ----------
    n = len(smtp.messages)
    Client(base).post('/login.php', {'username': 'admin', 'password': 'Test12345pw'})
    check('L1 แอดมินหลักไม่ถูกบังคับ 2FA (ไม่ส่งอีเมล)', len(smtp.messages) == n)
    check('L1b เจ้าหน้าที่อำเภอเข้าด้วยรหัสผ่านอย่างเดียว ไม่ถูกบังคับเปลี่ยนรหัส', loc == 'index.php' and d.req('/transactions.php?type=income')[0] == 200)

    probe = '$c=require "config.php"; require "includes/install_lib.php"; var_dump(is_installed($c));'
    lock = app.dir / 'storage' / 'installed.lock'
    lock.unlink()
    r = app.php('-r', probe)
    check('L2 ระบบที่ติดตั้งแล้วแต่ไม่มีไฟล์ล็อก → ตรวจพบว่าติดตั้งแล้ว และสร้างล็อกให้', 'bool(true)' in r.stdout and lock.is_file(), r.stdout + r.stderr)
    empty = db.new_database('empty')
    lock.unlink()
    r = app.php('-r', probe, extra_env={'TFMS_DB_NAME': empty})
    check('L3 ฐานข้อมูลว่าง + ไม่มีไฟล์ล็อก → ยังไม่ถือว่าติดตั้ง', 'bool(false)' in r.stdout and not lock.exists(), r.stdout + r.stderr)


def _dup_rejected(q, code):
    try:
        q(f"INSERT INTO users (username,password_hash,full_name,role,temple_id) "
          f"SELECT 'dupacct','x','x','temple',id FROM temples WHERE temple_code='{code}'")
    except RuntimeError as e:
        return 'Duplicate' in str(e) or '1062' in str(e)
    return False
