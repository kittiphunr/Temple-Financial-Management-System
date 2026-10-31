"""ชุดที่ 15: รายการประจำ — ตั้งแม่แบบรายเดือน ถึงกำหนดแล้วกดยืนยันบันทึก/แก้จำนวน/ข้าม (ไม่บันทึกอัตโนมัติ)

ตรวจ: การตรวจข้อมูล · วันที่ถึงกำหนด (ปรับวันสิ้นเดือน) · บันทึกซ้ำไม่ได้ · ลบรายการที่บันทึกแล้วกลับมารอยืนยัน · ปิดงวด
      หยุด/ลบแม่แบบ · สิทธิ์ · ก่อน/หลังรัน migration
"""
import datetime
import re
import subprocess

from support import App, Client, FakeSMTP

PW = 'Wat12345pw!'


def run(db, R):
    smtp = FakeSMTP()
    name = db.new_database('recur')
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


def _first(n):
    d = datetime.date.today().replace(day=1)
    for _ in range(n):
        d = (d - datetime.timedelta(days=1)).replace(day=1)
    return d


def _clamp(period, day):
    nxt = (period.replace(day=28) + datetime.timedelta(days=4)).replace(day=1)
    last = (nxt - datetime.timedelta(days=1)).day
    return period.replace(day=min(day, last))


def _expected(start, day, today=None, end=None):
    """ช่วงเดือน (วันที่ 1) ที่ถึงกำหนดแล้วตามกติกา"""
    today = today or datetime.date.today()
    out, p = [], start
    while p <= today.replace(day=1) and (end is None or p <= end):
        if _clamp(p, day) <= today:
            out.append(p)
        p = (p.replace(day=28) + datetime.timedelta(days=4)).replace(day=1)
    return out


