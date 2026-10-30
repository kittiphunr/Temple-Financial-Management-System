"""ชุดที่ 8: นำเข้าทะเบียนวัดจากไฟล์ Excel (.xlsx) รูปแบบ binfo.onab.go.th — ใช้กับจังหวัดอื่นได้

ใช้ไฟล์จริงของจังหวัดแพร่ (tests/fixtures/Report_Temple_phrae.xlsx, 381 วัด) เทียบกับ data/temples_phrae.csv ที่แปลงด้วยสคริปต์เดิม
และไฟล์สังเคราะห์ของ "จังหวัดน่าน" ที่สร้างในชุดทดสอบ (ครอบคลุมกรณีผิดปกติและไฟล์ประสงค์ร้าย)
"""
import csv
import io
import json
import re
import zipfile
from pathlib import Path

from support import App, Client, ROOT, notice, parse_creds_csv

FIXTURE = ROOT / 'tests' / 'fixtures' / 'Report_Temple_phrae.xlsx'
HEAD = ['ลำดับ', 'รหัสวัด', 'ชื่อวัด', 'ประเภทวัด', 'นิกาย', 'ประเภทการขึ้นทะเบียน', 'วันที่ตั้งวัด', 'ได้รับวิสุงคามสีมา', 'วันที่ได้รับวิสุงคามสีมา', 'วันที่สถานะวัด',
        'แขวง/ตำบล', 'เขต/อำเภอ', 'จังหวัด', 'รหัสไปรษณีย์', 'เบอร์โทรศัพท์', 'โทรสาร', 'อีเมล์', 'เว็บไซต์', 'ชื่อหมู่บ้าน', 'บ้านเลขที่', 'หมู่', 'ซอย', 'ถนน', 'หมายเหตุ']


def col(i):
    s = ''
    i += 1
    while i:
        i, r = divmod(i - 1, 26)
        s = chr(65 + r) + s
    return s


def make_xlsx(rows, inline=True, prefix=''):
    """สร้างไฟล์ .xlsx เล็ก ๆ ด้วย zipfile (ไม่ต้องมีไลบรารี) — inline=True ใช้ inlineStr, prefix='x:' ทดสอบ namespace prefix"""
    p = prefix
    ns = f'xmlns{":" + p[:-1] if p else ""}="http://schemas.openxmlformats.org/spreadsheetml/2006/main"'
    shared = []

    def cell(r, c, v):
        ref = f'{col(c)}{r + 1}'
        if v is None or v == '':
            return f'<{p}c r="{ref}"/>'
        if isinstance(v, (int, float)):
            return f'<{p}c r="{ref}"><{p}v>{v}</{p}v></{p}c>'
        t = str(v).replace('&', '&amp;').replace('<', '&lt;')
        if inline:
            return f'<{p}c r="{ref}" t="inlineStr"><{p}is><{p}t>{t}</{p}t></{p}is></{p}c>'
        shared.append(t)
        return f'<{p}c r="{ref}" t="s"><{p}v>{len(shared) - 1}</{p}v></{p}c>'
    body = ''.join(f'<{p}row r="{i + 1}">' + ''.join(cell(i, j, v) for j, v in enumerate(row)) + f'</{p}row>' for i, row in enumerate(rows))
    sheet = f'<?xml version="1.0" encoding="UTF-8"?><{p}worksheet {ns}><{p}sheetData>{body}</{p}sheetData></{p}worksheet>'
    buf = io.BytesIO()
    with zipfile.ZipFile(buf, 'w', zipfile.ZIP_DEFLATED) as z:
        z.writestr('[Content_Types].xml', '<?xml version="1.0"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"/>')
        z.writestr('xl/workbook.xml', f'<?xml version="1.0"?><workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets><sheet name="S" sheetId="1" r:id="rId1"/></sheets></workbook>')
        z.writestr('xl/_rels/workbook.xml.rels', '<?xml version="1.0"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="x/worksheet" Target="worksheets/sheet1.xml"/></Relationships>')
        z.writestr('xl/worksheets/sheet1.xml', sheet)
        if not inline:
            z.writestr('xl/sharedStrings.xml', '<?xml version="1.0"?><sst xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">' + ''.join(f'<si><t>{s}</t></si>' for s in shared) + '</sst>')
    return buf.getvalue()


