<?php
/** นำเข้ารายการจากไฟล์ statement ธนาคาร (CSV/Excel) เข้าบัญชีธนาคารของวัด: อัปโหลด → ตรวจคอลัมน์ → ดูตัวอย่าง/จัดหมวด → นำเข้า */
require __DIR__ . '/includes/bootstrap.php';
$u = require_login();
$pdo = db();
if (in_array($u['role'], ['province', 'subdistrict'], true)) {   // เจ้าคณะจังหวัด/ตำบลดูข้อมูลได้อย่างเดียว
    redirect('dashboard.php');
}

$tid = $u['role'] === 'temple' ? (int)$u['temple_id'] : (int)($_GET['temple'] ?? $_POST['temple'] ?? 0);
$temple = $tid && can_access_temple($tid) ? temple_row($tid) : null;
$banks = $temple ? array_values(array_filter(ledger_accounts($pdo, $tid, true), fn($a) => $a['kind'] === 'bank')) : [];
$aid = (int)($_GET['account'] ?? $_POST['account'] ?? ($banks[0]['id'] ?? 0));
$acc = null;
foreach ($banks as $b) {
    if ((int)$b['id'] === $aid) $acc = $b;
}
$tq = $u['role'] === 'temple' ? [] : ['temple' => $tid];
$url = fn(array $extra = []) => 'bank_import.php?' . http_build_query($tq + ($acc ? ['account' => (int)$acc['id']] : []) + $extra);
$sessKey = 'bank_import_' . $tid . '_' . (int)($acc['id'] ?? 0);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    if (!$temple || !$acc) {
        http_response_code(403);
        exit('ไม่มีสิทธิ์นำเข้ารายการของบัญชีนี้');
    }
    $act = $_POST['action'] ?? '';
    if ($act === 'upload') {
        $f = $_FILES['file'] ?? null;
        if (!$f || $f['error'] !== UPLOAD_ERR_OK || !is_uploaded_file($f['tmp_name'])) {
            flash(($f['error'] ?? 0) === UPLOAD_ERR_INI_SIZE || ($f['error'] ?? 0) === UPLOAD_ERR_FORM_SIZE ? 'ไฟล์ใหญ่เกินไป (ไม่เกิน 5 MB)' : 'กรุณาเลือกไฟล์ที่จะนำเข้า', 'err');
            redirect($url());
        }
        [$rows, $err] = bank_import_read($f['tmp_name'], (string)$f['name']);
        if ($rows === null) {
            flash($err, 'err');
            redirect($url());
        }
        $det = bank_import_detect($rows);
        $_SESSION[$sessKey] = ['rows' => $rows, 'file' => mb_substr(basename((string)$f['name']), 0, 100), 'header' => $det['header'], 'map' => $det['map']];
        redirect($url(['step' => 'preview']));
    }
    $state = $_SESSION[$sessKey] ?? null;
    if (!$state) {
        flash('หมดเวลาหรือยังไม่ได้อัปโหลดไฟล์ กรุณาอัปโหลดใหม่', 'err');
        redirect($url());
    }
    if ($act === 'remap') {
        $map = [];
        foreach (['date', 'desc', 'debit', 'credit', 'amount', 'ref'] as $fld) {
            $v = $_POST['col_' . $fld] ?? '';
            $map[$fld] = $v === '' ? null : max(0, min(60, (int)$v));
        }
        $_SESSION[$sessKey]['map'] = array_filter($map, fn($v) => $v !== null);
        $_SESSION[$sessKey]['header'] = max(-1, min(200, (int)($_POST['header'] ?? 0) - 1));   // ฟอร์มนับแถวจาก 1 (0 = ไม่มีหัวตาราง)
        redirect($url(['step' => 'preview']));
    }
    if ($act === 'cancel') {
        unset($_SESSION[$sessKey]);
        redirect($url());
    }
    if ($act === 'import') {
        $impRows = bank_import_rows($state['rows'], $state['map'], $state['header']);
        $sel = [];
        foreach ((array)($_POST['row'] ?? []) as $i => $r) {
            if (is_array($r)) $sel[(int)$i] = ['include' => !empty($r['include']), 'category' => $r['category'] ?? 0, 'desc' => $r['desc'] ?? null];
        }
        $res = bank_import_apply($pdo, $tid, $acc, $impRows, $sel, (int)$u['id']);
        unset($_SESSION[$sessKey]);
        $msg = "นำเข้าแล้ว {$res['done']} รายการ";
        if ($res['skipped']) $msg .= ' · ข้าม ' . count($res['skipped']) . ' รายการ (' . implode('; ', array_slice($res['skipped'], 0, 5)) . (count($res['skipped']) > 5 ? ' …' : '') . ')';
        flash($msg, $res['done'] ? 'ok' : 'err');
        redirect($url(['done' => 1]));
    }
    redirect($url());
}