def _run(R, q, app, smtp):
    check = R.check
    app.php('setup.php', 'Test12345pw')
    base = app.serve()
    adm = Client(base)
    adm.post('/login.php', {'username': 'admin', 'password': 'Test12345pw'})
    txt = lambda b: re.sub(r'\s+', ' ', re.sub(r'<[^>]+>', ' ', b))
    check('R0 ตาราง/คอลัมน์รายการประจำถูกสร้างพร้อมการติดตั้ง',
          q("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name IN ('recurring_items','recurring_skips')") == '2'
          and q("SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='transactions' AND column_name IN ('recurring_id','recurring_period')") == '2')

    rows = [r.split('\t') for r in q("SELECT district_id, id FROM temples WHERE is_active=1 ORDER BY district_id, id").split('\n')]
    dA, tA = rows[0]
    tOther = next(r[1] for r in rows if r[0] != dA)
    cat_ex = q("SELECT id FROM categories WHERE type='expense' AND is_transfer=0 AND is_active=1 ORDER BY id LIMIT 1")
    cat_in = q("SELECT id FROM categories WHERE type='income' AND is_transfer=0 AND is_active=1 ORDER BY id LIMIT 1")
    page = f'/recurring.php?temple={tA}'

    def save(cl, temple=tA, **kw):
        s = _first(3)
        d = {'action': 'save', 'temple': temple, 'id': 0, 'type': 'expense', 'category_id': cat_ex, 'description': 'ค่าไฟฟ้า', 'amount': '1,200.50', 'day_of_month': 5,
             'account_id': 'cash', 'start_m': s.month, 'start_y': s.year + 543, 'end_m': 0, 'end_y': 0, **kw}
        return cl.post(f'/recurring.php?temple={temple}', d, f'/recurring.php?temple={temple}')

    n_items = lambda: int(q('SELECT COUNT(*) FROM recurring_items'))
    n_txn = lambda: int(q('SELECT COUNT(*) FROM transactions WHERE recurring_id IS NOT NULL'))

    # ---- A: ตรวจข้อมูลตอนตั้ง
    for label, kw in (('วันที่ 0', {'day_of_month': 0}), ('วันที่ 32', {'day_of_month': 32}), ('จำนวนเงิน 0', {'amount': '0'}), ('ไม่มีชื่อ', {'description': ' '}),
                      ('หมวดรายรับกับประเภทรายจ่าย', {'category_id': cat_in}), ('เดือนสิ้นสุดก่อนเริ่ม', {'end_m': _first(5).month, 'end_y': _first(5).year + 543})):
        save(adm, **kw)
        check(f'A1 ตั้งไม่ได้: {label}', n_items() == 0)
    save(adm)
    check('A2 ตั้งรายการประจำได้ (จำนวน 1,200.50 วันที่ 5 บัญชีเงินสด)',
          n_items() == 1 and q('SELECT CONCAT(amount,"|",day_of_month,"|",type,"|",is_active) FROM recurring_items') == '1200.50|5|expense|1', q('SELECT * FROM recurring_items'))
    iid = q('SELECT id FROM recurring_items')
    exp = _expected(_first(3), 5)
    c, _, b = adm.req(page)
    check('A3 หน้ารายการประจำแสดงรอยืนยันตามจำนวนเดือนที่ถึงกำหนด และเมนูมี "รายการประจำ"', c == 200 and f'ถึงกำหนด รอยืนยัน ({len(exp)})' in txt(b) and 'รายการประจำ' in re.search(r'<nav class="hidden.*?</nav>', b, re.S).group(0), txt(b)[:300])

    # ---- B: ยืนยันบันทึก
    def post_selected(cl, picks, amts=None, temple=tA, extra=()):
        tok = cl.csrf(f'/recurring.php?temple={temple}')
        data = [('csrf', tok), ('action', 'post_selected'), ('temple', temple)] + [('pick[]', k) for k in picks] + [(f'amt[{k}]', v) for k, v in (amts or {}).items()] + list(extra)
        return cl.req(f'/recurring.php?temple={temple}', data)

    keys = [f'{iid}|{p.isoformat()}' for p in exp]
    post_selected(adm, keys)
    got = q("SELECT GROUP_CONCAT(CONCAT(txn_date,'|',amount,'|',type) ORDER BY txn_date) FROM transactions WHERE recurring_id IS NOT NULL")
    want = ','.join(f'{_clamp(p, 5).isoformat()}|1200.50|expense' for p in exp)
    check('B1 กดบันทึกทั้งหมด → สร้างรายการวันที่ 5 ของแต่ละเดือน จำนวนตั้งต้น', n_txn() == len(exp) and got == want, f'{got} != {want}')
    check('B2 รายการที่สร้างเป็นของวัดนี้ หมวด/บัญชีเงินสด ผู้บันทึก = แอดมิน และอ้างอิงแม่แบบ/เดือน',
          q(f"SELECT COUNT(*) FROM transactions WHERE recurring_id={iid} AND temple_id={tA} AND category_id={cat_ex} AND created_by=(SELECT id FROM users WHERE username='admin') AND description='ค่าไฟฟ้า' AND account_id=(SELECT id FROM accounts WHERE temple_id={tA} AND cash_flag=1)") == str(len(exp)))
    c, _, b = adm.req(page)
    check('B3 บันทึกแล้วไม่ขึ้นรอยืนยันอีก', 'ถึงกำหนด รอยืนยัน (0)' in txt(b))
    post_selected(adm, keys)
    check('B4 กดบันทึกซ้ำ ไม่เกิดรายการซ้ำ', n_txn() == len(exp))
    check('B5 บันทึก audit การสร้างจากรายการประจำ', int(q("SELECT COUNT(*) FROM audit_log WHERE action='create' AND entity='transaction' AND detail LIKE '%recurring#%'")) == len(exp))

    # ---- C: แก้จำนวนตอนยืนยัน · ข้าม · ลบรายการที่บันทึกแล้ว
    save(adm, description='ค่าน้ำประปา', amount='300', day_of_month=10, start_m=_first(2).month, start_y=_first(2).year + 543)
    iid2 = q("SELECT id FROM recurring_items WHERE description='ค่าน้ำประปา'")
    exp2 = _expected(_first(2), 10)
    k2 = [f'{iid2}|{p.isoformat()}' for p in exp2]
    if exp2:
        post_selected(adm, [k2[0]], {k2[0]: '345.75'})
        check('C1 แก้จำนวนเงินตอนยืนยันได้ (345.75)', q(f"SELECT amount FROM transactions WHERE recurring_id={iid2} AND recurring_period='{exp2[0].isoformat()}'") == '345.75')
        post_selected(adm, [k2[-1]] if len(k2) > 1 else [], {k2[-1]: '0'} if len(k2) > 1 else None)
        if len(k2) > 1:
            check('C2 จำนวนเงิน 0 ตอนยืนยัน → ไม่บันทึก', q(f"SELECT COUNT(*) FROM transactions WHERE recurring_id={iid2} AND recurring_period='{exp2[-1].isoformat()}'") == '0')
    if len(k2) > 1:
        tok = adm.csrf(page)
        adm.req(page, [('csrf', tok), ('action', 'post_selected'), ('temple', tA), ('skip', k2[-1])])
        check('C3 กด "ข้าม" → บันทึกการข้าม ไม่สร้างรายการ ไม่ขึ้นรอยืนยันอีก',
              q(f"SELECT COUNT(*) FROM recurring_skips WHERE recurring_id={iid2} AND period='{exp2[-1].isoformat()}'") == '1'
              and q(f"SELECT COUNT(*) FROM transactions WHERE recurring_id={iid2} AND recurring_period='{exp2[-1].isoformat()}'") == '0'
              and f'{iid2}%7C{exp2[-1].isoformat()}' not in adm.req(page)[2] and f'value="{iid2}|{exp2[-1].isoformat()}"' not in adm.req(page)[2])
    victim = q(f"SELECT id FROM transactions WHERE recurring_id={iid} ORDER BY txn_date LIMIT 1")
    vper = q(f"SELECT recurring_period FROM transactions WHERE id={victim}")
    q(f"UPDATE transactions SET is_deleted=1 WHERE id={victim}")
    check('C4 ลบรายการที่บันทึกแล้ว → เดือนนั้นกลับมารอยืนยัน', f'value="{iid}|{vper}"' in adm.req(page)[2])
    post_selected(adm, [f'{iid}|{vper}'])
    check('C5 ยืนยันเดือนนั้นอีกครั้งได้ (ไม่ชนรายการที่ลบแล้ว)', q(f"SELECT COUNT(*) FROM transactions WHERE recurring_id={iid} AND recurring_period='{vper}' AND is_deleted=0") == '1')

    # ---- D: ปิดงวด
    save(adm, description='เงินเดือนไวยาวัจกร', day_of_month=1, start_m=_first(1).month, start_y=_first(1).year + 543, amount='5000')
    iid3 = q("SELECT id FROM recurring_items WHERE description='เงินเดือนไวยาวัจกร'")
    pm = _first(1)
    q(f"INSERT INTO monthly_reports (temple_id, report_year, report_month, submitted_at, txn_count, income_total, expense_total, closed_at) VALUES ({tA},{pm.year + 543},{pm.month},NOW(),0,0,0,NOW())")
    c, _, b = adm.req(page)
    check('D1 เดือนที่ปิดงวดแล้ว แสดง "ปิดงวดแล้ว" และไม่มีช่องติ๊กบันทึก', 'ปิดงวดแล้ว' in txt(b) and f'name="pick[]" value="{iid3}|{pm.isoformat()}"' not in b)
    post_selected(adm, [f'{iid3}|{pm.isoformat()}'])
    check('D2 บันทึกเดือนที่ปิดงวดไม่ได้', q(f"SELECT COUNT(*) FROM transactions WHERE recurring_id={iid3} AND recurring_period='{pm.isoformat()}'") == '0')

    # ---- E: วันสิ้นเดือน · สิ้นสุด · หยุด/เปิด · ลบ
    out = subprocess.run(['php', '-r', "require 'includes/recurring.php'; echo recurring_date('2026-02-01',31),'|',recurring_date('2028-02-01',31),'|',recurring_date('2026-04-01',31),'|',recurring_date('2026-01-01',31);"],
                         capture_output=True, text=True, cwd=app.dir).stdout
    check('E1 วันที่ตั้งไว้เกินวันสุดท้ายของเดือน → ใช้วันสุดท้าย (ก.พ. 28/29 เม.ย. 30)', out == '2026-02-28|2028-02-29|2026-04-30|2026-01-31', out)
    e = _first(2)
    save(adm, description='ค่าเช่าสิ้นสุดแล้ว', day_of_month=1, start_m=_first(4).month, start_y=_first(4).year + 543, end_m=_first(3).month, end_y=_first(3).year + 543, amount='10')
    iid4 = q("SELECT id FROM recurring_items WHERE description='ค่าเช่าสิ้นสุดแล้ว'")
    pg = adm.req(page)[2]
    mine = re.findall(rf'name="pick\[\]" value="{iid4}\|(\d{{4}}-\d{{2}}-01)"', pg)
    check('E2 รายการที่กำหนดเดือนสิ้นสุด ขึ้นเฉพาะช่วงเริ่ม–สิ้นสุด (2 เดือน)', sorted(mine) == sorted(p.isoformat() for p in _expected(_first(4), 1, end=_first(3))), str(mine))
    tok = adm.csrf(page)
    adm.req(page, {'csrf': tok, 'action': 'toggle', 'temple': tA, 'id': iid4})
    check('E3 หยุดรายการประจำ → ไม่ขึ้นรอยืนยัน', q(f"SELECT is_active FROM recurring_items WHERE id={iid4}") == '0' and f'value="{iid4}|' not in adm.req(page)[2])
    adm.req(page, {'csrf': tok, 'action': 'toggle', 'temple': tA, 'id': iid4})
    check('E4 เปิดใช้อีกครั้ง → กลับมารอยืนยัน', f'value="{iid4}|' in adm.req(page)[2])
    adm.req(page, {'csrf': tok, 'action': 'delete', 'temple': tA, 'id': iid})
    check('E5 ลบแม่แบบ → แม่แบบหายและตัดการอ้างอิงจากรายการที่บันทึกแล้ว', q(f"SELECT COUNT(*) FROM recurring_items WHERE id={iid}") == '0' and q(f"SELECT COUNT(*) FROM transactions WHERE recurring_id={iid}") == '0')
    check('E6 หลังลบแม่แบบ รายการค่าไฟยังอยู่ในบัญชี', int(q("SELECT COUNT(*) FROM transactions WHERE description='ค่าไฟฟ้า' AND is_deleted=0 AND recurring_id IS NULL")) == len(exp))

    # ---- F: สิทธิ์
    h = subprocess.run(['php', '-r', f"echo password_hash('{PW}', PASSWORD_DEFAULT);"], capture_output=True, text=True).stdout

    def staff_login(username):
        uid = q(f"SELECT id FROM users WHERE username='{username}'")
        q(f"UPDATE users SET password_hash='{h}', must_change_password=0, email='{username}@example.org' WHERE id={uid}")
        cl = Client(base)
        c, loc, _ = cl.post('/login.php', {'username': username, 'password': PW})
        assert loc == 'index.php', (username, c, loc)
        return cl

    dcode = q(f"SELECT code FROM districts WHERE id={dA}")
    dist = staff_login('amphoe' + dcode)
    prov = staff_login('province')
    base_n = n_items()
    save(dist, description='อำเภอตั้งให้วัดในอำเภอ')
    check('F1 แอดมินอำเภอตั้งรายการประจำให้วัดในอำเภอตนได้', n_items() == base_n + 1)
    base_n = n_items()
    c, loc, _ = save(dist, temple=tOther, description='ข้ามอำเภอ')
    check('F2 แอดมินอำเภอตั้งให้วัดนอกอำเภอไม่ได้', n_items() == base_n and q("SELECT COUNT(*) FROM recurring_items WHERE description='ข้ามอำเภอ'") == '0')
    c, loc, _ = prov.req('/recurring.php')
    check('F3 เจ้าคณะจังหวัด (ดูอย่างเดียว) เข้าหน้านี้ไม่ได้', c == 302 and 'dashboard.php' in (loc or ''))
    base_n = n_items()
    try:
        save(prov, description='จังหวัดลองตั้ง')
    except Exception:
        pass
    check('F4 เจ้าคณะจังหวัดตั้งรายการประจำไม่ได้', n_items() == base_n)

    # ---- G: แอดมินวัด (ต้องผ่านอีเมล 2FA) เห็นเฉพาะวัดตน + แบนเนอร์บนแดชบอร์ด
    q(f"UPDATE temples SET email='tA@example.org' WHERE id={tA}")
    tuser = q(f"SELECT username FROM users WHERE temple_id={tA}")
    q(f"UPDATE users SET password_hash='{h}', must_change_password=0 WHERE temple_id={tA}")
    t = Client(base)
    t.post('/login.php', {'username': tuser, 'password': PW})
    t.post('/login_verify.php', {'action': 'verify', 'code': smtp.last_code()})
    c, _, b = t.req('/recurring.php')
    check('G1 แอดมินวัดเปิดหน้ารายการประจำของวัดตนได้ และเห็นรายการที่ตั้งไว้', c == 200 and 'เงินเดือนไวยาวัจกร' in b and 'ค่าน้ำประปา' in b)
    c, _, b = t.req('/dashboard.php')
    check('G2 แดชบอร์ดแอดมินวัดมีแบนเนอร์ "รายการประจำ ... ถึงกำหนดรอยืนยัน" พร้อมลิงก์', c == 200 and re.search(r'รายการประจำ\s*\d+\s*รายการ', txt(b)) is not None and 'recurring.php' in b, txt(b)[:200])
    base_n = n_items()
    t.post('/recurring.php', {'action': 'save', 'temple': tOther, 'id': 0, 'type': 'expense', 'category_id': cat_ex, 'description': 'วัดอื่น', 'amount': '10', 'day_of_month': 1,
                              'account_id': 'cash', 'start_m': 1, 'start_y': _first(0).year + 543}, '/recurring.php')
    check('G3 แอดมินวัดตั้งให้วัดอื่นไม่ได้ (ใช้วัดของตนเสมอ)', q("SELECT COUNT(*) FROM recurring_items WHERE description='วัดอื่น' AND temple_id=" + tOther) == '0')

    # ---- H: ฐานข้อมูลที่ยังไม่รัน migration → ระบบทำงานแบบเดิม แล้วรัน migration ได้
    q('ALTER TABLE transactions DROP FOREIGN KEY fk_txn_recurring, DROP KEY idx_txn_recurring, DROP COLUMN recurring_id, DROP COLUMN recurring_period')
    q('DROP TABLE recurring_skips')
    q('DROP TABLE recurring_items')
    c, loc, _ = adm.req(page)
    c2, _, b2 = adm.req('/transactions.php?type=expense')
    check('H1 ยังไม่รัน migration → หน้ารายการประจำพากลับแดชบอร์ด หน้าบัญชียังใช้ได้ ไม่มีเมนูรายการประจำ', c == 302 and 'dashboard.php' in (loc or '') and c2 == 200 and 'recurring.php' not in b2)
    mig = open(__file__.replace('tests/test_recurring.py', 'sql/migrations/2026_recurring.sql'), encoding='utf-8').read().replace('USE tfms_phrae;', '')
    q(mig)
    check('H2 รัน migration 2026_recurring.sql ได้ ตาราง/คอลัมน์ครบ',
          q("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name IN ('recurring_items','recurring_skips')") == '2'
          and q("SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='transactions' AND column_name IN ('recurring_id','recurring_period')") == '2')
    save(adm, description='หลังรัน migration')
    check('H3 หลังรัน migration ตั้งรายการประจำได้ทันที', q("SELECT COUNT(*) FROM recurring_items WHERE description='หลังรัน migration'") == '1')
