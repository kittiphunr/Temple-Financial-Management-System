<?php
/** กระทบยอดบัญชีธนาคารรายเดือน: ติ๊กรายการที่ขึ้นใน statement แล้วเทียบยอดสิ้นเดือน */
require __DIR__ . '/includes/bootstrap.php';
$u = require_login();
$pdo = db();
if (!reconcile_available()) {
    flash('ยังไม่ได้เปิดใช้การกระทบยอด (ผู้ดูแลต้องรัน sql/migrations/2026_bank_reconcile.sql)', 'err');
    redirect('dashboard.php');
}

$tid = $u['role'] === 'temple' ? (int)$u['temple_id'] : (int)($_GET['temple'] ?? $_POST['temple'] ?? 0);
$temple = $tid && people_can_view($tid) ? temple_row($tid) : null;
$canEdit = $temple && can_access_temple($tid);
$banks = $temple ? array_values(array_filter(ledger_accounts($pdo, $tid), fn($a) => $a['kind'] === 'bank')) : [];
$aid = (int)($_GET['account'] ?? $_POST['account'] ?? ($banks[0]['id'] ?? 0));
$acc = null;
foreach ($banks as $b) {
    if ((int)$b['id'] === $aid) $acc = $b;
}
$prev = strtotime('first day of last month');
$year = (int)($_GET['year'] ?? $_POST['year'] ?? date('Y', $prev) + 543);
$month = (int)($_GET['month'] ?? $_POST['month'] ?? date('n', $prev));
if ($month < 1 || $month > 12 || $year < 2500 || $year > 2700) {
    $year = (int)date('Y', $prev) + 543;
    $month = (int)date('n', $prev);
}
$q = '?' . http_build_query(array_filter(['temple' => $u['role'] === 'temple' ? 0 : $tid, 'account' => $aid, 'year' => $year, 'month' => $month]));
$self = 'reconcile.php' . $q;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    if (!$canEdit || !$acc) {
        http_response_code(403);
        exit('ไม่มีสิทธิ์กระทบยอดบัญชีนี้');
    }
    $raw = trim(str_replace(',', '', (string)($_POST['statement_balance'] ?? '')));
    if ($raw === '' || !is_numeric($raw) || abs((float)$raw) > 999999999999) {
        flash('กรุณากรอกยอดคงเหลือตาม statement เป็นตัวเลข', 'err');
    } else {
        $err = reconcile_save($pdo, $acc, $year, $month, round((float)$raw, 2), (array)($_POST['cleared'] ?? []), mb_substr(trim($_POST['note'] ?? ''), 0, 255), (int)$u['id']);
        $err === '' ? flash('บันทึกผลกระทบยอดแล้ว') : flash($err, 'err');
    }
    redirect($self);
}

$saved = $acc ? reconcile_row($pdo, (int)$acc['id'], $year, $month) : null;
$recItems = $acc ? reconcile_items($pdo, $acc, $year, $month) : [];
$ym = $year * 100 + $month;
$endDate = reconcile_month_end($year, $month);
$ended = $endDate <= date('Y-m-d');
$stmtVal = $saved ? (float)$saved['statement_balance'] : null;
$calc = $acc ? reconcile_compute($pdo, $acc, $year, $month, $stmtVal ?? 0.0) : null;
$stale = $saved && $acc && !reconcile_is_current($pdo, $acc, $saved);
$hist = [];
if ($acc) {
    $hs = $pdo->prepare('SELECT * FROM bank_reconciliations WHERE account_id = ? ORDER BY recon_year DESC, recon_month DESC LIMIT 12');
    $hs->execute([(int)$acc['id']]);
    $hist = $hs->fetchAll();
}
$groups = temples_grouped();

$pageTitle = 'กระทบยอดบัญชีธนาคาร';
require __DIR__ . '/includes/header.php';
?>
<div class="flex flex-wrap items-center justify-between gap-2"><h2>กระทบยอดบัญชีธนาคาร<?= $temple ? ' · ' . e($temple['name']) : '' ?></h2>
  <?php if ($temple): ?><a class="btn btn-outline btn-sm" href="accounts.php<?= $u['role'] === 'temple' ? '' : '?temple=' . $tid ?>">บัญชีเงินสด/ธนาคาร</a><?php endif; ?></div>

