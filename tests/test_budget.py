"""ชุดที่ 16: งบประมาณประจำปี — ตั้งงบรายหมวด เทียบผลจริง เตือนเมื่อใกล้/เกินงบ คัดลอกจากปีก่อน สิทธิ์ และ migration ที่ยังไม่รัน"""
import datetime
import re
from urllib.parse import urlencode

from support import App, Client, FakeSMTP, be_date, hash_password, notice

PW = 'Wat12345pw!'


def run(db, R):
    smtp = FakeSMTP()
    name = db.new_database('budget')
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
    check('B0 ตารางงบประมาณถูกสร้างพร้อมการติดตั้ง', q("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name='budgets'") == '1')

    tA, tB = q("SELECT id FROM temples ORDER BY id LIMIT 1"), q("SELECT id FROM temples ORDER BY id LIMIT 1 OFFSET 1")
    cex, cex2 = q("SELECT id FROM categories WHERE type='expense' AND is_transfer=0 ORDER BY id LIMIT 1"), q("SELECT id FROM categories WHERE type='expense' AND is_transfer=0 ORDER BY id LIMIT 1 OFFSET 1")
    cin = q("SELECT id FROM categories WHERE type='income' AND is_transfer=0 ORDER BY id LIMIT 1")
    h = hash_password(app, PW)
    q(f"UPDATE users SET password_hash='{h}', must_change_password=0 WHERE role IN ('temple','province')")
    for t, em in ((tA, 'tA@example.org'), (tB, 'tB@example.org')):
        q(f"UPDATE temples SET email='{em}' WHERE id={t}")
    ca, cb = Client(base), Client(base)
    for c, t in ((ca, tA), (cb, tB)):
        c.post('/login.php', {'username': q(f"SELECT username FROM users WHERE temple_id={t}"), 'password': PW})
        c.post('/login_verify.php', {'action': 'verify', 'code': smtp.last_code()})

    year = datetime.date.today().year + 543
    today = datetime.date.today().replace(day=1)

    def save(cl, amounts, temple=None, y=year, action='save'):
        page = f'/budget.php?year={y}' + (f'&temple={temple}' if temple else '')
        data = [('csrf', cl.csrf(page)), ('action', action), ('year', y)] + ([('temple', temple)] if temple else []) + [(f'amount[{k}]', v) for k, v in amounts.items()]
        return cl.req('/budget.php', raw=urlencode(data).encode(), headers={'Content-Type': 'application/x-www-form-urlencoded'})

    def spend(cl, tid, cat, amt, typ='expense'):
        cl.post(f'/transactions.php?type={typ}', {'action': 'save', 'temple_id': tid, 'category_id': cat, 'account_id': 'cash', **be_date('txn_date', today), 'amount': str(amt), 'description': 'ทดสอบงบ', 'doc_no': ''}, f'/transactions.php?type={typ}')
        return notice(cl.req(f'/transactions.php?type={typ}')[2])

    code, _, pg = ca.req('/budget.php')
    check('B1 หน้างบประมาณเปิดได้ มีช่องกรอกงบรายหมวด', code == 200 and f'name="amount[{cex}]"' in pg and f'name="amount[{cin}]"' in pg)
    check('B2 ไม่มีหมวดโอน/ฝาก-ถอนในรายการงบ', not re.search(r'amount\[%s\]' % q("SELECT id FROM categories WHERE is_transfer=1 LIMIT 1"), pg) if q("SELECT COUNT(*) FROM categories WHERE is_transfer=1") != '0' else True)

    save(ca, {cex: '1,000', cex2: '500.50', cin: '2000'})
    check('B3 บันทึกงบ 3 หมวด (รองรับเครื่องหมายจุลภาค)', q(f"SELECT GROUP_CONCAT(CONCAT(category_id,':',amount) ORDER BY category_id) FROM budgets WHERE temple_id={tA} AND budget_year={year}") ==
          ','.join(f'{k}:{v}' for k, v in sorted([(int(cex), '1000.00'), (int(cex2), '500.50'), (int(cin), '2000.00')])))
    save(ca, {cex2: ''})
    check('B4 เว้นว่าง = ลบงบหมวดนั้น', q(f"SELECT COUNT(*) FROM budgets WHERE temple_id={tA} AND category_id={cex2}") == '0' and q(f"SELECT COUNT(*) FROM budgets WHERE temple_id={tA}") == '2')
    save(ca, {cex: 'abc'})
    check('B5 ค่าที่ไม่ใช่ตัวเลขไม่ทำให้งบเดิมเปลี่ยน', q(f"SELECT amount FROM budgets WHERE temple_id={tA} AND category_id={cex}") == '1000.00')
    save(ca, {'999999': '5', 'x': '1'})
    check('B6 รหัสหมวดที่ไม่มี/ไม่ถูกต้องถูกเมินเฉย', q(f"SELECT COUNT(*) FROM budgets WHERE temple_id={tA}") == '2')

    msg = spend(ca, tA, cex, 500)
    _, _, pg = ca.req(f'/budget.php')
    check('B7 ใช้ 500 จาก 1,000 → ไม่เตือน และหน้างบแสดง 50%', 'คำเตือน' not in msg and '50%' in pg)
    msg = spend(ca, tA, cex, 350)
    check('B8 ใช้ถึง 85% → เตือน "ใช้ไปแล้ว 85%" ตอนบันทึกรายจ่าย', 'คำเตือน' in msg and '85%' in msg, msg)
    msg = spend(ca, tA, cex, 300)
    _, _, pg = ca.req('/budget.php')
    check('B9 เกินงบ → เตือน "เกินงบ" และหน้างบแสดงสีแดง/เกินงบ', 'เกินงบปี' in msg and 'เกินงบ' in pg and 'text-red-700' in pg, msg)
    msg = spend(ca, tA, cex2, 100)
    check('B10 หมวดที่ไม่ได้ตั้งงบ ไม่เตือน', 'คำเตือน' not in msg)
    msg = spend(ca, tA, cin, 100, 'income')
    check('B11 รายรับไม่เตือนเรื่องงบ', 'คำเตือน' not in msg)

    # ผลจริงไม่นับรายการที่ลบ / ของวัดอื่น
    spend(cb, tB, cex, 9999)
    _, _, pg = ca.req('/budget.php')
    check('B12 ผลจริงของวัดเราไม่ปนกับวัดอื่น', '9,999' not in pg and '1,150.00' in pg)
    tx = q(f"SELECT MAX(id) FROM transactions WHERE temple_id={tA} AND category_id={cex}")
    ca.post('/transactions.php?type=expense', {'action': 'delete', 'id': tx}, '/transactions.php?type=expense')
    _, _, pg = ca.req('/budget.php')
    check('B13 รายการที่ลบแล้วไม่นับเป็นผลจริง (1,150 → 850)', '850.00' in pg and '1,150.00' not in pg)

    # คัดลอกจากปีก่อน
    save(ca, {cex: '700'}, y=year - 1)
    save(ca, {}, action='copy_prev', y=year)
    check('B14 คัดลอกจากปีก่อนไม่ทับหมวดที่ตั้งแล้ว', q(f"SELECT amount FROM budgets WHERE temple_id={tA} AND budget_year={year} AND category_id={cex}") == '1000.00')
    save(ca, {cex2: '123'}, y=year - 1)
    save(ca, {}, action='copy_prev', y=year)
    check('B15 คัดลอกเติมเฉพาะหมวดที่ยังไม่ได้ตั้ง', q(f"SELECT amount FROM budgets WHERE temple_id={tA} AND budget_year={year} AND category_id={cex2}") == '123.00')

    # สิทธิ์
    n0 = q("SELECT COUNT(*) FROM budgets")
    _, _, pgb = cb.req(f'/budget.php?temple={tA}')
    save(cb, {cex: '1'}, temple=tA)
    check('B16 แอดมินวัดอื่นตั้ง/ดูงบของวัดเราไม่ได้ (ได้หน้าของวัดตัวเองเท่านั้น)', q(f"SELECT COUNT(*) FROM budgets WHERE temple_id={tA}") == q(f"SELECT COUNT(*) FROM budgets WHERE temple_id={tA} AND amount<>1") and q(f"SELECT amount FROM budgets WHERE temple_id={tA} AND budget_year={year} AND category_id={cex}") == '1000.00')
    pro = q("SELECT username FROM users WHERE role='province' LIMIT 1")
    if pro:
        cp = Client(base)
        cp.post('/login.php', {'username': pro, 'password': PW})
        _, _, pg = cp.req(f'/budget.php?temple={tA}')
        body = cp.csrf('/account.php')
        code, _, _ = cp.req('/budget.php', raw=f'csrf={body}&action=save&temple={tA}&year={year}&amount[{cex}]=1'.encode(), headers={'Content-Type': 'application/x-www-form-urlencoded'})
        check('B17 เจ้าคณะจังหวัดดูได้แต่ตั้งงบไม่ได้ (403)', f'name="amount[{cex}]"' not in pg and 'ดูได้อย่างเดียว' in pg and code == 403 and q(f"SELECT amount FROM budgets WHERE temple_id={tA} AND budget_year={year} AND category_id={cex}") == '1000.00')

    # migration ยังไม่รัน
    q('DROP TABLE budgets')
    code, _, _ = ca.req('/budget.php')
    msg = spend(ca, tA, cex, 10)
    _, _, pg = ca.req('/transactions.php?type=expense')
    check('B18 ไม่มีตาราง: บันทึกรายจ่ายยังใช้ได้ ไม่มีเมนูงบ และหน้างบ redirect', code in (302, 303) and 'เรียบร้อย' in msg and 'budget.php' not in pg)
