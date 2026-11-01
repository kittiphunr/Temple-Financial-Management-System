<?php
/** ค้นหารายการรายรับ-รายจ่ายขั้นสูง ข้ามเดือน/ข้ามปี ตามขอบเขตของบทบาท พร้อมส่งออก CSV และชุดกรองที่บันทึกไว้ */
require __DIR__ . '/includes/bootstrap.php';
$u = require_login();
$pdo = db();
$canWrite = in_array($u['role'], ['admin', 'district', 'temple'], true);   // เจ้าคณะจังหวัด/ตำบลดูได้อย่างเดียว
$savedOk = search_saved_available();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $act = $_POST['action'] ?? '';
    $back = 'search.php';
    if ($savedOk && $act === 'save_filter') {
        parse_str((string)($_POST['qs'] ?? ''), $in);
        [$sf, $se] = search_filters($in);
        [$ok, $msg] = $se ? [false, implode(' ', $se)] : search_saved_add($pdo, (int)$u['id'], (string)($_POST['name'] ?? ''), search_params($sf));
        flash($msg, $ok ? 'ok' : 'err');
        $ok && audit('search_save', 'saved_search', (int)$pdo->lastInsertId());
        $back = 'search.php' . ($se ? '' : '?' . http_build_query(search_params($sf)));
    } elseif ($savedOk && $act === 'delete_filter') {
        search_saved_delete($pdo, (int)$u['id'], (int)($_POST['id'] ?? 0)) && flash('ลบชุดกรองแล้ว');
    }
    redirect($back);
}

[$flt, $errs] = search_filters($_GET);
$params = search_params($flt);

if (!$errs && ($_GET['export'] ?? '') === 'csv') {
    [$from, $p] = search_from_where($flt);
    $st = $pdo->prepare(search_select() . $from . ' ORDER BY ' . SEARCH_SORTS[$flt['sort']][1] . ' LIMIT ' . SEARCH_EXPORT_MAX);
    $st->execute($p);
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="search-' . date('Ymd-His') . '.csv"');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF");
    fputcsv($out, ['วันที่', unit_district(), 'วัด', 'ประเภท', 'หมวด', 'บัญชี', 'กองทุน/โครงการ', 'เลขที่เอกสาร', 'รายละเอียด', 'จำนวนเงิน', 'จำนวนไฟล์แนบ']);
    $safe = fn($v) => is_string($v) && preg_match('/^[=+\-@\t\r]/', $v) ? "'" . $v : $v;   // กัน CSV formula injection
    $n = 0;
    while ($r = $st->fetch()) {
        $acc = ($r['acc_kind'] ?? 'cash') === 'bank' ? ledger_account_label(['kind' => 'bank', 'bank_name' => $r['acc_bank'], 'account_no' => $r['acc_no']]) : 'เงินสด';
        fputcsv($out, array_map($safe, [th_date_short($r['txn_date']), $r['district'], $r['temple'], $r['type'] === 'income' ? 'รายรับ' : 'รายจ่าย', $r['category'], $acc, $r['fund_name'] ?? '',
            $r['doc_no'], $r['description'], $r['amount'], $r['att_n']]));
        $n++;
    }
    audit('export', 'search', null, $n . ' rows');
    exit;
}

$tot = $errs ? ['n' => 0, 'income' => 0.0, 'expense' => 0.0] : search_totals($pdo, $flt);
$pages = max(1, (int)ceil($tot['n'] / SEARCH_PAGE_SIZE));
$flt['page'] = min($flt['page'], $pages);
$rows = $errs || !$tot['n'] ? [] : search_rows($pdo, $flt, SEARCH_PAGE_SIZE, ($flt['page'] - 1) * SEARCH_PAGE_SIZE);
$groups = temples_grouped();
$districts = in_array($u['role'], ['admin', 'province'], true) ? $pdo->query('SELECT id, name FROM districts ORDER BY sort_order, id')->fetchAll() : [];
$cats = ['income' => [], 'expense' => []];
foreach ($pdo->query('SELECT id, type, name FROM categories WHERE is_transfer = 0 ORDER BY sort_order, name')->fetchAll() as $c) {
    $cats[$c['type']][] = $c;
}
$funds = [];
if (fund_available()) {
    [$sc, $sp] = scope_sql('t');
    $fs = $pdo->prepare("SELECT f.id, f.name, t.name AS temple FROM funds f JOIN temples t ON t.id = f.temple_id WHERE t.is_active = 1 $sc ORDER BY f.name, f.id LIMIT 500");
    $fs->execute($sp);
    $funds = $fs->fetchAll();
}
$saved = $savedOk ? search_saved_list($pdo, (int)$u['id']) : [];
$qs = http_build_query($params);
$pageUrl = fn(int $p) => 'search.php?' . http_build_query($params + ($p > 1 ? ['page' => $p] : []));
$sel = fn($a, $b) => (string)$a === (string)$b ? 'selected' : '';

