<?php
/** ทะเบียนทรัพย์สินของวัด: เพิ่ม/แก้ไข/จำหน่าย รูป รายจ่ายที่ซื้อ-ซ่อม พิมพ์ทะเบียนและส่งออก CSV */
require __DIR__ . '/includes/bootstrap.php';
$u = require_login();
$pdo = db();
if (!asset_available()) {
    flash('ยังไม่ได้เปิดใช้ทะเบียนทรัพย์สิน (ผู้ดูแลต้องรัน sql/migrations/2026_assets.sql)', 'err');
    redirect('dashboard.php');
}

$tid = $u['role'] === 'temple' ? (int)$u['temple_id'] : (int)($_GET['temple'] ?? $_POST['temple'] ?? 0);
$temple = $tid && people_can_view($tid) ? temple_row($tid) : null;
$canEdit = $temple && can_access_temple($tid);
$tq = $u['role'] === 'temple' ? '' : 'temple=' . $tid;
$url = fn(array $extra = []) => 'assets.php' . ($tq || $extra ? '?' . http_build_query(array_merge($tq ? ['temple' => $tid] : [], $extra)) : '');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    if (!$canEdit) {
        http_response_code(403);
        exit('ไม่มีสิทธิ์จัดการทรัพย์สินของวัดนี้');
    }
    $act = $_POST['action'] ?? '';
    $aid = (int)($_POST['asset_id'] ?? 0);
    if ($act === 'save') {
        $cur = $aid ? asset_get($pdo, $aid, $tid) : null;
        if ($aid && !$cur) {
            flash('ไม่พบทรัพย์สิน', 'err');
            redirect($url());
        }
        [$newMedia, $imgErr] = media_save_upload($pdo, $_FILES['photo'] ?? null, 'photo', (int)$u['id']);
        if ($imgErr !== '') {
            flash($imgErr, 'err');
            redirect($url($aid ? ['edit' => $aid] : []));
        }
        $remove = !empty($_POST['remove_photo']);
        $photo = $newMedia ?: ($remove ? 0 : null);       // null = ไม่แตะรูปเดิม · 0 = ลบรูป
        [$id, $err] = asset_save($pdo, $tid, $aid, $_POST, $photo === 0 ? null : $photo, (int)$u['id']);
        if ($err !== '') {
            media_delete($pdo, $newMedia);
            flash($err, 'err');
            redirect($url($aid ? ['edit' => $aid] : []));
        }
        if ($cur && $cur['photo_media_id'] && ($newMedia || $remove)) {
            if ($remove && !$newMedia) {
                $pdo->prepare('UPDATE assets SET photo_media_id = NULL WHERE id = ?')->execute([$id]);
            }
            media_delete($pdo, (int)$cur['photo_media_id']);
        }
        flash($aid ? 'บันทึกทรัพย์สินแล้ว' : 'เพิ่มทรัพย์สินเข้าทะเบียนแล้ว');
        redirect($url(['asset' => $id]));
    }
    if ($act === 'dispose' && ($cur = asset_get($pdo, $aid, $tid)) && $cur['status'] !== 'disposed') {
        $d = date_be_parse($_POST, 'disposed_date');
        $note = mb_substr(trim($_POST['disposed_note'] ?? ''), 0, 255);
        if ($d === null || mb_strlen($note) < 3) {
            flash('กรุณาระบุวันที่จำหน่ายและเหตุผล (อย่างน้อย 3 ตัวอักษร)', 'err');
        } else {
            $pdo->prepare("UPDATE assets SET status='disposed', disposed_date=?, disposed_note=? WHERE id=? AND temple_id=?")->execute([$d, $note, $aid, $tid]);
            audit('dispose', 'asset', $aid, $note);
            flash('จำหน่ายทรัพย์สินออกจากทะเบียนแล้ว (ข้อมูลยังเก็บไว้)');
        }
        redirect($url(['asset' => $aid]));
    }
    if ($act === 'restore' && ($cur = asset_get($pdo, $aid, $tid)) && $cur['status'] === 'disposed') {
        $pdo->prepare("UPDATE assets SET status='good', disposed_date=NULL, disposed_note=NULL WHERE id=? AND temple_id=?")->execute([$aid, $tid]);
        audit('restore', 'asset', $aid);
        flash('ยกเลิกการจำหน่ายแล้ว');
        redirect($url(['asset' => $aid]));
    }
    redirect($url());
}

