<?php
require __DIR__ . '/../includes/bootstrap.php';
require_admin();
backup_auto_maybe(db());   // สำรองอัตโนมัติถ้าเปิดไว้และถึงกำหนด
$pageTitle = 'จัดการระบบ';
require __DIR__ . '/../includes/header.php';
?>
<h2>จัดการระบบ</h2>
<div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
  <a class="card block text-stone-700 no-underline transition hover:border-gold-500 hover:shadow-md" href="temples.php"><div class="font-bold text-brand-700"><?= icon('home') ?> จัดการวัด</div>เพิ่ม/แก้ไข/ปิดใช้งานวัด แยกตาม<?= e(unit_district()) ?></a>
  <a class="card block text-stone-700 no-underline transition hover:border-gold-500 hover:shadow-md" href="districts.php"><div class="font-bold text-brand-700"><?= icon('image') ?> โลโก้<?= e(unit_district()) ?></div>ติดตามและอัปโหลดโลโก้ของแต่ละ<?= e(unit_district()) ?> (ใช้เป็นหัวเอกสาร)</a>
  <a class="card block text-stone-700 no-underline transition hover:border-gold-500 hover:shadow-md" href="import.php"><div class="font-bold text-brand-700"><?= icon('upload') ?> นำเข้ารายชื่อวัด (Excel / CSV)</div>โหลดทะเบียนวัดทั้งจังหวัดเข้าระบบ จากไฟล์ของ binfo.onab.go.th</a>
  <a class="card block text-stone-700 no-underline transition hover:border-gold-500 hover:shadow-md" href="temple_accounts.php"><div class="font-bold text-brand-700"><?= icon('key') ?> บัญชีวัด (อีเมล / รหัสผ่าน)</div>สร้างบัญชีอัตโนมัติ 1 วัด 1 บัญชี ลงอีเมลวัดสำหรับยืนยันตัวตน 2 ชั้น</a>
  <a class="card block text-stone-700 no-underline transition hover:border-gold-500 hover:shadow-md" href="users.php"><div class="font-bold text-brand-700"><?= icon('shield') ?> ผู้ใช้ระดับจังหวัด/<?= e(unit_district()) ?></div>แอดมินหลัก · เจ้าคณะจังหวัด · แอดมินประจำ<?= e(unit_district()) ?> (จำกัดโควตา)</a>
  <a class="card block text-stone-700 no-underline transition hover:border-gold-500 hover:shadow-md" href="subdistricts.php"><div class="font-bold text-brand-700"><?= icon('map') ?> เจ้าคณะ<?= e(unit_subdistrict()) ?> (ดูข้อมูลอย่างเดียว)</div>สร้างบัญชีให้เจ้าคณะ<?= e(unit_subdistrict()) ?>ดูวัดใน<?= e(unit_subdistrict()) ?>ที่ดูแล (ไม่นับโควตา)</a>
  <a class="card block text-stone-700 no-underline transition hover:border-gold-500 hover:shadow-md" href="categories.php"><div class="font-bold text-brand-700"><?= icon('tag') ?> หมวดรายรับ-รายจ่าย</div>กำหนดหมวดบัญชี</a>
  <a class="card block text-stone-700 no-underline transition hover:border-gold-500 hover:shadow-md" href="backup.php"><div class="font-bold text-brand-700"><?= icon('database') ?> สำรองข้อมูล</div>ดาวน์โหลดไฟล์สำรอง ตั้งสำรองอัตโนมัติ สำรองกุญแจระบบ</a>
  <a class="card block text-stone-700 no-underline transition hover:border-gold-500 hover:shadow-md" href="audit.php"><div class="font-bold text-brand-700"><?= icon('activity') ?> ประวัติการใช้งาน</div>ตรวจสอบย้อนหลังว่าใครทำอะไร</a>
</div>
<?php require __DIR__ . '/../includes/footer.php'; ?>
