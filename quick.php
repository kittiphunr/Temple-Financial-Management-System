<?php
/** บันทึกรายรับ-รายจ่ายด่วน (ออกแบบสำหรับมือถือ): ฟอร์มสั้น ปุ่มใหญ่ ใช้ตอนรับ/จ่ายเงินหน้างาน — บันทึกผ่านตัวเดียวกับหน้าบัญชี (transactions.php) */
require __DIR__ . '/includes/bootstrap.php';
$u = require_login();
if (!in_array($u['role'], ['admin', 'district', 'temple'], true)) {   // เจ้าคณะจังหวัด/ตำบลดูข้อมูลได้อย่างเดียว
    redirect('dashboard.php');
}
$pdo = db();
$tid = $u['role'] === 'temple' ? (int)$u['temple_id'] : (int)($_GET['temple'] ?? 0);
$temple = $tid && can_access_temple($tid) ? temple_row($tid) : null;
$groups = temples_grouped();

$cats = ['income' => [], 'expense' => []];
foreach ($pdo->query('SELECT id, type, name FROM categories WHERE is_active = 1 AND is_transfer = 0 ORDER BY sort_order, name')->fetchAll() as $c) {
    $cats[$c['type']][] = $c;
}
$banks = [];
$funds = [];
$recent = [];
if ($temple) {
    foreach (ledger_accounts($pdo, $tid, true) as $a) {
        if ($a['kind'] === 'bank') $banks[] = $a;
    }
    $funds = fund_available() ? fund_list($pdo, $tid, true) : [];
    $st = $pdo->prepare('SELECT x.id, x.type, x.amount, x.txn_date, x.description, c.name AS category FROM transactions x JOIN categories c ON c.id = x.category_id
        WHERE x.temple_id = ? AND x.is_deleted = 0 AND x.transfer_id IS NULL ORDER BY x.id DESC LIMIT 5');
    $st->execute([$tid]);
    $recent = $st->fetchAll();
}
$qType = ($_GET['type'] ?? '') === 'expense' ? 'expense' : 'income';

$pageTitle = 'บันทึกด่วน';
require __DIR__ . '/includes/header.php';
?>
<div class="mx-auto max-w-lg">
<h2>บันทึกรายรับ-รายจ่ายด่วน<?= $temple ? '<span class="block text-base font-normal text-stone-600">' . e($temple['name']) . '</span>' : '' ?></h2>
<?php if ($u['role'] !== 'temple'): ?>
<form method="get" class="form-lg mb-4">
  <label>วัด</label><select name="temple" onchange="this.form.submit()" class="w-full text-lg"><option value="0">— เลือกวัด —</option>
    <?php foreach ($groups as $dn => $list): ?><optgroup label="<?= e(unit_district()) ?><?= e($dn) ?>"><?php foreach ($list as $t): ?><option value="<?= (int)$t['id'] ?>" <?= $t['id'] == $tid ? 'selected' : '' ?>><?= e($t['name']) ?></option><?php endforeach; ?></optgroup><?php endforeach; ?></select>
  <noscript><button class="btn mt-2">แสดง</button></noscript>
</form>
<?php endif; ?>

<?php if (!$temple): ?>
  <p class="text-stone-600">เลือกวัดเพื่อบันทึกรายการ</p>
<?php else: ?>
<form method="post" action="transactions.php?type=<?= $qType ?>" id="quick-form" class="card form-lg space-y-4 [&_input:not([type=radio])]:w-full [&_select]:w-full">
  <fieldset class="grid grid-cols-2 gap-3" aria-label="ประเภทรายการ">
    <label class="flex min-h-14 cursor-pointer items-center justify-center rounded-xl border-2 border-emerald-600 text-lg font-bold text-emerald-700 has-[:checked]:bg-emerald-600 has-[:checked]:text-white"><input type="radio" name="kind" value="income" class="sr-only" <?= $qType === 'income' ? 'checked' : '' ?>>รายรับ</label>
    <label class="flex min-h-14 cursor-pointer items-center justify-center rounded-xl border-2 border-red-600 text-lg font-bold text-red-700 has-[:checked]:bg-red-600 has-[:checked]:text-white"><input type="radio" name="kind" value="expense" class="sr-only" <?= $qType === 'expense' ? 'checked' : '' ?>>รายจ่าย</label>
  </fieldset>
  <div><label for="q-amount" class="text-lg">จำนวนเงิน (บาท)</label><input id="q-amount" name="amount" inputmode="decimal" required autofocus placeholder="0.00" class="text-right text-2xl font-bold"></div>
  <div><label for="q-cat" class="text-lg">หมวด</label><select id="q-cat" name="category_id" required>
    <?php foreach (['income', 'expense'] as $t): foreach ($cats[$t] as $c): ?><option value="<?= (int)$c['id'] ?>" data-type="<?= $t ?>"><?= e($c['name']) ?></option><?php endforeach; endforeach; ?></select></div>
  <div><label for="q-desc" class="text-lg">รายละเอียด (ไม่บังคับ)</label><input id="q-desc" name="description" maxlength="255" autocomplete="off"></div>
  <?php if ($banks): ?><div><label for="q-acc" class="text-lg">เข้า/ออกบัญชี</label><select id="q-acc" name="account_id"><option value="cash">เงินสด / เช็ค</option>
    <?php foreach ($banks as $b): ?><option value="<?= (int)$b['id'] ?>"><?= e(ledger_account_label($b)) ?></option><?php endforeach; ?></select></div>
  <?php else: ?><input type="hidden" name="account_id" value="cash"><?php endif; ?>
  <?php if ($funds): ?><div><label for="q-fund" class="text-lg">กองทุน/โครงการ</label><select id="q-fund" name="fund_id"><option value="0">เงินทั่วไป</option>
    <?php foreach ($funds as $f): ?><option value="<?= (int)$f['id'] ?>"><?= e($f['name']) ?></option><?php endforeach; ?></select></div><?php endif; ?>
  <?php $today = date('Y-m-d'); ?>
  <input type="hidden" name="doc_no" value="">
  <button class="btn w-full" type="submit">บันทึก</button>
  <p class="text-center text-sm text-stone-500">บันทึกเป็นวันที่ <?= e(th_date($today)) ?> · แก้วันที่หรือเพิ่มรายละเอียดอื่นได้ที่ <a class="underline" href="transactions.php?type=<?= $qType ?>">หน้าบัญชี<?= $qType === 'income' ? 'รายรับ' : 'รายจ่าย' ?></a></p>
  <?= csrf_field() ?>
  <input type="hidden" name="action" value="save"><input type="hidden" name="back" value="quick"><input type="hidden" name="temple_id" value="<?= $tid ?>">
  <?php $today = date('Y-m-d'); echo '<input type="hidden" name="txn_date_d" value="' . (int)date('j') . '"><input type="hidden" name="txn_date_m" value="' . (int)date('n') . '"><input type="hidden" name="txn_date_y" value="' . ((int)date('Y') + 543) . '" data-today>'; ?>
</form>

<h3 class="mt-6">ล่าสุด</h3>
<ul class="card-table divide-y divide-stone-100">
  <?php foreach ($recent as $r): ?>
  <li class="flex items-center justify-between gap-3 px-5 py-3"><span class="min-w-0"><span class="block truncate"><?= e($r['category']) ?><?= $r['description'] ? ' · ' . e($r['description']) : '' ?></span><span class="text-xs text-stone-500"><?= e(th_date($r['txn_date'])) ?></span></span>
    <span class="whitespace-nowrap text-lg font-bold <?= $r['type'] === 'income' ? 'text-emerald-700' : 'text-red-700' ?>"><?= $r['type'] === 'income' ? '+' : '−' ?><?= money($r['amount']) ?></span></li>
  <?php endforeach; ?>
  <?php if (!$recent): ?><li class="px-5 py-3 text-stone-500">ยังไม่มีรายการ</li><?php endif; ?>
</ul>
<?php endif; ?>
</div>
<?php require __DIR__ . '/includes/footer.php'; ?>