$filters = ['category' => (string)($_GET['category'] ?? ''), 'status' => (string)($_GET['status'] ?? ''), 'q' => trim((string)($_GET['q'] ?? ''))];
$assets = $temple ? asset_list($pdo, $tid, $filters) : [];

// ส่งออก CSV (UTF-8 BOM) ป้องกัน formula injection
if ($temple && ($_GET['export'] ?? '') === 'csv') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="assets-' . $tid . '.csv"');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF");
    fputcsv($out, ['เลขทะเบียน', 'ชื่อทรัพย์สิน', 'ประเภท', 'วันที่ได้มา', 'ได้มาโดย', 'มูลค่า', 'สภาพ', 'สถานที่', 'ผู้รับผิดชอบ', 'รายละเอียด', 'ค่าซ่อมสะสม']);
    $safe = fn($v) => is_string($v) && preg_match('/^[=+\-@\t\r]/', $v) ? "'" . $v : $v;
    foreach ($assets as $a) {
        fputcsv($out, array_map($safe, [$a['asset_no'], $a['name'], ASSET_CATEGORIES[$a['category']], $a['acquired_date'] ? th_date_short($a['acquired_date']) : '', $a['acquired_how'], $a['value'], ASSET_STATUS[$a['status']], $a['location'], $a['holder'], $a['detail'], $a['repair_cost']]));
    }
    audit('export', 'assets', $tid);
    exit;
}

$detail = $temple && !empty($_GET['asset']) ? asset_get($pdo, (int)$_GET['asset'], $tid) : null;
$edit = $temple && $canEdit && !empty($_GET['edit']) ? asset_get($pdo, (int)$_GET['edit'], $tid) : null;
$showForm = $canEdit && !$detail && (isset($_GET['new']) || $edit);
$groups = temples_grouped();
$sumByCat = [];
foreach ($assets as $a) {
    if ($a['status'] !== 'disposed') {
        $sumByCat[$a['category']] = ($sumByCat[$a['category']] ?? ['n' => 0, 'v' => 0.0]);
        $sumByCat[$a['category']]['n']++;
        $sumByCat[$a['category']]['v'] += (float)$a['value'];
    }
}

$pageTitle = 'ทะเบียนทรัพย์สิน';
require __DIR__ . '/includes/header.php';
?>
<?php if ($detail): $txns = asset_transactions($pdo, (int)$detail['id']); ?>
<div class="flex flex-wrap items-center justify-between gap-2"><h2><?= e($detail['name']) ?> · <?= e($temple['name']) ?></h2>
  <span class="flex gap-2"><?php if ($canEdit): ?><a class="btn btn-outline btn-sm" href="<?= e($url(['edit' => (int)$detail['id']])) ?>"><?= icon('edit-2') ?> แก้ไข</a><?php endif; ?><a class="btn btn-outline btn-sm" href="<?= e($url()) ?>"><?= icon('arrow-left') ?> กลับ</a></span></div>
