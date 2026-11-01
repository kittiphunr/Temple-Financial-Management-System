"""ชุดที่ 19: กราฟด้วย Chart.js — ไฟล์/เครดิต กราฟรายเดือนและโดนัทบนแดชบอร์ด กราฟรายเดือนในหน้ารายงาน กราฟสำรองเมื่อไม่มี JavaScript

ตรวจ: ข้อมูลที่ส่งให้กราฟตรงกับฐานข้อมูล · โหลดสคริปต์เฉพาะหน้าที่มีกราฟ · escape ชื่อหมวด · ในเบราว์เซอร์จริง (ถ้ามี Playwright) กราฟวาดสำเร็จและไม่มี error
"""
import datetime
import html
import json
import os
import re
import shutil
import subprocess as sp
from pathlib import Path

from support import App, Client, ROOT


def run(db, R):
    name = db.new_database('charts')
    q = db.q(name)
    app = App(env={'TFMS_DB_HOST': db.host, 'TFMS_DB_PORT': db.port, 'TFMS_DB_USER': db.user, 'TFMS_DB_PASS': db.password, 'TFMS_DB_NAME': name})
    try:
        _run(R, q, app)
    finally:
        problems = app.php_problems()
        R.check('Z1 ไม่มี PHP warning/notice/fatal ใน log ของเซิร์ฟเวอร์', not problems, '\n'.join(problems[:3]))
        app.close()


def _boxes(page):
    """กล่องกราฟทั้งหมดในหน้า: [(config dict, label, fallback html)]"""
    out = []
    for m in re.finditer(r'<div class="chart-box" data-chart="([^"]*)" data-chart-label="([^"]*)" data-chart-height="[^"]*"><div data-chart-fallback>(.*?)</div></div>', page, re.S):
        out.append((json.loads(html.unescape(m.group(1))), html.unescape(m.group(2)), m.group(3)))
    return out


