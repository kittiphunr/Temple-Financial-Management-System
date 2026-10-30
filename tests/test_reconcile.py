"""ชุดที่ 15: กระทบยอดบัญชีธนาคาร — ติ๊กรายการ เทียบยอด statement ส่วนต่าง รายการค้างยกไปเดือนถัดไป สิทธิ์ และ migration ที่ยังไม่รัน"""
import datetime
import re

from support import App, Client, FakeSMTP, be_date, hash_password

PW = 'Wat12345pw!'


def run(db, R):
    smtp = FakeSMTP()
    name = db.new_database('recon')
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


def _months_ago(n):
    d = datetime.date.today().replace(day=1)
    for _ in range(n):
        d = (d - datetime.timedelta(days=1)).replace(day=1)
    return d


def _run(R, q, app, smtp):
    check = R.check
    app.php('setup.php', 'Test12345pw')
    base = app.serve()
    check('C0 ตารางกระทบยอดถูกสร้างพร้อมการติดตั้ง', q("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name IN ('bank_reconciliations','bank_cleared')") == '2')

    tA, tB = q("SELECT id FROM temples ORDER BY id LIMIT 1"), q("SELECT id FROM temples ORDER BY id LIMIT 1 OFFSET 1")
    cin = q("SELECT id FROM categories WHERE type='income' AND is_transfer=0 LIMIT 1")
    cex = q("SELECT id FROM categories WHERE type='expense' AND is_transfer=0 LIMIT 1")
    h = hash_password(app, PW)
    q(f"UPDATE users SET password_hash='{h}', must_change_password=0 WHERE role IN ('temple','province')")
    for t, em in ((tA, 'tA@example.org'), (tB, 'tB@example.org')):
        q(f"UPDATE temples SET email='{em}' WHERE id={t}")
    ca, cb = Client(base), Client(base)
    for c, t in ((ca, tA), (cb, tB)):
        c.post('/login.php', {'username': q(f"SELECT username FROM users WHERE temple_id={t}"), 'password': PW})
        c.post('/login_verify.php', {'action': 'verify', 'code': smtp.last_code()})

    d2, d1 = _months_ago(2), _months_ago(1)          # d2 = ก่อนหน้า 2 เดือน (เดือนเริ่มบัญชี) · d1 = เดือนที่กระทบยอด
    y1, m1 = d1.year + 543, d1.month
    y0, m0 = _months_ago(0).year + 543, _months_ago(0).month
    ca.post('/accounts.php', {'action': 'add_bank', 'bank_name': 'กสิกรไทย', 'branch': 'แพร่', 'account_no': '123-4-56789-0', 'name': 'วัด', 'opening_balance': '1000', **be_date('opening_date', d2.replace(day=1))}, '/accounts.php')
    bank = q(f"SELECT id FROM accounts WHERE temple_id={tA} AND kind='bank'")
    cash = q(f"SELECT id FROM accounts WHERE temple_id={tA} AND kind='cash'")

    def txn(cl, typ, cat, date, amt, desc, acct):
        cl.post(f'/transactions.php?type={typ}', {'action': 'save', 'temple_id': tA, 'category_id': cat, 'account_id': acct, **be_date('txn_date', date), 'amount': str(amt), 'description': desc, 'doc_no': ''}, f'/transactions.php?type={typ}')
        return q(f"SELECT MAX(id) FROM transactions WHERE temple_id={tA} AND is_deleted=0")

    day = d1.replace(day=10)
    t_dep = txn(ca, 'income', cin, day, 500, 'เงินโอนบริจาค', bank)
    t_chq = txn(ca, 'expense', cex, day, 200, 'เช็คค่าไฟ', bank)
    ca.post('/accounts.php', {'action': 'transfer', 'from_id': cash, 'to_id': bank, **be_date('txn_date', day), 'amount': '300', 'description': 'ฝากเงินสด', 'doc_no': ''}, '/accounts.php')
    t_xf = q(f"SELECT id FROM transactions WHERE temple_id={tA} AND account_id={bank} AND type='income' AND amount=300")

    url = f'/reconcile.php?account={bank}&year={y1}&month={m1}'
    code, _, pg = ca.req(url)
    check('C1 หน้ากระทบยอดแสดงรายการของบัญชีและยอดสมุด 1,600.00', code == 200 and 'เช็คค่าไฟ' in pg and '1,600.00' in pg)

    def save(cl, stmt, ids, y=y1, m=m1, acct=bank, tmp=None, note=''):
        data = {'account': acct, 'year': y, 'month': m, 'statement_balance': stmt, 'note': note}
        if tmp:
            data['temple'] = tmp
        body = cl.csrf(f'/reconcile.php?account={acct}&year={y}&month={m}' + (f'&temple={tmp}' if tmp else ''))
        from urllib.parse import urlencode
        enc = urlencode([('csrf', body)] + list(data.items()) + [('cleared[]', i) for i in ids])
        return cl.req('/reconcile.php', raw=enc.encode(), headers={'Content-Type': 'application/x-www-form-urlencoded'})

    save(ca, '1800', [t_dep, t_xf])
    r = q(f"SELECT CONCAT(statement_balance,'|',book_balance,'|',outstanding_in,'|',outstanding_out,'|',difference) FROM bank_reconciliations WHERE account_id={bank}")
    check('C2 ติ๊กฝาก+โอน เช็คค้าง 200 → statement 1,800 ตรงกัน (ส่วนต่าง 0)', r == '1800.00|1600.00|0.00|200.00|0.00', r)
    _, _, pg = ca.req(url)
    check('C3 หน้าแสดงผล "ตรงกัน" และติ๊กไว้ 2 รายการ', '#check-circle' in pg and 'ไม่ตรงกัน' not in pg and 'ตรงกัน' in pg and len(re.findall(r'name="cleared\[\]"[^>]*checked', pg)) == 2)

    save(ca, '1750', [t_dep, t_xf], note='ลองผิด')
    r = q(f"SELECT difference FROM bank_reconciliations WHERE account_id={bank}")
    check('C4 statement ผิด 50 → ส่วนต่าง -50.00 และถูกบันทึกทับเดือนเดิม', r == '-50.00' and q(f"SELECT COUNT(*) FROM bank_reconciliations WHERE account_id={bank}") == '1', r)
    _, _, pg = ca.req(url)
    check('C5 หน้าแสดง "ไม่ตรงกัน" พร้อมส่วนต่าง', '#x-circle' in pg and 'ไม่ตรงกัน' in pg and '50.00' in pg)

    save(ca, '1800', [t_dep, t_xf])
    # ผลเสีย: ไม่ติ๊กอะไรเลย → ค้างฝาก 800 → expected 800 -> diff 1000
    save(ca, '1800', [])
    r = q(f"SELECT CONCAT(outstanding_in,'|',outstanding_out,'|',difference) FROM bank_reconciliations WHERE account_id={bank}")
    check('C6 ไม่ติ๊กรายการ → ถือเป็นรายการค้างทั้งหมดและส่วนต่างเปลี่ยน', r == '800.00|200.00|800.00', r)
    save(ca, '1800', [t_dep, t_xf])

    # ติ๊กรายการของวัดอื่น/ที่ไม่เกี่ยวข้อง
    cb.post('/transactions.php?type=income', {'action': 'save', 'temple_id': tB, 'category_id': cin, 'account_id': 'cash', **be_date('txn_date', day), 'amount': '77', 'description': 'วัดอื่น', 'doc_no': ''}, '/transactions.php?type=income')
    tb_id = q(f"SELECT MAX(id) FROM transactions WHERE temple_id={tB}")
    save(ca, '1800', [t_dep, t_xf, tb_id])
    check('C7 ติ๊กรหัสรายการของวัดอื่นแล้วถูกเมินเฉย (ไม่บันทึก)', q(f"SELECT COUNT(*) FROM bank_cleared WHERE transaction_id={tb_id}") == '0' and q(f"SELECT COUNT(*) FROM bank_cleared WHERE account_id={bank}") == '2')

    # ยังไม่สิ้นเดือน
    save(ca, '1000', [], y=y0, m=m0)
    check('C8 เดือนปัจจุบันที่ยังไม่สิ้นเดือน กระทบยอดไม่ได้', q(f"SELECT COUNT(*) FROM bank_reconciliations WHERE account_id={bank} AND recon_year={y0} AND recon_month={m0}") == '0')

    # สิทธิ์
    _, _, pgb = cb.req(f'/reconcile.php?account={bank}&year={y1}&month={m1}')
    save(cb, '5', [], acct=bank)
    check('C9 แอดมินวัดอื่นดู/บันทึกกระทบยอดของวัดเรา ไม่ได้', 'เช็คค่าไฟ' not in pgb and q(f"SELECT COUNT(*) FROM bank_reconciliations WHERE account_id={bank}") == '1')
    pro = q("SELECT username FROM users WHERE role='province' LIMIT 1")
    if pro:
        cp = Client(base)
        cp.post('/login.php', {'username': pro, 'password': PW})
        _, _, pgp = cp.req(f'/reconcile.php?temple={tA}&account={bank}&year={y1}&month={m1}')
        body = cp.csrf('/account.php')
        code, _, _ = cp.req('/reconcile.php', raw=f'csrf={body}&temple={tA}&account={bank}&year={y1}&month={m1}&statement_balance=9'.encode(), headers={'Content-Type': 'application/x-www-form-urlencoded'})
        check('C10 เจ้าคณะจังหวัดดูได้แต่บันทึกไม่ได้ (403)', 'เช็คค่าไฟ' in pgp and 'บันทึกผลกระทบยอด' not in pgp and code == 403)

    # เดือนถัดไป: รายการที่ผ่านแล้วหายไป เช็คค้างยกมา
    _, _, pg = ca.req(f'/reconcile.php?account={bank}&year={y0}&month={m0}')
    check('C11 เดือนถัดไป: รายการที่ผ่านแล้วไม่โชว์ เช็คที่ค้างยังอยู่', 'เช็คค่าไฟ' in pg and 'เงินโอนบริจาค' not in pg)

    # แก้รายการหลังบันทึก → เตือนว่าผลล้าสมัย
    txn(ca, 'income', cin, day, 40, 'ดอกเบี้ย', bank)
    _, _, pg = ca.req(url)
    check('C12 แก้/เพิ่มรายการหลังบันทึก → เตือนให้กระทบยอดใหม่', 'กรุณากระทบยอดและบันทึกอีกครั้ง' in pg)
    _, _, pg = ca.req(f'/accounts.php')
    check('C13 หน้าบัญชีมีปุ่มไปกระทบยอด', 'reconcile.php' in pg)

    # migration ยังไม่รัน
    q('DROP TABLE bank_cleared')
    q('DROP TABLE bank_reconciliations')
    code, loc, _ = ca.req(url)
    _, _, pg = ca.req('/accounts.php')
    check('C14 ไม่มีตาราง: หน้าบัญชีใช้ได้ ไม่มีปุ่มกระทบยอด และหน้ากระทบยอด redirect', code in (302, 303) and 'reconcile.php' not in pg and 'กสิกรไทย' in pg)
