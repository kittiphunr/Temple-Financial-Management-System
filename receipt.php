<?php
require __DIR__ . '/includes/bootstrap.php';
$u = require_login();
$pdo = db();

/** อนุโมทนาบัตรที่ผู้ใช้เปิดดูได้ (ตามขอบเขตวัด) */
function receipt_load(PDO $pdo, int $id): ?array
{
    $st = $pdo->prepare('SELECT r.*, c.name AS category, x.txn_date, x.description FROM donation_receipts r
        JOIN transactions x ON x.id = r.transaction_id JOIN categories c ON c.id = x.category_id WHERE r.id = ?');
    $st->execute([$id]);
    $r = $st->fetch();
    return $r && can_access_temple((int)$r['temple_id']) ? $r : null;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $act = $_POST['action'] ?? '';
    if ($act === 'issue') {
        $txn = (int)($_POST['txn'] ?? 0);
        $st = $pdo->prepare('SELECT temple_id FROM transactions WHERE id = ? AND is_deleted = 0');
        $st->execute([$txn]);
        $tid = (int)$st->fetchColumn();
        if (!$tid || !people_can_edit($tid)) {
            http_response_code(403);
            exit('ไม่มีสิทธิ์ออกอนุโมทนาบัตรของวัดนี้');
        }
        [$rid, $err] = receipt_issue($pdo, $txn, $_POST, (int)$u['id']);
        if ($rid) {
            audit('issue_receipt', 'donation_receipt', $rid);
            flash('ออกอนุโมทนาบัตรแล้ว');
            redirect('receipt.php?id=' . $rid);
        }
        flash($err, 'err');
        redirect('receipt.php?txn=' . $txn);
    }
    if ($act === 'void') {
        $r = receipt_load($pdo, (int)($_POST['id'] ?? 0));
        $reason = mb_substr(trim($_POST['reason'] ?? ''), 0, 255);
        if (!$r || !people_can_edit((int)$r['temple_id'])) {
            http_response_code(403);
            exit('ไม่มีสิทธิ์');
        }
        if ($reason === '') {
            flash('กรุณาระบุเหตุผลที่ยกเลิก', 'err');
        } elseif ($r['status'] === 'issued') {
            $pdo->prepare("UPDATE donation_receipts SET status='void', void_reason=?, voided_at=NOW() WHERE id=?")->execute([$reason, $r['id']]);
            audit('void_receipt', 'donation_receipt', (int)$r['id'], $reason);
            flash('ยกเลิกอนุโมทนาบัตรแล้ว (เลขที่นี้ไม่ถูกนำกลับมาใช้)');
        }
        redirect('receipt.php?id=' . (int)$r['id']);
    }
    redirect('receipts.php');
}

// ---------- แบบฟอร์มออกอนุโมทนาบัตรจากรายการรายรับ ----------
if (isset($_GET['txn'])) {
    $txnId = (int)$_GET['txn'];
    $st = $pdo->prepare("SELECT x.*, c.name AS category, c.is_donation FROM transactions x JOIN categories c ON c.id = x.category_id
        WHERE x.id = ? AND x.is_deleted = 0 AND x.type = 'income'");
    $st->execute([$txnId]);
    $x = $st->fetch();
    if (!$x || !can_access_temple((int)$x['temple_id'])) {
        http_response_code(404);
        exit('ไม่พบรายการ');
    }
    if ($act = receipt_active_for($pdo, $txnId)) {
        redirect('receipt.php?id=' . $act['id']);
    }
    $tid = (int)$x['temple_id'];
    $canIssue = people_can_edit($tid) && $x['is_donation'];
    $t = temple_row($tid);
    $last = $pdo->prepare('SELECT book_no FROM donation_receipts WHERE temple_id = ? ORDER BY id DESC LIMIT 1');
    $last->execute([$tid]);
    $book = (string)($last->fetchColumn() ?: '1');
    $ppl = $pdo->prepare("SELECT position, title, first_name, last_name FROM temple_people WHERE temple_id = ? AND is_active = 1 AND position IN ('steward','abbot') ORDER BY position = 'steward' DESC, id LIMIT 1");
    $ppl->execute([$tid]);
    $rc = $ppl->fetch();
    $pageTitle = 'ออกอนุโมทนาบัตร';
    require __DIR__ . '/includes/header.php';
    ?>
<h2>ออกอนุโมทนาบัตร</h2>
<div class="mb-4 rounded-2xl border border-stone-200 bg-white p-4 shadow-sm">
  <div class="text-sm text-stone-600"><?= e($t['name']) ?> · <?= e(th_date($x['txn_date'])) ?> · <?= e($x['category']) ?></div>
  <div class="text-2xl font-bold text-emerald-700"><?= money($x['amount']) ?> บาท</div>
  <div class="text-sm text-stone-600">(<?= e(thai_baht_text($x['amount'])) ?>)</div>
  <?php if (!$x['is_donation']): ?><p class="mt-2 text-red-700">หมวด "<?= e($x['category']) ?>" ไม่ใช่หมวดรับบริจาค จึงออกอนุโมทนาบัตรไม่ได้ (แอดมินหลักกำหนดหมวดรับบริจาคได้ที่ จัดการระบบ → หมวดบัญชี)</p><?php endif; ?>
  <?php if (!$t['tax_id'] && $canIssue): ?><p class="mt-2 rounded-lg border border-amber-300 bg-amber-50 px-3 py-2 text-sm text-amber-950">วัดยังไม่ได้กรอกเลขประจำตัวผู้เสียภาษี — ผู้บริจาคอาจนำไปลดหย่อนภาษีไม่ได้ กรอกได้ที่ <a class="underline" href="<?= BASE ?>profile.php<?= $u['role'] === 'admin' ? '?temple=' . $tid : '' ?>">ข้อมูลวัด</a></p><?php endif; ?>
</div>
<?php if ($canIssue): ?>
<form method="post" class="grid gap-3 rounded-2xl border border-stone-200 bg-white p-4 shadow-sm sm:grid-cols-2 [&_input]:w-full">
  <?= csrf_field() ?><input type="hidden" name="action" value="issue"><input type="hidden" name="txn" value="<?= $txnId ?>">
  <div class="col-span-full"><label>ชื่อผู้บริจาค *</label><input name="donor_name" required maxlength="200"></div>
  <div class="col-span-full"><label>ที่อยู่ผู้บริจาค</label><input name="donor_address" maxlength="500"></div>
  <div class="col-span-full"><label>เพื่อ (วัตถุประสงค์)</label><input name="purpose" maxlength="255" value="<?= e($x['description'] ?? '') ?>"></div>
  <div><label>เล่มที่</label><input name="book_no" maxlength="20" required value="<?= e($book) ?>"></div>
  <div><label>วันที่ออก</label><?= date_be_field('receipt_date', null, 3, 0) ?></div>
  <div><label>ผู้รับเงิน (ชื่อ)</label><input name="receiver_name" maxlength="200" value="<?= e($rc ? person_full_name($rc, false) : '') ?>"></div>
  <div><label>ตำแหน่งผู้รับเงิน</label><input name="receiver_position" maxlength="100" value="<?= e($rc ? PERSON_POSITIONS[$rc['position']] : '') ?>"></div>
  <p class="col-span-full text-sm text-stone-600">เลขที่ออกให้อัตโนมัติเรียงต่อเนื่องในแต่ละปี พ.ศ. · ชื่อเจ้าอาวาสดึงจากข้อมูลบุคลากร · พิมพ์แล้วให้เจ้าอาวาสหรือผู้ที่วัดมอบหมายลงลายมือชื่อและประทับตราวัด</p>
  <div class="col-span-full"><button class="btn">ออกอนุโมทนาบัตร</button> <a class="btn btn-outline" href="transactions.php?type=income">กลับ</a></div>
</form>
<?php elseif ($x['is_donation']): ?><p class="text-stone-600">รายการนี้ยังไม่ได้ออกอนุโมทนาบัตร (เฉพาะแอดมินวัด/แอดมินหลักเท่านั้นที่ออกได้)</p><?php endif;
    require __DIR__ . '/includes/footer.php';
    exit;
}

// ---------- แสดง/พิมพ์อนุโมทนาบัตร ----------
$r = receipt_load($pdo, (int)($_GET['id'] ?? 0));
if (!$r) {
    http_response_code(404);
    exit('ไม่พบอนุโมทนาบัตร');
}
$tid = (int)$r['temple_id'];
$t = temple_row($tid);
$void = $r['status'] === 'void';
$pageTitle = 'อนุโมทนาบัตร ' . receipt_label($r);
// ฟอนต์ Charmonman (Google Fonts) เฉพาะตัวเอกสารอนุโมทนาบัตร
$extraHead = '<link href="https://fonts.googleapis.com/css2?family=Charmonman:wght@400;700&display=swap" rel="stylesheet">';
require __DIR__ . '/includes/header.php';
?>
<div class="mb-3 flex flex-wrap items-center gap-2 print:hidden">
  <h2 class="mr-auto">อนุโมทนาบัตร <?= e(receipt_label($r)) ?><?= $void ? ' <span class="badge">ยกเลิกแล้ว</span>' : '' ?></h2>
  <button class="btn" type="button" onclick="window.print()">พิมพ์</button>
  <a class="btn btn-outline" href="receipts.php?temple=<?= $tid ?>">รายการทั้งหมด</a>
</div>

<?php ob_start(); ?>
<article class="receipt-font relative mx-auto max-w-3xl rounded-2xl border-2 border-gold-500 bg-white p-8 shadow-sm print:max-w-none print:rounded-none print:border-0 print:p-0 print:shadow-none">
  <?php if ($void): ?><div class="pointer-events-none absolute inset-0 flex items-center justify-center text-7xl font-bold text-red-600/20 -rotate-12">ยกเลิก</div><?php endif; ?>
  <?= letterhead($pdo, $tid, 0, 'อนุโมทนาบัตร') ?>
  <div class="flex justify-between text-sm"><div>เล่มที่ <?= e($r['book_no']) ?> เลขที่ <?= (int)$r['receipt_no'] ?>/<?= (int)$r['receipt_year'] ?></div><div>วันที่ <?= e(th_date($r['receipt_date'])) ?></div></div>
  <div class="mt-5 space-y-3 leading-8">
    <p class="indent-10">ขออนุโมทนาบุญแก่ <strong><?= e($r['donor_name']) ?></strong><?= $r['donor_address'] ? ' ที่อยู่ ' . e($r['donor_address']) : '' ?></p>
    <p class="indent-10">ได้บริจาคทรัพย์ (<?= e($r['category']) ?>) จำนวน <strong><?= money($r['amount']) ?> บาท</strong> (<?= e(thai_baht_text($r['amount'])) ?>)
      <?= $r['purpose'] ? ' เพื่อ ' . e($r['purpose']) : ' เพื่อถวาย' . e($t['name']) ?> เมื่อวันที่ <?= e(th_date($r['txn_date'])) ?></p>
    <p class="indent-10">ขออนุโมทนาในกุศลเจตนา ขอให้ผลบุญนี้จงเป็นปัจจัยอำนวยให้ท่านและครอบครัวเจริญด้วยจตุรพิธพรชัย คือ อายุ วรรณะ สุขะ พละ ตลอดกาลนาน เทอญ</p>
  </div>
  <div class="mt-10 grid grid-cols-2 gap-10">
    <?php
    $box = fn(string $role, string $n) => '<div class="text-center"><div class="mb-1 mt-12 border-b border-dotted border-stone-500"></div><div class="text-sm">('
        . ($n !== '' ? e($n) : str_repeat('&hellip;', 10)) . ')</div><div class="text-sm font-semibold">' . e($role) . '</div></div>';
    echo $box($r['receiver_position'] ?: 'ผู้รับเงิน', (string)$r['receiver_name']), $box('เจ้าอาวาส ' . $t['name'], (string)$r['abbot_name']);
    ?>
  </div>
  <div class="mt-6 flex items-end justify-between text-xs text-stone-500">
    <div><?= $t['tax_id'] ? 'เลขประจำตัวผู้เสียภาษีของวัด ' . e($t['tax_id']) : '' ?><br>ต้องมีลายมือชื่อและตราประทับของวัดจึงจะสมบูรณ์</div>
    <div class="flex h-20 w-20 items-center justify-center rounded-full border border-dashed border-stone-400 text-center">ตราประทับ<br>วัด</div>
  </div>
  <?php if ($void): ?><p class="mt-4 rounded-lg bg-red-50 px-3 py-2 text-sm text-red-800">ยกเลิกเมื่อ <?= e(th_date(substr((string)$r['voided_at'], 0, 10))) ?> — <?= e($r['void_reason']) ?></p><?php endif; ?>
</article>
<?= thai_digits_html((string)ob_get_clean()) ?>   <?php /* เอกสารอนุโมทนาบัตรใช้เลขไทยทั้งฉบับ (ส่วนปุ่ม/ฟอร์มรอบนอกยังเป็นเลขอารบิก) */ ?>

<?php if (!$void && people_can_edit($tid)): ?>
<form method="post" class="mx-auto mt-4 flex max-w-3xl flex-wrap items-end gap-2 print:hidden" data-confirm="ยืนยันยกเลิกอนุโมทนาบัตรใบนี้? (เลขที่จะไม่ถูกนำกลับมาใช้)">
  <?= csrf_field() ?><input type="hidden" name="action" value="void"><input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
  <div class="grow"><label>เหตุผลที่ยกเลิก</label><input name="reason" required maxlength="255" class="w-full"></div>
  <button class="btn btn-danger">ยกเลิกอนุโมทนาบัตร</button>
</form>
<?php endif; ?>
<?php require __DIR__ . '/includes/footer.php'; ?>
