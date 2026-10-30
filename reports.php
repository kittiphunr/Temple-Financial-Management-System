<?php
require __DIR__ . '/includes/bootstrap.php';
require __DIR__ . '/includes/stats.php';
$u = require_login();

$pdo = db();
$year = (int)($_GET['year'] ?? be_year());
$dist = (int)($_GET['district'] ?? 0);
$temple = (int)($_GET['temple'] ?? 0);
[$from, $to] = year_range($year);
[$sc, $sp] = scope_sql('t');

$where = " WHERE x.is_deleted = 0 AND x.transfer_id IS NULL AND x.txn_date BETWEEN ? AND ? $sc";
$params = array_merge([$from, $to], $sp);
if ($dist)   { $where .= ' AND t.district_id = ?'; $params[] = $dist; }
if ($temple) { $where .= ' AND t.id = ?'; $params[] = $temple; }
$join = ' FROM transactions x JOIN temples t ON t.id = x.temple_id JOIN districts d ON d.id = t.district_id JOIN categories c ON c.id = x.category_id';

function q(PDO $pdo, string $sql, array $p): array
{
    $st = $pdo->prepare($sql);
    $st->execute($p);
    return $st->fetchAll();
}

// ส่งออก CSV (UTF-8 BOM เพื่อให้ Excel อ่านภาษาไทยได้)
if (($_GET['export'] ?? '') === 'csv') {
    $rows = q($pdo, "SELECT x.txn_date, d.name AS district, t.name AS temple, x.type, c.name AS category, x.doc_no, x.description, x.amount $join $where ORDER BY x.txn_date, x.id", $params);
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="report-' . $year . '.csv"');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF");
    fputcsv($out, ['วันที่', unit_district(), 'วัด', 'ประเภท', 'หมวด', 'เลขที่เอกสาร', 'รายละเอียด', 'จำนวนเงิน']);
    foreach ($rows as $r) {
        // ป้องกัน CSV/Excel formula injection
        foreach (['district', 'temple', 'category', 'doc_no', 'description'] as $k) {
            if (isset($r[$k]) && preg_match('/^[=+\-@\t\r]/', $r[$k])) $r[$k] = "'" . $r[$k];
        }
        fputcsv($out, [th_date_short($r['txn_date']), $r['district'], $r['temple'], $r['type'] === 'income' ? 'รายรับ' : 'รายจ่าย', $r['category'], $r['doc_no'], $r['description'], $r['amount']]);
    }
    audit('export', 'report', null, "year $year");
    exit;
}