<div class="grid gap-4 lg:grid-cols-[1fr_16rem]">
  <section class="rounded-2xl border border-stone-200 bg-white p-4 shadow-sm">
    <dl class="grid gap-x-6 gap-y-2 sm:grid-cols-2">
      <?php foreach ([['เลขทะเบียน', $detail['asset_no'] ?: '-'], ['ประเภท', ASSET_CATEGORIES[$detail['category']]], ['วันที่ได้มา', $detail['acquired_date'] ? th_date($detail['acquired_date']) : '-'], ['ได้มาโดย', $detail['acquired_how'] ?: '-'],
          ['มูลค่า/ราคาทุน', money($detail['value']) . ' บาท'], ['สภาพ', ASSET_STATUS[$detail['status']]], ['สถานที่', $detail['location'] ?: '-'], ['ผู้รับผิดชอบ', $detail['holder'] ?: '-']] as [$k, $v]): ?>
      <div><dt class="text-xs text-stone-500"><?= e($k) ?></dt><dd><?= e($v) ?></dd></div>
      <?php endforeach; ?>
      <div class="sm:col-span-2"><dt class="text-xs text-stone-500">รายละเอียด</dt><dd><?= e($detail['detail'] ?: '-') ?></dd></div>
    </dl>
    <?php if ($detail['status'] === 'disposed'): ?>
      <p class="mt-3 rounded-lg border border-stone-300 bg-stone-50 p-3">จำหน่ายแล้วเมื่อ <?= e(th_date($detail['disposed_date'])) ?> — <?= e($detail['disposed_note']) ?></p>
      <?php if ($canEdit): ?><form method="post" class="mt-2" data-confirm="ยกเลิกการจำหน่ายทรัพย์สินนี้?"><?= csrf_field() ?><input type="hidden" name="action" value="restore"><input type="hidden" name="asset_id" value="<?= (int)$detail['id'] ?>"><input type="hidden" name="temple" value="<?= $tid ?>"><button class="btn btn-outline btn-sm">ยกเลิกการจำหน่าย</button></form><?php endif; ?>
    <?php elseif ($canEdit): ?>
      <details class="mt-3"><summary class="cursor-pointer text-sm text-brand-700">จำหน่ายทรัพย์สินออกจากทะเบียน</summary>
        <form method="post" class="mt-2 grid gap-2 sm:grid-cols-2 [&_input]:w-full" data-confirm="จำหน่ายทรัพย์สินนี้ออกจากทะเบียน?">
          <?= csrf_field() ?><input type="hidden" name="action" value="dispose"><input type="hidden" name="asset_id" value="<?= (int)$detail['id'] ?>"><input type="hidden" name="temple" value="<?= $tid ?>">
          <div><label>วันที่จำหน่าย</label><?= date_be_field('disposed_date', null, 3, 0) ?></div>
          <div><label>เหตุผล/วิธีจำหน่าย *</label><input name="disposed_note" maxlength="255" required></div>
          <div class="col-span-full"><button class="btn btn-danger btn-sm">จำหน่าย</button></div>
        </form></details>
    <?php endif; ?>
  </section>
  <aside><?php if ($detail['photo_media_id']): ?><img class="w-full rounded-2xl border border-stone-200" src="<?= e(media_url((int)$detail['photo_media_id'])) ?>" alt="รูปทรัพย์สิน"><?php else: ?><div class="rounded-2xl border border-dashed border-stone-300 p-6 text-center text-stone-400">ไม่มีรูป</div><?php endif; ?></aside>
</div>
<h3 class="mt-4">รายจ่ายที่ซื้อ/ซ่อมทรัพย์สินนี้</h3>
<div class="overflow-x-auto rounded-2xl border border-stone-200 bg-white shadow-sm"><table class="data-table">
  <tr><th>วันที่</th><th>ประเภท</th><th>หมวด</th><th>รายละเอียด</th><th class="text-right">จำนวนเงิน</th></tr>
  <?php foreach ($txns as $x): ?><tr><td class="whitespace-nowrap"><?= e(th_date($x['txn_date'])) ?></td><td><?= e(ASSET_KINDS[$x['asset_kind']] ?? '-') ?></td><td><?= e($x['category']) ?></td><td><?= e($x['description']) ?></td><td class="text-right"><?= money($x['amount']) ?></td></tr><?php endforeach; ?>
  <?php if (!$txns): ?><tr><td colspan="5" class="text-stone-500">ยังไม่มีรายจ่ายที่ผูกไว้ — เลือกทรัพย์สินที่ช่อง "ทรัพย์สินที่ซื้อ/ซ่อม" ตอนบันทึกรายจ่าย</td></tr><?php endif; ?>
  <tr><th colspan="4" class="text-right">รวมซื้อ <?= money($detail['purchase_cost']) ?> · ซ่อมบำรุง <?= money($detail['repair_cost']) ?></th><th class="text-right"><?= money($detail['purchase_cost'] + $detail['repair_cost']) ?></th></tr>
