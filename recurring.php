<?php
/** รายการประจำ: ตั้งแม่แบบรายรับ-รายจ่ายรายเดือน แล้วกดยืนยันบันทึกเมื่อถึงกำหนด (ไม่บันทึกอัตโนมัติ) */
require __DIR__ . '/includes/bootstrap.php';
$u = require_login();
if (!in_array($u['role'], ['admin', 'district', 'temple'], true)) {   // เจ้าคณะจังหวัด/ตำบลดูข้อมูลได้อย่างเดียว
    redirect('dashboard.php');
}
$pdo = db();
if (!recurring_available()) {
    flash('ยังไม่ได้เปิดใช้รายการประจำ (ผู้ดูแลต้องรัน sql/migrations/2026_recurring.sql)', 'err');
    redirect('dashboard.php');
}

$tid = $u['role'] === 'temple' ? (int)$u['temple_id'] : (int)($_GET['temple'] ?? $_POST['temple'] ?? 0);
$temple = $tid && can_access_temple($tid) ? temple_row($tid) : null;
$self = 'recurring.php' . ($u['role'] === 'temple' || !$tid ? '' : '?temple=' . $tid);

if ($temple && $_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $act = isset($_POST['skip']) ? 'skip' : ($_POST['action'] ?? '');   // ปุ่ม "ข้าม" ในตารางรอยืนยันส่งค่า skip
    $id = (int)($_POST['id'] ?? 0);
    if ($act === 'save') {
        [$ok, $msg] = recurring_save($pdo, $tid, $_POST, $id, (int)$u['id']);
        $ok && audit($id ? 'update' : 'create', 'recurring', $id ?: (int)$pdo->lastInsertId(), mb_substr(trim((string)($_POST['description'] ?? '')), 0, 100));
        flash($msg, $ok ? 'ok' : 'err');
        redirect($ok ? $self : $self . ($self === 'recurring.php' ? '?' : '&') . ($id ? "edit=$id" : 'new=1'));
    } elseif ($act === 'toggle' && ($row = recurring_row($pdo, $id, $tid))) {
        $pdo->prepare('UPDATE recurring_items SET is_active = 1 - is_active WHERE id = ?')->execute([$id]);
        audit('update', 'recurring', $id, $row['is_active'] ? 'pause' : 'resume');
        flash($row['is_active'] ? 'หยุดรายการประจำชั่วคราวแล้ว (ไม่ขึ้นรายการรอยืนยัน)' : 'เปิดใช้รายการประจำอีกครั้งแล้ว');
    } elseif ($act === 'delete' && recurring_row($pdo, $id, $tid)) {
        $pdo->prepare('DELETE FROM recurring_items WHERE id = ? AND temple_id = ?')->execute([$id, $tid]);   // รายการที่บันทึกไปแล้วยังอยู่ (ตัดการอ้างอิง)
        audit('delete', 'recurring', $id);
        flash('ลบรายการประจำแล้ว (รายการที่บันทึกไปแล้วยังอยู่ในบัญชี)');
    } elseif ($act === 'post_selected') {
        $done = [];
        $errs = [];
        foreach ((array)($_POST['pick'] ?? []) as $key) {
            if (!is_string($key) || !preg_match('/^(\d+)\|(\d{4}-\d{2}-01)$/', $key, $m)) {
                continue;
            }
            $raw = $_POST['amt'][$key] ?? '';
            $amt = is_scalar($raw) && trim((string)$raw) !== '' ? round((float)str_replace(',', '', (string)$raw), 2) : null;
            [$ok, $msg] = recurring_post($pdo, $tid, (int)$m[1], $m[2], $amt, (int)$u['id']);
            $ok ? $done[] = $msg : $errs[] = $msg;
        }
        if (!$done && !$errs) {
            flash('ยังไม่ได้เลือกรายการ', 'err');
        } else {
            flash(($done ? 'บันทึกแล้ว ' . count($done) . ' รายการ' : '') . ($done && $errs ? ' · ' : '') . ($errs ? 'ไม่สำเร็จ: ' . implode(', ', $errs) : ''), $errs ? 'err' : 'ok');
        }
    } elseif ($act === 'skip' && preg_match('/^(\d+)\|(\d{4}-\d{2}-01)$/', (string)($_POST['skip'] ?? ''), $m)) {
        [$ok, $msg] = recurring_skip($pdo, $tid, (int)$m[1], $m[2], (int)$u['id']);
        flash($msg, $ok ? 'ok' : 'err');
    }
    redirect($self);
}

