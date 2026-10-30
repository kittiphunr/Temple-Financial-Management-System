<?php
/** กองทุน/โครงการของวัด: สร้าง แก้ไข เปิด/ปิด และดูยอดรับ-จ่าย-คงเหลือ รายละเอียดรายการของแต่ละกองทุน */
require __DIR__ . '/includes/bootstrap.php';
$u = require_login();
$pdo = db();
if (!fund_available()) {
    flash('ยังไม่ได้เปิดใช้กองทุน/โครงการ (ผู้ดูแลต้องรัน sql/migrations/2026_funds.sql)', 'err');
    redirect('dashboard.php');
}

$tid = $u['role'] === 'temple' ? (int)$u['temple_id'] : (int)($_GET['temple'] ?? $_POST['temple'] ?? 0);
$temple = $tid && people_can_view($tid) ? temple_row($tid) : null;
$canEdit = $temple && can_access_temple($tid);
$tq = $u['role'] === 'temple' ? '' : 'temple=' . $tid;
$self = 'funds.php' . ($tq ? '?' . $tq : '');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    if (!$canEdit) {
        http_response_code(403);
        exit('ไม่มีสิทธิ์จัดการกองทุนของวัดนี้');
    }
    $act = $_POST['action'] ?? '';
    $fid = (int)($_POST['fund_id'] ?? 0);
    if ($act === 'save') {
        $name = mb_substr(trim($_POST['name'] ?? ''), 0, 150);
        $purpose = mb_substr(trim($_POST['purpose'] ?? ''), 0, 255);
        $rawT = trim(str_replace(',', '', (string)($_POST['target_amount'] ?? '')));
        $target = $rawT === '' ? null : (is_numeric($rawT) ? round((float)$rawT, 2) : false);
        $dup = $pdo->prepare('SELECT id FROM funds WHERE temple_id = ? AND name = ? AND id <> ?');
        $dup->execute([$tid, $name, $fid]);
        if ($name === '') {
            flash('กรุณาระบุชื่อกองทุน/โครงการ', 'err');
        } elseif ($target === false || ($target !== null && ($target <= 0 || $target > 999999999999))) {
            flash('เป้ายอดต้องเป็นตัวเลขมากกว่า 0 (เว้นว่างถ้าไม่ตั้งเป้า)', 'err');
        } elseif ($dup->fetchColumn()) {
            flash('มีกองทุน/โครงการชื่อนี้อยู่แล้ว', 'err');
        } elseif ($fid) {
            if (fund_get($pdo, $fid, $tid)) {
                $pdo->prepare('UPDATE funds SET name=?, purpose=?, target_amount=? WHERE id=? AND temple_id=?')
                    ->execute([$name, $purpose ?: null, $target, $fid, $tid]);
                audit('update', 'fund', $fid);
                flash('บันทึกกองทุน/โครงการแล้ว');
            }
        } else {
            $pdo->prepare('INSERT INTO funds (temple_id, name, purpose, target_amount, created_by, created_at) VALUES (?,?,?,?,?,NOW())')
                ->execute([$tid, $name, $purpose ?: null, $target, (int)$u['id']]);
            audit('create', 'fund', (int)$pdo->lastInsertId());
            flash('สร้างกองทุน/โครงการแล้ว');
        }
    } elseif ($act === 'toggle' && fund_get($pdo, $fid, $tid)) {
        $pdo->prepare('UPDATE funds SET is_active = 1 - is_active WHERE id = ? AND temple_id = ?')->execute([$fid, $tid]);
        audit('toggle', 'fund', $fid);
        flash('เปลี่ยนสถานะกองทุน/โครงการแล้ว (กองทุนที่ปิดยังแสดงรายการเดิม แต่เลือกบันทึกรายการใหม่ไม่ได้)');
    }
    redirect($self);
}

$funds = $temple ? fund_list($pdo, $tid) : [];
$detailId = (int)($_GET['fund'] ?? 0);
$detail = $detailId && $temple ? fund_get($pdo, $detailId, $tid) : null;
$editId = (int)($_GET['edit'] ?? 0);
$edit = $editId && $canEdit ? fund_get($pdo, $editId, $tid) : null;
$groups = temples_grouped();

