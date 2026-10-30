"""ตัวช่วยของชุดทดสอบ (ใช้เฉพาะไลบรารีมาตรฐานของ Python)

- Results   : นับผ่าน/ไม่ผ่าน
- Db        : รัน SQL ผ่าน PHP (tests/helpers/sql.php) สร้าง/ลบฐานข้อมูลชั่วคราว
- FakeSMTP  : เซิร์ฟเวอร์ SMTP ปลอมในโปรเซส เก็บอีเมลที่ระบบส่ง
- App       : คัดลอกโปรเจกต์ไปโฟลเดอร์ชั่วคราว แล้วรัน php -S จากสำเนา (ไม่แตะไฟล์จริง)
- Client    : HTTP client มีคุกกี้ + ช่วยดึง CSRF token
"""
import base64
import csv
import email
import email.policy
import http.cookiejar
import io
import json
import os
import re
import shutil
import socket
import socketserver
import subprocess
import sys
import tempfile
import threading
import time
import urllib.error
import urllib.parse
import urllib.request
import uuid
from pathlib import Path

ROOT = Path(__file__).resolve().parent.parent
HELPER_SQL = ROOT / 'tests' / 'helpers' / 'sql.php'


# ---------- ผลการทดสอบ ----------
class Results:
    def __init__(self):
        self.ok = 0
        self.fail = 0
        self.failures = []

    def check(self, name, cond, extra=''):
        if cond:
            self.ok += 1
            print('PASS', name)
        else:
            self.fail += 1
            self.failures.append(name)
            print('FAIL', name, str(extra)[:300])

    def summary(self, title):
        print(f'\n=== {title}: {self.ok} ผ่าน, {self.fail} ไม่ผ่าน ===')
        return self.fail == 0


# ---------- ฐานข้อมูล ----------
class DbUnavailable(Exception):
    pass


class Db:
    """เชื่อมต่อด้วยบัญชีที่สร้าง/ลบฐานข้อมูลได้ (TFMS_TEST_DB_HOST/PORT/USER/PASS)"""

    def __init__(self):
        self.host = os.environ.get('TFMS_TEST_DB_HOST', '127.0.0.1')
        self.port = os.environ.get('TFMS_TEST_DB_PORT', '3306')
        self.user = os.environ.get('TFMS_TEST_DB_USER', 'root')
        self.password = os.environ.get('TFMS_TEST_DB_PASS', '')
        self.created = []

    def run(self, sql, db=''):
        env = dict(os.environ, TFMS_TEST_DB_HOST=self.host, TFMS_TEST_DB_PORT=self.port,
                   TFMS_TEST_DB_USER=self.user, TFMS_TEST_DB_PASS=self.password)
        p = subprocess.run(['php', str(HELPER_SQL), sql, db], capture_output=True, text=True, env=env)
        if p.returncode != 0:
            raise RuntimeError(p.stderr.strip() or 'sql failed')
        return p.stdout.strip()

    def ping(self):
        try:
            self.run('SELECT 1')
        except Exception as e:
            raise DbUnavailable(
                f'เชื่อมต่อฐานข้อมูลไม่ได้ ({self.user}@{self.host}:{self.port}): {e}\n'
                'ตั้งค่า TFMS_TEST_DB_HOST / TFMS_TEST_DB_PORT / TFMS_TEST_DB_USER / TFMS_TEST_DB_PASS '
                'ให้เป็นบัญชีที่สร้างและลบฐานข้อมูลได้')

    def new_database(self, label, create=True):
        name = f'tfms_test_{uuid.uuid4().hex[:8]}_{label}'
        if create:
            self.run(f'CREATE DATABASE `{name}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci')
        self.created.append(name)
        return name

    def q(self, db):
        """คืนฟังก์ชัน q(sql) ที่รันบนฐานข้อมูล db"""
        return lambda sql: self.run(sql, db)

    def cleanup(self):
        for name in self.created:
            try:
                self.run(f'DROP DATABASE IF EXISTS `{name}`')
            except Exception:
                pass
        self.created = []


# ---------- SMTP ปลอม ----------
class _SmtpHandler(socketserver.StreamRequestHandler):
    def w(self, s):
        self.wfile.write((s + '\r\n').encode())
        self.wfile.flush()

    def handle(self):
        self.w('220 fake ESMTP')
        msg = {'auth': [], 'rcpt': []}
        while True:
            line = self.rfile.readline()
            if not line:
                return
            c = line.decode(errors='replace').strip()
            u = c.upper()
            if u.startswith('EHLO'):
                self.w('250-fake\r\n250 AUTH LOGIN')
            elif u == 'AUTH LOGIN':
                self.w('334 VXNlcm5hbWU6')
                msg['auth'].append(base64.b64decode(self.rfile.readline().strip()).decode())
                self.w('334 UGFzc3dvcmQ6')
                msg['auth'].append(base64.b64decode(self.rfile.readline().strip()).decode())
                self.w('235 ok')
            elif u.startswith('MAIL FROM'):
                msg['from'] = c[10:]
                self.w('250 ok')
            elif u.startswith('RCPT TO'):
                msg['rcpt'].append(c[8:])
                self.w('250 ok')
            elif u == 'DATA':
                self.w('354 go')
                buf = b''
                while True:
                    d = self.rfile.readline()
                    if d == b'.\r\n' or not d:
                        break
                    buf += d
                m = email.message_from_bytes(buf, policy=email.policy.default)
                msg['subject'] = str(m['Subject'])
                msg['to'] = str(m['To'])
                msg['body'] = m.get_content()
                self.server.messages.append(msg)
                self.w('250 queued')
            elif u == 'QUIT':
                self.w('221 bye')
                return
            else:
                self.w('250 ok')


