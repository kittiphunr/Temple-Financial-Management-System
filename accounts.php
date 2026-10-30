<?php
/**
 * บัญชีเงินสด/ธนาคารของวัด: ยอดยกมา, เพิ่ม/แก้บัญชีธนาคาร, โอน/นำฝาก/ถอนระหว่างบัญชี
 * ข้อมูลชุดนี้ใช้ออกรายงานแบบที่ 1 (เงินสด) แบบที่ 2 (เงินฝากธนาคาร) แบบที่ 3 (เงินคงเหลือ) ที่ statements.php
 */
require __DIR__ . '/includes/bootstrap.php';
$u = require_login();
$pdo = db();

$tid = $u['role'] === 'temple' ? (int)$u['temple_id'] : (int)($_GET['temple'] ?? $_POST['temple'] ?? 0);
$temple = $tid && people_can_view($tid) ? temple_row($tid) : null;
$canEdit = $temple && can_access_temple($tid);
$self = 'accounts.php' . ($u['role'] === 'temple' ? '' : '?temple=' . $tid);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    if (!$canEdit) {
        http_response_code(403);
        exit('ไม่มีสิทธิ์แก้ไขบัญชีของวัดนี้');
    }
    $act = $_POST['action'] ?? '';
    $aid = (int)($_POST['account_id'] ?? 0);
    $acc = $aid ? ledger_account($pdo, $aid, $tid) : null;
    $money = fn(string $k) => round((float)str_replace(',', '', $_POST[$k] ?? '0'), 2);
    $txt = fn(string $k, int $max) => mb_substr(trim($_POST[$k] ?? ''), 0, $max);

    if ($act === 'add_bank') {
        $bank = $txt('bank_name', 100);
        $no = preg_replace('/[^0-9-]/', '', $_POST['account_no'] ?? '');
        $open = date_be_parse($_POST, 'opening_date');
        $bal = $money('opening_balance');
        if ($bank === '') {
            flash('กรุณาระบุชื่อธนาคาร', 'err');
        } elseif ($no === '' || strlen($no) > 30) {
            flash('กรุณาระบุเลขที่บัญชี (ตัวเลข/ขีด ไม่เกิน 30 ตัว)', 'err');
        } elseif ($open === null || $bal < 0) {
            flash('วันที่เริ่มบัญชีหรือยอดยกมาไม่ถูกต้อง', 'err');
        } else {
            $pdo->prepare("INSERT INTO accounts (temple_id, kind, name, bank_name, branch, account_no, opening_balance, opening_date) VALUES (?, 'bank', ?, ?, ?, ?, ?, ?)")
                ->execute([$tid, $txt('name', 150) ?: $temple['name'], $bank, $txt('branch', 100) ?: null, $no, $bal, $open]);
            audit('create', 'account', (int)$pdo->lastInsertId());
            flash('เพิ่มบัญชีธนาคารแล้ว');
        }
    } elseif ($act === 'edit_bank' && $acc && $acc['kind'] === 'bank') {
        $bank = $txt('bank_name', 100);
        $no = preg_replace('/[^0-9-]/', '', $_POST['account_no'] ?? '');
        if ($bank === '' || $no === '') {
            flash('กรุณาระบุธนาคารและเลขที่บัญชี', 'err');
        } else {
            $pdo->prepare('UPDATE accounts SET name = ?, bank_name = ?, branch = ?, account_no = ? WHERE id = ?')
                ->execute([$txt('name', 150) ?: $temple['name'], $bank, $txt('branch', 100) ?: null, $no, $aid]);
            audit('update', 'account', $aid);
            flash('บันทึกข้อมูลบัญชีแล้ว');
        }
    } elseif ($act === 'set_opening' && $acc) {
        $open = date_be_parse($_POST, 'opening_date');
        $bal = $money('opening_balance');
        if ($open === null || $bal < 0 || $bal > 999999999999) {
            flash('วันที่เริ่มบัญชีหรือยอดยกมาไม่ถูกต้อง', 'err');
        } else {
            $pdo->prepare('UPDATE accounts SET opening_balance = ?, opening_date = ? WHERE id = ?')->execute([$bal, $open, $aid]);
            audit('set_opening', 'account', $aid, "$open $bal");
            flash('บันทึกยอดยกมาแล้ว');
        }
    } elseif ($act === 'toggle' && $acc && $acc['kind'] === 'bank') {
        $pdo->prepare('UPDATE accounts SET is_active = 1 - is_active WHERE id = ?')->execute([$aid]);
        audit('toggle', 'account', $aid);
        flash($acc['is_active'] ? 'ปิดใช้งานบัญชีแล้ว (ยังแสดงในรายงานย้อนหลัง)' : 'เปิดใช้งานบัญชีแล้ว');
    } elseif ($act === 'transfer') {
        $date = date_be_parse($_POST, 'txn_date');
        if ($date === null) {
            flash('วันที่ไม่ถูกต้อง (เลือกวัน เดือน และปี พ.ศ.)', 'err');
        } else {
            [$gid, $err] = ledger_transfer($pdo, $tid, (int)($_POST['from_id'] ?? 0), (int)($_POST['to_id'] ?? 0), $date, $money('amount'),
                (string)($_POST['description'] ?? ''), (int)$u['id'], $txt('doc_no', 50));
            if ($gid) {
                audit('transfer', 'account', $gid);
                flash('บันทึกรายการโอน/ฝาก-ถอนแล้ว');
            } else {
                flash($err, 'err');
            }
        }
    } elseif ($act === 'delete_transfer') {
        $gid = (int)($_POST['transfer_id'] ?? 0);
        $n = $pdo->prepare('UPDATE transactions SET is_deleted = 1 WHERE transfer_id = ? AND temple_id = ?');
        $n->execute([$gid, $tid]);
        if ($n->rowCount()) {
            audit('delete_transfer', 'account', $gid);
            flash('ลบรายการโอน/ฝาก-ถอนแล้ว');
        }
    }
    redirect($self);
}

