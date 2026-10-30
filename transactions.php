<?php
require __DIR__ . '/includes/bootstrap.php';
require __DIR__ . '/includes/stats.php';
$u = require_login();
if (in_array($u['role'], ['province', 'subdistrict'], true)) {   // เจ้าคณะจังหวัด/ตำบลดูข้อมูลได้อย่างเดียว
    redirect('dashboard.php');
}

$type = ($_GET['type'] ?? 'income') === 'expense' ? 'expense' : 'income';
$label = $type === 'income' ? 'รายรับ' : 'รายจ่าย';
$pdo = db();

// ---------- บันทึก / ลบ ----------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = $_POST['action'] ?? '';
    $id = (int)($_POST['id'] ?? 0);

    if ($action === 'delete' && $id) {
        $st = $pdo->prepare('SELECT temple_id, txn_date FROM transactions WHERE id = ? AND is_deleted = 0 AND transfer_id IS NULL');
        $st->execute([$id]);
        $row = $st->fetch();
        if ($row && can_access_temple((int)$row['temple_id']) && ($lock = period_lock_error($pdo, (int)$row['temple_id'], $row['txn_date'])) !== '') {
            flash($lock, 'err');
        } elseif ($row && can_access_temple((int)$row['temple_id']) && ($rc = receipt_active_for($pdo, $id))) {
            flash('รายการนี้ออกอนุโมทนาบัตรแล้ว (' . receipt_label($rc) . ') ต้องยกเลิกอนุโมทนาบัตรก่อนจึงจะลบได้', 'err');
        } elseif ($row && can_access_temple((int)$row['temple_id'])) {
            $pdo->prepare('UPDATE transactions SET is_deleted = 1 WHERE id = ?')->execute([$id]);
            audit('delete', 'transaction', $id);
            flash('ลบรายการแล้ว');
        }
        redirect("transactions.php?type=$type");
    }

    if ($action === 'save') {
        $templeId = (int)($_POST['temple_id'] ?? 0);
        $catId = (int)($_POST['category_id'] ?? 0);
        $date = date_be_parse($_POST, 'txn_date');
        $amount = round((float)str_replace(',', '', $_POST['amount'] ?? '0'), 2);
        $errs = [];
        if (!can_access_temple($templeId)) $errs[] = 'ไม่มีสิทธิ์บันทึกข้อมูลของวัดนี้';
        $cs = $pdo->prepare('SELECT COUNT(*) FROM categories WHERE id = ? AND type = ? AND is_active = 1 AND is_transfer = 0');
        $cs->execute([$catId, $type]);
        if (!$cs->fetchColumn()) $errs[] = 'หมวดไม่ถูกต้อง';
        $acc = null;
        if ($templeId && can_access_temple($templeId)) {
            $accIn = (string)($_POST['account_id'] ?? 'cash');
            $acc = $accIn === 'cash' || $accIn === '' ? ledger_account($pdo, ledger_cash_account($pdo, $templeId)) : ledger_account($pdo, (int)$accIn, $templeId);
            if (!$acc || !$acc['is_active']) $errs[] = 'เลือกบัญชีของวัดนี้ (เงินสดหรือบัญชีธนาคาร)';
        }
        if ($date === null) $errs[] = 'วันที่ไม่ถูกต้อง (เลือกวัน เดือน และปี พ.ศ.)';
        if ($amount <= 0 || $amount > 999999999999) $errs[] = 'จำนวนเงินต้องมากกว่า 0';
        if ($templeId && can_access_temple($templeId) && ($lock = period_lock_error($pdo, $templeId, $date)) !== '') $errs[] = $lock;
        if ($id) {   // แก้ไขรายการเดิม: เดือนเดิมของรายการต้องไม่ปิดงวดด้วย (กันย้ายรายการออกจากงวดที่ปิด)
            $oldRow = $pdo->prepare('SELECT temple_id, txn_date FROM transactions WHERE id = ? AND is_deleted = 0 AND transfer_id IS NULL');
            $oldRow->execute([$id]);
            if (($orow = $oldRow->fetch()) && can_access_temple((int)$orow['temple_id']) && ($lock = period_lock_error($pdo, (int)$orow['temple_id'], $orow['txn_date'])) !== '') $errs[] = $lock;
        }

        if ($errs) {
            flash(implode(', ', $errs), 'err');
            redirect("transactions.php?type=$type" . ($id ? "&edit=$id" : ''));
        }
        $desc = mb_substr(trim($_POST['description'] ?? ''), 0, 255);
        $doc = mb_substr(trim($_POST['doc_no'] ?? ''), 0, 50);
        if ($id) {
            $own = $pdo->prepare('SELECT temple_id FROM transactions WHERE id = ? AND is_deleted = 0 AND transfer_id IS NULL AND type = ?');
            $own->execute([$id, $type]);
            $o = $own->fetch();
            if (!$o || !can_access_temple((int)$o['temple_id'])) {
                http_response_code(403);
                exit('ไม่มีสิทธิ์แก้ไขรายการนี้');
            }
            if ($rc = receipt_active_for($pdo, $id)) {
                flash('รายการนี้ออกอนุโมทนาบัตรแล้ว (' . receipt_label($rc) . ') ต้องยกเลิกอนุโมทนาบัตรก่อนจึงจะแก้ไขได้', 'err');
                redirect("transactions.php?type=$type");
            }
            $pdo->prepare('UPDATE transactions SET temple_id=?, category_id=?, txn_date=?, doc_no=?, description=?, amount=?, account_id=? WHERE id=?')
                ->execute([$templeId, $catId, $date, $doc, $desc, $amount, $acc['id'], $id]);
            audit('update', 'transaction', $id, "$type $amount");
        } else {
            $pdo->prepare('INSERT INTO transactions (temple_id,type,category_id,txn_date,doc_no,description,amount,account_id,created_by) VALUES (?,?,?,?,?,?,?,?,?)')
                ->execute([$templeId, $type, $catId, $date, $doc, $desc, $amount, $acc['id'], $u['id']]);
            audit('create', 'transaction', (int)$pdo->lastInsertId(), "$type $amount");
        }
        $bw = $type === 'expense' ? budget_warning($pdo, $templeId, $catId, $date) : '';
        flash("บันทึก$label เรียบร้อย" . ($bw !== '' ? " — ⚠️ $bw" : ''));
        redirect("transactions.php?type=$type");
    }
}