class FakeSMTP:
    def __init__(self):
        socketserver.ThreadingTCPServer.allow_reuse_address = True
        self.server = socketserver.ThreadingTCPServer(('127.0.0.1', 0), _SmtpHandler)
        self.server.daemon_threads = True
        self.server.messages = []
        self.port = self.server.server_address[1]
        threading.Thread(target=self.server.serve_forever, daemon=True).start()

    @property
    def messages(self):
        return self.server.messages

    def last_code(self):
        """รหัส 2FA 6 หลักจากอีเมลล่าสุด"""
        return re.search(r'รหัสของคุณคือ: (\d{6,8})', self.messages[-1]['body']).group(1)

    def stop(self):
        self.server.shutdown()
        self.server.server_close()


def free_port():
    with socket.socket() as s:
        s.bind(('127.0.0.1', 0))
        return s.getsockname()[1]


# ---------- แอป (สำเนาชั่วคราว + php -S) ----------
_IGNORED = {'.git', 'node_modules', 'tests', 'config.local.php', 'installed.lock', 'mail.log', 'app.key'}


def _ignore(_dir, names):
    return [n for n in names if n in _IGNORED or n.startswith('temple_accounts_')]


class App:
    def __init__(self, env=None):
        self.env = env or {}
        self.dir = Path(tempfile.mkdtemp(prefix='tfms_app_'))
        shutil.copytree(ROOT, self.dir, dirs_exist_ok=True, ignore=_ignore)
        (self.dir / 'storage').mkdir(exist_ok=True)
        self.servers = []
        self.logs = []

    def child_env(self, extra=None):
        base = {k: v for k, v in os.environ.items() if not k.startswith('TFMS_')}
        base['TFMS_STAFF_2FA'] = '0'   # ชุดทดสอบทั่วไปล็อกอินแอดมินด้วยรหัสผ่านอย่างเดียว; ชุด auth เปิดเป็น '1'
        base.update(self.env)
        base.update(extra or {})
        return base

    def php(self, *args, extra_env=None):
        return subprocess.run(['php', *args], cwd=self.dir, capture_output=True, text=True, env=self.child_env(extra_env))

    def serve(self, extra_env=None):
        port = free_port()
        log = tempfile.NamedTemporaryFile('w+', prefix='tfms_php_', suffix='.log', delete=False)
        proc = subprocess.Popen(['php', '-S', f'127.0.0.1:{port}', '-t', str(self.dir)], cwd=self.dir,
                                env=self.child_env(extra_env), stdout=log, stderr=log)
        self.servers.append(proc)
        self.logs.append(log.name)
        for _ in range(100):
            try:
                socket.create_connection(('127.0.0.1', port), timeout=0.2).close()
                return f'http://127.0.0.1:{port}'
            except OSError:
                time.sleep(0.05)
        raise RuntimeError('php -S ไม่พร้อมใช้งาน')

    def php_problems(self):
        """บรรทัด Warning/Notice/Fatal/Deprecated ใน log ของ php -S"""
        out = []
        for f in self.logs:
            for line in Path(f).read_text(errors='replace').splitlines():
                if re.search(r'PHP (Warning|Notice|Fatal error|Deprecated|Parse error)', line):
                    out.append(line)
        return out

    def close(self):
        for p in self.servers:
            p.terminate()
            try:
                p.wait(timeout=5)
            except subprocess.TimeoutExpired:
                p.kill()
        for f in self.logs:
            try:
                os.unlink(f)
            except OSError:
                pass
        shutil.rmtree(self.dir, ignore_errors=True)


# ---------- HTTP client ----------
class _NoRedirect(urllib.request.HTTPRedirectHandler):
    def redirect_request(self, *a, **k):
        return None


