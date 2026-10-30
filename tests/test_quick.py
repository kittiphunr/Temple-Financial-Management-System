"""ชุดที่ 23: บันทึกรายการด่วนบนมือถือ (quick.php) — ฟอร์มสั้นที่บันทึกผ่านตัวเดียวกับหน้าบัญชี และกลับมาหน้าเดิมหลังบันทึก"""
import datetime
import re

from support import App, Client, FakeSMTP, hash_password, notice

PW = 'Wat12345pw!'


def run(db, R):
    smtp = FakeSMTP()
    name = db.new_database('quick')
    q = db.q(name)
    app = App(env={'TFMS_DB_HOST': db.host, 'TFMS_DB_PORT': db.port, 'TFMS_DB_USER': db.user, 'TFMS_DB_PASS': db.password, 'TFMS_DB_NAME': name,
                   'TFMS_MAIL_DRIVER': 'smtp', 'TFMS_SMTP_HOST': '127.0.0.1', 'TFMS_SMTP_PORT': str(smtp.port), 'TFMS_SMTP_SECURE': 'none',
                   'TFMS_SMTP_USER': 'smtpuser', 'TFMS_SMTP_PASS': 'smtppass', 'TFMS_MAIL_FROM': 'no-reply@wat.test'})
    try:
        _run(R, q, app, smtp)
    finally:
        problems = app.php_problems()
        R.check('Z1 ไม่มี PHP warning/notice/fatal ใน log ของเซิร์ฟเวอร์', not problems, '\n'.join(problems[:3]))
        app.close()
        smtp.stop()