</table></div>
<?php elseif ($showForm): $v = $edit ?? []; ?>
<div class="flex flex-wrap items-center justify-between gap-2"><h2><?= $edit ? 'แก้ไขทรัพย์สิน' : 'เพิ่มทรัพย์สิน' ?> · <?= e($temple['name']) ?></h2><a class="btn btn-outline btn-sm" href="<?= e($url()) ?>">ยกเลิก</a></div>
<form method="post" enctype="multipart/form-data" class="grid gap-3 rounded-2xl border border-stone-200 bg-white p-4 shadow-sm sm:grid-cols-2 [&_input:not([type=checkbox]):not([type=file])]:w-full [&_select]:w-full">
  <?= csrf_field() ?><input type="hidden" name="action" value="save"><input type="hidden" name="asset_id" value="<?= (int)($edit['id'] ?? 0) ?>"><input type="hidden" name="temple" value="<?= $tid ?>">
  <div class="col-span-full"><label>ชื่อทรัพย์สิน *</label><input name="name" required maxlength="200" value="<?= e($v['name'] ?? '') ?>" placeholder="เช่น อุโบสถ, รถกระบะ, เครื่องเสียง"></div>
  <div><label>ประเภท *</label><select name="category"><?php foreach (ASSET_CATEGORIES as $k => $lab): ?><option value="<?= $k ?>" <?= ($v['category'] ?? 'equipment') === $k ? 'selected' : '' ?>><?= e($lab) ?></option><?php endforeach; ?></select></div>
  <div><label>เลขทะเบียนทรัพย์สิน (ไม่ซ้ำในวัด)</label><input name="asset_no" maxlength="30" value="<?= e($v['asset_no'] ?? '') ?>"></div>
  <div><label>วันที่ได้มา</label><?= date_be_field('acquired_date', $v['acquired_date'] ?? null, 100, 0, true) ?></div>
  <div><label>ได้มาโดย</label><input name="acquired_how" maxlength="100" value="<?= e($v['acquired_how'] ?? '') ?>" placeholder="เช่น ซื้อ, ผู้มีจิตศรัทธาถวาย"></div>
  <div><label>มูลค่า/ราคาทุน (บาท)</label><input name="value" inputmode="decimal" value="<?= $edit ? e(number_format((float)$v['value'], 2, '.', '')) : '' ?>"></div>
  <div><label>สภาพ</label><select name="status"><?php foreach (ASSET_STATUS as $k => $lab): if ($k === 'disposed') continue; ?><option value="<?= $k ?>" <?= ($v['status'] ?? 'good') === $k ? 'selected' : '' ?>><?= e($lab) ?></option><?php endforeach; ?></select></div>
  <div><label>สถานที่เก็บ/ตั้งอยู่</label><input name="location" maxlength="150" value="<?= e($v['location'] ?? '') ?>"></div>
  <div><label>ผู้รับผิดชอบดูแล</label><input name="holder" maxlength="150" value="<?= e($v['holder'] ?? '') ?>"></div>
  <div class="col-span-full"><label>รายละเอียด (เลขโฉนด/เนื้อที่ ทะเบียนรถ ยี่ห้อรุ่น ฯลฯ)</label><input name="detail" maxlength="500" value="<?= e($v['detail'] ?? '') ?>"></div>
  <div class="col-span-full"><label>รูปทรัพย์สิน (JPEG/PNG/WebP ไม่เกิน 2 MB)</label><input type="file" name="photo" accept="image/jpeg,image/png,image/webp">
    <?php if (!empty($v['photo_media_id'])): ?><label class="mt-1 block text-sm"><input type="checkbox" name="remove_photo" value="1"> ลบรูปเดิม</label><?php endif; ?></div>
  <div class="col-span-full"><button class="btn" type="submit">บันทึก</button></div>
</form>
<?php else: ?>
<div class="flex flex-wrap items-center justify-between gap-2 print:hidden"><h2>ทะเบียนทรัพย์สิน<?= $temple ? ' · ' . e($temple['name']) : '' ?></h2>
  <?php if ($canEdit): ?><a class="btn btn-sm" href="<?= e($url(['new' => 1])) ?>"><?= icon('plus') ?> เพิ่มทรัพย์สิน</a><?php endif; ?></div>
<?php if ($u['role'] !== 'temple'): ?>
<form method="get" class="my-3 flex flex-wrap items-end gap-3 print:hidden">
  <div><label>วัด</label><select name="temple" onchange="this.form.submit()"><option value="0">— เลือกวัด —</option>
    <?php foreach ($groups as $dn => $list): ?><optgroup label="<?= e(unit_district()) ?><?= e($dn) ?>"><?php foreach ($list as $t): ?><option value="<?= (int)$t['id'] ?>" <?= $t['id'] == $tid ? 'selected' : '' ?>><?= e($t['name']) ?></option><?php endforeach; ?></optgroup><?php endforeach; ?></select></div>
  <noscript><button class="btn">แสดง</button></noscript>
</form>
<?php endif; ?>
<?php if (!$temple): ?>
  <p class="text-stone-600">เลือกวัดเพื่อดูทะเบียนทรัพย์สิน</p>
