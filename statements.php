<?php
/** รายงานประจำเดือนตามแบบฟอร์มของวัด: แบบที่ 1 เงินสด / แบบที่ 2 เงินฝากธนาคาร / แบบที่ 3 เงินคงเหลือ */
require __DIR__ . '/includes/bootstrap.php';
require __DIR__ . '/includes/statement_ui.php';
$u = require_login();
$pdo = db();

$tid = $u['role'] === 'temple' ? (int)$u['temple_id'] : (int)($_GET['temple'] ?? $_POST['temple'] ?? 0);
$temple = $tid && people_can_view($tid) ? temple_row($tid) : null;
$year = (int)($_GET['year'] ?? $_POST['year'] ?? be_year());
$month = (int)($_GET['month'] ?? $_POST['month'] ?? (int)date('n'));
$month = $month >= 1 && $month <= 12 ? $month : (int)date('n');
$form = in_array($_GET['form'] ?? 'all', ['all', '1', '2', '3'], true) ? ($_GET['form'] ?? 'all') : 'all';
$ceYear = $year - 543;
$groups = temples_grouped();
$canSubmit = $temple && report_available() && can_access_temple($tid) && $u['role'] !== 'province';

if ($canSubmit && $_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'submit_report') {
    csrf_check();
    [$ok, $msg] = report_submit($pdo, $tid, $year, $month, (int)$u['id'], (string)($_POST['note'] ?? ''));
    if ($ok) {
        audit('report_submit', 'temple', $tid, "$year/$month");
    }
    flash($msg, $ok ? 'ok' : 'err');
    redirect('statements.php?' . http_build_query(['temple' => $tid, 'year' => $year, 'month' => $month, 'form' => $form]));
}

$canClose = $canSubmit && month_close_available();                                  // แอดมินวัด/แอดมินหลัก/แอดมินอำเภอปิดงวดได้
$canReopen = $canClose && in_array($u['role'], ['admin', 'district'], true);         // เปิดงวด: แอดมินหลัก/แอดมินอำเภอเท่านั้น (ต้องระบุเหตุผล)
if ($canClose && $_SERVER['REQUEST_METHOD'] === 'POST' && in_array($_POST['action'] ?? '', ['close_month', 'reopen_month'], true)) {
    csrf_check();
    if ($_POST['action'] === 'close_month') {
        [$ok, $msg] = report_close($pdo, $tid, $year, $month, (int)$u['id']);
        $ok && audit('month_close', 'temple', $tid, "$year/$month");
    } elseif ($canReopen) {
        [$ok, $msg] = report_reopen($pdo, $tid, $year, $month, (int)$u['id'], (string)($_POST['reason'] ?? ''));
        $ok && audit('month_reopen', 'temple', $tid, "$year/$month " . mb_substr(trim((string)($_POST['reason'] ?? '')), 0, 100));
    } else {
        [$ok, $msg] = [false, 'เฉพาะแอดมินหลักหรือแอดมินอำเภอเท่านั้นที่เปิดงวดได้'];
    }
    flash($msg, $ok ? 'ok' : 'err');
    redirect('statements.php?' . http_build_query(['temple' => $tid, 'year' => $year, 'month' => $month, 'form' => $form]));
}

$pageTitle = 'รายงานแบบที่ 1–3 ประจำเดือน';
require __DIR__ . '/includes/header.php';
?>
<div class="flex flex-wrap items-center justify-between gap-2 print:hidden">
  <h2>รายงานประจำเดือนตามแบบฟอร์มของวัด</h2>
  <span class="flex flex-wrap gap-2"><a class="btn btn-outline btn-sm" href="reports.php">รายงานสรุป</a>
    <?php if ($temple && !in_array($u['role'], ['province', 'subdistrict'], true)): ?><a class="btn btn-outline btn-sm" href="accounts.php<?= $u['role'] === 'temple' ? '' : '?temple=' . $tid ?>">บัญชีเงินสด/ธนาคาร · ยอดยกมา</a><?php endif; ?></span>