def _run(R, q, app, smtp):
    check = R.check
    app.php('setup.php', 'Test12345pw')
    base = app.serve()
    tA, tB = q("SELECT id FROM temples ORDER BY id LIMIT 1"), q("SELECT id FROM temples ORDER BY id LIMIT 1 OFFSET 1")
    dA = q(f"SELECT district_id FROM temples WHERE id={tA}")
    cin = q("SELECT id FROM categories WHERE type='income' AND is_transfer=0 ORDER BY id LIMIT 1")
    cex = q("SELECT id FROM categories WHERE type='expense' AND is_transfer=0 ORDER BY id LIMIT 1")
    h = hash_password(app, PW)
    q(f"UPDATE users SET password_hash='{h}', must_change_password=0 WHERE role IN ('temple','province','district')")
    for t, em in ((tA, 'tA@example.org'), (tB, 'tB@example.org')):
        q(f"UPDATE temples SET email='{em}' WHERE id={t}")
    ca = Client(base)
    ca.post('/login.php', {'username': q(f"SELECT username FROM users WHERE temple_id={tA}"), 'password': PW})
    ca.post('/login_verify.php', {'action': 'verify', 'code': smtp.last_code()})
    today = datetime.date.today()

    code, _, pg = ca.req('/quick.php')
    check('Q1 หน้าบันทึกด่วนเปิดได้: มีปุ่มเลือกรายรับ/รายจ่าย ช่องจำนวนเงินแบบตัวเลข (inputmode=decimal) หมวด และปุ่มบันทึก',
          code == 200 and 'name="kind"' in pg and 'inputmode="decimal"' in pg and 'name="category_id"' in pg and 'id="quick-form"' in pg and 'width=device-width' in pg)
    check('Q2 ฟอร์มส่งไปบันทึกที่ transactions.php พร้อม back=quick และใส่วันที่วันนี้ (พ.ศ.) ให้เอง',
          'action="transactions.php?type=income"' in pg and 'name="back" value="quick"' in pg and f'name="txn_date_y" value="{today.year + 543}"' in pg)
    check('Q3 เมนูมีลิงก์ "บันทึกด่วน"', 'href="quick.php"' in pg or 'quick.php' in pg)
    css = Client(base).raw('/assets/css/tailwind.css')[2].decode('utf-8', 'ignore')
    check('Q4 CSS ที่ใช้ (ปุ่มใหญ่ has-[:checked]) ถูกคอมไพล์รวมอยู่แล้ว', 'min-h-14' in css and 'has-' in css and 'text-2xl' in css)

    def post(cl, typ, amount, cat, desc='', temple=None, page='/quick.php', **extra):
        f = {'action': 'save', 'back': 'quick', 'temple_id': temple or tA, 'category_id': cat, 'account_id': 'cash', 'amount': amount, 'description': desc, 'doc_no': '',
             'txn_date_d': today.day, 'txn_date_m': today.month, 'txn_date_y': today.year + 543, **extra}
        return cl.post(f'/transactions.php?type={typ}', f, page)
    code, loc, _ = post(ca, 'income', '1,250.50', cin, 'ใส่ตู้บริจาค')
    check('Q5 บันทึกรายรับ → redirect กลับ quick.php (ไม่ใช่หน้าบัญชี) และบันทึกเป็นวันนี้ในเงินสด',
          code in (302, 303) and loc and loc.endswith('quick.php') and q(f"SELECT CONCAT(type,'|',amount,'|',txn_date,'|',description,'|',account_id) FROM transactions WHERE temple_id={tA}") == f'income|1250.50|{today}|ใส่ตู้บริจาค|{q(f"SELECT id FROM accounts WHERE temple_id={tA} AND cash_flag=1")}')
    _, _, pg = ca.req('/quick.php')
    check('Q6 แสดงข้อความบันทึกสำเร็จ และรายการล่าสุดในหน้าเดียวกัน', 'บันทึกรายรับ' in notice(pg) and 'ใส่ตู้บริจาค' in pg and '+1,250.50' in pg)
    code, loc, _ = post(ca, 'expense', '300', cex, 'ค่าน้ำ')
    _, _, pg = ca.req('/quick.php')
    check('Q7 บันทึกรายจ่ายได้ และรายการล่าสุดแสดงเครื่องหมายลบ', q(f"SELECT COUNT(*) FROM transactions WHERE temple_id={tA} AND type='expense'") == '1' and '−300.00' in pg)

    n = q(f"SELECT COUNT(*) FROM transactions WHERE temple_id={tA}")
    code, loc, _ = post(ca, 'income', 'abc', cin)
    _, _, pg = ca.req('/quick.php')
    check('Q8 จำนวนเงินผิด → กลับ quick.php พร้อมข้อความผิดพลาด ไม่บันทึก', loc and loc.endswith('quick.php') and 'จำนวนเงิน' in notice(pg) and q(f"SELECT COUNT(*) FROM transactions WHERE temple_id={tA}") == n)
    code, loc, _ = post(ca, 'income', '10', cex)
    check('Q9 หมวดไม่ตรงประเภท (รายรับแต่ใช้หมวดรายจ่าย) ถูกปฏิเสธ', q(f"SELECT COUNT(*) FROM transactions WHERE temple_id={tA}") == n)
    code, loc, _ = ca.post('/transactions.php?type=income', {'action': 'save', 'temple_id': tA, 'category_id': cin, 'account_id': 'cash', 'amount': '5', 'description': 'ปกติ', 'doc_no': '', 'txn_date_d': today.day, 'txn_date_m': today.month, 'txn_date_y': today.year + 543}, '/transactions.php?type=income')
    check('Q10 บันทึกจากหน้าบัญชีปกติ (ไม่มี back) ยัง redirect ไปหน้าบัญชีเหมือนเดิม', loc and 'transactions.php?type=income' in loc)

    # บัญชีธนาคาร
    ca.post('/accounts.php', {'action': 'add_bank', 'bank_name': 'กสิกรไทย', 'branch': 'แพร่', 'account_no': '123-4-56789-0', 'name': 'วัด', 'opening_balance': '0', 'opening_date_d': 1, 'opening_date_m': 1, 'opening_date_y': today.year + 541}, '/accounts.php')
    bank = q(f"SELECT id FROM accounts WHERE temple_id={tA} AND kind='bank'")
    _, _, pg = ca.req('/quick.php')
    check('Q13 มีบัญชีธนาคารแล้ว ฟอร์มมีตัวเลือกบัญชี', f'<option value="{bank}">' in pg and 'name="account_id"' in pg)
    post(ca, 'income', '40', cin, 'เข้าธนาคาร', account_id=bank)
    check('Q14 เลือกบัญชีธนาคารจากหน้าด่วนแล้วบันทึกเข้าบัญชีนั้น', q("SELECT account_id FROM transactions WHERE description='เข้าธนาคาร'") == bank)

    # วัดอื่น / ปิดงวด
    code, loc, _ = post(ca, 'income', '99', cin, temple=tB)
    check('Q11 บันทึกให้วัดอื่นจาก quick ไม่ได้', q(f"SELECT COUNT(*) FROM transactions WHERE temple_id={tB}") == '0')
    ym = today - datetime.timedelta(days=75)
    q(f"INSERT INTO monthly_reports (temple_id, report_year, report_month, submitted_at, closed_at) VALUES ({tA}, {ym.year + 543}, {ym.month}, NOW(), NOW())")
    code, loc, _ = ca.post('/transactions.php?type=income', {'action': 'save', 'back': 'quick', 'temple_id': tA, 'category_id': cin, 'account_id': 'cash', 'amount': '7', 'description': 'ย้อนหลัง', 'doc_no': '', 'txn_date_d': ym.day, 'txn_date_m': ym.month, 'txn_date_y': ym.year + 543}, '/quick.php')
    _, _, pg = ca.req('/quick.php')
    check('Q12 เดือนที่ปิดงวดแล้วบันทึกไม่ได้ (ข้อความปิดงวด) และกลับหน้า quick', 'ปิดงวด' in notice(pg) and q("SELECT COUNT(*) FROM transactions WHERE description='ย้อนหลัง'") == '0')

    # สิทธิ์
    pro = q("SELECT username FROM users WHERE role='province' LIMIT 1")
    if pro:
        cp = Client(base)
        cp.post('/login.php', {'username': pro, 'password': PW})
        code, loc, _ = cp.req('/quick.php')
        check('Q15 เจ้าคณะจังหวัดเข้าหน้าบันทึกด่วนไม่ได้ (redirect) และเมนูไม่มี', code in (302, 303) and 'quick.php' not in cp.req('/dashboard.php')[2])
    dist = q(f"SELECT username FROM users WHERE role='district' AND district_id={dA} LIMIT 1")
    if dist:
        cd = Client(base)
        cd.post('/login.php', {'username': dist, 'password': PW})
        _, _, pg = cd.req('/quick.php')
        other = q(f"SELECT id FROM temples WHERE district_id<>{dA} ORDER BY id LIMIT 1")
        _, _, pgo = cd.req(f'/quick.php?temple={other}')
        _, _, pgm = cd.req(f'/quick.php?temple={tA}')
        check('Q16 แอดมินอำเภอ: ต้องเลือกวัดก่อน เลือกวัดในอำเภอตัวเองได้ แต่ขอวัดนอกอำเภอไม่ได้ (ไม่แสดงฟอร์ม)', 'เลือกวัดเพื่อบันทึกรายการ' in pg and 'id="quick-form"' in pgm and 'id="quick-form"' not in pgo)
        post(cd, 'income', '15', cin, 'อำเภอบันทึก', page=f'/quick.php?temple={tA}')
        check('Q17 แอดมินอำเภอบันทึกให้วัดในอำเภอตัวเองได้ และกลับหน้า quick ของวัดนั้น', q("SELECT COUNT(*) FROM transactions WHERE description='อำเภอบันทึก'") == '1')
    code, _, _ = Client(base).req('/quick.php')
    check('Q18 ยังไม่ล็อกอินเข้าหน้าบันทึกด่วนไม่ได้', code in (302, 303))