$pageTitle = 'กองทุน/โครงการ';
require __DIR__ . '/includes/header.php';
?>
<?php if ($detail): $txns = fund_transactions($pdo, (int)$detail['id']); $run = 0.0; ?>
<?= letterhead($pdo, $tid, 0, 'รายงานกองทุน/โครงการ', $detail['name'], true) ?>
<div class="flex flex-wrap items-center justify-between gap-2 print:hidden"><h2><?= e($detail['name']) ?> · <?= e($temple['name']) ?></h2>
  <span class="flex gap-2"><button class="btn btn-outline btn-sm" type="button" onclick="window.print()">พิมพ์</button><a class="btn btn-outline btn-sm" href="<?= e($self) ?>">กลับ</a></span></div>
<?php if ($detail['purpose']): ?><p class="text-stone-600"><?= e($detail['purpose']) ?></p><?php endif; ?>
<div class="grid gap-3 sm:grid-cols-3">
  <div class="rounded-2xl border border-stone-200 bg-white p-4 shadow-sm"><div class="text-sm text-stone-500">รับสะสม<?= $detail['target_amount'] ? ' (เป้า ' . money($detail['target_amount']) . ')' : '' ?></div><div class="text-2xl font-bold text-emerald-700"><?= money($detail['raised']) ?></div></div>
  <div class="rounded-2xl border border-stone-200 bg-white p-4 shadow-sm"><div class="text-sm text-stone-500">ใช้ไปสะสม</div><div class="text-2xl font-bold text-red-700"><?= money($detail['spent']) ?></div></div>
  <div class="rounded-2xl border border-stone-200 bg-white p-4 shadow-sm"><div class="text-sm text-stone-500">คงเหลือของกองทุน</div><div class="text-2xl font-bold <?= $detail['balance'] < 0 ? 'text-red-700' : 'text-sky-800' ?>"><?= money($detail['balance']) ?></div></div>
</div>
<div class="mt-4 overflow-x-auto rounded-2xl border border-stone-200 bg-white shadow-sm"><table class="data-table">
  <tr><th>วันที่</th><th>หมวด</th><th>รายละเอียด</th><th class="text-right">รับ</th><th class="text-right">จ่าย</th><th class="text-right">คงเหลือ</th></tr>
  <?php foreach ($txns as $x): $run += $x['type'] === 'income' ? (float)$x['amount'] : -(float)$x['amount']; ?>
  <tr><td class="whitespace-nowrap"><?= e(th_date($x['txn_date'])) ?></td><td><?= e($x['category']) ?></td><td><?= e($x['description']) ?></td>
    <td class="text-right text-emerald-700"><?= $x['type'] === 'income' ? money($x['amount']) : '' ?></td><td class="text-right text-red-700"><?= $x['type'] === 'expense' ? money($x['amount']) : '' ?></td><td class="text-right"><?= money($run) ?></td></tr>
  <?php endforeach; ?>
  <?php if (!$txns): ?><tr><td colspan="6" class="text-stone-500">ยังไม่มีรายการ — เลือกกองทุนนี้ที่ช่อง "กองทุน/โครงการ" เมื่อบันทึกรายรับ-รายจ่าย</td></tr><?php endif; ?>
</table></div>
<?= signature_block($pdo, $tid) ?>
<?php else: ?>
<div class="flex flex-wrap items-center justify-between gap-2"><h2>กองทุน/โครงการ<?= $temple ? ' · ' . e($temple['name']) : '' ?></h2></div>
<?php if ($u['role'] !== 'temple'): ?>
<form method="get" class="my-3 flex flex-wrap items-end gap-3">
  <div><label>วัด</label><select name="temple" onchange="this.form.submit()"><option value="0">— เลือกวัด —</option>
    <?php foreach ($groups as $dn => $list): ?><optgroup label="<?= e(unit_district()) ?><?= e($dn) ?>"><?php foreach ($list as $t): ?><option value="<?= (int)$t['id'] ?>" <?= $t['id'] == $tid ? 'selected' : '' ?>><?= e($t['name']) ?></option><?php endforeach; ?></optgroup><?php endforeach; ?></select></div>
  <noscript><button class="btn">แสดง</button></noscript>
</form>
<?php endif; ?>
<?php if (!$temple): ?>
  <p class="text-stone-600">เลือกวัดเพื่อดูกองทุน/โครงการ</p>