class Client:
    def __init__(self, base):
        self.base = base
        self.cj = http.cookiejar.CookieJar()
        self.opener = urllib.request.build_opener(urllib.request.HTTPCookieProcessor(self.cj), _NoRedirect)
        self.headers = {}

    def req(self, path, data=None, raw=None, headers=None):
        body = urllib.parse.urlencode(data).encode() if data is not None else raw
        r = urllib.request.Request(self.base + path, body, headers or {})
        try:
            resp = self.opener.open(r)
            code, loc, text, hdr = resp.status, None, resp.read().decode(), resp.headers
        except urllib.error.HTTPError as e:
            code, loc, text, hdr = e.code, e.headers.get('Location'), e.read().decode(), e.headers
        self.headers = hdr
        return code, loc, text

    def csrf(self, path):
        _, _, b = self.req(path)
        m = re.search(r'name="csrf" value="([a-f0-9]+)"', b)
        return m.group(1) if m else None

    def post(self, path, data, page=None):
        """ดึง CSRF token จากหน้า page (ค่าเริ่มต้น = path) แล้ว POST"""
        data = dict(data)
        data['csrf'] = self.csrf(page or path)
        return self.req(path, data)

    def upload(self, path, fields, filename, content, page=None, field='csv', content_type='text/csv'):
        """POST แบบ multipart พร้อมไฟล์ 1 ไฟล์ (ชื่อฟิลด์ไฟล์เริ่มต้น 'csv'; รูป: field='logo'/'photo')
        filename=None = ไม่แนบไฟล์ (ส่งเฉพาะฟิลด์ข้อความ)"""
        fields = dict(fields)
        fields['csrf'] = self.csrf(page or path)
        bd = uuid.uuid4().hex
        body = b''
        for k, v in fields.items():
            body += f'--{bd}\r\nContent-Disposition: form-data; name="{k}"\r\n\r\n{v}\r\n'.encode()
        if filename is not None:
            body += (f'--{bd}\r\nContent-Disposition: form-data; name="{field}"; filename="{filename}"\r\n'
                     f'Content-Type: {content_type}\r\n\r\n').encode() + content + b'\r\n'
        body += f'--{bd}--\r\n'.encode()
        return self.req(path, raw=body, headers={'Content-Type': 'multipart/form-data; boundary=' + bd})

    def raw(self, path):
        """GET แบบไบนารี (รูป) -> (รหัส, headers, bytes)"""
        try:
            resp = self.opener.open(urllib.request.Request(self.base + path))
            return resp.status, resp.headers, resp.read()
        except urllib.error.HTTPError as e:
            return e.code, e.headers, e.read()


# ---------- ตัวช่วยอื่น ----------
def notice(html):
    """ข้อความแจ้งเตือน (flash/notice) บนหน้า"""
    m = re.search(r'<div class="mb-4 rounded-lg[^>]*>(.*?)</div>', html, re.S)
    return re.sub(r'<[^>]+>', '', m.group(1)) if m else ''


def parse_creds_csv(text):
    """CSV รหัสผ่านเริ่มต้น -> [(รหัสวัด, อำเภอ, วัด, ชื่อผู้ใช้, รหัสผ่าน)]"""
    return [tuple(r[:5]) for r in list(csv.reader(io.StringIO(text.lstrip('﻿'))))[1:]]


def age_otps(q, where='1=1'):
    """ทำให้รหัส 2FA เก่าเกินช่วงรอ (cooldown 60 วินาที) เพื่อขอรหัสใหม่ได้ทันทีในการทดสอบ"""
    q(f'UPDATE login_otps SET created_at = created_at - INTERVAL 120 SECOND WHERE {where}')


def hash_password(app, password):
    return app.php('-r', f'echo password_hash({json.dumps(password)}, PASSWORD_DEFAULT);').stdout.strip()


# ---------- ข้อมูลทดสอบ ----------
def thai_id(prefix12):
    """เลขบัตรประชาชน 13 หลักที่หลักตรวจสอบถูกต้อง จาก 12 หลักแรก"""
    total = sum(int(d) * (13 - i) for i, d in enumerate(prefix12))
    return prefix12 + str((11 - total % 11) % 10)


def png_bytes(w, h, rgba=(200, 30, 30, 255), extra=b'', noise=False):
    """สร้าง PNG ถูกต้องด้วย stdlib (noise=True ให้ไฟล์บีบอัดไม่ลง ใช้ทดสอบขนาดใหญ่)"""
    import struct
    import zlib
    row = (lambda: b'\x00' + os.urandom(w * 4)) if noise else (lambda: b'\x00' + bytes(rgba) * w)
    raw = b''.join(row() for _ in range(h))

    def chunk(t, d):
        return struct.pack('>I', len(d)) + t + d + struct.pack('>I', zlib.crc32(t + d) & 0xffffffff)

    return (b'\x89PNG\r\n\x1a\n' + chunk(b'IHDR', struct.pack('>IIBBBBB', w, h, 8, 6, 0, 0, 0))
            + chunk(b'IDAT', zlib.compress(raw)) + chunk(b'IEND', b'') + extra)


def jpeg_bytes(app, w=640, h=800):
    """สร้าง JPEG ด้วย GD ของ PHP (stdlib ของ Python ไม่มีตัวเข้ารหัส JPEG)"""
    code = (f'$im=imagecreatetruecolor({w},{h});imagefill($im,0,0,imagecolorallocate($im,30,90,160));'
            'ob_start();imagejpeg($im,null,90);echo base64_encode(ob_get_clean());')
    return base64.b64decode(app.php('-r', code).stdout)
