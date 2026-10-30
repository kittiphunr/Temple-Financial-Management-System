"""ชุดที่ 24: บัญชีเงินฝากหลายบัญชี ตัวเลือกธนาคารพร้อมโลโก้ (thai-banks-logo) และภาพหน้าบุ๊คแบงก์

ใช้ภาพสังเคราะห์เท่านั้น (ห้ามใช้ภาพบุ๊คแบงก์จริง)
"""
import datetime
import json
import re
from urllib.parse import urlencode

from support import App, Client, FakeSMTP, be_date, hash_password, notice, png_bytes

PW = 'Wat12345pw!'
ROOT_BANKS = None


def run(db, R):
    smtp = FakeSMTP()
    name = db.new_database('bankbook')
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
    banks = json.loads((app.dir / 'data' / 'thai_banks.json').read_text(encoding='utf-8'))
    check('K0 คอลัมน์ bank_code/bookbank_media_id ถูกสร้างพร้อมการติดตั้ง และมีรายชื่อธนาคาร 19 แห่งพร้อมไฟล์โลโก้ครบ',
          q("SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='accounts' AND column_name IN ('bank_code','bookbank_media_id')") == '2'
          and len(banks) == 19 and all((app.dir / b['logo']).is_file() for b in banks) and {'KBANK', 'SCB', 'KTB', 'GSB', 'BAAC'} <= {b['code'] for b in banks}
          and not {'PromptPay', 'TrueMoney'} & {b['code'] for b in banks})
    check('K1 เปิดไฟล์โลโก้ผ่านเว็บได้ และเป็นไฟล์ PNG ต้นฉบับ', Client(base).raw('/assets/banks/KBANK.png')[2][:8] == b'\x89PNG\r\n\x1a\n')

    tA, tB = q("SELECT id FROM temples ORDER BY id LIMIT 1"), q("SELECT id FROM temples ORDER BY id LIMIT 1 OFFSET 1")
    dA = q(f"SELECT district_id FROM temples WHERE id={tA}")
    h = hash_password(app, PW)
    q(f"UPDATE users SET password_hash='{h}', must_change_password=0 WHERE role IN ('temple','province','district','subdistrict')")
    for t, em in ((tA, 'tA@example.org'), (tB, 'tB@example.org')):
        q(f"UPDATE temples SET email='{em}' WHERE id={t}")
    ca, cb = Client(base), Client(base)
    for c, t in ((ca, tA), (cb, tB)):
        c.post('/login.php', {'username': q(f"SELECT username FROM users WHERE temple_id={t}"), 'password': PW})
        c.post('/login_verify.php', {'action': 'verify', 'code': smtp.last_code()})
    d0 = datetime.date.today().replace(year=datetime.date.today().year - 1)

    code, _, pg = ca.req('/accounts.php')
    opts = re.findall(r'name="bank_code" value="([A-Za-z]*)"', pg)
    check('K2 หน้าบัญชีมีตัวเลือกธนาคารพร้อมโลโก้ครบ 19 แห่ง + "อื่น ๆ" และช่องค้นหา', code == 200 and len(opts) == 20 and '' in opts and 'data-bank-search' in pg and 'assets/banks/KBANK.png' in pg and 'ไม่จำกัดจำนวน' in pg)

    def add(cl, code_='KBANK', bank_text='', no='123-4-56789-0', file=None, name='บัญชีวัด', **extra):
        f = {'action': 'add_bank', 'bank_code': code_, 'bank_name': bank_text, 'branch': 'แพร่', 'account_no': no, 'name': name, 'opening_balance': '0', **be_date('opening_date', d0), **extra}
        return cl.upload('/accounts.php', f, 'bb.png' if file else None, file or b'', '/accounts.php', 'bookbank', 'image/png')

    add(ca)
    r = q(f"SELECT CONCAT(bank_code,'|',bank_name,'|',account_no) FROM accounts WHERE temple_id={tA} AND kind='bank'")
    check('K3 เลือกธนาคารจากรายชื่อ: เก็บรหัสและชื่อเต็มของธนาคารให้เอง', r == 'KBANK|ธนาคารกสิกรไทย|123-4-56789-0', r)
    add(ca, code_='', bank_text='สหกรณ์ออมทรัพย์ครูแพร่', no='555-5-55555-5')
    check('K4 เลือก "อื่น ๆ" แล้วพิมพ์ชื่อเอง: bank_code เป็น NULL และเก็บชื่อที่พิมพ์', q("SELECT CONCAT(bank_code IS NULL,'|',bank_name) FROM accounts WHERE account_no='555-5-55555-5'") == '1|สหกรณ์ออมทรัพย์ครูแพร่')
    n = q(f"SELECT COUNT(*) FROM accounts WHERE temple_id={tA} AND kind='bank'")
    add(ca, code_='', bank_text='', no='777-7-77777-7')
    add(ca, code_='NOPE', bank_text='', no='888-8-88888-8')
    check('K5 ไม่เลือกธนาคารและไม่พิมพ์ชื่อ / รหัสธนาคารที่ไม่มีในรายชื่อ → ปฏิเสธ', q(f"SELECT COUNT(*) FROM accounts WHERE temple_id={tA} AND kind='bank'") == n)
    add(ca)
    dupMsg = notice(ca.req('/accounts.php')[2])
    check('K6 ธนาคารและเลขที่บัญชีซ้ำในวัดเดียวกัน → ปฏิเสธ', q(f"SELECT COUNT(*) FROM accounts WHERE temple_id={tA} AND kind='bank'") == n and 'มีบัญชีธนาคารนี้' in dupMsg, dupMsg)

    # หลายบัญชี ไม่จำกัดจำนวน
    for i in range(9):
        add(ca, code_=['SCB', 'KTB', 'GSB', 'BBL', 'BAAC', 'UOB', 'TTB', 'GHB', 'KKP'][i], no=f'900-0-{i:05d}-0')
    add(ca, code_='KBANK', no='123-4-00000-1')           # ธนาคารเดียวกัน เลขบัญชีต่างกัน
    add(ca, code_='SCB', no='123-4-56789-0')             # เลขบัญชีเดียวกัน คนละธนาคาร
    total = q(f"SELECT COUNT(*) FROM accounts WHERE temple_id={tA} AND kind='bank'")
    _, _, pg = ca.req('/accounts.php')
    check('K7 เพิ่มบัญชีธนาคารได้หลายบัญชีไม่จำกัด (13 บัญชี): ธนาคารเดียวกันคนละเลขได้ เลขเดียวกันคนละธนาคารได้ และหน้าบัญชีแสดงจำนวน', total == '13' and 'ปัจจุบันมี 13 บัญชีธนาคาร' in pg, total)
    _, _, pt = ca.req('/transactions.php?type=income')
    check('K8 ทุกบัญชีเลือกในฟอร์มรายรับ/โอนได้ (รายการบัญชีในช่องเลือกครบ)', pt.count('data-bank-map') >= 1 and len(re.findall(r'\d{3}-\d-\d{5}-\d', pt)) >= 12)

    # โลโก้ในหัวบัญชี: จากรหัส และจากชื่อที่พิมพ์เดิม
    q(f"INSERT INTO accounts (temple_id, kind, name, bank_name, account_no, opening_balance, opening_date) VALUES ({tA}, 'bank', 'เก่า', 'ออมสิน', '020-1-23456-7', 0, '2025-01-01')")
    _, _, pg = ca.req('/accounts.php')
    check('K9 หัวบัญชีแสดงโลโก้ธนาคาร: จากรหัส (KBANK) และเดาจากชื่อที่พิมพ์ไว้เดิม (ออมสิน → GSB) ส่วนสหกรณ์ไม่มีโลโก้',
          'assets/banks/KBANK.png' in pg and re.search(r'GSB\.png[^>]*>\s*<span>ธนาคารออมสิน</span>', pg) is not None and re.search(r'<span class="inline-flex items-center gap-2"><span>สหกรณ์ออมทรัพย์ครูแพร่</span>', pg) is not None)

    # แก้ไขธนาคาร
    aid = q(f"SELECT id FROM accounts WHERE account_no='555-5-55555-5'")
    ca.post('/accounts.php', {'action': 'edit_bank', 'account_id': aid, 'bank_code': 'BAAC', 'bank_name': '', 'branch': '', 'account_no': '555-5-55555-5', 'name': 'x'}, '/accounts.php')
    check('K10 แก้ไขธนาคารของบัญชีเดิมจาก "อื่น ๆ" เป็นธนาคารในรายชื่อได้', q(f"SELECT CONCAT(bank_code,'|',bank_name) FROM accounts WHERE id={aid}").startswith('BAAC|ธนาคารเพื่อการเกษตร'))

    # ---------- ภาพหน้าบุ๊คแบงก์ ----------
    book = png_bytes(900, 600, (30, 100, 200, 255))
    add(ca, code_='LHB', no='444-4-44444-4', file=book)
    a4 = q("SELECT id FROM accounts WHERE account_no='444-4-44444-4'")
    mid = q(f"SELECT bookbank_media_id FROM accounts WHERE id={a4}")
    mm = q(f"SELECT CONCAT(kind,'|',mime) FROM media WHERE id={mid}") if mid not in ('', 'NULL') else ''
    check('K11 แนบภาพหน้าบุ๊คแบงก์พร้อมตอนเพิ่มบัญชี: เก็บเป็น WebP (แปลงใหม่)', mm == 'photo|image/webp', mm)
    code, hd, body = ca.raw(f'/media.php?id={mid}')
    check('K12 แอดมินวัดเจ้าของเปิดภาพได้ (WebP ไม่แคชสาธารณะ)', code == 200 and body[:4] == b'RIFF' and body[8:12] == b'WEBP' and 'no-cache' in hd.get('Cache-Control', ''))
    code_b, _, _ = cb.raw(f'/media.php?id={mid}')
    code_n, _, _ = Client(base).raw(f'/media.php?id={mid}')
    check('K13 แอดมินวัดอื่น (403) และผู้ที่ไม่ได้ล็อกอิน เปิดภาพบุ๊คแบงก์ไม่ได้', code_b == 403 and code_n in (302, 401, 403))
    pro = q("SELECT username FROM users WHERE role='province' LIMIT 1")
    sub = q("SELECT username FROM users WHERE role='subdistrict' LIMIT 1")
    dist = q(f"SELECT username FROM users WHERE role='district' AND district_id={dA} LIMIT 1")
    res = {}
    for label, un in (('province', pro), ('subdistrict', sub), ('district', dist)):
        if un:
            c = Client(base)
            c.post('/login.php', {'username': un, 'password': PW})
            res[label] = c.raw(f'/media.php?id={mid}')[0]
    check('K14 เจ้าคณะจังหวัด/ตำบลเปิดภาพบุ๊คแบงก์ไม่ได้ (403) ส่วนแอดมินอำเภอเจ้าของวัดเปิดได้', res.get('province', 403) == 403 and res.get('subdistrict', 403) == 403 and res.get('district', 200) == 200, str(res))
    _, _, pg = ca.req('/accounts.php')
    check('K15 หน้าบัญชีแสดงภาพย่อพร้อมปุ่ม "เปลี่ยนภาพ" และ "ลบภาพ"', f'media.php?id={mid}' in pg and 'เปลี่ยนภาพ' in pg and 'bookbank_remove' in pg)

    # เปลี่ยนภาพ / ลบ / ไฟล์ไม่ถูกต้อง
    def upload(cl, aid_, content, fname='n.png', ct='image/png'):
        return cl.upload('/accounts.php', {'action': 'bookbank_upload', 'account_id': aid_}, fname, content, '/accounts.php', 'bookbank', ct)
    upload(ca, a4, png_bytes(300, 200, (200, 10, 10, 255)))
    mid2 = q(f"SELECT bookbank_media_id FROM accounts WHERE id={a4}")
    check('K16 เปลี่ยนภาพ: ได้ภาพใหม่ และภาพเดิมถูกลบออกจากฐานข้อมูล', mid2 != mid and q(f"SELECT COUNT(*) FROM media WHERE id={mid}") == '0' and q(f"SELECT COUNT(*) FROM media WHERE id={mid2}") == '1')
    for nm, content in (('ข้อความ', b'hello'), ('php', b'<?php system("id"); ?>'), ('เสีย', png_bytes(100, 100)[:50])):
        upload(ca, a4, content)
    check('K17 ไฟล์ที่ไม่ใช่ภาพจริง (ข้อความ/PHP/ภาพเสีย) ถูกปฏิเสธ ภาพเดิมไม่เปลี่ยน', q(f"SELECT bookbank_media_id FROM accounts WHERE id={a4}") == mid2)
    cash = q(f"SELECT id FROM accounts WHERE temple_id={tA} AND kind='cash'")
    upload(ca, cash, png_bytes(100, 100))
    check('K18 บัญชีเงินสดแนบภาพบุ๊คแบงก์ไม่ได้', q(f"SELECT bookbank_media_id IS NULL FROM accounts WHERE id={cash}") == '1')
    upload(cb, a4, png_bytes(100, 100))
    check('K19 แอดมินวัดอื่นแนบ/เปลี่ยนภาพของบัญชีวัดเราไม่ได้', q(f"SELECT bookbank_media_id FROM accounts WHERE id={a4}") == mid2)
    ca.post('/accounts.php', {'action': 'bookbank_remove', 'account_id': a4}, '/accounts.php')
    check('K20 ลบภาพ: bookbank_media_id เป็น NULL และไฟล์หายจาก media', q(f"SELECT bookbank_media_id IS NULL FROM accounts WHERE id={a4}") == '1' and q(f"SELECT COUNT(*) FROM media WHERE id={mid2}") == '0')
    check('K21 การแนบ/ลบภาพบุ๊คแบงก์ถูกบันทึกใน audit log', int(q("SELECT COUNT(*) FROM audit_log WHERE action IN ('bookbank_upload','bookbank_remove')")) >= 2)

    # ---------- ยังไม่ได้รัน migration ----------
    q('ALTER TABLE accounts DROP FOREIGN KEY fk_account_bookbank')
    q('ALTER TABLE accounts DROP COLUMN bookbank_media_id, DROP COLUMN bank_code')
    code, _, pg = ca.req('/accounts.php')
    add(ca, code_='KBANK', bank_text='ธนาคารกรุงศรีอยุธยา', no='321-0-00000-9')
    check('K22 ยังไม่รัน migration: หน้าบัญชียังใช้ได้ ไม่มีช่องแนบภาพ และเพิ่มบัญชีด้วยชื่อที่เลือก/พิมพ์ได้ตามเดิม',
          code == 200 and 'name="bookbank"' not in pg and q("SELECT bank_name FROM accounts WHERE account_no='321-0-00000-9'") == 'ธนาคารกสิกรไทย')