<?php else: ?>
<p class="mb-3 text-sm text-stone-600">แยกเงินตามวัตถุประสงค์ เช่น กฐิน ผ้าป่าสามัคคี สร้างอาคาร กองทุนการศึกษา — เลือกกองทุนที่ช่อง "กองทุน/โครงการ" ตอนบันทึกรายรับ-รายจ่าย ระบบสรุปรับ/ใช้/คงเหลือของแต่ละกองทุน (ไม่นับรายการโอน/ฝาก-ถอน) และชื่อกองทุนจะเป็นวัตถุประสงค์เริ่มต้นบนอนุโมทนาบัตร</p>
<div class="overflow-x-auto rounded-2xl border border-stone-200 bg-white shadow-sm"><table class="data-table">
  <tr><th>กองทุน/โครงการ</th><th class="text-right">เป้ายอด</th><th class="text-right">รับสะสม</th><th class="text-right">ใช้ไป</th><th class="text-right">คงเหลือ</th><th>ความคืบหน้า</th><th></th></tr>
  <?php foreach ($funds as $f): ?>
  <tr class="<?= $f['is_active'] ? '' : 'opacity-60' ?>">
    <td><a class="underline" href="funds.php?<?= e(trim($tq . '&fund=' . (int)$f['id'], '&')) ?>"><?= e($f['name']) ?></a><?= $f['is_active'] ? '' : ' <span class="badge">ปิดแล้ว</span>' ?><?= $f['purpose'] ? '<br><small class="text-stone-500">' . e($f['purpose']) . '</small>' : '' ?></td>
    <td class="text-right"><?= $f['target_amount'] ? money($f['target_amount']) : '-' ?></td>
    <td class="text-right text-emerald-700"><?= money($f['raised']) ?></td><td class="text-right text-red-700"><?= money($f['spent']) ?></td>
    <td class="text-right font-semibold <?= $f['balance'] < 0 ? 'text-red-700' : '' ?>"><?= money($f['balance']) ?></td>
    <td><?php if ($f['pct'] !== null): ?><div class="flex items-center gap-2"><div class="h-2 w-24 overflow-hidden rounded bg-stone-200"><div class="h-2 bg-emerald-600" style="width:<?= min(100, round($f['pct'])) ?>%"></div></div><span class="text-sm"><?= number_format($f['pct'], 0) ?>%</span></div><?php endif; ?></td>
    <td class="whitespace-nowrap"><?php if ($canEdit): ?><a class="btn btn-sm btn-outline" href="funds.php?<?= e(trim($tq . '&edit=' . (int)$f['id'], '&')) ?>">แก้ไข</a>
      <form method="post" class="inline"><?= csrf_field() ?><input type="hidden" name="action" value="toggle"><input type="hidden" name="fund_id" value="<?= (int)$f['id'] ?>"><input type="hidden" name="temple" value="<?= $tid ?>"><button class="btn btn-sm btn-outline"><?= $f['is_active'] ? 'ปิด' : 'เปิด' ?></button></form><?php endif; ?></td>
  </tr>
  <?php endforeach; ?>
  <?php if (!$funds): ?><tr><td colspan="7" class="text-stone-500">ยังไม่มีกองทุน/โครงการ</td></tr><?php endif; ?>
</table></div>

<?php if ($canEdit): ?>
<section class="mt-4 rounded-2xl border border-stone-200 bg-white p-4 shadow-sm">
  <h3><?= $edit ? 'แก้ไขกองทุน/โครงการ' : 'สร้างกองทุน/โครงการ' ?></h3>
  <form method="post" class="grid gap-3 sm:grid-cols-2 [&_input:not([type=checkbox])]:w-full">
    <?= csrf_field() ?><input type="hidden" name="action" value="save"><input type="hidden" name="temple" value="<?= $tid ?>"><input type="hidden" name="fund_id" value="<?= (int)($edit['id'] ?? 0) ?>">
    <div><label>ชื่อกองทุน/โครงการ *</label><input name="name" required maxlength="150" value="<?= e($edit['name'] ?? '') ?>" placeholder="เช่น กฐินสามัคคี"></div>
    <div><label>เป้ายอด (บาท) เว้นว่าง = ไม่ตั้งเป้า</label><input name="target_amount" inputmode="decimal" value="<?= $edit && $edit['target_amount'] ? e(number_format($edit['target_amount'], 2, '.', '')) : '' ?>"></div>
    <div class="col-span-full"><label>วัตถุประสงค์/รายละเอียด</label><input name="purpose" maxlength="255" value="<?= e($edit['purpose'] ?? '') ?>"></div>
    <div class="col-span-full"><button class="btn" type="submit">บันทึก</button><?php if ($edit): ?> <a class="btn btn-outline" href="<?= e($self) ?>">ยกเลิก</a><?php endif; ?></div>
  </form>
</section>
<?php endif; ?>
<?php endif; ?>
<?php endif; ?>
<?php require __DIR__ . '/includes/footer.php'; ?>
