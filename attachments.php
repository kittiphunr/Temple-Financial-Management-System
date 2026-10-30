<?php
require __DIR__ . '/includes/bootstrap.php';
$u = require_login();
$pdo = db();
if (!attachments_available()) {
    flash('ยังไม่ได้เปิดใช้การแนบหลักฐาน (ผู้ดูแลต้องรัน sql/migrations/2026_attachments.sql)', 'err');
    redirect('dashboard.php');
}
$txnId = (int)($_GET['txn'] ?? $_POST['txn'] ?? 0);
$st = $pdo->prepare('SELECT x.id, x.temple_id, x.type, x.txn_date, x.amount, x.description, x.doc_no, c.name AS category, t.name AS temple
    FROM transactions x JOIN temples t ON t.id = x.temple_id JOIN categories c ON c.id = x.category_id
    WHERE x.id = ? AND x.is_deleted = 0 AND x.transfer_id IS NULL');
$st->execute([$txnId]);
$x = $st->fetch();
if (!$x || !attachment_can_view($pdo, (int)$x['temple_id'])) {
    http_response_code(404);
    exit('ไม่พบรายการ');
}
$tid = (int)$x['temple_id'];
$canEdit = can_access_temple($tid);
$lock = period_lock_error($pdo, $tid, $x['txn_date']);
$self = 'attachments.php?txn=' . $txnId;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    if (!$canEdit) {
        http_response_code(403);
        exit('ไม่มีสิทธิ์แนบหลักฐานของวัดนี้');
    }
    if ($lock !== '') {
        flash($lock, 'err');
        redirect($self);
    }
    $action = $_POST['action'] ?? '';
    if ($action === 'upload') {
        $err = attachment_save($pdo, $txnId, $tid, $_FILES['file'] ?? null, (int)$u['id']);
        $err === '' ? flash('แนบหลักฐานแล้ว') : flash($err, 'err');
    } elseif ($action === 'delete') {
        $aid = (int)($_POST['aid'] ?? 0);
        $del = $pdo->prepare('DELETE FROM transaction_attachments WHERE id = ? AND transaction_id = ?');
        $del->execute([$aid, $txnId]);
        if ($del->rowCount()) {
            audit('detach', 'transaction', $txnId, 'attachment ' . $aid);
            flash('ลบไฟล์แนบแล้ว');
        }
    }
    redirect($self);
}

$list = attachment_list($pdo, $txnId);
$back = in_array($u['role'], ['province', 'subdistrict'], true) ? 'dashboard.php' : 'transactions.php?type=' . $x['type'];
$pageTitle = 'หลักฐานแนบรายการ';
require __DIR__ . '/includes/header.php';
?>
<div class="flex flex-wrap items-center justify-between gap-2"><h2>หลักฐานแนบรายการ</h2>
  <a class="btn btn-outline btn-sm" href="<?= e($back) ?>">กลับ</a></div>
<div class="rounded-2xl border border-stone-200 bg-white p-4 shadow-sm">
  <p><b><?= $x['type'] === 'income' ? 'รายรับ' : 'รายจ่าย' ?></b> · <?= e($x['temple']) ?> · <?= e(th_date($x['txn_date'])) ?> · <?= e($x['category']) ?> · <?= money($x['amount']) ?> บาท</p>
  <?php if ($x['description'] !== ''): ?><p class="text-stone-600"><?= e($x['description']) ?></p><?php endif; ?>
</div>
<div class="mt-4 rounded-2xl border border-stone-200 bg-white p-4 shadow-sm">
  <h3>ไฟล์แนบ (<?= count($list) ?>/<?= ATT_MAX_PER_TXN ?>)</h3>
  <?php if (!$list): ?><p class="text-stone-500">ยังไม่มีไฟล์แนบ</p><?php endif; ?>
  <ul class="divide-y divide-stone-100">
  <?php foreach ($list as $a): $pdf = $a['mime'] === 'application/pdf'; ?>
    <li class="flex flex-wrap items-center justify-between gap-2 py-2">
      <span><?= $pdf ? '📄' : '🖼️' ?> <a class="underline" href="attachment.php?id=<?= (int)$a['id'] ?>" <?= $pdf ? '' : 'target="_blank" rel="noopener"' ?>><?= e($a['filename']) ?></a>
        <small class="text-stone-500">(<?= number_format($a['size'] / 1024, 0) ?> KB · <?= e($a['created_at']) ?>)</small></span>
      <?php if ($canEdit && $lock === ''): ?>
      <form method="post" data-confirm="ยืนยันการลบไฟล์แนบนี้?"><?= csrf_field() ?><input type="hidden" name="action" value="delete"><input type="hidden" name="txn" value="<?= $txnId ?>"><input type="hidden" name="aid" value="<?= (int)$a['id'] ?>"><button class="btn btn-sm btn-danger">ลบ</button></form>
      <?php endif; ?>
    </li>
    <?php if (!$pdf): ?><li class="py-2"><img class="max-h-96 rounded border border-stone-200" alt="<?= e($a['filename']) ?>" src="attachment.php?id=<?= (int)$a['id'] ?>"></li><?php endif; ?>
  <?php endforeach; ?>
  </ul>
  <?php if ($canEdit && $lock !== ''): ?><p class="mt-3 text-amber-700">🔒 <?= e($lock) ?></p>
  <?php elseif ($canEdit && count($list) < ATT_MAX_PER_TXN): ?>
  <form method="post" enctype="multipart/form-data" class="mt-3 flex flex-wrap items-end gap-3">
    <?= csrf_field() ?><input type="hidden" name="action" value="upload"><input type="hidden" name="txn" value="<?= $txnId ?>">
    <div><label>เลือกไฟล์ (ภาพ JPEG/PNG/WebP หรือ PDF ไม่เกิน 4 MB)</label><input type="file" name="file" accept="image/jpeg,image/png,image/webp,application/pdf" required></div>
    <button class="btn" type="submit">แนบไฟล์</button>
  </form>
  <p class="mt-2 text-sm text-stone-500">ภาพจะถูกแปลงใหม่และลบข้อมูลแฝง (ตำแหน่ง/กล้อง) ก่อนเก็บ — สลิปมีข้อมูลส่วนบุคคล เปิดดูได้เฉพาะผู้มีสิทธิ์ของวัดนี้</p>
  <?php endif; ?>
</div>
<?php require __DIR__ . '/includes/footer.php'; ?>
