"""ชุดที่ 3: โลโก้วัด/อำเภอ, บุคลากรวัด (ข้อมูลส่วนบุคคล), โควตาผู้ใช้ระดับจังหวัด/อำเภอ, บทบาทเจ้าคณะจังหวัด

หัวใจคือ "ใครเห็นข้อมูลอะไร": เลขบัตรประชาชนต้องเข้ารหัสในฐานข้อมูล แสดงแบบปิดบัง ไม่รั่วในหน้าเว็บ/ประวัติการใช้งาน
และรูปถ่าย/ข้อมูลบุคลากรต้องเปิดได้เฉพาะผู้มีสิทธิ์
"""
import re
import struct

from support import (App, Client, FakeSMTP, age_otps, notice, parse_creds_csv, png_bytes, jpeg_bytes, thai_id)

STAFF_PW = 'DistrictPass123'


def run(db, R):
    smtp = FakeSMTP()
    name = db.new_database('people')
    q = db.q(name)
    app = App(env={
        'TFMS_DB_HOST': db.host, 'TFMS_DB_PORT': db.port, 'TFMS_DB_USER': db.user, 'TFMS_DB_PASS': db.password, 'TFMS_DB_NAME': name,
        'TFMS_MAIL_DRIVER': 'smtp', 'TFMS_SMTP_HOST': '127.0.0.1', 'TFMS_SMTP_PORT': str(smtp.port), 'TFMS_SMTP_SECURE': 'none',
        'TFMS_SMTP_USER': 'smtpuser', 'TFMS_SMTP_PASS': 'smtppass', 'TFMS_MAIL_FROM': 'no-reply@wat.test',
    })
    try:
        _run(db, R, smtp, q, app)
    finally:
        problems = app.php_problems()
        R.check('Z1 ไม่มี PHP warning/notice/fatal ใน log ของเซิร์ฟเวอร์', not problems, '\n'.join(problems[:3]))
        app.close()
        smtp.stop()


