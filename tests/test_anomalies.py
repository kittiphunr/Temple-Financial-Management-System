"""ชุดที่ 18: วัดที่ควรติดตาม (anomalies.php) — ธงความผิดปกติของบัญชีตามเดือน ขอบเขตสิทธิ์ ตัวกรอง CSV

ข้อมูลสังเคราะห์ต่อวัดทดสอบ: ยอดติดลบ รายจ่ายพุ่ง (และกรณีที่ไม่ถึงเกณฑ์) รายจ่ายสูงไม่มีหลักฐาน เงินสดสูง ถูกส่งกลับ ไม่มีรายการหลายเดือน และวัดปกติที่ต้องไม่ถูกติดธง
"""
import csv
import io
import re
import subprocess
import urllib.parse

from support import App, Client

PW = 'Wat12345pw!'


def run(db, R):
    name = db.new_database('anom')
    q = db.q(name)
    app = App(env={'TFMS_DB_HOST': db.host, 'TFMS_DB_PORT': db.port, 'TFMS_DB_USER': db.user, 'TFMS_DB_PASS': db.password, 'TFMS_DB_NAME': name})
    try:
        _run(R, q, app)
    finally:
        problems = app.php_problems()
        R.check('Z1 ไม่มี PHP warning/notice/fatal ใน log ของเซิร์ฟเวอร์', not problems, '\n'.join(problems[:3]))
        app.close()


