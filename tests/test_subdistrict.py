"""ชุดที่ 9: เจ้าคณะตำบล (role = subdistrict) — ดูข้อมูลวัดในตำบลที่ดูแลได้อย่างเดียว

ตั้งข้อมูลจริงของแพร่ (381 วัด) แล้วสร้างบัญชีผ่านหน้า admin/subdistricts.php ตรวจ: ขอบเขตข้อมูล (แดชบอร์ด รายงาน CSV รายงานแบบ 1–3 บุคลากร)
การเขียนถูกบล็อก (บัญชีรายรับ-รายจ่าย อนุโมทนาบัตร ข้อมูลวัด ผู้ดูแลระบบ) ไม่นับโควตา และการจัดการบัญชีโดยแอดมินหลัก
"""
import datetime
import re

from support import App, Client, notice

PW = 'Tambon12345pw'


def run(db, R):
    name = db.new_database('subdist')
    q = db.q(name)
    app = App(env={'TFMS_DB_HOST': db.host, 'TFMS_DB_PORT': db.port, 'TFMS_DB_USER': db.user, 'TFMS_DB_PASS': db.password, 'TFMS_DB_NAME': name, 'TFMS_MAIL_DRIVER': 'log'})
    try:
        _run(R, q, app)
    finally:
        problems = app.php_problems()
        R.check('Z1 ไม่มี PHP warning/notice/fatal ใน log ของเซิร์ฟเวอร์', not problems, '\n'.join(problems[:3]))
        app.close()