$groups = temples_grouped();
$cats = ['income' => [], 'expense' => []];
$banks = [];
$funds = [];
$items = [];
$due = [];
$edit = null;
if ($temple) {
    foreach ($pdo->query('SELECT id, type, name FROM categories WHERE is_active = 1 AND is_transfer = 0 ORDER BY sort_order, name')->fetchAll() as $c) {
        $cats[$c['type']][] = $c;
    }
    foreach (ledger_accounts($pdo, $tid, true) as $a) {
        if ($a['kind'] === 'bank') $banks[] = $a;
    }
    $funds = fund_available() ? fund_list($pdo, $tid, true) : [];
    $items = recurring_list($pdo, $tid);
    $due = recurring_due($pdo, $tid);
    if (!empty($_GET['edit'])) {
        $edit = recurring_row($pdo, (int)$_GET['edit'], $tid);
    }
}
$showForm = $temple && ($edit || isset($_GET['new']));
$fv = fn(string $k, $d = '') => $edit[$k] ?? $d;
$accSel = $edit ? (int)$edit['account_id'] : 0;
$cashId = $temple ? ledger_cash_account($pdo, $tid) : 0;
$sm = $edit ? (int)substr($edit['start_month'], 5, 2) : (int)date('n');
$sy = $edit ? (int)substr($edit['start_month'], 0, 4) + 543 : be_year();
$em = $edit && $edit['end_month'] ? (int)substr($edit['end_month'], 5, 2) : 0;
$ey = $edit && $edit['end_month'] ? (int)substr($edit['end_month'], 0, 4) + 543 : 0;
$accLabel = function (array $r): string {
    if (!$r['acc_kind'] || $r['acc_kind'] === 'cash') return 'เงินสด / เช็ค';
    return trim(($r['acc_bank'] ?? '') . ' ' . ($r['acc_no'] ?? ''));
};

$pageTitle = 'รายการประจำ';
require __DIR__ . '/includes/header.php';
?>
<div class="flex flex-wrap items-center justify-between gap-2">
  <h2>รายการประจำ<?= $temple ? ' · ' . e($temple['name']) : '' ?></h2>
  <?php if ($temple && !$showForm): ?><a class="btn btn-sm" href="<?= e($self . ($self === 'recurring.php' ? '?' : '&') . 'new=1') ?>"><?= icon('plus') ?> เพิ่มรายการประจำ</a><?php endif; ?>
</div>
<?php if ($u['role'] !== 'temple'): ?>
<form method="get" class="my-4 flex flex-wrap items-end gap-3">
  <div><label>วัด</label><select name="temple" onchange="this.form.submit()"><option value="0">— เลือกวัด —</option>
    <?php foreach ($groups as $dn => $list): ?><optgroup label="<?= e(unit_district()) ?><?= e($dn) ?>"><?php foreach ($list as $t): ?><option value="<?= (int)$t['id'] ?>" <?= $t['id'] == $tid ? 'selected' : '' ?>><?= e($t['name']) ?></option><?php endforeach; ?></optgroup><?php endforeach; ?></select></div>
  <noscript><button class="btn">แสดง</button></noscript>
</form>
<?php endif; ?>
<?php if (!$temple): ?>
  <p class="text-stone-600">เลือกวัดเพื่อดูหรือตั้งรายการประจำ</p>
<?php else: ?>
<p class="mb-4 text-sm text-stone-600">ตั้งรายการที่เกิดซ้ำทุกเดือน เช่น ค่าน้ำ ค่าไฟ เงินเดือน ระบบ<strong>ไม่บันทึกเอง</strong> — เมื่อถึงวันที่ตั้งไว้จะขึ้นในรายการรอยืนยัน ตรวจ/แก้จำนวนเงินแล้วกดบันทึก หรือกดข้ามเดือนนั้น (ตรวจย้อนหลังได้ <?= RECURRING_LOOKBACK_MONTHS ?> เดือน)</p>