// ---------- ข้อมูลสำหรับหน้า ----------
$year = (int)($_GET['year'] ?? be_year());
[$from, $to] = year_range($year);
$fTemple = (int)($_GET['temple'] ?? 0);
[$sc, $sp] = scope_sql('t');

$cats = $pdo->prepare('SELECT id, name FROM categories WHERE type = ? AND is_active = 1 AND is_transfer = 0 ORDER BY sort_order, name');
$cats->execute([$type]);
$cats = $cats->fetchAll();
$groups = temples_grouped();

$edit = null;
if (!empty($_GET['edit'])) {
    $st = $pdo->prepare('SELECT x.* FROM transactions x JOIN temples t ON t.id = x.temple_id WHERE x.id = ? AND x.type = ? AND x.is_deleted = 0 AND x.transfer_id IS NULL' . $sc);
    $st->execute(array_merge([(int)$_GET['edit'], $type], $sp));
    $edit = $st->fetch() ?: null;
}

$sql = "SELECT x.*, t.name AS temple, d.name AS district, c.name AS category, c.is_donation, a.kind AS acc_kind, a.bank_name AS acc_bank, a.account_no AS acc_no,
      (SELECT r.id FROM donation_receipts r WHERE r.transaction_id = x.id AND r.status = 'issued' ORDER BY r.id DESC LIMIT 1) AS receipt_id" . (attachments_available()
      ? ", (SELECT COUNT(*) FROM transaction_attachments ta WHERE ta.transaction_id = x.id) AS att_n" : ', 0 AS att_n') . (month_close_available()
      ? ",
      (SELECT 1 FROM monthly_reports m WHERE m.temple_id = x.temple_id AND m.report_year = YEAR(x.txn_date) + 543 AND m.report_month = MONTH(x.txn_date) AND m.closed_at IS NOT NULL) AS locked" : ', NULL AS locked') . "

    FROM transactions x JOIN temples t ON t.id = x.temple_id
    JOIN districts d ON d.id = t.district_id JOIN categories c ON c.id = x.category_id LEFT JOIN accounts a ON a.id = x.account_id
    WHERE x.is_deleted = 0 AND x.transfer_id IS NULL AND x.type = ? AND x.txn_date BETWEEN ? AND ? $sc";
$p = array_merge([$type, $from, $to], $sp);
if ($fTemple) { $sql .= ' AND t.id = ?'; $p[] = $fTemple; }
$sql .= ' ORDER BY x.txn_date DESC, x.id DESC LIMIT 500';
$st = $pdo->prepare($sql);
$st->execute($p);
$rows = $st->fetchAll();
$total = array_sum(array_column($rows, 'amount'));

// บัญชีธนาคารของวัดที่ผู้ใช้เข้าถึงได้ (ให้ช่อง "บัญชี" ในฟอร์มเปลี่ยนตามวัดที่เลือก)
$bankMap = [];
$bs = $pdo->prepare("SELECT a.id, a.temple_id, a.bank_name, a.account_no FROM accounts a JOIN temples t ON t.id = a.temple_id
    WHERE a.kind = 'bank' AND a.is_active = 1 AND t.is_active = 1 $sc ORDER BY a.id");
$bs->execute($sp);
foreach ($bs->fetchAll() as $b) {
    $bankMap[(int)$b['temple_id']][] = [(string)$b['id'], ledger_account_label(['kind' => 'bank'] + $b)];
}
$formTemple = (int)($edit['temple_id'] ?? ($u['temple_id'] ?? 0));
$editAcc = $edit && $edit['account_id'] ? (string)$edit['account_id'] : 'cash';
$accLabel = fn(array $r) => ($r['acc_kind'] ?? 'cash') === 'bank' ? ledger_account_label(['kind' => 'bank', 'bank_name' => $r['acc_bank'], 'account_no' => $r['acc_no']]) : 'เงินสด';

$pageTitle = "บัญชี$label";
require __DIR__ . '/includes/header.php';

function temple_options(array $groups, int $sel): string
{
    $h = '';
    foreach ($groups as $dn => $list) {
        $h .= '<optgroup label="' . unit_district() . e($dn) . '">';
        foreach ($list as $t) {
            $h .= '<option value="' . (int)$t['id'] . '"' . ($t['id'] == $sel ? ' selected' : '') . '>' . e($t['name']) . '</option>';
        }
        $h .= '</optgroup>';
    }
    return $h;
}
?>
<?php $lhTemple = $fTemple ?: ($u['role'] === 'temple' ? (int)$u['temple_id'] : 0); ?>
<?= $lhTemple ? letterhead($pdo, $lhTemple, 0, 'บัญชี' . $label, 'ปี พ.ศ. ' . $year, true) : '' ?>
<div class="flex flex-wrap items-center justify-between gap-2 print:hidden"><h2>บัญชี<?= $label ?></h2>
  <span class="flex flex-wrap gap-2"><a class="btn btn-outline btn-sm" href="accounts.php<?= $fTemple ? '?temple=' . $fTemple : '' ?>">บัญชีเงินสด/ธนาคาร · โอน-ฝาก-ถอน</a>
  <a class="btn btn-outline btn-sm" href="statements.php<?= $fTemple ? '?temple=' . $fTemple : '' ?>">รายงานแบบที่ 1–3 ประจำเดือน</a></span></div>

<div class="rounded-2xl border border-stone-200 bg-white p-4 shadow-sm print:hidden">
  <h3><?= $edit ? "แก้ไขรายการ$label" : "เพิ่มรายการ$label" ?></h3>
  <form method="post" class="grid gap-3 sm:grid-cols-2 lg:grid-cols-3 [&_input:not([type=checkbox])]:w-full [&_select]:w-full">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="save">
    <input type="hidden" name="id" value="<?= (int)($edit['id'] ?? 0) ?>">
    <div><label>วัด</label><select name="temple_id" required><?= temple_options($groups, (int)($edit['temple_id'] ?? ($u['temple_id'] ?? 0))) ?></select></div>
    <div><label>วันที่</label><?= date_be_field('txn_date', $edit['txn_date'] ?? null) ?></div>
    <div><label>หมวด</label><select name="category_id" required>
      <?php foreach ($cats as $c): ?><option value="<?= (int)$c['id'] ?>" <?= ($edit['category_id'] ?? 0) == $c['id'] ? 'selected' : '' ?>><?= e($c['name']) ?></option><?php endforeach; ?>
    </select></div>
    <div><label>บัญชี</label><select name="account_id" data-bank-map='<?= e(json_encode($bankMap, JSON_UNESCAPED_UNICODE)) ?>' data-temple-select="temple_id" data-selected="<?= e($editAcc) ?>">
      <option value="cash">เงินสด / เช็ค</option>
      <?php foreach ($bankMap[$formTemple] ?? [] as [$bid, $bl]): ?><option value="<?= e($bid) ?>" <?= $editAcc === $bid ? 'selected' : '' ?>><?= e($bl) ?></option><?php endforeach; ?>
    </select></div>
    <div><label>จำนวนเงิน (บาท)</label><input name="amount" inputmode="decimal" required value="<?= e($edit['amount'] ?? '') ?>"></div>
    <div><label>เลขที่เอกสาร</label><input name="doc_no" maxlength="50" value="<?= e($edit['doc_no'] ?? '') ?>"></div>
    <div class="col-span-full"><label>รายละเอียด</label><input name="description" maxlength="255" value="<?= e($edit['description'] ?? '') ?>"></div>
    <div class="col-span-full"><button class="btn" type="submit">บันทึก</button>
      <?php if ($edit): ?><a class="btn btn-outline" href="transactions.php?type=<?= $type ?>">ยกเลิก</a><?php endif; ?></div>
  </form>
</div>

<form class="my-4 flex flex-wrap items-end gap-3 print:hidden" method="get">
  <input type="hidden" name="type" value="<?= $type ?>">
  <div><label>ปี พ.ศ.</label><select name="year"><?php for ($y = be_year() + 1; $y >= be_year() - 6; $y--): ?><option <?= $y === $year ? 'selected' : '' ?>><?= $y ?></option><?php endfor; ?></select></div>
  <div><label>วัด</label><select name="temple"><option value="0">ทุกวัด</option><?= temple_options($groups, $fTemple) ?></select></div>
  <button class="btn" type="submit">แสดง</button>
  <button class="btn btn-outline" type="button" onclick="window.print()">พิมพ์</button>
</form>

<div class="overflow-x-auto rounded-2xl border border-stone-200 bg-white shadow-sm"><table class="data-table">
  <tr><th>วันที่</th><th>วัด</th><th>หมวด</th><th>บัญชี</th><th>รายละเอียด</th><th>เลขที่</th><th class="text-right whitespace-nowrap">จำนวนเงิน</th><th class="print:hidden"></th></tr>
  <?php foreach ($rows as $r): ?>
  <tr>
    <td><?= e(th_date($r['txn_date'])) ?></td>
    <td><?= e($r['temple']) ?><br><small class="text-stone-500">อ.<?= e($r['district']) ?></small></td>
    <td><?= e($r['category']) ?></td><td class="text-sm"><?= e($accLabel($r)) ?></td><td><?= e($r['description']) ?></td><td><?= e($r['doc_no']) ?></td>
    <td class="text-right whitespace-nowrap <?= $type === 'income' ? 'text-emerald-700' : 'text-red-700' ?>"><?= money($r['amount']) ?></td>
    <td class="print:hidden">
      <?php if (attachments_available()): ?>
        <a class="btn btn-sm btn-outline" href="attachments.php?txn=<?= (int)$r['id'] ?>" title="หลักฐานแนบ (ใบเสร็จ/สลิป)">📎<?= $r['att_n'] ? ' ' . (int)$r['att_n'] : '' ?></a>
      <?php endif; ?>
      <?php if ($type === 'income' && $r['is_donation']): ?>
        <a class="btn btn-sm btn-outline" href="receipt.php?<?= $r['receipt_id'] ? 'id=' . (int)$r['receipt_id'] : 'txn=' . (int)$r['id'] ?>"><?= $r['receipt_id'] ? 'ดูอนุโมทนาบัตร' : 'อนุโมทนาบัตร' ?></a>
      <?php endif; ?>
      <?php if (!empty($r['locked'])): ?>
        <span class="badge badge-mute" title="ปิดงวดแล้ว แก้ไขไม่ได้ (แอดมินหลัก/อำเภอเปิดงวดได้ที่หน้ารายงานประจำเดือน)">🔒 ปิดงวด</span>
      <?php else: ?>
      <a class="btn btn-sm btn-outline" href="transactions.php?type=<?= $type ?>&edit=<?= (int)$r['id'] ?>">แก้ไข</a>
      <form method="post" class="inline" data-confirm="ยืนยันการลบรายการนี้?">
        <?= csrf_field() ?><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
        <button class="btn btn-sm btn-danger">ลบ</button></form>
      <?php endif; ?>
    </td>
  </tr>
  <?php endforeach; ?>
  <?php if (!$rows): ?><tr><td colspan="8" class="text-stone-500">ยังไม่มีรายการในช่วงที่เลือก</td></tr><?php endif; ?>
  <tr><th colspan="6" class="text-right whitespace-nowrap">รวม (<?= count($rows) ?> รายการ)</th><th class="text-right whitespace-nowrap"><?= money($total) ?></th><th class="print:hidden"></th></tr>
</table></div>
<?php if (count($rows) === 500): ?><p class="text-stone-500">แสดง 500 รายการล่าสุด กรุณากรองตามวัดเพื่อดูเพิ่มเติม</p><?php endif; ?>
<?php require __DIR__ . '/includes/footer.php'; ?>
