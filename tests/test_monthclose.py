"""ชุดที่ 13: ปิดงวดรายเดือน — ล็อกการแก้ไขรายการของเดือนที่ปิดแล้ว เปิดงวดต้องมีเหตุผลและทำได้เฉพาะแอดมินหลัก/อำเภอ

เงื่อนไขปิดงวด: ส่งรายงานแล้วและยอดตรงกับปัจจุบัน และสิ้นเดือนแล้ว · ล็อก: เพิ่ม/แก้/ลบรายการ โอน/ฝาก-ถอน ยอดยกมา
"""
import datetime
import re
import subprocess

from support import App, Client, FakeSMTP, be_date, notice

PW = 'Wat12345pw!'


def run(db, R):
    smtp = FakeSMTP()
    name = db.new_database('mclose')
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
    adm = Client(base)
    adm.post('/login.php', {'username': 'admin', 'password': 'Test12345pw'})
    txt = lambda b: re.sub(r'\s+', ' ', re.sub(r'<[^>]+>', ' ', b))
    check('M0 ตาราง/คอลัมน์ปิดงวดถูกสร้างพร้อมการติดตั้ง',
          q("SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='monthly_reports' AND column_name IN ('closed_at','closed_by')") == '2'
          and q("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name='month_close_events'") == '1')

    d3, d2 = _months_ago(3), _months_ago(2)          # d3 = เดือนที่จะปิด · d2 = เดือนถัดไป (ยังเปิด)
    y3, m3, y2, m2 = d3.year + 543, d3.month, d2.year + 543, d2.month
    cur = datetime.date.today().replace(day=1)
    yc, mc = cur.year + 543, cur.month
    day3, day2 = d3.replace(day=10), d2.replace(day=10)

    tA = q("SELECT id FROM temples ORDER BY id LIMIT 1")
    dA = q(f"SELECT district_id FROM temples WHERE id={tA}")
    cat_in = q("SELECT id FROM categories WHERE type='income' AND is_transfer=0 LIMIT 1")
    q(f"UPDATE temples SET email='tA@example.org' WHERE id={tA}")
    tuser = q(f"SELECT username FROM users WHERE temple_id={tA}")
    h = subprocess.run(['php', '-r', f"echo password_hash('{PW}', PASSWORD_DEFAULT);"], capture_output=True, text=True).stdout
    q(f"UPDATE users SET password_hash='{h}', must_change_password=0 WHERE temple_id={tA}")
    t = Client(base)
    t.post('/login.php', {'username': tuser, 'password': PW})
    t.post('/login_verify.php', {'action': 'verify', 'code': smtp.last_code()})

    # บัญชีธนาคาร + รายการโอนในเดือน d3 (สร้างก่อนปิดงวด)
    t.post('/accounts.php', {'action': 'add_bank', 'bank_name': 'กสิกรไทย', 'branch': 'แพร่', 'account_no': '123-4-56789-0', 'name': 'วัด', 'opening_balance': '1000', **be_date('opening_date', d3.replace(day=1) - datetime.timedelta(days=60))}, '/accounts.php')
    bank = q(f"SELECT id FROM accounts WHERE temple_id={tA} AND kind='bank'")
    cash = q(f"SELECT id FROM accounts WHERE temple_id={tA} AND kind='cash'")
    check('M1 สร้างบัญชีธนาคารได้ก่อนปิดงวด', bank != '' and cash != '')
    t.post('/accounts.php', {'action': 'transfer', 'from_id': cash, 'to_id': bank, **be_date('txn_date', day3), 'amount': '200', 'description': 'ฝากเงินสด', 'doc_no': ''}, '/accounts.php')
    gid = q(f"SELECT transfer_id FROM transactions WHERE temple_id={tA} AND transfer_id IS NOT NULL ORDER BY id LIMIT 1")
    check('M2 บันทึกรายการโอน/ฝากในเดือนที่จะปิดได้ (ยังไม่ปิดงวด)', gid != '')

    def add_txn(date, amt, desc='ทดสอบ', cl=t, **extra):
        cl.post('/transactions.php?type=income', {'action': 'save', 'temple_id': tA, 'category_id': cat_in, 'account_id': 'cash', **be_date('txn_date', date), 'amount': str(amt), 'description': desc, 'doc_no': '', **extra}, '/transactions.php?type=income')
        return notice(cl.req('/transactions.php?type=income')[2])

    add_txn(day3, 1000, 'รายการ A')
    add_txn(day3, 500, 'รายการ B')
    add_txn(day2, 700, 'รายการเดือนถัดไป')
    idA = q(f"SELECT id FROM transactions WHERE temple_id={tA} AND description='รายการ A'")
    idB = q(f"SELECT id FROM transactions WHERE temple_id={tA} AND description='รายการ B'")
    idN = q(f"SELECT id FROM transactions WHERE temple_id={tA} AND description='รายการเดือนถัดไป'")

    stm = lambda cl, y=y3, m=m3: cl.req(f'/statements.php?year={y}&month={m}&form=1')
    post_stm = lambda cl, data, y=y3, m=m3: cl.post(f'/statements.php?year={y}&month={m}&form=1', {'year': y, 'month': m, **data}, f'/statements.php?year={y}&month={m}&form=1')

    # ---- A: เงื่อนไขปิดงวด
    post_stm(t, {'action': 'close_month'})
    check('A1 ยังไม่ส่งรายงาน → ปิดงวดไม่ได้', q("SELECT COUNT(*) FROM monthly_reports") == '0')
    post_stm(t, {'action': 'submit_report', 'note': ''})
    post_stm(t, {'action': 'submit_report', 'note': ''}, yc, mc)
    post_stm(t, {'action': 'close_month'}, yc, mc)
    check('A2 เดือนปัจจุบัน (ยังไม่สิ้นเดือน) ปิดงวดไม่ได้ แม้ส่งรายงานแล้ว', q(f"SELECT closed_at IS NULL FROM monthly_reports WHERE temple_id={tA} AND report_year={yc} AND report_month={mc}") == '1')
    c, _, b = stm(t)
    check('A3 หน้ารายงานมีปุ่ม "ปิดงวดเดือนนี้" เมื่อส่งรายงานแล้วและยอดตรงกัน', re.search(r'#lock"></use></svg> ปิดงวดเดือนนี้', b) is not None and 'ปิดงวดแล้ว' not in txt(b))
    # แก้ไขหลังส่ง → ปิดไม่ได้
    add_txn(day3, 50, 'เพิ่มหลังส่ง')
    post_stm(t, {'action': 'close_month'})
    check('A4 มีการแก้ไขหลังส่งรายงาน → ปิดงวดไม่ได้ ต้องส่งใหม่ก่อน', q(f"SELECT closed_at IS NULL FROM monthly_reports WHERE temple_id={tA} AND report_year={y3} AND report_month={m3}") == '1' and 'ปิดงวดเดือนนี้' not in stm(t)[2])
    post_stm(t, {'action': 'submit_report', 'note': ''})
    post_stm(t, {'action': 'close_month'})
    check('A5 ส่งรายงานใหม่แล้วปิดงวดสำเร็จ (บันทึกเวลา/ผู้ปิด/ประวัติ)',
          q(f"SELECT closed_at IS NOT NULL AND closed_by IS NOT NULL FROM monthly_reports WHERE temple_id={tA} AND report_year={y3} AND report_month={m3}") == '1'
          and q(f"SELECT COUNT(*) FROM month_close_events WHERE temple_id={tA} AND action='close'") == '1')
    c, _, b = stm(t)
    check('A6 หน้ารายงานแสดง "ปิดงวดแล้ว" ไม่มีปุ่มส่งรายงาน/ปิดงวด และไม่มีปุ่มเปิดงวดสำหรับแอดมินวัด', 'ปิดงวดแล้ว' in txt(b) and 'ส่งรายงานใหม่' not in b and 'เปิดงวด' not in b.replace('ปิด/เปิดงวด', ''))
    rev = q(f"SELECT revision FROM monthly_reports WHERE temple_id={tA} AND report_year={y3} AND report_month={m3}")
    post_stm(t, {'action': 'submit_report', 'note': ''})
    check('A7 งวดที่ปิดแล้วส่งรายงานใหม่ไม่ได้', q(f"SELECT revision FROM monthly_reports WHERE temple_id={tA} AND report_year={y3} AND report_month={m3}") == rev)

    # ---- B: การล็อก
    n0 = q(f"SELECT COUNT(*) FROM transactions WHERE temple_id={tA} AND is_deleted=0")
    msg = add_txn(day3, 123, 'ห้ามเพิ่ม')
    check('B1 เพิ่มรายการวันที่ในเดือนที่ปิดแล้ว → ปฏิเสธ พร้อมข้อความ', q(f"SELECT COUNT(*) FROM transactions WHERE temple_id={tA} AND is_deleted=0") == n0 and 'ปิดงวดแล้ว' in msg, msg)
    add_txn(day2, 321, 'เดือนถัดไปเพิ่มได้')
    check('B2 เพิ่มรายการในเดือนอื่น (ยังเปิด) ได้ตามปกติ', q(f"SELECT COUNT(*) FROM transactions WHERE temple_id={tA} AND description='เดือนถัดไปเพิ่มได้'") == '1')
    add_txn(day3, 999, 'แก้รายการ A', id=idA)
    check('B3 แก้ไขรายการในเดือนที่ปิดแล้ว → ปฏิเสธ (จำนวนเงินเดิม)', q(f"SELECT amount FROM transactions WHERE id={idA}") == '1000.00')
    add_txn(day3, 700, 'ย้ายเข้างวดที่ปิด', id=idN)
    check('B4 ย้ายรายการจากเดือนอื่นเข้าไปในเดือนที่ปิดแล้ว → ปฏิเสธ', q(f"SELECT txn_date FROM transactions WHERE id={idN}") == day2.isoformat())
    add_txn(day2, 500, 'ย้ายออก', id=idB)
    check('B5 ย้ายรายการออกจากเดือนที่ปิดแล้ว → ปฏิเสธ', q(f"SELECT txn_date FROM transactions WHERE id={idB}") == day3.isoformat())
    t.post('/transactions.php?type=income', {'action': 'delete', 'id': idA}, '/transactions.php?type=income')
    check('B6 ลบรายการในเดือนที่ปิดแล้ว → ปฏิเสธ', q(f"SELECT is_deleted FROM transactions WHERE id={idA}") == '0')
    dist_code = q(f"SELECT code FROM districts WHERE id={dA}")
    dist = Client(base)
    uid = q(f"SELECT id FROM users WHERE username='amphoe{dist_code}'")
    q(f"UPDATE users SET password_hash='{h}', must_change_password=0, email='d@example.org' WHERE id={uid}")
    dist.post('/login.php', {'username': f'amphoe{dist_code}', 'password': PW})
    dist.post('/transactions.php?type=income', {'action': 'delete', 'id': idA}, '/transactions.php?type=income')
    check('B7 แอดมินอำเภอก็ลบรายการในงวดที่ปิดไม่ได้ (ล็อกทุกบทบาท)', q(f"SELECT is_deleted FROM transactions WHERE id={idA}") == '0')
    adm.post('/transactions.php?type=income', {'action': 'delete', 'id': idA}, '/transactions.php?type=income')
    check('B8 แอดมินหลักก็ลบไม่ได้ ต้องเปิดงวดก่อน', q(f"SELECT is_deleted FROM transactions WHERE id={idA}") == '0')
    n1 = q(f"SELECT COUNT(*) FROM transactions WHERE temple_id={tA} AND transfer_id IS NOT NULL AND is_deleted=0")
    t.post('/accounts.php', {'action': 'transfer', 'from_id': cash, 'to_id': bank, **be_date('txn_date', day3), 'amount': '50', 'description': 'ห้ามโอน', 'doc_no': ''}, '/accounts.php')
    check('B9 บันทึกโอน/ฝาก-ถอนวันที่ในเดือนที่ปิดแล้ว → ปฏิเสธ', q(f"SELECT COUNT(*) FROM transactions WHERE temple_id={tA} AND transfer_id IS NOT NULL AND is_deleted=0") == n1)
    t.post('/accounts.php', {'action': 'delete_transfer', 'transfer_id': gid}, '/accounts.php')
    check('B10 ลบรายการโอน/ฝากในเดือนที่ปิดแล้ว → ปฏิเสธ', q(f"SELECT COUNT(*) FROM transactions WHERE transfer_id={gid} AND is_deleted=0") == '2')
    t.post('/accounts.php', {'action': 'set_opening', 'account_id': bank, 'opening_balance': '5000', **be_date('opening_date', d3 - datetime.timedelta(days=90))}, '/accounts.php')
    check('B11 แก้ยอดยกมาเมื่อมีเดือนที่ปิดงวด → ปฏิเสธ', q(f"SELECT opening_balance FROM accounts WHERE id={bank}") == '1000.00')
    t.post('/accounts.php', {'action': 'add_bank', 'bank_name': 'กรุงไทย', 'account_no': '999-9-99999-9', 'opening_balance': '0', **be_date('opening_date', d2)}, '/accounts.php')
    check('B12 เพิ่มบัญชีธนาคารใหม่เมื่อมีเดือนที่ปิดงวด → ปฏิเสธ', q(f"SELECT COUNT(*) FROM accounts WHERE temple_id={tA} AND kind='bank'") == '1')
    c, _, b = t.req(f'/transactions.php?type=income&year={y3}')
    check('B13 รายการบัญชีรายรับของงวดที่ปิดแสดงไอคอนล็อก "ปิดงวด" และไม่มีปุ่มแก้ไข/ลบของรายการนั้น', re.search(r'#lock"></use></svg> ปิดงวด', b) is not None and f'edit={idA}"' not in b and f'edit={idN}"' in b)
    c, _, b = adm.req(f'/report_status.php?year={y3}&month={m3}&status=closed')
    check('B14 หน้าติดตามรายงานกรองสถานะ "ปิดงวดแล้ว" ได้ 1 วัด มีการ์ดสรุปและส่งออก CSV', c == 200 and b.count('ดูรายงาน') == 1 and re.search(r'#lock"></use></svg> ปิดงวดแล้ว', b) is not None and 'ปิดงวดเมื่อ' in adm.req(f'/report_status.php?year={y3}&month={m3}&export=csv')[2])

    # ---- C: เปิดงวด
    post_stm(t, {'action': 'reopen_month', 'reason': 'ขอแก้ไขรายการให้ถูกต้อง'})
    check('C1 แอดมินวัดเปิดงวดเองไม่ได้', q(f"SELECT closed_at IS NOT NULL FROM monthly_reports WHERE temple_id={tA} AND report_year={y3} AND report_month={m3}") == '1')
    dstm = lambda data: dist.post(f'/statements.php?temple={tA}&year={y3}&month={m3}&form=1', {'temple': tA, 'year': y3, 'month': m3, **data}, f'/statements.php?temple={tA}&year={y3}&month={m3}&form=1')
    c, _, b = dist.req(f'/statements.php?temple={tA}&year={y3}&month={m3}&form=1')
    check('C2 แอดมินอำเภอเห็นช่องเปิดงวด (เหตุผลจำเป็น)', 'เหตุผลที่เปิดงวด' in b)
    dstm({'action': 'reopen_month', 'reason': 'สั้น'})
    check('C3 เปิดงวดโดยเหตุผลสั้นกว่า 5 ตัวอักษร → ปฏิเสธ', q(f"SELECT closed_at IS NOT NULL FROM monthly_reports WHERE temple_id={tA} AND report_year={y3} AND report_month={m3}") == '1')
    dstm({'action': 'reopen_month', 'reason': 'ใบเสร็จลงยอดผิด ต้องแก้ไข'})
    check('C4 แอดมินอำเภอเปิดงวดพร้อมเหตุผลได้ และบันทึกประวัติ',
          q(f"SELECT closed_at IS NULL FROM monthly_reports WHERE temple_id={tA} AND report_year={y3} AND report_month={m3}") == '1'
          and q(f"SELECT reason FROM month_close_events WHERE temple_id={tA} AND action='reopen'") == 'ใบเสร็จลงยอดผิด ต้องแก้ไข')
    add_txn(day3, 111, 'เพิ่มได้หลังเปิดงวด')
    check('C5 หลังเปิดงวด เพิ่ม/แก้ไขรายการได้อีก', q(f"SELECT COUNT(*) FROM transactions WHERE temple_id={tA} AND description='เพิ่มได้หลังเปิดงวด'") == '1')
    add_txn(day3, 998, 'แก้ A หลังเปิด', id=idA)
    check('C6 แก้ไขรายการเดิมได้หลังเปิดงวด', q(f"SELECT amount FROM transactions WHERE id={idA}") == '998.00')
    check('C7 หลังเปิดงวดและมีการแก้ไข สถานะรายงานเป็น "แก้ไขหลังส่ง" ปิดงวดซ้ำไม่ได้จนกว่าจะส่งใหม่', 'แก้ไขหลังส่ง' in txt(stm(t)[2]))
    post_stm(t, {'action': 'submit_report', 'note': ''})
    post_stm(t, {'action': 'close_month'})
    check('C8 ส่งรายงานใหม่แล้วปิดงวดอีกครั้งได้ ประวัติครบ (ปิด 2 เปิด 1)',
          q(f"SELECT COUNT(*) FROM month_close_events WHERE temple_id={tA} AND action='close'") == '2' and q(f"SELECT COUNT(*) FROM month_close_events WHERE temple_id={tA} AND action='reopen'") == '1')
    c, _, b = dist.req(f'/statements.php?temple={tA}&year={y3}&month={m3}&form=1')
    check('C9 หน้ารายงานแสดงประวัติปิด/เปิดงวดพร้อมเหตุผล', 'ประวัติปิด/เปิดงวด (3)' in b and 'ใบเสร็จลงยอดผิด' in b)
    prov_uid = q("SELECT id FROM users WHERE username='province'")
    q(f"UPDATE users SET password_hash='{h}', must_change_password=0, email='p@example.org' WHERE id={prov_uid}")
    prov = Client(base)
    prov.post('/login.php', {'username': 'province', 'password': PW})
    prov.post(f'/statements.php?temple={tA}&year={y3}&month={m3}&form=1', {'action': 'reopen_month', 'temple': tA, 'year': y3, 'month': m3, 'reason': 'เจ้าคณะจังหวัดลองเปิด'}, f'/statements.php?temple={tA}&year={y3}&month={m3}&form=1')
    check('C10 เจ้าคณะจังหวัดเปิดงวดไม่ได้ (ดูอย่างเดียว)', q(f"SELECT closed_at IS NOT NULL FROM monthly_reports WHERE temple_id={tA} AND report_year={y3} AND report_month={m3}") == '1')
    check('C11 บันทึก audit การปิด/เปิดงวด', int(q("SELECT COUNT(*) FROM audit_log WHERE action IN ('month_close','month_reopen')")) >= 3)

    # ---- D: ฐานข้อมูลที่ยังไม่รัน migration ปิดงวด
    q('ALTER TABLE monthly_reports DROP FOREIGN KEY fk_report_closer, DROP COLUMN closed_at, DROP COLUMN closed_by')
    q('DROP TABLE month_close_events')
    add_txn(day3, 77, 'ไม่มีการล็อกเมื่อยังไม่รัน migration')
    check('D1 ยังไม่รัน migration ปิดงวด → ไม่มีการล็อก ระบบทำงานแบบเดิม', q(f"SELECT COUNT(*) FROM transactions WHERE description='ไม่มีการล็อกเมื่อยังไม่รัน migration'") == '1')
    c, _, b = stm(t)
    c2, _, b2 = adm.req(f'/report_status.php?year={y3}&month={m3}')
    check('D2 หน้ารายงาน/ติดตามรายงานยังใช้ได้ ไม่มีปุ่มปิดงวด', c == 200 and c2 == 200 and 'ปิดงวดเดือนนี้' not in b and 'ปิดงวดแล้ว' not in b2)
