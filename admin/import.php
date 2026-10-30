<?php
require __DIR__ . '/../includes/bootstrap.php';
require_admin();
require __DIR__ . '/../includes/temple_import.php';
$pdo = db();
$result = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $f = $_FILES['csv'] ?? null;
    if (!$f || $f['error'] !== UPLOAD_ERR_OK || $f['size'] > 2 * 1024 * 1024) {
        flash('อัปโหลดไฟล์ไม่สำเร็จ (ขนาดไม่เกิน 2 MB)', 'err');
        redirect('import.php');
    }
    $result = import_temples_csv($pdo, $f['tmp_name'], isset($_POST['verified']), 'นำเข้า CSV');
    $ok = $result['ok'];
    $bad = $result['bad'];
    audit('import', 'temple', null, "ok=$ok bad=" . count($bad));
}

$pageTitle = 'นำเข้ารายชื่อวัด';
require __DIR__ . '/../includes/header.php';
?>
<h2>นำเข้ารายชื่อวัด (CSV)</h2>
<?php if ($result): ?>
  <div class="mb-4 rounded-lg px-4 py-2.5 bg-emerald-50 text-emerald-900">นำเข้า/อัปเดตสำเร็จ <?= $result['ok'] ?> วัด</div>
  <?php if ($result['bad']): ?><div class="mb-4 rounded-lg px-4 py-2.5 bg-red-50 text-red-900">ข้ามรายการที่ไม่ถูกต้อง <?= count($result['bad']) ?> บรรทัด:<br><?= e(implode(' | ', array_slice($result['bad'], 0, 20))) ?></div><?php endif; ?>
<?php endif; ?>
<div class="rounded-2xl border border-stone-200 bg-white p-4 shadow-sm">
  <p>ไฟล์ CSV (UTF-8) คอลัมน์ตามลำดับ: <code>อำเภอ, ชื่อวัด, ตำบล, หมู่ที่, เจ้าอาวาส, โทรศัพท์, รหัสวัด, ประเภทวัด, นิกาย</code> (สามคอลัมน์หลังไม่บังคับ)<br>
  ชื่ออำเภอต้องเป็นหนึ่งใน 8 อำเภอของจังหวัดแพร่ (เมืองแพร่, ร้องกวาง, ลอง, สูงเม่น, เด่นชัย, สอง, วังชิ้น, หนองม่วงไข่)
  วัดที่รหัสวัดซ้ำ หรือชื่อ+ตำบลซ้ำ จะถูกอัปเดตแทนการเพิ่มใหม่ ดูตัวอย่างที่ <code>data/temples_template.csv</code> (ทะเบียนที่นำเข้าแล้วอยู่ที่ <code>data/temples_phrae.csv</code>)</p>
  <form method="post" enctype="multipart/form-data" class="grid gap-3 sm:grid-cols-2 lg:grid-cols-3 [&_input:not([type=checkbox])]:w-full [&_select]:w-full">
    <?= csrf_field() ?>
    <div class="col-span-full"><input type="file" name="csv" accept=".csv,text/csv" required></div>
    <div class="col-span-full"><label><input type="checkbox" name="verified"> ไฟล์นี้มาจากทะเบียนวัดอย่างเป็นทางการ (ทำเครื่องหมาย "ตรวจสอบแล้ว")</label></div>
    <div class="col-span-full"><button class="btn">นำเข้า</button></div>
  </form>
</div>
<?php require __DIR__ . '/../includes/footer.php'; ?>
