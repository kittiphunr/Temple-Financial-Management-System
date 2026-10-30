<?php
require __DIR__ . '/includes/bootstrap.php';
require __DIR__ . '/includes/stats.php';
require __DIR__ . '/includes/dashboard_ui.php';

$u = user();
$year = (int)($_GET['year'] ?? be_year());
$pageTitle = 'หน้าแรก';
require __DIR__ . '/includes/header.php';

$features = [
    ['จัดการบัญชีรายรับ', 'บันทึกรายรับจากการบริจาค การทำบุญ และรายได้อื่น ๆ ของวัด', 'transactions.php?type=income', 'bg-emerald-100 text-emerald-700', '＋'],
    ['จัดการบัญชีรายจ่าย', 'บันทึกค่าบูรณปฏิสังขรณ์ ค่าสาธารณูปโภค และค่าใช้จ่ายอื่น ๆ', 'transactions.php?type=expense', 'bg-red-100 text-red-700', '－'],
    ['ดูรายงานและสรุปผล', 'สรุปรายรับ-รายจ่าย รายเดือน รายปี แยกตาม' . unit_district() . 'และวัด', 'reports.php', 'bg-sky-100 text-sky-700', '▤'],
    ['พิมพ์และส่งออกเอกสาร', 'พิมพ์รายงาน และส่งออกไฟล์ CSV (เปิดด้วย Excel ได้)', 'reports.php?export=csv', 'bg-amber-100 text-amber-700', '⇩'],
];
?>
<section class="relative overflow-hidden rounded-3xl border border-gold-200 bg-gradient-to-b from-[#fff6dc] via-[#f8dfb4] to-[#e9b98c] px-6 pb-10 pt-12 text-center text-brand-900 shadow-sm">
  <div class="absolute inset-y-0 left-0 w-6 bg-[repeating-linear-gradient(135deg,#9b1c1c_0_10px,#b8892e_10px_14px)] sm:w-20"></div>
  <div class="absolute inset-y-0 right-0 w-6 bg-[repeating-linear-gradient(135deg,#9b1c1c_0_10px,#b8892e_10px_14px)] sm:w-20"></div>
  <div class="relative">
    <img class="mx-auto h-28 w-28 drop-shadow" src="<?= BASE ?>assets/img/logo.svg" alt="ตราสัญลักษณ์">
    <h1 class="mt-3 text-3xl font-bold text-brand-700 sm:text-5xl"><?= e($CONFIG['app_name']) ?></h1>
    <p class="mt-1 text-lg sm:text-xl"><?= e(app_subtitle()) ?></p>
    <div class="mx-auto mt-5 flex max-w-xl flex-wrap justify-center gap-x-5 gap-y-1 rounded-full bg-white/80 px-6 py-2.5 font-semibold">
      <span>จัดเก็บข้อมูลบัญชี</span><span class="text-gold-500">•</span><span>ตรวจสอบได้</span><span class="text-gold-500">•</span><span>พิมพ์และส่งออกเอกสารได้</span>
    </div>
    <div class="mt-6 flex flex-wrap justify-center gap-3">
      <a class="btn px-7 py-2.5 text-white" href="<?= $u ? 'transactions.php?type=income' : 'login.php' ?>">เริ่มใช้งาน →</a>
      <a class="btn btn-outline bg-white/70 px-7 py-2.5" href="<?= $u ? 'reports.php' : 'login.php' ?>">ดูรายงาน</a>
    </div>
  </div>
</section>

<div class="mt-5 grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
  <?php foreach ($features as [$ttl, $desc, $href, $iconCls, $icon]): ?>
  <a class="card group flex gap-3 no-underline transition hover:border-gold-500 hover:shadow-md" href="<?= $u ? ((in_array($u['role'], ['province', 'subdistrict'], true) && str_starts_with($href, 'transactions')) ? 'dashboard.php' : $href) : 'login.php' ?>">
    <span class="flex h-12 w-12 shrink-0 items-center justify-center rounded-full text-xl font-bold <?= $iconCls ?>"><?= $icon ?></span>
    <span>
      <span class="block font-bold text-brand-700"><?= e($ttl) ?></span>
      <span class="text-sm text-stone-600"><?= e($desc) ?></span>
    </span>
  </a>
  <?php endforeach; ?>
</div>

<?php if ($u):
    $sum = summary($year);
    $rows = summary_by_district($year);
    $max = 1;
    foreach ($rows as $r) { $max = max($max, $r['income'], $r['expense']); }
?>
<div class="mb-4 mt-8 flex flex-wrap items-end justify-between gap-3">
  <h2 class="!my-0">ภาพรวมบัญชีวัด (ปี พ.ศ. <?= $year ?>)</h2>
  <form method="get">
    <label class="sr-only" for="year">ปี พ.ศ.</label>
    <select id="year" name="year" onchange="this.form.submit()">
      <?php for ($y = be_year() + 1; $y >= be_year() - 6; $y--): ?>
        <option value="<?= $y ?>" <?= $y === $year ? 'selected' : '' ?>>ปี พ.ศ. <?= $y ?></option>
      <?php endfor; ?>
    </select>
  </form>
</div>
<?= stat_cards($sum, $year, temple_count(), 'ในสังกัด' . province_phrase()) ?>

<h2 class="mt-8">สรุปตาม<?= e(unit_district()) ?> <a class="ml-2 text-base font-semibold" href="dashboard.php?year=<?= $year ?>">ดูแดชบอร์ดแต่ละ<?= e(unit_district()) ?> →</a></h2>
<div class="card-table"><table class="data-table">
  <thead><tr><th><?= e(unit_district()) ?></th><th class="text-right">วัด</th><th>รายรับ</th><th>รายจ่าย</th><th class="text-right">คงเหลือ</th></tr></thead>
  <tbody>
  <?php foreach ($rows as $r): ?>
  <tr>
    <td><a class="font-semibold" href="dashboard.php?year=<?= $year ?>&amp;district=<?= (int)$r['id'] ?>"><?= e($r['name']) ?></a></td>
    <td class="text-right"><?= (int)$r['temples'] ?></td>
    <td class="min-w-[160px]"><div class="h-2.5 overflow-hidden rounded-full bg-stone-200"><i class="block h-full bg-emerald-600" style="width:<?= round($r['income'] / $max * 100) ?>%"></i></div><span class="text-emerald-700"><?= money($r['income']) ?></span></td>
    <td class="min-w-[160px]"><div class="h-2.5 overflow-hidden rounded-full bg-stone-200"><i class="block h-full bg-red-600" style="width:<?= round($r['expense'] / $max * 100) ?>%"></i></div><span class="text-red-700"><?= money($r['expense']) ?></span></td>
    <td class="whitespace-nowrap text-right font-semibold text-sky-800"><?= money($r['income'] - $r['expense']) ?></td>
  </tr>
  <?php endforeach; ?>
  </tbody>
</table></div>
<?php else: ?>
<p class="mt-6 text-center text-stone-500">กรุณาเข้าสู่ระบบเพื่อดูข้อมูลบัญชี</p>
<?php endif; ?>
<?php require __DIR__ . '/includes/footer.php'; ?>
