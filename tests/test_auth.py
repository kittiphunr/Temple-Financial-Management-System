"""ชุดที่ 5: 2FA ของแอดมินหลัก/อำเภอ/เจ้าคณะจังหวัด และลืมรหัสผ่านทางอีเมล"""
import os
import re
import subprocess

from support import App, Client, FakeSMTP, age_otps, notice, parse_creds_csv

PW = 'Test12345pw'
STAFF_PW = 'DistrictPass123'


def run(db, R):
    smtp = FakeSMTP()
    name = db.new_database('auth')
    q = db.q(name)
    app = App(env={
        'TFMS_DB_HOST': db.host, 'TFMS_DB_PORT': db.port, 'TFMS_DB_USER': db.user, 'TFMS_DB_PASS': db.password, 'TFMS_DB_NAME': name,
        'TFMS_MAIL_DRIVER': 'smtp', 'TFMS_SMTP_HOST': '127.0.0.1', 'TFMS_SMTP_PORT': str(smtp.port), 'TFMS_SMTP_SECURE': 'none',
        'TFMS_SMTP_USER': 'smtpuser', 'TFMS_SMTP_PASS': 'smtppass', 'TFMS_MAIL_FROM': 'no-reply@wat.test', 'TFMS_STAFF_2FA': '1',
    })
    try:
        _run(R, smtp, q, app)
    finally:
        problems = app.php_problems()
        R.check('Z1 ไม่มี PHP warning/notice/fatal ใน log ของเซิร์ฟเวอร์', not problems, '\n'.join(problems[:3]))
        app.close()
        smtp.stop()


