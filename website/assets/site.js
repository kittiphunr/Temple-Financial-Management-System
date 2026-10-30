(function () {
  var shots = {
    dashboard: ['dashboard.php', 'แดชบอร์ด: ตัวเลขรวม กราฟรายเดือน และแท็บเลือกอำเภอ'],
    statements: ['statements.php', 'รายงานประจำเดือนแบบที่ 1 (เงินสด) ตามแบบฟอร์มของวัด พร้อมยอดยกมาและยอดคงเหลือ'],
    receipt: ['receipt.php', 'อนุโมทนาบัตร ตัวอักษรไทย พร้อม QR และรหัสตรวจสอบ'],
    temples: ['temples.php', 'ทะเบียนวัดแยกตามอำเภอ ค้นหาและกรองได้'],
    install: ['install.php', 'ตัวช่วยติดตั้ง: เลือกจังหวัดที่ดูแล ตั้งชื่อระบบ อัปโหลดทะเบียนวัด และสร้างแอดมินหลัก'],
    import: ['admin/import.php', 'นำเข้าทะเบียนวัดจากไฟล์ Excel ของ binfo.onab.go.th พร้อมหน้าสรุปก่อนบันทึก'],
    adminhome: ['admin/index.php', 'เมนูจัดการระบบสำหรับแอดมินหลัก']
  };
  var img = document.getElementById('shot'), url = document.getElementById('shot-url'), cap = document.getElementById('shot-cap');
  function show(k) {
    img.src = 'assets/shots/' + k + '.webp'; img.alt = shots[k][1]; url.textContent = shots[k][0]; cap.textContent = shots[k][1];
    document.querySelector('.browser').classList.toggle('narrow-shot', k === 'install');
    document.querySelectorAll('.tabs button').forEach(function (b) { b.setAttribute('aria-selected', b.dataset.tab === k); });
  }
  document.querySelectorAll('.tabs button').forEach(function (b) { b.addEventListener('click', function () { show(b.dataset.tab); }); });
  show('dashboard');

  function fmt(n) { return n > 1048576 ? (n / 1048576).toFixed(1) + ' MB' : Math.round(n / 1024) + ' KB'; }
  fetch('downloads/release.json', { cache: 'no-cache' }).then(function (r) { return r.json(); }).then(function (m) {
    document.querySelectorAll('[data-ver]').forEach(function (e) { e.textContent = 'v' + m.version; });
    document.querySelector('[data-file]').textContent = m.file;
    document.querySelector('[data-size]').textContent = fmt(m.size);
    document.querySelector('[data-date]').textContent = m.date;
    document.querySelector('[data-sha]').textContent = m.sha256;
    document.getElementById('dl-link').href = 'downloads/' + m.file;
  }).catch(function () {
    document.getElementById('dl-link').textContent = 'ยังไม่มีไฟล์ดาวน์โหลด';
  });
})();
