<?php
/** แดชบอร์ดรายอำเภอ (และภาพรวมทั้งจังหวัดสำหรับแอดมินหลัก) */
require __DIR__ . '/includes/bootstrap.php';
require __DIR__ . '/includes/stats.php';
require __DIR__ . '/includes/dashboard_ui.php';
$u = require_login();
$pdo = db();

$districts = $pdo->query('SELECT id, name FROM districts ORDER BY sort_order')->fetchAll();
$byId = array_column($districts, 'name', 'id');

// สิทธิ์: แอดมินหลักเลือกอำเภอ/ทั้งจังหวัดได้ — เจ้าหน้าที่อำเภอและแอดมินวัดเห็นได้เฉพาะอำเภอของตน
if (in_array($u['role'], ['admin', 'province'], true)) {
    $dist = (int)($_GET['district'] ?? 0);
    if (!isset($byId[$dist])) {
        $dist = 0;
    }
} elseif ($u['role'] === 'district') {
    $dist = (int)$u['district_id'];
} elseif ($u['role'] === 'subdistrict') {
    // เจ้าคณะตำบล: ตำบลที่ดูแลอยู่อำเภอเดียวกันทั้งหมด = แสดงเป็นอำเภอนั้น (ขอบเขตข้อมูลยังจำกัดเฉพาะตำบลที่ดูแลผ่าน scope_sql) ไม่งั้นแสดงรวมหลายอำเภอ
    $assign = subdistrict_assignments($pdo, (int)$u['id']);
    $ds = array_values(array_unique(array_column($assign, 'district_id')));
    $dist = count($ds) === 1 ? $ds[0] : 0;
} else {
    $st = $pdo->prepare('SELECT district_id FROM temples WHERE id = ?');
    $st->execute([(int)$u['temple_id']]);
    $dist = (int)$st->fetchColumn();
}
$distName = $byId[$dist] ?? '';

$year = (int)($_GET['year'] ?? be_year());
$year = max(be_year() - 10, min(be_year() + 1, $year));

$sum = summary($year, $dist);
$temples = temple_count_in($dist);
$series = monthly_series($year, $dist);
$incCats = top_categories($year, 'income', $dist);
$expCats = top_categories($year, 'expense', $dist);
$rows = $dist ? temple_breakdown($year, $dist) : summary_by_district($year);
$withData = $dist ? count(array_filter($rows, fn($r) => (int)$r['n'] > 0)) : 0;

$subLabel = $u['role'] === 'subdistrict' ? subdistrict_label($assign ?? []) : '';
$scopeText = $u['role'] === 'temple' ? 'เฉพาะวัดของท่าน' : ($subLabel !== '' ? 'ใน' . unit_subdistrict() . 'ที่ท่านดูแล' : ($dist ? 'ในสังกัด' . unit_district() . $distName : 'ในสังกัด' . province_phrase()));
$title = $subLabel !== '' ? 'แดชบอร์ดเจ้าคณะ' . unit_subdistrict() : ($dist ? 'แดชบอร์ด' . unit_district() . $distName : 'แดชบอร์ดทั้ง' . province_phrase());
$q = fn(array $o = []) => http_build_query(array_merge(['district' => $dist, 'year' => $year], $o));

$pageTitle = $title;
require __DIR__ . '/includes/header.php';
?>
<?php
$lhTemple = $u['role'] === 'temple' ? (int)$u['temple_id'] : 0;
echo letterhead($pdo, $lhTemple, $lhTemple ? 0 : $dist, 'สรุปภาพรวมบัญชีรายรับ-รายจ่าย', 'ปี พ.ศ. ' . $year, true);
?>
<div class="mb-4 flex flex-wrap items-end justify-between gap-3">
  <div>
    <h2 class="!my-0"><?= e($title) ?> <span class="text-xl font-semibold text-stone-600">(ปี พ.ศ. <?= $year ?>)</span></h2>
    <p class="mt-1 text-sm text-stone-600">สรุปข้อมูลบัญชีรายรับ-รายจ่ายของวัด<?= $subLabel !== '' ? 'ใน' . unit_subdistrict() . 'ที่ท่านดูแล: ' . e($subLabel) : ($dist ? 'ในสังกัดคณะสงฆ์' . unit_district() . e($distName) . ' ' : '') . e(province_phrase()) ?></p>
  </div>
  <form method="get" class="flex items-center gap-2 print:hidden">
    <input type="hidden" name="district" value="<?= $dist ?>">
    <label class="sr-only" for="year">ปี พ.ศ.</label>
    <select id="year" name="year" onchange="this.form.submit()">
      <?php for ($y = be_year() + 1; $y >= be_year() - 6; $y--): ?>
        <option value="<?= $y ?>" <?= $y === $year ? 'selected' : '' ?>>ปี พ.ศ. <?= $y ?></option>
      <?php endfor; ?>
    </select>
    <button type="button" class="btn btn-outline" onclick="window.print()"><?= icon('printer') ?> พิมพ์</button>
  </form>