$groups = temples_grouped();
$accounts = $temple ? ledger_accounts($pdo, $tid) : [];
$today = date('Y-m-d');
$transfers = [];
if ($temple) {
    $st = $pdo->prepare("SELECT x.transfer_id, x.txn_date, x.amount, x.description, x.doc_no, x.account_id AS from_id,
        (SELECT y.account_id FROM transactions y WHERE y.transfer_id = x.transfer_id AND y.type = 'income' AND y.is_deleted = 0 LIMIT 1) AS to_id
        FROM transactions x WHERE x.temple_id = ? AND x.type = 'expense' AND x.transfer_id = x.id AND x.is_deleted = 0 ORDER BY x.txn_date DESC, x.id DESC LIMIT 50");
    $st->execute([$tid]);
    $transfers = $st->fetchAll();
}
$byId = array_column($accounts, null, 'id');
$label = fn($id) => isset($byId[(int)$id]) ? ledger_account_label($byId[(int)$id]) : '-';

$pageTitle = 'บัญชีเงินสด/ธนาคาร';
require __DIR__ . '/includes/header.php';
?>
<div class="flex flex-wrap items-center justify-between gap-2">
  <h2>บัญชีเงินสด/ธนาคาร<?= $temple ? ' · ' . e($temple['name']) : '' ?></h2>
  <?php if ($temple): ?><a class="btn btn-outline btn-sm" href="statements.php<?= $u['role'] === 'temple' ? '' : '?temple=' . $tid ?>">รายงานแบบที่ 1–3 ประจำเดือน →</a><?php endif; ?>
</div>

<?php if ($u['role'] !== 'temple'): ?>
<form method="get" class="mb-4 flex flex-wrap items-end gap-3">
  <div><label>วัด</label><select name="temple" onchange="this.form.submit()">
    <option value="0">— เลือกวัด —</option>
    <?php foreach ($groups as $dn => $list): ?><optgroup label="อำเภอ<?= e($dn) ?>"><?php foreach ($list as $t): ?><option value="<?= (int)$t['id'] ?>" <?= $t['id'] == $tid ? 'selected' : '' ?>><?= e($t['name']) ?></option><?php endforeach; ?></optgroup><?php endforeach; ?>
  </select></div>
  <noscript><button class="btn">แสดง</button></noscript>
</form>
<?php endif; ?>

<?php if (!$temple): ?>
  <p class="text-stone-600">เลือกวัดเพื่อจัดการบัญชี</p>
<?php else: ?>
<p class="mb-3 text-sm text-stone-600">รายงานแบบที่ 1–3 ต้องรู้ <strong>ยอดยกมา</strong> ของเงินสดและบัญชีธนาคารแต่ละบัญชี ณ วันเริ่มใช้ระบบ แล้วระบบจะคำนวณยอดคงเหลือต่อให้เองจากรายการที่บันทึก
  การนำเงินสดไปฝากธนาคาร/ถอนเงิน ให้บันทึกเป็น "โอน/ฝาก-ถอน" ด้านล่าง (ไม่นับเป็นรายรับ-รายจ่ายซ้ำ)</p>

<div class="grid gap-3">
<?php foreach ($accounts as $a):
    $bal = ledger_balance_on($pdo, $a, $today);
    $isBank = $a['kind'] === 'bank'; ?>
  <section class="rounded-2xl border border-stone-200 bg-white p-4 shadow-sm <?= $a['is_active'] ? '' : 'opacity-70' ?>">
    <div class="flex flex-wrap items-start justify-between gap-2">
      <div>
        <h3 class="!my-0"><?= $isBank ? 'บัญชีธนาคาร' : 'เงินสด / เช็ค' ?><?= $a['is_active'] ? '' : ' <span class="badge">ปิดใช้งาน</span>' ?></h3>
        <?php if ($isBank): ?><div class="text-sm text-stone-600">ชื่อบัญชี <?= e($a['name']) ?> · ธนาคาร<?= e($a['bank_name']) ?> สาขา<?= e($a['branch'] ?: '-') ?> · เลขที่ <span class="font-mono"><?= e($a['account_no']) ?></span></div><?php endif; ?>
      </div>
      <div class="text-right"><div class="text-xs text-stone-500">ยอดคงเหลือวันนี้</div><div class="text-xl font-bold <?= $bal < 0 ? 'text-red-700' : 'text-emerald-700' ?>"><?= money($bal) ?> บาท</div></div>
    </div>
    <?php if ($canEdit): ?>
    <form method="post" class="mt-3 flex flex-wrap items-end gap-3 border-t border-stone-100 pt-3">
      <?= csrf_field() ?><input type="hidden" name="action" value="set_opening"><input type="hidden" name="account_id" value="<?= (int)$a['id'] ?>"><input type="hidden" name="temple" value="<?= $tid ?>">
      <div><label>ยอดยกมา (บาท)</label><input name="opening_balance" inputmode="decimal" value="<?= e(number_format((float)$a['opening_balance'], 2, '.', '')) ?>" class="w-40"></div>
      <div><label>ณ วันเริ่มบัญชี (พ.ศ.)</label><?= date_be_field('opening_date', $a['opening_date'] ?: (date('Y') . '-01-01'), 15, 1) ?></div>
      <button class="btn btn-sm">บันทึกยอดยกมา</button>
      <?php if (!$a['opening_date']): ?><span class="text-sm text-amber-800">ยังไม่ได้ตั้งยอดยกมา — ยอดคงเหลือคิดจากรายการทั้งหมดโดยเริ่มที่ 0</span><?php endif; ?>
    </form>
    <?php if ($isBank): ?>
    <details class="mt-2"><summary class="cursor-pointer text-sm text-brand-700">แก้ไขข้อมูลบัญชี / ปิดใช้งาน</summary>
      <form method="post" class="mt-2 grid gap-2 sm:grid-cols-2 lg:grid-cols-4 [&_input]:w-full">
        <?= csrf_field() ?><input type="hidden" name="action" value="edit_bank"><input type="hidden" name="account_id" value="<?= (int)$a['id'] ?>"><input type="hidden" name="temple" value="<?= $tid ?>">
        <div><label>ชื่อบัญชี</label><input name="name" value="<?= e($a['name']) ?>" maxlength="150"></div>
        <div><label>ธนาคาร</label><input name="bank_name" value="<?= e($a['bank_name']) ?>" maxlength="100" required></div>
        <div><label>สาขา</label><input name="branch" value="<?= e($a['branch']) ?>" maxlength="100"></div>
        <div><label>เลขที่บัญชี</label><input name="account_no" value="<?= e($a['account_no']) ?>" maxlength="30" required></div>
        <div class="col-span-full"><button class="btn btn-sm">บันทึก</button></div>
      </form>
      <form method="post" class="mt-2" data-confirm="<?= $a['is_active'] ? 'ปิดใช้งานบัญชีนี้? (ยังแสดงในรายงานย้อนหลัง แต่เลือกบันทึกรายการใหม่ไม่ได้)' : 'เปิดใช้งานบัญชีนี้อีกครั้ง?' ?>">
        <?= csrf_field() ?><input type="hidden" name="action" value="toggle"><input type="hidden" name="account_id" value="<?= (int)$a['id'] ?>"><input type="hidden" name="temple" value="<?= $tid ?>">
        <button class="btn btn-sm btn-outline"><?= $a['is_active'] ? 'ปิดใช้งานบัญชีนี้' : 'เปิดใช้งานบัญชีนี้' ?></button></form>
    </details>
    <?php endif; ?>
    <?php elseif ($a['opening_date']): ?><div class="mt-2 text-sm text-stone-600">ยอดยกมา <?= money($a['opening_balance']) ?> บาท ณ <?= e(th_date($a['opening_date'])) ?></div><?php endif; ?>
  </section>
<?php endforeach; ?>
</div>

<?php if ($canEdit): ?>
<section class="mt-4 rounded-2xl border border-stone-200 bg-white p-4 shadow-sm">
  <h3>เพิ่มบัญชีธนาคาร</h3>
  <form method="post" class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4 [&_input]:w-full">
    <?= csrf_field() ?><input type="hidden" name="action" value="add_bank"><input type="hidden" name="temple" value="<?= $tid ?>">
    <div><label>ธนาคาร *</label><input name="bank_name" required maxlength="100" placeholder="เช่น กรุงไทย"></div>
    <div><label>สาขา</label><input name="branch" maxlength="100"></div>
    <div><label>เลขที่บัญชี *</label><input name="account_no" required maxlength="30" inputmode="numeric" placeholder="000-0-00000-0"></div>
    <div><label>ชื่อบัญชี</label><input name="name" maxlength="150" value="<?= e($temple['name']) ?>"></div>
    <div><label>ยอดยกมา (บาท)</label><input name="opening_balance" inputmode="decimal" value="0"></div>
    <div><label>ณ วันเริ่มบัญชี (พ.ศ.)</label><?= date_be_field('opening_date', date('Y') . '-01-01', 15, 1) ?></div>
    <div class="flex items-end"><button class="btn">เพิ่มบัญชี</button></div>
  </form>
</section>

<section class="mt-4 rounded-2xl border border-stone-200 bg-white p-4 shadow-sm">
  <h3>โอน / นำฝาก / ถอน ระหว่างบัญชี</h3>
  <p class="mb-2 text-sm text-stone-600">เช่น นำเงินสดฝากธนาคาร (จากเงินสด → ธนาคาร), ถอนเงินจากธนาคาร (ธนาคาร → เงินสด) ระบบบันทึกเป็นรายจ่ายของบัญชีต้นทางและรายรับของบัญชีปลายทางในรายงานแบบที่ 1–2 แต่ไม่นับเป็นรายรับ-รายจ่ายของวัดในแดชบอร์ดและรายงานสรุป</p>
  <?php $active = array_filter($accounts, fn($a) => $a['is_active']); ?>
  <form method="post" class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4 [&_input]:w-full [&_select]:w-full">
    <?= csrf_field() ?><input type="hidden" name="action" value="transfer"><input type="hidden" name="temple" value="<?= $tid ?>">
    <div><label>จากบัญชี</label><select name="from_id"><?php foreach ($active as $a): ?><option value="<?= (int)$a['id'] ?>"><?= e(ledger_account_label($a)) ?></option><?php endforeach; ?></select></div>
    <div><label>ไปยังบัญชี</label><select name="to_id"><?php foreach ($active as $i => $a): ?><option value="<?= (int)$a['id'] ?>" <?= $i === 1 ? 'selected' : '' ?>><?= e(ledger_account_label($a)) ?></option><?php endforeach; ?></select></div>
    <div><label>วันที่ (พ.ศ.)</label><?= date_be_field('txn_date') ?></div>
    <div><label>จำนวนเงิน (บาท)</label><input name="amount" inputmode="decimal" required></div>
    <div><label>เลขที่เอกสาร</label><input name="doc_no" maxlength="50"></div>
    <div class="sm:col-span-2"><label>รายละเอียด</label><input name="description" maxlength="255" placeholder="เช่น นำเงินสดฝากธนาคาร กรุงไทย"></div>
    <div class="flex items-end"><button class="btn" <?= count($active) < 2 ? 'disabled' : '' ?>>บันทึกการโอน</button></div>
  </form>
  <?php if (count($active) < 2): ?><p class="mt-2 text-sm text-amber-800">ต้องมีอย่างน้อย 2 บัญชี (เงินสด + ธนาคาร) จึงจะโอนได้ — เพิ่มบัญชีธนาคารก่อน</p><?php endif; ?>
</section>
<?php endif; ?>

<?php if ($transfers): ?>
<section class="mt-4 overflow-x-auto rounded-2xl border border-stone-200 bg-white shadow-sm"><table class="data-table">
  <tr><th>วันที่</th><th>จากบัญชี</th><th>ไปยังบัญชี</th><th>รายละเอียด</th><th class="text-right">จำนวนเงิน</th><th></th></tr>
  <?php foreach ($transfers as $t): ?>
  <tr><td class="whitespace-nowrap"><?= e(th_date($t['txn_date'])) ?></td><td><?= e($label($t['from_id'])) ?></td><td><?= e($label($t['to_id'])) ?></td><td><?= e($t['description']) ?></td>
    <td class="text-right whitespace-nowrap"><?= money($t['amount']) ?></td>
    <td><?php if ($canEdit): ?><form method="post" data-confirm="ลบรายการโอน/ฝาก-ถอนนี้ (ทั้งสองบัญชี)?"><?= csrf_field() ?><input type="hidden" name="action" value="delete_transfer"><input type="hidden" name="transfer_id" value="<?= (int)$t['transfer_id'] ?>"><input type="hidden" name="temple" value="<?= $tid ?>"><button class="btn btn-sm btn-danger">ลบ</button></form><?php endif; ?></td></tr>
  <?php endforeach; ?>
</table></section>
<?php endif; ?>
<?php endif; ?>
<?php require __DIR__ . '/includes/footer.php'; ?>
