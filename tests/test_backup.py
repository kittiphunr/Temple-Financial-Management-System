"""ชุดที่ 22: สำรองข้อมูลและกู้คืน — ไฟล์สำรอง .sql.gz (PDO ล้วน) กู้คืนแล้วข้อมูลตรงกันทุกตาราง กุญแจระบบเข้ารหัสด้วยรหัสผ่านสำรอง สำรองอัตโนมัติ สิทธิ์แอดมินหลัก"""
import base64
import gzip
import json
import os
import re
import stat
import subprocess
from pathlib import Path
from urllib.parse import urlencode

from support import App, Client, FakeSMTP, hash_password, notice

PW = 'Wat12345pw!'
KEYPASS = 'ม้าแข็งแรง correct-horse-12'


def run(db, R):
    smtp = FakeSMTP()
    name = db.new_database('backup')
    rname = db.new_database('restored')
    q = db.q(name)
    env = {'TFMS_DB_HOST': db.host, 'TFMS_DB_PORT': db.port, 'TFMS_DB_USER': db.user, 'TFMS_DB_PASS': db.password, 'TFMS_DB_NAME': name,
           'TFMS_MAIL_DRIVER': 'smtp', 'TFMS_SMTP_HOST': '127.0.0.1', 'TFMS_SMTP_PORT': str(smtp.port), 'TFMS_SMTP_SECURE': 'none',
           'TFMS_SMTP_USER': 'smtpuser', 'TFMS_SMTP_PASS': 'smtppass', 'TFMS_MAIL_FROM': 'no-reply@wat.test'}
    app = App(env=env)
    app2 = App(env={**env, 'TFMS_DB_NAME': rname})
    try:
        _run(R, q, db.q(rname), app, app2, smtp, rname)
    finally:
        problems = app.php_problems()
        R.check('Z1 ไม่มี PHP warning/notice/fatal ใน log ของเซิร์ฟเวอร์', not problems, '\n'.join(problems[:3]))
        app.close()
        app2.close()
        smtp.stop()


def _tables(q):
    return [t for t in q("SELECT table_name FROM information_schema.tables WHERE table_schema=DATABASE() AND table_type='BASE TABLE' ORDER BY table_name").split('\n') if t]


def _checksums(q, tables):
    return {t: q(f'CHECKSUM TABLE `{t}`').split('\t')[-1] for t in tables}


