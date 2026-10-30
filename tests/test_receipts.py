"""ชุดที่ 4: อนุโมทนาบัตร (ใบอนุโมทนาบัตรสำหรับรายรับประเภทบริจาค) + ตราคณะสงฆ์จังหวัดแพร่เป็นโลโก้ระบบ"""
from datetime import date, timedelta

from support import App, Client, FakeSMTP, age_otps, be_date, notice, parse_creds_csv

STAFF_PW = 'DistrictPass123'


def run(db, R):
    smtp = FakeSMTP()
    name = db.new_database('receipts')
    q = db.q(name)
    app = App(env={
        'TFMS_DB_HOST': db.host, 'TFMS_DB_PORT': db.port, 'TFMS_DB_USER': db.user, 'TFMS_DB_PASS': db.password, 'TFMS_DB_NAME': name,
        'TFMS_MAIL_DRIVER': 'smtp', 'TFMS_SMTP_HOST': '127.0.0.1', 'TFMS_SMTP_PORT': str(smtp.port), 'TFMS_SMTP_SECURE': 'none',
        'TFMS_SMTP_USER': 'smtpuser', 'TFMS_SMTP_PASS': 'smtppass', 'TFMS_MAIL_FROM': 'no-reply@wat.test',
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
    p = app.php('setup.php', 'Test12345pw')
    creds = parse_creds_csv(sorted((app.dir / 'storage').glob('temple_accounts_*.csv'))[0].read_text(encoding='utf-8'))
    q("UPDATE users SET must_change_password = 0 WHERE role = 'temple'")
    base = app.serve()
    adm = Client(base)
    adm.post('/login.php', {'username': 'admin', 'password': 'Test12345pw'})

    # ---- ตัวเลข -> ตัวอักษร ----
    def baht(v):
        return app.php('-r', f"require 'includes/receipts.php'; echo thai_baht_text('{v}');").stdout
    cases = {'1250.50': 'หนึ่งพันสองร้อยห้าสิบบาทห้าสิบสตางค์', '21': 'ยี่สิบเอ็ดบาทถ้วน', '11': 'สิบเอ็ดบาทถ้วน', '100': 'หนึ่งร้อยบาทถ้วน',
             '1000001': 'หนึ่งล้านหนึ่งบาทถ้วน', '0.75': 'ศูนย์บาทเจ็ดสิบห้าสตางค์', '2500000': 'สองล้านห้าแสนบาทถ้วน'}
    bad = {k: baht(k) for k, v in cases.items() if baht(k) != v}
    check('R1 แปลงจำนวนเงินเป็นตัวอักษรไทยถูกต้อง (เอ็ด/ยี่/ล้าน/สตางค์)', not bad, str(bad))

    th = app.php('-r', "require 'includes/receipts.php'; echo thai_digits_html('<a href=\"x.php?id=12\" data-n=\"3\">ที่ 12/2569 &#039;7&#039; &amp; 0.50</a>');").stdout
    check('R1b แปลงเลขไทยเฉพาะข้อความ: ไม่แตะแอตทริบิวต์/เอนทิตี (&#039;) ของ HTML', th == '<a href="x.php?id=12" data-n="3">ที่ ๑๒/๒๕๖๙ &#039;๗&#039; &amp; ๐.๕๐</a>', th)

    by_dist = {}
    for c in creds:
        by_dist.setdefault(c[1], []).append(c)
    A, B, C = by_dist['เมืองแพร่'][0], by_dist['เมืองแพร่'][1], by_dist['เด่นชัย'][0]
    tid = lambda cr: q(f"SELECT id FROM temples WHERE temple_code='{cr[0]}'")

    def login_temple(cr, email):
        t = tid(cr)
        adm.post('/admin/temple_accounts.php', {'action': 'save_email', 'temple_id': t, 'email': email}, '/admin/temple_accounts.php')
        age_otps(q, f"user_id=(SELECT id FROM users WHERE temple_id={t})")
        cl = Client(base)
        cl.post('/login.php', {'username': cr[3], 'password': cr[4]})
        cl.post('/login_verify.php', {'action': 'verify', 'code': smtp.last_code()})
        return cl, t
    ta, ida = login_temple(A, 'a@wat.test')
    tb, idb = login_temple(B, 'b@wat.test')

    donate = q("SELECT id FROM categories WHERE type='income' AND is_donation=1 LIMIT 1")
    plain = q("SELECT id FROM categories WHERE type='income' AND is_donation=0 LIMIT 1")
    check('C1 หมวดรับบริจาคเริ่มต้นถูกกำหนดจาก seed (3 หมวด)', q("SELECT COUNT(*) FROM categories WHERE is_donation=1") == '3')

    def add_income(cl, temple, cat, amount='1250.50', desc='ทำบุญ'):
        cl.post('/transactions.php?type=income', {'action': 'save', 'id': '0', 'temple_id': temple, **be_date('txn_date', date.today()),
                                                  'category_id': cat, 'amount': amount, 'doc_no': '', 'description': desc}, '/transactions.php?type=income')
        return q(f"SELECT MAX(id) FROM transactions WHERE temple_id={temple}")
    x1 = add_income(ta, ida, donate)
    x2 = add_income(ta, ida, plain, '300')
    xb = add_income(tb, idb, donate, '500')

    c, _, b = ta.req('/transactions.php?type=income')
    check('T1 รายการหมวดบริจาคมีปุ่ม "อนุโมทนาบัตร" แต่หมวดอื่นไม่มี', f'receipt.php?txn={x1}' in b and f'receipt.php?txn={x2}' not in b)

    form = {'action': 'issue', 'txn': x1, 'donor_name': 'นายสมชาย ใจบุญ', 'donor_address': '1 ถ.ยันตรกิจโกศล อ.เมือง จ.แพร่', 'purpose': 'สร้างศาลา',
            'book_no': '1', **be_date('receipt_date', date.today()), 'receiver_name': 'นายไวยา วัจกร', 'receiver_position': 'ไวยาวัจกร'}
    c, loc, b = ta.post('/receipt.php', {**form, 'txn': x2}, f'/receipt.php?txn={x1}')
    check('R2 หมวดที่ไม่ใช่รับบริจาคออกอนุโมทนาบัตรไม่ได้', q("SELECT COUNT(*) FROM donation_receipts") == '0', notice(b))
    c, loc, b = ta.post('/receipt.php', {**form, 'donor_name': ''}, f'/receipt.php?txn={x1}')
    check('R3 ไม่กรอกชื่อผู้บริจาคไม่ได้', q("SELECT COUNT(*) FROM donation_receipts") == '0')
    c, loc, b = ta.post('/receipt.php', {**form, **be_date('receipt_date', date.today() + timedelta(days=3))}, f'/receipt.php?txn={x1}')
    check('R4 วันที่ออกเป็นอนาคตไม่ได้', q("SELECT COUNT(*) FROM donation_receipts") == '0')
    c, loc, b = ta.post('/receipt.php', form, f'/receipt.php?txn={x1}')
    check('R5 ออกอนุโมทนาบัตรสำเร็จ ได้เลขที่ 1', q("SELECT receipt_no FROM donation_receipts") == '1' and loc and 'receipt.php?id=' in loc, notice(b))
    rid = q("SELECT id FROM donation_receipts")
    c, _, b = ta.req(f'/receipt.php?id={rid}')
    year = date.today().year + 543
    check('R6 หน้าอนุโมทนาบัตรแสดงชื่อ จำนวนเงิน ตัวอักษร เลขที่ และช่องลงนาม',
          c == 200 and 'นายสมชาย ใจบุญ' in b and '๑,๒๕๐.๕๐' in b and 'หนึ่งพันสองร้อยห้าสิบบาทห้าสิบสตางค์' in b and f'เลขที่ 1/{year}' in b and 'ตราประทับ' in b and 'ไวยาวัจกร' in b, b[:200])
    check('R6b อนุโมทนาบัตรใช้ฟอนต์ Charmonman จาก Google Fonts (โหลดเฉพาะหน้านี้) และฟอนต์ระบบสำรองเป็น Sarabun', 'family=Charmonman' in b and 'receipt-font' in b and 'family=Charmonman' not in ta.req('/receipts.php')[2])
    c, loc, b = ta.post('/receipt.php', form, f'/receipt.php?txn={x1}')
    check('R7 รายการเดียวออกซ้ำไม่ได้', q("SELECT COUNT(*) FROM donation_receipts") == '1')
    c, loc, _ = ta.req(f'/receipt.php?txn={x1}')
    check('R7b เปิดฟอร์มของรายการที่ออกแล้วพาไปดูใบเดิม', loc and f'id={rid}' in loc)

    # ---- ผูกกับรายการบัญชี ----
    c, _, b = ta.post('/transactions.php?type=income', {'action': 'delete', 'id': x1}, '/transactions.php?type=income')
    check('T2 ลบรายการที่ออกอนุโมทนาบัตรแล้วไม่ได้', q(f"SELECT is_deleted FROM transactions WHERE id={x1}") == '0')
    ta.post('/transactions.php?type=income', {'action': 'save', 'id': x1, 'temple_id': ida, **be_date('txn_date', date.today()), 'category_id': donate,
                                              'amount': '9999', 'doc_no': '', 'description': 'x'}, f'/transactions.php?type=income&edit={x1}')
    check('T3 แก้จำนวนเงินรายการที่ออกอนุโมทนาบัตรแล้วไม่ได้', q(f"SELECT amount FROM transactions WHERE id={x1}") == '1250.50')

    # ---- สิทธิ์ ----
    c, _, _ = tb.req(f'/receipt.php?id={rid}')
    check('P1 แอดมินวัดอื่นเปิดใบของวัดนี้ไม่ได้ (404)', c == 404)
    c, _, _ = tb.req(f'/receipt.php?txn={x1}')
    check('P1b แอดมินวัดอื่นเปิดฟอร์มออกใบของรายการวัดนี้ไม่ได้', c == 404)
    c, loc, _ = tb.post('/receipt.php', {**form, 'txn': x1}, f'/receipt.php?txn={xb}')
    check('P2 แอดมินวัดอื่นส่งฟอร์มตรง ๆ ออกใบแทนวัดนี้ไม่ได้ (403)', c == 403)
    c, _, b = tb.req('/receipts.php')
    check('P3 หน้ารายการของแอดมินวัด B ไม่เห็นใบของวัด A', 'นายสมชาย' not in b)

    # แอดมินอำเภอ: ดูได้อย่างเดียว
    did = q(f"SELECT district_id FROM temples WHERE id={ida}")
    other = q(f"SELECT id FROM districts WHERE id<>{did} ORDER BY id LIMIT 1")
    adm.post('/admin/users.php', {'action': 'create', 'slot': f'district:{did}', 'username': 'dist_a', 'full_name': 'อำเภอ A', 'password': STAFF_PW}, '/admin/users.php')
    adm.post('/admin/users.php', {'action': 'create', 'slot': f'district:{other}', 'username': 'dist_o', 'full_name': 'อำเภอ O', 'password': STAFF_PW}, '/admin/users.php')
    da, do = Client(base), Client(base)
    da.post('/login.php', {'username': 'dist_a', 'password': STAFF_PW})
    do.post('/login.php', {'username': 'dist_o', 'password': STAFF_PW})
    check('P4 แอดมินอำเภอของวัดนี้เปิดดูใบได้', da.req(f'/receipt.php?id={rid}')[0] == 200)
    c, _, b = da.req(f'/receipt.php?id={rid}')
    check('P4b แต่ไม่มีปุ่มยกเลิกใบ', 'name="action" value="void"' not in b)
    c, _, _ = da.post('/receipt.php', {'action': 'void', 'id': rid, 'reason': 'x'}, '/account.php')
    check('P4c และยกเลิกผ่านการส่งฟอร์มตรง ๆ ไม่ได้ (403)', c == 403 and q("SELECT status FROM donation_receipts") == 'issued')
    check('P5 แอดมินอำเภออื่นเปิดใบไม่ได้', do.req(f'/receipt.php?id={rid}')[0] == 404)
    check('P6 ไม่ล็อกอินเปิดใบไม่ได้', Client(base).req(f'/receipt.php?id={rid}')[1] is not None)

    # ---- เลขที่ต่อเนื่อง / ยกเลิก ----
    x3 = add_income(ta, ida, donate, '100')
    ta.post('/receipt.php', {**form, 'txn': x3, 'donor_name': '<script>alert(1)</script>'}, f'/receipt.php?txn={x3}')
    check('N1 ใบที่สองได้เลขที่ 2', q(f"SELECT receipt_no FROM donation_receipts WHERE transaction_id={x3}") == '2')
    r3 = q(f"SELECT id FROM donation_receipts WHERE transaction_id={x3}")
    c, _, b = ta.req(f'/receipt.php?id={r3}')
    check('N2 ชื่อผู้บริจาคถูก escape (กัน XSS)', '<script>alert(1)</script>' not in b and '&lt;script&gt;' in b)
    ta.post('/receipt.php', {'action': 'void', 'id': rid, 'reason': ''}, f'/receipt.php?id={rid}')
    check('N3 ยกเลิกโดยไม่ระบุเหตุผลไม่ได้', q("SELECT status FROM donation_receipts WHERE id=" + rid) == 'issued')
    ta.post('/receipt.php', {'action': 'void', 'id': rid, 'reason': 'พิมพ์ชื่อผิด'}, f'/receipt.php?id={rid}')
    check('N4 ยกเลิกสำเร็จพร้อมเหตุผล', q("SELECT status FROM donation_receipts WHERE id=" + rid) == 'void')
    c, _, b = ta.req(f'/receipt.php?id={rid}')
    check('N5 ใบที่ยกเลิกแสดง "ยกเลิก" และเหตุผล', 'พิมพ์ชื่อผิด' in b and 'ยกเลิกแล้ว' in b)
    ta.post('/receipt.php', {**form, 'donor_name': 'นายสมชาย ใจบุญ (แก้ชื่อ)'}, f'/receipt.php?txn={x1}')
    check('N6 ออกใหม่หลังยกเลิกได้ และเลขที่ไม่ถูกนำกลับมาใช้ (ได้ 3)', q(f"SELECT MAX(receipt_no) FROM donation_receipts WHERE transaction_id={x1}") == '3')
    ta.post('/transactions.php?type=income', {'action': 'delete', 'id': x2}, '/transactions.php?type=income')
    check('T4 รายการที่ไม่มีใบ (หมวดทั่วไป) ลบได้ตามปกติ', q(f"SELECT is_deleted FROM transactions WHERE id={x2}") == '1')

    # ---- ตั้งค่า ----
    ta.post('/profile.php', {'action': 'save_profile', 'phone': '', 'tax_id': '12345'}, '/profile.php')
    check('S1 เลขผู้เสียภาษีไม่ครบ 13 หลักถูกปฏิเสธ', q(f"SELECT COALESCE(tax_id,'') FROM temples WHERE id={ida}") == '')
    ta.post('/profile.php', {'action': 'save_profile', 'phone': '', 'tax_id': '0-9940-00123-45-6'}, '/profile.php')
    c, _, b = ta.req(f'/receipt.php?id={r3}')
    check('S2 บันทึกเลขผู้เสียภาษีแล้วแสดงในใบ (เลขไทย)', '๐๙๙๔๐๐๐๑๒๓๔๕๖' in b)
    adm.post('/admin/categories.php', {'action': 'toggle_donation', 'id': plain}, '/admin/categories.php')
    check('S3 แอดมินหลักกำหนดหมวดเป็นหมวดรับบริจาคได้', q(f"SELECT is_donation FROM categories WHERE id={plain}") == '1')
    ta.post('/profile.php', {'action': 'x'}, '/profile.php')

    # ---- ตราคณะสงฆ์จังหวัดแพร่ ----
    _, _, home = Client(base).req('/login.php')
    c, hdr, svg = Client(base).raw('/assets/img/logo.svg')
    check('L1 โลโก้ระบบเป็นตราคณะสงฆ์จังหวัดแพร่ (SVG ไม่มีสคริปต์) และใช้เป็นไอคอนเว็บ',
          c == 200 and b'<svg' in svg and b'<script' not in svg.lower() and len(svg) > 100000 and 'rel="icon"' in home)
    _, _, b = ta.req('/receipts.php')
    check('L2 หน้ารายการอนุโมทนาบัตรเปิดได้และเห็นใบของวัดตน', 'นายสมชาย' in b or 'แก้ชื่อ' in b)
