<?php
/** วัดที่ควรติดตาม: ธงความผิดปกติของบัญชีวัดในขอบเขตของผู้ใช้ (เจ้าคณะ/แอดมินอำเภอ/แอดมินหลัก) ในเดือนที่เลือก */
require __DIR__ . '/includes/bootstrap.php';
$u = require_login();
if ($u['role'] === 'temple') {
    redirect('dashboard.php');   // แอดมินวัดดูข้อมูลของวัดตนเองที่หน้าอื่น
}
$pdo = db();
$prev = anomaly_month_start(be_year(), (int)date('n'), -1);   // เดือนก่อนหน้า (เดือนเต็มล่าสุด)
$year = (int)($_GET['year'] ?? ((int)substr($prev, 0, 4) + 543));
$month = (int)($_GET['month'] ?? (int)substr($prev, 5, 2));
$month = $month >= 1 && $month <= 12 ? $month : (int)substr($prev, 5, 2);
$year = max(be_year() - 8, min(be_year(), $year));
$fDist = in_array($u['role'], ['admin', 'province'], true) ? max(0, (int)($_GET['district'] ?? 0)) : 0;
$fKind = isset(ANOMALY_KINDS[$_GET['kind'] ?? '']) ? $_GET['kind'] : '';
$future = anomaly_month_start($year, $month) > date('Y-m-01');   // เดือนที่ยังไม่เริ่ม ไม่มีอะไรให้ตรวจ

$res = $future ? ['scanned' => 0, 'rows' => [], 'counts' => array_fill_keys(array_keys(ANOMALY_KINDS), 0)] : anomaly_scan($pdo, $year, $month, ['district' => $fDist, 'kind' => $fKind]);
$lastDay = (int)date('t', strtotime(anomaly_month_start($year, $month)));

if (($_GET['export'] ?? '') === 'csv' && !$future) {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="anomalies-' . $year . '-' . sprintf('%02d', $month) . '.csv"');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF");
    fputcsv($out, [unit_district(), unit_subdistrict(), 'วัด', 'ธง', 'ระดับ', 'รายละเอียด']);
    $safe = fn($v) => is_string($v) && preg_match('/^[=+\-@\t\r]/', $v) ? "'" . $v : $v;
    foreach ($res['rows'] as $r) {
        foreach ($r['flags'] as $f) {
            fputcsv($out, array_map($safe, [$r['temple']['district'], (string)$r['temple']['subdistrict'], $r['temple']['name'], $f['label'], ANOMALY_SEVERITY_LABEL[$f['severity']][0], $f['detail']]));
        }
    }
    audit('export', 'anomalies', null, "$year/$month");
    exit;
}

$districts = in_array($u['role'], ['admin', 'province'], true) ? $pdo->query('SELECT id, name FROM districts ORDER BY sort_order, id')->fetchAll() : [];
$nFlagged = count($res['rows']);
$pageTitle = 'วัดที่ควรติดตาม';
require __DIR__ . '/includes/header.php';
?>
<div class="mb-4 flex flex-wrap items-end justify-between gap-3">
  <div>
    <h2 class="!my-0">วัดที่ควรติดตาม</h2>
    <p class="mt-1 text-sm text-stone-600">ธงความผิดปกติของบัญชี เดือน<?= e(TH_MONTHS[$month]) ?> พ.ศ. <?= $year ?> — เป็นสัญญาณให้ตรวจสอบ ไม่ได้ตัดสินว่าผิด</p>
  </div>
  <?php if (!$future): ?><a class="btn btn-outline btn-sm" href="<?= e('anomalies.php?' . http_build_query(['year' => $year, 'month' => $month, 'district' => $fDist ?: '', 'kind' => $fKind, 'export' => 'csv'])) ?>"><?= icon('download') ?> ส่งออก CSV (Excel)</a><?php endif; ?>
</div>

<form method="get" class="mb-4 flex flex-wrap items-end gap-3">
  <div><label>เดือน</label><select name="month"><?php for ($m = 1; $m <= 12; $m++): ?><option value="<?= $m ?>" <?= $m === $month ? 'selected' : '' ?>><?= e(TH_MONTHS[$m]) ?></option><?php endfor; ?></select></div>
  <div><label>ปี พ.ศ.</label><select name="year"><?php for ($y = be_year(); $y >= be_year() - 8; $y--): ?><option <?= $y === $year ? 'selected' : '' ?>><?= $y ?></option><?php endfor; ?></select></div>
  <?php if ($districts): ?><div><label><?= e(unit_district()) ?></label><select name="district"><option value="0">ทุก<?= e(unit_district()) ?></option>
    <?php foreach ($districts as $d): ?><option value="<?= (int)$d['id'] ?>" <?= (int)$d['id'] === $fDist ? 'selected' : '' ?>><?= e($d['name']) ?></option><?php endforeach; ?></select></div><?php endif; ?>
  <div><label>ธง</label><select name="kind"><option value="">ทุกธง</option><?php foreach (ANOMALY_KINDS as $k => [$lab]): ?><option value="<?= $k ?>" <?= $k === $fKind ? 'selected' : '' ?>><?= e($lab) ?></option><?php endforeach; ?></select></div>
  <button class="btn" type="submit">แสดง</button>
</form>

<?php if ($future): ?>
  <p class="text-stone-600">เดือนที่เลือกยังไม่เริ่ม จึงยังไม่มีข้อมูลให้ตรวจ</p>
