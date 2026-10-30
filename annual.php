<?php
/** งบแสดงรายรับ-รายจ่ายประจำปี (รายวัด): เทียบปีก่อน เทียบงบประมาณ ยอดยกมา/คงเหลือ และสรุปกองทุน — พิมพ์พร้อมลงนาม */
require __DIR__ . '/includes/bootstrap.php';
$u = require_login();
$pdo = db();

$tid = $u['role'] === 'temple' ? (int)$u['temple_id'] : (int)($_GET['temple'] ?? 0);
$temple = $tid && people_can_view($tid) ? temple_row($tid) : null;
$year = (int)($_GET['year'] ?? be_year());
if ($year < 2500 || $year > 2700) {
    $year = be_year();
}
$groups = temples_grouped();

/** รวมตามหมวดของปี: [type => [category_id => ['name'=>..,'total'=>..]]] ไม่นับรายการที่ลบและรายการโอน/ฝาก-ถอน */
function annual_by_category(PDO $pdo, int $templeId, int $beYear): array
{
    $st = $pdo->prepare("SELECT x.type, c.id, c.name, SUM(x.amount) AS total FROM transactions x JOIN categories c ON c.id = x.category_id
        WHERE x.temple_id = ? AND x.is_deleted = 0 AND x.transfer_id IS NULL AND x.txn_date BETWEEN ? AND ?
        GROUP BY x.type, c.id, c.name, c.sort_order ORDER BY x.type, c.sort_order, c.name");
    $st->execute([$templeId, sprintf('%04d-01-01', $beYear - 543), sprintf('%04d-12-31', $beYear - 543)]);
    $out = ['income' => [], 'expense' => []];
    foreach ($st->fetchAll() as $r) {
        $out[$r['type']][(int)$r['id']] = ['name' => $r['name'], 'total' => (float)$r['total']];
    }
    return $out;
}

function annual_change(float $now, float $prev): string
{
    if ($prev == 0.0) {
        return $now == 0.0 ? '' : 'ใหม่';
    }
    $p = ($now - $prev) / $prev * 100;
    return ($p > 0 ? '+' : '') . number_format($p, 1) . '%';
}

$pageTitle = 'งบแสดงรายรับ-รายจ่ายประจำปี';
require __DIR__ . '/includes/header.php';
?>
<h2 class="print:hidden">งบแสดงรายรับ-รายจ่ายประจำปี</h2>
<form method="get" class="my-3 flex flex-wrap items-end gap-3 print:hidden">
  <?php if ($u['role'] !== 'temple'): ?>
  <div><label>วัด</label><select name="temple" onchange="this.form.submit()"><option value="0">— เลือกวัด —</option>
    <?php foreach ($groups as $dn => $list): ?><optgroup label="<?= e(unit_district()) ?><?= e($dn) ?>"><?php foreach ($list as $t): ?><option value="<?= (int)$t['id'] ?>" <?= $t['id'] == $tid ? 'selected' : '' ?>><?= e($t['name']) ?></option><?php endforeach; ?></optgroup><?php endforeach; ?></select></div>
  <?php endif; ?>
  <div><label>ปี พ.ศ.</label><select name="year"><?php for ($y = be_year() + 1; $y >= be_year() - 6; $y--): ?><option <?= $y === $year ? 'selected' : '' ?>><?= $y ?></option><?php endfor; ?></select></div>
  <button class="btn" type="submit">แสดง</button>
  <?php if ($temple): ?><button class="btn btn-outline" type="button" onclick="window.print()"><?= icon('printer') ?> พิมพ์ / บันทึก PDF</button><?php endif; ?>
  <a class="btn btn-outline" href="reports.php?year=<?= $year ?>">รายงานภาพรวม</a>
</form>
<?php if (!$temple): ?>
  <p class="text-stone-600">เลือกวัดเพื่อดูงบแสดงรายรับ-รายจ่ายประจำปี</p>
<?php else:
    $cur = annual_by_category($pdo, $tid, $year);
    $prv = annual_by_category($pdo, $tid, $year - 1);
    $bud = [];
    if (budget_available()) {
        foreach (budget_rows($pdo, $tid, $year) as $list) {
            foreach ($list as $r) {
                if ($r['budget'] !== null) $bud[(int)$r['id']] = $r['budget'];
            }
        }
    }
    $hasBud = (bool)$bud;
    $yearStart = sprintf('%04d-01-01', $year - 543);
    $yearEnd = min(sprintf('%04d-12-31', $year - 543), date('Y-m-d'));
    $accounts = ledger_accounts($pdo, $tid);
    $openTotal = $closeTotal = $startedInYear = 0.0;
    $accRows = [];
    foreach ($accounts as $a) {
        $o = ledger_balance_before($pdo, $a, $yearStart);
        $c = $yearEnd >= $yearStart ? ledger_balance_on($pdo, $a, $yearEnd) : 0.0;
        if (!$a['is_active'] && abs($o) < 0.005 && abs($c) < 0.005) continue;
        if ($a['opening_date'] && $a['opening_date'] > $yearStart && $a['opening_date'] <= $yearEnd) $startedInYear += (float)$a['opening_balance'];
        $openTotal += $o;
        $closeTotal += $c;
        $accRows[] = [ledger_account_label($a), $o, $c];
    }
    $sumIn = array_sum(array_column($cur['income'], 'total'));
    $sumEx = array_sum(array_column($cur['expense'], 'total'));
    $pIn = array_sum(array_column($prv['income'], 'total'));
    $pEx = array_sum(array_column($prv['expense'], 'total'));
    $bIn = $bEx = 0.0;
    $catType = $pdo->query('SELECT id, type FROM categories')->fetchAll(PDO::FETCH_KEY_PAIR);
    foreach ($bud as $cid => $amt) {
        if (($catType[$cid] ?? '') === 'income') $bIn += $amt; else $bEx += $amt;
    }
    $sections = ['income' => ['รายรับ', $sumIn, $pIn, $bIn], 'expense' => ['รายจ่าย', $sumEx, $pEx, $bEx]];
?>
<?= letterhead($pdo, $tid, 0, 'งบแสดงรายรับ-รายจ่ายประจำปี', 'ปี พ.ศ. ' . $year . ' (1 มกราคม – 31 ธันวาคม ' . $year . ')', false) ?>

<div class="overflow-x-auto rounded-2xl border border-stone-200 bg-white shadow-sm print:border-0 print:shadow-none"><table class="data-table">
  <tr><th>รายการ</th><th class="text-right whitespace-nowrap">ปี <?= $year ?></th><th class="text-right whitespace-nowrap">ปี <?= $year - 1 ?></th><th class="text-right whitespace-nowrap">เปลี่ยนแปลง</th><?php if ($hasBud): ?><th class="text-right whitespace-nowrap">งบประมาณ</th><th class="text-right whitespace-nowrap">ผลต่างจากงบ</th><?php endif; ?></tr>
  <tr class="bg-stone-50"><td class="font-semibold">เงินคงเหลือยกมา ต้นปี (1 ม.ค. <?= $year ?>)</td><td class="text-right font-semibold"><?= money($openTotal) ?></td><td></td><td></td><?php if ($hasBud): ?><td></td><td></td><?php endif; ?></tr>
  <?php foreach ($sections as $type => [$title, $sum, $psum, $bsum]):
      $ids = array_unique(array_merge(array_keys($cur[$type]), array_keys($prv[$type]))); ?>
  <tr class="bg-stone-50"><td colspan="<?= $hasBud ? 6 : 4 ?>" class="font-semibold"><?= $title ?></td></tr>
  <?php foreach ($ids as $cid): $n = $cur[$type][$cid]['total'] ?? 0.0; $p = $prv[$type][$cid]['total'] ?? 0.0; $nm = $cur[$type][$cid]['name'] ?? $prv[$type][$cid]['name']; ?>
  <tr><td class="pl-6"><?= e($nm) ?></td><td class="text-right"><?= money($n) ?></td><td class="text-right text-stone-600"><?= money($p) ?></td><td class="text-right text-sm"><?= e(annual_change($n, $p)) ?></td>
    <?php if ($hasBud): ?><td class="text-right"><?= isset($bud[$cid]) ? money($bud[$cid]) : '' ?></td><td class="text-right text-sm"><?= isset($bud[$cid]) ? money($type === 'income' ? $n - $bud[$cid] : $bud[$cid] - $n) : '' ?></td><?php endif; ?></tr>
  <?php endforeach; ?>
  <?php if (!$ids): ?><tr><td colspan="<?= $hasBud ? 6 : 4 ?>" class="pl-6 text-stone-500">ไม่มีรายการ</td></tr><?php endif; ?>
  <tr class="font-semibold"><td>รวม<?= $title ?></td><td class="text-right"><?= money($sum) ?></td><td class="text-right text-stone-600"><?= money($psum) ?></td><td class="text-right text-sm"><?= e(annual_change($sum, $psum)) ?></td>
    <?php if ($hasBud): ?><td class="text-right"><?= $bsum > 0 ? money($bsum) : '' ?></td><td></td><?php endif; ?></tr>
  <?php endforeach; ?>
  <tr class="font-semibold"><td>รายรับสูง(ต่ำ)กว่ารายจ่าย</td><td class="text-right <?= $sumIn - $sumEx < 0 ? 'text-red-700' : '' ?>"><?= money($sumIn - $sumEx) ?></td><td class="text-right text-stone-600"><?= money($pIn - $pEx) ?></td><td></td><?php if ($hasBud): ?><td></td><td></td><?php endif; ?></tr>
  <?php if (abs($startedInYear) > 0.004): ?><tr><td>บวก ยอดยกมาของบัญชีที่เริ่มใช้ระหว่างปี</td><td class="text-right"><?= money($startedInYear) ?></td><td></td><td></td><?php if ($hasBud): ?><td></td><td></td><?php endif; ?></tr><?php endif; ?>
  <tr class="bg-stone-50 font-bold"><td>เงินคงเหลือ ณ <?= e(th_date($yearEnd)) ?></td><td class="text-right"><?= money($closeTotal) ?></td><td></td><td></td><?php if ($hasBud): ?><td></td><td></td><?php endif; ?></tr>
</table></div>

<h3 class="mt-6">เงินคงเหลือแยกตามบัญชี</h3>
<div class="overflow-x-auto rounded-2xl border border-stone-200 bg-white shadow-sm print:border-0 print:shadow-none"><table class="data-table">
  <tr><th>บัญชี</th><th class="text-right">ต้นปี</th><th class="text-right">ปลายปี</th></tr>
  <?php foreach ($accRows as [$lab, $o, $c]): ?><tr><td><?= e($lab) ?></td><td class="text-right"><?= money($o) ?></td><td class="text-right"><?= money($c) ?></td></tr><?php endforeach; ?>
  <tr class="font-semibold"><td>รวม</td><td class="text-right"><?= money($openTotal) ?></td><td class="text-right"><?= money($closeTotal) ?></td></tr>
</table></div>

<?php if (fund_available() && ($fl = fund_list($pdo, $tid))):
    $fy = $pdo->prepare("SELECT fund_id, type, SUM(amount) AS s FROM transactions WHERE temple_id = ? AND fund_id IS NOT NULL AND is_deleted = 0 AND transfer_id IS NULL AND txn_date BETWEEN ? AND ? GROUP BY fund_id, type");
    $fy->execute([$tid, $yearStart, sprintf('%04d-12-31', $year - 543)]);
    $fyr = [];
    foreach ($fy->fetchAll() as $r) $fyr[(int)$r['fund_id']][$r['type']] = (float)$r['s'];
?>
<h3 class="mt-6">กองทุน/โครงการ</h3>
<div class="overflow-x-auto rounded-2xl border border-stone-200 bg-white shadow-sm print:border-0 print:shadow-none"><table class="data-table">
  <tr><th>กองทุน/โครงการ</th><th class="text-right">รับในปี</th><th class="text-right">ใช้ในปี</th><th class="text-right">คงเหลือสะสม</th></tr>
  <?php foreach ($fl as $f): $yi = $fyr[(int)$f['id']]['income'] ?? 0.0; $ye = $fyr[(int)$f['id']]['expense'] ?? 0.0; if (!$f['is_active'] && $yi == 0.0 && $ye == 0.0 && abs($f['balance']) < 0.005) continue; ?>
  <tr><td><?= e($f['name']) ?></td><td class="text-right"><?= money($yi) ?></td><td class="text-right"><?= money($ye) ?></td><td class="text-right"><?= money($f['balance']) ?></td></tr>
  <?php endforeach; ?>
</table></div>
<?php endif; ?>
<p class="mt-3 text-xs text-stone-500">ไม่นับรายการที่ลบและรายการโอน/ฝาก-ถอนระหว่างบัญชี<?= $yearEnd < sprintf('%04d-12-31', $year - 543) ? ' · ปีนี้ยังไม่สิ้นสุด ยอดคงเหลือคิดถึงวันนี้' : '' ?></p>
<?= signature_block($pdo, $tid) ?>
<?php endif; ?>
<?php require __DIR__ . '/includes/footer.php'; ?>