<section class="card-table mb-6">
  <form method="post" id="due-form">
  <?= csrf_field() ?><input type="hidden" name="action" value="post_selected"><input type="hidden" name="temple" value="<?= $tid ?>">
  <table class="data-table">
    <caption class="px-5 pt-4 text-left text-lg font-semibold">ถึงกำหนด รอยืนยัน (<?= count($due) ?>)</caption>
    <thead><tr><th></th><th>วันที่</th><th>รายการ</th><th>หมวด / บัญชี</th><th class="text-right">จำนวนเงิน (บาท)</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($due as $d): $it = $d['item']; $key = $it['id'] . '|' . $d['period']; ?>
    <tr>
      <td><?php if (!$d['locked']): ?><input type="checkbox" name="pick[]" value="<?= e($key) ?>" checked aria-label="เลือก <?= e($it['description']) ?>"><?php endif; ?></td>
      <td class="whitespace-nowrap"><?= e(th_date($d['date'])) ?></td>
      <td><span class="badge <?= $it['type'] === 'income' ? 'badge-ok' : 'badge-err' ?>"><?= $it['type'] === 'income' ? 'รายรับ' : 'รายจ่าย' ?></span> <?= e($it['description']) ?></td>
      <td class="text-sm text-stone-600"><?php foreach ([...$cats['income'], ...$cats['expense']] as $c) { if ((int)$c['id'] === (int)$it['category_id']) { echo e($c['name']); break; } } ?></td>
      <td class="text-right"><?php if ($d['locked']): ?><span class="badge badge-mute"><?= icon('lock') ?> ปิดงวดแล้ว</span><?php else: ?><input name="amt[<?= e($key) ?>]" inputmode="decimal" class="w-32 text-right" value="<?= e(number_format((float)$it['amount'], 2, '.', '')) ?>"><?php endif; ?></td>
      <td><button class="btn btn-sm btn-outline" name="skip" value="<?= e($key) ?>">ข้าม</button></td>
    </tr>
    <?php endforeach; ?>
    <?php if (!$due): ?><tr><td colspan="6" class="text-stone-500">ไม่มีรายการที่ถึงกำหนด</td></tr><?php endif; ?>
    </tbody>
  </table>
  <?php if ($due): ?><div class="px-5 pb-4"><button class="btn" type="submit">บันทึกรายการที่เลือก</button></div><?php endif; ?>
  </form>
</section>

<?php if ($showForm): ?>
<form method="post" class="card mb-6 space-y-3">
  <h3><?= $edit ? 'แก้ไขรายการประจำ' : 'เพิ่มรายการประจำ' ?></h3>
  <?= csrf_field() ?><input type="hidden" name="action" value="save"><input type="hidden" name="temple" value="<?= $tid ?>"><input type="hidden" name="id" value="<?= $edit ? (int)$edit['id'] : 0 ?>">
  <div class="flex flex-wrap items-end gap-3">
    <div><label>ประเภท</label><select name="type" id="r-type"><option value="income" <?= $fv('type') === 'income' ? 'selected' : '' ?>>รายรับ</option><option value="expense" <?= $fv('type', 'expense') === 'expense' ? 'selected' : '' ?>>รายจ่าย</option></select></div>
    <div><label>หมวด</label><select name="category_id" id="r-cat" required>
      <?php foreach (['income', 'expense'] as $t): foreach ($cats[$t] as $c): ?><option value="<?= (int)$c['id'] ?>" data-type="<?= $t ?>" <?= (int)$fv('category_id') === (int)$c['id'] ? 'selected' : '' ?>><?= e($c['name']) ?></option><?php endforeach; endforeach; ?></select></div>
    <div><label>ชื่อรายการ</label><input name="description" required maxlength="255" value="<?= e((string)$fv('description')) ?>" placeholder="เช่น ค่าไฟฟ้า" class="w-56"></div>
    <div><label>จำนวนเงินตั้งต้น (บาท)</label><input name="amount" inputmode="decimal" required value="<?= $edit ? e(number_format((float)$edit['amount'], 2, '.', '')) : '' ?>" class="w-36 text-right"></div>
    <div><label>วันที่ของเดือน</label><input type="number" name="day_of_month" min="1" max="31" required value="<?= (int)$fv('day_of_month', 1) ?>" class="w-24"></div>
  </div>
  <div class="flex flex-wrap items-end gap-3">
    <div><label>บัญชี</label><select name="account_id"><option value="cash">เงินสด / เช็ค</option>
      <?php foreach ($banks as $b): ?><option value="<?= (int)$b['id'] ?>" <?= $accSel === (int)$b['id'] ? 'selected' : '' ?>><?= e(ledger_account_label($b)) ?></option><?php endforeach; ?></select></div>
    <?php if ($funds): ?><div><label>กองทุน/โครงการ</label><select name="fund_id"><option value="0">เงินทั่วไป</option>
      <?php foreach ($funds as $f): ?><option value="<?= (int)$f['id'] ?>" <?= (int)$fv('fund_id') === (int)$f['id'] ? 'selected' : '' ?>><?= e($f['name']) ?></option><?php endforeach; ?></select></div><?php endif; ?>
    <div><label>เริ่มเดือน</label><span class="flex gap-2"><select name="start_m"><?php for ($m = 1; $m <= 12; $m++): ?><option value="<?= $m ?>" <?= $m === $sm ? 'selected' : '' ?>><?= e(TH_MONTHS[$m]) ?></option><?php endfor; ?></select>
      <select name="start_y"><?php for ($y = be_year() + 1; $y >= be_year() - 3; $y--): ?><option <?= $y === $sy ? 'selected' : '' ?>><?= $y ?></option><?php endfor; ?></select></span></div>
    <div><label>สิ้นสุดเดือน (ไม่บังคับ)</label><span class="flex gap-2"><select name="end_m"><option value="0">— ไม่กำหนด —</option><?php for ($m = 1; $m <= 12; $m++): ?><option value="<?= $m ?>" <?= $m === $em ? 'selected' : '' ?>><?= e(TH_MONTHS[$m]) ?></option><?php endfor; ?></select>
      <select name="end_y"><option value="0">—</option><?php for ($y = be_year() + 10; $y >= be_year() - 3; $y--): ?><option <?= $y === $ey ? 'selected' : '' ?>><?= $y ?></option><?php endfor; ?></select></span></div>
    <?php if ($edit): ?><label class="flex items-center gap-2 !mb-0"><input type="checkbox" name="is_active" value="1" <?= $edit['is_active'] ? 'checked' : '' ?>> ใช้งานอยู่</label><?php endif; ?>
  </div>
  <div class="flex gap-2"><button class="btn" type="submit">บันทึก</button><a class="btn btn-outline" href="<?= e($self) ?>">ยกเลิก</a></div>
