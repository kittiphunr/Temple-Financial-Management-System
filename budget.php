<?php
/** งบประมาณประจำปีรายหมวด เทียบกับผลจริง — แอดมินวัด/อำเภอ/หลักตั้งงบได้ เจ้าคณะจังหวัด/ตำบลดูได้ตามขอบเขต */
require __DIR__ . '/includes/bootstrap.php';
$u = require_login();
$pdo = db();
if (!budget_available()) {
    flash('ยังไม่ได้เปิดใช้งบประมาณ (ผู้ดูแลต้องรัน sql/migrations/2026_budget.sql)', 'err');
    redirect('dashboard.php');
}

$tid = $u['role'] === 'temple' ? (int)$u['temple_id'] : (int)($_GET['temple'] ?? $_POST['temple'] ?? 0);
$temple = $tid && people_can_view($tid) ? temple_row($tid) : null;
$canEdit = $temple && can_access_temple($tid);
$year = (int)($_GET['year'] ?? $_POST['year'] ?? be_year());
if ($year < 2500 || $year > 2700) {
    $year = be_year();
}
$self = 'budget.php?' . http_build_query(array_filter(['temple' => $u['role'] === 'temple' ? 0 : $tid, 'year' => $year]));

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    if (!$canEdit) {
        http_response_code(403);
        exit('ไม่มีสิทธิ์ตั้งงบประมาณของวัดนี้');
    }
    $act = $_POST['action'] ?? '';
    if ($act === 'save') {
        $n = budget_save($pdo, $tid, $year, (array)($_POST['amount'] ?? []), (int)$u['id']);
        audit('budget', 'temple', $tid, "$year categories $n");
        flash("บันทึกงบประมาณปี $year แล้ว ($n หมวด)");
    } elseif ($act === 'copy_prev') {
        $n = budget_copy_prev($pdo, $tid, $year, (int)$u['id']);
        audit('budget_copy', 'temple', $tid, "$year n $n");
        flash($n ? "คัดลอกงบจากปี " . ($year - 1) . " แล้ว $n หมวด (เฉพาะหมวดที่ยังไม่ได้ตั้ง)" : 'ไม่มีหมวดให้คัดลอก (ปีก่อนไม่มีงบ หรือปีนี้ตั้งครบแล้ว)', $n ? 'ok' : 'err');
    }
    redirect($self);
}

$rows = $temple ? budget_rows($pdo, $tid, $year) : ['income' => [], 'expense' => []];
$ti = budget_totals($rows['income']);
$te = budget_totals($rows['expense']);
$groups = temples_grouped();

function budget_bar(?float $pct, string $type): string
{
    if ($pct === null) {
        return '';
    }
    $over = $type === 'expense' && $pct >= 100;
    $warn = $type === 'expense' && $pct >= BUDGET_WARN_PCT && !$over;
    $color = $over ? 'bg-red-600' : ($warn ? 'bg-amber-500' : ($type === 'income' ? 'bg-emerald-600' : 'bg-brand-600'));
    return '<div class="h-2 w-24 overflow-hidden rounded bg-stone-200"><div class="h-2 ' . $color . '" style="width:' . min(100, round($pct)) . '%"></div></div>';
}

$pageTitle = 'งบประมาณประจำปี';
require __DIR__ . '/includes/header.php';
?>
<div class="flex flex-wrap items-center justify-between gap-2"><h2>งบประมาณประจำปี<?= $temple ? ' · ' . e($temple['name']) : '' ?></h2></div>

<form method="get" class="my-4 flex flex-wrap items-end gap-3">
  <?php if ($u['role'] !== 'temple'): ?>
  <div><label>วัด</label><select name="temple" onchange="this.form.submit()"><option value="0">— เลือกวัด —</option>
    <?php foreach ($groups as $dn => $list): ?><optgroup label="<?= e(unit_district()) ?><?= e($dn) ?>"><?php foreach ($list as $t): ?><option value="<?= (int)$t['id'] ?>" <?= $t['id'] == $tid ? 'selected' : '' ?>><?= e($t['name']) ?></option><?php endforeach; ?></optgroup><?php endforeach; ?></select></div>
  <?php endif; ?>
  <div><label>ปี พ.ศ.</label><select name="year"><?php for ($y = be_year() + 1; $y >= be_year() - 6; $y--): ?><option <?= $y === $year ? 'selected' : '' ?>><?= $y ?></option><?php endfor; ?></select></div>
  <button class="btn" type="submit">แสดง</button>
</form>

<?php if (!$temple): ?>
  <p class="text-stone-600">เลือกวัดเพื่อดูหรือตั้งงบประมาณ</p>