$pageTitle = 'ค้นหารายการ';
require __DIR__ . '/includes/header.php';
?>
<div class="flex flex-wrap items-center justify-between gap-2"><h2>ค้นหารายการรายรับ-รายจ่าย</h2>
  <span class="text-sm text-stone-600">ค้นข้ามเดือน/ข้ามปีได้ ไม่นับรายการที่ลบแล้วและรายการโอน/ฝาก-ถอน</span></div>

<?php if ($saved): ?>
<div class="my-3 flex flex-wrap items-center gap-2 text-sm print:hidden"><span class="text-stone-600">ชุดกรองที่บันทึกไว้:</span>
  <?php foreach ($saved as $s): ?>
  <span class="inline-flex items-center gap-1 rounded-full border border-stone-300 bg-white pl-3">
    <a class="font-semibold text-brand-700" href="search.php?<?= e(http_build_query($s['params'])) ?>"><?= e($s['name']) ?></a>
    <form method="post" class="inline" data-confirm="ลบชุดกรองนี้?"><?= csrf_field() ?><input type="hidden" name="action" value="delete_filter"><input type="hidden" name="id" value="<?= $s['id'] ?>">
      <button class="px-2 py-1 text-stone-500 hover:text-red-700" aria-label="ลบชุดกรอง <?= e($s['name']) ?>"><?= icon('x') ?></button></form>
  </span>
  <?php endforeach; ?>
</div>
<?php endif; ?>

