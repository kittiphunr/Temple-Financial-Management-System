"""ชุดที่ 16: แจ้งเตือนอัตโนมัติทางอีเมล (cron_reminders.php) — ก่อนครบกำหนด เกินกำหนด ปิดงวด ส่งกลับค้าง และสรุปให้ staff

ใช้ --today= จำลองวันที่ (เดือนรายงาน = ก.ย. 2569 กำหนดส่ง 10 ต.ค. 2026) · ตรวจ: ไม่ส่งซ้ำ ทดลอง (dry-run) เปิด/ปิด จำนวนวัน
การข้ามเมื่อเคยกดเตือนด้วยมือ ขอบเขตของสรุป สิทธิ์แก้ค่าตั้ง CLI เท่านั้น ก่อน/หลังรัน migration
"""
import re
import subprocess

from support import App, Client, FakeSMTP

PW = 'Wat12345pw!'


def run(db, R):
    smtp = FakeSMTP()
    name = db.new_database('remind')
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
    adm = Client(base)
    adm.post('/login.php', {'username': 'admin', 'password': 'Test12345pw'})
    check('R0 ตาราง report_notices ถูกสร้างพร้อมการติดตั้ง', q("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name='report_notices'") == '1')

    Y, M = 2569, 9
    rows = [r.split('\t') for r in q("SELECT district_id, subdistrict, COUNT(*) c FROM temples WHERE subdistrict<>'' GROUP BY district_id, subdistrict HAVING c>=2 ORDER BY district_id, subdistrict").split('\n')]
    dA, subA = rows[0][0], rows[0][1]
    ids = [x for x in q(f"SELECT id FROM temples WHERE district_id={dA} AND subdistrict='{subA}' ORDER BY id").split('\n')]
    tA, tB = ids[0], ids[1]
    others = q(f"SELECT id FROM temples WHERE district_id={dA} AND id NOT IN ({tA},{tB}) ORDER BY id LIMIT 4").split('\n')
    tD, tE, tF, tG = others
    tC = q(f"SELECT id FROM temples WHERE district_id<>{dA} ORDER BY id LIMIT 1")
    mail = {t: f't{t}@example.org' for t in (tA, tB, tC, tD, tE)}
    for t, e in mail.items():
        q(f"UPDATE temples SET email='{e}' WHERE id={t}")
    q(f"INSERT INTO monthly_reports (temple_id,report_year,report_month,submitted_at,txn_count,income_total,expense_total) VALUES ({tA},{Y},{M},'2026-10-03 09:00:00',0,0,0)")
    q(f"INSERT INTO monthly_reports (temple_id,report_year,report_month,submitted_at,txn_count,income_total,expense_total,returned_at,return_reason) VALUES ({tE},{Y},{M},'2026-10-02 09:00:00',0,0,0,'2026-10-06 10:00:00','ยอดไม่ตรงใบเสร็จ')")
    q(f"INSERT INTO report_reminders (temple_id,report_year,report_month,sent_at,sent_by) VALUES ({tD},{Y},{M},'2026-10-06 08:00:00',NULL)")
    # staff ที่มีอีเมล: แอดมินหลัก เจ้าคณะจังหวัด แอดมินอำเภอ เจ้าคณะตำบล
    staff = {
        'admin': q("SELECT id FROM users WHERE username='admin'"),
        'province': q("SELECT id FROM users WHERE role='province' LIMIT 1"),
        'district': q(f"SELECT id FROM users WHERE role='district' AND district_id={dA}"),
        'sub': q(f"SELECT u.id FROM users u JOIN user_subdistricts s ON s.user_id=u.id WHERE s.district_id={dA} AND s.subdistrict='{subA}' LIMIT 1"),
    }
    semail = {k: f'{k}@example.org' for k in staff}
    for k, uid in staff.items():
        q(f"UPDATE users SET email='{semail[k]}' WHERE id={uid}")

    def cron(today=None, *extra):
        args = ['cron_reminders.php'] + ([f'--today={today}'] if today else []) + list(extra)
        p = app.php(*args)
        out = p.stdout + p.stderr
        assert p.returncode == 0 and not re.search(r'Warning|Fatal|Notice|Deprecated', out), (args, p.returncode, out[:300])
        return out

    def new_msgs(since):
        return smtp.messages[since:]

    def to(msgs):
        return sorted(m['rcpt'][0].strip('<>') for m in msgs)

    kinds = lambda: dict(r.split('\t') for r in q("SELECT CONCAT(recipient,'|',kind), 1 FROM report_notices").split('\n') if r)
    n_notice = lambda: int(q('SELECT COUNT(*) FROM report_notices'))

    # ---- A: ก่อนถึงช่วงเตือน
    n = len(smtp.messages)
    out = cron('2026-10-05')
    check('A1 ก่อนถึงช่วงเตือน (เหลือ 5 วัน) ไม่ส่งอะไร', len(smtp.messages) == n and 'ไม่มีรายการที่ต้องส่ง' in out, out)

    # ---- B: ใกล้ครบกำหนด (3 วันก่อน)
    q(f"UPDATE temples SET email='{mail[tD]}' WHERE id={tD}")
    out = cron('2026-10-08')
    got = new_msgs(n)
    check('B1 เหลือ 3 วัน: เตือนวัดที่ยังไม่ส่ง (B, C, D) ไม่เตือนวัดที่ส่งแล้ว (A) และวัดที่ถูกส่งกลับ (E)',
          to(got) == sorted([mail[tB], mail[tC], mail[tD]]) and all('ใกล้ครบกำหนด' in m['subject'] or 'ใกล้ครบกำหนด' in m['body'] for m in got), f'{to(got)} A={tA} B={tB} C={tC} D={tD} E={tE}')
    check('B2 อีเมลระบุกำหนดส่ง 10 ตุลาคม และชื่อวัด', all('10 ตุลาคม' in m['body'] and 'เรียน ผู้ดูแล' in m['body'] for m in got), got[0]['body'][:200] if got else '')
    n2 = len(smtp.messages)
    cron('2026-10-08')
    check('B3 รันซ้ำวันเดียวกัน ไม่ส่งซ้ำ', len(smtp.messages) == n2)

    # ---- C: ทดลอง (dry-run)
    c0, k0 = len(smtp.messages), n_notice()
    out = cron('2026-10-11', '--dry-run')
    check('C1 --dry-run แสดงแผนแต่ไม่ส่งและไม่บันทึก', '[ทดลอง]' in out and 'overdue=' in out and len(smtp.messages) == c0 and n_notice() == k0, out)
    check('C2 --dry-run ไม่ทำเครื่องหมายเตือนด้วยมือ', q("SELECT COUNT(*) FROM report_reminders") == '1')

    # ---- D: เกินกำหนด + ส่งกลับค้าง + สรุปให้ staff
    out = cron('2026-10-11')
    got = new_msgs(c0)
    subj = lambda m: m['subject']
    ov = [m for m in got if m['rcpt'][0].strip('<>').startswith('t') and 'เกินกำหนด' in (m['subject'] + m['body'])]
    check('D1 เกินกำหนด: เตือน B และ C แต่ข้าม D (เคยกดเตือนด้วยมือแล้ว)', sorted(m['rcpt'][0].strip('<>') for m in ov) == sorted([mail[tB], mail[tC]]), str(to(got)))
    nudge = [m for m in got if m['rcpt'][0].strip('<>') == mail[tE]]
    check('D2 รายงานที่ถูกส่งกลับเกิน 3 วันแล้ววัดยังไม่ส่งใหม่ → เตือน E พร้อมเหตุผลที่ส่งกลับ', len(nudge) == 1 and 'ยอดไม่ตรงใบเสร็จ' in nudge[0]['body'], str([m['rcpt'] for m in nudge]))
    dig = {m['rcpt'][0].strip('<>'): m for m in got if 'สรุปการส่งรายงาน' in m['subject']}
    check('D3 สรุปส่งให้ staff 4 คน (แอดมินหลัก เจ้าคณะจังหวัด แอดมินอำเภอ เจ้าคณะตำบล)', sorted(dig) == sorted(semail.values()), str(sorted(dig)))
    check('D4 รวมทั้งหมดที่ส่ง = 2 เกินกำหนด + 1 ส่งกลับค้าง + 4 สรุป', len(got) == 7, str(len(got)))

    def scope_counts(where):
        n_all = int(q(f"SELECT COUNT(*) FROM temples t WHERE t.is_active=1 AND {where}"))
        n_rep = int(q(f"SELECT COUNT(*) FROM monthly_reports r JOIN temples t ON t.id=r.temple_id WHERE r.report_year={Y} AND r.report_month={M} AND t.is_active=1 AND {where}"))
        return n_all, n_all - n_rep
    for key, where in (('province', '1=1'), ('district', f't.district_id={dA}'), ('sub', f"t.district_id={dA} AND t.subdistrict='{subA}'")):
        n_all, late = scope_counts(where)
        body = dig[semail[key]]['body']
        check(f'D5 สรุปของ {key} นับเฉพาะขอบเขตตน (ทั้งหมด {n_all} เกินกำหนด {late})', f'วัดในความดูแลทั้งหมด {n_all} วัด' in body and f'ยังไม่ส่งและเกินกำหนด {late} วัด' in body, body[:300])
    check('D6 เตือนเกินกำหนดแล้ว ทำเครื่องหมายเหมือนเตือนด้วยมือ (B, C ในตาราง report_reminders)', q(f"SELECT COUNT(*) FROM report_reminders WHERE report_year={Y} AND report_month={M} AND temple_id IN ({tB},{tC})") == '2')
    n3 = len(smtp.messages)
    cron('2026-10-11')
    check('D7 รันซ้ำ ไม่ส่งซ้ำ', len(smtp.messages) == n3)
    check('D8 หน้าติดตามรายงานแสดงเวลารันล่าสุด (แอดมินหลัก)', 'รันล่าสุด' in adm.req(f'/report_status.php?year={Y}&month={M}')[2])

    # ---- E: รับรองครบแล้วยังไม่ปิดงวด
    q(f"UPDATE monthly_reports SET sub_approved_at=NOW(), dist_approved_at=NOW() WHERE temple_id={tA} AND report_year={Y} AND report_month={M}")
    n4 = len(smtp.messages)
    cron('2026-10-12')
    got = new_msgs(n4)
    check('E1 อำเภอรับรองครบแล้วยังไม่ปิดงวด → เตือน A ให้ปิดงวด', to(got) == [mail[tA]] and 'ปิดงวด' in got[0]['subject'], str(to(got)))
    n5 = len(smtp.messages)
    cron('2026-10-12')
    check('E2 ไม่เตือนซ้ำ', len(smtp.messages) == n5)
    q(f"UPDATE monthly_reports SET closed_at=NOW() WHERE temple_id={tA} AND report_year={Y} AND report_month={M}")

    # ---- F: เปิด/ปิด และจำนวนวัน
    q(f"UPDATE temples SET email='f@example.org' WHERE id={tF}")
    q("INSERT INTO settings (k, v) VALUES ('reminder_auto','0') ON DUPLICATE KEY UPDATE v='0'")
    n6 = len(smtp.messages)
    out = cron('2026-10-13')
    check('F1 ปิดแจ้งเตือนอัตโนมัติ → ไม่ส่งอะไร', len(smtp.messages) == n6 and 'ปิดอยู่' in out, out)
    q("UPDATE settings SET v='1' WHERE k='reminder_auto'")
    cron('2026-10-13')
    check('F2 เปิดอีกครั้ง → เตือนวัด F ที่เกินกำหนด', to(new_msgs(n6)) == ['f@example.org'])
    q(f"UPDATE temples SET email='g@example.org' WHERE id={tG}")
    q("INSERT INTO settings (k, v) VALUES ('reminder_days_before','1') ON DUPLICATE KEY UPDATE v='1'")
    n7 = len(smtp.messages)
    cron('2026-10-08')
    check('F3 ตั้งเตือนก่อน 1 วัน: วันที่ 8 (เหลือ 2 วัน) ยังไม่เตือน G', 'g@example.org' not in to(new_msgs(n7)))
    cron('2026-10-09')
    check('F4 ตั้งเตือนก่อน 1 วัน: วันที่ 9 เตือน G', 'g@example.org' in to(new_msgs(n7)))

    # ---- G: สิทธิ์และการเรียก
    c, _, b = Client(base).req('/cron_reminders.php')
    check('G1 เรียก cron_reminders.php ผ่านเว็บไม่ได้ (404)', c == 404)
    h = subprocess.run(['php', '-r', f"echo password_hash('{PW}', PASSWORD_DEFAULT);"], capture_output=True, text=True).stdout
    dcode = q(f"SELECT code FROM districts WHERE id={dA}")
    uid = q(f"SELECT id FROM users WHERE username='amphoe{dcode}'")
    q(f"UPDATE users SET password_hash='{h}', must_change_password=0 WHERE id={uid}")
    dist = Client(base)
    dist.post('/login.php', {'username': f'amphoe{dcode}', 'password': PW})
    page = f'/report_status.php?year={Y}&month={M}'
    dist.post(page, {'action': 'set_auto', 'days_before': 9, 'year': Y, 'month': M}, page)
    check('G2 แอดมินอำเภอแก้ค่าแจ้งเตือนอัตโนมัติไม่ได้', q("SELECT v FROM settings WHERE k='reminder_days_before'") == '1' and q("SELECT v FROM settings WHERE k='reminder_auto'") == '1')
    check('G3 แอดมินอำเภอไม่เห็นฟอร์มตั้งค่า', 'name="auto_on"' not in dist.req(page)[2])
    adm.post(page, {'action': 'set_auto', 'days_before': 5, 'year': Y, 'month': M}, page)
    check('G4 แอดมินหลักปิดแจ้งเตือน (ไม่ติ๊ก) และตั้ง 5 วัน', q("SELECT v FROM settings WHERE k='reminder_auto'") == '0' and q("SELECT v FROM settings WHERE k='reminder_days_before'") == '5')

    # ---- H: ก่อน/หลังรัน migration
    q('DROP TABLE report_notices')
    out = cron('2026-10-14')
    check('H1 ยังไม่รัน migration → cron แจ้งแล้วจบอย่างปกติ (ไม่ error)', 'migration' in out)
    check('H2 หน้าติดตามรายงานยังใช้ได้ ไม่มีฟอร์มตั้งค่า', adm.req(page)[0] == 200 and 'name="auto_on"' not in adm.req(page)[2])
    mig = open(__file__.replace('tests/test_reminders.py', 'sql/migrations/2026_report_notices.sql'), encoding='utf-8').read().replace('USE tfms_phrae;', '')
    q(mig)
    q("UPDATE settings SET v='1' WHERE k='reminder_auto'")
    out = cron('2026-10-14')
    check('H3 รัน migration แล้ว cron ทำงานต่อได้', 'ไม่มีอีเมล' in out and q("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name='report_notices'") == '1', out)
