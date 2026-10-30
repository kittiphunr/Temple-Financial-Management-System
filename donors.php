<?php
/** ทะเบียนผู้บริจาค (ไม่บังคับ): เฉพาะผู้ที่ให้ความยินยอมตาม PDPA — เพิ่ม/แก้ไข ดูประวัติบริจาค และถอนความยินยอม */
require __DIR__ . '/includes/bootstrap.php';
$u = require_login();
$pdo = db();
if (!donor_available()) {
    flash('ยังไม่ได้เปิดใช้ทะเบียนผู้บริจาค (ผู้ดูแลต้องรัน sql/migrations/2026_donors.sql)', 'err');
    redirect('dashboard.php');
}
if (in_array($u['role'], ['province', 'subdistrict'], true)) {   // ข้อมูลส่วนบุคคลของผู้บริจาค: ไม่เปิดให้เจ้าคณะจังหวัด/ตำบล
    redirect('dashboard.php');
}

$tid = $u['role'] === 'temple' ? (int)$u['temple_id'] : (int)($_GET['temple'] ?? $_POST['temple'] ?? 0);
$temple = $tid && donor_can_manage($tid) ? temple_row($tid) : null;
$tq = $u['role'] === 'temple' ? [] : ['temple' => $tid];
$url = fn(array $extra = []) => 'donors.php' . ($tq || $extra ? '?' . http_build_query($tq + $extra) : '');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    if (!$temple) {
        http_response_code(403);
        exit('ไม่มีสิทธิ์จัดการทะเบียนผู้บริจาคของวัดนี้');
    }
    $act = $_POST['action'] ?? '';
    $did = (int)($_POST['donor_id'] ?? 0);
    if ($act === 'save') {
        [$id, $err] = donor_save($pdo, $tid, $did, $_POST, (int)$u['id']);
        if ($err !== '') {
            flash($err, 'err');
            redirect($url($did ? ['edit' => $did] : ['new' => 1]));
        }
        flash($did ? 'บันทึกข้อมูลผู้บริจาคแล้ว' : 'เพิ่มผู้บริจาคเข้าทะเบียนแล้ว');
        redirect($url(['donor' => $id]));
    }
    if ($act === 'withdraw') {
        flash(donor_withdraw($pdo, $did, $tid, (int)$u['id']) ? 'บันทึกการถอนความยินยอมแล้ว ข้อมูลส่วนบุคคลในทะเบียนถูกลบ' : 'ไม่พบผู้บริจาคหรือถอนความยินยอมไปแล้ว', 'ok');
        redirect($url());
    }
    redirect($url());
}

$q = trim((string)($_GET['q'] ?? ''));
$donors = $temple ? donor_list($pdo, $tid, $q) : [];
$detail = $temple && !empty($_GET['donor']) ? donor_get($pdo, (int)$_GET['donor'], $tid) : null;
$edit = $temple && !empty($_GET['edit']) ? donor_get($pdo, (int)$_GET['edit'], $tid) : null;
if ($edit && $edit['withdrawn_at']) {
    $edit = null;
}
$showForm = $temple && (isset($_GET['new']) || $edit) && !$detail;
if ($detail) {
    audit('view', 'donor', (int)$detail['id']);
}
$groups = temples_grouped();

$pageTitle = 'ทะเบียนผู้บริจาค';
require __DIR__ . '/includes/header.php';
?>
<?php if ($detail): $rcs = donor_receipts($pdo, (int)$detail['id']); $sum = 0.0; ?>
<div class="flex flex-wrap items-center justify-between gap-2"><h2><?= e($detail['name']) ?> · <?= e($temple['name']) ?></h2>
  <span class="flex gap-2"><?php if (!$detail['withdrawn_at']): ?><a class="btn btn-outline btn-sm" href="<?= e($url(['edit' => (int)$detail['id']])) ?>"><?= icon('edit-2') ?> แก้ไข</a><?php endif; ?><a class="btn btn-outline btn-sm" href="<?= e($url()) ?>"><?= icon('arrow-left') ?> กลับ</a></span></div>