<form method="get" class="card my-4 grid gap-3 sm:grid-cols-2 lg:grid-cols-4 [&_input:not([type=checkbox])]:w-full [&_select]:w-full print:hidden">
  <div class="sm:col-span-2"><label>ข้อความ (รายละเอียด / เลขที่เอกสาร / จำนวนเงินตรงตัว)</label><input name="q" maxlength="100" value="<?= e($flt['q']) ?>" placeholder="เช่น ค่าไฟ หรือ 1200"></div>
  <div><label>ประเภท</label><select name="type"><option value="all">รายรับและรายจ่าย</option><option value="income" <?= $sel($flt['type'], 'income') ?>>รายรับ</option><option value="expense" <?= $sel($flt['type'], 'expense') ?>>รายจ่าย</option></select></div>
  <div><label>หมวด</label><select name="category"><option value="0">ทุกหมวด</option>
    <?php foreach (['income' => 'รายรับ', 'expense' => 'รายจ่าย'] as $t => $tl): ?><optgroup label="<?= $tl ?>"><?php foreach ($cats[$t] as $c): ?><option value="<?= (int)$c['id'] ?>" <?= $sel($flt['category'], $c['id']) ?>><?= e($c['name']) ?></option><?php endforeach; ?></optgroup><?php endforeach; ?></select></div>
  <div><label>ตั้งแต่วันที่</label><?= date_be_field('from', $flt['from'], 10, 1, true) ?></div>
  <div><label>ถึงวันที่</label><?= date_be_field('to', $flt['to'], 10, 1, true) ?></div>
  <div><label>จำนวนเงินตั้งแต่ (บาท)</label><input name="min" inputmode="decimal" value="<?= e((string)($flt['min'] ?? '')) ?>"></div>
  <div><label>ถึง (บาท)</label><input name="max" inputmode="decimal" value="<?= e((string)($flt['max'] ?? '')) ?>"></div>
  <?php if ($districts): ?><div><label><?= e(unit_district()) ?></label><select name="district"><option value="0">ทุก<?= e(unit_district()) ?></option>
    <?php foreach ($districts as $d): ?><option value="<?= (int)$d['id'] ?>" <?= $sel($flt['district'], $d['id']) ?>><?= e($d['name']) ?></option><?php endforeach; ?></select></div><?php endif; ?>
  <?php if ($u['role'] !== 'temple'): ?><div><label>วัด</label><select name="temple"><option value="0">ทุกวัด</option>
    <?php foreach ($groups as $dn => $list): ?><optgroup label="<?= e(unit_district()) ?><?= e($dn) ?>"><?php foreach ($list as $t): ?><option value="<?= (int)$t['id'] ?>" <?= $sel($flt['temple'], $t['id']) ?>><?= e($t['name']) ?></option><?php endforeach; ?></optgroup><?php endforeach; ?></select></div><?php endif; ?>
  <div><label>บัญชี</label><select name="account"><option value="all">ทุกบัญชี</option><option value="cash" <?= $sel($flt['account'], 'cash') ?>>เงินสด / เช็ค</option><option value="bank" <?= $sel($flt['account'], 'bank') ?>>ธนาคาร</option></select></div>
  <?php if ($funds): ?><div><label>กองทุน/โครงการ</label><select name="fund"><option value="all">ทั้งหมด</option><option value="none" <?= $sel($flt['fund'], 'none') ?>>เงินทั่วไป (ไม่ระบุกองทุน)</option>
    <?php foreach ($funds as $fu): ?><option value="<?= (int)$fu['id'] ?>" <?= $sel($flt['fund'], $fu['id']) ?>><?= e($fu['name']) ?><?= $u['role'] === 'temple' ? '' : ' · ' . e($fu['temple']) ?></option><?php endforeach; ?></select></div><?php endif; ?>
  <?php if (attachments_available()): ?><div><label>หลักฐานแนบ</label><select name="att"><option value="all">ทั้งหมด</option><option value="yes" <?= $sel($flt['att'], 'yes') ?>>มีไฟล์แนบ</option><option value="no" <?= $sel($flt['att'], 'no') ?>>ไม่มีไฟล์แนบ</option></select></div><?php endif; ?>
  <div><label>เรียงตาม</label><select name="sort"><?php foreach (SEARCH_SORTS as $k => [$lab]): ?><option value="<?= $k ?>" <?= $sel($flt['sort'], $k) ?>><?= e($lab) ?></option><?php endforeach; ?></select></div>
  <div class="col-span-full flex flex-wrap gap-2"><button class="btn" type="submit"><?= icon('search') ?> ค้นหา</button><a class="btn btn-outline" href="search.php">ล้างเงื่อนไข</a></div>
</form>

<?php foreach ($errs as $er): ?><p class="mb-2 rounded-lg border border-red-300 bg-red-50 px-3 py-2 text-sm text-red-900"><?= e($er) ?></p><?php endforeach; ?>

<?php if (!$errs): ?>
<div class="mb-3 grid gap-3 sm:grid-cols-3">
  <div class="card"><div class="text-sm text-stone-600">พบ</div><div class="text-2xl font-bold"><?= number_format($tot['n']) ?> <span class="text-base font-semibold">รายการ</span></div></div>
  <div class="card border-emerald-200 bg-emerald-50"><div class="text-sm text-emerald-900">รายรับรวม</div><div class="text-2xl font-bold text-emerald-800"><?= money($tot['income']) ?></div></div>
  <div class="card border-red-200 bg-red-50"><div class="text-sm text-red-900">รายจ่ายรวม</div><div class="text-2xl font-bold text-red-800"><?= money($tot['expense']) ?></div></div>