</div>

<?php if (in_array($u['role'], ['admin', 'province'], true)): ?>
<nav class="mb-4 flex flex-wrap gap-1.5 print:hidden" aria-label="เลือก<?= e(unit_district()) ?>">
  <?php foreach ([0 => 'ทั้งจังหวัด'] + $byId as $id => $nm):
      $on = $id === $dist; ?>
    <a class="rounded-full border px-3.5 py-1.5 text-sm font-semibold no-underline <?= $on ? 'border-brand-600 bg-brand-600 text-white' : 'border-stone-300 bg-white text-stone-700 hover:bg-gold-100' ?>"
       <?= $on ? 'aria-current="page"' : '' ?> href="dashboard.php?<?= e($q(['district' => $id])) ?>"><?= $id ? e($nm) : 'ทั้งจังหวัด' ?></a>
  <?php endforeach; ?>
</nav>
<?php endif; ?>

<?= stat_cards($sum, $year, $temples, $scopeText) ?>
<?php if ($u['role'] !== 'temple' && report_available()):
    // สรุปการส่งรายงานประจำเดือนของเดือนที่แล้ว (เดือนที่ครบกำหนดส่งล่าสุด) ในขอบเขตของผู้ใช้
    $rm = (int)date('n') === 1 ? 12 : (int)date('n') - 1;
    $ry = (int)date('n') === 1 ? be_year() - 1 : be_year();
    $rs = report_summary(report_list($pdo, $ry, $rm, ['district' => $dist]));
    if ($rs['total']): ?>
<a class="card-bar flex flex-wrap items-center justify-between gap-2 no-underline hover:border-gold-500 print:hidden" href="report_status.php?year=<?= $ry ?>&month=<?= $rm ?><?= $dist ? '&district=' . $dist : '' ?>">
  <span class="text-stone-800">รายงานประจำเดือน<?= e(TH_MONTHS[$rm]) ?> <?= $ry ?>: ส่งแล้ว <strong><?= $rs['sent'] ?></strong> จาก <?= $rs['total'] ?> วัด<?= $rs['late'] ? ' · <strong class="text-red-700">เกินกำหนด ' . $rs['late'] . ' วัด</strong>' : '' ?></span>
  <span class="font-semibold text-brand-700">ติดตามรายงาน →</span>
</a>
<?php endif; endif; ?>

<section class="card">
    <div class="mb-2 flex flex-wrap items-center justify-between gap-2">
      <h3 class="!mb-0">รายรับ–รายจ่ายรายเดือน</h3>
      <span class="flex items-center gap-3 text-sm text-stone-600">
        <span class="flex items-center gap-1"><i class="inline-block h-3 w-3 rounded-sm bg-emerald-600"></i>รายรับ</span>
        <span class="flex items-center gap-1"><i class="inline-block h-3 w-3 rounded-sm bg-red-500"></i>รายจ่าย</span>
      </span>
    </div>
    <?= monthly_chart($series) ?>
    <details class="mt-2 text-sm print:hidden">
      <summary class="cursor-pointer text-stone-600">ดูตัวเลขรายเดือน</summary>
      <div class="mt-2 overflow-x-auto rounded-xl border border-stone-200"><table class="data-table">
        <thead><tr><th>เดือน</th><th class="text-right">รายรับ</th><th class="text-right">รายจ่าย</th><th class="text-right">สุทธิ</th></tr></thead>
        <tbody>
        <?php foreach ($series as $m => $v): ?>
          <tr><td><?= TH_MONTHS[$m] ?></td><td class="text-right text-emerald-700"><?= money($v['inc']) ?></td><td class="text-right text-red-700"><?= money($v['exp']) ?></td><td class="text-right font-semibold text-sky-800"><?= money($v['inc'] - $v['exp']) ?></td></tr>
        <?php endforeach; ?>
        </tbody>
      </table></div>
    </details>
