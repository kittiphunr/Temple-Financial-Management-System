<?php
/** ติดตามการส่งรายงานประจำเดือนของวัด (ตามขอบเขตของผู้ใช้: แอดมินหลัก/เจ้าคณะจังหวัด = ทุกวัด, อำเภอ = วัดในอำเภอ, เจ้าคณะตำบล = วัดในตำบลที่ดูแล) */
require __DIR__ . '/includes/bootstrap.php';
$u = require_login();
if ($u['role'] === 'temple') {
    redirect('statements.php');
}
$pdo = db();
if (!report_available()) {
    http_response_code(503);
    exit('ยังไม่ได้รัน migration sql/migrations/2026_monthly_reports.sql — ดู UPGRADE.md');
}
$year = (int)($_GET['year'] ?? $_POST['year'] ?? be_year());
$month = (int)($_GET['month'] ?? $_POST['month'] ?? (int)date('n'));
$month = $month >= 1 && $month <= 12 ? $month : (int)date('n');
$year = max(be_year() - 8, min(be_year() + 1, $year));
$canRemind = in_array($u['role'], ['admin', 'district'], true);
$fDist = in_array($u['role'], ['admin', 'province'], true) ? (int)($_GET['district'] ?? $_POST['district'] ?? 0) : 0;
$fStatus = in_array($_GET['status'] ?? $_POST['status'] ?? 'all', ['all', 'sent', 'unsent', 'late', 'changed', 'closed', 'awaiting', 'returned', 'approved'], true) ? ($_GET['status'] ?? $_POST['status'] ?? 'all') : 'all';
$fQ = trim((string)($_GET['q'] ?? $_POST['q'] ?? ''));
$filters = ['district' => $fDist, 'status' => $fStatus, 'q' => $fQ];
$self = 'report_status.php?' . http_build_query(['year' => $year, 'month' => $month, 'district' => $fDist, 'status' => $fStatus, 'q' => $fQ]);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $act = $_POST['action'] ?? '';
    if ($act === 'remind' && $canRemind) {
        // เตือนเฉพาะวัดในขอบเขตของผู้ใช้ที่ยังไม่ส่ง (ไม่ขึ้นกับตัวกรองสถานะ แต่ใช้ตัวกรองอำเภอ/ชื่อ)
        $rows = report_list($pdo, $year, $month, ['district' => $fDist, 'q' => $fQ, 'status' => 'unsent']);
        $r = report_send_reminders($pdo, $rows, $year, $month, (int)$u['id']);
        audit('report_remind', 'report', null, "$year/$month sent={$r['sent']} skipped={$r['skipped']} failed={$r['failed']}");
        flash('ส่งอีเมลเตือนแล้ว ' . $r['sent'] . ' วัด · ข้าม ' . $r['skipped'] . ' วัด (ไม่มีอีเมล/ส่งส่วนนี้แล้ว/ยังไม่ถึงเดือน)' . ($r['failed'] ? ' · ส่งไม่สำเร็จ ' . $r['failed'] . ' วัด' : ''), $r['failed'] ? 'err' : 'ok');
    } elseif ($act === 'set_due' && $u['role'] === 'admin') {
        $d = (int)($_POST['due_day'] ?? 0);
        if ($d >= 1 && $d <= 28) {
            setting_set('report_due_day', (string)$d);
            audit('report_due_day', 'setting', null, (string)$d);
            flash('ตั้งกำหนดส่งรายงานเป็นวันที่ ' . $d . ' ของเดือนถัดไปแล้ว');
        } else {
            flash('วันที่กำหนดส่งต้องอยู่ระหว่าง 1–28', 'err');
        }
    }
    redirect($self);
}

$rows = report_list($pdo, $year, $month, $filters);
$all = $fStatus === 'all' ? $rows : report_list($pdo, $year, $month, ['district' => $fDist, 'q' => $fQ]);
$sum = report_summary($all);
$due = report_due_date($year, $month);
$ratio = $sum['total'] ? round(100 * $sum['sent'] / $sum['total']) : 0;

