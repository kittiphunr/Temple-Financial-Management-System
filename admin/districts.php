<?php
/** โลโก้ประจำอำเภอ — แอดมินหลักเป็นผู้ติดตามและใส่ให้แต่ละอำเภอ (ใช้เป็นหัวเอกสารระดับอำเภอ) */
require __DIR__ . '/../includes/bootstrap.php';
$me = require_admin();
$pdo = db();
require __DIR__ . '/../includes/temple_import.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    if (($_POST['action'] ?? '') === 'prune') {
        $gone = districts_prune_empty($pdo);
        audit('prune_districts', 'district', null, (string)count($gone));
        flash($gone ? 'ลบอำเภอที่ไม่มีวัดและไม่มีผู้ใช้แล้ว: ' . implode(', ', $gone) : 'ไม่มีอำเภอที่ว่างให้ลบ');
        redirect('districts.php');
    }
    $did = (int)($_POST['district_id'] ?? 0);
    $st = $pdo->prepare('SELECT id, name, logo_media_id FROM districts WHERE id = ?');
    $st->execute([$did]);
    $d = $st->fetch();
    if ($d) {
        if (($_POST['action'] ?? '') === 'logo_upload') {
            [$mid, $err] = media_save_upload($pdo, $_FILES['logo'] ?? null, 'logo', $me['id']);
            if ($err !== '') {
                flash($err, 'err');
            } elseif (!$mid) {
                flash('กรุณาเลือกไฟล์โลโก้ก่อน', 'err');
            } else {
                $pdo->prepare('UPDATE districts SET logo_media_id = ? WHERE id = ?')->execute([$mid, $did]);
                media_delete($pdo, $d['logo_media_id'] ? (int)$d['logo_media_id'] : null);
                audit('logo_upload', 'district', $did);
                flash('อัปโหลดโลโก้อำเภอ' . $d['name'] . 'แล้ว');
            }
        } elseif (($_POST['action'] ?? '') === 'logo_remove' && $d['logo_media_id']) {
            $pdo->prepare('UPDATE districts SET logo_media_id = NULL WHERE id = ?')->execute([$did]);
            media_delete($pdo, (int)$d['logo_media_id']);
            audit('logo_remove', 'district', $did);
            flash('ลบโลโก้อำเภอ' . $d['name'] . 'แล้ว');
        }
    }
    redirect('districts.php');
}

$rows = $pdo->query('SELECT id, name, logo_media_id FROM districts ORDER BY sort_order, id')->fetchAll();
$missing = count(array_filter($rows, fn($r) => !$r['logo_media_id']));
$pageTitle = 'โลโก้อำเภอ';
require __DIR__ . '/../includes/header.php';
?>
<h2>โลโก้อำเภอ</h2>
<p class="mb-3 text-sm text-stone-600">ติดตามโลโก้ของแต่ละอำเภอแล้วอัปโหลดที่นี่ (ใช้เป็นหัวเอกสารรายงานระดับอำเภอ) ·
  <?= $missing ? '<strong class="text-amber-700">ยังไม่มีโลโก้ ' . $missing . ' จาก ' . count($rows) . ' อำเภอ</strong>' : '<strong class="text-emerald-700">มีโลโก้ครบทุกอำเภอแล้ว</strong>' ?></p>
<?php $emptyCount = (int)$pdo->query('SELECT COUNT(*) FROM districts d WHERE NOT EXISTS (SELECT 1 FROM temples t WHERE t.district_id = d.id) AND NOT EXISTS (SELECT 1 FROM users u WHERE u.district_id = d.id)')->fetchColumn(); ?>
<?php if ($emptyCount): ?>
<form method="post" class="mb-3 flex flex-wrap items-center gap-3 rounded-xl border border-amber-300 bg-amber-50 px-4 py-3 text-sm text-amber-950" data-confirm="ลบอำเภอที่ไม่มีวัดและไม่มีผู้ใช้ทั้งหมด?">
  <?= csrf_field() ?><input type="hidden" name="action" value="prune">
  <span>มี <?= $emptyCount ?> อำเภอที่ยังไม่มีวัดและไม่มีผู้ใช้ (เช่น อำเภอตัวอย่างที่ติดมากับระบบ) — นับรวมในโควตาผู้ใช้ระดับจังหวัด</span>
  <button class="btn btn-sm btn-outline">ลบอำเภอที่ว่าง</button>
</form>
<?php endif; ?>
<div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
  <?php foreach ($rows as $r): ?>
  <section class="rounded-2xl border border-stone-200 bg-white p-4 shadow-sm">
    <h3>อำเภอ<?= e($r['name']) ?></h3>
    <?php if ($r['logo_media_id']): ?>
      <img class="mb-3 h-28 w-28 rounded-xl border border-stone-200 bg-white object-contain p-1" src="<?= e(media_url((int)$r['logo_media_id'])) ?>" alt="โลโก้อำเภอ<?= e($r['name']) ?>">
    <?php else: ?>
      <div class="mb-3 flex h-28 w-28 items-center justify-center rounded-xl border-2 border-dashed border-amber-400 bg-amber-50 text-center text-sm text-amber-800">ยังไม่มีโลโก้</div>
    <?php endif; ?>
    <form method="post" enctype="multipart/form-data" class="grid gap-2">
      <?= csrf_field() ?><input type="hidden" name="action" value="logo_upload"><input type="hidden" name="district_id" value="<?= (int)$r['id'] ?>">
      <input type="file" name="logo" accept="image/jpeg,image/png,image/webp" required class="max-w-full text-sm">
      <button class="btn btn-sm"><?= $r['logo_media_id'] ? 'เปลี่ยนโลโก้' : 'อัปโหลด' ?></button>
    </form>
    <?php if ($r['logo_media_id']): ?>
    <form method="post" class="mt-2" data-confirm="ลบโลโก้อำเภอนี้?">
      <?= csrf_field() ?><input type="hidden" name="action" value="logo_remove"><input type="hidden" name="district_id" value="<?= (int)$r['id'] ?>">
      <button class="text-sm text-red-700 underline">ลบโลโก้</button>
    </form>
    <?php endif; ?>
  </section>
  <?php endforeach; ?>
</div>
<?php require __DIR__ . '/../includes/footer.php'; ?>
