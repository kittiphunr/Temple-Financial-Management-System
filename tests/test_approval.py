"""ชุดที่ 14: รับรองรายงานประจำเดือนเป็นลำดับชั้น — วัดส่ง → เจ้าคณะตำบลรับรอง → อำเภอรับรอง · ส่งกลับแก้ไขพร้อมเหตุผล

ตรวจ: ลำดับขั้น (อำเภอข้ามตำบลไม่ได้เมื่อมีเจ้าคณะตำบล) · ส่งกลับล้างการรับรอง/เปิดงวด/แจ้งอีเมล · ส่งใหม่เริ่มรับรองใหม่
      แก้ไขรายการหลังรับรอง = รับรองต่อไม่ได้ · สิทธิ์ (จังหวัด/ตำบลอื่น/อำเภออื่นทำไม่ได้) · ฐานข้อมูลที่ยังไม่รัน migration
"""
import datetime
import re
import subprocess

from support import App, Client, FakeSMTP

PW = 'Wat12345pw!'


def run(db, R):
    smtp = FakeSMTP()
    name = db.new_database('rappr')
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
    check('R0 คอลัมน์/ตารางรับรองรายงานถูกสร้างพร้อมการติดตั้ง',
          q("SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='monthly_reports' AND column_name IN ('sub_approved_at','sub_approved_by','dist_approved_at','dist_approved_by','returned_at','returned_by','return_reason')") == '7'
          and q("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name='report_review_events'") == '1')

    d3 = _months_ago(3)
    y3, m3 = d3.year + 543, d3.month
    first = d3.isoformat()
    cat_in = q("SELECT id FROM categories WHERE type='income' AND is_transfer=0 LIMIT 1")
    rows = [r.split('\t') for r in q("SELECT district_id, subdistrict, COUNT(*) c FROM temples WHERE subdistrict<>'' GROUP BY district_id, subdistrict HAVING c>=2 ORDER BY district_id, subdistrict").split('\n')]
    dA, subA = rows[0][0], rows[0][1]
    tA = q(f"SELECT id FROM temples WHERE district_id={dA} AND subdistrict='{subA}' ORDER BY id LIMIT 1")
    tB = q(f"SELECT id FROM temples WHERE district_id={dA} AND subdistrict='{subA}' ORDER BY id LIMIT 1 OFFSET 1")
    dC, subC = next((r[0], r[1]) for r in rows if r[0] != dA)
    tC = q(f"SELECT id FROM temples WHERE district_id={dC} AND subdistrict='{subC}' ORDER BY id LIMIT 1")
    for t in (tA, tB, tC):
        q(f"INSERT INTO transactions (temple_id,type,category_id,txn_date,description,amount) VALUES ({t},'income',{cat_in},'{first}','ทดสอบ',1000)")
    q(f"UPDATE temples SET email='tA@example.org' WHERE id={tA}")

    h = subprocess.run(['php', '-r', f"echo password_hash('{PW}', PASSWORD_DEFAULT);"], capture_output=True, text=True).stdout

    def staff_login(username):
        uid = q(f"SELECT id FROM users WHERE username='{username}'")
        q(f"UPDATE users SET password_hash='{h}', must_change_password=0, email='{username}@example.org' WHERE id={uid}")
        cl = Client(base)
        c, loc, _ = cl.post('/login.php', {'username': username, 'password': PW})
        assert loc == 'index.php', (username, c, loc)
        return cl

    page = lambda t: f'/statements.php?temple={t}&year={y3}&month={m3}&form=1'
    act = lambda cl, t, action, **kw: cl.post(page(t), {'action': action, 'temple': t, 'year': y3, 'month': m3, **kw}, page(t))
    rep = lambda t, col: q(f"SELECT {col} FROM monthly_reports WHERE temple_id={t} AND report_year={y3} AND report_month={m3}")
    events = lambda t, a='': int(q(f"SELECT COUNT(*) FROM report_review_events WHERE temple_id={t}" + (f" AND action='{a}'" if a else '')))

    dcode = q(f"SELECT code FROM districts WHERE id={dA}")
    dist = staff_login('amphoe' + dcode)
    sub_user = q(f"SELECT u.username FROM users u JOIN user_subdistricts s ON s.user_id=u.id WHERE s.district_id={dA} AND s.subdistrict='{subA}' LIMIT 1")
    sd = staff_login(sub_user)
    sub_other = q(f"SELECT u.username FROM users u JOIN user_subdistricts s ON s.user_id=u.id WHERE s.district_id={dC} AND s.subdistrict='{subC}' LIMIT 1")
    sdC = staff_login(sub_other)
    prov = staff_login('province')

    # ---- A: ยังไม่ส่ง → รับรองไม่ได้ · ส่งแล้วตามลำดับขั้น
    act(sd, tA, 'approve_report')
    check('A1 วัดยังไม่ส่งรายงาน → เจ้าคณะตำบลรับรองไม่ได้ (ไม่เกิดแถว)', rep(tA, 'COUNT(*)') == '0' and events(tA) == 0)
    act(adm, tA, 'submit_report', note='')
    act(adm, tB, 'submit_report', note='')
    c, _, b = sd.req(page(tA))
    check('A2 เจ้าคณะตำบลเห็นสถานะ "รอรับรอง" พร้อมปุ่มรับรองและส่งกลับ', c == 200 and 'รอรับรอง' in txt(b) and 'รับรองรายงาน' in txt(b) and 'ส่งกลับแก้ไข' in txt(b), txt(b)[:200])
    c, _, b = dist.req(page(tA))
    check('A3 แอดมินอำเภอเห็นว่ายังรับรองไม่ได้ (รอเจ้าคณะตำบลก่อน) แต่ส่งกลับได้', 'ต้องให้เจ้าคณะ' in txt(b) and 'name="action" value="approve_report"' not in b and 'value="return_report"' in b)
    act(dist, tA, 'approve_report')
    check('A4 อำเภอข้ามขั้นตำบลไม่ได้เมื่อตำบลมีเจ้าคณะตำบล', rep(tA, 'dist_approved_at IS NULL') == '1' and events(tA) == 0)
    act(sd, tA, 'approve_report')
    check('A5 เจ้าคณะตำบลรับรอง → บันทึกเวลา/ผู้รับรอง/ประวัติ',
          rep(tA, 'sub_approved_at IS NOT NULL') == '1' and rep(tA, 'sub_approved_by') == q(f"SELECT id FROM users WHERE username='{sub_user}'") and events(tA, 'sub_approve') == 1)
    act(sd, tA, 'approve_report')
    check('A6 กดรับรองซ้ำไม่เกิดประวัติซ้ำ', events(tA, 'sub_approve') == 1)
    c, _, b = dist.req(page(tA))
    check('A7 อำเภอเห็น "ตำบลรับรองแล้ว รออำเภอ" และมีปุ่มรับรอง', 'ตำบลรับรองแล้ว รออำเภอ' in txt(b) and 'name="action" value="approve_report"' in b)
    act(dist, tA, 'approve_report')
    check('A8 อำเภอรับรอง → ครบทั้งสองขั้น', rep(tA, 'dist_approved_at IS NOT NULL') == '1' and events(tA, 'dist_approve') == 1)
    c, _, b = adm.req(page(tA))
    check('A9 หน้ารายงานแสดง "อำเภอรับรองแล้ว" และประวัติการรับรอง', 'อำเภอรับรองแล้ว' in txt(b) and 'ประวัติการรับรอง/ส่งกลับ (2)' in txt(b))

    # ---- B: ส่งกลับ
    act(sd, tA, 'return_report', reason='ตรวจแล้วควรแก้')
    check('B1 อำเภอรับรองแล้ว เจ้าคณะตำบลส่งกลับไม่ได้', rep(tA, 'returned_at IS NULL') == '1' and events(tA, 'return') == 0)
    act(dist, tA, 'return_report', reason='สั้น')
    check('B2 เหตุผลสั้นกว่า 5 ตัวอักษร → ส่งกลับไม่ได้', rep(tA, 'returned_at IS NULL') == '1')
    n_mail = len(smtp.messages)
    act(dist, tA, 'return_report', reason='ยอดรายรับไม่ตรงใบเสร็จ')
    check('B3 อำเภอส่งกลับ → ล้างการรับรองทั้งสองขั้น บันทึกเหตุผล/ผู้ส่งกลับ',
          rep(tA, 'returned_at IS NOT NULL') == '1' and rep(tA, 'sub_approved_at IS NULL AND dist_approved_at IS NULL') == '1'
          and rep(tA, 'return_reason') == 'ยอดรายรับไม่ตรงใบเสร็จ' and events(tA, 'return') == 1)
    check('B4 แจ้งอีเมลวัดว่าถูกส่งกลับ พร้อมเหตุผล', len(smtp.messages) == n_mail + 1 and 'ยอดรายรับไม่ตรงใบเสร็จ' in smtp.messages[-1]['body'] and 'tA@example.org' in str(smtp.messages[-1]), str(smtp.messages[-1])[:200] if smtp.messages else '')
    act(sd, tA, 'approve_report')
    act(dist, tA, 'approve_report')
    check('B5 รายงานที่ถูกส่งกลับ รับรองไม่ได้จนกว่าวัดจะส่งใหม่', rep(tA, 'sub_approved_at IS NULL AND dist_approved_at IS NULL') == '1')
    c, _, b = adm.req(f'/report_status.php?year={y3}&month={m3}&status=returned')
    check('B6 หน้าติดตามรายงาน: กรอง "ส่งกลับแก้ไข" เห็นวัดนี้พร้อมเหตุผล', c == 200 and 'ยอดรายรับไม่ตรงใบเสร็จ' in b and 'ส่งกลับแก้ไข' in b, txt(b)[:200])
    act(dist, tA, 'return_report', reason='ส่งกลับซ้ำอีกครั้ง')
    check('B7 ส่งกลับซ้ำขณะรอวัดแก้ ไม่เกิดประวัติซ้ำ', events(tA, 'return') == 1)
    act(adm, tA, 'submit_report', note='')
    check('B8 วัดส่งใหม่ → ล้างสถานะส่งกลับ เริ่มรับรองใหม่ ครั้งที่ 2 ประวัติเดิมยังอยู่',
          rep(tA, 'returned_at IS NULL AND return_reason IS NULL AND revision=2') == '1' and events(tA) == 3)
    act(sd, tA, 'approve_report')
    check('B9 รับรองรอบใหม่ได้ (ตำบล) ประวัติระบุครั้งที่ส่ง 2', rep(tA, 'sub_approved_at IS NOT NULL') == '1' and q(f"SELECT revision FROM report_review_events WHERE temple_id={tA} AND action='sub_approve' ORDER BY id DESC LIMIT 1") == '2')

    # ---- C: แก้ไขรายการหลังส่ง → รับรองต่อไม่ได้
    q(f"INSERT INTO transactions (temple_id,type,category_id,txn_date,description,amount) VALUES ({tA},'income',{cat_in},'{first}','เพิ่มหลังรับรอง',50)")
    act(dist, tA, 'approve_report')
    check('C1 มีรายการเปลี่ยนหลังส่ง → อำเภอรับรองไม่ได้', rep(tA, 'dist_approved_at IS NULL') == '1')
    c, _, b = dist.req(page(tA))
    check('C2 แสดง "แก้ไขหลังรับรอง"', 'แก้ไขหลังรับรอง' in txt(b))
    act(adm, tA, 'submit_report', note='')
    check('C3 วัดส่งใหม่ → การรับรองตำบลของรอบก่อนหมดผล', rep(tA, 'sub_approved_at IS NULL AND revision=3') == '1')

    # ---- D: ปิดงวดแล้วถูกส่งกลับ → เปิดงวดให้อัตโนมัติ
    act(sd, tA, 'approve_report')
    act(dist, tA, 'approve_report')
    act(adm, tA, 'close_month')
    check('D1 ปิดงวดหลังรับรองครบ', rep(tA, 'closed_at IS NOT NULL') == '1' and rep(tA, 'dist_approved_at IS NOT NULL') == '1')
    act(dist, tA, 'return_report', reason='พบรายการซ้ำสองบรรทัด')
    check('D2 ส่งกลับขณะปิดงวด → เปิดงวดอัตโนมัติ พร้อมประวัติเหตุผล',
          rep(tA, 'closed_at IS NULL AND closed_by IS NULL') == '1'
          and q(f"SELECT COUNT(*) FROM month_close_events WHERE temple_id={tA} AND action='reopen' AND reason LIKE 'ส่งกลับแก้ไข:%พบรายการซ้ำ%'") == '1')
    act(adm, tA, 'submit_report', note='')

    # ---- E: สิทธิ์
    act(adm, tB, 'submit_report', note='')   # ตรวจว่า tB ส่งแล้ว (ส่งซ้ำได้ ยังไม่ปิดงวด)
    base_ev = events(tB)
    act(sdC, tB, 'approve_report')
    check('E1 เจ้าคณะตำบลอื่น (คนละตำบล) รับรองรายงานวัดนี้ไม่ได้', events(tB) == base_ev and rep(tB, 'sub_approved_at IS NULL') == '1')
    act(sdC, tB, 'return_report', reason='ไม่มีสิทธิ์แต่ลอง')
    check('E2 เจ้าคณะตำบลอื่นส่งกลับไม่ได้', rep(tB, 'returned_at IS NULL') == '1')
    act(prov, tB, 'approve_report')
    act(prov, tB, 'return_report', reason='จังหวัดลองส่งกลับ')
    check('E3 เจ้าคณะจังหวัดดูอย่างเดียว รับรอง/ส่งกลับไม่ได้', rep(tB, 'sub_approved_at IS NULL AND returned_at IS NULL') == '1')
    c, _, b = prov.req(page(tB))
    check('E4 เจ้าคณะจังหวัดเห็นสถานะ แต่ไม่มีปุ่มรับรอง/ส่งกลับ', c == 200 and 'รอรับรอง' in txt(b) and 'value="approve_report"' not in b and 'value="return_report"' not in b)
    act(adm, tB, 'approve_report')
    check('E5 แอดมินหลักรับรองในนามอำเภอได้ (ข้ามขั้นตำบลได้)', rep(tB, 'dist_approved_at IS NOT NULL AND sub_approved_at IS NULL') == '1')

    # ---- F: ตำบลที่ไม่มีเจ้าคณะตำบล อำเภอรับรองตรง ๆ ได้ · อำเภออื่นรับรองไม่ได้
    act(adm, tC, 'submit_report', note='')
    q(f"UPDATE users SET is_active=0 WHERE username='{sub_other}'")
    distC = staff_login('amphoe' + q(f"SELECT code FROM districts WHERE id={dC}"))
    act(dist, tC, 'approve_report')
    check('F1 แอดมินอำเภออื่นรับรองวัดนอกอำเภอตนไม่ได้', rep(tC, 'dist_approved_at IS NULL') == '1')
    act(distC, tC, 'approve_report')
    check('F2 ตำบลไม่มีเจ้าคณะตำบลที่ใช้งานอยู่ → อำเภอรับรองได้เลย', rep(tC, 'dist_approved_at IS NOT NULL AND sub_approved_at IS NULL') == '1')

    # ---- G: หน้าติดตามรายงาน
    c, _, b = adm.req(f'/report_status.php?year={y3}&month={m3}')
    check('G1 หน้าติดตามรายงานมีตัวกรองและการ์ดสรุปการรับรอง', c == 200 and 'value="awaiting"' in b and 'รอรับรอง' in b and 'อำเภอรับรองแล้ว' in b and 'การรับรอง' in b)
    c, _, b = adm.req(f'/report_status.php?year={y3}&month={m3}&status=approved')
    check('G2 กรอง "อำเภอรับรองแล้ว" ได้ 2 วัด (B, C)', c == 200 and b.count('class="badge badge-ok">อำเภอรับรองแล้ว') == 2, str(b.count('อำเภอรับรองแล้ว')))
    c, _, b = adm.req(f'/report_status.php?year={y3}&month={m3}&status=awaiting')
    check('G3 กรอง "รอรับรอง" เห็นวัด A', c == 200 and f'temple={tA}&' in b and f'temple={tB}&' not in b)
    c, _, csvb = adm.req(f'/report_status.php?year={y3}&month={m3}&export=csv')
    check('G4 CSV มีคอลัมน์ การรับรอง', c == 200 and 'การรับรอง' in csvb.splitlines()[0] and 'อำเภอรับรองแล้ว' in csvb)
    check('G5 บันทึก audit การรับรอง/ส่งกลับ', int(q("SELECT COUNT(*) FROM audit_log WHERE action='report_approve'")) >= 6 and int(q("SELECT COUNT(*) FROM audit_log WHERE action='report_return'")) >= 2)

    # ---- H: ฐานข้อมูลที่ยังไม่รัน migration → ระบบทำงานแบบเดิม แล้วรัน migration ตามขั้นตอน UPGRADE ได้
    q('ALTER TABLE monthly_reports DROP FOREIGN KEY fk_report_sub_appr, DROP FOREIGN KEY fk_report_dist_appr, DROP FOREIGN KEY fk_report_returner, '
      'DROP COLUMN sub_approved_at, DROP COLUMN sub_approved_by, DROP COLUMN dist_approved_at, DROP COLUMN dist_approved_by, DROP COLUMN returned_at, DROP COLUMN returned_by, DROP COLUMN return_reason')
    q('DROP TABLE report_review_events')
    c, _, b = adm.req(page(tA))
    c2, _, b2 = adm.req(f'/report_status.php?year={y3}&month={m3}')
    check('H1 ยังไม่รัน migration → หน้ารายงาน/ติดตามรายงานใช้ได้ ไม่มีปุ่มรับรอง', c == 200 and c2 == 200 and 'value="approve_report"' not in b and 'การรับรอง' not in b2)
    act(adm, tA, 'submit_report', note='')
    check('H2 ยังไม่รัน migration → ส่งรายงานใหม่ได้ตามเดิม', rep(tA, 'revision') == '5')
    mig = open(__file__.replace('tests/test_approval.py', 'sql/migrations/2026_report_approval.sql'), encoding='utf-8').read().replace('USE tfms_phrae;', '')
    q(mig)
    check('H3 รัน migration 2026_report_approval.sql ได้ และคอลัมน์/ตารางครบ',
          q("SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='monthly_reports' AND column_name IN ('sub_approved_at','dist_approved_at','returned_at','return_reason')") == '4'
          and q("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name='report_review_events'") == '1')
    act(sd, tA, 'approve_report')
    check('H4 หลังรัน migration ใช้ขั้นตอนรับรองได้ทันที', rep(tA, 'sub_approved_at IS NOT NULL') == '1')