$state = $acc ? ($_SESSION[$sessKey] ?? null) : null;
$preview = ($state && ($_GET['step'] ?? '') === 'preview') ? $state : null;
$impRows = [];
if ($preview) {
    $impRows = bank_import_prepare($pdo, $tid, $acc, bank_import_rows($preview['rows'], $preview['map'], $preview['header']));
}
$cats = ['income' => [], 'expense' => []];
foreach ($pdo->query('SELECT id, type, name FROM categories WHERE is_active = 1 AND is_transfer = 0 ORDER BY sort_order, name')->fetchAll() as $c) {
    $cats[$c['type']][] = $c;
}
$groups = temples_grouped();
$fieldLabels = ['date' => 'วันที่', 'desc' => 'รายการ/รายละเอียด', 'debit' => 'ถอน (เงินออก)', 'credit' => 'ฝาก (เงินเข้า)', 'amount' => 'จำนวนเงินช่องเดียว (+/−)', 'ref' => 'เลขที่อ้างอิง'];

$pageTitle = 'นำเข้ารายการจากธนาคาร';
require __DIR__ . '/includes/header.php';
?>
<div class="flex flex-wrap items-center justify-between gap-2"><h2>นำเข้ารายการจากไฟล์ธนาคาร<?= $temple ? ' · ' . e($temple['name']) : '' ?></h2>
  <?php if ($temple): ?><a class="btn btn-outline btn-sm" href="accounts.php<?= $u['role'] === 'temple' ? '' : '?temple=' . $tid ?>">บัญชีเงินสด/ธนาคาร</a><?php endif; ?></div>

<form method="get" class="my-4 flex flex-wrap items-end gap-3">
  <?php if ($u['role'] !== 'temple'): ?>
  <div><label>วัด</label><select name="temple" onchange="this.form.submit()"><option value="0">— เลือกวัด —</option>
    <?php foreach ($groups as $dn => $list): ?><optgroup label="<?= e(unit_district()) ?><?= e($dn) ?>"><?php foreach ($list as $t): ?><option value="<?= (int)$t['id'] ?>" <?= $t['id'] == $tid ? 'selected' : '' ?>><?= e($t['name']) ?></option><?php endforeach; ?></optgroup><?php endforeach; ?></select></div>
  <?php endif; ?>
  <?php if ($banks): ?><div><label>บัญชีธนาคาร</label><select name="account" onchange="this.form.submit()"><?php foreach ($banks as $b): ?><option value="<?= (int)$b['id'] ?>" <?= $b['id'] == $aid ? 'selected' : '' ?>><?= e(ledger_account_label($b)) ?></option><?php endforeach; ?></select></div><?php endif; ?>
  <noscript><button class="btn">แสดง</button></noscript>
</form>

<?php if (!$temple): ?>
  <p class="text-stone-600">เลือกวัดเพื่อนำเข้ารายการ</p>
<?php elseif (!$acc): ?>
  <p class="text-stone-600">วัดนี้ยังไม่มีบัญชีธนาคารที่ใช้งานอยู่ — เพิ่มได้ที่หน้า <a class="underline" href="accounts.php<?= $u['role'] === 'temple' ? '' : '?temple=' . $tid ?>">บัญชีเงินสด/ธนาคาร</a></p>
