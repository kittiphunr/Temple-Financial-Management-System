"""ชุดที่ 19: จัดการขนาดรูปภาพและแปลงเป็น WebP — โปรไฟล์ขนาดของโลโก้/รูปถ่าย/หลักฐาน และเครื่องมือแปลงรูปเดิม (convert_images.php)"""
import base64
import subprocess

from support import App, Client, jpeg_bytes, png_bytes


def run(db, R):
    name = db.new_database('images')
    q = db.q(name)
    app = App(env={'TFMS_DB_HOST': db.host, 'TFMS_DB_PORT': db.port, 'TFMS_DB_USER': db.user, 'TFMS_DB_PASS': db.password, 'TFMS_DB_NAME': name,
                   'TFMS_STAFF_2FA': '0'})
    try:
        _run(R, q, app)
    finally:
        problems = app.php_problems()
        R.check('Z1 ไม่มี PHP warning/notice/fatal ใน log ของเซิร์ฟเวอร์', not problems, '\n'.join(problems[:3]))
        app.close()


def _dims(blob):
    out = subprocess.run(['php', '-r', '$i=getimagesizefromstring(base64_decode($argv[1]));echo $i[0]."x".$i[1]."|".$i["mime"];', base64.b64encode(blob).decode()], capture_output=True, text=True).stdout
    return out


def _run(R, q, app):
    check = R.check
    app.php('setup.php', 'Test12345pw')
    base = app.serve()
    adm = Client(base)
    adm.post('/login.php', {'username': 'admin', 'password': 'Test12345pw'})
    dist = q("SELECT id FROM districts ORDER BY id LIMIT 1")

    def logo(content, ct='image/png'):
        adm.upload('/admin/districts.php', {'action': 'logo_upload', 'district_id': dist}, 'l.png', content, '/admin/districts.php', 'logo', ct)
        return q(f"SELECT logo_media_id FROM districts WHERE id={dist}")

    # โลโก้ใหญ่ -> ย่อไม่เกิน 512x512 เก็บเป็น WebP คงความโปร่งใส
    lid = logo(png_bytes(1000, 800, (10, 120, 60, 0)))
    row = q(f"SELECT CONCAT(kind,'|',mime) FROM media WHERE id={lid}")
    _, _, body = adm.raw(f'/media.php?id={lid}')
    d = _dims(body)
    check('I1 โลโก้ 1000x800 → WebP ย่อเป็น 512x410 (คงสัดส่วน ไม่เกิน 512)', row == 'logo|image/webp' and d.startswith('512x410|'), f'{row} {d}')
    alpha = subprocess.run(['php', '-r', '$im=imagecreatefromstring(base64_decode($argv[1]));$c=imagecolorat($im,2,2);echo ($c>>24)&127;', base64.b64encode(body).decode()], capture_output=True, text=True).stdout.strip()
    check('I2 โลโก้พื้นโปร่งใสยังโปร่งใสหลังแปลงเป็น WebP', alpha == '127', alpha)
    lid = logo(png_bytes(100, 100, (200, 0, 0, 255)))
    _, _, body = adm.raw(f'/media.php?id={lid}')
    check('I3 โลโก้เล็กไม่ถูกขยาย (100x100 คงเดิม)', _dims(body).startswith('100x100'), _dims(body))

    # รับ WebP เป็นอินพุตได้
    webp_in = base64.b64decode(subprocess.run(['php', '-r', '$im=imagecreatetruecolor(300,300);ob_start();imagewebp($im);echo base64_encode(ob_get_clean());'], capture_output=True, text=True).stdout)
    lid = logo(webp_in, 'image/webp')
    check('I4 รับไฟล์ WebP เป็นอินพุตและเก็บเป็น WebP', lid != '' and q(f"SELECT mime FROM media WHERE id={lid}") == 'image/webp')

    # ตัวเครื่องมือแปลงรูปเดิม
    jpg = jpeg_bytes(app, 1200, 1600)
    b64 = base64.b64encode(jpg).decode()
    q(f"INSERT INTO media (kind, mime, size, data) VALUES ('photo','image/jpeg',{len(jpg)},FROM_BASE64('{b64}'))")
    old = q("SELECT MAX(id) FROM media")
    r = app.php('convert_images.php')
    check('I5 ทดลองรัน (ไม่มี --apply) ไม่แก้ข้อมูล และรายงานจำนวนรูป', q(f"SELECT mime FROM media WHERE id={old}") == 'image/jpeg' and 'ทดลองรัน' in r.stdout, r.stdout + r.stderr)
    r = app.php('convert_images.php', '--apply')
    sz = q(f"SELECT CONCAT(mime,'|',size) FROM media WHERE id={old}")
    body = base64.b64decode(q(f"SELECT REPLACE(TO_BASE64(data), '\\n', '') FROM media WHERE id={old}"))
    check('I6 --apply แปลงรูป JPEG เดิมเป็น WebP ที่เล็กลง ย่อตามโปรไฟล์ (600x800)', sz.startswith('image/webp|') and int(sz.split('|')[1]) < len(jpg) and _dims(body).startswith('600x800'), f'{sz} {_dims(body)} {r.stdout}')
    r = app.php('convert_images.php', '--apply')
    check('I7 รันซ้ำไม่มีรูปให้แปลงเพิ่ม (0 รูป)', ': 0 รูป' in r.stdout, r.stdout)