def _run(R, q, q2, app, app2, smtp, rname):
    check = R.check
    app.php('setup.php', 'Test12345pw')
    base = app.serve()
    adm = Client(base)
    adm.post('/login.php', {'username': 'admin', 'password': 'Test12345pw'})

    # ข้อมูลที่ทำให้การสำรองยาก: อัญประกาศ แบ็กสแลช ขึ้นบรรทัดใหม่ อีโมจิ ; NULL และไบนารี
    tA = q("SELECT id FROM temples ORDER BY id LIMIT 1")
    cat = q("SELECT id FROM categories WHERE type='income' AND is_transfer=0 LIMIT 1")
    nasty = "O'Brien \"quote\" \\ back\\slash\nบรรทัดใหม่\r\n😀 ไทย; DROP TABLE x; -- แล้ว"
    app.php('-r', "require 'includes/bootstrap.php'; db()->prepare('INSERT INTO transactions (temple_id,type,category_id,txn_date,description,amount,doc_no) VALUES (?,?,?,?,?,?,NULL)')->execute([" + tA + ",'income'," + cat + ",'2026-01-05'," + json.dumps(nasty, ensure_ascii=False).replace('$', '\\$') + ",1234.50]);")
    blob = os.urandom(3000) + b"\x00\x01'\"\;--\n"
    q(f"INSERT INTO media (kind, mime, size, data) VALUES ('photo','image/webp',{len(blob)},FROM_BASE64('{base64.b64encode(blob).decode()}'))")
    q(f"UPDATE temples SET email='dump@example.org' WHERE id={tA}")

    # ---------- CLI สำรองและกู้คืน ----------
    r = app.php('backup.php', '--keep=3')
    bdir = app.dir / 'storage' / 'backups'
    files = sorted(bdir.glob('tfms-*.sql.gz'))
    check('B1 php backup.php สร้างไฟล์ .sql.gz ใน storage/backups (สิทธิ์ 0600 มี .htaccess กันเว็บ)',
          r.returncode == 0 and 'สำเร็จ' in r.stdout and len(files) == 1 and stat.S_IMODE(files[0].stat().st_mode) == 0o600 and (bdir / '.htaccess').exists(), r.stdout + r.stderr)
    text = gzip.decompress(files[0].read_bytes()).decode('utf-8')
    check('B2 ไฟล์สำรองเป็น SQL ที่ถูกต้อง: มี DROP/CREATE/INSERT ครบทุกตาราง และข้อความขึ้นบรรทัดใหม่ถูก escape (หนึ่งคำสั่งต่อบรรทัด)',
          'CREATE TABLE `transactions`' in text and 'INSERT INTO `media`' in text and all(f'DROP TABLE IF EXISTS `{t}`' in text for t in _tables(q)) and "O\\'Brien" in text and '\nบรรทัดใหม่' not in text)
    check('B3 ข้อมูลไบนารี (BLOB) ถูกเขียนเป็นเลขฐานสิบหก ไม่ใช่ข้อความดิบ', '0x' + blob[:16].hex() in text)

    r = app2.php('restore_backup.php', str(files[0]))
    check('B4 restore_backup.php ไม่ใส่ --yes → ปฏิเสธ (รหัสออก 2) ไม่แตะฐานข้อมูล', r.returncode == 2 and '--yes' in r.stderr and q2("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE()") == '0', r.stderr)
    r = app2.php('restore_backup.php', str(files[0]), '--yes')
    check('B5 กู้คืนลงฐานข้อมูลใหม่สำเร็จ', r.returncode == 0 and 'กู้คืนสำเร็จ' in r.stdout, r.stdout + r.stderr)
    tabs = [t for t in _tables(q) if t != 'settings']
    c1, c2 = _checksums(q, tabs), _checksums(q2, tabs)
    diff = [t for t in tabs if c1[t] != c2[t]]
    check(f'B6 หลังกู้คืน ข้อมูลตรงกันทุกตาราง ({len(tabs)} ตาราง CHECKSUM เท่ากัน)', _tables(q2) == _tables(q) and not diff, str(diff))
    check('B7 ข้อความยากและ BLOB กลับมาครบ (ตรวจค่าตรง ๆ)',
          q2("SELECT description FROM transactions WHERE amount=1234.50 AND txn_date='2026-01-05'").replace('\\n', '\n').count('บรรทัดใหม่') == 1
          and q2(f"SELECT LOWER(HEX(data)) FROM media WHERE size={len(blob)}") == blob.hex() and q2("SELECT COUNT(*) FROM transactions WHERE doc_no IS NULL AND description LIKE '%DROP TABLE x%'") == '1')
    check('B8 กู้คืนแล้วระบบเดิมยังเข้าสู่ระบบได้ (รหัสผ่านแอดมินคงเดิม)', q2("SELECT COUNT(*) FROM users WHERE username='admin'") == '1')

    # ---------- กุญแจระบบ ----------
    app_key_b64 = (app.dir / 'storage' / 'app.key').read_text().strip()
    raw = base64.b64decode(app_key_b64)

    def php(app_, code, **env):
        return app_.php('-r', "require 'includes/bootstrap.php'; " + code, extra_env=env)
    exp = php(app, f"echo backup_key_export({json.dumps(KEYPASS, ensure_ascii=False)});").stdout
    j = json.loads(exp)
    check('B9 ไฟล์กุญแจส่งออกเป็น JSON เข้ารหัส (ไม่มีกุญแจดิบอยู่ในไฟล์)', j.get('tfms_key') == 1 and raw.hex() not in exp.lower() and app_key_b64 not in exp and j.get('kdf') == 'argon2id13', exp[:120])
    keyfile = app.dir / 'key.json'
    keyfile.write_text(exp)
    ok = php(app, f"echo bin2hex(backup_key_import(file_get_contents('{keyfile}'), {json.dumps(KEYPASS, ensure_ascii=False)}));").stdout
    bad = php(app, f"var_dump(backup_key_import(file_get_contents('{keyfile}'), 'รหัสผิดรหัสผิดรหัสผิด'));").stdout
    tam = json.loads(exp)
    tam['ct'] = base64.b64encode(b'x' * 48).decode()
    (app.dir / 'tam.json').write_text(json.dumps(tam))
    tamper = php(app, f"var_dump(backup_key_import(file_get_contents('{app.dir / 'tam.json'}'), {json.dumps(KEYPASS, ensure_ascii=False)}));").stdout
    short = php(app, "try { backup_key_export('short'); echo 'no'; } catch (InvalidArgumentException $e) { echo 'refused'; }").stdout
    check('B10 ถอดรหัสกุญแจด้วยรหัสผ่านสำรองถูกต้องได้กุญแจเดิม · รหัสผิด/ไฟล์ถูกแก้ → null · รหัสผ่านสั้นกว่า 12 → ปฏิเสธ',
          ok == raw.hex() and 'NULL' in bad and 'NULL' in tamper and short == 'refused', f'{ok[:8]} {bad} {tamper} {short}')

    # กู้คืนลงเซิร์ฟเวอร์ใหม่ที่ยังไม่มีกุญแจ
    app3 = App(env=app2.env)
    try:
        (app3.dir / 'key.json').write_text(exp)
        r = app3.php('restore_backup.php', str(files[0]), '--yes', '--key=' + str(app3.dir / 'key.json'), extra_env={'TFMS_KEY_PASSPHRASE': 'รหัสผิดรหัสผิดรหัสผิด'})
        no_key = not (app3.dir / 'storage' / 'app.key').exists()
        r2 = app3.php('restore_backup.php', str(files[0]), '--yes', '--key=' + str(app3.dir / 'key.json'), extra_env={'TFMS_KEY_PASSPHRASE': KEYPASS})
        got = (app3.dir / 'storage' / 'app.key').read_text().strip() if (app3.dir / 'storage' / 'app.key').exists() else ''
        check('B11 กู้คืนพร้อม --key: รหัสผ่านผิด → ไม่เขียนกุญแจและไม่กู้คืน · ถูก → เขียน storage/app.key เป็นกุญแจเดิม', r.returncode == 1 and no_key and r2.returncode == 0 and got == app_key_b64, r.stderr + r2.stderr)
    finally:
        app3.close()

    # ---------- หน้าเว็บ ----------
    code, _, pg = adm.req('/admin/backup.php')
    check('B12 หน้าสำรองข้อมูลแสดงไฟล์ที่มีอยู่ ตัวตั้งค่า คำสั่ง cron และวิธีกู้คืน', code == 200 and files[0].name in pg and 'php backup.php' in pg and 'restore_backup.php' in pg)
    adm.post('/admin/backup.php', {'action': 'create'}, '/admin/backup.php')
    names = [p.name for p in sorted(bdir.glob('tfms-*.sql.gz'))]
    check('B13 สร้างไฟล์สำรองจากหน้าเว็บได้ (ชื่อไม่ซ้ำแม้สร้างในวินาทีเดียวกัน)', len(names) == 2 and len(set(names)) == 2, str(names))
    c, hd, body = adm.raw('/admin/backup.php?download=' + names[-1])
    check('B14 ดาวน์โหลดไฟล์สำรองได้ (gzip, attachment, ไม่แคช)', c == 200 and body[:2] == b'\x1f\x8b' and 'attachment' in hd.get('Content-Disposition', '') and 'no-store' in hd.get('Cache-Control', ''))
    evil = ['../../config.php', '..%2F..%2Fconfig.php', 'tfms-20260101-000000.sql.gz', 'tfms-%2e%2e.sql.gz', 'x.sql.gz', '.tmp-aaaa', '../storage/app.key']
    bad_codes = [adm.raw('/admin/backup.php?download=' + n)[0] for n in evil]
    check('B15 ชื่อไฟล์นอกรูปแบบ/path traversal/ไฟล์ที่ไม่มี → 404 ทั้งหมด', all(c == 404 for c in bad_codes), str(bad_codes))
    adm.post('/admin/backup.php', {'action': 'delete', 'name': '../../config.php'}, '/admin/backup.php')
    adm.post('/admin/backup.php', {'action': 'delete', 'name': names[0]}, '/admin/backup.php')
    check('B16 ลบไฟล์สำรองได้เฉพาะชื่อที่ถูกต้อง (config.php ไม่ถูกแตะ)', not (bdir / names[0]).exists() and (app.dir / 'config.php').exists())

    # สิทธิ์
    tuser = q(f"SELECT username FROM users WHERE temple_id={tA}")
    h = hash_password(app, PW)
    q(f"UPDATE users SET password_hash='{h}', must_change_password=0 WHERE temple_id={tA}")
    ct = Client(base)
    ct.post('/login.php', {'username': tuser, 'password': PW})
    ct.post('/login_verify.php', {'action': 'verify', 'code': smtp.last_code()})
    c1, _, p1 = ct.req('/admin/backup.php')
    c2 = ct.raw('/admin/backup.php?download=' + names[-1])[0]
    check('B17 แอดมินวัดเข้าหน้าสำรองข้อมูลและดาวน์โหลดไม่ได้ (403)', c1 == 403 and c2 == 403 and files[0].name not in p1)
    c3 = Client(base).raw('/admin/backup.php?download=' + names[-1])[0]
    check('B18 ยังไม่ล็อกอินดาวน์โหลดไม่ได้', c3 in (302, 401, 403))

    # กุญแจผ่านเว็บ
    def keypost(**kw):
        f = {'action': 'key', 'passphrase': KEYPASS, 'passphrase2': KEYPASS, 'password': 'Test12345pw', **kw}
        f['csrf'] = adm.csrf('/admin/backup.php')
        return adm.req('/admin/backup.php', raw=urlencode(f).encode(), headers={'Content-Type': 'application/x-www-form-urlencoded'})
    results = {k: keypost(**v) for k, v in {'wrongpw': {'password': 'ผิดผิดผิด'}, 'mismatch': {'passphrase2': 'อื่นอื่นอื่นอื่นอื่น'}, 'short': {'passphrase': 'short', 'passphrase2': 'short'}}.items()}
    bad_ok = all(r[0] in (302, 303) and not r[2].lstrip().startswith('{') for r in results.values())
    c, loc, body = keypost()
    got = backup_key = None
    try:
        keyjson = body
        (app.dir / 'dl.json').write_text(keyjson)
        got = php(app, f"echo bin2hex(backup_key_import(file_get_contents('{app.dir / 'dl.json'}'), {json.dumps(KEYPASS, ensure_ascii=False)}));").stdout
    except Exception:
        pass
    check('B19 ส่งออกกุญแจผ่านเว็บ: ต้องใส่รหัสผ่านปัจจุบันถูก รหัสสำรองตรงกันและยาวพอ · สำเร็จแล้วถอดรหัสได้กุญแจเดิม',
          bad_ok and c == 200 and 'attachment' in adm.headers.get('Content-Disposition', '') and 'application/json' in adm.headers.get('Content-Type', '') and got == raw.hex(), f'{c} {got}')
    check('B20 การส่งออกกุญแจและดาวน์โหลดไฟล์สำรองถูกบันทึกใน audit log', int(q("SELECT COUNT(*) FROM audit_log WHERE action IN ('backup_key_export','backup_download','backup_create')")) >= 3)

    # ตั้งค่า + สำรองอัตโนมัติ
    adm.post('/admin/backup.php', {'action': 'settings', 'auto_days': '5', 'keep': '3'}, '/admin/backup.php')
    check('B21 ค่าสำรองอัตโนมัติที่ไม่อยู่ในตัวเลือกถูกปฏิเสธ', q("SELECT COUNT(*) FROM settings WHERE k='backup_auto_days' AND v='5'") == '0')
    n0 = len(list(bdir.glob('tfms-*.sql.gz')))
    adm.req('/admin/index.php')
    check('B22 ปิดสำรองอัตโนมัติ (ค่าเริ่มต้น): เปิดหน้าจัดการระบบแล้วไม่สร้างไฟล์', len(list(bdir.glob('tfms-*.sql.gz'))) == n0)
    adm.post('/admin/backup.php', {'action': 'settings', 'auto_days': '1', 'keep': '2'}, '/admin/backup.php')
    q("DELETE FROM settings WHERE k='backup_last'")
    adm.req('/admin/index.php')
    n1 = len(list(bdir.glob('tfms-*.sql.gz')))
    adm.req('/admin/index.php')
    n2 = len(list(bdir.glob('tfms-*.sql.gz')))
    check('B23 เปิดสำรองอัตโนมัติทุกวัน: ถึงกำหนดแล้วสร้างไฟล์ 1 ครั้ง (ไม่สร้างซ้ำถ้ายังไม่ถึงรอบ) และเก็บไม่เกิน 2 ไฟล์', n1 <= 2 and n2 == n1 and int(q("SELECT COUNT(*) FROM audit_log WHERE action='backup_auto'")) == 1, f'{n0} {n1} {n2}')
    q("UPDATE settings SET v='1' WHERE k='backup_last'")
    adm.req('/admin/index.php')
    check('B24 เลยกำหนดแล้วสำรองใหม่ (backup_last เก่า)', int(q("SELECT COUNT(*) FROM audit_log WHERE action='backup_auto'")) == 2)
    r = app.php('backup.php', '--keep=1')
    check('B25 php backup.php --keep=1 ลบไฟล์เก่า เหลือไฟล์เดียว', r.returncode == 0 and len(list(bdir.glob('tfms-*.sql.gz'))) == 1, r.stdout)
