"""ชุดที่ 21: นำเข้ารายการจากไฟล์ statement ธนาคาร (CSV/Excel) — แปลงวันที่/จำนวนเงิน เดาคอลัมน์ ตัวอย่าง จัดหมวด ตัดรายการซ้ำ ปิดงวด สิทธิ์"""
import base64
import datetime
import json
import re
import subprocess
from urllib.parse import urlencode

from support import App, Client, FakeSMTP, be_date, hash_password, notice
from test_import import make_xlsx

PW = 'Wat12345pw!'


def run(db, R):
    smtp = FakeSMTP()
    name = db.new_database('bankimp')
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


def _php(app, code):
    return app.php('-r', "require 'includes/bootstrap.php'; " + code).stdout


def _run(R, q, app, smtp):
    check = R.check
    app.php('setup.php', 'Test12345pw')
    base = app.serve()

    # ---------- ตัวแปลงวันที่/จำนวนเงิน (ฟังก์ชันล้วน) ----------
    cases = {'05/01/2569': '2026-01-05', '5/1/69': '2026-01-05', '2026-01-05': '2026-01-05', '05-01-2026': '2026-01-05', '5.1.2569': '2026-01-05',
             '5 ม.ค. 69': '2026-01-05', '5 มกราคม 2569': '2026-01-05', '5 Jan 2026': '2026-01-05', '2026-01-05 10:30:00': '2026-01-05', '05/01/2569 10:30': '2026-01-05',
             '46027': '2026-01-05', '29/02/2568': None, '31/02/2569': None, 'abc': None, '': None, '5/13/2569': None}
    got = json.loads(_php(app, 'echo json_encode(array_map("bank_import_parse_date", ' + json.dumps(list(cases), ensure_ascii=False) + '), JSON_UNESCAPED_UNICODE);'))
    bad = [(k, v, g) for (k, v), g in zip(cases.items(), got) if v != g]
    check('E1 แปลงวันที่ได้ทุกรูปแบบ (พ.ศ./ค.ศ./เลขวันที่ Excel/เดือนไทย) และปฏิเสธวันที่ที่ไม่มีจริง', not bad, str(bad))
    amts = {'1,234.50': 1234.5, '(500.00)': -500.0, '-20': -20.0, '20-': -20.0, '฿ 100': 100.0, '0.00': 0.0, '': None, 'abc': None, '1.2.3': None, '12 345.00': 12345.0}
    got = json.loads(_php(app, 'echo json_encode(array_map("bank_import_parse_amount", ' + json.dumps(list(amts), ensure_ascii=False) + '));'))
    bad = [(k, v, g) for (k, v), g in zip(amts.items(), got) if v != g]
    check('E2 แปลงจำนวนเงินได้ (คอมมา วงเล็บ/ลบ ติดลบท้าย ฿ ช่องว่าง) และปฏิเสธข้อความอื่น', not bad, str(bad))

    tA, tB = q("SELECT id FROM temples ORDER BY id LIMIT 1"), q("SELECT id FROM temples ORDER BY id LIMIT 1 OFFSET 1")
    cin = q("SELECT id FROM categories WHERE type='income' AND is_transfer=0 ORDER BY id LIMIT 1")
    cin2 = q("SELECT id FROM categories WHERE type='income' AND is_transfer=0 ORDER BY id LIMIT 1 OFFSET 1")
    cex = q("SELECT id FROM categories WHERE type='expense' AND is_transfer=0 ORDER BY id LIMIT 1")
    h = hash_password(app, PW)
    q(f"UPDATE users SET password_hash='{h}', must_change_password=0 WHERE role IN ('temple','province')")
    for t, em in ((tA, 'tA@example.org'), (tB, 'tB@example.org')):
        q(f"UPDATE temples SET email='{em}' WHERE id={t}")
    ca, cb = Client(base), Client(base)
    for c, t in ((ca, tA), (cb, tB)):
        c.post('/login.php', {'username': q(f"SELECT username FROM users WHERE temple_id={t}"), 'password': PW})
        c.post('/login_verify.php', {'action': 'verify', 'code': smtp.last_code()})
    today = datetime.date.today()
    open_d = today - datetime.timedelta(days=400)
    ca.post('/accounts.php', {'action': 'add_bank', 'bank_name': 'กสิกรไทย', 'branch': 'แพร่', 'account_no': '123-4-56789-0', 'name': 'วัด', 'opening_balance': '0', **be_date('opening_date', open_d)}, '/accounts.php')
    bank = q(f"SELECT id FROM accounts WHERE temple_id={tA} AND kind='bank'")
    cash = q(f"SELECT id FROM accounts WHERE temple_id={tA} AND kind='cash'")
    cb.post('/accounts.php', {'action': 'add_bank', 'bank_name': 'ไทยพาณิชย์', 'branch': 'แพร่', 'account_no': '999-9-99999-9', 'name': 'วัดบี', 'opening_balance': '0', **be_date('opening_date', open_d)}, '/accounts.php')
    bank_b = q(f"SELECT id FROM accounts WHERE temple_id={tB} AND kind='bank'")

    def be(d):
        return f'{d.day:02d}/{d.month:02d}/{d.year + 543}'
    d1, d2, d3 = today - datetime.timedelta(days=20), today - datetime.timedelta(days=15), today - datetime.timedelta(days=10)

    csv1 = ('﻿รายการเดินบัญชี\nบัญชี 123-4-56789-0\n\n'
            'วันที่,รายการ,ถอนเงิน,ฝากเงิน,ยอดคงเหลือ\n'
            f'{be(d1)},โอนเงินเข้า นายสมชาย,,"1,000.00","1,000.00"\n'
            f'{be(d2)},ชำระค่าไฟฟ้า,"300.00",,"700.00"\n'
            f'{be(d3)},โอนเงินเข้า นางสมหญิง,,500.00,"1,200.00"\n'
            'ยอดรวม,,300.00,1500.00,\n').encode('utf-8')

    def upload(cl, content, fname='stmt.csv', acct=bank, temple=None, page=None):
        f = {'action': 'upload', 'account': acct}
        if temple:
            f['temple'] = temple
        return cl.upload('/bank_import.php', f, fname, content, page or f'/bank_import.php?account={acct}' + (f'&temple={temple}' if temple else ''), 'file', 'text/csv')

    code, loc, _ = upload(ca, csv1)
    check('E3 อัปโหลด CSV (มีบรรทัดหัวรายงาน/แถวสรุป) → ไปหน้าตัวอย่าง', code in (302, 303) and loc and 'step=preview' in loc, str(loc))
    _, _, pg = ca.req(f'/bank_import.php?account={bank}&step=preview')
    rows = re.findall(r'name="row\[(\d+)\]\[include\]"', pg)
    check('E4 หน้าตัวอย่างอ่านได้ 3 รายการ (ข้ามบรรทัดหัวรายงานและแถวสรุปยอดรวม) แสดงวันที่ พ.ศ. และจำนวนเงิน',
          len(rows) == 3 and 'โอนเงินเข้า นายสมชาย' in pg and '1,000.00' in pg and '300.00' in pg and str(d1.year + 543) in pg, str(rows))
    sel_cols = re.findall(r'<select name="col_(\w+)">.*?</select>', pg, re.S)
    check('E5 เดาคอลัมน์จากหัวตารางไทยได้ครบ (วันที่/รายการ/ถอน/ฝาก)', {'date', 'desc', 'debit', 'credit'} <= set(sel_cols) and pg.count(' selected>') >= 4)

    def do_import(cl, picks, acct=bank, temple=None, page_step=True):
        """picks: {ลำดับแถว: (หมวด, ติ๊กนำเข้า)}"""
        data = [('csrf', cl.csrf(f'/bank_import.php?account={acct}&step=preview')), ('action', 'import'), ('account', acct)]
        if temple:
            data.append(('temple', temple))
        for i, (cat, on) in picks.items():
            if on:
                data.append((f'row[{i}][include]', '1'))
            data.append((f'row[{i}][category]', str(cat)))
        return cl.req('/bank_import.php', raw=urlencode(data).encode(), headers={'Content-Type': 'application/x-www-form-urlencoded'})

    code, loc, _ = do_import(ca, {0: (cin, True), 1: (cex, True), 2: (0, True)})
    msg = notice(ca.req(f'/bank_import.php?account={bank}')[2])
    check('E6 นำเข้า: 2 รายการที่เลือกหมวดแล้วสำเร็จ รายการที่ไม่ได้เลือกหมวดถูกข้ามพร้อมแจ้ง', 'นำเข้าแล้ว 2 รายการ' in msg and 'ข้าม 1' in msg and 'ยังไม่ได้เลือกหมวด' in msg, msg)
    r = q(f"SELECT GROUP_CONCAT(CONCAT(type,':',amount,':',txn_date,':',description,':',account_id) ORDER BY id SEPARATOR '|') FROM transactions WHERE temple_id={tA}")
    exp = f'income:1000.00:{d1}:โอนเงินเข้า นายสมชาย:{bank}|expense:300.00:{d2}:ชำระค่าไฟฟ้า:{bank}'
    check('E7 รายการเข้าบัญชีธนาคารถูกต้อง (ประเภท จำนวน วันที่ ค.ศ. รายละเอียด บัญชี)', r == exp, r)
    check('E8 ยอดบัญชีธนาคารเพิ่มตามรายการ (700.00)', q(f"SELECT COALESCE(SUM(CASE WHEN type='income' THEN amount ELSE -amount END),0) FROM transactions WHERE account_id={bank} AND is_deleted=0") == '700.00')

    # อัปโหลดซ้ำ -> ซ้ำ
    upload(ca, csv1)
    _, _, pg = ca.req(f'/bank_import.php?account={bank}&step=preview')
    dups = pg.count('มีรายการนี้ในระบบแล้ว')
    unchecked = len(re.findall(r'name="row\[\d+\]\[include\]" value="1"\s*>', pg))
    check('E9 อัปโหลดไฟล์เดิมซ้ำ: 2 รายการที่นำเข้าแล้วถูกทำเครื่องหมายซ้ำและไม่ติ๊กไว้ (รายการที่ 3 ยังพร้อมนำเข้า)', dups == 2 and unchecked == 2 and pg.count('checked') >= 1, f'{dups} {unchecked}')
    sel = re.findall(r'<select name="row\[0\]\[category\]">.*?</select>', pg, re.S)[0]
    check('E10 จำหมวดที่เคยใช้กับรายการชื่อเดียวกันและเลือกให้อัตโนมัติ', f'<option value="{cin}" selected>' in sel, sel[:200])
    do_import(ca, {2: (cin2, True)})
    check('E11 นำเข้าเฉพาะรายการที่ยังไม่ซ้ำ (รวมเป็น 3 รายการ) และไม่เกิดรายการซ้ำ', q(f"SELECT COUNT(*) FROM transactions WHERE temple_id={tA}") == '3' and q(f"SELECT category_id FROM transactions WHERE description='โอนเงินเข้า นางสมหญิง'") == cin2)

    # รูปแบบอื่น: ช่องจำนวนเงินช่องเดียวติดลบ + ค.ศ. + ตัวคั่นแท็บ
    d4, d5 = today - datetime.timedelta(days=5), today - datetime.timedelta(days=4)
    csv2 = ('Date\tDescription\tAmount\tBalance\n' + f'{d4.isoformat()}\tdeposit A\t2,000.00\t0\n' + f'{d5.isoformat()}\tpay B\t(450.50)\t0\n').encode()
    upload(ca, csv2, 'a.csv')
    _, _, pg = ca.req(f'/bank_import.php?account={bank}&step=preview')
    check('E12 CSV ตัวคั่นแท็บ + วันที่ ค.ศ. + ช่องจำนวนเงินเดียว (วงเล็บ = เงินออก): แยกเงินเข้า/ออกถูกต้อง', 'deposit A' in pg and '2,000.00' in pg and '450.50' in pg and pg.count('text-emerald-700">2,000.00') >= 1 and pg.count('text-red-700">450.50') >= 1)
    do_import(ca, {0: (cin, True), 1: (cex, True)})
    check('E13 นำเข้ารายการจากไฟล์แบบช่องเดียว (income 2000 / expense 450.50)', q(f"SELECT GROUP_CONCAT(CONCAT(type,':',amount) ORDER BY id) FROM transactions WHERE account_id={bank} AND description IN ('deposit A','pay B')") == 'income:2000.00,expense:450.50')

    # Windows-874 (TIS-620)
    csv3 = ('วันที่,รายการ,ฝาก,ถอน\n' + f'{be(today - datetime.timedelta(days=3))},ฝากเงินสด,100.00,\n').encode('cp874')
    upload(ca, csv3, 't.csv')
    _, _, pg = ca.req(f'/bank_import.php?account={bank}&step=preview')
    check('E14 ไฟล์ CSV เข้ารหัส Windows-874 (TIS-620) อ่านภาษาไทยได้', 'ฝากเงินสด' in pg and '100.00' in pg)

    # Excel (.xlsx) พร้อมเลขวันที่ของ Excel
    serial = (today - datetime.timedelta(days=2) - datetime.date(1899, 12, 30)).days
    xl = make_xlsx([['รายงาน'], ['วันที่', 'รายละเอียด', 'ฝาก', 'ถอน'], [serial, 'ผ้าป่าสามัคคี', 5000, ''], [serial, 'ค่าน้ำ', '', 120.5]])
    upload(ca, xl, 's.xlsx')
    _, _, pg = ca.req(f'/bank_import.php?account={bank}&step=preview')
    check('E15 ไฟล์ Excel (.xlsx) เลขวันที่ Excel ถูกแปลงเป็นวันที่', 'ผ้าป่าสามัคคี' in pg and '5,000.00' in pg and '120.50' in pg and str((today - datetime.timedelta(days=2)).year + 543) in pg)
    do_import(ca, {0: (cin, True), 1: (cex, True)})
    check('E16 นำเข้าจาก Excel สำเร็จ', q(f"SELECT COUNT(*) FROM transactions WHERE account_id={bank} AND description IN ('ผ้าป่าสามัคคี','ค่าน้ำ')") == '2')

    # แถวผิดปกติ
    csv4 = ('วันที่,รายการ,ถอน,ฝาก\n' + 'วันผิด,ทดสอบ,,50.00\n' + f'{be(today - datetime.timedelta(days=1))},มีทั้งสองช่อง,10.00,20.00\n').encode()
    upload(ca, csv4, 'e.csv')
    _, _, pg = ca.req(f'/bank_import.php?account={bank}&step=preview')
    n0 = q(f"SELECT COUNT(*) FROM transactions WHERE temple_id={tA}")
    do_import(ca, {0: (cin, True), 1: (cin, True)})
    check('E17 แถววันที่อ่านไม่ได้/มีทั้งฝากและถอน ถูกแจ้งปัญหาและนำเข้าไม่ได้แม้บังคับส่งมา', 'วันที่อ่านไม่ได้' in pg and 'มีทั้งยอดฝากและถอน' in pg and q(f"SELECT COUNT(*) FROM transactions WHERE temple_id={tA}") == n0)

    # ไม่มีหัวตาราง -> แมปเอง
    csv5 = (f'{be(today - datetime.timedelta(days=1))},โอนมา,,77.00\n{be(today - datetime.timedelta(days=2))},โอนมาอีก,,5.00\n').encode()
    upload(ca, csv5, 'n.csv')
    _, _, pg = ca.req(f'/bank_import.php?account={bank}&step=preview')
    check('E18 ไม่มีหัวตาราง: แจ้งให้เลือกคอลัมน์เอง', 'ระบบเดาหัวตารางไม่ได้' in pg)
    data = [('csrf', ca.csrf(f'/bank_import.php?account={bank}&step=preview')), ('action', 'remap'), ('account', bank), ('header', '0'), ('col_date', '0'), ('col_desc', '1'), ('col_debit', '2'), ('col_credit', '3')]
    ca.req('/bank_import.php', raw=urlencode(data).encode(), headers={'Content-Type': 'application/x-www-form-urlencoded'})
    _, _, pg = ca.req(f'/bank_import.php?account={bank}&step=preview')
    check('E19 แมปคอลัมน์เอง (ไม่มีหัวตาราง) แล้วอ่านรายการได้', 'โอนมา' in pg and '77.00' in pg and 'ระบบเดาหัวตารางไม่ได้' not in pg)

    # ไฟล์ไม่รองรับ
    for nm, content in (('x.exe', b'MZ'), ('old.xls', b'\xd0\xcf\x11\xe0'), ('empty.csv', b'a,b\n'), ('bad.xlsx', b'not a zip')):
        code, loc, _ = upload(ca, content, nm)
        check(f'E20 ปฏิเสธไฟล์ {nm}', code in (302, 303) and 'step=preview' not in (loc or '') and notice(ca.req(f'/bank_import.php?account={bank}')[2]) != '')

    # ปิดงวด
    ym = today - datetime.timedelta(days=60)
    q(f"INSERT INTO monthly_reports (temple_id, report_year, report_month, submitted_at, closed_at) VALUES ({tA}, {ym.year + 543}, {ym.month}, NOW(), NOW())")
    csv6 = ('วันที่,รายการ,ฝาก,ถอน\n' + f'{be(ym.replace(day=5))},ย้อนหลังเดือนปิดงวด,10.00,\n').encode()
    upload(ca, csv6, 'c.csv')
    _, _, pg = ca.req(f'/bank_import.php?account={bank}&step=preview')
    n1 = q(f"SELECT COUNT(*) FROM transactions WHERE temple_id={tA}")
    do_import(ca, {0: (cin, True)})
    check('E21 เดือนที่ปิดงวดแล้ว: ตัวอย่างแจ้งปิดงวด และนำเข้าไม่ได้', 'เดือนที่ปิดงวดแล้ว' in pg and q(f"SELECT COUNT(*) FROM transactions WHERE temple_id={tA}") == n1)

    # สิทธิ์
    code, loc, _ = cb.req(f'/bank_import.php?account={bank}')
    _, _, pgb = cb.req(f'/bank_import.php?account={bank}')
    code2, _, _ = upload(cb, csv1, acct=bank, page='/account.php')
    check('E22 แอดมินวัดอื่นใช้บัญชีธนาคารของวัดเราไม่ได้ (ไม่แสดงบัญชีเรา อัปโหลดถูกปฏิเสธ)', '123-4-56789-0' not in pgb and code2 == 403)
    code3, _, _ = upload(ca, csv1, acct=cash, page='/account.php')
    check('E23 บัญชีเงินสดนำเข้าไม่ได้ (เฉพาะบัญชีธนาคาร)', code3 == 403)
    pro = q("SELECT username FROM users WHERE role='province' LIMIT 1")
    if pro:
        cp = Client(base)
        cp.post('/login.php', {'username': pro, 'password': PW})
        code, _, _ = cp.req(f'/bank_import.php?temple={tA}&account={bank}')
        tok = cp.csrf('/account.php')
        c2, _, _ = cp.req('/bank_import.php', raw=urlencode({'csrf': tok, 'action': 'upload', 'temple': tA, 'account': bank}).encode(), headers={'Content-Type': 'application/x-www-form-urlencoded'})
        check('E24 เจ้าคณะจังหวัดเข้าหน้านำเข้าไม่ได้ (redirect)', code in (302, 303) and c2 in (302, 303))
    _, _, pg = ca.req('/accounts.php')
    check('E25 หน้าบัญชีมีปุ่ม "นำเข้ารายการจากไฟล์ธนาคาร"', 'bank_import.php' in pg)
    check('E26 การนำเข้าถูกบันทึกใน audit log', int(q("SELECT COUNT(*) FROM audit_log WHERE action='import_bank'")) >= 3)