if (($_GET['export'] ?? '') === 'csv') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="report-status-' . $year . '-' . sprintf('%02d', $month) . '.csv"');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF");
    fputcsv($out, [unit_district(), unit_subdistrict(), 'วัด', 'สถานะ', 'วันที่ส่ง', 'ผู้ส่ง', 'ครั้งที่ส่ง', 'รายการ', 'รายรับ', 'รายจ่าย', 'เตือนทางอีเมลแล้ว', 'ปิดงวดเมื่อ', 'การรับรอง', 'เหตุผลที่ส่งกลับ']);
    foreach ($rows as $r) {
        fputcsv($out, [$r['district'], $r['subdistrict'], $r['name'], REPORT_STATE_LABEL[$r['state']][0], $r['submitted_at'] ?? '', $r['by_name'] ?? '', $r['revision'] ?? '',
            $r['n_count'], $r['n_income'], $r['n_expense'], $r['reminded_at'] ? 'ใช่' : '', $r['closed_at'] ?? '', REPORT_APPROVAL_LABEL[$r['approval']][0], $r['return_reason'] ?? '']);
    }
    audit('export', 'report_status', null, "$year/$month");
    exit;
}

$districts = $pdo->query('SELECT id, name FROM districts ORDER BY sort_order, id')->fetchAll();
$remindable = $canRemind ? count(array_filter(report_list($pdo, $year, $month, ['district' => $fDist, 'q' => $fQ, 'status' => 'unsent']), 'report_remindable')) : 0;
$pageTitle = 'ติดตามรายงาน';
require __DIR__ . '/includes/header.php';
?>
<div class="mb-4 flex flex-wrap items-end justify-between gap-3">
  <div>
    <h2 class="!my-0">ติดตามการส่งรายงานประจำเดือน</h2>
    <p class="mt-1 text-sm text-stone-600"><?= e(TH_MONTHS[$month]) ?> พ.ศ. <?= $year ?> · กำหนดส่งภายใน <?= e(th_date($due)) ?></p>
  </div>
  <a class="btn btn-outline btn-sm" href="<?= e($self . '&export=csv') ?>"><?= icon('download') ?> ส่งออก CSV (Excel)</a>
</div>

<form method="get" class="mb-4 flex flex-wrap items-end gap-3">
  <div><label>เดือน</label><select name="month"><?php for ($m = 1; $m <= 12; $m++): ?><option value="<?= $m ?>" <?= $m === $month ? 'selected' : '' ?>><?= e(TH_MONTHS[$m]) ?></option><?php endfor; ?></select></div>
  <div><label>ปี พ.ศ.</label><select name="year"><?php for ($y = be_year() + 1; $y >= be_year() - 8; $y--): ?><option <?= $y === $year ? 'selected' : '' ?>><?= $y ?></option><?php endfor; ?></select></div>
  <?php if (in_array($u['role'], ['admin', 'province'], true)): ?>
  <div><label><?= e(unit_district()) ?></label><select name="district"><option value="0">ทุก<?= e(unit_district()) ?></option>
    <?php foreach ($districts as $d): ?><option value="<?= (int)$d['id'] ?>" <?= (int)$d['id'] === $fDist ? 'selected' : '' ?>><?= e($d['name']) ?></option><?php endforeach; ?></select></div>
  <?php endif; ?>
  <div><label>สถานะ</label><select name="status">
    <?php foreach (['all' => 'ทุกสถานะ', 'sent' => 'ส่งแล้ว', 'unsent' => 'ยังไม่ส่ง', 'late' => 'เกินกำหนด', 'changed' => 'แก้ไขหลังส่ง'] + (month_close_available() ? ['closed' => 'ปิดงวดแล้ว'] : []) + (report_approval_available() ? ['awaiting' => 'รอรับรอง', 'returned' => 'ส่งกลับแก้ไข', 'approved' => 'อำเภอรับรองแล้ว'] : []) as $k => $v): ?><option value="<?= $k ?>" <?= $k === $fStatus ? 'selected' : '' ?>><?= $v ?></option><?php endforeach; ?></select></div>
  <div><label>ชื่อวัด</label><input name="q" value="<?= e($fQ) ?>" class="w-48"></div>
  <button class="btn">แสดง</button>
</form>