<form method="get" class="my-4 flex flex-wrap items-end gap-3">
  <?php if ($u['role'] !== 'temple'): ?>
  <div><label>วัด</label><select name="temple" onchange="this.form.submit()"><option value="0">— เลือกวัด —</option>
    <?php foreach ($groups as $dn => $list): ?><optgroup label="<?= e(unit_district()) ?><?= e($dn) ?>"><?php foreach ($list as $t): ?><option value="<?= (int)$t['id'] ?>" <?= $t['id'] == $tid ? 'selected' : '' ?>><?= e($t['name']) ?></option><?php endforeach; ?></optgroup><?php endforeach; ?></select></div>
  <?php endif; ?>
  <?php if ($banks): ?>
  <div><label>บัญชี</label><select name="account"><?php foreach ($banks as $b): ?><option value="<?= (int)$b['id'] ?>" <?= $b['id'] == $aid ? 'selected' : '' ?>><?= e(ledger_account_label($b)) ?></option><?php endforeach; ?></select></div>
  <div><label>เดือน</label><select name="month"><?php for ($m = 1; $m <= 12; $m++): ?><option value="<?= $m ?>" <?= $m === $month ? 'selected' : '' ?>><?= e(TH_MONTHS[$m]) ?></option><?php endfor; ?></select></div>
  <div><label>ปี พ.ศ.</label><select name="year"><?php for ($y = be_year() + 1; $y >= be_year() - 6; $y--): ?><option <?= $y === $year ? 'selected' : '' ?>><?= $y ?></option><?php endfor; ?></select></div>
  <button class="btn" type="submit">แสดง</button>
  <?php endif; ?>
</form>

<?php if (!$temple): ?>
  <p class="text-stone-600">เลือกวัดเพื่อกระทบยอด</p>
<?php elseif (!$banks): ?>
  <p class="text-stone-600">วัดนี้ยังไม่มีบัญชีธนาคาร — เพิ่มได้ที่หน้า <a class="underline" href="accounts.php<?= $u['role'] === 'temple' ? '' : '?temple=' . $tid ?>">บัญชีเงินสด/ธนาคาร</a></p>
<?php elseif ($acc): ?>
<p class="mb-4 text-sm text-stone-600">ติ๊กรายการที่ <strong>ปรากฏใน statement/สมุดบัญชีธนาคารแล้ว</strong> ส่วนรายการที่ไม่ติ๊กจะถือเป็นรายการค้าง (เงินฝากระหว่างทาง หรือเช็ค/รายจ่ายที่ธนาคารยังไม่หัก) จากนั้นกรอกยอดคงเหลือสิ้นเดือนตาม statement ระบบจะบอกว่าตรงกันหรือไม่ — ถ้าไม่ตรง ให้ตรวจว่าบันทึกรายการผิดจำนวน ขาดรายการ (เช่น ดอกเบี้ย ค่าธรรมเนียม) หรือซ้ำ</p>
<?php if ($stale): ?><div class="mb-4 rounded-lg border border-amber-300 bg-amber-50 p-3 text-amber-900"><?= icon('alert-triangle') ?> มีการแก้ไขรายการหลังบันทึกผลกระทบยอดครั้งนี้ ยอดด้านล่างคำนวณใหม่จากข้อมูลปัจจุบัน กรุณากระทบยอดและบันทึกอีกครั้ง</div><?php endif; ?>