</div>
<div class="mb-3 flex flex-wrap items-center gap-2 print:hidden">
  <a class="btn btn-outline btn-sm" href="search.php?<?= e(http_build_query($params + ['export' => 'csv'])) ?>"><?= icon('download') ?> ส่งออก CSV (Excel)<?= $tot['n'] > SEARCH_EXPORT_MAX ? ' · ' . number_format(SEARCH_EXPORT_MAX) . ' รายการแรก' : '' ?></a>
  <?php if ($savedOk && $params): ?>
  <form method="post" class="flex flex-wrap items-center gap-2"><?= csrf_field() ?><input type="hidden" name="action" value="save_filter"><input type="hidden" name="qs" value="<?= e($qs) ?>">
    <input name="name" required minlength="2" maxlength="60" placeholder="ชื่อชุดกรอง" class="w-48 ctl-sm"><button class="btn btn-sm btn-outline">บันทึกชุดกรองนี้</button></form>
  <?php endif; ?>
</div>

<div class="card-table"><table class="data-table">
  <thead><tr><th>วันที่</th><th>วัด</th><th>ประเภท</th><th>หมวด</th><th>บัญชี</th><th>รายละเอียด</th><th>เลขที่</th><th class="text-right whitespace-nowrap">จำนวนเงิน</th><th class="print:hidden"></th></tr></thead>
  <tbody>
  <?php foreach ($rows as $r): $inc = $r['type'] === 'income'; ?>
  <tr>
    <td class="whitespace-nowrap"><?= e(th_date($r['txn_date'])) ?></td>
    <td><?= e($r['temple']) ?><br><small class="text-stone-500"><?= e(abbr_district()) ?><?= e($r['district']) ?></small></td>
    <td><span class="badge <?= $inc ? 'badge-ok' : 'badge-err' ?>"><?= $inc ? 'รายรับ' : 'รายจ่าย' ?></span></td>
    <td><?= e($r['category']) ?><?= !empty($r['fund_name']) ? '<br><small class="text-stone-500">' . icon('layers') . ' ' . e($r['fund_name']) . '</small>' : '' ?></td>
    <td class="text-sm"><?= ($r['acc_kind'] ?? 'cash') === 'bank' ? e(ledger_account_label(['kind' => 'bank', 'bank_name' => $r['acc_bank'], 'account_no' => $r['acc_no']])) : 'เงินสด' ?></td>
    <td><?= e((string)$r['description']) ?></td>
    <td><?= e((string)$r['doc_no']) ?></td>
    <td class="text-right whitespace-nowrap <?= $inc ? 'text-emerald-700' : 'text-red-700' ?>"><?= money($r['amount']) ?></td>
    <td class="whitespace-nowrap print:hidden">
      <?php if (attachments_available() && (int)$r['att_n'] > 0): ?><a class="btn btn-sm btn-outline" href="attachments.php?txn=<?= (int)$r['id'] ?>" title="หลักฐานแนบ" aria-label="หลักฐานแนบ"><?= icon('paperclip') ?> <?= (int)$r['att_n'] ?></a><?php endif; ?>
      <?php if ($canWrite): ?><a class="btn btn-sm btn-outline" href="transactions.php?type=<?= e($r['type']) ?>&edit=<?= (int)$r['id'] ?>"><?= icon('edit-2') ?> แก้ไข</a><?php endif; ?>
    </td>
  </tr>
  <?php endforeach; ?>
  <?php if (!$rows): ?><tr><td colspan="9" class="text-stone-500">ไม่พบรายการตามเงื่อนไขที่เลือก</td></tr><?php endif; ?>
  </tbody>
</table></div>
<?php if ($pages > 1): ?>
<nav class="mt-3 flex flex-wrap items-center gap-2 text-sm print:hidden" aria-label="เลือกหน้า">
  <?php if ($flt['page'] > 1): ?><a class="btn btn-sm btn-outline" href="<?= e($pageUrl($flt['page'] - 1)) ?>">← ก่อนหน้า</a><?php endif; ?>
  <span>หน้า <?= $flt['page'] ?> / <?= $pages ?> (<?= SEARCH_PAGE_SIZE ?> รายการต่อหน้า)</span>
  <?php if ($flt['page'] < $pages): ?><a class="btn btn-sm btn-outline" href="<?= e($pageUrl($flt['page'] + 1)) ?>">ถัดไป →</a><?php endif; ?>
</nav>
<?php endif; ?>
<?php endif; ?>
<?php require __DIR__ . '/includes/footer.php'; ?>