<?php elseif (!$preview): ?>
<section class="card">
  <p class="mb-4 text-sm text-stone-600">ดาวน์โหลด statement จากธนาคาร (Internet/Mobile Banking) เป็นไฟล์ <strong>.csv</strong> หรือ <strong>.xlsx</strong> แล้วอัปโหลดที่นี่ ระบบจะเดาคอลัมน์ วันที่ รายการ ฝาก/ถอน ให้เอง (แก้ได้ก่อนนำเข้า) รองรับวันที่ ค.ศ./พ.ศ. จากนั้นเลือกหมวดของแต่ละรายการ ระบบจะจำหมวดที่เคยใช้กับรายการชื่อเดียวกัน และข้ามรายการที่มีอยู่แล้วให้ ไฟล์ไม่เกิน 5 MB (<?= number_format(BANK_IMPORT_MAX_ROWS) ?> แถว)</p>
  <?php if (!xlsx_available()): ?><p class="mb-4 rounded-lg bg-amber-50 px-3 py-2 text-sm text-amber-950">เซิร์ฟเวอร์ยังไม่ได้เปิดส่วนขยาย PHP <code>zip</code> จึงอ่านไฟล์ .xlsx ไม่ได้ — ใช้ไฟล์ CSV แทนได้</p><?php endif; ?>
  <form method="post" enctype="multipart/form-data" class="flex flex-wrap items-end gap-3">
    <?= csrf_field() ?><input type="hidden" name="action" value="upload"><input type="hidden" name="account" value="<?= (int)$acc['id'] ?>"><?php foreach ($tq as $k => $v): ?><input type="hidden" name="<?= $k ?>" value="<?= (int)$v ?>"><?php endforeach; ?>
    <div><label>ไฟล์ statement (.csv / .xlsx)</label><input type="file" name="file" accept=".csv,.txt,.xlsx" required></div>
    <button class="btn" type="submit">อัปโหลดและตรวจ</button>
  </form>
  <p class="mt-3 text-xs text-stone-500">รายการที่นำเข้าจะบันทึกเข้าบัญชี <?= e(ledger_account_label($acc)) ?> ของวัดนี้ · เดือนที่ปิดงวดแล้วนำเข้าไม่ได้ · ไม่ได้เก็บไฟล์ต้นฉบับไว้ในระบบ</p>
</section>
<?php else:
    $header = $preview['header'];
    $cols = $preview['rows'][max(0, $header)] ?? [];
    $ncols = max(array_map('count', array_slice($preview['rows'], 0, 40)));
    $nOk = count(array_filter($impRows, fn($i) => $i['error'] === '' && !$i['dup'] && $i['locked'] === ''));
?>
<section class="card">
  <h3>ไฟล์ <?= e($preview['file']) ?> — ตรวจคอลัมน์</h3>
  <?php if (!isset($preview['map']['date'])): ?><p class="mb-2 rounded-lg bg-amber-50 px-3 py-2 text-sm text-amber-950">ระบบเดาหัวตารางไม่ได้ กรุณาเลือกคอลัมน์ด้านล่างให้ตรงกับไฟล์ แล้วกด "ใช้คอลัมน์นี้"</p><?php endif; ?>
  <form method="post" class="grid gap-2 sm:grid-cols-3 lg:grid-cols-4 [&_select]:w-full [&_input]:w-full">
    <?= csrf_field() ?><input type="hidden" name="action" value="remap"><input type="hidden" name="account" value="<?= (int)$acc['id'] ?>"><?php foreach ($tq as $k => $v): ?><input type="hidden" name="<?= $k ?>" value="<?= (int)$v ?>"><?php endforeach; ?>
    <div><label>แถวหัวตารางอยู่แถวที่ (0 = ไม่มีหัวตาราง)</label><input type="number" name="header" min="-1" max="200" value="<?= (int)$header + 1 ?>"></div>
    <?php foreach ($fieldLabels as $f => $lab): ?>
    <div><label><?= e($lab) ?></label><select name="col_<?= $f ?>"><option value="">— ไม่มี —</option>
      <?php for ($c = 0; $c < $ncols; $c++): $h = trim((string)($preview['rows'][max(0, $header)][$c] ?? '')); ?><option value="<?= $c ?>" <?= isset($preview['map'][$f]) && $preview['map'][$f] === $c ? 'selected' : '' ?>><?= chr(65 + $c % 26) ?><?= $h !== '' ? ' · ' . e(mb_strimwidth($h, 0, 24, '…')) : '' ?></option><?php endfor; ?></select></div>
    <?php endforeach; ?>
    <div class="flex items-end"><button class="btn btn-outline btn-sm">ใช้คอลัมน์นี้</button></div>
  </form>