</div>
<form class="my-4 flex flex-wrap items-end gap-3 print:hidden" method="get">
  <?php if ($u['role'] !== 'temple'): ?>
  <div><label>วัด</label><select name="temple">
    <option value="0">— เลือกวัด —</option>
    <?php foreach ($groups as $dn => $list): ?><optgroup label="<?= e(unit_district()) ?><?= e($dn) ?>"><?php foreach ($list as $t): ?><option value="<?= (int)$t['id'] ?>" <?= $t['id'] == $tid ? 'selected' : '' ?>><?= e($t['name']) ?></option><?php endforeach; ?></optgroup><?php endforeach; ?>
  </select></div>
  <?php endif; ?>
  <div><label>เดือน</label><select name="month"><?php for ($m = 1; $m <= 12; $m++): ?><option value="<?= $m ?>" <?= $m === $month ? 'selected' : '' ?>><?= e(TH_MONTHS[$m]) ?></option><?php endfor; ?></select></div>
  <div><label>ปี พ.ศ.</label><select name="year"><?php for ($y = be_year() + 1; $y >= be_year() - 8; $y--): ?><option <?= $y === $year ? 'selected' : '' ?>><?= $y ?></option><?php endfor; ?></select></div>
  <div><label>แบบฟอร์ม</label><select name="form">
    <option value="all" <?= $form === 'all' ? 'selected' : '' ?>>ชุดทั้งหมด (แบบที่ 1 + 2 ทุกบัญชี + 3)</option>
    <option value="1" <?= $form === '1' ? 'selected' : '' ?>>แบบที่ 1 เงินสด</option>
    <option value="2" <?= $form === '2' ? 'selected' : '' ?>>แบบที่ 2 เงินฝากธนาคาร (ทุกบัญชี)</option>
    <option value="3" <?= $form === '3' ? 'selected' : '' ?>>แบบที่ 3 รายงานเงินคงเหลือ</option>
  </select></div>
  <button class="btn" type="submit">แสดง</button>
  <?php if ($temple): ?><button class="btn btn-outline" type="button" onclick="window.print()"><?= icon('printer') ?> พิมพ์ / บันทึก PDF</button><?php endif; ?>
