<?php
/** รายงานประจำเดือนตามแบบฟอร์มของวัด: แบบที่ 1 เงินสด / แบบที่ 2 เงินฝากธนาคาร / แบบที่ 3 เงินคงเหลือ */
require __DIR__ . '/includes/bootstrap.php';
require __DIR__ . '/includes/statement_ui.php';
$u = require_login();
$pdo = db();

$tid = $u['role'] === 'temple' ? (int)$u['temple_id'] : (int)($_GET['temple'] ?? 0);
$temple = $tid && people_can_view($tid) ? temple_row($tid) : null;
$year = (int)($_GET['year'] ?? be_year());
$month = (int)($_GET['month'] ?? (int)date('n'));
$month = $month >= 1 && $month <= 12 ? $month : (int)date('n');
$form = in_array($_GET['form'] ?? 'all', ['all', '1', '2', '3'], true) ? ($_GET['form'] ?? 'all') : 'all';
$ceYear = $year - 543;
$groups = temples_grouped();

$pageTitle = 'รายงานแบบที่ 1–3 ประจำเดือน';
require __DIR__ . '/includes/header.php';
?>
<div class="flex flex-wrap items-center justify-between gap-2 print:hidden">
  <h2>รายงานประจำเดือนตามแบบฟอร์มของวัด</h2>
  <span class="flex flex-wrap gap-2"><a class="btn btn-outline btn-sm" href="reports.php">รายงานสรุป</a>
    <?php if ($temple && $u['role'] !== 'province'): ?><a class="btn btn-outline btn-sm" href="accounts.php<?= $u['role'] === 'temple' ? '' : '?temple=' . $tid ?>">บัญชีเงินสด/ธนาคาร · ยอดยกมา</a><?php endif; ?></span>
</div>
<form class="my-4 flex flex-wrap items-end gap-3 print:hidden" method="get">
  <?php if ($u['role'] !== 'temple'): ?>
  <div><label>วัด</label><select name="temple">
    <option value="0">— เลือกวัด —</option>
    <?php foreach ($groups as $dn => $list): ?><optgroup label="อำเภอ<?= e($dn) ?>"><?php foreach ($list as $t): ?><option value="<?= (int)$t['id'] ?>" <?= $t['id'] == $tid ? 'selected' : '' ?>><?= e($t['name']) ?></option><?php endforeach; ?></optgroup><?php endforeach; ?>
  </select></div>
  <?php endif; ?>
  <div><label>เดือน</label><select name="month"><?php for ($m = 1; $m <= 12; $m++): ?><option value="<?= $m ?>" <?= $m === $month ? 'selected' : '' ?>><?= e(TH_MONTHS[$m]) ?></option><?php endfor; ?></select></div>
  <div><label>ปี พ.ศ.</label><select name="year"><?php for ($y = be_year() + 1; $y >= be_year() - 8; $y--): ?><option <?= $y === $year ? 'selected' : '' ?>><?= $y ?></option><?php endfor; ?></select></div>
  <div><label>แบบฟอร์ม</label><select name="form">
    <option value="all" <?= $form === 'all' ? 'selected' : '' ?>>ชุดทั้งหมด (แบบที่ 1 + 2 ทุกบัญชี + 3)</option>
    <option value="1" <?= $form === '1' ? 'selected' : '' ?>>แบบที่ 1 เงินสด</option>
    <option value="2" <?= $form === '2' ? 'selected' : '' ?>>แบบที่ 2 เงินฝากธนาคาร (ทุกบัญชี)</option>
    <option value="3" <?= $form === '3' ? 'selected' : '' ?>>แบบที่ 3 รายงานเงินคงเหลือ</option>
  </select></div>
  <button class="btn" type="submit">แสดง</button>
  <?php if ($temple): ?><button class="btn btn-outline" type="button" onclick="window.print()">พิมพ์ / บันทึก PDF</button><?php endif; ?>
</form>
<?php
if (!$temple) {
    echo '<p class="text-stone-600">เลือกวัดและเดือนที่ต้องการออกรายงาน</p>';
} else {
    $c = stmt_context($pdo, $tid);
    $cash = ledger_account($pdo, ledger_cash_account($pdo, $tid));
    $needOpening = !$cash['opening_date'];
    if ($needOpening && $u['role'] !== 'province'): ?>
<p class="mb-3 rounded-lg border border-amber-300 bg-amber-50 px-3 py-2 text-sm text-amber-950 print:hidden">ยังไม่ได้ตั้ง <strong>ยอดยกมา</strong> ของบัญชีเงินสด — ยอดยกมาในรายงานจะเริ่มที่ 0
  ตั้งได้ที่ <a class="underline" href="accounts.php<?= $u['role'] === 'temple' ? '' : '?temple=' . $tid ?>">บัญชีเงินสด/ธนาคาร</a></p>
    <?php endif;
    $out = '';
    if ($form === 'all' || $form === '1') {
        $out .= stmt_account_sheet($pdo, $c, $cash, $ceYear, $month);
    }
    if ($form === 'all' || $form === '2') {
        $banks = ledger_report_accounts($pdo, $tid, $ceYear, $month, 'bank');
        foreach ($banks as $i => $b) {
            $out .= stmt_account_sheet($pdo, $c, $b, $ceYear, $month, $i + 1);
        }
        if (!$banks && $form === '2') {
            $out .= '<p class="text-stone-600">วัดนี้ยังไม่มีบัญชีเงินฝากธนาคารในระบบ</p>';
        }
    }
    if ($form === 'all' || $form === '3') {
        $out .= stmt_balance_sheet($pdo, $c, $ceYear, $month);
    }
    echo '<div class="stmt-wrap">' . $out . '</div>';
}
require __DIR__ . '/includes/footer.php';