</section>

<form method="post">
  <?= csrf_field() ?><input type="hidden" name="action" value="import"><input type="hidden" name="account" value="<?= (int)$acc['id'] ?>"><?php foreach ($tq as $k => $v): ?><input type="hidden" name="<?= $k ?>" value="<?= (int)$v ?>"><?php endforeach; ?>
  <p class="mb-2 text-sm text-stone-700">พบ <strong><?= count($impRows) ?></strong> รายการ · พร้อมนำเข้า <strong><?= $nOk ?></strong> · ซ้ำกับในระบบ <strong><?= count(array_filter($impRows, fn($i) => $i['dup'])) ?></strong> · มีปัญหา <strong><?= count(array_filter($impRows, fn($i) => $i['error'] !== '' || $i['locked'] !== '')) ?></strong></p>
  <div class="card-table"><table class="data-table">
    <tr><th>นำเข้า</th><th>แถว</th><th>วันที่</th><th>รายละเอียด</th><th class="text-right">เงินเข้า</th><th class="text-right">เงินออก</th><th>หมวด</th><th>หมายเหตุ</th></tr>
    <?php foreach ($impRows as $i => $it): $bad = $it['error'] !== '' || $it['locked'] !== ''; $on = !$bad && !$it['dup']; ?>
    <tr class="<?= $bad ? 'bg-red-50' : ($it['dup'] ? 'bg-amber-50' : '') ?>">
      <td><input type="checkbox" name="row[<?= $i ?>][include]" value="1" <?= $on ? 'checked' : '' ?> <?= $bad ? 'disabled' : '' ?>></td>
      <td class="text-sm text-stone-500"><?= (int)$it['line'] ?></td>
      <td class="whitespace-nowrap"><?= $it['date'] ? e(th_date($it['date'])) : '-' ?></td>
      <td><?php if (!$bad): ?><input name="row[<?= $i ?>][desc]" value="<?= e($it['desc']) ?>" maxlength="255" class="w-full min-w-[12rem]"><?php else: ?><?= e($it['desc']) ?><?php endif; ?></td>
      <td class="text-right text-emerald-700"><?= $it['amount'] > 0 ? money($it['amount']) : '' ?></td>
      <td class="text-right text-red-700"><?= $it['amount'] < 0 ? money(-$it['amount']) : '' ?></td>
      <td><?php if (!$bad): ?><select name="row[<?= $i ?>][category]"><option value="0">— เลือกหมวด —</option>
        <?php foreach ($cats[$it['type']] as $c): ?><option value="<?= (int)$c['id'] ?>" <?= $it['suggest'] === (int)$c['id'] ? 'selected' : '' ?>><?= e($c['name']) ?></option><?php endforeach; ?></select><?php endif; ?></td>
      <td class="text-sm <?= $bad ? 'text-red-700' : 'text-amber-800' ?>"><?= e($it['error'] ?: ($it['locked'] ? 'เดือนที่ปิดงวดแล้ว' : ($it['dup'] ? 'มีรายการนี้ในระบบแล้ว' : ''))) ?></td>
    </tr>
    <?php endforeach; ?>
    <?php if (!$impRows): ?><tr><td colspan="8" class="text-stone-500">ไม่พบรายการที่อ่านได้ — ตรวจคอลัมน์ด้านบน</td></tr><?php endif; ?>
  </table></div>
  <div class="mt-3 flex flex-wrap gap-2">
    <?php if ($impRows): ?><button class="btn" type="submit">นำเข้ารายการที่เลือก</button><?php endif; ?>
  </div>
</form>
<form method="post" class="mt-2"><?= csrf_field() ?><input type="hidden" name="action" value="cancel"><input type="hidden" name="account" value="<?= (int)$acc['id'] ?>"><?php foreach ($tq as $k => $v): ?><input type="hidden" name="<?= $k ?>" value="<?= (int)$v ?>"><?php endforeach; ?><button class="btn btn-outline btn-sm">ยกเลิก</button></form>
<?php endif; ?>
<?php require __DIR__ . '/includes/footer.php'; ?>
