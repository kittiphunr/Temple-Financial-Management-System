<?php
require __DIR__ . '/includes/bootstrap.php';

// ข้อมูลวัดเป็นข้อมูลสาธารณะ (ไม่มีข้อมูลการเงิน) ผู้เยี่ยมชมเห็นทุกวัด
$st = db()->query("SELECT t.*, d.name AS district_name FROM temples t
    JOIN districts d ON d.id = t.district_id WHERE t.is_active = 1
    ORDER BY d.sort_order, t.subdistrict, t.name");
$groups = [];
foreach ($st->fetchAll() as $r) {
    $groups[$r['district_name']][] = $r;
}
$districts = db()->query('SELECT name FROM districts ORDER BY sort_order')->fetchAll(PDO::FETCH_COLUMN);

$pageTitle = 'ข้อมูลวัด';
require __DIR__ . '/includes/header.php';
?>
<h2>ข้อมูลวัดจังหวัด<?= e(province_name()) ?> แยกตามอำเภอ</h2>
<p class="text-stone-500">ข้อมูลจากทะเบียนวัดจังหวัด<?= e(province_name()) ?> ป้าย <span class="badge">รอตรวจสอบ</span> หมายถึงวัดที่เพิ่มเองและยังไม่ได้เทียบกับทะเบียนทางการ</p>
<?php foreach ($districts as $dn): $list = $groups[$dn] ?? []; ?>
  <h3 class="mb-2 mt-7 rounded-md border-l-4 border-gold-500 bg-gold-100 px-3 py-1.5 !text-lg !font-semibold">อำเภอ<?= e($dn) ?> <small class="text-stone-500">(<?= count($list) ?> วัด)</small></h3>
  <?php if (!$list): ?>
    <p class="text-stone-500">ยังไม่มีข้อมูลวัดของอำเภอนี้ในระบบ</p>
  <?php else: ?>
  <div class="overflow-x-auto rounded-2xl border border-stone-200 bg-white shadow-sm"><table class="data-table">
    <tr><th>ชื่อวัด</th><th>ตำบล</th><th>หมู่</th><th>ประเภท / นิกาย</th><th>รหัสวัด</th><th>สถานะข้อมูล</th></tr>
    <?php foreach ($list as $r): ?>
    <tr>
      <td><?= e($r['name']) ?></td><td><?= e($r['subdistrict'] ?? '-') ?></td>
      <td><?= e($r['moo'] ?? '-') ?></td><td><?= e(trim(($r['temple_type'] ?? '') . ' / ' . ($r['sect'] ?? ''), ' /') ?: '-') ?></td><td><?= e($r['temple_code'] ?? '-') ?></td>
      <td><?= $r['verified'] ? '<span class="badge badge-ok">ตรวจสอบแล้ว</span>' : '<span class="badge">รอตรวจสอบ</span>' ?></td>
    </tr>
    <?php endforeach; ?>
  </table></div>
  <?php endif; ?>
<?php endforeach; ?>
<?php require __DIR__ . '/includes/footer.php'; ?>