$monthly = q($pdo, "SELECT MONTH(x.txn_date) m,
    SUM(CASE WHEN x.type='income' THEN x.amount ELSE 0 END) inc,
    SUM(CASE WHEN x.type='expense' THEN x.amount ELSE 0 END) exp $join $where GROUP BY m ORDER BY m", $params);
$byCat = q($pdo, "SELECT x.type, c.name, SUM(x.amount) total $join $where GROUP BY x.type, c.id, c.name ORDER BY x.type, total DESC", $params);
$byTemple = q($pdo, "SELECT d.name district, t.name temple,
    SUM(CASE WHEN x.type='income' THEN x.amount ELSE 0 END) inc,
    SUM(CASE WHEN x.type='expense' THEN x.amount ELSE 0 END) exp $join $where GROUP BY d.id, d.sort_order, t.id, t.name ORDER BY d.sort_order, t.name", $params);
$sum = summary($year, $dist, $temple);

$mon = array_fill(1, 12, ['inc' => 0, 'exp' => 0]);
foreach ($monthly as $r) $mon[(int)$r['m']] = ['inc' => (float)$r['inc'], 'exp' => (float)$r['exp']];

$districts = $pdo->query('SELECT id, name FROM districts ORDER BY sort_order')->fetchAll();
$groups = temples_grouped();
$pageTitle = 'รายงาน';
require __DIR__ . '/includes/header.php';
?>
<h2 class="print:hidden">รายงานสรุปรายรับ-รายจ่าย ปี พ.ศ. <?= $year ?></h2>
<form class="my-4 flex flex-wrap items-end gap-3 print:hidden" method="get">
  <div><label>ปี พ.ศ.</label><select name="year"><?php for ($y = be_year() + 1; $y >= be_year() - 6; $y--): ?><option <?= $y === $year ? 'selected' : '' ?>><?= $y ?></option><?php endfor; ?></select></div>
  <div><label><?= e(unit_district()) ?></label><select name="district"><option value="0">ทุก<?= e(unit_district()) ?></option>
    <?php foreach ($districts as $d): ?><option value="<?= (int)$d['id'] ?>" <?= $dist === (int)$d['id'] ? 'selected' : '' ?>><?= e($d['name']) ?></option><?php endforeach; ?></select></div>
  <div><label>วัด</label><select name="temple"><option value="0">ทุกวัด</option>
    <?php foreach ($groups as $dn => $list): ?><optgroup label="<?= e(unit_district()) ?><?= e($dn) ?>"><?php foreach ($list as $t): ?><option value="<?= (int)$t['id'] ?>" <?= $temple === (int)$t['id'] ? 'selected' : '' ?>><?= e($t['name']) ?></option><?php endforeach; ?></optgroup><?php endforeach; ?></select></div>
  <button class="btn" type="submit">แสดงรายงาน</button>
  <a class="btn btn-outline" href="?<?= e(http_build_query(['year' => $year, 'district' => $dist, 'temple' => $temple, 'export' => 'csv'])) ?>">ส่งออก CSV (Excel)</a>
  <a class="btn btn-outline" href="statements.php<?= $temple ? '?temple=' . $temple : '' ?>">แบบฟอร์มรายงานประจำเดือน (แบบที่ 1–3)</a>
  <a class="btn btn-outline" href="annual.php?year=<?= $year ?><?= $temple ? '&temple=' . $temple : '' ?>">งบแสดงรายรับ-รายจ่ายประจำปี (รายวัด เทียบปีก่อน)</a>
  <button class="btn btn-outline" type="button" onclick="window.print()">พิมพ์ / บันทึก PDF</button>
</form>

<?php
$lhTemple = $temple ?: ($u['role'] === 'temple' ? (int)$u['temple_id'] : 0);
$lhDist = $lhTemple ? 0 : ($dist ?: ($u['role'] === 'district' ? (int)$u['district_id'] : 0));
?>
<?= letterhead($pdo, $lhTemple, $lhDist, 'รายงานสรุปรายรับ-รายจ่าย', 'ปี พ.ศ. ' . $year) ?>

<div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
  <div class="rounded-2xl border border-stone-200 bg-white p-4 shadow-sm"><div class="text-sm text-stone-500">รายรับรวม</div><div class="text-2xl font-bold text-emerald-700"><?= money($sum['income']) ?></div></div>
  <div class="rounded-2xl border border-stone-200 bg-white p-4 shadow-sm"><div class="text-sm text-stone-500">รายจ่ายรวม</div><div class="text-2xl font-bold text-red-700"><?= money($sum['expense']) ?></div></div>
  <div class="rounded-2xl border border-stone-200 bg-white p-4 shadow-sm"><div class="text-sm text-stone-500">คงเหลือ</div><div class="text-2xl font-bold text-sky-800"><?= money($sum['balance']) ?></div></div>
</div>

<h3 class="mt-6">รายเดือน</h3>
<div class="overflow-x-auto rounded-2xl border border-stone-200 bg-white shadow-sm"><table class="data-table">
  <tr><th>เดือน</th><th class="text-right whitespace-nowrap">รายรับ</th><th class="text-right whitespace-nowrap">รายจ่าย</th><th class="text-right whitespace-nowrap">คงเหลือ</th></tr>
  <?php foreach ($mon as $m => $v): ?>
  <tr><td><?= TH_MONTHS[$m] ?></td><td class="text-right whitespace-nowrap text-emerald-700"><?= money($v['inc']) ?></td><td class="text-right whitespace-nowrap text-red-700"><?= money($v['exp']) ?></td><td class="text-right whitespace-nowrap text-sky-800"><?= money($v['inc'] - $v['exp']) ?></td></tr>
  <?php endforeach; ?>
</table></div>

<div class="grid gap-4 lg:grid-cols-2">
  <div>
    <h3 class="mt-6">แยกตามหมวด</h3>
    <div class="overflow-x-auto rounded-2xl border border-stone-200 bg-white shadow-sm"><table class="data-table">
      <tr><th>ประเภท</th><th>หมวด</th><th class="text-right whitespace-nowrap">รวม</th></tr>
      <?php foreach ($byCat as $r): ?>
      <tr><td><?= $r['type'] === 'income' ? 'รายรับ' : 'รายจ่าย' ?></td><td><?= e($r['name']) ?></td><td class="text-right whitespace-nowrap"><?= money($r['total']) ?></td></tr>
      <?php endforeach; ?>
      <?php if (!$byCat): ?><tr><td colspan="3" class="text-stone-500">ไม่มีข้อมูล</td></tr><?php endif; ?>
    </table></div>
  </div>
  <div>
    <h3 class="mt-6">แยกตามวัด (จัดกลุ่มตาม<?= e(unit_district()) ?>)</h3>
    <div class="overflow-x-auto rounded-2xl border border-stone-200 bg-white shadow-sm"><table class="data-table">
      <tr><th><?= e(unit_district()) ?></th><th>วัด</th><th class="text-right whitespace-nowrap">รายรับ</th><th class="text-right whitespace-nowrap">รายจ่าย</th></tr>
      <?php foreach ($byTemple as $r): ?>
      <tr><td><?= e($r['district']) ?></td><td><?= e($r['temple']) ?></td><td class="text-right whitespace-nowrap text-emerald-700"><?= money($r['inc']) ?></td><td class="text-right whitespace-nowrap text-red-700"><?= money($r['exp']) ?></td></tr>
      <?php endforeach; ?>
      <?php if (!$byTemple): ?><tr><td colspan="4" class="text-stone-500">ไม่มีข้อมูล</td></tr><?php endif; ?>
    </table></div>
  </div>
</div>
<?php if ($lhTemple): ?><?= signature_block($pdo, $lhTemple) ?><?php endif; ?>
<?php require __DIR__ . '/includes/footer.php'; ?>
