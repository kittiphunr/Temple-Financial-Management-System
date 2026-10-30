"""ชุดที่ 18: ทะเบียนทรัพย์สิน — เพิ่ม/แก้/จำหน่าย รูป ผูกรายจ่ายซื้อ-ซ่อม สิทธิ์ ส่งออก CSV และ migration ที่ยังไม่รัน

ใช้ภาพสังเคราะห์เท่านั้น
"""
import datetime
import re
from urllib.parse import quote, urlencode

from support import App, Client, FakeSMTP, be_date, hash_password, notice, png_bytes

PW = 'Wat12345pw!'


def run(db, R):
    smtp = FakeSMTP()
    name = db.new_database('assets')
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
    check('H0 ตารางทรัพย์สินและคอลัมน์ asset_id/asset_kind ถูกสร้างพร้อมการติดตั้ง',
          q("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name='assets'") == '1'
          and q("SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='transactions' AND column_name IN ('asset_id','asset_kind')") == '2')

    tA, tB = q("SELECT id FROM temples ORDER BY id LIMIT 1"), q("SELECT id FROM temples ORDER BY id LIMIT 1 OFFSET 1")
    cex = q("SELECT id FROM categories WHERE type='expense' AND is_transfer=0 ORDER BY id LIMIT 1")
    cin = q("SELECT id FROM categories WHERE type='income' AND is_transfer=0 ORDER BY id LIMIT 1")
    h = hash_password(app, PW)
    q(f"UPDATE users SET password_hash='{h}', must_change_password=0 WHERE role IN ('temple','province')")
    for t, em in ((tA, 'tA@example.org'), (tB, 'tB@example.org')):
        q(f"UPDATE temples SET email='{em}' WHERE id={t}")
    ca, cb = Client(base), Client(base)
    for c, t in ((ca, tA), (cb, tB)):
        c.post('/login.php', {'username': q(f"SELECT username FROM users WHERE temple_id={t}"), 'password': PW})
        c.post('/login_verify.php', {'action': 'verify', 'code': smtp.last_code()})
    today = datetime.date.today()

    def add(cl, name='เครื่องเสียง', cat='equipment', no='', value='12,000', photo=None, aid='', extra=None, temple=None):
        f = {'action': 'save', 'asset_id': aid, 'name': name, 'category': cat, 'asset_no': no, 'value': value, 'status': 'good', 'location': 'ศาลา', 'holder': 'ไวยาวัจกร',
             'detail': 'ยี่ห้อทดสอบ', 'acquired_how': 'ซื้อ', **be_date('acquired_date', today.replace(year=today.year - 2)), **(extra or {})}
        if temple:
            f['temple'] = temple
        page = '/assets.php?new=1' + (f'&temple={temple}' if temple else '')
        if photo is not None:
            return cl.upload('/assets.php', f, 'a.png', photo, page, 'photo', 'image/png')
        return cl.upload('/assets.php', f, None, b'', page, 'photo', 'image/png')

    add(ca, no='ท-001')
    aid = q(f"SELECT id FROM assets WHERE temple_id={tA} AND asset_no='ท-001'")
    check('H1 เพิ่มทรัพย์สินได้ และบันทึกมูลค่า 12,000 วันที่ได้มา', aid != '' and q(f"SELECT value FROM assets WHERE id={aid}") == '12000.00' and q(f"SELECT acquired_date IS NOT NULL FROM assets WHERE id={aid}") == '1')
    _, _, pg = ca.req('/assets.php')
    check('H2 หน้าทะเบียนแสดงทรัพย์สินและยอดรวมตามประเภท', 'เครื่องเสียง' in pg and 'ท-001' in pg and '12,000.00' in pg and 'ครุภัณฑ์' in pg)

    n0 = q(f"SELECT COUNT(*) FROM assets WHERE temple_id={tA}")
    add(ca, name='  ')
    add(ca, name='ผิดประเภท', cat='hack')
    add(ca, name='มูลค่าผิด', value='abc')
    add(ca, name='มูลค่าติดลบ', value='-5')
    add(ca, name='เลขซ้ำ', no='ท-001')
    check('H3 ชื่อว่าง/ประเภทผิด/มูลค่าผิด/เลขทะเบียนซ้ำ ถูกปฏิเสธ', q(f"SELECT COUNT(*) FROM assets WHERE temple_id={tA}") == n0)
    add(ca, name='ไม่มีวันที่', extra={'acquired_date_d': '', 'acquired_date_m': '', 'acquired_date_y': ''})
    check('H4 ไม่ระบุวันที่ได้มา/เลขทะเบียน/มูลค่า ได้ (เป็น NULL และ 0)', q(f"SELECT CONCAT(acquired_date IS NULL, asset_no IS NULL) FROM assets WHERE name='ไม่มีวันที่'") == '11')

    # รูป
    add(ca, name='อุโบสถ', cat='building', no='ท-002', value='5,000,000', photo=png_bytes(300, 200))
    bid = q(f"SELECT id FROM assets WHERE asset_no='ท-002'")
    mid = q(f"SELECT photo_media_id FROM assets WHERE id={bid}")
    code, hd, body = ca.raw(f'/media.php?id={mid}')
    code_b, _, _ = cb.raw(f'/media.php?id={mid}')
    code_n, _, _ = Client(base).raw(f'/media.php?id={mid}')
    check('H5 แนบรูปทรัพย์สิน: เจ้าของเปิดได้ (JPEG) วัดอื่นเปิดไม่ได้ (403) ไม่ล็อกอินเปิดไม่ได้', code == 200 and body[:2] == b'\xff\xd8' and code_b == 403 and code_n in (302, 401, 403))
    add(ca, name='อุโบสถ', cat='building', no='ท-002', value='5000000', photo=png_bytes(100, 100, (1, 2, 3, 255)), aid=bid)
    mid2 = q(f"SELECT photo_media_id FROM assets WHERE id={bid}")
    check('H6 เปลี่ยนรูป: รูปเดิมถูกลบออกจากฐานข้อมูล', mid2 != mid and q(f"SELECT COUNT(*) FROM media WHERE id={mid}") == '0')
    add(ca, name='อุโบสถ', cat='building', no='ท-002', value='5000000', aid=bid, extra={'remove_photo': '1'})
    check('H7 ลบรูป: photo_media_id เป็น NULL และรูปหายจาก media', q(f"SELECT photo_media_id IS NULL FROM assets WHERE id={bid}") == '1' and q(f"SELECT COUNT(*) FROM media WHERE id={mid2}") == '0')
    add(ca, name='รูปปลอม', photo=b'<?php system("id"); ?>')
    check('H8 ไฟล์รูปปลอมถูกปฏิเสธและไม่สร้างทรัพย์สิน', q("SELECT COUNT(*) FROM assets WHERE name='รูปปลอม'") == '0')

    # แก้ไขสภาพ
    add(ca, aid=aid, no='ท-001', extra={'status': 'poor', 'name': 'เครื่องเสียงชุดใหญ่'})
    _, _, pg = ca.req('/assets.php')
    check('H9 แก้ไขชื่อ/สภาพ: แสดง "ชำรุด/ต้องซ่อม"', 'เครื่องเสียงชุดใหญ่' in pg and 'ชำรุด/ต้องซ่อม' in pg)

    # ผูกรายจ่าย
    def exp(cl, tid, amt, asset='0', kind='repair', typ='expense', date=today, desc='ซ่อม'):
        cl.post(f'/transactions.php?type={typ}', {'action': 'save', 'temple_id': tid, 'category_id': cex if typ == 'expense' else cin, 'account_id': 'cash', 'asset_id': asset, 'asset_kind': kind, **be_date('txn_date', date), 'amount': str(amt), 'description': desc, 'doc_no': ''}, f'/transactions.php?type={typ}')
        return q(f"SELECT COALESCE(MAX(id),0) FROM transactions WHERE temple_id={tid} AND is_deleted=0")
    code, _, pg = ca.req('/transactions.php?type=expense')
    check('H10 ฟอร์มรายจ่ายมีช่องทรัพย์สินพร้อมรายการทรัพย์สินของวัด แต่ฟอร์มรายรับไม่มี', 'name="asset_id"' in pg and 'เครื่องเสียงชุดใหญ่' in pg and 'name="asset_id"' not in ca.req('/transactions.php?type=income')[2])
    x1 = exp(ca, tA, 2500, aid, 'repair')
    x2 = exp(ca, tA, 12000, aid, 'purchase', desc='ซื้อ')
    x3 = exp(ca, tA, 700, aid, 'repair')
    check('H11 บันทึกรายจ่ายผูกทรัพย์สิน (ซ่อม/ซื้อ)', q(f"SELECT GROUP_CONCAT(asset_kind ORDER BY id) FROM transactions WHERE asset_id={aid}") == 'repair,purchase,repair')
    _, _, pg = ca.req(f'/assets.php?asset={aid}')
    check('H12 หน้ารายละเอียดรวมซื้อ 12,000 / ซ่อม 3,200', '12,000.00' in pg and '3,200.00' in pg)
    ca.post('/transactions.php?type=expense', {'action': 'delete', 'id': x3}, '/transactions.php?type=expense')
    _, _, pg = ca.req(f'/assets.php?asset={aid}')
    check('H13 รายจ่ายที่ลบแล้วไม่นับ (ซ่อมเหลือ 2,500)', '2,500.00' in pg and '3,200.00' not in pg)
    _, _, pg = ca.req('/transactions.php?type=expense')
    check('H14 รายการรายจ่ายแสดงชื่อทรัพย์สินและประเภท (ซ่อมบำรุง)', '🛠️ เครื่องเสียงชุดใหญ่' in pg and 'ซ่อมบำรุง' in pg)

    # ทรัพย์สินวัดอื่น / รายรับ / จำหน่ายแล้ว
    add(cb, name='ของวัดอื่น', no='ข-001')
    ab = q(f"SELECT id FROM assets WHERE temple_id={tB}")
    n1 = q(f"SELECT COUNT(*) FROM transactions WHERE temple_id={tA}")
    exp(ca, tA, 50, ab)
    check('H15 ผูกทรัพย์สินของวัดอื่นกับรายจ่ายของเราไม่ได้', q(f"SELECT COUNT(*) FROM transactions WHERE temple_id={tA}") == n1)
    xi = exp(ca, tA, 60, aid, typ='income')
    check('H16 รายรับไม่ผูกทรัพย์สิน (ค่า asset_id ถูกเมินเฉย)', q(f"SELECT asset_id IS NULL FROM transactions WHERE id={xi}") == '1')

    def dispose(cl, asset, note='ขายทอดตลาด', temple=None):
        data = {'action': 'dispose', 'asset_id': asset, 'disposed_note': note, **be_date('disposed_date', today)}
        if temple:
            data['temple'] = temple
        cl.post('/assets.php' + (f'?temple={temple}' if temple else ''), data, f'/assets.php?asset={asset}')
    dispose(ca, aid, 'x')
    check('H17 จำหน่ายต้องมีเหตุผลอย่างน้อย 3 ตัวอักษร', q(f"SELECT status FROM assets WHERE id={aid}") == 'poor')
    dispose(ca, aid)
    check('H18 จำหน่ายทรัพย์สิน: สถานะ disposed พร้อมวันที่/เหตุผล', q(f"SELECT CONCAT(status,'|',disposed_note) FROM assets WHERE id={aid}") == 'disposed|ขายทอดตลาด')
    n2 = q(f"SELECT COUNT(*) FROM transactions WHERE temple_id={tA}")
    exp(ca, tA, 80, aid)
    check('H19 ทรัพย์สินที่จำหน่ายแล้วผูกรายจ่ายใหม่ไม่ได้ แต่แก้รายการเดิมที่ผูกไว้ได้',
          q(f"SELECT COUNT(*) FROM transactions WHERE temple_id={tA}") == n2)
    ca.post('/transactions.php?type=expense', {'action': 'save', 'id': x1, 'temple_id': tA, 'category_id': cex, 'account_id': 'cash', 'asset_id': aid, 'asset_kind': 'repair', **be_date('txn_date', today), 'amount': '2600', 'description': 'แก้ไข', 'doc_no': ''}, '/transactions.php?type=expense')
    check('H20 แก้รายการเดิมที่ผูกทรัพย์สินจำหน่ายแล้ว ยังบันทึกได้', q(f"SELECT CONCAT(amount,'|',asset_id) FROM transactions WHERE id={x1}") == f'2600.00|{aid}')
    _, _, pg = ca.req(f'/assets.php?asset={aid}')
    check('H21 หน้ารายละเอียดแสดงข้อความจำหน่ายแล้วและปุ่มยกเลิกการจำหน่าย', 'จำหน่ายแล้วเมื่อ' in pg and 'ขายทอดตลาด' in pg and 'value="restore"' in pg)
    ca.post('/assets.php', {'action': 'restore', 'asset_id': aid}, f'/assets.php?asset={aid}')
    check('H22 ยกเลิกการจำหน่ายได้', q(f"SELECT CONCAT(status,'|',disposed_note IS NULL) FROM assets WHERE id={aid}") == 'good|1')

    # ตัวกรอง/ค้นหา/CSV
    _, _, pg = ca.req('/assets.php?category=building')
    check('H23 กรองตามประเภท: เห็นอุโบสถ ไม่เห็นเครื่องเสียง', 'อุโบสถ' in pg and 'เครื่องเสียงชุดใหญ่' not in pg)
    _, _, pg = ca.req('/assets.php?q=' + quote('%'))
    check('H24 ค้นหาด้วย % ไม่ถูกตีเป็น wildcard (ไม่พบรายการ)', 'ยังไม่มีทรัพย์สินในทะเบียน' in pg)
    add(ca, name='=HYPERLINK("http://x")', no='ท-009')
    code, hd, body = ca.raw('/assets.php?export=csv')
    txt = body.decode('utf-8-sig')
    check('H25 ส่งออก CSV มีข้อมูลและกัน formula injection', code == 200 and 'ท-001' in txt and "'=HYPERLINK" in txt and '\n=HYPERLINK' not in txt and 'csv' in hd.get('Content-Type', ''))

    # สิทธิ์
    _, _, pgb = cb.req(f'/assets.php?temple={tA}')
    check('H26 แอดมินวัดอื่นเห็นเฉพาะทรัพย์สินของวัดตัวเอง', 'ของวัดอื่น' in pgb and 'ท-001' not in pgb)
    code_ro, _, _ = cb.req(f'/assets.php?asset={aid}')
    check('H27 แอดมินวัดอื่นเปิดรายละเอียดทรัพย์สินของวัดเราไม่ได้ (ไม่แสดงข้อมูล)', 'เครื่องเสียงชุดใหญ่' not in cb.req(f'/assets.php?asset={aid}')[2])
    pro = q("SELECT username FROM users WHERE role='province' LIMIT 1")
    if pro:
        cp = Client(base)
        cp.post('/login.php', {'username': pro, 'password': PW})
        _, _, pgp = cp.req(f'/assets.php?temple={tA}')
        cpm, _, _ = cp.raw(f'/media.php?id={q(f"SELECT photo_media_id FROM assets WHERE id={bid}") or 0}')
        tok = cp.csrf('/account.php')
        code, _, _ = cp.req('/assets.php', raw=urlencode({'csrf': tok, 'action': 'save', 'temple': tA, 'name': 'แอบเพิ่ม', 'category': 'other'}).encode(), headers={'Content-Type': 'application/x-www-form-urlencoded'})
        check('H28 เจ้าคณะจังหวัดดูทะเบียนได้แต่เพิ่มไม่ได้ (403 และไม่มีปุ่มเพิ่ม)', 'ท-001' in pgp and 'เพิ่มทรัพย์สิน' not in pgp and code == 403 and q("SELECT COUNT(*) FROM assets WHERE name='แอบเพิ่ม'") == '0')
        add(ca, name='มีรูปให้จังหวัดดู', no='ท-010', photo=png_bytes(120, 120, (9, 9, 9, 255)))
        pm = q("SELECT photo_media_id FROM assets WHERE asset_no='ท-010'")
        check('H29 เจ้าคณะจังหวัดเปิดรูปทรัพย์สินได้ (ดูอย่างเดียว)', cp.raw(f'/media.php?id={pm}')[0] == 200)

    # migration ยังไม่รัน
    q('ALTER TABLE transactions DROP FOREIGN KEY fk_txn_asset')
    q('ALTER TABLE transactions DROP COLUMN asset_kind, DROP COLUMN asset_id')
    q('UPDATE assets SET photo_media_id=NULL')
    q('DROP TABLE assets')
    n3 = int(q(f"SELECT COUNT(*) FROM transactions WHERE temple_id={tA}"))
    code, _, pg = ca.req('/transactions.php?type=expense')
    exp(ca, tA, 5)
    c2, _, _ = ca.req('/assets.php')
    check('H30 ไม่มีตาราง: บันทึกรายจ่ายยังได้ ไม่มีช่องทรัพย์สิน และหน้าทะเบียน redirect',
          code == 200 and 'name="asset_id"' not in pg and int(q(f"SELECT COUNT(*) FROM transactions WHERE temple_id={tA}")) == n3 + 1 and c2 in (302, 303))