def trow(**kw):
    r = [''] * len(HEAD)
    for k, v in kw.items():
        r[HEAD.index(k)] = v
    return r


def run(db, R):
    name = db.new_database('import')
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
    app.php('setup.php', 'Test12345pw')
    base = app.serve()
    adm = Client(base)
    adm.post('/login.php', {'username': 'admin', 'password': 'Test12345pw'})
    xl_bytes = FIXTURE.read_bytes()

    def upload(cl, content, filename='f.xlsx', verified=True, emails=False):
        fields = {'action': 'preview'}
        if verified:
            fields['verified'] = '1'
        if emails:
            fields['set_emails'] = '1'
        return cl.upload('/admin/import.php', fields, filename, content, '/admin/import.php', field='file', content_type='application/octet-stream')

    def review(cl, content, **kw):
        c, loc, b = upload(cl, content, **kw)
        if loc and 'review=' in loc:
            return loc.split('review=')[1], cl.req('/admin/import.php?review=' + loc.split('review=')[1])[2], None
        return None, None, notice(cl.req('/admin/import.php')[2])

    leftovers = lambda: list((app.dir / 'storage').glob('import_*.json'))

    # ---------------- A: ไฟล์จริงของจังหวัดแพร่ ----------------
    check('A0 ตรวจ: ฐานข้อมูลติดตั้งด้วย 381 วัด 8 อำเภอ', q("SELECT COUNT(*) FROM temples") == '381' and q("SELECT COUNT(*) FROM districts") == '8')
    tok, pg, err = review(adm, xl_bytes)
    check('A1 อัปโหลด Excel จริง (381 วัด) → หน้าตรวจก่อนนำเข้า แสดง 381 วัด จังหวัดแพร่ ไม่ต่างจากระบบ และยังไม่บันทึกอะไร',
          tok is not None and '381' in pg and 'แพร่' in pg and 'ต่างกัน' not in pg and q("SELECT COUNT(*) FROM temples") == '381', str(err))
    check('A2 สรุปอำเภอ 8 อำเภอ "มีอยู่แล้ว" และวัดทั้ง 381 เป็นการอัปเดต (ไม่มีวัดใหม่)', pg.count('มีอยู่แล้ว') >= 8 and re.search(r'วัดใหม่</div><div class="text-2xl font-bold text-emerald-800">0<', pg) is not None)
    check('A3 ไฟล์พักข้อมูลเก็บใน storage/ (สิทธิ์ 0600) ไม่อยู่ในเว็บ', len(leftovers()) == 1 and oct(leftovers()[0].stat().st_mode & 0o777) == '0o600')
    # เทียบกับ CSV เดิม
    out = app.php('-r', "require 'includes/temple_import.php'; echo json_encode(temple_import_parse_xlsx('" + str(FIXTURE) + "'), JSON_UNESCAPED_UNICODE);").stdout
    parsed = json.loads(out)
    csvrows = list(csv.reader(open(ROOT / 'data' / 'temples_phrae.csv', encoding='utf-8-sig')))[1:]
    key = lambda r: r[6]
    by_code = {r['code']: r for r in parsed['rows']}
    keys = ['district', 'name', 'subdistrict', 'moo', 'code', 'type', 'sect', 'village', 'house_no', 'soi', 'road', 'postcode', 'founded']
    diffs = []
    for r in csvrows:
        p = by_code.get(r[6])
        want = [r[0], r[1], r[2] or None, r[3] or None, r[6], r[7] or None, r[8] or None, r[9] or None, r[10] or None, r[11] or None, r[12] or None, r[13] or None, int(r[14]) if r[14] else None]
        got = [p[k] if p else 'MISSING' for k in keys]
        if got != want:
            diffs.append((r[1], want, got))
    check('A4 ตัวอ่าน Excel ในระบบให้ผลตรงกับ CSV ที่แปลงด้วยสคริปต์เดิมทุกวัด ทุกช่อง (381 วัด)', len(parsed['rows']) == 381 and not diffs and parsed['province'] == ['แพร่'], str(diffs[:2]))
    adm.post('/admin/import.php', {'action': 'confirm', 'token': tok, 'province': 'แพร่'}, '/admin/import.php?review=' + tok)
    c, _, pg = adm.req('/admin/import.php?done=1')
    check('A5 ยืนยันนำเข้า: อัปเดต 381 วัด ไม่เพิ่มซ้ำ ไม่สร้างบัญชีใหม่ ไม่เปลี่ยนจำนวนอำเภอ',
          'อัปเดต <strong>381</strong>' in pg and 'วัดใหม่ <strong>0</strong>' in pg and q("SELECT COUNT(*) FROM temples") == '381' and q("SELECT COUNT(*) FROM districts") == '8' and q("SELECT COUNT(*) FROM users WHERE role='temple'") == '381')
    check('A6 ไฟล์พักถูกลบหลังยืนยัน', not leftovers())

    # ---------------- B: ไฟล์ผิดรูปแบบ / ประสงค์ร้าย ----------------
    t, _, err = review(adm, b'not a zip at all')
    check('B1 ไฟล์ที่ไม่ใช่ .xlsx ถูกปฏิเสธด้วยข้อความ', t is None and 'ไม่ใช่ไฟล์ Excel' in err, err)
    t, _, err = review(adm, make_xlsx([['ก', 'ข'], ['1', '2']]))
    check('B2 ไฟล์ที่ไม่มีหัวคอลัมน์ ชื่อวัด/เขต-อำเภอ ถูกปฏิเสธและบอกวิธีแก้', t is None and 'ชื่อวัด' in err, err)
    t, _, err = review(adm, make_xlsx([HEAD]))
    check('B3 ไฟล์มีแต่หัวตาราง (ไม่มีวัด) ถูกปฏิเสธ', t is None and 'ไม่พบรายชื่อวัด' in err, err)
    xxe = make_xlsx([HEAD, trow(ชื่อวัด='X', **{'เขต/อำเภอ': 'เมือง', 'จังหวัด': 'ทดสอบ', 'รหัสวัด': '03550101001'})], inline=False)
    z = zipfile.ZipFile(io.BytesIO(xxe))
    buf = io.BytesIO()
    with zipfile.ZipFile(buf, 'w') as zo:
        for n in z.namelist():
            d = z.read(n)
            if n == 'xl/sharedStrings.xml':
                d = d.replace(b'<sst ', b'<!DOCTYPE sst [<!ENTITY e SYSTEM "file:///etc/passwd">]><sst ').replace(b'<si><t>X</t></si>', b'<si><t>&e;X</t></si>')
            zo.writestr(n, d)
    t, pg2, err = review(adm, buf.getvalue())
    blob = (pg2 or '') + (err or '')
    check('B4 ไฟล์ที่พยายามอ่านไฟล์ระบบผ่าน XML external entity (XXE) ไม่ได้ข้อมูลใด ๆ (ไม่มี root: ใน /etc/passwd)', 'root:x:' not in blob and 'root:' not in blob)
    if t:
        adm.post('/admin/import.php', {'action': 'cancel', 'token': t}, '/admin/import.php?review=' + t)
    # ปลอมโทเคน / ยกเลิก
    adm.post('/admin/import.php', {'action': 'confirm', 'token': 'a' * 24}, '/admin/import.php')
    check('B5 ยืนยันด้วยโทเคนปลอมไม่ได้ (ไม่เปลี่ยนข้อมูล)', q("SELECT COUNT(*) FROM temples") == '381')
    t, _, _ = review(adm, make_xlsx([HEAD, trow(ชื่อวัด='ยกเลิกทดสอบ', **{'เขต/อำเภอ': 'เมืองแพร่', 'จังหวัด': 'แพร่'})]))
    adm.post('/admin/import.php', {'action': 'cancel', 'token': t}, '/admin/import.php?review=' + t)
    check('B6 ยกเลิกแล้วไฟล์พักถูกลบและไม่บันทึก', not leftovers() and q("SELECT COUNT(*) FROM temples") == '381')
    # สิทธิ์
    T = parse_creds_csv(sorted((app.dir / 'storage').glob('temple_accounts_*.csv'))[0].read_text(encoding='utf-8'))[0]
    anon = Client(base)
    check('B7 ผู้ที่ไม่ใช่แอดมินหลักเปิด/ส่งหน้านำเข้าไม่ได้ (ไม่ล็อกอิน = ส่งไปล็อกอิน)', anon.req('/admin/import.php')[0] in (302, 403) and anon.req('/admin/import.php')[1] is not None)
    c, _, _ = adm.req('/admin/import.php', data={'action': 'preview'})
    check('B8 ส่งฟอร์มโดยไม่มี CSRF token ถูกปฏิเสธ (400)', c == 400, str(c))

    # ---------------- C: ไฟล์ของจังหวัดน่าน (จังหวัดอื่น) ----------------
    q("DELETE FROM users WHERE role='temple'")
    q("DELETE FROM accounts")
    q("DELETE FROM temples")
    q("UPDATE users SET district_id=NULL WHERE role='admin'")
    base_cols = {'จังหวัด': 'น่าน', 'ประเภทวัด': 'วัดราษฎร์', 'นิกาย': 'มหานิกาย'}
    rows = [HEAD,
            trow(รหัสวัด='03550101001', ชื่อวัด='ภูมินทร์', **{'วันที่ตั้งวัด': '2143', 'แขวง/ตำบล': 'ในเวียง', 'เขต/อำเภอ': 'เมืองน่าน', 'รหัสไปรษณีย์': '55000', 'หมู่': '5 ', 'อีเมล์': 'phumin@wat.test', **base_cols}),
            trow(รหัสวัด='03550101002', ชื่อวัด='วัดช้างค้ำ', **{'วันที่ตั้งวัด': '12/3/2159', 'แขวง/ตำบล': 'ตำบลในเวียง', 'เขต/อำเภอ': 'อำเภอเมืองน่าน', 'เบอร์โทรศัพท์': '054-710-000', 'อีเมล์': 'ไม่ใช่อีเมล', **base_cols}),
            trow(รหัสวัด='03550201001', ชื่อวัด='ห้วยเขน', **{'ประเภทวัด': 'สำนักสงฆ์', 'จังหวัด': 'น่าน', 'วันที่ตั้งวัด': '1985', 'แขวง/ตำบล': 'ปัว', 'เขต/อำเภอ': 'ปัว'}),
            trow(รหัสวัด='03550201001', ชื่อวัด='ซ้ำรหัส', **{'เขต/อำเภอ': 'ปัว', **base_cols}),
            trow(รหัสวัด='03550301001', ชื่อวัด='ท่าวังผา', **{'เขต/อำเภอ': 'ท่าวังผา', 'แขวง/ตำบล': 'ป่าคา', 'รหัสไปรษณีย์': '5500', **base_cols}),
            trow(ชื่อวัด='ไม่มีอำเภอ', **base_cols),
            trow(ชื่อวัด='ไม่มีรหัสวัด', **{'เขต/อำเภอ': 'ปัว', 'แขวง/ตำบล': 'วรนคร', **base_cols})]
    for variant, kw in (('inline', dict(inline=True)), ('sharedStrings+prefix', dict(inline=False, prefix='x:'))):
        t, pg, err = review(adm, make_xlsx(rows, **kw))
        check(f'C0 ตัวอ่านรองรับไฟล์แบบ {variant}', t is not None, str(err))
        if variant != 'sharedStrings+prefix':
            adm.post('/admin/import.php', {'action': 'cancel', 'token': t}, '/admin/import.php')
    check('C1 หน้าตรวจ: จังหวัดในไฟล์ "น่าน" ต่างจากระบบ (แพร่) 3 อำเภอใหม่ (ท่าวังผา ปัว เมืองน่าน) วัดใหม่ 5 ข้าม 2 (ซ้ำรหัส + ไม่มีอำเภอ)',
          'ต่างกัน' in pg and 'น่าน' in pg and pg.count('อำเภอใหม่') >= 3 and '3</div></div>' not in pg and re.search(r'วัดใหม่</div><div class="text-2xl font-bold text-emerald-800">5<', pg) is not None
          and re.search(r'ข้าม[^<]*</div><div class="text-2xl font-bold">2<', pg) is not None, '')
    adm.post('/admin/import.php', {'action': 'confirm', 'token': t, 'province': 'น่าน', 'create_districts': '1', 'set_province': '1', 'prune_districts': '1'}, '/admin/import.php?review=' + t)
    c, _, pg = adm.req('/admin/import.php?done=1')
    check('C2 นำเข้า: วัดใหม่ 5 อำเภอใหม่ 3 ลบอำเภอแพร่ที่ว่าง 8 และสร้างบัญชีแอดมินวัด 5 บัญชี',
          'วัดใหม่ <strong>5</strong>' in pg and 'สร้างอำเภอใหม่ 3 อำเภอ' in pg and 'ลบอำเภอที่ไม่มีวัด 8 อำเภอ' in pg and 'สร้างบัญชีแอดมินวัดใหม่ 5 บัญชี' in pg, notice(pg))
    check('C3 อำเภอเหลือ 3 (เมืองน่าน ปัว ท่าวังผา) รหัสอำเภอมาจากรหัสวัด (5501 5502 5503)', q("SELECT GROUP_CONCAT(CONCAT(code,':',name) ORDER BY code) FROM districts") == '5501:เมืองน่าน,5502:ปัว,5503:ท่าวังผา')
    check('C4 ชื่อวัด: เติม "วัด" ให้ชื่อที่ไม่มี, ไม่ซ้ำ "วัด" ที่มีแล้ว, "สำนักสงฆ์" ตามประเภท', q("SELECT GROUP_CONCAT(name ORDER BY name) FROM temples WHERE name LIKE '%ภูมินทร์' OR name LIKE '%ช้างค้ำ' OR name LIKE '%ห้วยเขน'") == 'วัดช้างค้ำ,วัดภูมินทร์,สำนักสงฆ์ห้วยเขน')
    check('C5 ปรับค่า: อำเภอ/ตำบลตัดคำนำหน้า, หมู่ตัดช่องว่าง, ปีที่ตั้งเก็บตามไฟล์ (วันที่เต็มใช้ปีท้าย), รหัสไปรษณีย์ผิดรูปแบบเป็นว่าง',
          q("SELECT CONCAT(subdistrict,'|',moo,'|',founded_be) FROM temples WHERE temple_code='03550101001'") == 'ในเวียง|5|2143'
          and q("SELECT CONCAT(subdistrict,'|',founded_be) FROM temples WHERE temple_code='03550101002'") == 'ในเวียง|2159'
          and q("SELECT founded_be FROM temples WHERE temple_code='03550201001'") == '1985'
          and q("SELECT COALESCE(postcode,'ว่าง') FROM temples WHERE temple_code='03550301001'") == 'ว่าง')
    check('C6 อีเมลในไฟล์ไม่ถูกนำเข้า (ไม่ได้ติ๊ก) และวัดทุกวัดถูกทำเครื่องหมายตรวจสอบแล้ว', q("SELECT COUNT(*) FROM temples WHERE email IS NOT NULL") == '0' and q("SELECT COUNT(*) FROM temples WHERE verified=1") == '5')
    _, _, home = Client(base).req('/login.php')
    c, _, dash = adm.req('/admin/users.php')
    check('C7 ตั้งชื่อจังหวัดของระบบเป็น "น่าน": ข้อความใต้ชื่อระบบ/ท้ายหน้า และโควตาผู้ใช้ = 3 อำเภอ + 2 = 5 คน', 'จังหวัดน่าน' in home and 'ใช้ไป 1 จาก 5 คน' in dash and q("SELECT v FROM settings WHERE k='province_name'") == 'น่าน')
    tid = q("SELECT id FROM temples WHERE temple_code='03550101001'")
    q(f"UPDATE accounts SET name='x' WHERE temple_id={tid}")
    q("INSERT INTO accounts (temple_id, kind, cash_flag, name) VALUES (%s, 'cash', 1, 'เงินสด') ON DUPLICATE KEY UPDATE name='เงินสด'" % tid)
    c, _, st = adm.req(f'/statements.php?temple={tid}&month=1&year=2569&form=1')
    check('C8 หัวรายงานแบบที่ 1 ใช้จังหวัดน่านและชื่อวัด/ตำบล/อำเภอจากไฟล์', 'น่าน' in st and 'วัดภูมินทร์' in st and 'เมืองน่าน' in st and 'ในเวียง' in st)
    # นำเข้าซ้ำพร้อมอีเมล: เติมเฉพาะที่ยังว่างและอีเมลถูกต้อง
    q("UPDATE temples SET email='keep@wat.test' WHERE temple_code='03550101002'")
    t, pg, _ = review(adm, make_xlsx(rows[:3] + [rows[3]], inline=True), emails=True)
    adm.post('/admin/import.php', {'action': 'confirm', 'token': t, 'province': 'น่าน'}, '/admin/import.php?review=' + t)
    check('C9 นำเข้าซ้ำพร้อมอีเมล: ลงอีเมลที่ถูกต้องให้วัดที่ยังว่าง ไม่ทับอีเมลเดิม ไม่ลงอีเมลที่ผิดรูปแบบ และไม่เพิ่มวัดซ้ำ',
          q("SELECT email FROM temples WHERE temple_code='03550101001'") == 'phumin@wat.test' and q("SELECT email FROM temples WHERE temple_code='03550101002'") == 'keep@wat.test' and q("SELECT COUNT(*) FROM temples") == '5')
    # CSV ผ่านขั้นตอนเดียวกัน
    csvb = ('อำเภอ,ชื่อวัด,ตำบล\nเมืองน่าน,วัดมิ่งเมือง,ในเวียง\nสองแคว,วัดสองแคว,ยอด\n').encode('utf-8-sig')
    t, pg, err = review(adm, csvb, filename='t.csv')
    check('C10 CSV ผ่านหน้าตรวจเดียวกัน และอำเภอใหม่ "สองแคว" สร้างให้ได้', t is not None and 'สองแคว' in pg, str(err))
    adm.post('/admin/import.php', {'action': 'confirm', 'token': t, 'create_districts': '1'}, '/admin/import.php?review=' + t)
    check('C11 นำเข้า CSV แล้วมีวัดมิ่งเมืองและอำเภอสองแคว (4 อำเภอ → โควตา 6)', q("SELECT COUNT(*) FROM temples WHERE name='วัดมิ่งเมือง'") == '1' and q("SELECT COUNT(*) FROM districts") == '4')
    # ปุ่มลบอำเภอที่ว่างในหน้าโลโก้อำเภอ
    q("INSERT INTO districts (code, name, sort_order) VALUES ('9999','อำเภอว่าง',99)")
    c, _, dp = adm.req('/admin/districts.php')
    check('C12 หน้าโลโก้อำเภอเตือนอำเภอที่ว่างและมีปุ่มลบ', 'ลบอำเภอที่ว่าง' in dp)
    adm.post('/admin/districts.php', {'action': 'prune'}, '/admin/districts.php')
    check('C13 กดลบอำเภอที่ว่างแล้วอำเภอว่างหาย (อำเภอที่มีวัดอยู่ไม่ถูกลบ)', q("SELECT COUNT(*) FROM districts WHERE code='9999'") == '0' and q("SELECT COUNT(*) FROM districts") == '4')
    check('C14 ไม่มีไฟล์พักค้างใน storage/', not leftovers())
