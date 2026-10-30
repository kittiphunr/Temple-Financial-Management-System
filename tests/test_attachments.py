"""ชุดที่ 14: แนบหลักฐานใบเสร็จ/สลิป — ภาพแปลงใหม่ PDF เก็บตามต้นฉบับ สิทธิ์ดู/แนบ ล็อกเมื่อปิดงวด

ใช้ภาพสังเคราะห์เท่านั้น (ห้ามใช้สลิปจริงเป็นไฟล์ทดสอบ)
"""
import datetime
import re
import subprocess

from support import App, Client, FakeSMTP, be_date, hash_password, png_bytes

PW = 'Wat12345pw!'


def run(db, R):
    name = db.new_database('attach')
    q = db.q(name)
    smtp = FakeSMTP()
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
    adm = Client(base)
    adm.post('/login.php', {'username': 'admin', 'password': 'Test12345pw'})
    check('A0 ตารางหลักฐานแนบถูกสร้างพร้อมการติดตั้ง', q("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name='transaction_attachments'") == '1')

    tA, tB = q("SELECT id FROM temples ORDER BY id LIMIT 1"), q("SELECT id FROM temples ORDER BY id LIMIT 1 OFFSET 1")
    cat = q("SELECT id FROM categories WHERE type='expense' AND is_transfer=0 LIMIT 1")
    day = datetime.date.today().replace(day=1)
    h = hash_password(app, PW)
    q(f"UPDATE users SET password_hash='{h}', must_change_password=0 WHERE role IN ('temple','district')")
    userA, userB = q(f"SELECT username FROM users WHERE temple_id={tA}"), q(f"SELECT username FROM users WHERE temple_id={tB}")
    ca, cb = Client(base), Client(base)
    q(f"UPDATE temples SET email='tA@example.org' WHERE id={tA}")
    q(f"UPDATE temples SET email='tB@example.org' WHERE id={tB}")
    for c, un in ((ca, userA), (cb, userB)):
        c.post('/login.php', {'username': un, 'password': PW})
        c.post('/login_verify.php', {'action': 'verify', 'code': smtp.last_code()})

    def add_txn(cl, tid, amt):
        cl.post('/transactions.php?type=expense', {'action': 'save', 'temple_id': tid, 'category_id': cat, 'account_id': 'cash', **be_date('txn_date', day), 'amount': str(amt), 'description': 'ค่าไฟ', 'doc_no': ''}, '/transactions.php?type=expense')
        return q(f"SELECT MAX(id) FROM transactions WHERE temple_id={tid}")

    xa, xb = add_txn(ca, tA, 100), add_txn(cb, tB, 200)

    def up(cl, txn, content, fname, ctype):
        return cl.upload('/attachments.php?txn=' + str(txn), {'action': 'upload', 'txn': txn}, fname, content, '/attachments.php?txn=' + str(txn), 'file', ctype)

    # สลิปมือถือแนวตั้งสูง (สังเคราะห์) 893x1832
    slip = png_bytes(893, 1832, (20, 120, 200, 255))
    up(ca, xa, slip, 'slip ttb.png', 'image/png')
    row = q(f"SELECT CONCAT(mime,'|',filename) FROM transaction_attachments WHERE transaction_id={xa}")
    check('A1 แนบสลิป PNG แนวตั้งได้ เก็บเป็น JPEG', row.startswith('image/jpeg|') and row.endswith('.jpg'), row)
    aid = q(f"SELECT id FROM transaction_attachments WHERE transaction_id={xa}")
    code, hd, body = ca.raw('/attachment.php?id=' + aid)
    check('A2 เจ้าของเปิดไฟล์ได้ พร้อม nosniff และไม่แคช', code == 200 and hd.get('X-Content-Type-Options') == 'nosniff' and 'no-store' in hd.get('Cache-Control', '') and body[:2] == b'\xff\xd8')
    import base64
    out = subprocess.run(['php', '-r', 'echo implode("x", array_slice(getimagesizefromstring(base64_decode($argv[1])),0,2));', base64.b64encode(body).decode()], capture_output=True, text=True).stdout
    check('A3 ย่อด้านยาวไม่เกิน 2000 px และคงสัดส่วน (ไม่ถูกบีบเป็นรูปถ่าย 600x800)', out == '893x1832', out)
    big = png_bytes(2600, 1300, (200, 200, 20, 255))
    up(ca, xa, big, 'big.png', 'image/png')
    bid = q(f"SELECT MAX(id) FROM transaction_attachments WHERE transaction_id={xa}")
    _, _, body2 = ca.raw('/attachment.php?id=' + bid)
    out = subprocess.run(['php', '-r', 'echo implode("x", array_slice(getimagesizefromstring(base64_decode($argv[1])),0,2));', base64.b64encode(body2).decode()], capture_output=True, text=True).stdout
    check('A4 ภาพกว้างเกิน 2000 px ถูกย่อ', out == '2000x1000', out)

    pdf = b'%PDF-1.4\n1 0 obj<<>>endobj\ntrailer<<>>\n%%EOF\n'
    up(ca, xa, pdf, 'ใบเสร็จ.pdf', 'application/pdf')
    pid = q(f"SELECT MAX(id) FROM transaction_attachments WHERE transaction_id={xa}")
    code, hd, body = ca.raw('/attachment.php?id=' + pid)
    check('A5 PDF เก็บตามต้นฉบับ และส่งเป็น attachment', body == pdf and 'attachment' in hd.get('Content-Disposition', '') and hd.get('Content-Type') == 'application/pdf')

    n0 = q(f"SELECT COUNT(*) FROM transaction_attachments WHERE transaction_id={xa}")
    bad = [('ไฟล์ข้อความ', b'hello', 'a.png', 'image/png'), ('PHP', b'<?php system("id"); ?>', 'a.php', 'image/png'),
           ('SVG', b'<svg xmlns="http://www.w3.org/2000/svg"><script>1</script></svg>', 'a.svg', 'image/svg+xml'),
           ('PNG เสีย', png_bytes(100, 100)[:60], 'a.png', 'image/png')]
    for nm, content, fn, ct in bad:
        up(ca, xa, content, fn, ct)
    check('A6 ปฏิเสธไฟล์ที่ไม่ใช่ภาพ/PDF จริง (ข้อความ PHP SVG ไฟล์เสีย)', q(f"SELECT COUNT(*) FROM transaction_attachments WHERE transaction_id={xa}") == n0)
    up(ca, xa, png_bytes(100, 100), 'x.png', 'image/png')
    up(ca, xa, png_bytes(100, 100), 'y.png', 'image/png')
    check('A7 แนบได้ไม่เกิน 5 ไฟล์ต่อรายการ', q(f"SELECT COUNT(*) FROM transaction_attachments WHERE transaction_id={xa}") == '5')
    xl = add_txn(ca, tA, 5)
    up(ca, xl, png_bytes(64, 64, noise=True) + b'\x00' * (4 * 1024 * 1024), 'huge.png', 'image/png')
    check('A8 ไฟล์ใหญ่เกิน 4 MB ถูกปฏิเสธ', q(f"SELECT COUNT(*) FROM transaction_attachments WHERE transaction_id={xl}") == '0')

    # สิทธิ์
    c, _, _ = cb.raw('/attachment.php?id=' + aid)
    check('A9 แอดมินวัดอื่นเปิดไฟล์ไม่ได้ (403)', c == 403)
    c, _, _ = Client(base).raw('/attachment.php?id=' + aid)
    check('A10 ยังไม่ล็อกอินเปิดไม่ได้', c in (302, 401, 403))
    c, _, _ = adm.raw('/attachment.php?id=' + aid)
    check('A11 แอดมินหลักเปิดได้', c == 200)
    code, _, _ = up(cb, xa, png_bytes(100, 100), 'z.png', 'image/png')
    check('A12 วัดอื่นแนบ/เข้าหน้ารายการของวัดเรา ไม่ได้', code == 404 and q(f"SELECT COUNT(*) FROM transaction_attachments WHERE transaction_id={xa}") == '5')
    _, _, pg = ca.req('/transactions.php?type=expense')
    check('A13 หน้ารายการมีปุ่มแนบ 📎 พร้อมจำนวน', 'attachments.php?txn=' + xa in pg and '📎 5' in pg)
    pro = q("SELECT username FROM users WHERE role='province' LIMIT 1")
    if pro:
        q(f"UPDATE users SET password_hash='{h}', must_change_password=0 WHERE username='{pro}'")
        cp = Client(base)
        cp.post('/login.php', {'username': pro, 'password': PW})
        c, _, _ = cp.raw('/attachment.php?id=' + aid)
        code, _, pg = cp.req('/attachments.php?txn=' + xa)
        check('A14 เจ้าคณะจังหวัดดูได้แต่ไม่มีฟอร์มแนบ/ลบ', c == 200 and code == 200 and 'name="file"' not in pg and 'value="delete"' not in pg)
        code, _, _ = cp.post('/attachments.php?txn=' + xa, {'action': 'delete', 'txn': xa, 'aid': aid}, '/account.php')
        check('A15 เจ้าคณะจังหวัดลบไม่ได้', code == 403 and q(f"SELECT COUNT(*) FROM transaction_attachments WHERE id={aid}") == '1')

    # ลบ
    ca.post('/attachments.php?txn=' + xa, {'action': 'delete', 'txn': xa, 'aid': aid}, '/attachments.php?txn=' + xa)
    check('A16 ลบไฟล์แนบได้', q(f"SELECT COUNT(*) FROM transaction_attachments WHERE id={aid}") == '0')
    # ลบรายการ -> เปิดไฟล์ไม่ได้
    ca.post('/transactions.php?type=expense', {'action': 'delete', 'id': xa}, '/transactions.php?type=expense')
    c, _, _ = adm.raw('/attachment.php?id=' + pid)
    check('A17 รายการที่ถูกลบแล้ว เปิดไฟล์แนบไม่ได้', c == 404)

    # ปิดงวด -> ล็อก
    prev = (day - datetime.timedelta(days=1)).replace(day=1)
    yb, mb = prev.year + 543, prev.month
    xp = add_txn(ca, tA, 300) if False else None
    ca.post('/transactions.php?type=expense', {'action': 'save', 'temple_id': tA, 'category_id': cat, 'account_id': 'cash', **be_date('txn_date', prev), 'amount': '300', 'description': 'เดือนก่อน', 'doc_no': ''}, '/transactions.php?type=expense')
    xp = q(f"SELECT MAX(id) FROM transactions WHERE temple_id={tA}")
    up(ca, xp, png_bytes(100, 100), 's.png', 'image/png')
    ca.post('/statements.php', {'action': 'submit_report', 'year': yb, 'month': mb, 'temple': tA}, '/statements.php?year=%d&month=%d&temple=%s' % (yb, mb, tA))
    ca.post('/statements.php', {'action': 'close_month', 'year': yb, 'month': mb, 'temple': tA}, '/statements.php?year=%d&month=%d&temple=%s' % (yb, mb, tA))
    closed = q(f"SELECT COUNT(*) FROM monthly_reports WHERE temple_id={tA} AND closed_at IS NOT NULL")
    if closed == '1':
        up(ca, xp, png_bytes(100, 100), 't.png', 'image/png')
        check('A18 เดือนที่ปิดงวด แนบเพิ่มไม่ได้', q(f"SELECT COUNT(*) FROM transaction_attachments WHERE transaction_id={xp}") == '1')
        pid2 = q(f"SELECT id FROM transaction_attachments WHERE transaction_id={xp}")
        ca.post('/attachments.php?txn=' + xp, {'action': 'delete', 'txn': xp, 'aid': pid2}, '/attachments.php?txn=' + xp)
        check('A19 เดือนที่ปิดงวด ลบไฟล์แนบไม่ได้ แต่ยังเปิดดูได้', q(f"SELECT COUNT(*) FROM transaction_attachments WHERE id={pid2}") == '1' and ca.raw('/attachment.php?id=' + pid2)[0] == 200)
    else:
        check('A18 ปิดงวดเพื่อทดสอบล็อกไม่สำเร็จ (เตรียมข้อมูลผิด)', False, closed)

    # migration ยังไม่รัน -> ซ่อนฟีเจอร์ ไม่พัง
    q('DROP TABLE transaction_attachments')
    _, _, pg = ca.req('/transactions.php?type=expense')
    c1, _, _ = ca.req('/attachments.php?txn=' + xb)
    c2, _, _ = ca.raw('/attachment.php?id=1')
    check('A20 ไม่มีตาราง: หน้ารายการยังใช้ได้ ไม่มีปุ่ม 📎 และหน้าแนบไม่พัง', '📎' not in pg and 'ค่าไฟ' in pg and c1 in (302, 303) and c2 == 404)
