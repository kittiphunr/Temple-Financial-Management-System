<?php
require __DIR__ . '/../includes/bootstrap.php';
require_admin();
$pageTitle = 'จัดการระบบ';
require __DIR__ . '/../includes/header.php';
?>
<h2>จัดการระบบ</h2>
<div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
  <a class="rounded-2xl border border-stone-200 bg-white p-4 shadow-sm block no-underline transition hover:border-gold-500 hover:shadow-md" href="temples.php"><div class="font-bold text-brand-700">จัดการวัด</div>เพิ่ม/แก้ไข/ปิดใช้งานวัด แยกตามอำเภอ</a>
  <a class="rounded-2xl border border-stone-200 bg-white p-4 shadow-sm block no-underline transition hover:border-gold-500 hover:shadow-md" href="districts.php"><div class="font-bold text-brand-700">โลโก้อำเภอ</div>ติดตามและอัปโหลดโลโก้ของแต่ละอำเภอ (ใช้เป็นหัวเอกสาร)</a>
  <a class="rounded-2xl border border-stone-200 bg-white p-4 shadow-sm block no-underline transition hover:border-gold-500 hover:shadow-md" href="import.php"><div class="font-bold text-brand-700">นำเข้ารายชื่อวัด (Excel / CSV)</div>โหลดทะเบียนวัดทั้งจังหวัดเข้าระบบ จากไฟล์ของ binfo.onab.go.th</a>
  <a class="rounded-2xl border border-stone-200 bg-white p-4 shadow-sm block no-underline transition hover:border-gold-500 hover:shadow-md" href="temple_accounts.php"><div class="font-bold text-brand-700">บัญชีวัด (อีเมล / รหัสผ่าน)</div>สร้างบัญชีอัตโนมัติ 1 วัด 1 บัญชี ลงอีเมลวัดสำหรับยืนยันตัวตน 2 ชั้น</a>
  <a class="rounded-2xl border border-stone-200 bg-white p-4 shadow-sm block no-underline transition hover:border-gold-500 hover:shadow-md" href="users.php"><div class="font-bold text-brand-700">ผู้ใช้ระดับจังหวัด/อำเภอ</div>แอดมินหลัก · เจ้าคณะจังหวัด · แอดมินประจำอำเภอ (จำกัดโควตา)</a>
  <a class="rounded-2xl border border-stone-200 bg-white p-4 shadow-sm block no-underline transition hover:border-gold-500 hover:shadow-md" href="categories.php"><div class="font-bold text-brand-700">หมวดรายรับ-รายจ่าย</div>กำหนดหมวดบัญชี</a>
  <a class="rounded-2xl border border-stone-200 bg-white p-4 shadow-sm block no-underline transition hover:border-gold-500 hover:shadow-md" href="audit.php"><div class="font-bold text-brand-700">ประวัติการใช้งาน</div>ตรวจสอบย้อนหลังว่าใครทำอะไร</a>
</div>
<?php require __DIR__ . '/../includes/footer.php'; ?>
