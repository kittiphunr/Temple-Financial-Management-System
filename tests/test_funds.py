"""ชุดที่ 17: กองทุน/โครงการ (แยกเงินตามวัตถุประสงค์) และงบแสดงรายรับ-รายจ่ายประจำปี"""
import datetime
import re
from urllib.parse import urlencode

from support import App, Client, FakeSMTP, be_date, hash_password, notice

PW = 'Wat12345pw!'


def run(db, R):
    smtp = FakeSMTP()
    name = db.new_database('funds')
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
    check('F0 ตารางกองทุนและคอลัมน์ fund_id ถูกสร้างพร้อมการติดตั้ง',
          q("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name='funds'") == '1'
          and q("SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='transactions' AND column_name='fund_id'") == '1')

    tA, tB = q("SELECT id FROM temples ORDER BY id LIMIT 1"), q("SELECT id FROM temples ORDER BY id LIMIT 1 OFFSET 1")
    cin = q("SELECT id FROM categories WHERE type='income' AND is_transfer=0 ORDER BY id LIMIT 1")
    cdon = q("SELECT id FROM categories WHERE is_donation=1 AND type='income' ORDER BY id LIMIT 1")
    cex = q("SELECT id FROM categories WHERE type='expense' AND is_transfer=0 ORDER BY id LIMIT 1")
    h = hash_password(app, PW)
    q(f"UPDATE users SET password_hash='{h}', must_change_password=0 WHERE role IN ('temple','province')")
    for t, em in ((tA, 'tA@example.org'), (tB, 'tB@example.org')):
        q(f"UPDATE temples SET email='{em}' WHERE id={t}")
    ca, cb = Client(base), Client(base)
    for c, t in ((ca, tA), (cb, tB)):
        c.post('/login.php', {'username': q(f"SELECT username FROM users WHERE temple_id={t}"), 'password': PW})
        c.post('/login_verify.php', {'action': 'verify', 'code': smtp.last_code()})

    today = datetime.date.today()
    year = today.year + 543

    def fund(cl, name, target='', fid='', temple=None):
        data = {'action': 'save', 'name': name, 'target_amount': target, 'purpose': 'ทดสอบ', 'fund_id': fid}
        if temple:
            data['temple'] = temple
        cl.post('/funds.php' + (f'?temple={temple}' if temple else ''), data, '/funds.php' + (f'?temple={temple}' if temple else ''))

    def txn(cl, tid, typ, cat, amt, fund_id=0, date=today, desc='ทดสอบ'):
        cl.post(f'/transactions.php?type={typ}', {'action': 'save', 'temple_id': tid, 'category_id': cat, 'account_id': 'cash', 'fund_id': fund_id, **be_date('txn_date', date), 'amount': str(amt), 'description': desc, 'doc_no': ''}, f'/transactions.php?type={typ}')
        return q(f"SELECT COALESCE(MAX(id),0) FROM transactions WHERE temple_id={tid} AND is_deleted=0")

    fund(ca, 'กฐินสามัคคี', '10,000')
    fid = q(f"SELECT id FROM funds WHERE temple_id={tA} AND name='กฐินสามัคคี'")
    check('F1 สร้างกองทุนพร้อมเป้ายอด 10,000', fid != '' and q(f"SELECT target_amount FROM funds WHERE id={fid}") == '10000.00')
    fund(ca, 'กฐินสามัคคี')
    fund(ca, '   ')
    fund(ca, 'เป้าผิด', 'abc')
    fund(ca, 'เป้าติดลบ', '-5')
    check('F2 ชื่อซ้ำ/ว่าง/เป้ายอดผิด ถูกปฏิเสธ', q(f"SELECT COUNT(*) FROM funds WHERE temple_id={tA}") == '1')
    fund(ca, 'สร้างศาลา')
    fid2 = q(f"SELECT id FROM funds WHERE temple_id={tA} AND name='สร้างศาลา'")
    check('F3 สร้างกองทุนไม่ตั้งเป้ายอดได้ (target เป็น NULL)', fid2 != '' and q(f"SELECT target_amount IS NULL FROM funds WHERE id={fid2}") == '1')

    code, _, pg = ca.req('/transactions.php?type=income')
    check('F4 ฟอร์มรายการมีช่องกองทุน/โครงการพร้อมข้อมูลกองทุนของวัด', code == 200 and 'name="fund_id"' in pg and 'กฐินสามัคคี' in pg)

    x1 = txn(ca, tA, 'income', cdon, 6000, fid)
    x2 = txn(ca, tA, 'income', cin, 2000, fid)
    x3 = txn(ca, tA, 'expense', cex, 1500, fid)
    x4 = txn(ca, tA, 'income', cin, 777, 0)
    check('F5 บันทึกรายการผูกกองทุนได้ และรายการที่ไม่เลือกคือเงินทั่วไป (NULL)', q(f"SELECT COUNT(*) FROM transactions WHERE fund_id={fid}") == '3' and q(f"SELECT fund_id IS NULL FROM transactions WHERE id={x4}") == '1')
    _, _, pg = ca.req('/funds.php')
    check('F6 หน้ากองทุนสรุป รับ 8,000 ใช้ 1,500 คงเหลือ 6,500 และ 80%', all(s in pg for s in ('8,000.00', '1,500.00', '6,500.00', '80%')))
    _, _, pg = ca.req('/transactions.php?type=income')
    check('F7 รายการในบัญชีแสดงชื่อกองทุน', re.search(r'#layers"></use></svg> กฐินสามัคคี', pg) is not None)

    # กองทุนของวัดอื่น / กองทุนปิด
    fund(cb, 'ของวัดอื่น')
    fb = q(f"SELECT id FROM funds WHERE temple_id={tB}")
    n0 = q(f"SELECT COUNT(*) FROM transactions WHERE temple_id={tA}")
    txn(ca, tA, 'income', cin, 50, fb)
    check('F8 ใช้กองทุนของวัดอื่นกับรายการของเราไม่ได้ (ไม่บันทึก)', q(f"SELECT COUNT(*) FROM transactions WHERE temple_id={tA}") == n0)
    ca.post('/funds.php', {'action': 'toggle', 'fund_id': fid2}, '/funds.php')
    txn(ca, tA, 'income', cin, 60, fid2)
    check('F9 กองทุนที่ปิดแล้วเลือกกับรายการใหม่ไม่ได้', q(f"SELECT COUNT(*) FROM transactions WHERE temple_id={tA}") == n0)
    ca.post('/funds.php', {'action': 'toggle', 'fund_id': fid}, '/funds.php')
    ca.post('/transactions.php?type=income', {'action': 'save', 'id': x2, 'temple_id': tA, 'category_id': cin, 'account_id': 'cash', 'fund_id': fid, **be_date('txn_date', today), 'amount': '2100', 'description': 'แก้ไข', 'doc_no': ''}, '/transactions.php?type=income')
    check('F10 แก้ไขรายการเดิมของกองทุนที่ปิดแล้ว ยังคงผูกกองทุนเดิมได้', q(f"SELECT CONCAT(amount,'|',fund_id) FROM transactions WHERE id={x2}") == f'2100.00|{fid}')
    ca.post('/funds.php', {'action': 'toggle', 'fund_id': fid}, '/funds.php')
    ca.post('/funds.php', {'action': 'toggle', 'fund_id': fid2}, '/funds.php')

    # ลบรายการ -> ไม่นับ
    ca.post('/transactions.php?type=expense', {'action': 'delete', 'id': x3}, '/transactions.php?type=expense')
    _, _, pg = ca.req(f'/funds.php?fund={fid}')
    check('F11 รายการที่ลบแล้วไม่นับ และหน้ารายละเอียดแสดงยอดคงเหลือสะสม', '8,100.00' in pg and '1,500.00' not in pg)

    # อนุโมทนาบัตร: วัตถุประสงค์เริ่มต้น = ชื่อกองทุน
    _, _, pg = ca.req(f'/receipt.php?txn={x1}')
    m = re.search(r'name="purpose"[^>]*value="([^"]*)"', pg)
    check('F12 ฟอร์มอนุโมทนาบัตรใช้ชื่อกองทุนเป็นวัตถุประสงค์เริ่มต้น', bool(m) and m.group(1) == 'กฐินสามัคคี', pg[:0] + (m.group(1) if m else 'ไม่พบ'))

    # สิทธิ์
    _, _, pgb = cb.req(f'/funds.php?temple={tA}')
    check('F13 แอดมินวัดอื่นเห็นเฉพาะกองทุนของตัวเอง', f'fund={fid}' not in pgb and 'ของวัดอื่น' in pgb)
    pro = q("SELECT username FROM users WHERE role='province' LIMIT 1")
    if pro:
        cp = Client(base)
        cp.post('/login.php', {'username': pro, 'password': PW})
        _, _, pgp = cp.req(f'/funds.php?temple={tA}')
        tok = cp.csrf('/account.php')
        code, _, _ = cp.req('/funds.php', raw=urlencode({'csrf': tok, 'action': 'save', 'temple': tA, 'name': 'แอบสร้าง'}).encode(), headers={'Content-Type': 'application/x-www-form-urlencoded'})
        check('F14 เจ้าคณะจังหวัดดูกองทุนได้แต่สร้างไม่ได้ (403)', 'กฐินสามัคคี' in pgp and 'name="name"' not in pgp and code == 403 and q("SELECT COUNT(*) FROM funds WHERE name='แอบสร้าง'") == '0')

    # ---------- งบแสดงรายรับ-รายจ่ายประจำปี ----------
    last = datetime.date(today.year - 1, 1, 15)
    txn(ca, tA, 'income', cin, 100, 0, date=last, desc='ปีก่อน')
    q(f"UPDATE transactions SET is_deleted=1 WHERE id={x4}")       # ตัด 777 ออก ให้ตัวเลขชัด
    expect_close = 100 + 6000 + 2100 - 0          # เปิดต้นปี 100 + รับปีนี้ 8,100 (รายจ่าย 1,500 ถูกลบแล้ว)
    code, _, pg = ca.req(f'/annual.php?year={year}')
    flat = re.sub(r'\s+', ' ', re.sub(r'<[^>]+>', ' ', pg))
    check('G1 หน้างบประจำปีเปิดได้ มีรายรับ/รายจ่าย รายหมวด และเทียบปีก่อน', code == 200 and 'งบแสดงรายรับ-รายจ่ายประจำปี' in pg and f'ปี {year - 1}' in pg)
    check('G2 ยอดคงเหลือต้นปี 100.00 และคงเหลือปลายปี = ต้นปี + รับ − จ่าย', '100.00' in flat and f'{expect_close:,.2f}' in flat, flat[-600:])
    _, _, pgp = ca.req(f'/annual.php?year={year - 1}')
    check('G3 ปีก่อนหน้า: แสดงรายรับ 100.00 ของปีนั้น', '100.00' in pgp)
    check('G4 มีส่วนสรุปกองทุน/โครงการ', 'กฐินสามัคคี' in pg and 'รับในปี' in pg)
    save = {'csrf': ca.csrf('/budget.php'), 'action': 'save', 'year': year, f'amount[{cex}]': '9999'}
    ca.req('/budget.php', raw=urlencode(save).encode(), headers={'Content-Type': 'application/x-www-form-urlencoded'})
    _, _, pg = ca.req(f'/annual.php?year={year}')
    check('G5 เมื่อมีงบประมาณ แสดงคอลัมน์งบประมาณ', 'ผลต่างจากงบ' in pg and '9,999.00' in pg)
    _, _, pgb = cb.req(f'/annual.php?temple={tA}&year={year}')
    check('G6 แอดมินวัดอื่นดูงบของวัดเราไม่ได้', 'กฐินสามัคคี' not in pgb and '8,100' not in pgb)
    _, _, pg = ca.req('/reports.php')
    check('G7 หน้ารายงานมีลิงก์ไปงบแสดงรายรับ-รายจ่ายประจำปี', 'annual.php' in pg)

    # migration ยังไม่รัน
    q('ALTER TABLE transactions DROP FOREIGN KEY fk_txn_fund')
    q('ALTER TABLE transactions DROP COLUMN fund_id')
    q('DROP TABLE funds')
    code, _, pg = ca.req('/transactions.php?type=income')
    msg_before = q(f"SELECT COUNT(*) FROM transactions WHERE temple_id={tA}")
    txn(ca, tA, 'income', cin, 5)
    c2, _, _ = ca.req('/funds.php')
    c3, _, pga = ca.req(f'/annual.php?year={year}')
    check('F15 ไม่มีตาราง/คอลัมน์: บันทึกรายการยังได้ ไม่มีช่องกองทุน หน้ากองทุน redirect และงบประจำปียังเปิดได้',
          code == 200 and 'name="fund_id"' not in pg and int(q(f"SELECT COUNT(*) FROM transactions WHERE temple_id={tA}")) == int(msg_before) + 1 and c2 in (302, 303) and c3 == 200 and 'กองทุน/โครงการ' not in pga.split('<h2')[-1].split('</table>')[-1])