<?php else: ?>
<?= letterhead($pdo, $tid, 0, 'ทะเบียนทรัพย์สิน', 'ข้อมูล ณ วันที่ ' . th_date(date('Y-m-d')), true) ?>
<form method="get" class="my-3 flex flex-wrap items-end gap-3 print:hidden">
  <?php if ($u['role'] !== 'temple'): ?><input type="hidden" name="temple" value="<?= $tid ?>"><?php endif; ?>
  <div><label>ประเภท</label><select name="category"><option value="">ทั้งหมด</option><?php foreach (ASSET_CATEGORIES as $k => $lab): ?><option value="<?= $k ?>" <?= $filters['category'] === $k ? 'selected' : '' ?>><?= e($lab) ?></option><?php endforeach; ?></select></div>
  <div><label>สภาพ</label><select name="status"><option value="">ทั้งหมด</option><?php foreach (ASSET_STATUS as $k => $lab): ?><option value="<?= $k ?>" <?= $filters['status'] === $k ? 'selected' : '' ?>><?= e($lab) ?></option><?php endforeach; ?></select></div>
  <div><label>ค้นหา</label><input name="q" value="<?= e($filters['q']) ?>" placeholder="ชื่อ/เลขทะเบียน/ผู้ดูแล"></div>
  <button class="btn" type="submit">แสดง</button>
  <a class="btn btn-outline" href="<?= e($url(array_filter($filters) + ['export' => 'csv'])) ?>"><?= icon('download') ?> ส่งออก CSV</a>
  <button class="btn btn-outline" type="button" onclick="window.print()"><?= icon('printer') ?> พิมพ์ทะเบียน</button>
</form>
<?php if ($sumByCat): ?><div class="mb-3 flex flex-wrap gap-2 text-sm"><?php foreach ($sumByCat as $k => $s): ?><span class="rounded-lg border border-stone-200 bg-white px-3 py-1"><?= e(ASSET_CATEGORIES[$k]) ?> <strong><?= $s['n'] ?></strong> รายการ · <?= money($s['v']) ?> บาท</span><?php endforeach; ?>
  <span class="rounded-lg border border-gold-500 bg-white px-3 py-1">รวม <strong><?= array_sum(array_column($sumByCat, 'n')) ?></strong> รายการ · <?= money(array_sum(array_column($sumByCat, 'v'))) ?> บาท</span></div><?php endif; ?>
<div class="overflow-x-auto rounded-2xl border border-stone-200 bg-white shadow-sm print:border-0 print:shadow-none"><table class="data-table">
  <tr><th>เลขทะเบียน</th><th>ทรัพย์สิน</th><th>ประเภท</th><th>วันที่ได้มา</th><th class="text-right">มูลค่า</th><th>สภาพ</th><th>ผู้ดูแล/สถานที่</th><th class="text-right print:hidden">ค่าซ่อมสะสม</th></tr>
  <?php foreach ($assets as $a): ?>
  <tr class="<?= $a['status'] === 'disposed' ? 'opacity-60' : '' ?>">
    <td class="font-mono text-sm"><?= e($a['asset_no'] ?: '-') ?></td>
    <td><a class="underline" href="<?= e($url(['asset' => (int)$a['id']])) ?>"><?= e($a['name']) ?></a><?= $a['detail'] ? '<br><small class="text-stone-500">' . e(mb_strimwidth($a['detail'], 0, 80, '…')) . '</small>' : '' ?></td>
    <td><?= e(ASSET_CATEGORIES[$a['category']]) ?></td><td class="whitespace-nowrap"><?= $a['acquired_date'] ? e(th_date($a['acquired_date'])) : '-' ?></td>
    <td class="text-right"><?= money($a['value']) ?></td>
    <td><span class="badge <?= $a['status'] === 'poor' ? 'badge-err' : ($a['status'] === 'disposed' ? 'badge-mute' : '') ?>"><?= e(ASSET_STATUS[$a['status']]) ?></span></td>
    <td class="text-sm"><?= e($a['holder'] ?: '-') ?><?= $a['location'] ? '<br><span class="text-stone-500">' . e($a['location']) . '</span>' : '' ?></td>
    <td class="text-right print:hidden"><?= $a['repair_cost'] > 0 ? money($a['repair_cost']) : '' ?></td>
  </tr>
  <?php endforeach; ?>
  <?php if (!$assets): ?><tr><td colspan="8" class="text-stone-500">ยังไม่มีทรัพย์สินในทะเบียน<?= array_filter($filters) ? ' (ตามตัวกรองที่เลือก)' : '' ?></td></tr><?php endif; ?>
</table></div>
<?= signature_block($pdo, $tid) ?>
<?php endif; ?>
<?php endif; ?>
<?php require __DIR__ . '/includes/footer.php'; ?>
