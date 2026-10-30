<?php
require __DIR__ . '/../includes/bootstrap.php';
require_admin();
$pageTitle = 'จัดการระบบ';
require __DIR__ . '/../includes/header.php';
?>
<h2>จัดการระบบ</h2>
<div class="grid grid-4">
  <a class="card feature" href="temples.php"><div class="ttl">จัดการวัด</div>เพิ่ม/แก้ไข/ปิดใช้งานวัด แยกตามอำเภอ</a>
  <a class="card feature" href="import.php"><div class="ttl">นำเข้ารายชื่อวัด (CSV)</div>โหลดทะเบียนวัดทั้งจังหวัดเข้าระบบ</a>
  <a class="card feature" href="users.php"><div class="ttl">ผู้ใช้งาน</div>ผู้ดูแล / เจ้าหน้าที่อำเภอ / เจ้าหน้าที่วัด</a>
  <a class="card feature" href="categories.php"><div class="ttl">หมวดรายรับ-รายจ่าย</div>กำหนดหมวดบัญชี</a>
  <a class="card feature" href="audit.php"><div class="ttl">ประวัติการใช้งาน</div>ตรวจสอบย้อนหลังว่าใครทำอะไร</a>
</div>
<?php require __DIR__ . '/../includes/footer.php'; ?>