<form method="post" class="card">
  <?= csrf_field() ?><input type="hidden" name="temple" value="<?= $tid ?>"><input type="hidden" name="account" value="<?= (int)$acc['id'] ?>"><input type="hidden" name="year" value="<?= $year ?>"><input type="hidden" name="month" value="<?= $month ?>">
  <h3>เดือน<?= e(TH_MONTHS[$month]) ?> <?= $year ?> · <?= e(ledger_account_label($acc)) ?></h3>
  <div class="overflow-x-auto"><table class="data-table">
    <tr><th>ผ่านรายการแล้ว</th><th>วันที่</th><th>รายการ</th><th class="text-right">เงินเข้า</th><th class="text-right">เงินออก</th></tr>
    <?php foreach ($recItems as $it): $on = reconcile_is_cleared($it, $year, $month); $later = $it['cleared_ym'] !== null && (int)$it['cleared_ym'] > $ym; ?>
    <tr>
      <td><?php if ($later): ?><small class="text-stone-500">ผ่านในเดือนถัดไป</small>
        <?php else: ?><input type="checkbox" name="cleared[]" value="<?= (int)$it['id'] ?>" <?= $on ? 'checked' : '' ?> <?= $canEdit && $ended ? '' : 'disabled' ?>><?php endif; ?></td>
      <td class="whitespace-nowrap"><?= e(th_date($it['txn_date'])) ?></td>
      <td><?= e($it['category']) ?><?= $it['description'] !== '' ? ' · ' . e($it['description']) : '' ?><?= $it['doc_no'] !== '' ? ' <small class="text-stone-500">(' . e($it['doc_no']) . ')</small>' : '' ?></td>
      <td class="text-right whitespace-nowrap text-emerald-700"><?= $it['type'] === 'income' ? money($it['amount']) : '' ?></td>
      <td class="text-right whitespace-nowrap text-red-700"><?= $it['type'] === 'expense' ? money($it['amount']) : '' ?></td>
    </tr>
    <?php endforeach; ?>
    <?php if (!$recItems): ?><tr><td colspan="5" class="text-stone-500">ไม่มีรายการค้างหรือรายการในเดือนนี้</td></tr><?php endif; ?>
  </table></div>

  <dl class="mt-4 grid gap-2 sm:grid-cols-2 lg:grid-cols-4">
    <div><dt class="text-xs text-stone-500">ยอดตามสมุดวัด สิ้นเดือน</dt><dd class="text-lg font-bold"><?= money($calc['book']) ?></dd></div>
    <div><dt class="text-xs text-stone-500">หัก เงินฝากระหว่างทาง</dt><dd class="text-lg"><?= money($calc['out_in']) ?></dd></div>
    <div><dt class="text-xs text-stone-500">บวก รายจ่ายที่ธนาคารยังไม่หัก</dt><dd class="text-lg"><?= money($calc['out_exp']) ?></dd></div>
    <div><dt class="text-xs text-stone-500">ยอดที่ statement ควรเป็น</dt><dd class="text-lg font-bold"><?= money($calc['expected']) ?></dd></div>
  </dl>

  <div class="mt-4 grid gap-3 sm:grid-cols-2 [&_input]:w-full">
    <div><label>ยอดคงเหลือตาม statement สิ้นเดือน (บาท)</label><input name="statement_balance" inputmode="decimal" required value="<?= $stmtVal !== null ? e(number_format($stmtVal, 2, '.', '')) : '' ?>" <?= $canEdit && $ended ? '' : 'disabled' ?>></div>
    <div><label>หมายเหตุ (ถ้ามี)</label><input name="note" maxlength="255" value="<?= e($saved['note'] ?? '') ?>" <?= $canEdit && $ended ? '' : 'disabled' ?>></div>
  </div>
  <?php if ($saved): $ok = abs((float)$saved['difference']) < 0.005; ?>
    <p class="mt-3 <?= $ok ? 'text-emerald-700' : 'text-red-700' ?>"><?= $ok ? icon('check-circle') . ' ตรงกัน' : icon('x-circle') . ' ไม่ตรงกัน ส่วนต่าง ' . money($saved['difference']) . ' บาท' ?>
      <small class="text-stone-500">· บันทึกโดย <?= e($saved['by_name'] ?? '-') ?> เมื่อ <?= e($saved['reconciled_at']) ?></small></p>
  <?php endif; ?>
  <?php if (!$ended): ?><p class="mt-3 text-amber-800">ยังไม่สิ้นเดือน — กระทบยอดได้หลังสิ้นเดือนที่เลือก</p>
  <?php elseif ($canEdit): ?><button class="btn mt-3" type="submit">บันทึกผลกระทบยอด</button>
  <?php else: ?><p class="mt-3 text-sm text-stone-500">ดูได้อย่างเดียว</p><?php endif; ?>
</form>

<?php if ($hist): ?>
<section class="card-table"><table class="data-table">
  <tr><th>เดือน</th><th class="text-right">ยอด statement</th><th class="text-right">ยอดสมุดวัด</th><th class="text-right">ส่วนต่าง</th><th>ผล</th></tr>
  <?php foreach ($hist as $h): $ok = abs((float)$h['difference']) < 0.005; ?>
  <tr><td><a class="underline" href="reconcile.php?<?= e(http_build_query(array_filter(['temple' => $u['role'] === 'temple' ? 0 : $tid, 'account' => $aid, 'year' => $h['recon_year'], 'month' => $h['recon_month']]))) ?>"><?= e(TH_MONTHS[(int)$h['recon_month']]) ?> <?= (int)$h['recon_year'] ?></a></td>
    <td class="text-right"><?= money($h['statement_balance']) ?></td><td class="text-right"><?= money($h['book_balance']) ?></td><td class="text-right"><?= money($h['difference']) ?></td>
    <td><?= $ok ? '<span class="badge">' . icon('check-circle') . ' ตรงกัน</span>' : '<span class="badge badge-err">' . icon('x-circle') . ' ไม่ตรง</span>' ?></td></tr>
  <?php endforeach; ?>
</table></section>
<?php endif; ?>
<?php endif; ?>
<?php require __DIR__ . '/includes/footer.php'; ?>