def _run(R, q, app):
    check = R.check
    app.php('setup.php', 'Test12345pw')
    base = app.serve()
    adm = Client(base)
    adm.post('/login.php', {'username': 'admin', 'password': 'Test12345pw'})
    txt = lambda b: re.sub(r'\s+', ' ', re.sub(r'<[^>]+>', ' ', b))
    Y, M = 2569, 9     # ก.ย. 2569 = 2026-09 (ผ่านมาแล้ว เกินกำหนดส่ง 10 ต.ค.)

    rows = [r.split('\t') for r in q("SELECT district_id, subdistrict, COUNT(*) c FROM temples WHERE subdistrict<>'' GROUP BY district_id, subdistrict HAVING c>=3 ORDER BY district_id, subdistrict").split('\n')]
    dA, subA = rows[0][0], rows[0][1]
    ids = q(f"SELECT id FROM temples WHERE district_id={dA} AND subdistrict='{subA}' ORDER BY id").split('\n')
    other = q(f"SELECT id FROM temples WHERE district_id={dA} AND subdistrict<>'{subA}' ORDER BY id LIMIT 6").split('\n')
    tid = dict(zip(['quiet', 'neg', 'spike', 'nospike1', 'nospike2', 'evid', 'evid_att', 'evid_small', 'cash', 'ret', 'clean'], ids[:3] + other[:2] + [None] * 6))
    # วัดในตำบล A: quiet / neg / spike  · วัดอื่นในอำเภอเดียวกัน: nospike1 / nospike2 ... (ตั้งชื่อให้ค้นหาง่าย)
    pool = ids[3:] + other[2:]
    for k, t in zip(['evid', 'evid_att', 'evid_small', 'cash', 'ret', 'clean'], pool):
        tid[k] = t
    nm = lambda k: f'ทดสอบ-{k}'
    for k, t in tid.items():
        q(f"UPDATE temples SET name='{nm(k)}' WHERE id={t}")
    q(f"UPDATE temples SET name='=cmd|quiet' WHERE id={tid['quiet']}")
    cat_in = q("SELECT id FROM categories WHERE type='income' AND is_transfer=0 AND is_active=1 ORDER BY id LIMIT 1")
    cat_ex = q("SELECT id FROM categories WHERE type='expense' AND is_transfer=0 AND is_active=1 ORDER BY id LIMIT 1")

    def tx(t, typ, date, amt, acct='NULL'):
        cat = cat_in if typ == 'income' else cat_ex
        q(f"INSERT INTO transactions (temple_id,type,category_id,txn_date,description,amount,account_id) VALUES ({t},'{typ}',{cat},'{date}','ทดสอบ',{amt},{acct})")
        return q("SELECT MAX(id) FROM transactions")
    def acc(t, kind, opening, opening_date='2026-01-01', name='บัญชี'):
        if kind == 'cash':
            q(f"INSERT INTO accounts (temple_id,kind,cash_flag,name,opening_balance,opening_date) VALUES ({t},'cash',1,'เงินสด',{opening},'{opening_date}')")
        else:
            q(f"INSERT INTO accounts (temple_id,kind,name,bank_name,bank_code,account_no,opening_balance,opening_date) VALUES ({t},'bank','{name}','ธนาคารกรุงไทย','KTB','111-2-33333-4',{opening},'{opening_date}')")
        return q(f"SELECT id FROM accounts WHERE temple_id={t} AND kind='{kind}' ORDER BY id DESC LIMIT 1")

    # neg: ยอดยกมา 1,000 แล้วจ่าย 5,000 ในเดือน ส.ค. → ติดลบ ณ สิ้นเดือน ก.ย.
    acc(tid['neg'], 'cash', 1000)
    tx(tid['neg'], 'expense', '2026-08-10', 5000)
    # spike: ม.ิ.ย.–ส.ค. เดือนละ 1,000 ก.ย. 6,000 (6 เท่า) · nospike1: ก.ย. 4,000 (ต่ำกว่า 5,000) · nospike2: ก.ย. 8,000 เฉลี่ย 5,000 (1.6 เท่า)
    for t, base_e, sep in ((tid['spike'], 1000, 6000), (tid['nospike1'], 1000, 4000), (tid['nospike2'], 5000, 8000)):
        for m in ('06', '07', '08'):
            tx(t, 'expense', f'2026-{m}-05', base_e)
        tx(t, 'expense', '2026-09-05', sep)
    # evid: รายจ่าย 12,000 ไม่มีหลักฐาน · evid_att: 12,000 มีหลักฐาน · evid_small: 9,999 ไม่มีหลักฐาน
    tx(tid['evid'], 'expense', '2026-09-05', 12000)
    x = tx(tid['evid_att'], 'expense', '2026-09-05', 12000)
    q(f"INSERT INTO transaction_attachments (transaction_id,temple_id,filename,mime,size,data,created_at) VALUES ({x},{tid['evid_att']},'a.jpg','image/jpeg',3,X'FFD8FF',NOW())")
    tx(tid['evid_small'], 'expense', '2026-09-05', 9999)
    # cash: เงินสดยกมา 250,000 · และบัญชีธนาคารสูงของอีกวัดไม่ติดธง (ตรวจเฉพาะเงินสด)
    acc(tid['cash'], 'cash', 250000)
    acc(tid['evid_small'], 'bank', 900000)
    # ret: ถูกส่งกลับ
    q(f"INSERT INTO monthly_reports (temple_id,report_year,report_month,submitted_at,txn_count,income_total,expense_total,returned_at,return_reason) VALUES ({tid['ret']},{Y},{M},'2026-10-02 09:00:00',0,0,0,'2026-10-06 10:00:00','ยอดไม่ตรงใบเสร็จ')")
    # clean: มีรายการทุกเดือน ส่งรายงานแล้วตรงกับข้อมูล
    for m in ('07', '08', '09'):
        tx(tid['clean'], 'income', f'2026-{m}-10', 1000)
    q(f"INSERT INTO monthly_reports (temple_id,report_year,report_month,submitted_at,txn_count,income_total,expense_total) VALUES ({tid['clean']},{Y},{M},'2026-10-03 09:00:00',1,1000,0)")

    def page(cl=None, **kw):
        kw = {'year': Y, 'month': M, **kw}
        c, loc, b = (cl or adm).req('/anomalies.php?' + urllib.parse.urlencode(kw))
        return c, b
    def block(b, name):
        m = [x for x in re.findall(r'<tr>.*?</tr>', b, re.S) if name in x]
        return txt(m[0]) if m else None

    c, b = page()
    check('R0 หน้าเปิดได้ และเมนูมี "วัดที่ควรติดตาม"', c == 200 and 'วัดที่ควรติดตาม' in re.search(r'<nav class="hidden.*?</nav>', b, re.S).group(0))
    n_temples = q("SELECT COUNT(*) FROM temples WHERE is_active=1")
    check('R1 แสดงจำนวนวัดที่ตรวจเท่ากับวัดทั้งหมดในขอบเขต', f'{int(n_temples):,}' in txt(b))

    # ---- A: ธงแต่ละชนิด (ดูจากแถวของวัดทดสอบ ใช้ตัวกรองวัด/ธงลดขนาดหน้า)
    def flags_of(k, **kw):
        c, b = page(**kw)
        return block(b, nm(k)) or ''
    b_all = page(district=dA)[1]
    check('A1 ไม่มีรายการ 3 เดือนติดกัน → ธง "ไม่มีรายการหลายเดือน" (วัด quiet)', 'ไม่มีรายการหลายเดือน' in (block(b_all, 'quiet') or ''))
    check('A2 ยอดติดลบ ณ สิ้นเดือน → ธง "ยอดติดลบ" ระบุ 4,000.00 บาท', 'ยอดติดลบ' in (block(b_all, nm('neg')) or '') and '4,000.00' in (block(b_all, nm('neg')) or ''), block(b_all, nm('neg')))
    check('A3 รายจ่ายพุ่ง 6 เท่าของค่าเฉลี่ย (6,000 เทียบ 1,000) → ธง "รายจ่ายพุ่ง"', 'รายจ่ายพุ่ง' in (block(b_all, nm('spike')) or '') and '6.0 เท่า' in (block(b_all, nm('spike')) or ''))
    check('A4 ไม่ติดธงพุ่ง: ต่ำกว่า 5,000 บาท และไม่ถึง 2 เท่า', 'รายจ่ายพุ่ง' not in (block(b_all, nm('nospike1')) or '') and 'รายจ่ายพุ่ง' not in (block(b_all, nm('nospike2')) or ''))
    check('A5 รายจ่าย 12,000 ไม่มีหลักฐาน → ธง "รายจ่ายสูงไม่มีหลักฐาน"', 'รายจ่ายสูงไม่มีหลักฐาน' in (block(b_all, nm('evid')) or '') and '12,000.00' in (block(b_all, nm('evid')) or ''))
    check('A6 มีไฟล์แนบ หรือไม่ถึง 10,000 บาท → ไม่ติดธงหลักฐาน', 'รายจ่ายสูงไม่มีหลักฐาน' not in (block(b_all, nm('evid_att')) or '') and 'รายจ่ายสูงไม่มีหลักฐาน' not in (block(b_all, nm('evid_small')) or ''))
    check('A7 เงินสดคงเหลือ 250,000 → ธง "เงินสดสูง" · เงินฝากธนาคารสูงไม่ติดธง', 'เงินสดสูง' in (block(b_all, nm('cash')) or '') and 'เงินสดสูง' not in (block(b_all, nm('evid_small')) or ''))
    check('A8 รายงานถูกส่งกลับ → ธง "รายงานถูกส่งกลับ" พร้อมเหตุผล', 'รายงานถูกส่งกลับ' in (block(b_all, nm('ret')) or '') and 'ยอดไม่ตรงใบเสร็จ' in (block(b_all, nm('ret')) or ''))
    check('A9 วัดที่ยังไม่ส่งรายงานเดือนนั้น (เกินกำหนด) ติดธง "ยังไม่ส่งรายงาน"', 'ยังไม่ส่งรายงาน' in (block(b_all, nm('neg')) or ''))
    check('A10 วัดปกติ (มีรายการทุกเดือน ส่งรายงานตรงข้อมูล) ไม่ถูกติดธงเลย', block(b_all, nm('clean')) is None)
    check('A11 เรียงตามคะแนน: วัดที่มีธงสูง 2 ธงอยู่ก่อนวัดที่มีธงเดียว', b_all.index(nm('neg')) < b_all.index(nm('nospike1')))

    # ---- B: ตัวกรอง / เดือน
    b = page(district=dA, kind='negative')[1]
    check('B1 กรองธง "ยอดติดลบ" เหลือเฉพาะวัดที่ติดลบ', nm('neg') in b and nm('spike') not in b and nm('evid') not in b)
    check('B2 เดือนที่ยังไม่เริ่ม (ธ.ค. 2569) → แจ้งว่าไม่มีข้อมูลให้ตรวจ ไม่ตรวจวัด', 'ยังไม่เริ่ม' in page(year=Y, month=12)[1] and 'ส่งออก CSV' not in page(year=Y, month=12)[1])
    check('B3 ค่าเดือน/ปีที่ผิดปกติไม่ทำให้ล้ม', page(month='x', year='9999')[0] == 200 and page(kind='nope', district='abc')[0] == 200)
    b_jul = page(district=dA, month=7)[1]
    check('B4 เปลี่ยนเดือนเป็น ก.ค.: วัด spike ไม่ติดธงพุ่ง (ก.ค. เดือนละ 1,000 ไม่ใช่ 6,000)', 'รายจ่ายพุ่ง' not in (block(b_jul, nm('spike')) or ''))

    # ---- C: CSV
    c, _, body = adm.req('/anomalies.php?' + urllib.parse.urlencode({'year': Y, 'month': M, 'district': dA, 'export': 'csv'}))
    rr = list(csv.reader(io.StringIO(body.lstrip('﻿'))))
    names = {r[2] for r in rr[1:]}
    check('C1 CSV: BOM หัวตาราง และมีวัดที่ติดธง ไม่มีวัดปกติ', c == 200 and body.startswith('﻿') and nm('neg') in names and nm('clean') not in names, str(rr[:2]))
    check('C2 CSV กัน formula injection (ชื่อวัดที่ขึ้นต้น = ถูกนำหน้าด้วย \')', any(r[2] == "'=cmd|quiet" for r in rr[1:]), str([r[2] for r in rr[1:4]]))
    check('C3 บันทึก audit การส่งออก', int(q("SELECT COUNT(*) FROM audit_log WHERE action='export' AND entity='anomalies'")) >= 1)

    # ---- D: สิทธิ์และขอบเขต
    h = subprocess.run(['php', '-r', f"echo password_hash('{PW}', PASSWORD_DEFAULT);"], capture_output=True, text=True).stdout

    def staff(username):
        uid = q(f"SELECT id FROM users WHERE username='{username}'")
        q(f"UPDATE users SET password_hash='{h}', must_change_password=0, email='{username}@example.org' WHERE id={uid}")
        cl = Client(base)
        c, loc, _ = cl.post('/login.php', {'username': username, 'password': PW})
        assert loc == 'index.php', (username, c, loc)
        return cl
    dcode = q(f"SELECT code FROM districts WHERE id={dA}")
    dist = staff('amphoe' + dcode)
    sub_user = q(f"SELECT u.username FROM users u JOIN user_subdistricts s ON s.user_id=u.id WHERE s.district_id={dA} AND s.subdistrict='{subA}' LIMIT 1")
    sd = staff(sub_user)
    prov = staff('province')
    bd = page(dist)[1]
    n_dist = q(f"SELECT COUNT(*) FROM temples WHERE is_active=1 AND district_id={dA}")
    check('D1 แอดมินอำเภอตรวจเฉพาะวัดในอำเภอตน (จำนวนที่ตรวจ = วัดในอำเภอ)', f'{int(n_dist):,}' in txt(bd) and nm('neg') in bd)
    bs = page(sd)[1]
    n_sub = q(f"SELECT COUNT(*) FROM temples WHERE is_active=1 AND district_id={dA} AND subdistrict='{subA}'")
    check('D2 เจ้าคณะตำบลตรวจเฉพาะวัดในตำบลที่ดูแล', f'{int(n_sub):,}' in txt(bs) and nm('neg') in bs and nm('cash') not in bs, str(n_sub))
    check('D3 เจ้าคณะจังหวัดตรวจได้ทุกวัด', f'{int(n_temples):,}' in txt(page(prov)[1]))
    check('D4 แอดมินหลักเลือกอำเภอได้ · แอดมินอำเภอไม่มีตัวเลือกอำเภอ', 'name="district"' in page()[1] and 'name="district"' not in bd)
    check('D5 ต้องเข้าสู่ระบบ', Client(base).req('/anomalies.php')[0] == 302)
