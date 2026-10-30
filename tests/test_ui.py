"""ชุดที่ 6: การแจ้งเตือนด้วย SweetAlert2 (แถบข้อความ/กล่องยืนยัน) และหน้าต่าง "เกี่ยวกับ"
ทดสอบฝั่งเซิร์ฟเวอร์ (HTML ที่ส่งออก + ไฟล์ที่ต้องโหลด) — พฤติกรรมในเบราว์เซอร์ตรวจด้วยตา"""
import glob
import re
from pathlib import Path
from datetime import date

from support import App, Client, be_date, notice

ROOT = Path(__file__).resolve().parent.parent


def run(db, R):
    name = db.new_database('ui')
    q = db.q(name)
    app = App(env={'TFMS_DB_HOST': db.host, 'TFMS_DB_PORT': db.port, 'TFMS_DB_USER': db.user, 'TFMS_DB_PASS': db.password,
                   'TFMS_DB_NAME': name, 'TFMS_MAIL_DRIVER': 'log'})
    try:
        _run(R, q, app)
    finally:
        problems = app.php_problems()
        R.check('Z1 ไม่มี PHP warning/notice/fatal ใน log ของเซิร์ฟเวอร์', not problems, '\n'.join(problems[:3]))
        app.close()


def _run(R, q, app):
    check = R.check
    app.php('setup.php', 'Test12345pw', '--no-staff')
    base = app.serve()
    g = Client(base)
    _, _, b = g.req('/login.php')
    check('U1 ทุกหน้าโหลด SweetAlert2 และสคริปต์แจ้งเตือนจากเซิร์ฟเวอร์เอง (ไม่พึ่ง CDN)',
          'assets/vendor/sweetalert2.all.min.js' in b and 'assets/js/app.js' in b and 'cdn' not in b.lower())
    for path, ctype in (('/assets/vendor/sweetalert2.all.min.js', b'sweetalert2'), ('/assets/js/app.js', b'Swal'), ('/assets/img/developer.jpg', b''), ('/assets/img/logo.svg', b'<svg')):
        c, h, body = g.raw(path)
        check(f'U2 ไฟล์ {path} เปิดได้', c == 200 and len(body) > 1000 and ctype in body, str(c))
    g.post('/login.php', {'username': 'admin', 'password': 'wrong-password'})
    _, _, b = g.req('/login.php')
    c, _, b = g.post('/login.php', {'username': 'admin', 'password': 'wrong-password'}, '/login.php')
    check('U3 ข้อผิดพลาดตอนเข้าสู่ระบบแสดงผ่าน SweetAlert2 (data-swal="err") และยังอ่านข้อความได้เมื่อไม่มี JS',
          'data-swal="err"' in b and 'ไม่ถูกต้อง' in notice(b))
    adm = Client(base)
    adm.post('/login.php', {'username': 'admin', 'password': 'Test12345pw'})
    adm.post('/transactions.php?type=income', {'action': 'save', 'id': '0', 'temple_id': '1', **be_date('txn_date', date(2026, 1, 5)), 'category_id': '1',
                                                'amount': '100', 'doc_no': '', 'description': 'x'}, '/transactions.php?type=income')
    _, _, b = adm.req('/transactions.php?type=income')
    check('U4 ข้อความสำเร็จ (flash) แสดงเป็น data-swal="ok" ในหน้าถัดไป', 'data-swal="ok"' in b and 'บันทึก' in notice(b))
    check('U5 ปุ่มลบใช้ data-confirm (กล่องยืนยันของ SweetAlert2) ไม่ใช้ confirm() ของเบราว์เซอร์', 'data-confirm="ยืนยันการลบรายการนี้?"' in b and 'confirm(' not in b)
    _, _, b = adm.req('/transactions.php?type=income')
    check('U6 ข้อความ flash แสดงครั้งเดียว (รีเฟรชแล้วไม่ซ้ำ)', 'data-swal="ok"' not in b)

    # ห้ามเหลือ confirm() หรือ alert() ดิบของเบราว์เซอร์ในซอร์ส
    bad = []
    for f in glob.glob(str(app.dir / '*.php')) + glob.glob(str(app.dir / 'admin' / '*.php')) + glob.glob(str(app.dir / 'includes' / '*.php')):
        s = open(f, encoding='utf-8').read()
        if re.search(r'\bconfirm\(|\balert\(', s):
            bad.append(f.split('/')[-1])
    check('U7 ไม่มี confirm()/alert() ดิบเหลือในโค้ดหน้าเว็บ', not bad, ','.join(bad))

    # หน้าต่างเกี่ยวกับ
    check('U8 ท้ายหน้ามีปุ่ม "เกี่ยวกับระบบ · ผู้พัฒนา" และ template ของหน้าต่าง', 'data-about' in b and 'id="about-template"' in b)
    tpl = re.search(r'<template id="about-template">(.*?)</template>', b, re.S).group(1)
    check('U9 หน้าต่างเกี่ยวกับมีผู้พัฒนา อีเมล โทร GitHub เว็บไซต์ และคำอธิบายเว็บไซต์',
          all(x in tpl for x in ('ผู้พัฒนา', 'Kittiphun Ruttanakorn', 'mailto:kittiphun.rut@mcu.ac.th', 'tel:0899554194', 'github.com/kittiphunr', 'kittiphunr.github.io', 'เกี่ยวกับเว็บไซต์')))
    check('U9b หัวหน้าต่างเป็นชื่อระบบและตราคณะสงฆ์จังหวัดแพร่ (ไม่ใช่ชื่อหน่วยงานของผู้พัฒนา)', 'เกี่ยวกับ ระบบบัญชีรายรับ-รายจ่ายวัด' in tpl and 'assets/img/logo.svg' in tpl and 'เกี่ยวกับ มหาวิทยาลัย' not in tpl)
    check('U10 ลิงก์ภายนอกเปิดแท็บใหม่แบบปลอดภัย (noopener noreferrer)', len(re.findall(r'target="_blank" rel="noopener noreferrer"', tpl)) >= 3 and len(re.findall(r'target="_blank"', tpl)) == len(re.findall(r'target="_blank" rel="noopener noreferrer"', tpl)))
    check('U10c หน้าต่างเกี่ยวกับแสดงเครดิตส่วนประกอบที่ใช้ (SweetAlert2, thai-province-data ฯลฯ)', 'เครดิตส่วนประกอบที่ใช้' in tpl and 'thai-province-data' in tpl and 'SweetAlert2' in tpl)
    check('U10b หน้าต่างเกี่ยวกับระบุฟอนต์ JS Chanok + GPL v2 และลิงก์ไฟล์สัญญาอนุญาต',
          'JS Chanok' in tpl and 'GPL v2' in tpl and 'assets/fonts/GPL-License-JS.pdf' in tpl and Client(base).raw('/assets/fonts/GPL-License-JS.pdf')[0] == 200)
    check('U11 หน้าสาธารณะ (ก่อนเข้าสู่ระบบ) ก็มีหน้าต่างเกี่ยวกับ', 'id="about-template"' in Client(base).req('/')[2] or 'id="about-template"' in Client(base).req('/login.php')[2])

    ver = (app.dir / 'VERSION').read_text().strip()
    check('U12 หน้าต่างเกี่ยวกับแสดงเวอร์ชันจากไฟล์ VERSION (รันจากซอร์ส = dev)', re.fullmatch(r'\d+\.\d+\.\d+', ver) is not None and f'เวอร์ชัน {ver} (dev)' in tpl, ver)
    (app.dir / 'version.json').write_text('{"version":"%s","build":123,"commit":"abc1234","built_at":"2026-10-30"}' % ver)
    _, _, b2 = Client(base).req('/login.php')
    check('U13 มี version.json จากการบิวล์ → แสดงหมายเลขบิวล์และคอมมิต', f'เวอร์ชัน {ver} (บิวล์ 123 · abc1234)' in b2 and 'สร้างเมื่อ 2026-10-30' in b2)
    (app.dir / 'version.json').write_text('{"version":"<script>","build":"x","commit":"<b>"}')
    _, _, b3 = Client(base).req('/login.php')
    check('U14 version.json ผิดรูปแบบ/แฝงโค้ดถูกเมินเฉย (ใช้ค่าจาก VERSION)', f'เวอร์ชัน {ver}' in b3 and '<script>' not in re.search(r'<template id="about-template">(.*?)</template>', b3, re.S).group(1))
    (app.dir / 'version.json').unlink()

    # ================= เมนูและไอคอน (Feather Icons) =================
    sprite = (ROOT / 'assets' / 'vendor' / 'feather-sprite.svg').read_text(encoding='utf-8')
    have = set(re.findall(r'<symbol id="([a-z0-9-]+)"', sprite))
    used = set()
    for f in list(ROOT.glob('*.php')) + list(ROOT.glob('admin/*.php')) + list(ROOT.glob('includes/*.php')):
        used |= set(re.findall(r"icon\('([a-z0-9-]+)'", f.read_text(encoding='utf-8')))
    used |= set(re.findall(r"nav_(?:link|group)\([^)]*?'([a-z0-9-]+)'(?:, [^)]*)?\)", (ROOT / 'includes' / 'header.php').read_text(encoding='utf-8')))
    check('U15 ไอคอนทุกชื่อที่เรียกใช้ในโค้ดมีอยู่จริงใน Feather sprite', used and used <= have, str(sorted(used - have)))
    check('U16 ไฟล์ sprite และสัญญาอนุญาต MIT ของ Feather ถูกส่งออกจากเซิร์ฟเวอร์ได้ และมีเครดิต Cole Bemis',
          g.raw('/assets/vendor/feather-sprite.svg')[0] == 200 and b'Cole Bemis' in g.raw('/assets/vendor/feather.LICENSE')[2] and 'Cole Bemis' in tpl)
    ad = Client(base)
    ad.post('/login.php', {'username': 'admin', 'password': 'Test12345pw'})
    _, _, bd = ad.req('/dashboard.php')
    desk = re.search(r'<nav class="hidden[^>]*>(.*?)</nav>', bd, re.S).group(1)
    mob = re.search(r'<details class="nav-mobile.*?<nav[^>]*>(.*?)</nav>', bd, re.S).group(1)
    groups = re.findall(r'<details class="nav-group[^>]*><summary[^>]*>.*?<span>(.*?)</span>', desk, re.S)
    check('U17 เมนูแอดมินหลักแบ่งเป็นกลุ่ม dropdown (บันทึกบัญชี/การเงิน/รายงาน/ทะเบียน) พร้อมลิงก์เดี่ยว แดชบอร์ด และ จัดการระบบ', groups == ['บันทึกบัญชี', 'การเงิน', 'รายงาน', 'ทะเบียน'] and 'แดชบอร์ด' in desk and 'จัดการระบบ' in desk, str(groups))
    hrefs_d = re.findall(r'<a class="[^"]*" href="([^"]+)"', desk)
    check('U18 เมนูแถบแนวนอนไม่มีลิงก์ซ้ำ และเมนูมือถือมีลิงก์ชุดเดียวกัน (แยกเป็นหัวกลุ่ม)', len(hrefs_d) == len(set(hrefs_d)) and sorted(re.findall(r'<a class="[^"]*" href="([^"]+)"', mob)) == sorted(hrefs_d))
    check('U19 รายการที่อยู่ในกลุ่มมีไอคอนทุกรายการ และมีขนาดเมนูกระชับ (ลิงก์ระดับบนสุดไม่เกิน 8 ข้อ)', desk.count('<svg class="icon') >= len(hrefs_d) and len(re.findall(r'<details class="nav-group', desk)) + len(re.findall(r'</details><a|^<a', desk)) <= 8)
    check('U20 กล่องแจ้งเตือนมีไอคอน และบัญชีของฉัน/ออกจากระบบแสดงแต่ไอคอนบนแถบแคบ (มี title และข้อความสำหรับโปรแกรมอ่านหน้าจอ)',
          'sr-only 2xl:not-sr-only' in desk and 'title="ออกจากระบบ"' in desk and 'aria-hidden="true"' in desk)

    # ================= การ์ดกลาง: ช่องว่างเท่ากันทั้งระบบ =================
    css = g.raw('/assets/css/tailwind.css')[2].decode('utf-8', 'ignore')
    check('U21 CSS การ์ดกลางถูกคอมไพล์ (.card .card-table .card-bar .callout) พร้อมกฎจัดช่องไฟในกริด', all(x in css for x in ('.card{', '.card-table{', '.card-bar{', '.callout{')) and ':has(' in css)
    raw_cards = []
    for f in list(ROOT.glob('*.php')) + list(ROOT.glob('admin/*.php')) + list(ROOT.glob('includes/*.php')):
        for m in re.finditer(r'class="([^"]*)"', f.read_text(encoding='utf-8')):
            v = m.group(1).split('<?')[0].split()
            if 'rounded-2xl' in v and 'border' in v and 'border-stone-200' in v and 'bg-white' in v and not ({'card', 'card-table', 'card-bar'} & set(v)) and 'rc-page' not in v:
                raw_cards.append(f'{f.name}: {" ".join(v)[:70]}')
    check('U22 ไม่มีกล่องที่ประกอบสไตล์การ์ดเอง (ต้องใช้ .card/.card-table/.card-bar ให้ padding เท่ากันทั้งระบบ)', raw_cards == [], '; '.join(raw_cards[:5]))
    padded = []
    for f in list(ROOT.glob('*.php')) + list(ROOT.glob('admin/*.php')) + list(ROOT.glob('includes/*.php')):
        for m in re.finditer(r'class="([^"]*)"', f.read_text(encoding='utf-8')):
            v = m.group(1).split('<?')[0].split()
            if ({'card', 'card-table', 'card-bar'} & set(v)) and any(re.fullmatch(r'(p|px|py|pt|pb|pl|pr)-\d+(\.\d+)?', t) for t in v):
                padded.append(f'{f.name}: {" ".join(v)[:70]}')
    check('U23 การ์ดไม่กำหนด padding เอง (padding มาจาก .card เท่ากันทุกด้าน)', not padded, '; '.join(padded[:5]))

    # ================= ตัวควบคุมฟอร์ม: ความสูงและขอบมนสมดุลกัน =================
    check('U24 CSS ตัวควบคุม: ช่องกรอก/ตัวเลือก/ปุ่ม ใช้ความสูงมาตรฐานเดียวกัน (--ctl-h) ขอบมน 0.75rem เท่ากัน และปุ่มเล็ก/ฟอร์มใหญ่มีขนาดของตัวเอง',
          '--ctl-h:2.75rem' in css.replace(' ', '') and 'min-height:var(--ctl-h)' in css and 'min-height:var(--ctl-h-sm)' in css and '.form-lg' in css and 'border-radius:.75rem' in css.replace(' ', ''))
    odd = []
    for f in list(ROOT.glob('*.php')) + list(ROOT.glob('admin/*.php')) + list(ROOT.glob('includes/*.php')):
        for m in re.finditer(r'<(input|select|textarea|button class="btn[^"]*)([^>]*)>', f.read_text(encoding='utf-8')):
            tag = m.group(0).split('<?')[0]
            if re.search(r'(?<![\w-])(rounded(-[a-z0-9\[\]]+)?|h-\d+|min-h-\d+|!min-h-\d+)(?![\w-])', tag) and 'type="checkbox"' not in tag and 'type="radio"' not in tag:
                odd.append(f'{f.name}: {tag[:80]}')
    check('U25 ช่องกรอก/ตัวเลือก/ปุ่มไม่กำหนดความสูงหรือรัศมีขอบเอง (ใช้ค่ากลางของระบบ)', not odd, '; '.join(odd[:4]))
    flat = css.replace(' ', '')
    check('U27 ลูกศร dropdown วาดเอง (appearance:none + ไอคอน chevron ชิดขวากึ่งกลาง + เว้นพื้นที่ด้านขวา) และเปลี่ยนสีเมื่อโฟกัส',
          'appearance:none' in flat and 'data:image/svg+xml' in flat and 'backgroundposition' not in flat and 'background-position:right.75remcenter' in flat.replace('0.', '.') + css.replace(' ', '') and 'padding-right:2.25rem' in flat and flat.count('data:image/svg+xml') >= 2)
    _, _, bf = ad.req('/accounts.php?temple=' + q("SELECT id FROM temples ORDER BY id LIMIT 1"))
    check('U28 ช่องเลือกเดือนในช่องวันที่ใช้ชื่อย่อ (ม.ค./ก.พ. ...) ให้พอดีกับช่องแคบ', '>ม.ค.</option>' in bf and '>มกราคม</option>' not in bf)
    import shutil, subprocess as sp
    has_pw = shutil.which('node') and sp.run(['node', '-e', "require('playwright')"], capture_output=True, env={**__import__('os').environ, 'NODE_PATH': sp.run(['npm', 'root', '-g'], capture_output=True, text=True).stdout.strip()}).returncode == 0
    if has_pw:
        t0 = q("SELECT id FROM temples ORDER BY id LIMIT 1")
        js = """const {chromium}=require('playwright');(async()=>{const b=await chromium.launch();const p=await (await b.newContext({viewport:{width:1280,height:900}})).newPage();
        await p.goto('%s/login.php');await p.fill('input[name=username]','admin');await p.fill('input[name=password]','Test12345pw');await p.click('form button');await p.waitForLoadState('networkidle');
        const out={};for(const pg of ['/accounts.php?temple=%s','/funds.php?temple=%s','/assets.php?temple=%s&new=1']){await p.goto('%s'+pg);await p.waitForLoadState('networkidle');
        const r=await p.evaluate(()=>{const o=[];document.querySelectorAll('main input:not([type=checkbox]):not([type=radio]):not([type=hidden]),main select,main .btn:not(.btn-sm)').forEach(e=>{if(!e.offsetParent)return;const c=getComputedStyle(e);o.push(Math.round(e.getBoundingClientRect().height)+'/'+c.borderTopLeftRadius)});return [...new Set(o)]});out[pg]=r}
        console.log(JSON.stringify(out));await b.close()})()""" % (base, t0, t0, t0, base)
        r = sp.run(['node', '-e', js], capture_output=True, text=True, env={**__import__('os').environ, 'NODE_PATH': sp.run(['npm', 'root', '-g'], capture_output=True, text=True).stdout.strip()})
        sizes = {x for v in __import__('json').loads(r.stdout.strip().splitlines()[-1]).values() for x in v} if r.returncode == 0 else {'error'}
        check('U26 ในเบราว์เซอร์จริง ช่องกรอก/ตัวเลือก/ปุ่มทุกตัวสูง 44px และขอบมน 12px เท่ากัน', sizes == {'44/12px'}, f'{sizes} {r.stderr[-200:]}')
        aud = """const {chromium}=require('playwright');(async()=>{const b=await chromium.launch();const p=await (await b.newContext({viewport:{width:1280,height:900}})).newPage();
        await p.goto('%s/login.php');await p.fill('input[name=username]','admin');await p.fill('input[name=password]','Test12345pw');await p.click('form button');await p.waitForLoadState('networkidle');
        const bad=[];for(const pg of %s){await p.goto('%s'+pg);await p.waitForLoadState('networkidle');
        const r=await p.evaluate(()=>{const out=[];const vis=e=>e.offsetParent!==null;document.querySelectorAll('main *').forEach(par=>{const ctl=[...par.children].filter(vis).filter(k=>k.matches('input:not([type=hidden]):not([type=checkbox]):not([type=radio]),select,.btn,button'));
        if(!ctl.some(k=>k.matches('input,select'))||ctl.length<2||!/flex|grid/.test(getComputedStyle(par).display))return;const rs=ctl.map(k=>{const r=k.getBoundingClientRect();return {h:Math.round(r.height),b:Math.round(r.bottom),t:Math.round(r.top),n:k.tagName+'.'+(k.className||'').toString().slice(0,24)}});
        for(let i=0;i<rs.length;i++)for(let j=i+1;j<rs.length;j++){const a=rs[i],c=rs[j];if(Math.abs(a.t-c.t)<20&&(a.h!==c.h||a.b!==c.b))out.push(a.n+' h'+a.h+'|'+c.n+' h'+c.h)}});return out});
        r.forEach(x=>bad.push(pg+' '+x))}console.log(JSON.stringify(bad));await b.close()})()""" % (base, __import__('json').dumps(['/dashboard.php', f'/accounts.php?temple={t0}', '/report_status.php', f'/statements.php?temple={t0}', '/admin/users.php', '/admin/subdistricts.php', '/admin/temple_accounts.php', f'/profile.php?temple={t0}', '/transactions.php?type=income', '/reports.php']), base)
        ra = sp.run(['node', '-e', aud], capture_output=True, text=True, env={**__import__('os').environ, 'NODE_PATH': sp.run(['npm', 'root', '-g'], capture_output=True, text=True).stdout.strip()})
        mism = __import__('json').loads(ra.stdout.strip().splitlines()[-1]) if ra.returncode == 0 else ['error ' + ra.stderr[-200:]]
        check('U29 แถวฟอร์มที่มีช่องกรอก/ตัวเลือกคู่กับปุ่ม: ทุกตัวสูงเท่ากันและชิดขอบล่างเดียวกัน (ตรวจ 10 หน้าในเบราว์เซอร์จริง)', mism == [], '; '.join(mism[:4]))
    else:
        check('U26 (ข้าม: ไม่มี Node/Playwright ในเครื่องนี้) ตรวจขนาดตัวควบคุมในเบราว์เซอร์จริง', True)

    # ================= วันที่ทุกจุดเป็น พ.ศ. =================
    today = date.today()
    be = today.year + 543
    _, _, b = adm.req('/transactions.php?type=income')
    years = [int(x) for x in re.findall(r'<option value="(\d{4})"', re.search(r'name="txn_date_y".*?</select>', b, re.S).group(0))]
    check('D1 ช่องปีของฟอร์มรายการบัญชีเป็น พ.ศ. ทั้งหมด (ไม่มี ค.ศ.) และเลือกปีปัจจุบันเป็นค่าเริ่มต้น',
          years and min(years) > 2500 and be in years and f'<option value="{be}" selected>' in b, str(years))
    check('D2 ไม่มี <input type="date"> (แสดงปีตามเบราว์เซอร์/ค.ศ.) เหลือในโค้ดหน้าเว็บ', not [f for f in glob.glob(str(app.dir / '*.php')) + glob.glob(str(app.dir / 'admin' / '*.php'))
                                                                              if 'type="date"' in open(f, encoding='utf-8').read()])
    save = lambda **kw: adm.post('/transactions.php?type=income', {'action': 'save', 'id': '0', 'temple_id': '1', 'category_id': '1', 'amount': '77', 'doc_no': '', 'description': 'วันที่', **kw}, '/transactions.php?type=income')
    n0 = q("SELECT COUNT(*) FROM transactions")
    save(**{'txn_date_d': '31', 'txn_date_m': '2', 'txn_date_y': str(be)})
    save(**{'txn_date_d': '5', 'txn_date_m': '1', 'txn_date_y': '2026'})
    save(**{'txn_date_d': '5', 'txn_date_m': '1'})
    save(**{'txn_date_d': 'x', 'txn_date_m': '1', 'txn_date_y': str(be)})
    check('D3 วันที่ไม่ถูกต้องถูกปฏิเสธ: 31 กุมภาพันธ์, ปี ค.ศ. (2026) ที่ใส่ผิดช่อง, ไม่ครบ, ไม่ใช่ตัวเลข', q("SELECT COUNT(*) FROM transactions") == n0)
    save(**{'txn_date_d': '5', 'txn_date_m': '1', 'txn_date_y': '2569'})
    check('D4 ปี พ.ศ. 2569 ถูกแปลงเก็บเป็น ค.ศ. 2026 ในฐานข้อมูล', q("SELECT txn_date FROM transactions WHERE description='วันที่' ORDER BY id DESC LIMIT 1") == '2026-01-05')
    c, _, b = adm.req('/transactions.php?type=income&year=2569')
    check('D5 รายการแสดงวันที่เป็น พ.ศ. (5 มกราคม 2569) และไม่มีวันที่ ค.ศ. รูปแบบ 2026-01-05 หลุดในหน้า', '5 มกราคม 2569' in b and not re.search(r'\b20[0-4]\d-\d\d-\d\d', b))
    rid = q("SELECT id FROM transactions WHERE description='วันที่' ORDER BY id DESC LIMIT 1")
    _, _, b = adm.req(f'/transactions.php?type=income&year=2569&edit={rid}')
    check('D6 แก้ไขรายการ: ฟอร์มแสดงวัน/เดือน/ปี พ.ศ. ของรายการเดิม', '<option value="5" selected>' in b and '<option value="1" selected>' in b and '<option value="2569" selected>' in b)
    c, h, body = adm.raw('/reports.php?export=csv&year=2569')
    check('D7 ส่งออก CSV: คอลัมน์วันที่เป็น พ.ศ. (05/01/2569)', '05/01/2569' in body.decode('utf-8-sig') and '2026-01-05' not in body.decode('utf-8-sig'))
    _, _, b = adm.req('/admin/audit.php')
    check('D8 ประวัติการใช้งานแสดงเวลาเป็น พ.ศ. (ไม่ใช่ 2026-xx-xx)', f' {be} ' in b and not re.search(r'\b20[0-4]\d-\d\d-\d\d \d\d:\d\d', b))
    for page in ('/dashboard.php', '/reports.php', '/receipts.php', '/account.php', '/admin/temple_accounts.php', '/temples.php'):
        _, _, b = adm.req(page)
        check(f'D9 {page} ไม่มีวันที่ ค.ศ. หลุดในหน้า', not re.search(r'\b20[0-4]\d-\d\d-\d\d', b))
    _, _, b = adm.req(f'/receipt.php?txn={q("SELECT id FROM transactions ORDER BY id LIMIT 1")}')
    check('D10 ฟอร์มวันที่ออกอนุโมทนาบัตรเป็นช่อง พ.ศ. (วัน/เดือน/ปี)', 'name="receipt_date_y"' in b and 'type="date"' not in b)