<section class="card">
  <?php if ($detail['withdrawn_at']): ?>
    <p class="rounded-lg border border-stone-300 bg-stone-50 p-3">ผู้บริจาคถอนความยินยอมเมื่อ <?= e(th_date(substr($detail['withdrawn_at'], 0, 10))) ?> — ข้อมูลส่วนบุคคลในทะเบียนถูกลบแล้ว (ใบอนุโมทนาที่ออกไปแล้วยังเก็บสำเนาตามหลักฐานทางบัญชี)</p>
  <?php else: ?>
  <dl class="grid gap-x-6 gap-y-2 sm:grid-cols-2">
    <div class="sm:col-span-2"><dt class="text-xs text-stone-500">ที่อยู่</dt><dd><?= e($detail['address'] ?: '-') ?></dd></div>
    <div><dt class="text-xs text-stone-500">เบอร์โทร</dt><dd><?= e($detail['phone'] ?: '-') ?></dd></div>
    <div><dt class="text-xs text-stone-500">หมายเหตุ</dt><dd><?= e($detail['note'] ?: '-') ?></dd></div>
    <div class="sm:col-span-2"><dt class="text-xs text-stone-500">ความยินยอม</dt><dd>ให้ความยินยอม <?= e(DONOR_CHANNELS[$detail['consent_channel']] ?? $detail['consent_channel']) ?> · บันทึกเมื่อ <?= e(th_date(substr($detail['consent_at'], 0, 10))) ?></dd></div>
  </dl>
  <form method="post" class="mt-3" data-confirm="ผู้บริจาคถอนความยินยอม? ระบบจะลบชื่อ ที่อยู่ และเบอร์โทรในทะเบียนทันที กู้คืนไม่ได้">
    <?= csrf_field() ?><input type="hidden" name="action" value="withdraw"><input type="hidden" name="donor_id" value="<?= (int)$detail['id'] ?>"><?php foreach ($tq as $k => $v): ?><input type="hidden" name="<?= $k ?>" value="<?= (int)$v ?>"><?php endforeach; ?>
    <button class="btn btn-danger btn-sm">บันทึกการถอนความยินยอม (ลบข้อมูลส่วนบุคคล)</button></form>
  <?php endif; ?>
</section>
<h3 class="mt-6">ประวัติการบริจาค (ตามใบอนุโมทนาที่ออก)</h3>
<div class="card-table"><table class="data-table">
  <tr><th>เลขที่ใบ</th><th>วันที่</th><th>วัตถุประสงค์</th><th>สถานะ</th><th class="text-right">จำนวนเงิน</th></tr>
  <?php foreach ($rcs as $r): if ($r['status'] === 'issued') $sum += (float)$r['amount']; ?>
  <tr class="<?= $r['status'] === 'void' ? 'opacity-60' : '' ?>"><td><a class="underline" href="receipt.php?id=<?= (int)$r['id'] ?>"><?= (int)$r['receipt_no'] ?>/<?= (int)$r['receipt_year'] ?></a></td><td><?= e(th_date($r['receipt_date'])) ?></td><td><?= e($r['purpose']) ?></td><td><?= $r['status'] === 'void' ? 'ยกเลิก' : 'ออกแล้ว' ?></td><td class="text-right"><?= money($r['amount']) ?></td></tr>
  <?php endforeach; ?>
  <?php if (!$rcs): ?><tr><td colspan="5" class="text-stone-500">ยังไม่มีใบอนุโมทนา — เลือกผู้บริจาคนี้ตอนออกอนุโมทนาบัตรเพื่อบันทึกประวัติ</td></tr><?php endif; ?>
  <tr><th colspan="4" class="text-right">รวมที่ออกใบแล้ว</th><th class="text-right"><?= money($sum) ?></th></tr>
</table></div>
<?php elseif ($showForm): $v = $edit ?? []; ?>
<div class="flex flex-wrap items-center justify-between gap-2"><h2><?= $edit ? 'แก้ไขผู้บริจาค' : 'เพิ่มผู้บริจาค' ?> · <?= e($temple['name']) ?></h2><a class="btn btn-outline btn-sm" href="<?= e($url()) ?>">ยกเลิก</a></div>
<form method="post" class="card grid gap-3 sm:grid-cols-2 [&_input:not([type=checkbox])]:w-full [&_select]:w-full [&_textarea]:w-full">
  <?= csrf_field() ?><input type="hidden" name="action" value="save"><input type="hidden" name="donor_id" value="<?= (int)($edit['id'] ?? 0) ?>"><?php foreach ($tq as $k => $val): ?><input type="hidden" name="<?= $k ?>" value="<?= (int)$val ?>"><?php endforeach; ?>
  <div><label>ชื่อผู้บริจาค *</label><input name="name" required maxlength="200" value="<?= e($v['name'] ?? '') ?>"></div>
  <div><label>เบอร์โทร</label><input name="phone" inputmode="tel" maxlength="20" value="<?= e($v['phone'] ?? '') ?>"></div>
  <div class="col-span-full"><label>ที่อยู่ (ใช้เติมในอนุโมทนาบัตร)</label><textarea name="address" rows="2" maxlength="500"><?= e($v['address'] ?? '') ?></textarea></div>
  <div class="col-span-full"><label>หมายเหตุ</label><input name="note" maxlength="255" value="<?= e($v['note'] ?? '') ?>"></div>
  <?php if (!$edit): ?>
  <div class="col-span-full rounded-xl bg-gold-50 p-3 text-sm text-stone-800">
    <p class="mb-2"><strong>การขอความยินยอม (PDPA)</strong> วัตถุประสงค์: เก็บชื่อ ที่อยู่ และเบอร์โทรของผู้บริจาคเพื่อออกอนุโมทนาบัตรและติดต่อเรื่องการบริจาคของวัดนี้เท่านั้น ไม่เปิดเผยแก่บุคคลภายนอก ที่อยู่และเบอร์โทรเก็บแบบเข้ารหัส ผู้บริจาคถอนความยินยอมได้ทุกเมื่อ เมื่อถอนระบบจะลบข้อมูลส่วนบุคคลออกจากทะเบียน</p>
    <div class="mb-2"><label>ได้รับความยินยอมโดย</label><select name="consent_channel"><?php foreach (DONOR_CHANNELS as $k => $lab): ?><option value="<?= $k ?>"><?= e($lab) ?></option><?php endforeach; ?></select></div>
    <label class="!mb-0 flex items-start gap-2"><input type="checkbox" name="consent" value="1" class="mt-1"><span>ข้าพเจ้ายืนยันว่าผู้บริจาครายนี้ได้รับแจ้งวัตถุประสงค์ข้างต้นและให้ความยินยอมในการเก็บรวบรวมและใช้ข้อมูลส่วนบุคคลแล้ว</span></label>
  </div>
  <?php else: ?><p class="col-span-full text-xs text-stone-500">ยืนยันความยินยอมแล้วเมื่อ <?= e(th_date(substr($edit['consent_at'], 0, 10))) ?></p><?php endif; ?>
  <div class="col-span-full"><button class="btn" type="submit">บันทึก</button></div>
