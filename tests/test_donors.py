"""ชุดที่ 20: ทะเบียนผู้บริจาค (PDPA) — ความยินยอม การเข้ารหัส เชื่อมกับอนุโมทนาบัตร ถอนความยินยอม สิทธิ์ และ migration ที่ยังไม่รัน"""
import re
from datetime import date
from urllib.parse import quote, urlencode

from support import App, Client, FakeSMTP, be_date, hash_password, notice

PW = 'Wat12345pw!'
ADDR = '99 ถ.ยันตรกิจโกศล ต.ในเมือง อ.เมือง จ.แพร่'


def run(db, R):
    smtp = FakeSMTP()
    name = db.new_database('donors')
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
    check('D0 ตารางผู้บริจาคและคอลัมน์ donation_receipts.donor_id ถูกสร้างพร้อมการติดตั้ง',
          q("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name='donors'") == '1'
          and q("SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='donation_receipts' AND column_name='donor_id'") == '1')

    tA, tB = q("SELECT id FROM temples ORDER BY id LIMIT 1"), q("SELECT id FROM temples ORDER BY id LIMIT 1 OFFSET 1")
    cdon = q("SELECT id FROM categories WHERE is_donation=1 AND type='income' ORDER BY id LIMIT 1")
    h = hash_password(app, PW)
    q(f"UPDATE users SET password_hash='{h}', must_change_password=0 WHERE role IN ('temple','province','subdistrict')")
    for t, em in ((tA, 'tA@example.org'), (tB, 'tB@example.org')):
        q(f"UPDATE temples SET email='{em}' WHERE id={t}")
    ca, cb = Client(base), Client(base)
    for c, t in ((ca, tA), (cb, tB)):
        c.post('/login.php', {'username': q(f"SELECT username FROM users WHERE temple_id={t}"), 'password': PW})
        c.post('/login_verify.php', {'action': 'verify', 'code': smtp.last_code()})

    def donor(cl, name='นายใจดี มีทาน', consent=True, address=ADDR, phone='081-234-5678', did='', note='', temple=None):
        d = {'action': 'save', 'donor_id': did, 'name': name, 'address': address, 'phone': phone, 'note': note, 'consent_channel': 'written'}
        if consent:
            d['consent'] = '1'
        if temple:
            d['temple'] = temple
        cl.post('/donors.php' + (f'?temple={temple}' if temple else ''), d, '/donors.php?new=1' + (f'&temple={temple}' if temple else ''))

    donor(ca, consent=False)
    check('D1 ไม่ติ๊กยืนยันความยินยอม → ไม่บันทึกผู้บริจาค', q(f"SELECT COUNT(*) FROM donors WHERE temple_id={tA}") == '0')
    donor(ca, name='ก')
    donor(ca, phone='abc')
    check('D2 ชื่อสั้นเกินไป/เบอร์โทรผิด ถูกปฏิเสธ', q(f"SELECT COUNT(*) FROM donors WHERE temple_id={tA}") == '0')
    donor(ca)
    did = q(f"SELECT id FROM donors WHERE temple_id={tA}")
    check('D3 ติ๊กยืนยันความยินยอม → บันทึกพร้อมวันเวลา ช่องทาง และผู้บันทึก', did != '' and q(f"SELECT CONCAT(consent_at IS NOT NULL,'|',consent_channel,'|',consent_by IS NOT NULL) FROM donors WHERE id={did}") == '1|written|1')
    hex_addr = ADDR.encode().hex()
    hex_phone = '081-234-5678'.encode().hex()
    check('D4 ที่อยู่และเบอร์โทรเก็บแบบเข้ารหัส (ไม่พบข้อความจริงในฐานข้อมูล)',
          q(f"SELECT address_enc IS NOT NULL AND phone_enc IS NOT NULL FROM donors WHERE id={did}") == '1'
          and hex_addr not in q(f"SELECT HEX(address_enc) FROM donors WHERE id={did}").lower() and hex_phone not in q(f"SELECT HEX(phone_enc) FROM donors WHERE id={did}").lower())
    _, _, pg = ca.req(f'/donors.php?donor={did}')
    check('D5 หน้ารายละเอียดแสดงที่อยู่/เบอร์ที่ถอดรหัสแล้ว', ADDR in pg and '081-234-5678' in pg)
    _, _, pg = ca.req('/donors.php?q=' + quote('ใจดี'))
    _, _, pg2 = ca.req('/donors.php?q=' + quote('%'))
    check('D6 ค้นหาชื่อได้ และ % ไม่ถูกตีเป็น wildcard', 'นายใจดี' in pg and 'ยังไม่มีผู้บริจาคในทะเบียน' in pg2)
    donor(ca, name='นายใจดี มีทานสุข', did=did, address='แก้ที่อยู่ใหม่', phone='', note='ผู้ศรัทธา')
    _, _, pg = ca.req(f'/donors.php?donor={did}')
    check('D7 แก้ไขข้อมูลได้ (ไม่ต้องขอความยินยอมซ้ำ)', 'มีทานสุข' in pg and 'แก้ที่อยู่ใหม่' in pg and 'ผู้ศรัทธา' in pg and q(f"SELECT phone_enc IS NULL FROM donors WHERE id={did}") == '1')
    donor(ca, name='นายใจดี มีทานสุข', did=did, address=ADDR, phone='081-234-5678')

    # อนุโมทนาบัตร
    def income(cl, tid, amt='1000'):
        cl.post('/transactions.php?type=income', {'action': 'save', 'temple_id': tid, 'category_id': cdon, 'account_id': 'cash', **be_date('txn_date', date.today()), 'amount': amt, 'description': 'ทำบุญ', 'doc_no': ''}, '/transactions.php?type=income')
        return q(f"SELECT MAX(id) FROM transactions WHERE temple_id={tid}")

    def issue(cl, txn, **kw):
        f = {'action': 'issue', 'txn': txn, 'donor_name': '', 'donor_address': '', 'purpose': 'สร้างศาลา', 'book_no': '1', **be_date('receipt_date', date.today()), 'receiver_name': 'ไวยา', 'receiver_position': 'ไวยาวัจกร'}
        f.update(kw)
        return cl.post('/receipt.php', f, f'/receipt.php?txn={txn}')
    x1 = income(ca, tA)
    _, _, pg = ca.req(f'/receipt.php?txn={x1}')
    check('D8 ฟอร์มอนุโมทนาบัตรมีตัวเลือกผู้บริจาคพร้อมที่อยู่ให้เติมอัตโนมัติ', 'data-donor-pick' in pg and f'<option value="{did}" data-name' in pg and 'data-address=' in pg and ADDR in pg)
    issue(ca, x1, donor_id=did)
    r = q("SELECT CONCAT(donor_name,'|',donor_address,'|',donor_id) FROM donation_receipts WHERE transaction_id=" + x1)
    check('D9 เลือกผู้บริจาคจากทะเบียนและเว้นชื่อ/ที่อยู่ไว้ → ใบใช้ข้อมูลจากทะเบียน และผูก donor_id', r == f'นายใจดี มีทานสุข|{ADDR}|{did}', r)

    x2 = income(ca, tA, '500')
    issue(ca, x2, donor_name='นางสาวสมหญิง', donor_address='ที่อยู่ 2', save_donor='1')
    check('D10 ติ๊กบันทึกผู้บริจาคแต่ไม่ยืนยันความยินยอม → ออกใบไม่ได้และไม่สร้างผู้บริจาค', q(f"SELECT COUNT(*) FROM donation_receipts WHERE transaction_id={x2}") == '0' and q(f"SELECT COUNT(*) FROM donors WHERE temple_id={tA}") == '1')
    issue(ca, x2, donor_name='นางสาวสมหญิง', donor_address='ที่อยู่ 2', save_donor='1', donor_consent='1', donor_channel='at_receipt')
    d2 = q(f"SELECT id FROM donors WHERE name='นางสาวสมหญิง'")
    check('D11 ติ๊กบันทึก + ยืนยันความยินยอม → สร้างผู้บริจาคใหม่ (ช่องทาง at_receipt) และผูกกับใบ',
          d2 != '' and q(f"SELECT donor_id FROM donation_receipts WHERE transaction_id={x2}") == d2 and q(f"SELECT consent_channel FROM donors WHERE id={d2}") == 'at_receipt')

    x3 = income(ca, tA, '300')
    issue(ca, x3, donor_name='คนไม่ลงทะเบียน', donor_address='x')
    check('D12 ไม่เลือก/ไม่บันทึก → ออกใบได้ตามปกติและ donor_id เป็น NULL', q(f"SELECT donor_id IS NULL FROM donation_receipts WHERE transaction_id={x3}") == '1')

    # ผู้บริจาควัดอื่น
    donor(cb, name='ผู้บริจาควัดอื่น')
    db_ = q(f"SELECT id FROM donors WHERE temple_id={tB}")
    x4 = income(ca, tA, '200')
    issue(ca, x4, donor_name='ทดสอบ', donor_address='x', donor_id=db_)
    check('D13 ใช้ผู้บริจาคของวัดอื่นตอนออกใบไม่ได้', q(f"SELECT COUNT(*) FROM donation_receipts WHERE transaction_id={x4}") == '0')

    _, _, pg = ca.req(f'/donors.php?donor={did}')
    check('D14 ประวัติผู้บริจาคแสดงใบที่ออกและยอดรวม 1,000.00', f'/{date.today().year + 543}' in pg and '1,000.00' in pg)
    _, _, pg = ca.req('/donors.php')
    check('D15 รายการผู้บริจาคแสดงจำนวนใบและยอดรวม', 'นายใจดี มีทานสุข' in pg and '1,000.00' in pg)

    # สิทธิ์
    _, _, pgb = cb.req(f'/donors.php?temple={tA}')
    cod, _, pgd = cb.req(f'/donors.php?donor={did}')
    check('D16 แอดมินวัดอื่นเห็นเฉพาะผู้บริจาคของวัดตัวเอง และเปิดผู้บริจาคของวัดเราไม่ได้', 'นายใจดี' not in pgb and 'ผู้บริจาควัดอื่น' in pgb and 'นายใจดี' not in pgd and ADDR not in pgd)
    pro = q("SELECT username FROM users WHERE role='province' LIMIT 1")
    sub = q("SELECT username FROM users WHERE role='subdistrict' LIMIT 1")
    for label, un in (('เจ้าคณะจังหวัด', pro), ('เจ้าคณะตำบล', sub)):
        if not un:
            continue
        cp = Client(base)
        cp.post('/login.php', {'username': un, 'password': PW})
        code, loc, _ = cp.req(f'/donors.php?temple={tA}&donor={did}')
        tok = cp.csrf('/account.php')
        c2, _, _ = cp.req('/donors.php', raw=urlencode({'csrf': tok, 'action': 'save', 'temple': tA, 'name': 'แอบเพิ่ม', 'consent': '1', 'consent_channel': 'written'}).encode(), headers={'Content-Type': 'application/x-www-form-urlencoded'})
        check(f'D17 {label} เข้าทะเบียนผู้บริจาคไม่ได้ (redirect) และเพิ่มไม่ได้', code in (302, 303) and c2 in (302, 303) and q("SELECT COUNT(*) FROM donors WHERE name='แอบเพิ่ม'") == '0')
    check('D18 การเปิดดูรายละเอียดผู้บริจาคถูกบันทึกใน audit log', int(q("SELECT COUNT(*) FROM audit_log WHERE entity='donor' AND action='view'")) >= 1)

    # ถอนความยินยอม
    ca.post('/donors.php', {'action': 'withdraw', 'donor_id': did}, f'/donors.php?donor={did}')
    check('D19 ถอนความยินยอม: ลบชื่อ/ที่อยู่/เบอร์/หมายเหตุ คงบันทึกการยินยอมและการถอน',
          q(f"SELECT CONCAT(name,'|',address_enc IS NULL,'|',phone_enc IS NULL,'|',note IS NULL,'|',withdrawn_at IS NOT NULL,'|',consent_at IS NOT NULL) FROM donors WHERE id={did}") == '(ถอนความยินยอมแล้ว)|1|1|1|1|1')
    check('D20 ใบอนุโมทนาที่ออกไปแล้วยังคงชื่อและที่อยู่ตามหลักฐานทางบัญชี', q(f"SELECT CONCAT(donor_name,'|',donor_address) FROM donation_receipts WHERE transaction_id={x1}") == f'นายใจดี มีทานสุข|{ADDR}')
    x5 = income(ca, tA, '100')
    _, _, pg = ca.req(f'/receipt.php?txn={x5}')
    issue(ca, x5, donor_name='ทดสอบ', donor_address='x', donor_id=did)
    donor(ca, name='พยายามแก้', did=did)
    check('D21 ผู้บริจาคที่ถอนแล้วไม่อยู่ในตัวเลือก ใช้ออกใบใหม่ไม่ได้ และแก้ไขไม่ได้',
          '(ถอนความยินยอมแล้ว)' not in pg and 'นายใจดี' not in pg and q(f"SELECT COUNT(*) FROM donation_receipts WHERE transaction_id={x5}") == '0' and q(f"SELECT name FROM donors WHERE id={did}") == '(ถอนความยินยอมแล้ว)')
    _, _, pg = ca.req(f'/donors.php?donor={did}')
    check('D22 หน้าผู้บริจาคที่ถอนแล้วแสดงสถานะถอน ไม่มีข้อมูลส่วนบุคคล', 'ถอนความยินยอมเมื่อ' in pg and ADDR not in pg)

    # migration ยังไม่รัน
    q('ALTER TABLE donation_receipts DROP FOREIGN KEY fk_receipt_donor')
    q('ALTER TABLE donation_receipts DROP COLUMN donor_id')
    q('DROP TABLE donors')
    x6 = income(ca, tA, '50')
    code, _, pg = ca.req(f'/receipt.php?txn={x6}')
    issue(ca, x6, donor_name='ไม่มีทะเบียน', donor_address='x')
    c2, _, _ = ca.req('/donors.php')
    check('D23 ไม่มีตาราง: ออกอนุโมทนาบัตรได้ ไม่มีตัวเลือกผู้บริจาค และหน้าทะเบียน redirect', code == 200 and 'data-donor-pick' not in pg and q(f"SELECT COUNT(*) FROM donation_receipts WHERE transaction_id={x6}") == '1' and c2 in (302, 303))