</section>

<div class="mt-4 grid gap-4 md:grid-cols-2">
  <section class="card">
    <h3>หมวดรายรับสูงสุด</h3>
    <?= category_bars($incCats, 'bg-emerald-600') ?>
  </section>
  <section class="card">
    <h3>หมวดรายจ่ายสูงสุด</h3>
    <?= category_bars($expCats, 'bg-red-500') ?>
  </section>
</div>

<?php if ($dist): ?>
<section class="mt-4">
  <div class="mb-2 flex flex-wrap items-end justify-between gap-3">
    <h3 class="!mb-0"><?= $subLabel !== '' ? 'วัดใน' . unit_subdistrict() . 'ที่ท่านดูแล' : 'วัดใน' . unit_district() . e($distName) ?></h3>
    <span class="text-sm text-stone-600">บันทึกบัญชีแล้ว <strong><?= $withData ?></strong> จาก <?= count($rows) ?> วัด
      <?php if ($rows): ?>(<?= round($withData / count($rows) * 100) ?>%)<?php endif; ?></span>
  </div>
  <div class="card-table"><table class="data-table">
    <thead><tr><th class="text-right">#</th><th>วัด</th><th><?= e(unit_subdistrict()) ?></th><th class="text-right">รายรับ</th><th class="text-right">รายจ่าย</th><th class="text-right">คงเหลือ</th><th class="text-right">รายการ</th><th>บันทึกล่าสุด</th></tr></thead>
    <tbody>
    <?php foreach ($rows as $i => $r): $none = (int)$r['n'] === 0; ?>
      <tr class="<?= $none ? 'text-stone-500' : '' ?>">
        <td class="text-right text-stone-400"><?= $i + 1 ?></td>
        <td class="font-semibold"><?= e($r['name']) ?></td>
        <td><?= e($r['subdistrict'] ?? '-') ?></td>
        <td class="whitespace-nowrap text-right text-emerald-700"><?= money($r['income']) ?></td>
        <td class="whitespace-nowrap text-right text-red-700"><?= money($r['expense']) ?></td>
        <td class="whitespace-nowrap text-right font-semibold text-sky-800"><?= money($r['income'] - $r['expense']) ?></td>
        <td class="text-right"><?= (int)$r['n'] ?></td>
        <td class="whitespace-nowrap"><?= $none ? '<span class="badge">ยังไม่มีรายการ</span>' : e(th_date($r['last_date'])) ?></td>
      </tr>
    <?php endforeach; ?>
    <?php if (!$rows): ?><tr><td colspan="8" class="text-stone-500">ไม่พบวัดใน<?= e(unit_district()) ?>นี้</td></tr><?php endif; ?>
    </tbody>
  </table></div>
  <p class="mt-2 text-sm print:hidden"><a class="font-semibold" href="reports.php?<?= e(http_build_query(['year' => $year, 'district' => $dist])) ?>">ดูรายงานฉบับเต็มของ<?= e(unit_district()) ?>นี้ →</a></p>
</section>
<?php else: ?>
<section class="mt-4">
  <h3>เปรียบเทียบราย<?= e(unit_district()) ?></h3>
  <div class="card-table"><table class="data-table">
    <thead><tr><th><?= e(unit_district()) ?></th><th class="text-right">วัด</th><th class="text-right">รายรับ</th><th class="text-right">รายจ่าย</th><th class="text-right">คงเหลือ</th></tr></thead>
    <tbody>
    <?php foreach ($rows as $r): ?>
      <tr>
        <td><a class="font-semibold" href="dashboard.php?<?= e($q(['district' => (int)$r['id']])) ?>"><?= e($r['name']) ?></a></td>
        <td class="text-right"><?= (int)$r['temples'] ?></td>
        <td class="whitespace-nowrap text-right text-emerald-700"><?= money($r['income']) ?></td>
        <td class="whitespace-nowrap text-right text-red-700"><?= money($r['expense']) ?></td>
        <td class="whitespace-nowrap text-right font-semibold text-sky-800"><?= money($r['income'] - $r['expense']) ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
</section>
<?php endif; ?>
<?php require __DIR__ . '/includes/footer.php'; ?>