</form>
<?php else: ?>
<div class="flex flex-wrap items-center justify-between gap-2"><h2>ทะเบียนผู้บริจาค<?= $temple ? ' · ' . e($temple['name']) : '' ?></h2>
  <?php if ($temple): ?><a class="btn btn-sm" href="<?= e($url(['new' => 1])) ?>"><?= icon('plus') ?> เพิ่มผู้บริจาค</a><?php endif; ?></div>
<?php if ($u['role'] !== 'temple'): ?>
<form method="get" class="my-4 flex flex-wrap items-end gap-3">
  <div><label>วัด</label><select name="temple" onchange="this.form.submit()"><option value="0">— เลือกวัด —</option>
    <?php foreach ($groups as $dn => $list): ?><optgroup label="<?= e(unit_district()) ?><?= e($dn) ?>"><?php foreach ($list as $t): ?><option value="<?= (int)$t['id'] ?>" <?= $t['id'] == $tid ? 'selected' : '' ?>><?= e($t['name']) ?></option><?php endforeach; ?></optgroup><?php endforeach; ?></select></div>
  <noscript><button class="btn">แสดง</button></noscript>
</form>
<?php endif; ?>
<?php if (!$temple): ?>
  <p class="text-stone-600">เลือกวัดเพื่อดูทะเบียนผู้บริจาค</p>
<?php else: ?>
<p class="mb-4 text-sm text-stone-600">ทะเบียนนี้ไม่บังคับ — เก็บเฉพาะผู้บริจาคที่ให้ความยินยอม เพื่อค้นหาและเติมชื่อ-ที่อยู่ในอนุโมทนาบัตรอัตโนมัติ ที่อยู่และเบอร์โทรเก็บแบบเข้ารหัส และผู้บริจาคถอนความยินยอมได้ทุกเมื่อ</p>
<form method="get" class="mb-4 flex flex-wrap items-end gap-3">
  <?php foreach ($tq as $k => $val): ?><input type="hidden" name="<?= $k ?>" value="<?= (int)$val ?>"><?php endforeach; ?>
  <div><label>ค้นหาชื่อ</label><input name="q" value="<?= e($q) ?>"></div><button class="btn" type="submit">ค้นหา</button>
</form>
<div class="card-table"><table class="data-table">
  <tr><th>ชื่อ</th><th>เบอร์โทร</th><th class="text-right">ใบที่ออก</th><th class="text-right">ยอดรวม</th><th>ความยินยอม</th></tr>
  <?php foreach ($donors as $d): ?>
  <tr class="<?= $d['withdrawn_at'] ? 'opacity-60' : '' ?>"><td><a class="underline" href="<?= e($url(['donor' => (int)$d['id']])) ?>"><?= e($d['name']) ?></a></td><td><?= e($d['phone'] ?: '-') ?></td>
    <td class="text-right"><?= (int)$d['n'] ?></td><td class="text-right"><?= money($d['total']) ?></td>
    <td class="text-sm"><?= $d['withdrawn_at'] ? '<span class="badge badge-mute">ถอนแล้ว</span>' : e(th_date(substr($d['consent_at'], 0, 10))) ?></td></tr>
  <?php endforeach; ?>
  <?php if (!$donors): ?><tr><td colspan="5" class="text-stone-500">ยังไม่มีผู้บริจาคในทะเบียน<?= $q !== '' ? ' (ตามคำค้น)' : '' ?></td></tr><?php endif; ?>
</table></div>
<?php endif; ?>
<?php endif; ?>
<?php require __DIR__ . '/includes/footer.php'; ?>