<?php else: ?>
<p class="mb-4 text-sm text-stone-600">ตั้งงบรายหมวดของปี พ.ศ. <?= $year ?> (ปีปฏิทิน ม.ค.–ธ.ค.) ระบบเทียบกับรายการที่บันทึกจริงในปีนั้น ไม่นับรายการที่ลบและรายการโอน/ฝาก-ถอน — หมวดรายจ่ายที่ใช้ถึง <?= BUDGET_WARN_PCT ?>% จะเตือนเป็นสีเหลือง และเกินงบเป็นสีแดง (เตือนตอนบันทึกรายจ่ายด้วย) เว้นว่างหรือใส่ 0 = ไม่ตั้งงบหมวดนั้น</p>

<form method="post" class="space-y-4">
  <?= csrf_field() ?><input type="hidden" name="action" value="save"><input type="hidden" name="temple" value="<?= $tid ?>"><input type="hidden" name="year" value="<?= $year ?>">
  <?php foreach (['income' => ['รายรับ', $ti], 'expense' => ['รายจ่าย', $te]] as $type => [$title, $tot]): ?>
  <section class="card-table"><table class="data-table">
    <tr><th><?= $title ?></th><th class="text-right">งบ (บาท)</th><th class="text-right">ผลจริง</th><th class="text-right">คงเหลือ</th><th>ใช้ไป</th></tr>
    <?php foreach ($rows[$type] as $r): $hasB = $r['budget'] !== null; $left = $hasB ? $r['budget'] - $r['actual'] : null; $over = $type === 'expense' && $hasB && $r['pct'] >= 100; $warn = $type === 'expense' && $hasB && $r['pct'] >= BUDGET_WARN_PCT && !$over; ?>
    <tr>
      <td><?= e($r['name']) ?><?= $r['is_active'] ? '' : ' <span class="badge">ปิดใช้งาน</span>' ?></td>
      <td class="text-right"><?php if ($canEdit): ?><input name="amount[<?= (int)$r['id'] ?>]" inputmode="decimal" class="w-32 text-right" value="<?= $hasB ? e(number_format($r['budget'], 2, '.', '')) : '' ?>"><?php else: ?><?= $hasB ? money($r['budget']) : '-' ?><?php endif; ?></td>
      <td class="text-right whitespace-nowrap"><?= money($r['actual']) ?></td>
      <td class="text-right whitespace-nowrap <?= $over ? 'font-semibold text-red-700' : '' ?>"><?= $hasB ? ($left < 0 ? '−' . money(-$left) : money($left)) : '' ?></td>
      <td><?php if ($hasB): ?><div class="flex items-center gap-2"><?= budget_bar($r['pct'], $type) ?><span class="text-sm <?= $over ? 'font-semibold text-red-700' : ($warn ? 'text-amber-700' : 'text-stone-600') ?>"><?= number_format($r['pct'], 0) ?>%<?= $over ? ' เกินงบ' : ($warn ? ' ใกล้เต็ม' : '') ?></span></div><?php endif; ?></td>
    </tr>
    <?php endforeach; ?>
    <tr><th>รวมหมวดที่ตั้งงบ</th><th class="text-right"><?= money($tot['budget']) ?></th><th class="text-right"><?= money($tot['actual']) ?></th><th class="text-right"><?= money($tot['budget'] - $tot['actual']) ?></th>
      <th class="text-sm font-normal"><?= $tot['budget'] > 0 ? number_format($tot['actual'] / $tot['budget'] * 100, 0) . '%' : '' ?><?= abs($tot['actual_all'] - $tot['actual']) > 0.004 ? ' · ผลจริงทุกหมวด ' . money($tot['actual_all']) : '' ?></th></tr>
  </table></section>
  <?php endforeach; ?>
  <p class="text-sm text-stone-600">แผนรายรับ − แผนรายจ่าย = <strong class="<?= $ti['budget'] - $te['budget'] < 0 ? 'text-red-700' : 'text-emerald-700' ?>"><?= money($ti['budget'] - $te['budget']) ?> บาท</strong></p>
  <?php if ($canEdit): ?><button class="btn" type="submit">บันทึกงบประมาณ</button><?php else: ?><p class="text-sm text-stone-500">ดูได้อย่างเดียว</p><?php endif; ?>
</form>
<?php if ($canEdit): ?>
<form method="post" class="mt-3" data-confirm="คัดลอกงบจากปี <?= $year - 1 ?> มาเติมเฉพาะหมวดที่ยังไม่ได้ตั้ง?">
  <?= csrf_field() ?><input type="hidden" name="action" value="copy_prev"><input type="hidden" name="temple" value="<?= $tid ?>"><input type="hidden" name="year" value="<?= $year ?>">
  <button class="btn btn-outline btn-sm">คัดลอกงบจากปี <?= $year - 1 ?></button></form>
<?php endif; ?>
<?php endif; ?>
<?php require __DIR__ . '/includes/footer.php'; ?>