def _run(R, q, app):
    check = R.check
    setup = app.php('setup.php', 'Test12345pw')   # ค่าเริ่มต้น: สร้างผู้ใช้ระดับจังหวัด/อำเภอ/ตำบลให้ครบด้วย
    base = app.serve()
    adm = Client(base)
    adm.post('/login.php', {'username': 'admin', 'password': 'Test12345pw'})

    # ================= A: สร้างผู้ใช้ทุกระดับของจังหวัดแพร่อัตโนมัติ =================
    import csv
    n_tambon = int(q("SELECT COUNT(*) FROM (SELECT 1 FROM temples WHERE subdistrict IS NOT NULL AND subdistrict<>'' GROUP BY district_id, subdistrict) x"))
    files = sorted((app.dir / 'storage').glob('staff_accounts_*.csv'))
    rows = list(csv.reader(files[0].open(encoding='utf-8-sig'))) if files else []
    check('A1 setup.php สร้างผู้ใช้เจ้าคณะจังหวัด 1 + อำเภอ 8 + ตำบลตามทะเบียน (บัญชี=%d) และบอกจำนวน' % (9 + n_tambon),
          f'{9 + n_tambon} บัญชี' in setup.stdout and len(rows) == 1 + 9 + n_tambon, setup.stdout[-300:])
    check('A2 ไฟล์ CSV รหัสผ่านเริ่มต้นเก็บใน storage/ สิทธิ์ 0600 และมีชื่อผู้ใช้/รหัสผ่านทุกแถว',
          len(files) == 1 and oct(files[0].stat().st_mode & 0o777) == '0o600' and all(r[3] and len(r[4]) == 12 for r in rows[1:]), str(files))
    check('A3 เจ้าคณะจังหวัด 1 บัญชี ชื่อผู้ใช้ province', q("SELECT COUNT(*) FROM users WHERE role='province'") == '1' and q("SELECT username FROM users WHERE role='province'") == 'province')
    check('A4 แอดมินประจำอำเภอครบ 8 อำเภอ ชื่อผู้ใช้ amphoe<รหัสอำเภอ> ไม่ซ้ำอำเภอ',
          q("SELECT COUNT(DISTINCT district_id) FROM users WHERE role='district'") == '8' and q("SELECT COUNT(*) FROM users WHERE role='district' AND username REGEXP '^amphoe54[0-9]{2}$'") == '8')
    check('A5 เจ้าคณะตำบลตำบลละ 1 บัญชี (%d ตำบล) ทุกบัญชีมีตำบลที่ดูแลตำบลเดียว' % n_tambon,
          q("SELECT COUNT(*) FROM users WHERE role='subdistrict'") == str(n_tambon) and q("SELECT COUNT(*) FROM user_subdistricts") == str(n_tambon)
          and q("SELECT COUNT(*) FROM (SELECT user_id FROM user_subdistricts GROUP BY user_id HAVING COUNT(*)<>1) x") == '0')
    check('A6 ชื่อผู้ใช้เจ้าคณะตำบลมาจากรหัสตำบลของรหัสวัด (ร้องกวาง ทุ่งศรี = tambon540207) และไม่ซ้ำกัน',
          q("SELECT u.username FROM users u JOIN user_subdistricts s ON s.user_id=u.id WHERE s.subdistrict='ทุ่งศรี'") == 'tambon540207'
          and q("SELECT COUNT(DISTINCT username) FROM users WHERE role='subdistrict'") == str(n_tambon))
    check('A7 บัญชีที่สร้างทั้งหมดต้องเปลี่ยนรหัสผ่านครั้งแรก และเก็บเป็นแฮช',
          q("SELECT COUNT(*) FROM users WHERE role IN ('province','district','subdistrict') AND (must_change_password<>1 OR password_hash NOT LIKE '$%')") == '0')
    check('A8 โควตาผู้ใช้ระดับจังหวัด/อำเภอ = 10 จาก 10 (เจ้าคณะตำบลไม่นับ)', 'ใช้ไป 10 จาก 10' in re.sub(r'\s+', ' ', re.sub('<[^>]+>', ' ', adm.req('/admin/users.php')[2])))
    pwOf = {r[3]: r[4] for r in rows[1:]}
    adm.post('/admin/users.php', {'action': 'set_email', 'id': q("SELECT id FROM users WHERE username='amphoe5402'"), 'email': 'amphoe5402@example.org'}, '/admin/users.php')
    d = Client(base)
    c, loc, _ = d.post('/login.php', {'username': 'amphoe5402', 'password': pwOf['amphoe5402']})
    c2, loc2, _ = d.req('/transactions.php?type=income')
    check('A9 เข้าสู่ระบบด้วยรหัสผ่านเริ่มต้นจาก CSV ได้ แต่ถูกบังคับเปลี่ยนรหัสก่อนใช้งาน', loc == 'index.php' and c2 == 302 and loc2 == 'account.php', f'{loc} {c2} {loc2}')
    # กดปุ่มสร้างซ้ำ: ไม่มีอะไรต้องสร้าง / ลบบางบัญชีแล้วสร้างเฉพาะที่ขาด
    adm.post('/admin/users.php', {'action': 'provision_staff'}, '/admin/users.php')
    check('A10 กดสร้างซ้ำเมื่อครบแล้ว → ไม่สร้างเพิ่ม', 'ครบแล้ว' in notice(adm.req('/admin/users.php')[2]) and q("SELECT COUNT(*) FROM users WHERE role IN ('province','district','subdistrict')") == str(9 + n_tambon))
    q("DELETE FROM users WHERE username IN ('province','amphoe5403','tambon540207')")
    adm.post('/admin/users.php', {'action': 'provision_staff'}, '/admin/users.php')
    pg = adm.req('/admin/users.php')[2]
    check('A11 ลบ 3 บัญชีแล้วกดสร้าง → สร้างเฉพาะที่ขาด 3 บัญชี และมีลิงก์ดาวน์โหลดรหัสผ่าน',
          q("SELECT COUNT(*) FROM users WHERE role IN ('province','district','subdistrict')") == str(9 + n_tambon) and '3 บัญชี' in pg and 'download=staff_creds' in pg, notice(pg))
    c, _, csvb = adm.req('/admin/users.php?download=staff_creds')
    c2, loc2, _ = adm.req('/admin/users.php?download=staff_creds')
    check('A12 ดาวน์โหลด CSV ได้ครั้งเดียว (3 แถว) ครั้งที่สองถูกปฏิเสธ', c == 200 and csvb.count('\n') == 4 and 'tambon540207' in csvb and c2 == 302, str(csvb[:200]))
    q("DELETE FROM users WHERE role='subdistrict'")   # เริ่มชุดทดสอบเจ้าคณะตำบลจากบัญชีว่าง
    q("DELETE FROM users WHERE role IN ('province') OR (role='district' AND username<>'x')")


    # เลือกตำบล: A, B อยู่อำเภอเดียวกัน (ได้รับมอบหมาย) · C คนละอำเภอ (ไม่ได้รับมอบหมาย) · A2 = ตำบลชื่อซ้ำกับ A แต่อยู่อีกอำเภอ
    rows = [r.split('\t') for r in q("SELECT district_id, subdistrict, COUNT(*) c FROM temples WHERE subdistrict IS NOT NULL AND subdistrict<>'' GROUP BY district_id, subdistrict HAVING c>=2 ORDER BY district_id, subdistrict").split('\n')]
    d1 = rows[0][0]
    same = [r for r in rows if r[0] == d1]
    A, B = same[0], same[1]
    C = next(r for r in rows if r[0] != d1)
    keyA, keyB, keyC = f'{A[0]}|{A[1]}', f'{B[0]}|{B[1]}', f'{C[0]}|{C[1]}'
    tid = lambda r: q(f"SELECT id FROM temples WHERE district_id={r[0]} AND subdistrict='{r[1]}' ORDER BY id LIMIT 1")
    tA, tB, tC = tid(A), tid(B), tid(C)
    nameOf = lambda t: q(f'SELECT name FROM temples WHERE id={t}')
    # วัดในตำบลเดียวกับ A แต่ชื่อวัดต่างกัน ใช้เป็นข้อมูลเสริม / วัดใน A เท่านั้นที่รับมอบหมาย
    year = datetime.date.today().year
    be = year + 543
    cat_in = q("SELECT id FROM categories WHERE type='income' AND is_transfer=0 LIMIT 1")
    cat_ex = q("SELECT id FROM categories WHERE type='expense' AND is_transfer=0 LIMIT 1")
    for t, amt in ((tA, 1000), (tB, 2000), (tC, 50000)):
        q(f"INSERT INTO transactions (temple_id,type,category_id,txn_date,description,amount) VALUES ({t},'income',{cat_in},'{year}-06-15','ตรวจสอบ',{amt})")
    q(f"INSERT INTO transactions (temple_id,type,category_id,txn_date,description,amount) VALUES ({tA},'expense',{cat_ex},'{year}-06-16','จ่ายตรวจสอบ',300)")
    q(f"INSERT INTO temple_people (temple_id,position,first_name,last_name,national_id_enc,national_id_idx,national_id_last4,home_address,phone,consent_at,is_active) "
      f"VALUES ({tA},'steward','สมชาย','ทดสอบ','x',REPEAT('a',64),'1234','บ้านเลขที่ 1 ตำบลทดสอบ','0812345678',NOW(),1)")

    def quota_text(cl):
        return re.search(r'ใช้ไป\s*(\d+)\s*จาก\s*(\d+)', cl.req('/admin/users.php')[2]).group(0)

    def create(areas=None, **kw):
        import urllib.parse
        areas = [keyA, keyB] if areas is None else areas
        d = {'action': 'create', 'username': 'tambon1', 'full_name': 'พระครูทดสอบ ตำบล', 'password': PW, 'email': 'tambon1@example.org', 'csrf': adm.csrf('/admin/subdistricts.php')}
        d.update(kw)
        raw = urllib.parse.urlencode(d).encode()
        for a in areas:
            raw += b'&' + urllib.parse.urlencode({'areas[]': a}).encode()
        adm.req('/admin/subdistricts.php', raw=raw, headers={'Content-Type': 'application/x-www-form-urlencoded'})
        return notice(adm.req('/admin/subdistricts.php')[2])

    q0 = quota_text(adm)
    check('S0 หน้าจัดการเจ้าคณะตำบลเปิดได้ และเมนูจัดการระบบมีการ์ดนี้', adm.req('/admin/subdistricts.php')[0] == 200 and 'เจ้าคณะตำบล' in adm.req('/admin/index.php')[2])

    # ---- การสร้างบัญชี / ตรวจข้อมูล
    check('S1a ไม่เลือกตำบล → ปฏิเสธ', 'อย่างน้อย 1 ตำบล' in create(areas=[]))
    n_after_empty = q("SELECT COUNT(*) FROM users WHERE role='subdistrict'")
    check('S1b ไม่เลือกตำบล → ไม่สร้างบัญชี', n_after_empty == '0', n_after_empty)
    check('S1c ตำบลที่ไม่มีอยู่จริง → ปฏิเสธ', q("SELECT COUNT(*) FROM users WHERE role='subdistrict'") == '0' and 'ไม่ถูกต้อง' in create(areas=['99999|ไม่มีตำบลนี้']))
    check('S1d รหัสผ่านอ่อน → ปฏิเสธ', 'อย่างน้อย 10' in create(password='abc'))
    check('S1e ชื่อผู้ใช้ขึ้นต้นด้วย wat → ปฏิเสธ', 'wat' in create(username='watboss'))
    msg = create()
    check('S1 สร้างบัญชีเจ้าคณะตำบล 2 ตำบลสำเร็จ', 'สร้างบัญชีเจ้าคณะตำบลแล้ว' in msg and q("SELECT COUNT(*) FROM user_subdistricts") == '2', msg)
    check('S1f ชื่อผู้ใช้ซ้ำ → ปฏิเสธ', 'มีอยู่แล้ว' in create())
    check('S2 ไม่นับในโควตาผู้ใช้ระดับจังหวัด/อำเภอ (ตัวเลขเดิม)', quota_text(adm) == q0, f'{q0} -> {quota_text(adm)}')

    # ---- เข้าสู่ระบบและขอบเขตข้อมูล
    t = Client(base)
    c, loc, _ = t.post('/login.php', {'username': 'tambon1', 'password': PW})
    check('S3 เข้าสู่ระบบด้วยรหัสผ่าน (ปิด 2FA ของบุคลากรในชุดทดสอบ)', c == 302 and loc == 'index.php', f'{c} {loc}')
    c, _, b = t.req('/dashboard.php')
    nav = re.search(r'<nav class="hidden.*?</nav>', b, re.S).group(0)
    check('S4 แดชบอร์ดเฉพาะตำบลที่ดูแล: รายรับ 3,000 (ไม่รวมวัดนอกตำบล 50,000)', c == 200 and '3,000.00' in b and '53,000' not in b and 'แดชบอร์ดเจ้าคณะตำบล' in b, re.sub(r'\s+', ' ', re.sub('<[^>]+>', ' ', b))[:200])
    check('S5 เมนู: ดูได้ แดชบอร์ด/รายงาน/ข้อมูลวัด/บุคลากร — ไม่มีบัญชีรายรับ-รายจ่าย อนุโมทนาบัตร จัดการระบบ',
          all(x in nav for x in ('แดชบอร์ด', 'รายงาน', 'ข้อมูลวัด', 'บุคลากร')) and not any(x in nav for x in ('บัญชีรายรับ', 'บัญชีรายจ่าย', 'อนุโมทนาบัตร', 'จัดการระบบ')))
    c, _, csvb = t.req(f'/reports.php?year={be}&export=csv')
    check('S6 CSV รายงานมีเฉพาะรายการของวัดในตำบลที่ดูแล', c == 200 and '1000' in csvb and '2000' in csvb and '50000' not in csvb, csvb[:200])
    c, _, b = t.req(f'/reports.php?year={be}')
    check('S7 หน้ารายงานสรุปรวม 3,000 ไม่รวม 50,000', c == 200 and '3,000.00' in b and '53,000' not in b)
    c, _, b = t.req(f'/statements.php?temple={tA}&year={be}&month=6&form=1')
    check('S8 ดูรายงานแบบที่ 1 ของวัดในตำบลได้ และไม่มีลิงก์ตั้งยอดยกมา/แก้บัญชี', c == 200 and nameOf(tA) in b and 'accounts.php' not in b, b[:0])
    c, _, b = t.req(f'/statements.php?temple={tC}&year={be}&month=6&form=1')
    check('S9 รายงานแบบที่ 1 ของวัดนอกตำบล → ไม่แสดงข้อมูลวัดนั้น', nameOf(tC) not in b and 'จ่ายตรวจสอบ' not in b and '50000' not in b)
    c, _, b = t.req(f'/people.php?temple={tA}')
    check('S10 ดูบุคลากรของวัดในตำบลได้ (เลขบัตรปิดบัง ไม่เห็นที่อยู่ ไม่มีปุ่มเพิ่ม/แก้ไข)', c == 200 and 'สมชาย' in b and 'เพิ่มบุคลากร' not in b and 'บ้านเลขที่ 1 ตำบลทดสอบ' not in b)
    c, _, b = t.req(f'/people.php?temple={tC}')
    check('S11 ดูบุคลากรของวัดนอกตำบล → 403', c == 403)
    c, _, b = t.req(f'/accounts.php?temple={tC}')
    check('S11b หน้าบัญชีเงินสด/ธนาคารของวัดนอกตำบล → ไม่แสดงข้อมูล', nameOf(tC) not in b.split('<select')[-1] if '<select' in b else nameOf(tC) not in b)

    # ---- เขียนไม่ได้
    c, loc, _ = t.req('/transactions.php?type=income')
    check('S12 หน้าบัญชีรายรับ → ส่งกลับแดชบอร์ด (ดูอย่างเดียว)', c == 302 and loc == 'dashboard.php')
    before = q('SELECT COUNT(*) FROM transactions')
    t.post('/transactions.php?type=income', {'action': 'save', 'temple_id': tA, 'category_id': cat_in, 'amount': '999', 'txn_day': '1', 'txn_month': '6', 'txn_year': str(be)}, '/dashboard.php')
    check('S13 POST บันทึกรายการ → ไม่สร้างรายการใหม่', q('SELECT COUNT(*) FROM transactions') == before)
    check('S14 อนุโมทนาบัตร → ส่งกลับแดชบอร์ด', t.req('/receipts.php')[0] == 302)
    check('S15 หน้า จัดการระบบ/จัดการเจ้าคณะตำบล/ข้อมูลวัด(แก้ไข) → 403', all(t.req(p)[0] == 403 for p in ['/admin/index.php', '/admin/subdistricts.php', '/admin/users.php', f'/profile.php?temple={tA}']))
    c, _, b = t.req('/account.php')
    check('S16 หน้าบัญชีของฉันแสดงบทบาทและตำบลที่ดูแล', c == 200 and 'เจ้าคณะตำบล' in b and A[1] in b and B[1] in b)

    # ---- แอดมินหลักจัดการบัญชี
    uid = q("SELECT id FROM users WHERE username='tambon1'")

    def post(action, **kw):
        import urllib.parse
        tok = adm.csrf('/admin/subdistricts.php')
        d = {'action': action, 'id': uid, 'csrf': tok}
        d.update({k: v for k, v in kw.items() if k != 'areas'})
        raw = urllib.parse.urlencode(d).encode()
        for a in kw.get('areas', []):
            raw += b'&' + urllib.parse.urlencode({'areas[]': a}).encode()
        adm.req('/admin/subdistricts.php', raw=raw, headers={'Content-Type': 'application/x-www-form-urlencoded'})
        return notice(adm.req('/admin/subdistricts.php')[2])

    check('S17 ลดเหลือตำบล B → แดชบอร์ดเห็น 2,000', 'บันทึกตำบลที่ดูแลแล้ว' in post('areas', areas=[keyB]) and '2,000.00' in t.req('/dashboard.php')[2] and '3,000' not in t.req('/dashboard.php')[2])
    check('S18 ตำบลที่ไม่ได้รับมอบหมายแล้ว (A) → ดูบุคลากรวัด A ไม่ได้', t.req(f'/people.php?temple={tA}')[0] == 403 and t.req(f'/people.php?temple={tB}')[0] == 200)
    check('S19 ลบตำบลทั้งหมดไม่ได้', 'อย่างน้อย 1 ตำบล' in post('areas', areas=[]) and q('SELECT COUNT(*) FROM user_subdistricts') == '1')
    check('S20 ใส่ตำบลของคนละอำเภอ (C) → เห็นรวม 52,000 หลายอำเภอ', 'บันทึกตำบลที่ดูแลแล้ว' in post('areas', areas=[keyB, keyC]) and '52,000.00' in t.req('/dashboard.php')[2])
    check('S21 ตั้งอีเมลใหม่ได้ / อีเมลผิดรูปแบบถูกปฏิเสธ', 'บันทึกอีเมลแล้ว' in post('set_email', email='new@example.org') and 'ไม่ถูกต้อง' in post('set_email', email='bad'))
    check('S22 รีเซ็ตรหัสผ่านอ่อนถูกปฏิเสธ', 'อย่างน้อย 10' in post('reset', password='short'))
    post('reset', password='Another12345pw')
    t2 = Client(base)
    c1, l1, _ = t2.post('/login.php', {'username': 'tambon1', 'password': PW})
    t3 = Client(base)
    c2, l2, _ = t3.post('/login.php', {'username': 'tambon1', 'password': 'Another12345pw'})
    check('S23 รีเซ็ตรหัสผ่านแล้ว: รหัสเดิมใช้ไม่ได้ รหัสใหม่เข้าได้', l1 != 'index.php' and l2 == 'index.php', f'{l1} {l2}')
    post('toggle')
    t4 = Client(base)
    c3, l3, _ = t4.post('/login.php', {'username': 'tambon1', 'password': 'Another12345pw'})
    check('S24 ปิดใช้งานบัญชี → เข้าสู่ระบบไม่ได้', l3 != 'index.php')
    post('toggle')
    check('S25 เปิดใช้งานอีกครั้ง → เข้าได้', Client(base).post('/login.php', {'username': 'tambon1', 'password': 'Another12345pw'})[1] == 'index.php')
    # บทบาทอื่นทำเรื่องนี้ไม่ได้
    check('S26 บัญชีที่ไม่ใช่แอดมินหลักเรียกหน้าจัดการเจ้าคณะตำบลไม่ได้', t3.req('/admin/subdistricts.php')[0] == 403)
    post('delete')
    check('S27 ลบบัญชี → ตำบลที่มอบหมายถูกลบตาม (cascade)', q("SELECT COUNT(*) FROM users WHERE role='subdistrict'") == '0' and q('SELECT COUNT(*) FROM user_subdistricts') == '0')
    check('S28 หลังลบ ผู้ใช้ระดับจังหวัด/อำเภอและโควตายังเหมือนเดิม', quota_text(adm) == q0)
