"""ชุดที่ 17: ค้นหารายการรายรับ-รายจ่ายขั้นสูง (search.php) — กรองหลายเงื่อนไข ผลรวม แบ่งหน้า CSV ขอบเขตสิทธิ์ ชุดกรองที่บันทึกไว้

ข้อมูลสังเคราะห์ต่อวัดตัวอย่าง: ตรวจว่าไม่นับรายการที่ลบแล้ว/รายการโอน · LIKE ไม่ตีความ % _ · กัน CSV formula injection
"""
import csv
import io
import re
import subprocess
import urllib.parse

from support import App, Client

PW = 'Wat12345pw!'


def run(db, R):
    name = db.new_database('search')
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
    check('R0 ตาราง saved_searches ถูกสร้างพร้อมการติดตั้ง', q("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name='saved_searches'") == '1')

    rows = [r.split('\t') for r in q("SELECT district_id, id FROM temples WHERE is_active=1 ORDER BY district_id, id").split('\n')]
    dA, tA = rows[0]
    tB = rows[1][1]
    dC, tC = next(r for r in rows if r[0] != dA)
    cat_in = q("SELECT id FROM categories WHERE type='income' AND is_transfer=0 AND is_active=1 ORDER BY id LIMIT 1")
    cat_ex = q("SELECT id FROM categories WHERE type='expense' AND is_transfer=0 AND is_active=1 ORDER BY id LIMIT 1")
    q(f"INSERT INTO accounts (temple_id,kind,name,bank_name,bank_code,account_no) VALUES ({tA},'bank','ออมทรัพย์','ธนาคารกรุงไทย','KTB','123-4-56789-0')")
    bank = q(f"SELECT id FROM accounts WHERE temple_id={tA} AND kind='bank'")
    q(f"INSERT INTO funds (temple_id,name,created_at) VALUES ({tB},'กองทุนสร้างศาลา',NOW())")
    fund = q(f"SELECT id FROM funds WHERE temple_id={tB}")

    def tx(temple, typ, date, amt, desc, doc='', acct='NULL', fund_id='NULL', deleted=0, transfer='NULL'):
        cat = cat_in if typ == 'income' else cat_ex
        q(f"INSERT INTO transactions (temple_id,type,category_id,txn_date,doc_no,description,amount,account_id,fund_id,is_deleted,transfer_id) VALUES ({temple},'{typ}',{cat},'{date}','{doc}','{desc}',{amt},{acct},{fund_id},{deleted},{transfer})")
        return q("SELECT MAX(id) FROM transactions")
    t1 = tx(tA, 'income', '2026-01-10', 1000, 'ตู้บริจาค มกราคม', 'R-001')
    t2 = tx(tA, 'expense', '2026-02-05', 1200.50, 'ค่าไฟฟ้า กุมภาพันธ์', 'E-100')
    tx(tA, 'expense', '2025-12-20', 300, 'ค่าน้ำ ธันวาคม', acct=bank)
    tx(tB, 'income', '2026-03-01', 5000, 'ทอดผ้าป่า', fund_id=fund)
    tx(tC, 'expense', '2026-03-15', 800, 'ค่าไฟฟ้า สาขา')
    tx(tA, 'expense', '2026-02-06', 1200.50, 'ค่าไฟลบแล้ว', deleted=1)
    tx(tA, 'expense', '2026-02-07', 999, 'รายการโอน', transfer=1)
    tx(tA, 'expense', '2026-02-08', 50, '=HYPERLINK("http://x")')
    q(f"INSERT INTO transaction_attachments (transaction_id,temple_id,filename,mime,size,data,created_at) VALUES ({t2},{tA},'a.jpg','image/jpeg',3,X'FFD8FF',NOW())")

    def page(cl=None, **kw):
        c, _, b = (cl or adm).req('/search.php?' + urllib.parse.urlencode(kw, doseq=True))
        assert c == 200, (kw, c)
        return b
    nrows = lambda b: b.count('<td class="whitespace-nowrap">')
    found = lambda b: (re.search(r'พบ\s*</div><div[^>]*>\s*([\d,]+)', b) or [None, '-1'])[1].replace(',', '')

    # ---- A: กรอง
    b = page()
    check('A1 ไม่กรองอะไร: พบ 6 รายการ (ไม่นับที่ลบแล้วและรายการโอน)', found(b) == '6' and nrows(b) == 6, found(b))
    check('A2 ผลรวมรายรับ 6,000.00 รายจ่าย 2,350.50 (ตรงกับรายการที่นับ)', '6,000.00' in b and '2,350.50' in b)
    check('A3 เมนูมี "ค้นหารายการ"', 'ค้นหารายการ' in re.search(r'<nav class="hidden.*?</nav>', b, re.S).group(0))
    b = page(q='ค่าไฟ')
    check('A4 ข้อความ "ค่าไฟ" → 2 รายการ (ไม่รวมรายการที่ลบแล้ว)', found(b) == '2' and 'ค่าไฟลบแล้ว' not in b)
    check('A5 ค้นด้วยจำนวนเงินตรงตัว 1,200.50 → รายการเดียว', found(page(q='1200.50')) == '1' and found(page(q='1,200.50')) == '1')
    check('A6 ค้นด้วยเลขที่เอกสาร R-001', found(page(q='R-001')) == '1')
    check('A7 ประเภท: รายจ่าย 4 · รายรับ 2', found(page(type='expense')) == '4' and found(page(type='income')) == '2')
    b = page(from_d=1, from_m=1, from_y=2569, to_d=28, to_m=2, to_y=2569)
    check('A8 ช่วงวันที่ 1 ม.ค.–28 ก.พ. 2569 → 3 รายการ (ม.ค. 1 + ก.พ. 2 ที่ไม่ลบ/ไม่โอน)', found(b) == '3', found(b))
    b = page(from_d=1)
    check('A9 กรอกวันที่ไม่ครบ → แจ้งข้อผิดพลาดและไม่ค้น', 'ไม่ถูกต้อง' in b and 'พบ' not in txt(b).split('ไม่ถูกต้อง')[1][:40])
    check('A10 วันที่เริ่มหลังวันที่สิ้นสุด → แจ้งข้อผิดพลาด', 'ต้องไม่หลัง' in page(from_d=1, from_m=3, from_y=2569, to_d=1, to_m=1, to_y=2569))
    check('A11 ช่วงจำนวนเงิน 1,000–1,300 → 2 รายการ', found(page(min='1000', max='1300')) == '2')
    check('A12 จำนวนเงินต่ำสุดมากกว่าสูงสุด → แจ้งข้อผิดพลาด', 'ต้องไม่มากกว่า' in page(min='500', max='100'))
    check('A13 กรองวัด A → 4 รายการ · กรอง%s (อำเภอ) → 1' % 'อำเภอ', found(page(temple=tA)) == '4' and found(page(district=dC)) == '1')
    check('A14 บัญชี: ธนาคาร 1 · เงินสด 5', found(page(account='bank')) == '1' and found(page(account='cash')) == '5')
    check('A15 กองทุน: ระบุกองทุน 1 · ไม่ระบุกองทุน 5', found(page(fund=fund)) == '1' and found(page(fund='none')) == '5')
    check('A16 หลักฐานแนบ: มี 1 · ไม่มี 5', found(page(att='yes')) == '1' and found(page(att='no')) == '5')
    check('A17 กรองหมวด (หมวดรายรับแรก) ได้ผลตรงกับฐานข้อมูล', found(page(category=cat_in)) == q(f"SELECT COUNT(*) FROM transactions WHERE category_id={cat_in} AND is_deleted=0 AND transfer_id IS NULL"))
    check('A18 หลายเงื่อนไขพร้อมกัน (ค่าไฟ + รายจ่าย + วัด A) → 1 รายการ', found(page(q='ค่าไฟ', type='expense', temple=tA)) == '1')
    d1, d2 = page(sort='date_desc'), page(sort='date_asc')
    check('A19 เรียงตามวันที่: ใหม่→เก่า เริ่มที่ 15 มี.ค. · เก่า→ใหม่ เริ่มที่ ธ.ค. ปีก่อน', d1.index('ค่าไฟฟ้า สาขา') < d1.index('ค่าน้ำ ธันวาคม') and d2.index('ค่าน้ำ ธันวาคม') < d2.index('ค่าไฟฟ้า สาขา'))
    a1, a2 = page(sort='amount_desc'), page(sort='amount_asc')
    check('A20 เรียงตามจำนวนเงิน', a1.index('ทอดผ้าป่า') < a1.index('ค่าน้ำ ธันวาคม') and a2.index('HYPERLINK') < a2.index('ทอดผ้าป่า'))
    check('A21 ใส่ค่าที่ไม่ถูกต้อง (ตัวอักษรในจำนวนเงิน/หน้า/หมวด) ไม่ทำให้ล้ม', page(min='abc', page='999', category='x', fund='zz', type='nope', sort='bad') is not None)

    # LIKE: % และ _ ต้องเป็นตัวอักษรธรรมดา
    tx(tA, 'income', '2026-01-11', 77, '100% ทำบุญ_พิเศษ')
    check('A22 ค้น "%" ได้เฉพาะรายการที่มี % จริง (ไม่ตีความเป็น wildcard)', found(page(q='%')) == '1' and found(page(q='_')) == '1')

    # ---- B: แบ่งหน้า
    for i in range(130):
        tx(tA, 'income', '2026-04-01', 10, f'PAGINATE {i}')
    b1, b2 = page(q='PAGINATE'), page(q='PAGINATE', page=2)
    check('B1 130 รายการ: หน้า 1 มี 100 หน้า 2 มี 30 และมีปุ่มเปลี่ยนหน้า', found(b1) == '130' and nrows(b1) == 100 and nrows(b2) == 30 and 'หน้า 1 / 2' in txt(b1) and 'ก่อนหน้า' in b2 and 'ถัดไป' in b1)
    check('B2 หน้าเกินจำนวนหน้า → ใช้หน้าสุดท้าย', nrows(page(q='PAGINATE', page=99)) == 30)

    # ---- C: CSV
    def csv_get(**kw):
        c, _, body = adm.req('/search.php?' + urllib.parse.urlencode({**kw, 'export': 'csv'}))
        return c, body, list(csv.reader(io.StringIO(body.lstrip('﻿'))))
    c, body, rr = csv_get(q='ค่าไฟ')
    check('C1 ส่งออก CSV ตามเงื่อนไข (หัวตาราง + 2 แถว) มี BOM', c == 200 and body.startswith('﻿') and len(rr) == 3 and 'วันที่' in rr[0][0], str(rr)[:200])
    c, body, rr = csv_get(type='expense', temple=tA)
    cell = next((r for r in rr if 'HYPERLINK' in ''.join(r)), None)
    check('C2 CSV กัน formula injection (ค่าที่ขึ้นต้น = ถูกนำหน้าด้วย \')', cell is not None and cell[8].startswith("'="), str(cell))
    check('C3 CSV ไม่ส่งออกเมื่อเงื่อนไขผิด (แสดงหน้าเว็บพร้อมข้อผิดพลาดแทน)', csv_get(min='9', max='1')[1].lstrip().startswith('<'))
    check('C4 บันทึก audit การส่งออก', int(q("SELECT COUNT(*) FROM audit_log WHERE action='export' AND entity='search'")) >= 2)

    # ---- D: ขอบเขตสิทธิ์
    h = subprocess.run(['php', '-r', f"echo password_hash('{PW}', PASSWORD_DEFAULT);"], capture_output=True, text=True).stdout

    def staff(username):
        uid = q(f"SELECT id FROM users WHERE username='{username}'")
        q(f"UPDATE users SET password_hash='{h}', must_change_password=0, email='{username}@example.org' WHERE id={uid}")
        cl = Client(base)
        c, loc, _ = cl.post('/login.php', {'username': username, 'password': PW})
        assert loc == 'index.php', (username, c, loc)
        return cl
    dcode = q(f"SELECT code FROM districts WHERE id={dA}")
    dist, prov = staff('amphoe' + dcode), staff('province')
    b = page(dist, q='ค่าไฟ')
    check('D1 แอดมินอำเภอเห็นเฉพาะอำเภอตน (ค่าไฟ 1 ไม่เห็นวัดนอกอำเภอ)', found(b) == '1' and 'ค่าไฟฟ้า สาขา' not in b)
    check('D2 แอดมินอำเภอระบุวัดนอกอำเภอ → ไม่เห็นรายการ', found(page(dist, temple=tC)) == '0')
    check('D3 เจ้าคณะจังหวัดเห็นทุกวัด แต่ไม่มีปุ่มแก้ไข', found(page(prov, q='ค่าไฟ')) == '2' and 'edit=' not in page(prov, q='ค่าไฟ'))
    check('D4 แอดมินหลักมีปุ่มแก้ไขที่ลิงก์ไปหน้าบัญชีตามประเภท', f'transactions.php?type=expense&edit={t2}' in page(q='ค่าไฟ'))
    check('D5 ต้องเข้าสู่ระบบก่อน', Client(base).req('/search.php')[0] == 302)

    # ---- E: ชุดกรองที่บันทึกไว้
    qs = urllib.parse.urlencode({'q': 'ค่าไฟ', 'type': 'expense'})
    adm.post('/search.php', {'action': 'save_filter', 'qs': qs, 'name': 'ค่าไฟทั้งหมด'}, '/search.php?' + qs)
    row = q("SELECT CONCAT(name,'|',params) FROM saved_searches")
    check('E1 บันทึกชุดกรอง: เก็บเฉพาะเงื่อนไขมาตรฐาน', row.startswith('ค่าไฟทั้งหมด|') and '"q":"ค่าไฟ"' in row and '"type":"expense"' in row, row)
    b = page()
    check('E2 หน้าค้นหาแสดงชุดกรองที่บันทึก พร้อมลิงก์ที่ใช้เงื่อนไขนั้น', 'ค่าไฟทั้งหมด' in b and 'search.php?q=' in b)
    adm.post('/search.php', {'action': 'save_filter', 'qs': qs, 'name': 'ก'}, '/search.php?' + qs)
    adm.post('/search.php', {'action': 'save_filter', 'qs': '', 'name': 'ไม่มีเงื่อนไข'}, '/search.php')
    adm.post('/search.php', {'action': 'save_filter', 'qs': 'min=9&max=1', 'name': 'เงื่อนไขผิด'}, '/search.php')
    check('E3 ชื่อสั้นเกินไป/ไม่มีเงื่อนไข/เงื่อนไขผิด → ไม่บันทึก', q("SELECT COUNT(*) FROM saved_searches") == '1')
    sid = q("SELECT id FROM saved_searches")
    prov.post('/search.php', {'action': 'delete_filter', 'id': sid}, '/search.php')
    check('E4 ผู้ใช้อื่นลบชุดกรองของเราไม่ได้ และมองไม่เห็น', q("SELECT COUNT(*) FROM saved_searches") == '1' and 'ค่าไฟทั้งหมด' not in page(prov))
    uid = q("SELECT id FROM users WHERE username='admin'")
    for i in range(19):
        q(f"INSERT INTO saved_searches (user_id,name,params,created_at) VALUES ({uid},'ชุด{i}','{{\"q\":\"x\"}}',NOW())")
    adm.post('/search.php', {'action': 'save_filter', 'qs': qs, 'name': 'ชุดที่ 21'}, '/search.php?' + qs)
    check('E5 บันทึกได้สูงสุด 20 ชุดต่อผู้ใช้', q("SELECT COUNT(*) FROM saved_searches") == '20')
    adm.post('/search.php', {'action': 'delete_filter', 'id': sid}, '/search.php')
    check('E6 เจ้าของลบชุดกรองของตนได้', q(f"SELECT COUNT(*) FROM saved_searches WHERE id={sid}") == '0')

    # ---- F: ยังไม่รัน migration ชุดกรอง → ค้นหายังใช้ได้
    q('DROP TABLE saved_searches')
    b = page(q='ค่าไฟ')
    check('F1 ยังไม่รัน migration → ค้นหาใช้ได้ ไม่มีฟอร์มบันทึกชุดกรอง', found(b) == '2' and 'save_filter' not in b)
    mig = open(__file__.replace('tests/test_search.py', 'sql/migrations/2026_saved_searches.sql'), encoding='utf-8').read().replace('USE tfms_phrae;', '')
    q(mig)
    check('F2 รัน migration 2026_saved_searches.sql ได้ และกลับมาบันทึกได้', 'save_filter' in page(q='ค่าไฟ'))