<div class="mb-4 grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
  <div class="card border-emerald-200 bg-emerald-50"><div class="text-sm text-emerald-900">ส่งแล้ว</div><div class="text-3xl font-bold text-emerald-800"><?= $sum['sent'] ?> <span class="text-base font-semibold">/ <?= $sum['total'] ?> วัด (<?= $ratio ?>%)</span></div>
    <div class="mt-2 h-2 overflow-hidden rounded-full bg-emerald-100"><div class="h-2 bg-emerald-600" style="width: <?= $ratio ?>%"></div></div></div>
  <div class="card"><div class="text-sm text-stone-600">ยังไม่ส่ง</div><div class="text-3xl font-bold"><?= $sum['unsent'] ?> <span class="text-base font-semibold">วัด</span></div></div>
  <div class="card border-red-200 bg-red-50"><div class="text-sm text-red-900">เกินกำหนด</div><div class="text-3xl font-bold text-red-800"><?= $sum['late'] ?> <span class="text-base font-semibold">วัด</span></div></div>
  <div class="card border-amber-200 bg-amber-50"><div class="text-sm text-amber-900">แก้ไขหลังส่ง</div><div class="text-3xl font-bold text-amber-800"><?= $sum['changed'] ?> <span class="text-base font-semibold">วัด</span></div></div>
  <?php if (report_approval_available()): ?>
  <div class="card border-sky-200 bg-sky-50"><div class="text-sm text-sky-900">รอรับรอง</div><div class="text-3xl font-bold text-sky-800"><?= $sum['awaiting'] ?> <span class="text-base font-semibold">วัด</span></div></div>
  <div class="card border-red-200 bg-red-50"><div class="text-sm text-red-900">ส่งกลับแก้ไข</div><div class="text-3xl font-bold text-red-800"><?= $sum['returned'] ?> <span class="text-base font-semibold">วัด</span></div></div>
  <div class="card border-emerald-200 bg-emerald-50"><div class="text-sm text-emerald-900"><?= e(unit_district()) ?>รับรองแล้ว</div><div class="text-3xl font-bold text-emerald-800"><?= $sum['approved'] ?> <span class="text-base font-semibold">วัด</span></div></div>
  <?php endif; ?>
  <?php if (month_close_available()): ?><div class="card bg-stone-50"><div class="text-sm text-stone-700"><?= icon('lock') ?> ปิดงวดแล้ว</div><div class="text-3xl font-bold text-stone-800"><?= $sum['closed'] ?> <span class="text-base font-semibold">วัด</span></div></div><?php endif; ?>
</div>

<?php if ($canRemind || $u['role'] === 'admin'): ?>
<div class="card-bar flex flex-wrap items-center gap-3 text-sm">
  <?php if ($canRemind): ?>
  <form method="post" data-confirm="ส่งอีเมลเตือนวัดที่ยังไม่ส่งรายงานเดือนนี้?" class="flex items-center gap-2"><?= csrf_field() ?>
    <input type="hidden" name="action" value="remind"><input type="hidden" name="year" value="<?= $year ?>"><input type="hidden" name="month" value="<?= $month ?>"><input type="hidden" name="district" value="<?= $fDist ?>"><input type="hidden" name="q" value="<?= e($fQ) ?>">
    <button class="btn btn-sm" <?= $remindable ? '' : 'disabled' ?>>ส่งอีเมลเตือนวัดที่ยังไม่ส่ง (<?= $remindable ?> วัด)</button>
    <span class="text-stone-500">เฉพาะวัดที่มีอีเมลและยังไม่เคยถูกเตือนเดือนนี้</span>
  </form>
  <?php endif; ?>
  <?php if ($u['role'] === 'admin'): ?>
  <form method="post" class="ml-auto flex items-center gap-2"><?= csrf_field() ?><input type="hidden" name="action" value="set_due"><input type="hidden" name="year" value="<?= $year ?>"><input type="hidden" name="month" value="<?= $month ?>">
    <label class="!mb-0">กำหนดส่ง: วันที่</label><input type="number" name="due_day" min="1" max="28" value="<?= report_due_day() ?>" class="w-20 ctl-sm"><span>ของเดือนถัดไป</span><button class="btn btn-sm btn-outline">บันทึก</button></form>
  <?php endif; ?>
</div>
<?php endif; ?>