def _run(db, R, smtp, q, app):
    check = R.check
    p = app.php('setup.php', 'Test12345pw', '--no-staff')
    check('S1 ติดตั้งด้วย setup.php (สร้างกุญแจเข้ารหัส storage/app.key)', p.returncode == 0 and (app.dir / 'storage' / 'app.key').is_file(), p.stdout + p.stderr)
    creds = parse_creds_csv(sorted((app.dir / 'storage').glob('temple_accounts_*.csv'))[0].read_text(encoding='utf-8'))
    q("UPDATE users SET must_change_password = 0 WHERE role = 'temple'")
    base = app.serve()
    adm = Client(base)
    adm.post('/login.php', {'username': 'admin', 'password': 'Test12345pw'})

    by_dist = {}
    for c in creds:
        by_dist.setdefault(c[1], []).append(c)
    A, B, C = by_dist['เมืองแพร่'][0], by_dist['เมืองแพร่'][1], by_dist['เด่นชัย'][0]

    def tid_of(cred):
        return q(f"SELECT id FROM temples WHERE temple_code='{cred[0]}'")

    def login_temple(cred, email):
        tid = tid_of(cred)
        adm.post('/admin/temple_accounts.php', {'action': 'save_email', 'temple_id': tid, 'email': email}, '/admin/temple_accounts.php')
        age_otps(q, f"user_id=(SELECT id FROM users WHERE temple_id={tid})")
        cl = Client(base)
        cl.post('/login.php', {'username': cred[3], 'password': cred[4]})
        cl.post('/login_verify.php', {'action': 'verify', 'code': smtp.last_code()})
        return cl, tid

    # ======================= Q: โควตาผู้ใช้ระดับจังหวัด/อำเภอ =======================
    c, _, b = adm.req('/admin/users.php')
    check('Q1 หน้าผู้ใช้แสดงโควตา "ใช้ไป 1 จาก 10 คน" (8 อำเภอ + แอดมินหลัก + เจ้าคณะจังหวัด)', 'ใช้ไป 1 จาก 10 คน' in b, notice(b))

    def staff(slot, user, pw=STAFF_PW, full='ผู้ทดสอบ'):
        adm.post('/admin/users.php', {'action': 'create', 'slot': slot, 'username': user, 'full_name': full, 'password': pw}, '/admin/users.php')
        return notice(adm.req('/admin/users.php')[2])

    check('Q2 รหัสผ่านอ่อน (สั้น/ไม่มีตัวเลข) ถูกปฏิเสธ', 'อย่างน้อย 10' in staff('province', 'chief1', pw='short1') and 'อย่างน้อย 10' in staff('province', 'chief1', pw='onlyletterspass'))
    check('Q2b ชื่อผู้ใช้ขึ้นต้นด้วย wat (สงวนไว้สำหรับวัด) ถูกปฏิเสธ', 'wat' in staff('province', 'watchief') and q("SELECT COUNT(*) FROM users WHERE role='province'") == '0')
    check('Q3 สร้างผู้ใช้เจ้าคณะจังหวัดได้', 'สร้างผู้ใช้ตำแหน่ง' in staff('province', 'chief1') and q("SELECT COUNT(*) FROM users WHERE role='province'") == '1')
    check('Q3b ตำแหน่งเจ้าคณะจังหวัดมีผู้ใช้แล้ว สร้างคนที่สองไม่ได้', 'มีผู้ใช้แล้ว' in staff('province', 'chief2') and q("SELECT COUNT(*) FROM users WHERE role='province'") == '1')
    check('Q3c สร้างแอดมินหลักคนที่สองไม่ได้ (มีหนึ่งคนแล้ว)', 'มีผู้ใช้แล้ว' in staff('admin', 'admin2') and q("SELECT COUNT(*) FROM users WHERE role='admin'") == '1')
    districts = [r.split('\t') for r in q("SELECT id, name FROM districts ORDER BY sort_order, id").split('\n')]
    for i, (did, dname) in enumerate(districts):
        n = staff(f'district:{did}', f'dist_{i}')
        if not ('สร้างผู้ใช้ตำแหน่ง' in n):
            check(f'Q4 สร้างแอดมินประจำอำเภอ{dname}', False, n)
    check('Q4 สร้างแอดมินประจำอำเภอครบ 8 อำเภอ', q("SELECT COUNT(*) FROM users WHERE role='district'") == '8')
    c, _, b = adm.req('/admin/users.php')
    check('Q5 ครบ "ใช้ไป 10 จาก 10 คน" และขึ้นว่าครบทุกตำแหน่ง', 'ใช้ไป 10 จาก 10 คน' in b and 'ครบทุกตำแหน่งแล้ว' in b)
    check('Q5b สร้างคนที่ 11 ไม่ได้ (ไม่มีช่องว่าง)', staff('district:' + districts[0][0], 'extra1') != '' and q("SELECT COUNT(*) FROM users WHERE role <> 'temple'") == '10')
    check('Q5c ผู้ใช้ระดับจังหวัด/อำเภอรวม = จำนวนอำเภอ + 2', q("SELECT COUNT(*) FROM users WHERE role <> 'temple'") == str(len(districts) + 2))
    try:
        q(f"INSERT INTO users (username,password_hash,full_name,role,district_id) VALUES ('dupd','x','x','district',{districts[0][0]})")
        dup = False
    except RuntimeError as e:
        dup = 'Duplicate' in str(e) or '1062' in str(e)
    check('Q6 ฐานข้อมูลกันแอดมินอำเภอซ้ำในอำเภอเดียวกัน (unique)', dup)
    q("INSERT INTO districts (code, name, sort_order) VALUES ('9999','อำเภอทดสอบ',99)")
    c, _, b = adm.req('/admin/users.php')
    check('Q7 สูตรโควตา: เพิ่มอำเภอที่ 9 → โควตาเป็น 11 และมีตำแหน่งว่าง 1', 'ใช้ไป 10 จาก 11 คน' in b and 'แอดมินประจำอำเภออำเภอทดสอบ' in b)
    q("DELETE FROM districts WHERE code='9999'")
    q(f"INSERT INTO users (username,password_hash,full_name,role) VALUES ('adm_extra','x','เกินโควตา','admin')")
    c, _, b = adm.req('/admin/users.php')
    check('Q8 ผู้ใช้ที่เกินโควตาถูกแสดงให้ลบ', 'ผู้ใช้ที่เกินโควตา' in b and 'adm_extra' in b)
    chief_id = q("SELECT id FROM users WHERE username='chief1'")
    adm.post('/admin/users.php', {'action': 'delete_extra', 'id': chief_id}, '/admin/users.php')
    check('Q8b ลบผู้ใช้ที่ครองตำแหน่งจริงผ่านช่องทางลบส่วนเกินไม่ได้', q("SELECT COUNT(*) FROM users WHERE username='chief1'") == '1')
    adm.post('/admin/users.php', {'action': 'delete_extra', 'id': q("SELECT id FROM users WHERE username='adm_extra'")}, '/admin/users.php')
    check('Q8c ลบผู้ใช้ส่วนเกินสำเร็จ', q("SELECT COUNT(*) FROM users WHERE username='adm_extra'") == '0')

    dm = Client(base)    # แอดมินประจำอำเภอเมืองแพร่ (dist_0)
    dd = Client(base)    # แอดมินประจำอำเภอเด่นชัย
    idx_dc = [i for i, (_, n) in enumerate(districts) if n == 'เด่นชัย'][0]
    dm.post('/login.php', {'username': 'dist_0', 'password': STAFF_PW})
    dd.post('/login.php', {'username': f'dist_{idx_dc}', 'password': STAFF_PW})
    chief = Client(base)
    n_mail = len(smtp.messages)
    c, loc, _ = chief.post('/login.php', {'username': 'chief1', 'password': STAFF_PW})
    check('Q9 เจ้าคณะจังหวัดเข้าสู่ระบบด้วยรหัสผ่านอย่างเดียว (ไม่ส่ง 2FA)', loc == 'index.php' and len(smtp.messages) == n_mail)
    check('Q9b เจ้าคณะจังหวัดเปิดแดชบอร์ดทั้งจังหวัดและรายงานได้', chief.req('/dashboard.php')[0] == 200 and chief.req('/reports.php')[0] == 200)
    c, loc, _ = chief.req('/transactions.php?type=income')
    check('Q9c เจ้าคณะจังหวัดไม่มีหน้าบันทึกบัญชี (เด้งไปแดชบอร์ด)', c == 302 and 'dashboard.php' in loc)
    check('Q9d เจ้าคณะจังหวัดเข้าหน้า admin ไม่ได้ (403)', chief.req('/admin/users.php')[0] == 403)
    c, _, b = chief.req('/dashboard.php')
    nav = re.search(r'<nav class="hidden[^>]*>(.*?)</nav>', b, re.S).group(1)
    check('Q9e เมนูเจ้าคณะจังหวัด: ไม่มีบัญชีรายรับ/รายจ่าย/จัดการระบบ', 'บัญชีรายรับ' not in nav and 'จัดการระบบ' not in nav and 'บุคลากร' in nav, re.sub('<[^>]+>', ' ', nav))

    # ======================= L: โลโก้วัด / อำเภอ =======================
    ta, tid_a = login_temple(A, 'a@wat.test')
    tb, tid_b = login_temple(B, 'b@wat.test')
    tc, tid_c = login_temple(C, 'c@wat.test')
    c, _, b = ta.req('/dashboard.php')
    check('L1 แอดมินวัดที่ยังไม่มีโลโก้เห็นแบนเนอร์เตือนทุกหน้า', 'ยังไม่ได้ใส่โลโก้วัด' in b and 'profile.php#logo' in b)
    check('L1b และเตือนให้กรอกข้อมูลเจ้าอาวาส', 'ยังไม่ได้กรอกข้อมูลเจ้าอาวาส' in b)
    c, _, b = ta.req('/profile.php')
    check('L1c หน้า "ข้อมูลวัดของฉัน" แสดงรายการที่ต้องกรอก', c == 200 and 'โลโก้วัด' in b and 'จำเป็นสำหรับการออกเอกสาร' in b)

    def up_logo(cl, content, fname='logo.png', ctype='image/png', path='/profile.php', fields=None, field='logo', page=None):
        cl.upload(path, dict({'action': 'logo_upload'}, **(fields or {})), fname, content, page or path, field, ctype)
        return notice(cl.req(page or path)[2])

    up_logo(ta, png_bytes(800, 600, (180, 20, 20, 200)))
    lid_a = q(f"SELECT logo_media_id FROM temples WHERE id={tid_a}")
    check('L2 อัปโหลดโลโก้สำเร็จ (เก็บเป็น WebP ในฐานข้อมูล)', lid_a != '' and q(f"SELECT CONCAT(kind,'/',mime) FROM media WHERE id={lid_a}") == 'logo/image/webp')
    code, hdr, body = ta.raw(f'/media.php?id={lid_a}')
    w, h = struct.unpack('>II', body[16:24]) if body[:4] == b'\x89PNG' else (0, 0)
    check('L3 รูปถูกย่อ (ไม่เกิน 512px) และส่งพร้อมหัวความปลอดภัย',
          code == 200 and max(w, h) == 512 and hdr.get('X-Content-Type-Options') == 'nosniff' and "default-src 'none'" in (hdr.get('Content-Security-Policy') or ''), f'{code} {w}x{h} {dict(hdr)}')
    c, _, b = ta.req('/dashboard.php')
    check('L4 หลังใส่โลโก้ แบนเนอร์เตือนหายไป', 'ยังไม่ได้ใส่โลโก้วัด' not in b)

    n_media = int(q("SELECT COUNT(*) FROM media"))
    up_logo(ta, png_bytes(300, 300, (20, 120, 20, 255)))
    check('L5 เปลี่ยนโลโก้ → รูปเก่าถูกลบ (ไม่มีรูปค้างในฐานข้อมูล)', int(q("SELECT COUNT(*) FROM media")) == n_media and q(f"SELECT logo_media_id FROM temples WHERE id={tid_a}") != lid_a)
    lid_a = q(f"SELECT logo_media_id FROM temples WHERE id={tid_a}")

    gif = b'GIF89a\x01\x00\x01\x00\x80\x00\x00\x00\x00\x00\xff\xff\xff!\xf9\x04\x01\x00\x00\x00\x00,\x00\x00\x00\x00\x01\x00\x01\x00\x00\x02\x02D\x01\x00;'
    for label, content, fname, ctype in [
        ('ไฟล์ข้อความนามสกุล .png', b'this is not an image', 'logo.png', 'image/png'),
        ('SVG (อาจแฝงสคริปต์)', b'<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>', 'logo.svg', 'image/svg+xml'),
        ('GIF', gif, 'logo.gif', 'image/gif'),
        ('PNG ที่ไฟล์เสียหาย', png_bytes(100, 100)[:60], 'logo.png', 'image/png'),
        ('PHP ปลอมเป็นรูป', b'<?php system($_GET["c"]); ?>', 'shell.php.png', 'image/png'),
    ]:
        n = up_logo(ta, content, fname, ctype)
        check(f'L6 ปฏิเสธไฟล์: {label}', ('ภาพ' in n or 'JPEG' in n) and q(f"SELECT logo_media_id FROM temples WHERE id={tid_a}") == lid_a, n)
    n = up_logo(ta, png_bytes(900, 900, noise=True))
    check('L6b ปฏิเสธไฟล์ใหญ่เกิน 2 MB', 'ใหญ่เกิน' in n and q(f"SELECT logo_media_id FROM temples WHERE id={tid_a}") == lid_a, n)
    ta.upload('/profile.php', {'action': 'logo_upload'}, None, b'', '/profile.php', 'logo')
    check('L6c ไม่เลือกไฟล์ → แจ้งให้เลือก ไม่เกิดรูปใหม่', 'กรุณาเลือกไฟล์' in notice(ta.req('/profile.php')[2]))

    up_logo(ta, png_bytes(120, 120) + b'<?php system("id"); ?>')
    lid_a = q(f"SELECT logo_media_id FROM temples WHERE id={tid_a}")
    code, hdr, body = ta.raw(f'/media.php?id={lid_a}')
    check('L7 PNG ที่แนบโค้ด PHP ต่อท้าย: ถูกแปลงใหม่ ข้อมูลแฝงหายไป', code == 200 and b'<?php' not in body and body[:4] == b'\x89PNG')

    c, loc, _ = Client(base).req(f'/media.php?id={lid_a}')
    check('L8 ผู้ที่ยังไม่ล็อกอินเปิดรูปไม่ได้', c == 302 and 'login.php' in loc)
    check('L8b รูปที่ไม่มีอยู่ → 404', ta.req('/media.php?id=999999')[0] == 404)

    up_logo(tb, png_bytes(200, 200, (10, 10, 200, 255)), fields={'temple': tid_a})
    check('L9 แอดมินวัดอัปโหลดแทนวัดอื่นไม่ได้ (พารามิเตอร์ temple ถูกเพิกเฉย)',
          q(f"SELECT logo_media_id FROM temples WHERE id={tid_b}") != '' and q(f"SELECT logo_media_id FROM temples WHERE id={tid_a}") == lid_a)
    check('L9b แอดมินวัดเปิดหน้า admin/districts ไม่ได้ (403)', ta.req('/admin/districts.php')[0] == 403)
    check('L9c แอดมินอำเภอ/เจ้าคณะจังหวัดเข้าหน้าโปรไฟล์วัด (แก้โลโก้) ไม่ได้ (403)', dm.req('/profile.php')[0] == 403 and chief.req('/profile.php')[0] == 403)

    up_logo(adm, png_bytes(256, 256, (0, 0, 0, 255)), path='/profile.php?temple=' + tid_c, page='/profile.php?temple=' + tid_c)
    check('L10 แอดมินหลักใส่โลโก้ให้วัดใดก็ได้', q(f"SELECT logo_media_id FROM temples WHERE id={tid_c}") != '')

    d_mueang = districts[0][0]
    adm.upload('/admin/districts.php', {'action': 'logo_upload', 'district_id': d_mueang}, 'd.png', png_bytes(400, 400, (150, 100, 0, 255)), '/admin/districts.php', 'logo', 'image/png')
    dlogo = q(f"SELECT logo_media_id FROM districts WHERE id={d_mueang}")
    check('L11 แอดมินหลักอัปโหลดโลโก้อำเภอได้', dlogo != '' and ta.raw(f'/media.php?id={dlogo}')[0] == 200)
    c, _, b = adm.req('/admin/districts.php')
    check('L11b หน้าโลโก้อำเภอบอกจำนวนอำเภอที่ยังไม่มีโลโก้ (7 จาก 8)', 'ยังไม่มีโลโก้ 7 จาก 8 อำเภอ' in b, notice(b))

    # หัวเอกสาร
    c, _, b = ta.req('/reports.php')
    tname_a = q(f"SELECT name FROM temples WHERE id={tid_a}")
    check('L12 รายงานของวัด: หัวเอกสารมีโลโก้วัดและชื่อวัด + บล็อกลงนาม', f'media.php?id={lid_a}' in b and tname_a in b and 'เจ้าอาวาส' in b and 'ไวยาวัจกร' in b)
    c, _, b = tb.req('/reports.php')
    check('L12b โลโก้ของวัดอื่นไม่ปนมาในรายงาน', f'media.php?id={lid_a}' not in b)
    c, _, b = adm.req(f'/reports.php?district={d_mueang}')
    check('L13 รายงานระดับอำเภอใช้โลโก้อำเภอ + "คณะสงฆ์อำเภอเมืองแพร่"', f'media.php?id={dlogo}' in b and 'คณะสงฆ์อำเภอเมืองแพร่' in b)
    c, _, b = adm.req('/reports.php')
    check('L13b รายงานระดับจังหวัด: "คณะสงฆ์จังหวัดแพร่" + โลโก้ระบบ', 'คณะสงฆ์จังหวัดแพร่' in b and 'assets/img/logo.svg' in b)
    tid_nologo = q("SELECT id FROM temples WHERE logo_media_id IS NULL ORDER BY id LIMIT 1")
    c, _, b = adm.req(f'/reports.php?temple={tid_nologo}')
    check('L14 รายงานของวัดที่ยังไม่มีโลโก้: เตือน (เฉพาะหน้าจอ) พร้อมลิงก์ใส่โลโก้', 'ยังไม่ได้ใส่โลโก้วัด' in b and 'print:hidden' in b and 'profile.php?temple=' in b)
    c, _, b = ta.req('/transactions.php?type=income')
    check('L15 หน้าบัญชีของวัดมีหัวเอกสารสำหรับพิมพ์ (ซ่อนบนหน้าจอ)', f'media.php?id={lid_a}' in b and 'hidden print:block' in b)
    c, _, b = adm.req('/admin/temple_accounts.php')
    with_logo = q("SELECT COUNT(*) FROM temples WHERE logo_media_id IS NOT NULL")
    check('L16 หน้าบัญชีวัดสรุปจำนวนวัดที่ยังไม่ใส่โลโก้', f'{381 - int(with_logo)}</div>' in b and 'ยังไม่ได้ใส่โลโก้วัด' in b)

    # ======================= P: บุคลากรวัด =======================
    id_abbot = thai_id('110170012345')
    id_bad = id_abbot[:-1] + str((int(id_abbot[-1]) + 1) % 10)   # หลักตรวจสอบผิดแน่นอน
    fmt = lambda i: f'{i[0]}-{i[1:5]}-{i[5:10]}-{i[10:12]}-{i[12]}'

    def person_form(**kw):
        d = {'id': '', 'temple': tid_a, 'position': 'abbot', 'title': 'พระครู', 'first_name': 'สมชาย', 'last_name': 'ใจดี', 'dhamma_name': 'ธมฺมวโร',
             'national_id': id_abbot, 'phone': '081-234-5678', 'home_address': '99 หมู่ 3 ต.ในเวียง อ.เมืองแพร่ จ.แพร่ 54000', 'consent': '1'}
        d.update(kw)
        return {k: v for k, v in d.items() if v is not None}

    def add_person(cl, photo=None, page=None, **kw):
        f = person_form(**kw)
        pg = page or f"/person.php?temple={f['temple']}"
        if photo is not None:
            c, loc, body = cl.upload('/person.php', f, 'p.jpg', photo, pg, 'photo', 'image/jpeg')
        else:
            c, loc, body = cl.upload('/person.php', f, None, b'', pg, 'photo')
        # ไม่ผ่านการตรวจ: หน้าถูกแสดงทันทีพร้อมข้อความ (ไม่ redirect); สำเร็จ: redirect ไปหน้ารายการแล้วข้อความอยู่หน้าถัดไป
        return notice(body) if loc is None else notice(cl.req('/' + loc)[2])

    for label, kw, msg in [
        ('เลขบัตรหลักตรวจสอบผิด', dict(national_id=id_bad), 'เลขบัตรประชาชนไม่ถูกต้อง'),
        ('เลขบัตรไม่ครบ 13 หลัก', dict(national_id='110170012345'), 'เลขบัตรประชาชนไม่ถูกต้อง'),
        ('เลขบัตรมีตัวอักษร', dict(national_id='11017001234AB'), 'เลขบัตรประชาชนไม่ถูกต้อง'),
        ('ไม่กรอกที่อยู่', dict(home_address=''), 'ที่อยู่ตามภูมิลำเนาเดิม'),
        ('เบอร์โทรศัพท์ไม่ถูกต้อง', dict(phone='12-34'), 'เบอร์โทรศัพท์ไม่ถูกต้อง'),
        ('ไม่กรอกนามสกุล', dict(last_name=''), 'กรุณากรอกนามสกุล'),
        ('ไม่ยืนยันความยินยอม (PDPA)', dict(consent=None), 'ความยินยอม'),
    ]:
        n = add_person(ta, **kw)
        check(f'P1 ปฏิเสธข้อมูลบุคลากร: {label}', msg in n and q(f"SELECT COUNT(*) FROM temple_people WHERE temple_id={tid_a}") == '0', n)

    photo = jpeg_bytes(app)
    n = add_person(ta, photo=photo)
    pid_abbot = q(f"SELECT id FROM temple_people WHERE temple_id={tid_a} AND position='abbot'")
    check('P2 เพิ่มเจ้าอาวาสพร้อมรูปถ่ายสำเร็จ', pid_abbot != '' and 'บันทึกข้อมูลบุคคลเรียบร้อย' in notice(ta.req('/people.php')[2]) or pid_abbot != '', n)

    row = q(f"SELECT HEX(national_id_enc), national_id_idx, national_id_last4, LENGTH(national_id_enc) FROM temple_people WHERE id={pid_abbot}").split('\t')
    enc_hex, idx, last4, enc_len = row[0], row[1], row[2], int(row[3])
    check('P3 เลขบัตรเข้ารหัสในฐานข้อมูล (ไม่มีเลขจริงในคอลัมน์ใดเลย)',
          id_abbot.encode().hex() not in enc_hex.lower() and idx != id_abbot and id_abbot not in idx and enc_len > 13 + 16,
          f'len={enc_len}')
    check('P3b เก็บเฉพาะ 4 หลักท้ายสำหรับแสดงแบบปิดบัง', last4 == id_abbot[-4:])
    dump = q(f"SELECT CONCAT_WS('|', title, first_name, last_name, dhamma_name, home_address, phone, last4) FROM (SELECT *, national_id_last4 AS last4 FROM temple_people) x WHERE id={pid_abbot}")
    check('P3c เลขบัตรเต็มไม่ปรากฏในแถวข้อมูลเลย (ทั้งแบบมี/ไม่มีขีด)', id_abbot not in dump and fmt(id_abbot) not in dump)

    check('P4 ชื่อเจ้าอาวาสถูกซิงก์เข้าตาราง temples', q(f"SELECT abbot FROM temples WHERE id={tid_a}") == 'พระครู สมชาย ใจดี')
    c, _, b = ta.req('/people.php')
    check('P5 หน้าบุคลากร: แสดงเลขบัตรแบบปิดบัง ไม่มีเลขเต็ม', 'X-XXXX-XXXX' in b and id_abbot not in b and fmt(id_abbot) not in b and 'พระครู สมชาย ใจดี' in b)
    check('P5b แบนเนอร์เตือนเรื่องเจ้าอาวาสหายไปหลังกรอกแล้ว', 'ยังไม่ได้กรอกข้อมูลเจ้าอาวาส' not in ta.req('/dashboard.php')[2])
    c, _, b = ta.req(f'/person.php?id={pid_abbot}')
    check('P5c หน้าแก้ไขก็ไม่แสดงเลขเต็ม (เป็น placeholder แบบปิดบัง)', c == 200 and id_abbot not in b and fmt(id_abbot) not in b and 'X-XXXX-XXXX' in b)

    n = add_person(ta, national_id=thai_id('110170099999'), first_name='คนที่สอง')
    check('P6 เจ้าอาวาสคนที่สองในวัดเดียวกันไม่ได้', 'มีเจ้าอาวาสอยู่แล้ว' in n and q(f"SELECT COUNT(*) FROM temple_people WHERE temple_id={tid_a} AND position='abbot'") == '1', n)
    n = add_person(ta, position='monk', first_name='ซ้ำ')
    check('P6b เลขบัตรซ้ำในวัดเดียวกัน (ต่างตำแหน่ง) ไม่ได้', 'เลขบัตรประชาชนนี้ในวัดนี้แล้ว' in n and q(f"SELECT COUNT(*) FROM temple_people WHERE temple_id={tid_a}") == '1', n)

    for i, fn in enumerate(['พระสมศักดิ์', 'พระสมหมาย', 'พระสมบัติ']):
        add_person(ta, position='monk', title='พระ', first_name=fn, last_name='มั่นคง', dhamma_name='สีลวโร', national_id=thai_id(f'31201000100{i}'), phone=f'08111100{i}0')
    for i, fn in enumerate(['นายใจบุญ', 'นายสุขใจ']):
        add_person(ta, position='steward', title='', first_name=fn, last_name='ศรีสุข', national_id=thai_id(f'51201000200{i}'), phone=f'08222200{i}0')
    check('P7 เพิ่มพระลูกวัดได้ 3 รูป และไวยาวัจกรได้ 2 ท่าน (ไม่จำกัดจำนวน)',
          q(f"SELECT COUNT(*) FROM temple_people WHERE temple_id={tid_a} AND position='monk'") == '3' and q(f"SELECT COUNT(*) FROM temple_people WHERE temple_id={tid_a} AND position='steward'") == '2')

    # รูปถ่าย
    pmedia = q(f"SELECT photo_media_id FROM temple_people WHERE id={pid_abbot}")
    check('P8 รูปถ่ายเก็บเป็น WebP ในฐานข้อมูล', q(f"SELECT CONCAT(kind,'/',mime) FROM media WHERE id={pmedia}") == 'photo/image/webp')
    code, hdr, body = ta.raw(f'/media.php?id={pmedia}')
    check('P8b แอดมินวัดเจ้าของข้อมูลเห็นรูปถ่าย (ไม่แคชแบบสาธารณะ)', code == 200 and (body[:4] == b'RIFF' and body[8:12] == b'WEBP') and 'no-cache' in (hdr.get('Cache-Control') or ''))
    acl = {
        'แอดมินหลัก': (adm, 200), 'แอดมินอำเภอเมืองแพร่ (อำเภอเดียวกับวัด)': (dm, 200), 'เจ้าคณะจังหวัด': (chief, 200),
        'แอดมินอำเภอเด่นชัย (คนละอำเภอ)': (dd, 403), 'แอดมินวัดอื่นในอำเภอเดียวกัน': (tb, 403), 'แอดมินวัดอื่นต่างอำเภอ': (tc, 403), 'ผู้ที่ยังไม่ล็อกอิน': (Client(base), 302),
    }
    for who, (cl, expect) in acl.items():
        check(f'P9 สิทธิ์ดูรูปถ่าย: {who} → {expect}', cl.raw(f'/media.php?id={pmedia}')[0] == expect)

    # สิทธิ์ดูรายการบุคลากร
    c, _, b = dm.req(f'/people.php?temple={tid_a}')
    check('P10 แอดมินอำเภอเดียวกันดูรายชื่อได้ (ชื่อ/เบอร์/เลขปิดบัง) แต่ไม่เห็นที่อยู่ และไม่มีปุ่มแก้ไข/เพิ่ม',
          c == 200 and 'พระครู สมชาย ใจดี' in b and '081-234-5678'.replace('-', '') in b and 'ที่อยู่ตามภูมิลำเนา' not in b and '99 หมู่ 3' not in b
          and 'person.php?id=' not in b and 'person.php?temple=' not in b and id_abbot not in b and 'ดูข้อมูลได้อย่างเดียว' in b)
    check('P10b แอดมินอำเภอเด่นชัยดูวัดในอำเภอเมืองแพร่ไม่ได้ (403)', dd.req(f'/people.php?temple={tid_a}')[0] == 403)
    c, _, b = chief.req(f'/people.php?temple={tid_a}')
    check('P10c เจ้าคณะจังหวัดดูได้อย่างเดียว (ไม่เห็นที่อยู่/เลขเต็ม/ปุ่มแก้ไข)', c == 200 and '99 หมู่ 3' not in b and id_abbot not in b and 'person.php?id=' not in b)
    c, _, b = tb.req(f'/people.php?temple={tid_a}')
    check('P10d แอดมินวัดอื่นใส่ temple=… ก็ยังเห็นเฉพาะวัดตนเอง', c == 200 and 'พระครู สมชาย ใจดี' not in b)
    check('P11 แอดมินวัดอื่นเปิดหน้าแก้ไขบุคคลของวัดนี้ไม่ได้ (403)', tb.req(f'/person.php?id={pid_abbot}')[0] == 403)
    check('P11b แอดมินอำเภอ/เจ้าคณะจังหวัดเปิดหน้าแก้ไข/เพิ่มบุคคลไม่ได้ (403)',
          dm.req(f'/person.php?id={pid_abbot}')[0] == 403 and chief.req(f'/person.php?id={pid_abbot}')[0] == 403 and dm.req(f'/person.php?temple={tid_a}')[0] == 403)
    # ดึง CSRF token จากหน้าที่มีฟอร์ม (หน้ารายชื่อของผู้ดูอย่างเดียวไม่มีฟอร์ม) เหมือนผู้ใช้ที่ล็อกอินอยู่ส่งคำสั่งตรง ๆ
    c, _, _ = dm.post('/people.php?temple=' + tid_a, {'action': 'delete', 'id': pid_abbot}, '/account.php')
    check('P11c แอดมินอำเภอลบข้อมูลบุคคลไม่ได้ (403) ข้อมูลยังอยู่', c == 403 and q(f"SELECT COUNT(*) FROM temple_people WHERE id={pid_abbot}") == '1', f'status={c} pid={pid_abbot!r}')
    c, _, b = adm.req(f'/people.php?temple={tid_a}')
    check('P11d แอดมินหลักแก้ไขได้ (เห็นปุ่มแก้ไข + ที่อยู่) แต่เลขบัตรยังปิดบัง', 'person.php?id=' in b and '99 หมู่ 3' in b and id_abbot not in b)

    # แก้ไข / ดูเลขเต็ม
    idx_before = idx
    ta.upload('/person.php', person_form(id=pid_abbot, national_id='', phone='0899999999'), None, b'', f'/person.php?id={pid_abbot}', 'photo')
    row = q(f"SELECT national_id_idx, phone FROM temple_people WHERE id={pid_abbot}").split('\t')
    check('P12 แก้ไขโดยเว้นเลขบัตรว่าง → คงเลขเดิม และแก้เบอร์โทรได้', row[0] == idx_before and row[1] == '0899999999')
    new_id = thai_id('110170088888')
    ta.upload('/person.php', person_form(id=pid_abbot, national_id=new_id), None, b'', f'/person.php?id={pid_abbot}', 'photo')
    row = q(f"SELECT national_id_idx, national_id_last4 FROM temple_people WHERE id={pid_abbot}").split('\t')
    check('P12b เปลี่ยนเลขบัตร → ค่าแฮชและ 4 หลักท้ายเปลี่ยนตาม', row[0] != idx_before and row[1] == new_id[-4:])
    ta.upload('/person.php', person_form(id=pid_abbot, national_id=id_abbot), None, b'', f'/person.php?id={pid_abbot}', 'photo')

    c, _, b = ta.post('/person.php', {'action': 'reveal', 'id': pid_abbot, 'temple': tid_a}, f'/person.php?id={pid_abbot}')
    check('P13 แอดมินวัดกด "แสดงเลขบัตรเต็ม" เห็นเลขเต็ม', c == 200 and fmt(id_abbot) in b, notice(b))
    check('P13b การดูเลขเต็มถูกบันทึกประวัติ (ระบุผู้ดูและรหัสบุคคล)',
          q(f"SELECT COUNT(*) FROM audit_log WHERE action='reveal_national_id' AND entity='person' AND entity_id={pid_abbot} AND user_id=(SELECT id FROM users WHERE temple_id={tid_a})") == '1')
    check('P13c ประวัติการใช้งานทั้งตารางไม่มีเลขบัตรเลย',
          q(f"SELECT COUNT(*) FROM audit_log WHERE detail LIKE '%{id_abbot}%' OR detail LIKE '%{fmt(id_abbot)}%'") == '0')
    c, _, b = adm.post('/person.php', {'action': 'reveal', 'id': pid_abbot}, f'/person.php?id={pid_abbot}')
    check('P13d แอดมินหลักดูเลขเต็มได้ (และถูกบันทึกประวัติ)', fmt(id_abbot) in b and q("SELECT COUNT(*) FROM audit_log WHERE action='reveal_national_id'") == '2')
    c, _, b = dm.post('/person.php', {'action': 'reveal', 'id': pid_abbot}, '/dashboard.php')
    check('P13e แอดมินอำเภอสั่งดูเลขเต็มไม่ได้ (403) และไม่มีประวัติเพิ่ม', c == 403 and fmt(id_abbot) not in b and q("SELECT COUNT(*) FROM audit_log WHERE action='reveal_national_id'") == '2')

    # สถานะ/ลบ
    ta.post('/people.php', {'action': 'toggle', 'id': pid_abbot}, '/people.php')
    check('P14 เปลี่ยนเจ้าอาวาสเป็น "พ้นตำแหน่ง" → ชื่อเจ้าอาวาสในทะเบียนว่าง', q(f"SELECT is_active FROM temple_people WHERE id={pid_abbot}") == '0' and q(f"SELECT IFNULL(abbot,'') FROM temples WHERE id={tid_a}") == '')
    n = add_person(ta, national_id=thai_id('110170077777'), first_name='เจ้าอาวาสรูปใหม่', last_name='ศรัทธา')
    pid_new = q(f"SELECT id FROM temple_people WHERE first_name='เจ้าอาวาสรูปใหม่'")
    check('P14b มีเจ้าอาวาสรูปใหม่ได้เมื่อรูปเดิมพ้นตำแหน่งแล้ว', pid_new != '', n)
    ta.post('/people.php', {'action': 'toggle', 'id': pid_abbot}, '/people.php')
    check('P14c ให้รูปเดิมกลับเข้าตำแหน่งเจ้าอาวาสไม่ได้ขณะที่มีรูปใหม่อยู่', q(f"SELECT is_active FROM temple_people WHERE id={pid_abbot}") == '0')

    xss = '<script>alert(1)</script>'
    add_person(ta, position='steward', first_name=xss, last_name='ทดสอบ', national_id=thai_id('510000000001'), title='')
    c, _, b = ta.req('/people.php')
    check('P15 ชื่อที่มีสคริปต์ถูก escape (ไม่เกิด XSS)', xss not in b and '&lt;script&gt;' in b)

    n_media = int(q("SELECT COUNT(*) FROM media"))
    ta.post('/people.php', {'action': 'delete', 'id': pid_abbot}, '/people.php')
    check('P16 ลบบุคคลถาวร: ลบแถวข้อมูลและรูปถ่ายในฐานข้อมูลด้วย',
          q(f"SELECT COUNT(*) FROM temple_people WHERE id={pid_abbot}") == '0' and int(q("SELECT COUNT(*) FROM media")) == n_media - 1 and ta.raw(f'/media.php?id={pmedia}')[0] == 404)
    check('P16b ประวัติการลบบันทึกไว้โดยไม่มีข้อมูลส่วนบุคคล', q(f"SELECT COUNT(*) FROM audit_log WHERE action='delete' AND entity='person' AND entity_id={pid_abbot}") == '1')
    c, _, b = ta.req('/reports.php')
    check('P17 บล็อกลงนามในรายงานดึงชื่อเจ้าอาวาสปัจจุบันและไวยาวัจกรจากข้อมูลบุคลากร', 'พระ' in b and 'เจ้าอาวาสรูปใหม่' in b and 'นายใจบุญ' in b)

    # ผู้ใช้ที่ไม่ใช่แอดมินวัดต้องไม่สร้างข้อมูลได้
    n_people = q("SELECT COUNT(*) FROM temple_people")
    for who, cl in [('เจ้าคณะจังหวัด', chief), ('แอดมินอำเภอ', dm)]:
        c, _, _ = cl.upload('/person.php', person_form(national_id=thai_id('110170066666')), None, b'', '/dashboard.php', 'photo')
        check(f'P18 {who}สร้างบุคลากรผ่านการส่งฟอร์มตรง ๆ ไม่ได้ (403)', c == 403 and q("SELECT COUNT(*) FROM temple_people") == n_people)