</form>
<?php
if (!$temple) {
    echo '<p class="text-stone-600">เลือกวัดและเดือนที่ต้องการออกรายงาน</p>';
} else {
    if (report_available()) {
        $rep = report_row($pdo, $tid, $year, $month);
        $now = report_snapshot($pdo, $tid, $year, $month);
        $state = report_state($rep, $now, $year, $month);
        [$stLabel, $stCls] = REPORT_STATE_LABEL[$state];
        echo '<div class="card-bar flex flex-wrap items-center justify-between gap-3 print:hidden">'
            . '<div><div class="text-sm text-stone-600">การส่งรายงานประจำเดือน' . e(TH_MONTHS[$month]) . ' ' . $year . ' (กำหนดส่ง ' . e(th_date(report_due_date($year, $month))) . ')</div>'
            . '<div class="mt-1"><span class="badge ' . $stCls . '">' . e($stLabel) . '</span> '
            . ($rep ? '<span class="text-sm text-stone-600">ส่งเมื่อ ' . e(th_datetime($rep['submitted_at'])) . ' โดย ' . e((string)($rep['by_name'] ?? '-')) . ($rep['revision'] > 1 ? ' (ครั้งที่ ' . (int)$rep['revision'] . ')' : '') . '</span>' : '')
            . ($state === 'changed' ? '<div class="mt-1 text-sm text-amber-900">หลังส่งแล้วมีการแก้ไขรายการเดือนนี้ (ตอนส่ง ' . (int)$rep['txn_count'] . ' รายการ ตอนนี้ ' . $now['count'] . ' รายการ) — ตรวจแล้วส่งรายงานใหม่</div>' : '')
            . '</div>';
        $closed = $rep && !empty($rep['closed_at']);
        $evs = $closed || ($rep && month_close_available()) ? report_close_events($pdo, $tid, $year, $month) : [];
        if ($closed) {
            echo '<div class="basis-full text-sm"><span class="badge badge-ok">' . icon('lock') . ' ปิดงวดแล้ว</span> <span class="text-stone-600">' . e(th_datetime($rep['closed_at'])) . ' — แก้ไข/เพิ่ม/ลบรายการของเดือนนี้ไม่ได้</span></div>';
        }
        if ($canSubmit && $state !== 'future' && !$closed) {
            echo '<form method="post" class="flex flex-wrap items-center gap-2" data-confirm="' . e($rep ? 'ส่งรายงานเดือนนี้ใหม่?' : ($now['count'] === 0 ? 'เดือนนี้ไม่มีรายการ ยืนยันส่งรายงาน (ไม่มีรายการ)?' : 'ยืนยันส่งรายงานประจำเดือนนี้?')) . '">'
                . csrf_field() . '<input type="hidden" name="action" value="submit_report"><input type="hidden" name="year" value="' . $year . '"><input type="hidden" name="month" value="' . $month . '">'
                . '<input name="note" maxlength="255" placeholder="หมายเหตุ (ถ้ามี)" class="w-56 !py-1 text-sm">'
                . '<button class="btn btn-sm">' . ($rep ? 'ส่งรายงานใหม่' : 'ส่งรายงานประจำเดือน') . '</button></form>';
        }
        if ($canClose && !$closed && $state === 'ok') {
            echo '<form method="post" data-confirm="ปิดงวดเดือนนี้? หลังปิดแล้วแก้ไข/เพิ่ม/ลบรายการของเดือนนี้ไม่ได้ จนกว่าแอดมินหลักหรือแอดมินอำเภอจะเปิดงวด">' . csrf_field()
                . '<input type="hidden" name="action" value="close_month"><input type="hidden" name="year" value="' . $year . '"><input type="hidden" name="month" value="' . $month . '">'
                . '<button class="btn btn-sm btn-outline">' . icon('lock') . ' ปิดงวดเดือนนี้</button></form>';
        }
        if ($canReopen && $closed) {
            echo '<form method="post" class="flex flex-wrap items-center gap-2" data-confirm="เปิดงวดเดือนนี้ให้แก้ไขรายการได้?">' . csrf_field()
                . '<input type="hidden" name="action" value="reopen_month"><input type="hidden" name="year" value="' . $year . '"><input type="hidden" name="month" value="' . $month . '">'
                . '<input name="reason" required minlength="5" maxlength="255" placeholder="เหตุผลที่เปิดงวด (จำเป็น)" class="w-64 !py-1 text-sm"><button class="btn btn-sm btn-danger">เปิดงวด</button></form>';
        }
        if ($evs) {
            echo '<details class="basis-full text-sm"><summary class="cursor-pointer text-stone-600">ประวัติปิด/เปิดงวด (' . count($evs) . ')</summary><ul class="mt-1 grid gap-0.5">';
            foreach ($evs as $ev) {
                echo '<li>' . ($ev['action'] === 'close' ? 'ปิดงวด' : 'เปิดงวด') . ' · ' . e(th_datetime($ev['created_at'])) . ' · ' . e((string)($ev['full_name'] ?? '-')) . ($ev['reason'] ? ' · เหตุผล: ' . e($ev['reason']) : '') . '</li>';
            }
            echo '</ul></details>';
        }
        echo '</div>';
    }
    $c = stmt_context($pdo, $tid);
    $cash = ledger_account($pdo, ledger_cash_account($pdo, $tid));
    $needOpening = !$cash['opening_date'];
    if ($needOpening && !in_array($u['role'], ['province', 'subdistrict'], true)): ?>
<p class="mb-4 rounded-lg border border-amber-300 bg-amber-50 px-3 py-2 text-sm text-amber-950 print:hidden">ยังไม่ได้ตั้ง <strong>ยอดยกมา</strong> ของบัญชีเงินสด — ยอดยกมาในรายงานจะเริ่มที่ 0
  ตั้งได้ที่ <a class="underline" href="accounts.php<?= $u['role'] === 'temple' ? '' : '?temple=' . $tid ?>">บัญชีเงินสด/ธนาคาร</a></p>
    <?php endif;
    $out = '';
    if ($form === 'all' || $form === '1') {
        $out .= stmt_account_sheet($pdo, $c, $cash, $ceYear, $month);
    }
    if ($form === 'all' || $form === '2') {
        $banks = ledger_report_accounts($pdo, $tid, $ceYear, $month, 'bank');
        foreach ($banks as $i => $b) {
            $out .= stmt_account_sheet($pdo, $c, $b, $ceYear, $month, $i + 1);
        }
        if (!$banks && $form === '2') {
            $out .= '<p class="text-stone-600">วัดนี้ยังไม่มีบัญชีเงินฝากธนาคารในระบบ</p>';
        }
    }
    if ($form === 'all' || $form === '3') {
        $out .= stmt_balance_sheet($pdo, $c, $ceYear, $month);
    }
    echo '<div class="stmt-wrap">' . $out . '</div>';
}
require __DIR__ . '/includes/footer.php';
