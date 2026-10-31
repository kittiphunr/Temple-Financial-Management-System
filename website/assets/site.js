(function () {
  var shots = {
    dashboard: ['dashboard.php', 'แดชบอร์ด: ตัวเลขรวม สถานะการส่งรายงาน กราฟรายเดือน และแท็บเลือกอำเภอ'],
    reportstatus: ['report_status.php', 'ติดตามการส่งรายงานประจำเดือน: ส่งแล้ว รอรับรอง ส่งกลับแก้ไข อำเภอรับรองแล้ว ปิดงวด พร้อมตัวกรองและส่งออก CSV'],
    statements: ['statements.php', 'รายงานประจำเดือนแบบที่ 1 (เงินสด) ตามแบบฟอร์มของวัด พร้อมปุ่มส่งรายงาน ปิดงวด รับรอง และส่งกลับแก้ไข'],
    receipt: ['receipt.php', 'อนุโมทนาบัตร ตัวอักษรไทย พร้อม QR และรหัสตรวจสอบ'],
    recurring: ['recurring.php', 'รายการประจำ: ถึงกำหนดรอยืนยัน ตรวจ แก้จำนวนเงิน แล้วกดบันทึกหรือข้ามเดือนนั้น'],
    quick: ['quick.php', 'บันทึกด่วนบนมือถือ: ฟอร์มสั้น ปุ่มใหญ่ ใช้หน้างาน'],
    budget: ['budget.php', 'งบประมาณประจำปีรายหมวด เทียบกับผลจริง พร้อมเตือนเมื่อใกล้เกินงบ'],
    accounts: ['accounts.php', 'บัญชีเงินสดและเงินฝากธนาคารหลายบัญชี พร้อมโลโก้ธนาคาร ยอดยกมา กระทบยอด และนำเข้าจากไฟล์ธนาคาร'],
    temples: ['temples.php', 'ทะเบียนวัดแยกตามอำเภอ ค้นหาและกรองได้'],
    install: ['install.php', 'ตัวช่วยติดตั้ง: เลือกจังหวัดที่ดูแล ตั้งชื่อระบบ อัปโหลดทะเบียนวัด และสร้างแอดมินหลัก'],
    import: ['admin/import.php', 'นำเข้าทะเบียนวัดจากไฟล์ Excel ของ binfo.onab.go.th พร้อมหน้าสรุปก่อนบันทึก'],
    adminhome: ['admin/index.php', 'เมนูจัดการระบบสำหรับแอดมินหลัก: จัดการวัด ผู้ใช้ เจ้าคณะตำบล หมวดบัญชี สำรองข้อมูล และประวัติการใช้งาน']
  };
  var img = document.getElementById('shot'), url = document.getElementById('shot-url'), cap = document.getElementById('shot-cap');
  function show(k) {
    img.src = 'assets/shots/' + k + '.webp'; img.alt = shots[k][1]; url.textContent = shots[k][0]; cap.textContent = shots[k][1];
    document.querySelector('.browser').classList.toggle('narrow-shot', k === 'install');
    document.querySelector('.browser').classList.toggle('phone-shot', k === 'quick');
    document.querySelectorAll('.tabs button').forEach(function (b) { b.setAttribute('aria-selected', b.dataset.tab === k); });
  }
  document.querySelectorAll('.tabs button').forEach(function (b) { b.addEventListener('click', function () { show(b.dataset.tab); }); });
  show('dashboard');

  function fmt(n) { return n > 1048576 ? (n / 1048576).toFixed(1) + ' MB' : Math.round(n / 1024) + ' KB'; }
  fetch('downloads/release.json', { cache: 'no-cache' }).then(function (r) { return r.json(); }).then(function (m) {
    document.querySelectorAll('[data-ver]').forEach(function (e) { e.textContent = 'v' + m.version + (m.build ? ' (บิวล์ ' + m.build + ')' : ''); });
    document.querySelector('[data-file]').textContent = m.file;
    document.querySelector('[data-size]').textContent = fmt(m.size);
    document.querySelector('[data-date]').textContent = m.date;
    document.querySelector('[data-sha]').textContent = m.sha256;
    document.getElementById('dl-link').href = 'downloads/' + m.file;
  }).catch(function () {
    document.getElementById('dl-link').textContent = 'ยังไม่มีไฟล์ดาวน์โหลด';
  });
})();
