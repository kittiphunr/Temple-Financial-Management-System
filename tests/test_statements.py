"""ชุดที่ 7: รายงานประจำเดือนแบบที่ 1-3 (เงินสด / เงินฝากธนาคาร / เงินคงเหลือ) และบัญชีเงินสด-ธนาคาร

ใช้ "โจทย์ตัวอย่าง" จากโฟลเดอร์ ตัวอย่างเอกสาร (วัดสยามเทวา ม.ค.-ก.พ. 2568) แล้วตรวจว่าตัวเลขที่ระบบออกตรงกับเฉลยในแบบตัวอย่างทุกช่อง
"""
import re
from datetime import date

from support import App, Client, FakeSMTP, age_otps, be_date, notice, parse_creds_csv


def run(db, R):
    smtp = FakeSMTP()
    name = db.new_database('stmt')
    q = db.q(name)
    app = App(env={
        'TFMS_DB_HOST': db.host, 'TFMS_DB_PORT': db.port, 'TFMS_DB_USER': db.user, 'TFMS_DB_PASS': db.password, 'TFMS_DB_NAME': name,
        'TFMS_MAIL_DRIVER': 'smtp', 'TFMS_SMTP_HOST': '127.0.0.1', 'TFMS_SMTP_PORT': str(smtp.port), 'TFMS_SMTP_SECURE': 'none',
        'TFMS_SMTP_USER': 'u', 'TFMS_SMTP_PASS': 'p', 'TFMS_MAIL_FROM': 'no-reply@wat.test',
    })
    try:
        _run(R, smtp, q, app)
    finally:
        problems = app.php_problems()
        R.check('Z1 ไม่มี PHP warning/notice/fatal ใน log ของเซิร์ฟเวอร์', not problems, '\n'.join(problems[:3]))
        app.close()
        smtp.stop()


def text(html, cls):
    m = re.search(rf'<span class="{cls}">(.*?)</span>', html, re.S)
    return re.sub(r'<[^>]+>|&nbsp;', '', m.group(1)).strip() if m else None


def sheets(html):
    return re.findall(r'<section class="sheet.*?</section>', html, re.S)


