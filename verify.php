<?php
/**
 * ตรวจสอบใบอนุโมทนาด้วยรหัสจาก QR code — เปิดสาธารณะ (ไม่ต้องเข้าสู่ระบบ)
 * แสดงเฉพาะข้อมูลที่จำเป็นต่อการยืนยันว่าใบมีจริง: วัด เลขที่ วันที่ จำนวนเงิน สถานะ และชื่อผู้บริจาคแบบปิดบัง
 * ไม่แสดงที่อยู่ผู้บริจาค วัตถุประสงค์ หรือข้อมูลบุคลากร
 */
require __DIR__ . '/includes/bootstrap.php';
header('Cache-Control: no-store');
header('X-Robots-Tag: noindex, nofollow');

$raw = strtoupper(preg_replace('/[^A-Za-z2-7]/', '', (string)($_GET['c'] ?? '')));
$r = null;
if (strlen($raw) === 16) {
    $st = db()->prepare('SELECT r.*, t.name AS temple, t.subdistrict, d.name AS district FROM donation_receipts r
        JOIN temples t ON t.id = r.temple_id JOIN districts d ON d.id = t.district_id WHERE r.verify_code = ?');
    $st->execute([$raw]);
    $r = $st->fetch() ?: null;
}
if (!$r) {
    http_response_code(404);
}
$void = $r && $r['status'] === 'void';
$pageTitle = 'ตรวจสอบใบอนุโมทนา';
$extraHead = '<meta name="robots" content="noindex,nofollow">';
require __DIR__ . '/includes/header.php';
?>
<div class="mx-auto my-8 max-w-xl">
  <h2>ตรวจสอบใบอนุโมทนา</h2>
  <?php if ($r): ?>
  <div class="rounded-2xl border-2 p-5 shadow-sm <?= $void ? 'border-red-300 bg-red-50' : 'border-emerald-300 bg-emerald-50' ?>">
    <div class="text-2xl font-bold <?= $void ? 'text-red-800' : 'text-emerald-800' ?>"><?= $void ? '✗ ใบนี้ถูกยกเลิกแล้ว' : '✓ ตรวจสอบแล้ว: ใบอนุโมทนานี้ออกโดยระบบจริง' ?></div>
    <dl class="mt-4 grid grid-cols-[8rem_1fr] gap-y-2">
      <dt class="text-stone-600">วัด</dt><dd class="font-semibold"><?= e($r['temple']) ?> <span class="font-normal text-stone-600">อ.<?= e($r['district']) ?></span></dd>
      <dt class="text-stone-600">เล่มที่/เลขที่</dt><dd class="font-semibold"><?= e($r['book_no']) ?> / <?= (int)$r['receipt_no'] ?>/<?= (int)$r['receipt_year'] ?></dd>
      <dt class="text-stone-600">วันที่ออกใบ</dt><dd><?= e(th_date($r['receipt_date'])) ?></dd>
      <dt class="text-stone-600">จำนวนเงิน</dt><dd class="font-semibold"><?= money($r['amount']) ?> บาท</dd>
      <dt class="text-stone-600">ผู้บริจาค</dt><dd><?= e(receipt_mask_name((string)$r['donor_name'])) ?></dd>
      <dt class="text-stone-600">สถานะ</dt><dd><?= $void ? '<strong class="text-red-800">ยกเลิกแล้ว เมื่อ ' . e(th_date(substr((string)$r['voided_at'], 0, 10))) . '</strong>' : 'ใช้ได้' ?></dd>
      <dt class="text-stone-600">รหัสตรวจสอบ</dt><dd class="font-mono text-sm"><?= e(receipt_code_format($raw)) ?></dd>
    </dl>
    <p class="mt-4 text-sm text-stone-700">หน้านี้ยืนยันว่ามีใบอนุโมทนาตามข้อมูลข้างต้นอยู่ในระบบของวัด ให้เทียบกับใบที่ถือ (เลขที่ วันที่ จำนวนเงิน) ใบที่สมบูรณ์ต้องมีลายมือชื่อและตราประทับของวัดด้วย</p>
  </div>
  <?php else: ?>
  <div class="rounded-2xl border-2 border-red-300 bg-red-50 p-5 shadow-sm">
    <div class="text-2xl font-bold text-red-800">✗ ไม่พบใบอนุโมทนานี้ในระบบ</div>
    <p class="mt-2 text-stone-700">รหัสไม่ถูกต้องหรือใบไม่ได้ออกโดยระบบนี้ โปรดระวังเอกสารปลอม และติดต่อวัดผู้ออกใบเพื่อตรวจสอบ</p>
  </div>
  <?php endif; ?>
  <form method="get" class="mt-4 flex flex-wrap items-end gap-2">
    <div class="grow"><label for="c">กรอกรหัสตรวจสอบเอง (16 ตัวอักษร)</label><input id="c" name="c" maxlength="25" class="w-full font-mono" placeholder="XXXX-XXXX-XXXX-XXXX"></div>
    <button class="btn">ตรวจสอบ</button>
  </form>
</div>
<?php require __DIR__ . '/includes/footer.php'; ?>