def _run(R, q, app):
    check = R.check
    # ---- A: ไฟล์และเครดิต (ไม่ต้องใช้ฐานข้อมูล)
    lib = (ROOT / 'assets/vendor/chart.umd.min.js').read_text(encoding='utf-8', errors='replace')
    lic = (ROOT / 'assets/vendor/chart.LICENSE').read_text(encoding='utf-8')
    credits = json.loads((ROOT / 'data/credits.json').read_text(encoding='utf-8'))
    cj = next((c for c in credits if c['id'] == 'chartjs'), {})
    check('A1 ไฟล์ Chart.js v4 (MIT) อยู่ใน assets/vendor พร้อมข้อความลิขสิทธิ์ต้นฉบับ', lib.startswith('/*!') and 'Chart.js v4.' in lib[:200] and 'MIT License' in lib[:300] and 'MIT License' in lic and 'Chart.js Contributors' in lic)
    check('A2 มีรายการเครดิต chartjs (ผู้สร้าง สัญญา url ไฟล์สัญญา ไฟล์ที่ใช้) และไฟล์เหล่านั้นมีอยู่จริง',
          cj.get('license') == 'MIT' and cj.get('author') == 'Chart.js Contributors' and cj.get('url', '').startswith('https://www.chartjs.org')
          and all((ROOT / f).is_file() for f in cj.get('files', []) + [cj.get('license_file', '-')]) and 'assets/vendor/chart.umd.min.js' in cj.get('files', []))
    check('A3 CREDITS.md และเว็บประชาสัมพันธ์แสดงเครดิต Chart.js', 'Chart.js' in (ROOT / 'CREDITS.md').read_text(encoding='utf-8') and 'Chart.js' in (ROOT / 'website/index.html').read_text(encoding='utf-8'))
    js = (ROOT / 'assets/js/charts.js').read_text(encoding='utf-8')
    check('A4 charts.js เป็น progressive enhancement (ซ่อนกราฟสำรองเมื่อวาดสำเร็จ และคงกราฟสำรองถ้าสร้างไม่ได้)', 'data-chart-fallback' in js and 'catch' in js and "typeof Chart === 'undefined'" in js)

    app.php('setup.php', 'Test12345pw')
    base = app.serve()
    adm = Client(base)
    adm.post('/login.php', {'username': 'admin', 'password': 'Test12345pw'})
    ce = datetime.date.today().year
    be = ce + 543
    tA, tB = q("SELECT id FROM temples WHERE is_active=1 ORDER BY id LIMIT 2").split('\n')
    cat_in = q("SELECT id FROM categories WHERE type='income' AND is_transfer=0 AND is_active=1 ORDER BY sort_order LIMIT 1")
    cat_in2 = q("SELECT id FROM categories WHERE type='income' AND is_transfer=0 AND is_active=1 ORDER BY sort_order LIMIT 1 OFFSET 1")
    cat_ex = q("SELECT id FROM categories WHERE type='expense' AND is_transfer=0 AND is_active=1 ORDER BY sort_order LIMIT 1")
    q("INSERT INTO categories (type,name,sort_order) VALUES ('expense','ทดสอบ\"><script>alert(1)</script>',99)")
    cat_xss = q("SELECT id FROM categories WHERE name LIKE 'ทดสอบ%script%'")

    def tx(t, typ, month, amt, cat):
        q(f"INSERT INTO transactions (temple_id,type,category_id,txn_date,description,amount) VALUES ({t},'{typ}',{cat},'{ce}-{month:02d}-10','ทดสอบ',{amt})")
    tx(tA, 'income', 1, 1000, cat_in); tx(tA, 'expense', 1, 400.5, cat_ex); tx(tB, 'income', 3, 2500.25, cat_in2); tx(tB, 'expense', 3, 700, cat_xss); tx(tA, 'income', 12, 300, cat_in)
    inc = {m: float(q(f"SELECT COALESCE(SUM(amount),0) FROM transactions WHERE type='income' AND MONTH(txn_date)={m} AND YEAR(txn_date)={ce}")) for m in range(1, 13)}
    exp = {m: float(q(f"SELECT COALESCE(SUM(amount),0) FROM transactions WHERE type='expense' AND MONTH(txn_date)={m} AND YEAR(txn_date)={ce}")) for m in range(1, 13)}

    # ---- B: แดชบอร์ด
    c, _, b = adm.req(f'/dashboard.php?year={be}')
    boxes = _boxes(b)
    check('B1 แดชบอร์ดโหลด Chart.js แล้วตามด้วย charts.js (ลำดับถูกต้อง ไม่ซ้ำ)', c == 200 and b.count('assets/vendor/chart.umd.min.js') == 1 and b.count('assets/js/charts.js') == 1 and b.index('chart.umd.min.js') < b.index('charts.js'))
    check('B2 มีกล่องกราฟ 3 กล่อง (แท่งรายเดือน + โดนัทหมวดรายรับ + โดนัทหมวดรายจ่าย)', [x[0]['type'] for x in boxes] == ['bar', 'doughnut', 'doughnut'], str([x[0]['type'] for x in boxes]))
    bar = boxes[0][0]
    check('B3 กราฟแท่ง: 12 เดือน ข้อมูลรายรับ/รายจ่ายตรงกับฐานข้อมูลทุกเดือน',
          len(bar['labels']) == 12 and bar['labels'][0] == 'ม.ค.' and bar['datasets'][0]['data'] == [inc[m] for m in range(1, 13)] and bar['datasets'][1]['data'] == [exp[m] for m in range(1, 13)]
          and bar['datasets'][0]['color'] == '#059669' and bar['datasets'][1]['color'] == '#ef4444', str(bar['datasets'][0]['data']))
    check('B4 กราฟแท่งมีกราฟ SVG เดิมเป็นกราฟสำรอง และตารางตัวเลขรายเดือนยังอยู่', '<svg' in boxes[0][2] and 'ดูตัวเลขรายเดือน' in b)
    top_in = q(f"SELECT CONCAT(c.name,'\t',ROUND(SUM(x.amount),2)) FROM transactions x JOIN categories c ON c.id=x.category_id WHERE x.type='income' AND x.is_deleted=0 AND x.transfer_id IS NULL AND YEAR(x.txn_date)={ce} GROUP BY c.id,c.name ORDER BY SUM(x.amount) DESC LIMIT 6").split('\n')
    dn = boxes[1][0]
    check('B5 โดนัทหมวดรายรับ: ชื่อหมวดและยอดตรงกับฐานข้อมูล เรียงจากมากไปน้อย สีครบทุกชิ้น',
          dn['labels'] == [r.split('\t')[0] for r in top_in] and dn['datasets'][0]['data'] == [float(r.split('\t')[1]) for r in top_in] and len(dn['datasets'][0]['colors']) == len(top_in), str(dn))
    check('B6 รายการหมวดเดิม (แถบ) ยังอยู่ครบใต้โดนัท พร้อมจุดสีที่ตรงกับสีของแต่ละชิ้นในโดนัท',
          'หมวดรายรับสูงสุด' in b and b.count('rounded-full bg-stone-200') >= 2 and all(f'background:{col}' in b for col in dn['datasets'][0]['colors']))
    xss = boxes[2][0]
    check('B7 ชื่อหมวดที่มี " > <script> ถูก escape ในแอตทริบิวต์ (ไม่มีสคริปต์หลุดออกมา) และถอดค่ากลับได้ตรงเดิม',
          '<script>alert(1)' not in b and 'ทดสอบ"><script>alert(1)</script>' in xss['labels'], str(xss['labels']))
    c, _, e = adm.req(f'/dashboard.php?year={be - 5}')
    check('B8 ปีที่ไม่มีรายการ: แสดงข้อความเดิม ไม่สร้างกราฟแท่ง/โดนัท', 'ยังไม่มีรายการบัญชีในปีที่เลือก' in e and not _boxes(e))

    # ---- C: หน้ารายงานภาพรวม และหน้าอื่นไม่โหลดกราฟ
    c, _, r = adm.req(f'/reports.php?year={be}')
    rb = _boxes(r)
    check('C1 หน้ารายงานภาพรวมมีกราฟแท่งรายเดือน ข้อมูลตรงกับตาราง และโหลดสคริปต์กราฟ',
          c == 200 and len(rb) == 1 and rb[0][0]['datasets'][0]['data'] == [inc[m] for m in range(1, 13)] and rb[0][0]['datasets'][1]['data'] == [exp[m] for m in range(1, 13)] and 'assets/js/charts.js' in r)
    others = {p: adm.req(p)[2] for p in ('/transactions.php?type=income', '/accounts.php?temple=' + tA, '/budget.php?temple=' + tA, '/statements.php?temple=' + tA)}
    check('C2 หน้าที่ไม่มีกราฟไม่โหลด Chart.js', all('chart.umd.min.js' not in v for v in others.values()))
    check('C3 ไฟล์สคริปต์เสิร์ฟได้ (Chart.js ไม่ว่าง และ charts.js)', len(adm.req('/assets/vendor/chart.umd.min.js')[2]) > 100000 and 'data-chart' in adm.req('/assets/js/charts.js')[2])

    # ---- D: ในเบราว์เซอร์จริง
    env = {**os.environ, 'NODE_PATH': sp.run(['npm', 'root', '-g'], capture_output=True, text=True).stdout.strip()}
    has_pw = shutil.which('node') and sp.run(['node', '-e', "require('playwright')"], capture_output=True, env=env).returncode == 0
    if not has_pw:
        print('SKIP D* ไม่มี Playwright — ข้ามการทดสอบในเบราว์เซอร์จริง')
        return
    script = """const {chromium}=require('playwright');(async()=>{const b=await chromium.launch();const out={};
    const login=async(ctx)=>{const p=await ctx.newPage();await p.goto('%(base)s/login.php');await p.fill('input[name=username]','admin');await p.fill('input[name=password]','Test12345pw');await p.click('form button');await p.waitForLoadState('networkidle');return p};
    for(const js of [true,false]){const ctx=await b.newContext({viewport:{width:1280,height:900},javaScriptEnabled:js});
      if(!js){const lp=await b.newContext({viewport:{width:1280,height:900}});const p0=await login(lp);const ck=await lp.cookies();await ctx.addCookies(ck)}
      const p=js?await login(ctx):await ctx.newPage();const errs=[];p.on('pageerror',e=>errs.push(String(e)));p.on('console',m=>{if(m.type()==='error'&&!/fonts|net::ERR|Failed to load resource/.test(m.text()))errs.push(m.text())});
      for(const pg of ['/dashboard.php?year=%(be)s','/reports.php?year=%(be)s']){await p.goto('%(base)s'+pg);await p.waitForLoadState('networkidle');await p.waitForTimeout(600);
        out[(js?'js':'nojs')+pg]=await p.evaluate(()=>{const bx=[...document.querySelectorAll('.chart-box')];return {boxes:bx.length,ready:bx.filter(x=>x.dataset.chartReady==='1').length,
          canvases:[...document.querySelectorAll('.chart-box canvas')].map(c=>{const ch=window.Chart&&Chart.getChart(c);return {w:c.clientWidth,h:c.clientHeight,type:ch?ch.config.type:null,n:ch?ch.data.datasets[0].data.length:0,sum:ch?ch.data.datasets[0].data.reduce((a,b)=>a+b,0):0,nonblank:(()=>{const d=c.getContext('2d').getImageData(0,0,c.width,c.height).data;let k=0;for(let i=3;i<d.length;i+=4)if(d[i]>0){k++;if(k>50)return true}return false})()}}),
          fallbackHidden:bx.filter(x=>x.querySelector('[data-chart-fallback]')).map(x=>getComputedStyle(x.querySelector('[data-chart-fallback]')).display==='none'),
          svgVisible:[...document.querySelectorAll('.chart-box svg')].map(s=>s.getBoundingClientRect().width>100)}})}
      out[(js?'js':'nojs')+'_errors']=errs;await ctx.close()}
    console.log(JSON.stringify(out));await b.close()})()""" % {'base': base, 'be': be}
    r = sp.run(['node', '-e', script], capture_output=True, text=True, env=env)
    try:
        d = json.loads(r.stdout.strip().splitlines()[-1])
    except Exception:
        d = {}
    dash, rep = d.get('js/dashboard.php?year=%d' % be, {}), d.get('js/reports.php?year=%d' % be, {})
    check('D1 ในเบราว์เซอร์: แดชบอร์ดวาดกราฟสำเร็จครบ 3 กล่อง (แท่ง+โดนัท 2) มีขนาดและมีภาพจริง',
          dash.get('boxes') == 3 and dash.get('ready') == 3 and [c['type'] for c in dash.get('canvases', [])] == ['bar', 'doughnut', 'doughnut']
          and all(c['w'] > 100 and c['h'] > 100 and c['nonblank'] for c in dash.get('canvases', [])), (str(d) + r.stderr)[:400])
    check('D2 ข้อมูลที่กราฟถือตรงกับฐานข้อมูล (12 เดือน ผลรวมรายรับ)', dash.get('canvases') and dash['canvases'][0]['n'] == 12 and abs(dash['canvases'][0]['sum'] - sum(inc.values())) < 0.01, str(dash.get('canvases'))[:200])
    check('D3 กราฟสำรอง SVG ถูกซ่อนเมื่อกราฟ Chart.js พร้อม', dash.get('fallbackHidden', [False])[0] is True)
    check('D4 หน้ารายงานภาพรวมวาดกราฟสำเร็จ', rep.get('boxes') == 1 and rep.get('ready') == 1 and rep['canvases'][0]['nonblank'])
    nd = d.get('nojs/dashboard.php?year=%d' % be, {})
    check('D5 ปิด JavaScript: ไม่มีกราฟ Chart.js แต่ยังเห็นกราฟ SVG เดิม (ข้อมูลไม่หาย)', nd.get('ready') == 0 and nd.get('svgVisible', [False])[0] is True and nd.get('canvases') == [], str(nd)[:300])
    check('D6 ไม่มี JavaScript error ใน console', d.get('js_errors') == [], str(d.get('js_errors')))