<?php else: ?>
<div class="mb-4 grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
  <div class="card"><div class="text-sm text-stone-600">วัดที่ตรวจ</div><div class="text-3xl font-bold"><?= number_format($res['scanned']) ?> <span class="text-base font-semibold">วัด</span></div></div>
  <div class="card <?= $nFlagged ? 'border-amber-200 bg-amber-50' : 'border-emerald-200 bg-emerald-50' ?>"><div class="text-sm <?= $nFlagged ? 'text-amber-900' : 'text-emerald-900' ?>">วัดที่มีธง<?= $fKind !== '' ? ' (' . e(ANOMALY_KINDS[$fKind][0]) . ')' : '' ?></div><div class="text-3xl font-bold <?= $nFlagged ? 'text-amber-800' : 'text-emerald-800' ?>"><?= number_format($nFlagged) ?> <span class="text-base font-semibold">วัด</span></div></div>
  <?php foreach (['high' => 'ธงระดับสูง', 'mid' => 'ธงระดับกลาง'] as $sev => $title): $c = array_sum(array_map(fn($k) => ANOMALY_KINDS[$k][1] === $sev ? $res['counts'][$k] : 0, array_keys(ANOMALY_KINDS))); ?>
  <div class="card"><div class="text-sm text-stone-600"><?= $title ?></div><div class="text-3xl font-bold"><?= number_format($c) ?> <span class="text-base font-semibold">ธง</span></div></div>
  <?php endforeach; ?>
</div>
<div class="mb-4 flex flex-wrap gap-2 text-sm">
  <?php foreach (ANOMALY_KINDS as $k => [$lab, $sev]): [, $cls] = ANOMALY_SEVERITY_LABEL[$sev]; ?>
  <a class="badge <?= $cls ?> <?= $fKind === $k ? 'ring-2 ring-stone-400' : '' ?>" href="<?= e('anomalies.php?' . http_build_query(['year' => $year, 'month' => $month, 'district' => $fDist ?: '', 'kind' => $fKind === $k ? '' : $k])) ?>"><?= e($lab) ?> <?= (int)$res['counts'][$k] ?></a>
  <?php endforeach; ?>
</div>

<div class="card-table"><table class="data-table">
  <thead><tr><th><?= e(unit_district()) ?></th><th>วัด</th><th>ธงที่พบ</th><th></th></tr></thead>
  <tbody>
  <?php foreach ($res['rows'] as $r): $t = $r['temple']; ?>
  <tr>
    <td><?= e($t['district']) ?></td>
    <td class="font-semibold"><?= e($t['name']) ?><?php if ($t['subdistrict']): ?><div class="text-xs font-normal text-stone-500"><?= e(abbr_subdistrict()) ?><?= e($t['subdistrict']) ?></div><?php endif; ?></td>
    <td><ul class="grid gap-1">
      <?php foreach ($r['flags'] as $f): [$sl, $sc] = ANOMALY_SEVERITY_LABEL[$f['severity']]; ?>
      <li class="text-sm"><span class="badge <?= $sc ?>" title="ระดับ<?= e($sl) ?>"><?= e($f['label']) ?></span> <span class="text-stone-700"><?= e($f['detail']) ?></span></li>
      <?php endforeach; ?></ul></td>
    <td class="whitespace-nowrap">
      <a class="btn btn-sm btn-outline" href="statements.php?temple=<?= (int)$t['id'] ?>&year=<?= $year ?>&month=<?= $month ?>">ดูรายงาน</a>
      <a class="btn btn-sm btn-outline" href="<?= e('search.php?' . http_build_query(['temple' => (int)$t['id'], 'from_d' => 1, 'from_m' => $month, 'from_y' => $year, 'to_d' => $lastDay, 'to_m' => $month, 'to_y' => $year])) ?>">ค้นรายการ</a>
    </td>
  </tr>
  <?php endforeach; ?>
  <?php if (!$res['rows']): ?><tr><td colspan="4" class="text-stone-500">ไม่พบวัดที่มีธงตามเงื่อนไขที่เลือก</td></tr><?php endif; ?>
  </tbody>
</table></div>
<div class="mt-4 rounded-lg border border-stone-200 bg-white p-4 text-sm text-stone-700">
  <strong>เกณฑ์ที่ใช้ตรวจ</strong>
  <ul class="mt-1 list-disc pl-5">
    <li><strong>ยังไม่ส่งรายงาน</strong> — ยังไม่ส่งรายงานเดือนนี้และเกินกำหนดแล้ว</li>
    <li><strong>ไม่มีรายการหลายเดือน</strong> — ไม่มีรายการเลยทั้ง <?= ANOMALY_QUIET_MONTHS ?> เดือนติดกัน (เดือนนี้และก่อนหน้า)</li>
    <li><strong>ยอดติดลบ</strong> — บัญชีเงินสดหรือธนาคารมียอดคงเหลือสิ้นเดือนติดลบ</li>
    <li><strong>รายจ่ายพุ่ง</strong> — รายจ่ายเดือนนี้ตั้งแต่ <?= ANOMALY_SPIKE_RATIO ?> เท่าของค่าเฉลี่ย 3 เดือนก่อน และไม่ต่ำกว่า <?= number_format(ANOMALY_SPIKE_MIN) ?> บาท</li>
    <li><strong>รายจ่ายสูงไม่มีหลักฐาน</strong> — รายจ่ายรายการละ <?= number_format(ANOMALY_EVIDENCE_MIN) ?> บาทขึ้นไปที่ไม่มีไฟล์แนบ</li>
    <li><strong>รายงานถูกส่งกลับ</strong> — ถูกส่งกลับแก้ไขแล้ววัดยังไม่ส่งใหม่</li>
    <li><strong>เงินสดสูง</strong> — เงินสดคงเหลือสิ้นเดือน <?= number_format(ANOMALY_CASH_HIGH) ?> บาทขึ้นไป</li>
  </ul>
</div>
<?php endif; ?>
<?php require __DIR__ . '/includes/footer.php'; ?>
