/* แจ้งเตือนด้วย SweetAlert2: แทนกล่องข้อความ (flash/ข้อผิดพลาด), กล่องยืนยันก่อนลบ/ดำเนินการ และหน้าต่าง "เกี่ยวกับ"
 * ไม่มี JS ก็ยังเห็นข้อความเป็นแถบบนหน้าเหมือนเดิม และฟอร์มส่งได้ตามปกติ */
(function () {
  'use strict';
  if (typeof Swal === 'undefined') return;

  var BRAND = '#9b1c1c';
  var Toast = Swal.mixin({
    toast: true, position: 'top-end', showConfirmButton: false, timer: 4500, timerProgressBar: true,
    didOpen: function (t) { t.addEventListener('mouseenter', Swal.stopTimer); t.addEventListener('mouseleave', Swal.resumeTimer); }
  });
  var Modal = Swal.mixin({ confirmButtonColor: BRAND, cancelButtonColor: '#78716c', confirmButtonText: 'ตกลง', cancelButtonText: 'ยกเลิก' });

  // ---- ข้อความแจ้งเตือนจากเซิร์ฟเวอร์: <div data-swal="ok|err|info">ข้อความ</div> ----
  var queue = Array.prototype.slice.call(document.querySelectorAll('[data-swal]'));
  queue.forEach(function (el) { el.style.display = 'none'; });
  (function next() {
    var el = queue.shift();
    if (!el) return;
    var type = el.getAttribute('data-swal'), msg = el.textContent.trim();
    if (!msg) return next();
    if (type === 'ok') {                      // สำเร็จ: ป้ายเล็กมุมจอ หายเอง ไม่ขวางการทำงาน
      Toast.fire({ icon: 'success', title: msg });
      return next();
    }
    Modal.fire({ icon: type === 'err' ? 'error' : 'info', title: type === 'err' ? 'เกิดข้อผิดพลาด' : 'แจ้งให้ทราบ', text: msg }).then(next);
  })();

  // ---- ยืนยันก่อนส่งฟอร์ม: form[data-confirm] หรือปุ่ม[data-confirm] ----
  document.addEventListener('submit', function (e) {
    var form = e.target, btn = e.submitter;
    var text = (btn && btn.getAttribute('data-confirm')) || form.getAttribute('data-confirm');
    if (!text || form.getAttribute('data-confirmed') === '1') { form.removeAttribute('data-confirmed'); return; }
    e.preventDefault();
    Modal.fire({
      icon: 'warning', title: 'ยืนยันการดำเนินการ', text: text, showCancelButton: true, focusCancel: true,
      confirmButtonText: 'ยืนยัน', reverseButtons: true
    }).then(function (r) {
      if (!r.isConfirmed) return;
      form.setAttribute('data-confirmed', '1');
      if (form.requestSubmit) { form.requestSubmit(btn || undefined); } else { form.submit(); }
    });
  }, true);

  // ---- หน้าต่าง "เกี่ยวกับระบบ / ผู้พัฒนา" ----
  document.addEventListener('click', function (e) {
    var t = e.target.closest && e.target.closest('[data-about]');
    var tpl = document.getElementById('about-template');
    if (!t || !tpl) return;
    e.preventDefault();
    Swal.fire({
      html: tpl.innerHTML, width: 760, showConfirmButton: false, showCloseButton: true, scrollbarPadding: false,
      customClass: { popup: 'about-popup', htmlContainer: 'about-body' }, closeButtonHtml: '&times;'
    });
  });
})();

/* ช่อง "บัญชี" ในฟอร์มรายการ: แสดงบัญชีธนาคารของวัดที่เลือก (data-bank-map = {รหัสวัด: [[รหัสบัญชี, ชื่อ], ...]}) */
(function () {
  'use strict';
  document.querySelectorAll('select[data-bank-map]').forEach(function (sel) {
    var form = sel.form, temple = form && form.elements[sel.getAttribute('data-temple-select')], map;
    try { map = JSON.parse(sel.getAttribute('data-bank-map')); } catch (e) { return; }
    if (!temple) return;
    function render(keep) {
      var want = keep || 'cash';
      sel.innerHTML = '';
      sel.add(new Option('เงินสด / เช็ค', 'cash'));
      (map[temple.value] || []).forEach(function (b) { sel.add(new Option(b[1], b[0])); });
      sel.value = Array.prototype.some.call(sel.options, function (o) { return o.value === want; }) ? want : 'cash';
    }
    temple.addEventListener('change', function () { render('cash'); });
    render(sel.getAttribute('data-selected'));
  });
})();

/* ช่อง "กองทุน/โครงการ" ในฟอร์มรายการ: แสดงกองทุนของวัดที่เลือก (data-fund-map = {รหัสวัด: [[รหัสกองทุน, ชื่อ], ...]}) */
(function () {
  'use strict';
  document.querySelectorAll('select[data-fund-map]').forEach(function (sel) {
    var form = sel.form, temple = form && form.elements[sel.getAttribute('data-temple-select')], map;
    try { map = JSON.parse(sel.getAttribute('data-fund-map')); } catch (e) { return; }
    if (!temple) return;
    function render(keep) {
      var want = keep || '0';
      sel.innerHTML = '';
      sel.add(new Option('เงินทั่วไป (ไม่ระบุกองทุน)', '0'));
      (map[temple.value] || []).forEach(function (f) { sel.add(new Option(f[1], f[0])); });
      sel.value = Array.prototype.some.call(sel.options, function (o) { return o.value === want; }) ? want : '0';
    }
    temple.addEventListener('change', function () { render('0'); });
    render(sel.getAttribute('data-selected'));
  });
})();

/* ช่อง "ทรัพย์สินที่ซื้อ/ซ่อม" ในฟอร์มรายจ่าย: แสดงทรัพย์สินของวัดที่เลือก (data-asset-map = {รหัสวัด: [[รหัสทรัพย์สิน, ชื่อ], ...]}) */
(function () {
  'use strict';
  document.querySelectorAll('select[data-asset-map]').forEach(function (sel) {
    var form = sel.form, temple = form && form.elements[sel.getAttribute('data-temple-select')], map;
    try { map = JSON.parse(sel.getAttribute('data-asset-map')); } catch (e) { return; }
    if (!temple) return;
    function render(keep) {
      var want = keep || '0';
      sel.innerHTML = '';
      sel.add(new Option('ไม่เกี่ยวกับทรัพย์สิน', '0'));
      (map[temple.value] || []).forEach(function (a) { sel.add(new Option(a[1], a[0])); });
      sel.value = Array.prototype.some.call(sel.options, function (o) { return o.value === want; }) ? want : '0';
    }
    temple.addEventListener('change', function () { render('0'); });
    render(sel.getAttribute('data-selected'));
  });
})();

/* QR code ตรวจสอบใบอนุโมทนา: <div data-qr="ลิงก์"> วาดเป็น SVG ด้วย qrcode.js (assets/vendor) — ลิงก์แบบสัมพัทธ์จะต่อกับโดเมนที่เปิดอยู่ */
(function () {
  'use strict';
  if (typeof qrcode === 'undefined') return;
  document.querySelectorAll('[data-qr]').forEach(function (el) {
    try {
      var url = new URL(el.getAttribute('data-qr'), location.href).href;
      var qr = qrcode(0, 'M');
      qr.addData(url);
      qr.make();
      el.innerHTML = qr.createSvgTag({ cellSize: 3, margin: 0, scalable: true });
      el.setAttribute('title', url);
    } catch (e) { /* ไม่มี QR ก็ยังมีรหัสตรวจสอบเป็นตัวอักษรใต้ภาพ */ }
  });
})();
