"""ชุดที่ 12: ติดตามการส่งรายงานประจำเดือน — วัดกดส่งรายงาน เจ้าคณะ/แอดมินดูสถานะตามขอบเขต แก้ไขหลังส่ง อีเมลเตือน กำหนดส่ง CSV

ใช้ฐานข้อมูลแพร่ 381 วัด + ผู้ใช้จังหวัด/อำเภอ/ตำบลที่ setup.php สร้างให้ (ปิด 2FA ของบุคลากรในชุดทดสอบ) ส่วนบัญชีวัดใช้รหัสยืนยันทางอีเมลจริง (SMTP ปลอม)
"""
import csv
import datetime
import re
from urllib.parse import quote

from support import App, Client, FakeSMTP, notice

STAFF_PW = 'Staff12345pw!'


def run(db, R):
    smtp = FakeSMTP()
    name = db.new_database('repstat')
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
    return d.year + 543, d.month, d


def _run(R, q, app, smtp):
    check = R.check
    p = app.php('setup.php', 'Test12345pw')
    base = app.serve()
    adm = Client(base)
    adm.post('/login.php', {'username': 'admin', 'password': 'Test12345pw'})
    check('R0 ตารางติดตามรายงานถูกสร้างพร้อมการติดตั้ง', q("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name IN ('monthly_reports','report_reminders')") == '2')

    # เดือนที่เกินกำหนดแน่นอน (3 เดือนก่อน), เดือนปัจจุบัน (ยังไม่เกินกำหนด), เดือนหน้า (ยังไม่ถึง)
    y3, m3, d3 = _months_ago(3)
    yc, mc, _ = _months_ago(0)
    yn, mn = (yc, mc + 1) if mc < 12 else (yc + 1, 1)
    first, last = d3.isoformat(), (d3.replace(day=28) + datetime.timedelta(days=4)).replace(day=1) - datetime.timedelta(days=1)
    cat_in = q("SELECT id FROM categories WHERE type='income' AND is_transfer=0 LIMIT 1")
    cat_ex = q("SELECT id FROM categories WHERE type='expense' AND is_transfer=0 LIMIT 1")

    # วัด: tA, tB อยู่ตำบลเดียวกัน (ตำบลของเจ้าคณะตำบล) · tC คนละอำเภอ
    rows = [r.split('\t') for r in q("SELECT district_id, subdistrict, COUNT(*) c FROM temples WHERE subdistrict<>'' GROUP BY district_id, subdistrict HAVING c>=2 ORDER BY district_id, subdistrict").split('\n')]
    dA, subA = rows[0][0], rows[0][1]
    tA, tB = q(f"SELECT id FROM temples WHERE district_id={dA} AND subdistrict='{subA}' ORDER BY id LIMIT 1"), q(f"SELECT id FROM temples WHERE district_id={dA} AND subdistrict='{subA}' ORDER BY id LIMIT 1 OFFSET 1")
    dC = next(r[0] for r in rows if r[0] != dA)
    tC = q(f"SELECT id FROM temples WHERE district_id={dC} ORDER BY id LIMIT 1")
    for t, amt in ((tA, 1000), (tA, 500), (tB, 700)):
        q(f"INSERT INTO transactions (temple_id,type,category_id,txn_date,description,amount) VALUES ({t},'income',{cat_in},'{first}','ทดสอบ',{amt})")
    q(f"INSERT INTO transactions (temple_id,type,category_id,txn_date,description,amount) VALUES ({tA},'expense',{cat_ex},'{first}','จ่ายทดสอบ',300)")
    # รายการที่ต้องไม่นับ: ลบแล้ว และรายการโอน
    q(f"INSERT INTO transactions (temple_id,type,category_id,txn_date,description,amount,is_deleted) VALUES ({tA},'income',{cat_in},'{first}','ลบแล้ว',9999,1)")
    q(f"INSERT INTO transactions (temple_id,type,category_id,txn_date,description,amount,transfer_id) VALUES ({tA},'income',{cat_in},'{first}','โอน',8888,1)")

    url = lambda **kw: '/report_status.php?' + '&'.join(f'{k}={quote(str(v))}' for k, v in {'year': y3, 'month': m3, **kw}.items())
    page = lambda cl, **kw: cl.req(url(**kw))
    txt = lambda b: re.sub(r'\s+', ' ', re.sub(r'<[^>]+>', ' ', b))

    # ---- A: สถานะเริ่มต้น
    c, _, b = page(adm)
    check('A1 แอดมินหลักเปิดหน้า ติดตามรายงาน ได้ เดือนที่เกินกำหนด: ส่งแล้ว 0/381 เกินกำหนด 381', c == 200 and '0 / 381 วัด' in txt(b) and re.search(r'เกินกำหนด\s*</div><div[^>]*>\s*381', b) is not None, txt(b)[:300])
    check('A2 เมนูมี "ติดตามรายงาน" สำหรับแอดมินหลัก', 'ติดตามรายงาน' in re.search(r'<nav class="hidden.*?</nav>', b, re.S).group(0))
    c, _, b = adm.req(f'/report_status.php?year={yc}&month={mc}')
    check('A3 เดือนปัจจุบัน: ยังไม่เกินกำหนด (ยังไม่ส่ง 381 เกินกำหนด 0)', c == 200 and re.search(r'เกินกำหนด\s*</div><div[^>]*>\s*0', b) is not None and re.search(r'ยังไม่ส่ง\s*</div><div[^>]*>\s*381', b) is not None, txt(b)[:200])
    c, _, b = adm.req(f'/report_status.php?year={yn}&month={mn}')
    check('A4 เดือนหน้า: สถานะ "ยังไม่ถึงเดือน" ไม่นับเป็นค้าง', c == 200 and 'ยังไม่ถึงเดือน' in b and re.search(r'ยังไม่ส่ง\s*</div><div[^>]*>\s*0', b) is not None)

    c, _, b = adm.req('/dashboard.php')
    check('A5 แดชบอร์ดมีแถบสรุปการส่งรายงานเดือนที่แล้ว พร้อมลิงก์ไปหน้าติดตาม', c == 200 and 'ติดตามรายงาน →' in b and 'ส่งแล้ว 0 จาก 381 วัด' in txt(b), txt(b)[:200])

    # ---- B: วัดส่งรายงานเอง (ต้องผ่านอีเมล 2FA)
    q(f"UPDATE temples SET email='tA@example.org' WHERE id={tA}")
    q(f"UPDATE users SET must_change_password=0 WHERE temple_id={tA}")
    tuser = q(f"SELECT username FROM users WHERE temple_id={tA}")
    # ตั้งรหัสผ่านวัดให้ล็อกอินได้
    import subprocess
    h = subprocess.run(['php', '-r', "echo password_hash('Wat12345pw!', PASSWORD_DEFAULT);"], capture_output=True, text=True).stdout
    q(f"UPDATE users SET password_hash='{h}' WHERE temple_id={tA}")
    t = Client(base)
    t.post('/login.php', {'username': tuser, 'password': 'Wat12345pw!'})
    t.post('/login_verify.php', {'action': 'verify', 'code': smtp.last_code()})
    c, _, b = t.req(f'/statements.php?year={y3}&month={m3}&form=1')
    check('B1 หน้ารายงานของวัดแสดงสถานะ "ยังไม่ส่ง" เกินกำหนด และปุ่มส่งรายงาน', c == 200 and 'เกินกำหนด' in b and 'ส่งรายงานประจำเดือน' in b, txt(b)[:200])
    t.post(f'/statements.php?year={y3}&month={m3}&form=1', {'action': 'submit_report', 'year': y3, 'month': m3, 'note': 'ตรวจแล้ว'}, f'/statements.php?year={y3}&month={m3}&form=1')
    row = q(f"SELECT CONCAT(txn_count,'|',income_total,'|',expense_total,'|',revision,'|',IFNULL(note,'')) FROM monthly_reports WHERE temple_id={tA} AND report_year={y3} AND report_month={m3}")
    check('B2 ส่งรายงานแล้วเก็บ ยอดรวม ณ ตอนส่ง (ไม่นับรายการที่ลบ/รายการโอน): 3 รายการ รายรับ 1500 รายจ่าย 300', row == '3|1500.00|300.00|1|ตรวจแล้ว', row)
    c, _, b = t.req(f'/statements.php?year={y3}&month={m3}&form=1')
    check('B3 หลังส่ง: สถานะ "ส่งแล้ว" และปุ่มเปลี่ยนเป็นส่งรายงานใหม่', 'ส่งแล้ว' in txt(b) and 'ส่งรายงานใหม่' in b)
    c, _, b = adm.req(url())
    check('B4 แอดมินเห็นวัดนั้นส่งแล้ว 1/381 · ผู้ส่งเป็นบัญชีวัด', '1 / 381 วัด' in txt(b) and 'ผู้ดูแล' in b)
    t.post(f'/statements.php?year={y3}&month={m3}&form=1', {'action': 'submit_report', 'year': y3, 'month': m3, 'note': ''}, f'/statements.php?year={y3}&month={m3}&form=1')
    check('B5 ส่งใหม่ → ครั้งที่ 2 (แถวเดียว ไม่ซ้ำ)', q(f"SELECT revision FROM monthly_reports WHERE temple_id={tA} AND report_year={y3} AND report_month={m3}") == '2' and q("SELECT COUNT(*) FROM monthly_reports") == '1')
    # แก้ไขหลังส่ง
    q(f"INSERT INTO transactions (temple_id,type,category_id,txn_date,description,amount) VALUES ({tA},'income',{cat_in},'{first}','เพิ่มหลังส่ง',250)")
    c, _, b = adm.req(url())
    check('B6 มีรายการเพิ่มหลังส่ง → สถานะ "แก้ไขหลังส่ง" (นับ 1) แสดงจำนวนตอนส่ง', 'แก้ไขหลังส่ง' in b and re.search(r'แก้ไขหลังส่ง\s*</div><div[^>]*>\s*1', b) is not None and 'ตอนส่ง 3' in txt(b))
    c, _, b = t.req(f'/statements.php?year={y3}&month={m3}&form=1')
    check('B7 วัดเห็นคำเตือนให้ตรวจแล้วส่งใหม่', 'แก้ไขหลังส่ง' in txt(b) and 'ส่งรายงานใหม่' in txt(b))
    t.post(f'/statements.php?year={y3}&month={m3}&form=1', {'action': 'submit_report', 'year': y3, 'month': m3, 'note': ''}, f'/statements.php?year={y3}&month={m3}&form=1')
    check('B8 ส่งใหม่หลังแก้ไข → กลับเป็น "ส่งแล้ว"', adm.req(url(status='changed'))[2].count('ดูรายงาน') == 0 and q(f"SELECT revision FROM monthly_reports WHERE temple_id={tA}") == '3')
    # เดือนหน้าส่งไม่ได้
    before = q('SELECT COUNT(*) FROM monthly_reports')
    t.post(f'/statements.php?year={yn}&month={mn}&form=1', {'action': 'submit_report', 'year': yn, 'month': mn, 'note': ''}, f'/statements.php?year={yn}&month={mn}&form=1')
    check('B9 เดือนที่ยังไม่ถึง ส่งรายงานไม่ได้', q('SELECT COUNT(*) FROM monthly_reports') == before)
    # วัดอื่นส่งแทนกันไม่ได้
    t.post(f'/statements.php?temple={tB}&year={y3}&month={m3}&form=1', {'action': 'submit_report', 'temple': tB, 'year': y3, 'month': m3, 'note': ''}, f'/statements.php?year={y3}&month={m3}&form=1')
    check('B10 แอดมินวัดส่งรายงานแทนวัดอื่นไม่ได้', q(f"SELECT COUNT(*) FROM monthly_reports WHERE temple_id={tB}") == '0')
    c, loc, _ = t.req('/report_status.php')
    check('B11 แอดมินวัดไม่มีหน้าติดตามรวม (ส่งกลับหน้ารายงานของตน)', c == 302 and 'statements.php' in (loc or ''))

    # ---- C: ขอบเขตของบทบาทอื่น
    def staff_login(username):
        uid = q(f"SELECT id FROM users WHERE username='{username}'")
        q(f"UPDATE users SET password_hash='{h}', must_change_password=0, email='{username}@example.org' WHERE id={uid}")
        cl = Client(base)
        c, loc, _ = cl.post('/login.php', {'username': username, 'password': 'Wat12345pw!'})
        assert loc == 'index.php', (username, c, loc)
        return cl
    dcode = q(f"SELECT code FROM districts WHERE id={dA}")
    dist = staff_login('amphoe' + dcode)
    n_d = q(f"SELECT COUNT(*) FROM temples WHERE district_id={dA} AND is_active=1")
    c, _, b = dist.req(url())
    check('C1 แอดมินอำเภอเห็นเฉพาะวัดในอำเภอตน (%s วัด)' % n_d, c == 200 and f'/ {n_d} วัด' in txt(b) and 'ผู้ใช้' not in '', txt(b)[:200])
    dist.post(f'/statements.php?temple={tB}&year={y3}&month={m3}&form=1', {'action': 'submit_report', 'temple': tB, 'year': y3, 'month': m3, 'note': 'อำเภอส่งแทน'}, f'/statements.php?temple={tB}&year={y3}&month={m3}&form=1')
    check('C2 แอดมินอำเภอส่งรายงานแทนวัดในอำเภอตนได้', q(f"SELECT COUNT(*) FROM monthly_reports WHERE temple_id={tB}") == '1')
    before = q('SELECT COUNT(*) FROM monthly_reports')
    dist.post(f'/statements.php?temple={tC}&year={y3}&month={m3}&form=1', {'action': 'submit_report', 'temple': tC, 'year': y3, 'month': m3, 'note': ''}, f'/statements.php?temple={tC}&year={y3}&month={m3}&form=1')
    check('C3 แอดมินอำเภอส่งแทนวัดนอกอำเภอไม่ได้', q('SELECT COUNT(*) FROM monthly_reports') == before)

    sub_user = q(f"SELECT u.username FROM users u JOIN user_subdistricts s ON s.user_id=u.id WHERE s.district_id={dA} AND s.subdistrict='{subA}' LIMIT 1")
    sd = staff_login(sub_user)
    n_s = q(f"SELECT COUNT(*) FROM temples WHERE district_id={dA} AND subdistrict='{subA}' AND is_active=1")
    c, _, b = sd.req(url())
    check('C4 เจ้าคณะตำบลเห็นเฉพาะวัดในตำบลที่ดูแล (%s วัด)' % n_s, c == 200 and f'/ {n_s} วัด' in txt(b), txt(b)[:200])
    check('C5 เจ้าคณะตำบลไม่มีปุ่มส่งอีเมลเตือน', 'ส่งอีเมลเตือน' not in b)
    before = q('SELECT COUNT(*) FROM monthly_reports')
    sd.post(f'/statements.php?temple={tA}&year={y3}&month={m3}&form=1', {'action': 'submit_report', 'temple': tA, 'year': y3, 'month': m3, 'note': 'x'}, f'/statements.php?temple={tA}&year={y3}&month={m3}&form=1')
    check('C6 เจ้าคณะตำบลส่งรายงานแทนวัดไม่ได้ (ดูอย่างเดียว)', q('SELECT COUNT(*) FROM monthly_reports') == before and q(f"SELECT note FROM monthly_reports WHERE temple_id={tA}") != 'x')
    sd.post(url(), {'action': 'remind', 'year': y3, 'month': m3})
    check('C7 เจ้าคณะตำบลสั่งส่งอีเมลเตือนไม่ได้', q('SELECT COUNT(*) FROM report_reminders') == '0')

    prov = staff_login('province')
    c, _, b = prov.req(url())
    check('C8 เจ้าคณะจังหวัดเห็นทั้งจังหวัด (381 วัด) แต่ไม่มีปุ่มเตือน', c == 200 and '/ 381 วัด' in txt(b) and 'ส่งอีเมลเตือน' not in b)
    before = q('SELECT COUNT(*) FROM monthly_reports')
    prov.post(f'/statements.php?temple={tC}&year={y3}&month={m3}&form=1', {'action': 'submit_report', 'temple': tC, 'year': y3, 'month': m3, 'note': ''}, f'/statements.php?temple={tC}&year={y3}&month={m3}&form=1')
    check('C9 เจ้าคณะจังหวัดส่งรายงานแทนวัดไม่ได้', q('SELECT COUNT(*) FROM monthly_reports') == before)

    # ---- D: อีเมลเตือน
    q(f"UPDATE temples SET email='tC@example.org' WHERE id={tC}")
    q(f"UPDATE temples SET email='tB2@example.org' WHERE id={tB}")   # tB ส่งแล้ว (อำเภอส่งแทน) ต้องไม่ถูกเตือน
    n0 = len(smtp.messages)
    adm.post(url(), {'action': 'remind', 'year': y3, 'month': m3, 'district': dC, 'status': 'all', 'q': ''}, url(district=dC))
    msgs = smtp.messages[n0:]
    check('D1 เตือนเฉพาะวัดที่ยังไม่ส่ง มีอีเมล ในตัวกรอง (อำเภอของ tC) → 1 ฉบับถึง tC', len(msgs) == 1 and 'tC@example.org' in msgs[0]['rcpt'][0] and 'รายงานประจำเดือน' in msgs[0]['subject'], str(msgs)[:300])
    check('D2 บันทึกว่าเตือนแล้ว 1 วัด', q('SELECT COUNT(*) FROM report_reminders') == '1')
    adm.post(url(), {'action': 'remind', 'year': y3, 'month': m3, 'district': dC, 'status': 'all', 'q': ''}, url(district=dC))
    check('D3 กดเตือนซ้ำ → ไม่ส่งซ้ำ (เดือนละครั้งต่อวัด)', len(smtp.messages) == n0 + 1)
    n1 = len(smtp.messages)
    adm.post(url(), {'action': 'remind', 'year': y3, 'month': m3, 'district': 0, 'status': 'all', 'q': ''}, url())
    got = [m['rcpt'][0] for m in smtp.messages[n1:]]
    check('D4 เตือนทั้งจังหวัด: ไม่ส่งถึงวัดที่ส่งรายงานแล้ว (tA, tB) และวัดที่เตือนแล้ว (tC)', 'tB2@example.org' not in got and 'tA@example.org' not in got and 'tC@example.org' not in got, str(got))
    c, _, b = adm.req(url(district=dC))
    check('D5 หน้ารายการแสดง "เตือนแล้ว" ของ tC และ "ไม่มีอีเมลวัด" ของวัดที่ยังไม่มีอีเมล', 'เตือนแล้ว' in b and 'ไม่มีอีเมลวัด' in b)
    dist.post(url(), {'action': 'remind', 'year': y3, 'month': m3}, url())
    check('D6 แอดมินอำเภอสั่งเตือนได้เฉพาะวัดในอำเภอตน (ไม่ส่งถึง tC)', 'tC@example.org' not in [m['rcpt'][0] for m in smtp.messages[n1:]])

    # ---- E: กำหนดส่ง / CSV / ตัวกรอง
    adm.post(url(), {'action': 'set_due', 'due_day': '40', 'year': y3, 'month': m3}, url())
    check('E1 ตั้งกำหนดส่งนอก 1–28 → ปฏิเสธ', q("SELECT COUNT(*) FROM settings WHERE k='report_due_day'") == '0')
    adm.post(url(), {'action': 'set_due', 'due_day': '5', 'year': y3, 'month': m3}, url())
    c, _, b = adm.req(url())
    due = (d3.replace(day=28) + datetime.timedelta(days=4)).replace(day=1).replace(day=5)
    check('E2 ตั้งกำหนดส่งวันที่ 5 → แสดงกำหนดส่งวันที่ 5 ของเดือนถัดไป', q("SELECT v FROM settings WHERE k='report_due_day'") == '5' and f' {due.day} ' in txt(b).split('กำหนดส่งภายใน')[1][:20] if 'กำหนดส่งภายใน' in txt(b) else False)
    dist.post(url(), {'action': 'set_due', 'due_day': '20', 'year': y3, 'month': m3}, url())
    check('E3 บัญชีที่ไม่ใช่แอดมินหลักตั้งกำหนดส่งไม่ได้', q("SELECT v FROM settings WHERE k='report_due_day'") == '5')
    c, _, csvb = adm.req(url() + '&export=csv')
    rows_csv = list(csv.reader(csvb.lstrip('﻿').splitlines()))
    check('E4 ส่งออก CSV: หัวตาราง + 381 วัด และมีสถานะ', c == 200 and len(rows_csv) == 382 and rows_csv[0][3] == 'สถานะ' and sum(1 for r in rows_csv[1:] if r[3] == 'ส่งแล้ว') == 2, str(rows_csv[:2]))
    c, _, b = adm.req(url(status='sent'))
    check('E5 กรองสถานะ "ส่งแล้ว" เหลือ 2 วัด (tA, tB)', c == 200 and b.count('ดูรายงาน') == 2)
    c, _, b = adm.req(url(q='วัด', district=dC))
    check('E6 กรองอำเภอ/ชื่อวัดได้', c == 200 and b.count('ดูรายงาน') > 0 and b.count('ดูรายงาน') <= int(q(f"SELECT COUNT(*) FROM temples WHERE district_id={dC}")))
    q(f"DELETE FROM report_reminders")
    # ---- F: ฐานข้อมูลเก่าที่ยังไม่รัน migration
    q('DROP TABLE report_reminders')
    q('DROP TABLE monthly_reports')
    c, _, b = adm.req('/index.php')
    check('F1 ยังไม่รัน migration: เมนู ติดตามรายงาน ซ่อน และหน้านี้แจ้ง 503 แทนการล้ม', c == 200 and 'ติดตามรายงาน' not in re.search(r'<nav class="hidden.*?</nav>', b, re.S).group(0) and adm.req(url())[0] == 503)
    c, _, b = adm.req(f'/statements.php?temple={tA}&year={y3}&month={m3}&form=1')
    check('F2 หน้ารายงานของวัดยังเปิดได้ตามปกติ (ไม่มีกล่องส่งรายงาน)', c == 200 and 'ส่งรายงานประจำเดือน' not in b)