<div class="card-table"><table class="data-table">
  <thead><tr><th><?= e(unit_district()) ?></th><th>วัด</th><th>สถานะ</th><?php if (report_approval_available()): ?><th>การรับรอง</th><?php endif; ?><th>ส่งเมื่อ</th><th class="text-right">รายการ</th><th class="text-right">รายรับ</th><th class="text-right">รายจ่าย</th><th>เตือน</th><th></th></tr></thead>
  <tbody>
  <?php foreach ($rows as $r): [$lab, $cls] = REPORT_STATE_LABEL[$r['state']]; ?>
  <tr>
    <td><?= e($r['district']) ?></td>
    <td class="font-semibold"><?= e($r['name']) ?><?php if ($r['subdistrict']): ?><div class="text-xs font-normal text-stone-500"><?= e(abbr_subdistrict()) ?><?= e($r['subdistrict']) ?></div><?php endif; ?></td>
    <td><span class="badge <?= $cls ?>"><?= e($lab) ?></span><?php if (!empty($r['closed_at'])): ?> <span class="badge badge-mute" title="ปิดงวดเมื่อ <?= e(th_datetime($r['closed_at'])) ?>"><?= icon('lock') ?> ปิดงวด</span><?php endif; ?><?php if ((int)($r['revision'] ?? 0) > 1): ?><div class="text-xs text-stone-500">ส่งครั้งที่ <?= (int)$r['revision'] ?></div><?php endif; ?></td>
    <?php if (report_approval_available()): [$apL, $apC] = REPORT_APPROVAL_LABEL[$r['approval']]; ?>
    <td class="text-sm"><?php if ($apL !== ''): ?><span class="badge <?= $apC ?>"><?= e($apL) ?></span><?php endif; ?><?php if ($r['approval'] === 'returned'): ?><div class="mt-1 text-xs text-red-800"><?= e((string)$r['return_reason']) ?></div><?php endif; ?></td>
    <?php endif; ?>
    <td class="whitespace-nowrap text-sm"><?= $r['submitted_at'] ? e(th_datetime($r['submitted_at'])) . '<div class="text-xs text-stone-500">' . e((string)$r['by_name']) . '</div>' : '—' ?></td>
    <td class="text-right"><?= (int)$r['n_count'] ?><?php if ($r['state'] === 'changed'): ?><div class="text-xs text-amber-800">ตอนส่ง <?= (int)$r['r_count'] ?></div><?php endif; ?></td>
    <td class="text-right"><?= money($r['n_income']) ?></td>
    <td class="text-right"><?= money($r['n_expense']) ?></td>
    <td class="text-sm"><?= $r['reminded_at'] ? '<span class="text-stone-600">เตือนแล้ว ' . e(th_date_short(substr($r['reminded_at'], 0, 10))) . '</span>' : (in_array($r['state'], ['pending', 'late'], true) && trim((string)$r['email']) === '' ? '<span class="text-red-700">ไม่มีอีเมลวัด</span>' : '') ?></td>
    <td><a class="btn btn-sm btn-outline whitespace-nowrap" href="statements.php?temple=<?= (int)$r['id'] ?>&year=<?= $year ?>&month=<?= $month ?>">ดูรายงาน</a></td>
  </tr>
  <?php endforeach; ?>
  <?php if (!$rows): ?><tr><td colspan="10" class="text-stone-500">ไม่พบวัดตามเงื่อนไขที่เลือก</td></tr><?php endif; ?>
  </tbody>
</table></div>
<p class="mt-3 text-sm text-stone-600">"แก้ไขหลังส่ง" = หลังส่งรายงานแล้วมีการเพิ่ม/แก้ไข/ลบรายการของเดือนนั้น ยอดปัจจุบันจึงไม่ตรงกับตอนส่ง วัดควรตรวจและส่งรายงานใหม่<?php if (report_approval_available()): ?> · การรับรอง: วัดส่ง → เจ้าคณะ<?= e(unit_subdistrict()) ?>รับรอง → <?= e(unit_district()) ?>รับรอง (ส่งกลับแก้ไขได้ทุกขั้น วัดส่งใหม่แล้วเริ่มรับรองใหม่) · กดปุ่มท้ายแถวของวัดเพื่อตรวจและรับรอง<?php endif; ?></p>
<?php require __DIR__ . '/includes/footer.php'; ?>