def _run(R, smtp, q, app):
    check = R.check
    app.php('setup.php', 'Test12345pw')
    creds = parse_creds_csv(sorted((app.dir / 'storage').glob('temple_accounts_*.csv'))[0].read_text(encoding='utf-8'))
    q("UPDATE users SET must_change_password = 0 WHERE role = 'temple'")
    base = app.serve()
    adm = Client(base)
    adm.post('/login.php', {'username': 'admin', 'password': 'Test12345pw'})

    T = creds[0]
    tid = q(f"SELECT id FROM temples WHERE temple_code='{T[0]}'")
    q(f"UPDATE temples SET name='วัดสยามเทวา', subdistrict='ศีล' WHERE id={tid}")
    for pos, title, fn, ln in (('steward', 'นาย', 'หิริ', 'โอตัปปะ'), ('abbot', 'พระครู', 'ขันติโสรัจจะ', '')):
        q(f"INSERT INTO temple_people (temple_id, position, title, first_name, last_name, national_id_enc, national_id_idx, national_id_last4, home_address, phone, consent_at) "
          f"VALUES ({tid}, '{pos}', '{title}', '{fn}', '{ln}', 'x', '{pos}', '0000', 'ที่อยู่ทดสอบ 1234567890', '0800000000', NOW())")

    # ---------- ตั้งบัญชี: เงินสด + กสิกรไทย + กรุงไทย ----------
    adm.post(f'/accounts.php?temple={tid}', {'action': 'add_bank', 'temple': tid, 'bank_name': 'กสิกรไทย', 'branch': 'สมาธิ', 'account_no': '000-0-00000-0', 'name': 'วัดสยามเทวา',
                                             'opening_balance': '5,000', **be_date('opening_date', date(2025, 1, 1))}, f'/accounts.php?temple={tid}')
    adm.post(f'/accounts.php?temple={tid}', {'action': 'add_bank', 'temple': tid, 'bank_name': 'กรุงไทย', 'branch': 'สมาธิ', 'account_no': '111-1-11111-1', 'name': 'วัดสยามเทวา',
                                             'opening_balance': '3000', **be_date('opening_date', date(2025, 1, 1))}, f'/accounts.php?temple={tid}')
    cash = q(f"SELECT id FROM accounts WHERE temple_id={tid} AND kind='cash'")
    kbank = q(f"SELECT id FROM accounts WHERE temple_id={tid} AND bank_name='กสิกรไทย'")
    kt = q(f"SELECT id FROM accounts WHERE temple_id={tid} AND bank_name='กรุงไทย'")
    check('A1 มีบัญชีเงินสด 1 บัญชี (สร้างอัตโนมัติ) และเพิ่มบัญชีธนาคารได้ 2 บัญชี', bool(cash and kbank and kt) and q(f"SELECT COUNT(*) FROM accounts WHERE temple_id={tid}") == '3')
    adm.post(f'/accounts.php?temple={tid}', {'action': 'set_opening', 'temple': tid, 'account_id': cash, 'opening_balance': '3000', **be_date('opening_date', date(2025, 1, 1))}, f'/accounts.php?temple={tid}')
    check('A2 ตั้งยอดยกมาเงินสดได้ (วันที่ พ.ศ. → ค.ศ.)', q(f"SELECT CONCAT(opening_balance,' ',opening_date) FROM accounts WHERE id={cash}") == '3000.00 2025-01-01')
    adm.post(f'/accounts.php?temple={tid}', {'action': 'add_bank', 'temple': tid, 'bank_name': '', 'account_no': '1', 'opening_balance': '0', **be_date('opening_date', date(2025, 1, 1))}, f'/accounts.php?temple={tid}')
    adm.post(f'/accounts.php?temple={tid}', {'action': 'add_bank', 'temple': tid, 'bank_name': 'ธ.ก.ส.', 'account_no': 'abc', 'opening_balance': '0', **be_date('opening_date', date(2025, 1, 1))}, f'/accounts.php?temple={tid}')
    check('A3 ธนาคารว่าง/เลขที่บัญชีไม่ใช่ตัวเลข ถูกปฏิเสธ', q(f"SELECT COUNT(*) FROM accounts WHERE temple_id={tid}") == '3')

    inc_cat = q("SELECT id FROM categories WHERE type='income' AND is_donation=0 AND is_transfer=0 LIMIT 1")
    exp_cat = q("SELECT id FROM categories WHERE type='expense' AND is_transfer=0 LIMIT 1")

    def add(kind, d, amt, desc, acct='cash'):
        adm.post(f'/transactions.php?type={kind}', {'action': 'save', 'id': '0', 'temple_id': tid, **be_date('txn_date', d), 'category_id': inc_cat if kind == 'income' else exp_cat,
                                                   'amount': str(amt), 'doc_no': '', 'description': desc, 'account_id': acct}, f'/transactions.php?type={kind}')

    def transfer(d, amt, frm, to, desc):
        adm.post(f'/accounts.php?temple={tid}', {'action': 'transfer', 'temple': tid, 'from_id': frm, 'to_id': to, **be_date('txn_date', d), 'amount': str(amt), 'description': desc, 'doc_no': ''}, f'/accounts.php?temple={tid}')

    D = lambda m, d: date(2025, m, d)
    # ---- มกราคม 2568 ----
    add('income', D(1, 1), 1200, 'รับเงินค่าขายดอกไม้ ธูปเทียน'); add('income', D(1, 1), 18000, 'รับเงินบริจาคงานสวดมนต์ข้ามปี')
    transfer(D(1, 2), 19200, cash, kt, 'นำเงินสดฝากธนาคาร กรุงไทย')
    add('income', D(1, 3), 1500, 'รับเงินค่าบริการที่จอดรถ'); add('income', D(1, 4), 2000, 'รับเงินบำรุงค่าน้ำ-ค่าไฟ (ตู้บริจาค)')
    add('income', D(1, 20), 4000, 'รับโอนเงินผลประโยชน์ค่าเช่าที่ดินวัด', kt); add('income', D(1, 22), 500, 'รับเงินจากการขายทรัพย์สินที่ชำรุด')
    add('expense', D(1, 25), 1000, 'จ่ายค่าน้ำประปา เดือนธันวาคม 2567'); add('expense', D(1, 25), 3000, 'เขียนเช็ค ธ.กรุงไทย จ่ายค่าไฟฟ้า', kt)
    add('expense', D(1, 31), 900, 'จ่ายค่าจ้างคนงานทำความสะอาด')
    # ---- กุมภาพันธ์ 2568 ----
    add('expense', D(2, 5), 300, 'จ่ายค่าหนังสือพิมพ์'); add('expense', D(2, 6), 200, 'จ่ายค่าขยะ'); add('expense', D(2, 9), 1200, 'จ่ายเงินสดซื้อพัดลมตั้งโต๊ะ 2 ตัว')
    add('income', D(2, 13), 500, 'รับเงินติดกัณฑ์เทศน์'); add('income', D(2, 13), 1300, 'รับเงินค่าขายดอกไม้ ธูปเทียน'); add('income', D(2, 15), 20990, 'รับเงินบริจาคจากการทอดผ้าป่า')
    transfer(D(2, 15), 23090, cash, kt, 'นำเงินสดฝากธนาคาร กรุงไทย')
    add('income', D(2, 20), 1000, 'รับเงินติดกัณฑ์เทศน์'); add('income', D(2, 24), 200, 'รับดอกเบี้ยเงินฝากประจำ ธ.กสิกรไทย', kbank)
    add('expense', D(2, 25), 30000, 'เขียนเช็ค ธ.กรุงไทย จ่ายค่าก่อสร้างห้องน้ำ', kt); add('expense', D(2, 28), 900, 'จ่ายค่าจ้างคนงานทำความสะอาด')
    check('B1 บันทึกรายการตามโจทย์ครบ (ไม่รวมรายการโอน): 9 + 10 รายการ และรายการโอน 2 คู่', q(f"SELECT COUNT(*) FROM transactions WHERE temple_id={tid} AND transfer_id IS NULL") == '19'
          and q(f"SELECT COUNT(*) FROM transactions WHERE temple_id={tid} AND transfer_id IS NOT NULL") == '4')

    def stmt(month, form='all'):
        return adm.req(f'/statements.php?temple={tid}&month={month}&year=2568&form={form}')[2]

    # ---------- มกราคม ----------
    b = stmt(1, '1')
    check('J1 แบบที่ 1 ม.ค.: ยอดรวมรับ 23,200 รวมจ่าย 21,100 ยอดยกไป 5,100 (ตรงเฉลย)',
          (text(b, 'total-income'), text(b, 'total-expense'), text(b, 'total-closing')) == ('23,200', '21,100', '5,100'), str((text(b, 'total-income'), text(b, 'total-expense'), text(b, 'total-closing'))))
    check('J2 แบบที่ 1: มีแถวยอดยกมา 3,000 และหัวแบบฟอร์มครบ (วัด ตำบล อำเภอ จังหวัด ประจำเดือน พ.ศ.)',
          'ยอดคงเหลือยกมาจากเดือนก่อน' in b and '<b>3,000</b>' in b and 'แบบที่ 1 เงินสด' in b and 'วัดสยามเทวา' in b and 'ศีล' in b and 'ประจำเดือน' in b and 'มกราคม' in b and 'พ.ศ. 2568' in b and 'ตั้งแต่วันที่' in b and 'ถึงวันที่' in b)
    check('J3 ลงนามตามแบบ: ผู้จัดทำบัญชี (ไวยาวัจกร) และเจ้าอาวาส ดึงชื่อจากข้อมูลบุคลากร', '(นายหิริ โอตัปปะ)' in b and '(พระครูขันติโสรัจจะ)' in b and 'เจ้าอาวาสวัดสยามเทวา' in b and 'ตรวจถูกต้องแล้ว' in b)
    check('J4 รายการโอนฝากธนาคารแสดงเป็นรายจ่ายของบัญชีเงินสด', 'นำเงินสดฝากธนาคาร กรุงไทย' in b and '19,200' in b)
    b = stmt(1, '2')
    parts = sheets(b)
    check('J5 แบบที่ 2 ม.ค.: ได้ 2 ตาราง (กสิกรไทย + กรุงไทย) แต่ละบัญชีมีเลขที่/สาขา', len(parts) == 2 and 'กสิกรไทย' in parts[0] and '000-0-00000-0' in parts[0] and 'กรุงไทย' in parts[1] and '1. ชื่อบัญชี' in parts[0] and '2. ชื่อบัญชี' in parts[1])
    check('J6 แบบที่ 2 กสิกร ม.ค.: ไม่มีรายการ ยอดยกไป 5,000', text(parts[0], 'total-income') == '–' and text(parts[0], 'total-closing') == '5,000')
    check('J7 แบบที่ 2 กรุงไทย ม.ค.: รับ 23,200 (ฝาก 19,200 + ค่าเช่า 4,000) จ่าย 3,000 ยอดยกไป 23,200 (ตรงเฉลย)',
          (text(parts[1], 'total-income'), text(parts[1], 'total-expense'), text(parts[1], 'total-net'), text(parts[1], 'total-closing')) == ('23,200', '3,000', '20,200', '23,200'))
    b = stmt(1, '3')
    check('J8 แบบที่ 3 ม.ค.: เงินสด 5,100 + กสิกร 5,000 + กรุงไทย 23,200 = 33,300 (สามหมื่นสามพันสามร้อยบาทถ้วน)',
          text(b, 'bal-cash') == '5,100' and text(b, 'bal-total') == '33,300' and text(b, 'bal-words-text') == 'สามหมื่นสามพันสามร้อยบาทถ้วน' and '5,000' in b and '23,200' in b and 'ณ วันที่' in b and '(ถ้ามี)' in b, str(text(b, 'bal-total')))

    # ---------- กุมภาพันธ์ ----------
    b = stmt(2, '1')
    check('F1 แบบที่ 1 ก.พ.: ยกมา 5,100 รับ 23,790 จ่าย 25,690 สุทธิ –1,900 ยอดยกไป 3,200 (ตรงเฉลย)',
          (text(b, 'total-income'), text(b, 'total-expense'), text(b, 'total-net'), text(b, 'total-closing')) == ('23,790', '25,690', '–1,900', '3,200'), str(text(b, 'total-net')))
    check('F2 แบบที่ 1 ก.พ.: แถวยกมา 5,100 และเดือนย่อ "ก.พ."', '<b>5,100</b>' in b and 'ก.พ.' in b)
    parts = sheets(stmt(2, '2'))
    check('F3 แบบที่ 2 ก.พ. กสิกร: ยกมา 5,000 + ดอกเบี้ย 200 = 5,200 (ตรงเฉลย)', (text(parts[0], 'total-income'), text(parts[0], 'total-closing')) == ('200', '5,200'))
    check('F4 แบบที่ 2 ก.พ. กรุงไทย: ยกมา 23,200 + ฝาก 23,090 – เช็ค 30,000 = 16,290 (ตรงเฉลย)', (text(parts[1], 'total-income'), text(parts[1], 'total-expense'), text(parts[1], 'total-closing')) == ('23,090', '30,000', '16,290'))
    b = stmt(2, '3')
    check('F5 แบบที่ 3 ก.พ.: 3,200 + 5,200 + 16,290 = 24,690 (สองหมื่นสี่พันหกร้อยเก้าสิบบาทถ้วน)', text(b, 'bal-cash') == '3,200' and text(b, 'bal-total') == '24,690' and text(b, 'bal-words-text') == 'สองหมื่นสี่พันหกร้อยเก้าสิบบาทถ้วน')
    b = stmt(2)
    check('F6 ชุดทั้งหมด: แบบ 1 + แบบ 2 (2 บัญชี) + แบบ 3 = 4 แผ่น, แบบ 1-2 แนวนอน แบบ 3 แนวตั้ง', len(sheets(b)) == 4 and b.count('sheet-land') == 3 and b.count('sheet-port') == 1)

    # ---------- เดือนที่ไม่มีรายการ / ก่อนวันเริ่มบัญชี ----------
    b = stmt(3, '1')
    check('M1 มีนาคม (ไม่มีรายการ): ยกมา = ยอดยกไปของ ก.พ. = 3,200 ยอดยกไป 3,200 และตารางมีแถวว่างตามแบบ', text(b, 'total-closing') == '3,200' and b.count('class="blank"') >= 6)
    _, _, b = adm.req(f'/statements.php?temple={tid}&month=12&year=2567&form=1')
    check('M2 เดือนก่อนวันเริ่มบัญชี (ธ.ค. 2567): ยกมา 0 และเตือนว่าอยู่ก่อนวันเริ่มบัญชี', 'อยู่ก่อนวันเริ่มบัญชี' in b and text(b, 'total-closing') == '0')

    # ---------- ไม่นับรายการโอนเป็นรายรับ-รายจ่าย ----------
    _, _, b = adm.req(f'/reports.php?year=2568&temple={tid}')
    check('S1 รายงานสรุปไม่นับรายการโอนซ้ำ: รายรับ 51,190.00 รายจ่าย 37,500.00', '51,190.00' in b and '37,500.00' in b)
    _, _, b = adm.req(f'/transactions.php?type=expense&year=2568&temple={tid}')
    check('S2 หน้าบัญชีรายจ่ายไม่แสดงรายการโอน และแสดงคอลัมน์บัญชี (กรุงไทย)', 'นำเงินสดฝากธนาคาร' not in b and 'กรุงไทย 111-1-11111-1' in b and '<th>บัญชี</th>' in b)
    c, _, b = adm.req(f'/transactions.php?type=expense&year=2568&temple={tid}')
    gid = q(f"SELECT id FROM transactions WHERE temple_id={tid} AND transfer_id=id ORDER BY id LIMIT 1")
    adm.post('/transactions.php?type=expense', {'action': 'delete', 'id': gid}, '/transactions.php?type=expense')
    check('S3 รายการโอนลบผ่านหน้าบัญชีรายจ่ายไม่ได้ (ต้องลบที่หน้าบัญชีเงินสด/ธนาคาร)', q(f"SELECT is_deleted FROM transactions WHERE id={gid}") == '0')
    check('S4 ดูรายการโอนที่หน้าบัญชีเงินสด/ธนาคาร', 'นำเงินสดฝากธนาคาร กรุงไทย' in adm.req(f'/accounts.php?temple={tid}')[2])

    # ---------- ตรวจข้อมูลนำเข้า ----------
    n = q(f"SELECT COUNT(*) FROM transactions WHERE temple_id={tid}")
    transfer(D(3, 1), 100, cash, cash, 'ซ้ำบัญชี'); transfer(D(3, 1), 0, cash, kt, 'ศูนย์'); transfer(D(3, 1), -5, cash, kt, 'ติดลบ')
    check('V1 โอนเข้าบัญชีเดียวกัน/จำนวนเงิน 0/ติดลบ ถูกปฏิเสธ', q(f"SELECT COUNT(*) FROM transactions WHERE temple_id={tid}") == n)
    other = q(f"SELECT id FROM temples WHERE id<>{tid} ORDER BY id LIMIT 1")
    oc = q(f"SELECT id FROM accounts WHERE temple_id={other} LIMIT 1") or q(f"INSERT INTO accounts (temple_id, kind, cash_flag, name) VALUES ({other}, 'cash', 1, 'เงินสด')") or q(f"SELECT id FROM accounts WHERE temple_id={other}")
    transfer(D(3, 1), 100, cash, oc, 'ข้ามวัด')
    add('income', D(3, 1), 50, 'ใช้บัญชีของวัดอื่น', oc)
    check('V2 โอน/บันทึกรายการโดยใช้บัญชีของวัดอื่นไม่ได้', q(f"SELECT COUNT(*) FROM transactions WHERE temple_id={tid}") == n)
    adm.post('/transactions.php?type=income', {'action': 'save', 'id': '0', 'temple_id': tid, 'txn_date_d': '30', 'txn_date_m': '2', 'txn_date_y': '2568', 'category_id': inc_cat, 'amount': '1', 'account_id': 'cash'}, '/transactions.php?type=income')
    check('V3 วันที่ไม่มีจริง (30 ก.พ. 2568) ถูกปฏิเสธ', q(f"SELECT COUNT(*) FROM transactions WHERE temple_id={tid}") == n)
    adm.post('/transactions.php?type=income', {'action': 'save', 'id': '0', 'temple_id': tid, **be_date('txn_date', D(3, 1)), 'category_id': q("SELECT id FROM categories WHERE is_transfer=1 AND type='income'"), 'amount': '1', 'account_id': 'cash'}, '/transactions.php?type=income')
    check('V4 เลือกหมวดโอนของระบบในฟอร์มรายรับปกติไม่ได้', q(f"SELECT COUNT(*) FROM transactions WHERE temple_id={tid}") == n)

    # ---------- ลบรายการโอน -> ยอดย้อนกลับ ----------
    transfer(D(3, 5), 1000, cash, kbank, 'ฝากกสิกร')
    check('D1 โอน 1,000 จากเงินสดไปกสิกร: ยอดสิ้นเดือนมีนาคม เงินสด 2,200 / กสิกร 6,200', text(stmt(3, '3'), 'bal-cash') == '2,200' and '6,200' in stmt(3, '3'))
    g2 = q(f"SELECT id FROM transactions WHERE temple_id={tid} AND transfer_id=id AND description='ฝากกสิกร'")
    adm.post(f'/accounts.php?temple={tid}', {'action': 'delete_transfer', 'temple': tid, 'transfer_id': g2}, f'/accounts.php?temple={tid}')
    check('D2 ลบรายการโอนแล้วทั้งสองฝั่งหาย ยอดกลับเป็นเดิม (เงินสด 3,200)', text(stmt(3, '3'), 'bal-cash') == '3,200' and q(f"SELECT COUNT(*) FROM transactions WHERE transfer_id={g2} AND is_deleted=0") == '0')

    # ---------- สิทธิ์ ----------
    def login_temple(cr, email):
        t = q(f"SELECT id FROM temples WHERE temple_code='{cr[0]}'")
        adm.post('/admin/temple_accounts.php', {'action': 'save_email', 'temple_id': t, 'email': email}, '/admin/temple_accounts.php')
        age_otps(q, f"user_id=(SELECT id FROM users WHERE temple_id={t})")
        cl = Client(base)
        cl.post('/login.php', {'username': cr[3], 'password': cr[4]})
        cl.post('/login_verify.php', {'action': 'verify', 'code': smtp.last_code()})
        return cl
    mine = login_temple(T, 'a@wat.test')
    other_cred = [c for c in creds if c[0] != T[0]][0]
    stranger = login_temple(other_cred, 'b@wat.test')
    check('P1 แอดมินวัดตนเองเห็นรายงานของวัดตน (ไม่ต้องเลือกวัด)', 'ยอดคงเหลือยกมาจากเดือนก่อน' in mine.req('/statements.php?month=1&year=2568&form=1')[2])
    check('P2 แอดมินวัดอื่นใส่ temple= ของวัดนี้ไม่เห็นรายงาน (ได้รายงานวัดตนเอง ไม่ใช่วัดสยามเทวา)', 'วัดสยามเทวา' not in stranger.req(f'/statements.php?temple={tid}&month=1&year=2568&form=1')[2])
    check('P3 แอดมินวัดอื่นเปิดหน้าจัดการบัญชีของวัดนี้ไม่ได้ และส่งฟอร์มแก้ยอดยกมาไม่ได้ (403)',
          'วัดสยามเทวา' not in stranger.req(f'/accounts.php?temple={tid}')[2]
          and stranger.post(f'/accounts.php?temple={tid}', {'action': 'set_opening', 'temple': tid, 'account_id': cash, 'opening_balance': '999999', **be_date('opening_date', date(2025, 1, 1))}, '/account.php')[0] in (403, 302)
          and q(f"SELECT opening_balance FROM accounts WHERE id={cash}") == '3000.00')
    check('P4 แอดมินวัดตนเองบันทึกรายการเข้าบัญชีธนาคารของตนได้', mine.post('/transactions.php?type=income', {'action': 'save', 'id': '0', 'temple_id': tid, **be_date('txn_date', D(3, 9)), 'category_id': inc_cat, 'amount': '10', 'account_id': kbank, 'description': 'วัดทำเอง'}, '/transactions.php?type=income')[0] == 302
          and q(f"SELECT account_id FROM transactions WHERE description='วัดทำเอง'") == kbank)
    check('P5 ฟอร์มบันทึกรายการมีช่อง "บัญชี" และข้อมูลบัญชีธนาคารของวัด', 'name="account_id"' in mine.req('/transactions.php?type=income')[2] and 'กสิกรไทย' in mine.req('/transactions.php?type=income')[2])

    # ---------- รายละเอียดบัญชีอยู่ในหน้า "ข้อมูลวัด" ของแต่ละวัด ----------
    c, _, pf = mine.req('/profile.php')
    check('K1 หน้าข้อมูลวัดมีส่วน "บัญชีของวัด": แสดงเงินสด และบัญชีธนาคาร (ธนาคาร สาขา เลขที่บัญชี ยอดยกมา)',
          c == 200 and 'id="accounts"' in pf and 'เงินสด / เช็ค' in pf and 'กสิกรไทย' in pf and '000-0-00000-0' in pf and 'กรุงไทย' in pf and 'สาขาสมาธิ' in pf and 'เพิ่มบัญชีธนาคาร' in pf)
    check('K2 รายการตรวจในหน้าข้อมูลวัดมีเรื่องบัญชี/ยอดยกมา/เลขผู้เสียภาษี/โทรศัพท์', all(x in pf for x in ('ยอดยกมาเงินสด/เช็ค', 'บัญชีเงินฝากธนาคาร', 'เลขประจำตัวผู้เสียภาษีของวัด', 'โทรศัพท์และที่อยู่วัด')))
    # วัดอื่นที่ยังไม่กรอกบัญชีเลย
    sid = q(f"SELECT id FROM temples WHERE temple_code='{other_cred[0]}'")
    for pg in ('/dashboard.php', '/transactions.php?type=income'):
        check(f'K3 แอดมินวัดที่ยังไม่ตั้งยอดยกมา เห็นแบนเนอร์ชวนกรอกบัญชีของวัด ({pg})', 'ยังไม่ได้ตั้ง' in stranger.req(pg)[2] and 'profile.php#accounts' in stranger.req(pg)[2])
    c, loc, _ = stranger.post('/accounts.php', {'action': 'add_bank', 'back': 'profile', 'bank_name': 'ออมสิน', 'branch': 'แพร่', 'account_no': '020-1-23456-7', 'name': 'วัดทดสอบ',
                                               'opening_balance': '1,500', **be_date('opening_date', date(2025, 1, 1))}, '/profile.php')
    check('K4 เพิ่มบัญชีธนาคารจากหน้าข้อมูลวัด: บันทึกแล้วกลับมาหน้าข้อมูลวัด และเห็นบัญชีใหม่', loc == 'profile.php#accounts' and 'ออมสิน' in stranger.req('/profile.php')[2] and '020-1-23456-7' in stranger.req('/profile.php')[2]
          and q(f"SELECT COUNT(*) FROM accounts WHERE temple_id={sid} AND kind='bank'") == '1')
    cash_s = q(f"SELECT id FROM accounts WHERE temple_id={sid} AND kind='cash'")
    stranger.post('/accounts.php', {'action': 'set_opening', 'back': 'profile', 'account_id': cash_s, 'opening_balance': '800', **be_date('opening_date', date(2025, 1, 1))}, '/profile.php')
    check('K5 ตั้งยอดยกมาเงินสดจากหน้าข้อมูลวัด → แบนเนอร์หาย และรายการตรวจขึ้น ✓', 'profile.php#accounts' not in stranger.req('/dashboard.php')[2]
          and q(f"SELECT opening_balance FROM accounts WHERE id={cash_s}") == '800.00')
    # สิทธิ์: แอดมินวัดแก้บัญชีของวัดอื่นผ่านหน้าข้อมูลวัดไม่ได้
    c, _, _ = stranger.post('/accounts.php', {'action': 'set_opening', 'back': 'profile', 'temple': tid, 'account_id': cash, 'opening_balance': '1', **be_date('opening_date', date(2025, 1, 1))}, '/profile.php')
    check('K6 แอดมินวัดอื่นส่งฟอร์มแก้บัญชีของวัดนี้ไม่ได้ (บัญชีไม่เปลี่ยน)', q(f"SELECT opening_balance FROM accounts WHERE id={cash}") == '3000.00')
    c, _, pa = adm.req(f'/profile.php?temple={tid}')
    check('K7 แอดมินหลักเปิดข้อมูลของวัดใดก็ได้ (?temple=) เห็นบัญชีของวัดนั้นและบันทึกกลับมาหน้าเดิม', 'กสิกรไทย' in pa and f'value="{tid}"' in pa)
    c, loc, _ = adm.post('/accounts.php', {'action': 'set_opening', 'back': 'profile', 'temple': tid, 'account_id': cash, 'opening_balance': '3000', **be_date('opening_date', date(2025, 1, 1))}, f'/profile.php?temple={tid}')
    check('K8 แอดมินหลักบันทึกแล้วกลับหน้าข้อมูลวัดของวัดนั้น', loc == f'profile.php?temple={tid}#accounts')