</form>
<script>
(function () {
  var t = document.getElementById('r-type'), c = document.getElementById('r-cat');
  if (!t || !c) return;
  function sync() { var first = null; Array.prototype.forEach.call(c.options, function (o) { var show = o.getAttribute('data-type') === t.value; o.hidden = !show; o.disabled = !show; if (show && !first) first = o; }); if (c.selectedOptions[0] && c.selectedOptions[0].disabled && first) c.value = first.value; }
  t.addEventListener('change', sync); sync();
})();
</script>
<?php endif; ?>

<section class="card-table">
  <table class="data-table">
    <caption class="px-5 pt-4 text-left text-lg font-semibold">รายการประจำทั้งหมด (<?= count($items) ?>)</caption>
    <thead><tr><th>รายการ</th><th>หมวด</th><th>บัญชี</th><th class="text-right">จำนวนเงิน</th><th>ทุกวันที่</th><th>ช่วงเวลา</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($items as $r): ?>
    <tr class="<?= $r['is_active'] ? '' : 'opacity-60' ?>">
      <td><span class="badge <?= $r['type'] === 'income' ? 'badge-ok' : 'badge-err' ?>"><?= $r['type'] === 'income' ? 'รายรับ' : 'รายจ่าย' ?></span> <?= e($r['description']) ?><?= $r['is_active'] ? '' : ' <span class="badge badge-mute">หยุดชั่วคราว</span>' ?><?php if ($r['fund_name']): ?><div class="text-xs text-stone-500"><?= e($r['fund_name']) ?></div><?php endif; ?></td>
      <td><?= e($r['category']) ?></td>
      <td class="text-sm"><?= e($accLabel($r)) ?></td>
      <td class="text-right"><?= money($r['amount']) ?></td>
      <td><?= (int)$r['day_of_month'] ?></td>
      <td class="whitespace-nowrap text-sm"><?= e(TH_MONTHS[(int)substr($r['start_month'], 5, 2)]) ?> <?= (int)substr($r['start_month'], 0, 4) + 543 ?> – <?= $r['end_month'] ? e(TH_MONTHS[(int)substr($r['end_month'], 5, 2)]) . ' ' . ((int)substr($r['end_month'], 0, 4) + 543) : 'ไม่กำหนด' ?></td>
      <td class="whitespace-nowrap">
        <a class="btn btn-sm btn-outline" href="<?= e($self . ($self === 'recurring.php' ? '?' : '&') . 'edit=' . (int)$r['id']) ?>">แก้ไข</a>
        <form method="post" class="inline"><?= csrf_field() ?><input type="hidden" name="action" value="toggle"><input type="hidden" name="temple" value="<?= $tid ?>"><input type="hidden" name="id" value="<?= (int)$r['id'] ?>"><button class="btn btn-sm btn-outline"><?= $r['is_active'] ? 'หยุด' : 'เปิดใช้' ?></button></form>
        <form method="post" class="inline" data-confirm="ลบรายการประจำนี้? (รายการที่บันทึกไปแล้วยังอยู่ในบัญชี)"><?= csrf_field() ?><input type="hidden" name="action" value="delete"><input type="hidden" name="temple" value="<?= $tid ?>"><input type="hidden" name="id" value="<?= (int)$r['id'] ?>"><button class="btn btn-sm btn-danger">ลบ</button></form>
      </td>
    </tr>
    <?php endforeach; ?>
    <?php if (!$items): ?><tr><td colspan="7" class="text-stone-500">ยังไม่มีรายการประจำ กด "เพิ่มรายการประจำ"</td></tr><?php endif; ?>
    </tbody>
  </table>
</section>
<?php endif; ?>
<?php require __DIR__ . '/includes/footer.php'; ?>
