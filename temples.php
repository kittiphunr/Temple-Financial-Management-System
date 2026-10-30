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
<h2>ข้อมูลวัดจังหวัดแพร่ แยกตามอำเภอ</h2>
<p class="muted">ข้อมูลจากทะเบียนวัดจังหวัดแพร่ ป้าย <span class="badge">รอตรวจสอบ</span> หมายถึงวัดที่เพิ่มเองและยังไม่ได้เทียบกับทะเบียนทางการ</p>
<?php foreach ($districts as $dn): $list = $groups[$dn] ?? []; ?>
  <h3 class="district-title">อำเภอ<?= e($dn) ?> <small class="muted">(<?= count($list) ?> วัด)</small></h3>
  <?php if (!$list): ?>
    <p class="muted">ยังไม่มีข้อมูลวัดของอำเภอนี้ในระบบ</p>
  <?php else: ?>
  <div class="table-wrap"><table>
    <tr><th>ชื่อวัด</th><th>ตำบล</th><th>หมู่</th><th>ประเภท / นิกาย</th><th>รหัสวัด</th><th>สถานะข้อมูล</th></tr>
    <?php foreach ($list as $r): ?>
    <tr>
      <td><?= e($r['name']) ?></td><td><?= e($r['subdistrict'] ?? '-') ?></td>
      <td><?= e($r['moo'] ?? '-') ?></td><td><?= e(trim(($r['temple_type'] ?? '') . ' / ' . ($r['sect'] ?? ''), ' /') ?: '-') ?></td><td><?= e($r['temple_code'] ?? '-') ?></td>
      <td><?= $r['verified'] ? '<span class="badge ok">ตรวจสอบแล้ว</span>' : '<span class="badge">รอตรวจสอบ</span>' ?></td>
    </tr>
    <?php endforeach; ?>
  </table></div>
  <?php endif; ?>
<?php endforeach; ?>
<?php require __DIR__ . '/includes/footer.php'; ?>