def _run(R, smtp, q, app):
    check = R.check
    env = {k: v for k, v in os.environ.items() if not k.startswith('TFMS_')}
    dflt = subprocess.run(['php', '-r', "echo var_export((require 'config.php')['staff_2fa'], true);"], cwd=app.dir, capture_output=True, text=True, env=env).stdout
    off = subprocess.run(['php', '-r', "echo var_export((require 'config.php')['staff_2fa'], true);"], cwd=app.dir, capture_output=True, text=True, env={**env, 'TFMS_STAFF_2FA': '0'}).stdout
    check('C1 ค่าเริ่มต้นเปิด 2FA ให้แอดมิน (ปิดได้ด้วย TFMS_STAFF_2FA=0 เท่านั้น)', dflt == 'true' and off == 'false', dflt + off)

    p = app.php('setup.php', PW)
    creds = parse_creds_csv(sorted((app.dir / 'storage').glob('temple_accounts_*.csv'))[0].read_text(encoding='utf-8'))
    q("UPDATE users SET must_change_password = 0 WHERE role = 'temple'")
    base = app.serve()
    sent = lambda: len(smtp.messages)

    # ---------------- แอดมินหลัก: ลงทะเบียนอีเมลครั้งแรก ----------------
    adm = Client(base)
    c, loc, _ = adm.post('/login.php', {'username': 'admin', 'password': PW})
    check('A1 แอดมินที่ยังไม่มีอีเมลถูกพาไปลงทะเบียนอีเมลก่อน (ยังไม่เข้าระบบ)', loc == 'staff_email.php' and adm.req('/dashboard.php')[1] == 'login.php', str(loc))
    n0 = sent()
    adm.post('/staff_email.php', {'action': 'send', 'email': 'not-an-email'}, '/staff_email.php')
    check('A2 อีเมลรูปแบบผิดไม่ส่งรหัส', sent() == n0)
    adm.post('/staff_email.php', {'action': 'send', 'email': 'boss@wat.test'}, '/staff_email.php')
    check('A3 ส่งรหัสไปที่อีเมลใหม่ที่ระบุ', sent() == n0 + 1 and 'boss@wat.test' in smtp.messages[-1]['to'])
    adm.post('/staff_email.php', {'action': 'verify', 'code': '000000'}, '/staff_email.php')
    check('A4 รหัสผิด: ยังไม่เข้าระบบและยังไม่บันทึกอีเมล', adm.req('/dashboard.php')[1] == 'login.php' and q("SELECT COALESCE(email,'') FROM users WHERE username='admin'") == '')
    c, loc, _ = adm.post('/staff_email.php', {'action': 'verify', 'code': smtp.last_code()}, '/staff_email.php')
    check('A5 รหัสถูก: บันทึกอีเมล เข้าระบบสำเร็จ', loc == 'index.php' and adm.req('/dashboard.php')[0] == 200 and q("SELECT email FROM users WHERE username='admin'") == 'boss@wat.test')

    # ---------------- เข้าสู่ระบบครั้งต่อไปต้องมี 2FA ----------------
    def staff_login(user, pw, fresh=True):
        cl = Client(base)
        age_otps(q, f"user_id=(SELECT id FROM users WHERE username='{user}')")
        c, loc, b = cl.post('/login.php', {'username': user, 'password': pw})
        return cl, loc, b
    a2, loc, _ = staff_login('admin', PW)
    check('B1 รหัสผ่านถูก แต่ต้องยืนยันรหัสอีเมลก่อน (ยังไม่เข้าระบบ)', loc == 'login_verify.php' and a2.req('/dashboard.php')[1] == 'login.php')
    c, _, b = a2.post('/login_verify.php', {'action': 'verify', 'code': '111111'}, '/login_verify.php')
    check('B2 รหัสผิดเข้าไม่ได้', a2.req('/dashboard.php')[1] == 'login.php' and 'ไม่ถูกต้อง' in b)
    c, loc, _ = a2.post('/login_verify.php', {'action': 'verify', 'code': smtp.last_code()}, '/login_verify.php')
    check('B3 รหัสถูกเข้าได้', loc == 'index.php' and a2.req('/admin/users.php')[0] == 200)

    # ---------------- เปลี่ยนอีเมลแอดมิน ----------------
    n0 = sent()
    adm.post('/staff_email.php', {'action': 'send', 'email': 'new@wat.test', 'current_password': 'wrong'}, '/staff_email.php')
    check('E1 เปลี่ยนอีเมลโดยรหัสผ่านผิดไม่ส่งรหัส', sent() == n0)
    adm.post('/staff_email.php', {'action': 'send', 'email': 'new@wat.test', 'current_password': PW}, '/staff_email.php')
    check('E2 รหัสผ่านถูก: ส่งรหัสไปอีเมลใหม่ แต่ยังไม่เปลี่ยนจนกว่ายืนยัน', sent() == n0 + 1 and 'new@wat.test' in smtp.messages[-1]['to'] and q("SELECT email FROM users WHERE username='admin'") == 'boss@wat.test')
    adm.post('/staff_email.php', {'action': 'verify', 'code': smtp.last_code()}, '/staff_email.php')
    check('E3 ยืนยันแล้วอีเมลเปลี่ยน', q("SELECT email FROM users WHERE username='admin'") == 'new@wat.test')

    # ---------------- แอดมินอำเภอ/เจ้าคณะจังหวัด ----------------
    did = q("SELECT id FROM districts ORDER BY sort_order, id LIMIT 1")
    def mk(slot, user, email):
        adm.post('/admin/users.php', {'action': 'create', 'slot': slot, 'username': user, 'full_name': 'ผู้ทดสอบ', 'password': STAFF_PW, 'email': email}, '/admin/users.php')
    mk(f'district:{did}', 'dist1', '')
    check('D1 สร้างแอดมินอำเภอโดยไม่ลงอีเมลไม่ได้ (เมื่อเปิด 2FA)', q("SELECT COUNT(*) FROM users WHERE username='dist1'") == '0')
    mk(f'district:{did}', 'dist1', 'bad-email')
    check('D2 อีเมลรูปแบบผิดถูกปฏิเสธ', q("SELECT COUNT(*) FROM users WHERE username='dist1'") == '0')
    mk(f'district:{did}', 'dist1', 'dist1@wat.test')
    check('D3 สร้างพร้อมอีเมลสำเร็จ', q("SELECT email FROM users WHERE username='dist1'") == 'dist1@wat.test')
    n0 = sent()
    d1, loc, _ = staff_login('dist1', STAFF_PW)
    check('D4 แอดมินอำเภอต้องยืนยันรหัสอีเมลเช่นกัน (รหัสส่งถึงอีเมลของตน)', loc == 'login_verify.php' and sent() == n0 + 1 and 'dist1@wat.test' in smtp.messages[-1]['to'] and d1.req('/dashboard.php')[1] == 'login.php')
    d1.post('/login_verify.php', {'action': 'verify', 'code': smtp.last_code()}, '/login_verify.php')
    check('D5 ยืนยันแล้วเข้าได้ แต่เปลี่ยนอีเมลของแอดมินหลักผ่านหน้าลงทะเบียนไม่ได้ (403)', d1.req('/dashboard.php')[0] == 200 and d1.req('/staff_email.php')[0] == 403)
    q("UPDATE users SET email = NULL WHERE username='dist1'")
    d1b, loc, b = staff_login('dist1', STAFF_PW)
    check('D6 แอดมินอำเภอที่ไม่มีอีเมลเข้าไม่ได้ (บอกให้แจ้งแอดมินหลัก)', loc is None and 'ยังไม่ได้ลงทะเบียนอีเมล' in b and d1b.req('/dashboard.php')[1] == 'login.php')
    adm.post('/admin/users.php', {'action': 'set_email', 'id': q("SELECT id FROM users WHERE username='dist1'"), 'email': 'dist1b@wat.test'}, '/admin/users.php')
    check('D7 แอดมินหลักลงอีเมลให้ได้ แล้วเข้าสู่ระบบได้อีกครั้ง', q("SELECT email FROM users WHERE username='dist1'") == 'dist1b@wat.test' and staff_login('dist1', STAFF_PW)[1] == 'login_verify.php')
    check('D8 แอดมินอำเภอตั้งอีเมลตัวเองผ่านฟอร์มของแอดมินหลักไม่ได้', d1.post('/admin/users.php', {'action': 'set_email', 'id': '1', 'email': 'x@x.test'}, '/account.php')[0] in (302, 403) and q("SELECT email FROM users WHERE username='admin'") == 'new@wat.test')
    mk('province', 'chief1', 'chief@wat.test')
    check('D9 เจ้าคณะจังหวัดสร้างพร้อมอีเมล และต้องยืนยันรหัสด้วย', staff_login('chief1', STAFF_PW)[1] == 'login_verify.php')

    # ---------------- ลืมรหัสผ่าน ----------------
    by = {}
    for cr in creds:
        by.setdefault(cr[1], []).append(cr)
    T = by['เมืองแพร่'][0]
    tid = q(f"SELECT id FROM temples WHERE temple_code='{T[0]}'")
    adm.post('/admin/temple_accounts.php', {'action': 'save_email', 'temple_id': tid, 'email': 'wat@wat.test'}, '/admin/temple_accounts.php')

    def forgot(user, cl=None):
        cl = cl or Client(base)
        n = sent()
        c, loc, b = cl.post('/forgot.php', {'username': user}, '/forgot.php')
        return cl, loc, notice(cl.req('/reset.php')[2]), sent() - n

    def code8():
        return re.search(r'รหัสของคุณคือ: (\d{8})', smtp.messages[-1]['body']).group(1)

    def do_reset(cl, code, pw, confirm=None):
        return cl.post('/reset.php', {'code': code, 'new_password': pw, 'confirm_password': confirm or pw}, '/reset.php')

    c1, loc, msg_unknown, mails = forgot('no_such_user')
    check('F1 ชื่อผู้ใช้ที่ไม่มีอยู่: ตอบเหมือนเดิม (ไปหน้ากรอกรหัส) และไม่ส่งอีเมล', loc == 'reset.php' and mails == 0 and 'ส่งรหัส' in msg_unknown)
    age_otps(q, f"user_id=(SELECT id FROM users WHERE temple_id={tid})")
    c2, loc, msg_real, mails = forgot(T[3])
    check('F2 ผู้ใช้จริงได้ข้อความเหมือนกันทุกตัวอักษร และมีอีเมล 1 ฉบับถึงอีเมลวัด', msg_real == msg_unknown and mails == 1 and 'wat@wat.test' in smtp.messages[-1]['to'])
    code = code8()
    check('F3 รหัสกู้คืนเป็นตัวเลข 8 หลัก', len(code) == 8)
    c, _, b = do_reset(c2, '12345678', 'NewPass9999')
    check('F4 รหัสผิดเปลี่ยนรหัสผ่านไม่ได้', 'ไม่ถูกต้อง' in notice(c2.req('/reset.php')[2]) and Client(base).post('/login.php', {'username': T[3], 'password': T[4]})[1] == 'login_verify.php')
    do_reset(c2, code, 'short')
    check('F5 รหัสผ่านใหม่อ่อน: ปฏิเสธโดยไม่ใช้รหัสยืนยันทิ้ง', 'อย่างน้อย 8' in notice(c2.req('/reset.php')[2]) and q(f"SELECT COUNT(*) FROM login_otps WHERE purpose='reset' AND used_at IS NULL AND user_id=(SELECT id FROM users WHERE temple_id={tid})") == '1')
    do_reset(c2, code, 'GoodPass123', 'Different123')
    check('F6 ยืนยันรหัสผ่านไม่ตรง: ปฏิเสธ', 'ไม่ตรงกัน' in notice(c2.req('/reset.php')[2]))
    n0 = sent()
    c, loc, _ = do_reset(c2, code, 'GoodPass123')
    check('F7 รหัสถูก+รหัสผ่านดี: เปลี่ยนสำเร็จ ส่งอีเมลแจ้งเตือน', loc == 'login.php' and sent() == n0 + 1 and 'ถูกเปลี่ยน' in smtp.messages[-1]['subject'])
    age_otps(q, f"purpose='login' AND user_id=(SELECT id FROM users WHERE temple_id={tid})")
    check('F8 รหัสผ่านเก่าใช้ไม่ได้ รหัสใหม่ใช้ได้ (ผ่านด่านรหัสผ่านแล้วไปรอ 2FA)', 'ไม่ถูกต้อง' in Client(base).post('/login.php', {'username': T[3], 'password': T[4]})[2]
          and Client(base).post('/login.php', {'username': T[3], 'password': 'GoodPass123'})[1] == 'login_verify.php')
    n9 = sent()
    c3 = Client(base)
    c3.post('/forgot.php', {'username': T[3]}, '/forgot.php')
    check('F9 ขอรหัสซ้ำเร็วเกินไป (ภายใน 60 วินาที): ไม่ส่งอีเมลใหม่แต่ตอบเหมือนเดิม', sent() == n9)
    c, loc, _ = do_reset(c2, code, 'AnotherPass123')
    check('F10 รหัสเดิมใช้ซ้ำไม่ได้ (ใช้ได้ครั้งเดียว)', 'ไม่ถูกต้อง' in notice(c2.req('/reset.php')[2]) or loc == 'forgot.php')

    # ล็อกบัญชี + ผิด 5 ครั้ง
    age_otps(q, f"user_id=(SELECT id FROM users WHERE temple_id={tid})")
    q(f"UPDATE users SET locked_until = DATE_ADD(NOW(), INTERVAL 20 MINUTE), failed_logins = 3 WHERE temple_id={tid}")
    c4, _, _, mails = forgot(T[3])
    code2 = code8()
    for i in range(5):
        do_reset(c4, '%08d' % (i + 1), 'GoodPass456')
    c, loc, _ = do_reset(c4, code2, 'GoodPass456')
    check('F11 กรอกรหัสผิดครบ 5 ครั้ง: รหัสถูกล็อก ใช้รหัสที่ถูกต้องก็ไม่ได้', q(f"SELECT locked_until IS NOT NULL FROM users WHERE temple_id={tid}") == '1' and 'GoodPass123' != 'x'
          and Client(base).post('/login.php', {'username': T[3], 'password': 'GoodPass456'})[2].count('ไม่ถูกต้อง') >= 1)
    age_otps(q, f"user_id=(SELECT id FROM users WHERE temple_id={tid})")
    c5, _, _, mails = forgot(T[3])
    do_reset(c5, code8(), 'GoodPass789')
    check('F12 กู้รหัสสำเร็จปลดล็อกบัญชีที่ถูกล็อกไว้', q(f"SELECT locked_until IS NULL AND failed_logins=0 FROM users WHERE temple_id={tid}") == '1')

    # วัดไม่มีอีเมล / ผู้ใช้ปิดใช้งาน / แอดมินอำเภอ
    T2 = by['เมืองแพร่'][1]
    c6, loc, msg, mails = forgot(T2[3])
    check('F13 วัดที่ไม่มีอีเมล: ไม่ส่งอีเมล แต่ตอบเหมือนกัน', mails == 0 and msg == msg_unknown)
    q("UPDATE users SET is_active = 0 WHERE username='dist1'")
    c7, _, msg, mails = forgot('dist1')
    check('F14 ผู้ใช้ที่ปิดใช้งานกู้รหัสไม่ได้ (ไม่ส่งอีเมล)', mails == 0)
    q("UPDATE users SET is_active = 1 WHERE username='dist1'")
    age_otps(q, "user_id=(SELECT id FROM users WHERE username='dist1')")
    c8, _, _, mails = forgot('dist1')
    code3 = code8()
    do_reset(c8, code3, 'weakpassword')
    check('F15 แอดมินอำเภอ: รหัสผ่านใหม่ต้อง 10 ตัวขึ้นไปมีตัวเลข', 'อย่างน้อย 10' in notice(c8.req('/reset.php')[2]))
    do_reset(c8, code3, 'StrongerPass99')
    check('F16 แอดมินอำเภอกู้รหัสผ่านสำเร็จ', staff_login('dist1', 'StrongerPass99')[1] == 'login_verify.php' and 'ไม่ถูกต้อง' in staff_login('dist1', STAFF_PW)[2])
    c, _, _ = Client(base).req('/reset.php')
    check('F17 เปิดหน้ากรอกรหัสโดยไม่ผ่านหน้าลืมรหัสผ่านก่อน: ถูกส่งกลับ', Client(base).req('/reset.php')[1] == 'forgot.php')
    check('F18 หน้าเข้าสู่ระบบมีลิงก์ลืมรหัสผ่าน', 'forgot.php' in Client(base).req('/login.php')[2])
    check('F19 ผู้ที่เข้าสู่ระบบแล้วเปิดหน้าลืมรหัสผ่านถูกพาไปบัญชีของฉัน', adm.req('/forgot.php')[1] == 'account.php')
    audits = q("SELECT GROUP_CONCAT(DISTINCT action) FROM audit_log WHERE action IN ('reset_requested','password_reset','enroll_email','change_email','set_staff_email')")
    check('F20 มีบันทึกการใช้งาน (audit) ครบ และไม่มีรหัสหรืออีเมลเต็มในรายละเอียด', all(a in audits for a in ('reset_requested', 'password_reset', 'enroll_email', 'change_email', 'set_staff_email'))
          and q("SELECT COUNT(*) FROM audit_log WHERE detail LIKE '%@%' AND action IN ('reset_requested','reset_not_sent','password_reset','enroll_email','change_email','set_staff_email','otp_sent')") == '0')
