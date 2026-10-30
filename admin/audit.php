<?php
require __DIR__ . '/../includes/bootstrap.php';
require_admin();
$rows = db()->query('SELECT a.*, u.username FROM audit_log a LEFT JOIN users u ON u.id = a.user_id ORDER BY a.id DESC LIMIT 300')->fetchAll();
$pageTitle = 'ประวัติการใช้งาน';
require __DIR__ . '/../includes/header.php';
?>
<h2>ประวัติการใช้งาน (300 รายการล่าสุด)</h2>
<div class="overflow-x-auto rounded-2xl border border-stone-200 bg-white shadow-sm"><table class="data-table">
  <tr><th>เวลา</th><th>ผู้ใช้</th><th>การกระทำ</th><th>เรื่อง</th><th>รหัส</th><th>รายละเอียด</th><th>IP</th></tr>
  <?php foreach ($rows as $r): ?>
  <tr><td class="whitespace-nowrap"><?= e(th_datetime($r['created_at'])) ?></td><td><?= e($r['username'] ?? '-') ?></td><td><?= e($r['action']) ?></td><td><?= e($r['entity']) ?></td><td><?= e($r['entity_id']) ?></td><td><?= e($r['detail']) ?></td><td><?= e($r['ip']) ?></td></tr>
  <?php endforeach; ?>
</table></div>
<?php require __DIR__ . '/../includes/footer.php'; ?>
