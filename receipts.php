<?php
require __DIR__ . '/includes/bootstrap.php';
$u = require_login();
if (in_array($u['role'], ['province', 'subdistrict'], true)) {
    redirect('dashboard.php');
}
$pdo = db();
$year = (int)($_GET['year'] ?? be_year());
$fTemple = (int)($_GET['temple'] ?? 0);
[$sc, $sp] = scope_sql('t');
$sql = 'SELECT r.*, t.name AS temple, d.name AS district FROM donation_receipts r JOIN temples t ON t.id = r.temple_id
    JOIN districts d ON d.id = t.district_id WHERE r.receipt_year = ?' . $sc;
$p = array_merge([$year], $sp);
if ($fTemple) { $sql .= ' AND t.id = ?'; $p[] = $fTemple; }
$st = $pdo->prepare($sql . ' ORDER BY r.temple_id, r.receipt_no DESC LIMIT 500');
$st->execute($p);
$rows = $st->fetchAll();
$groups = temples_grouped();
$pageTitle = 'อนุโมทนาบัตร';
require __DIR__ . '/includes/header.php';
?>
<h2>อนุโมทนาบัตร</h2>
<p class="text-sm text-stone-600">ออกจากบัญชีรายรับที่อยู่ในหมวดรับบริจาค: ไปที่ <a href="transactions.php?type=income">บัญชีรายรับ</a> แล้วกดปุ่ม "อนุโมทนาบัตร" ที่รายการนั้น</p>
<form class="my-4 flex flex-wrap items-end gap-3" method="get">
  <div><label>ปี พ.ศ.</label><select name="year"><?php for ($y = be_year() + 1; $y >= be_year() - 6; $y--): ?><option <?= $y === $year ? 'selected' : '' ?>><?= $y ?></option><?php endfor; ?></select></div>
  <?php if ($u['role'] !== 'temple'): ?>
  <div><label>วัด</label><select name="temple"><option value="0">ทุกวัด</option>
    <?php foreach ($groups as $dn => $list): ?><optgroup label="<?= e(unit_district()) ?><?= e($dn) ?>"><?php foreach ($list as $t): ?><option value="<?= (int)$t['id'] ?>" <?= $t['id'] == $fTemple ? 'selected' : '' ?>><?= e($t['name']) ?></option><?php endforeach; ?></optgroup><?php endforeach; ?>
  </select></div>
  <?php endif; ?>
  <button class="btn">แสดง</button>
</form>
<div class="card-table"><table class="data-table">
  <tr><th>เลขที่</th><th>วันที่ออก</th><th>วัด</th><th>ผู้บริจาค</th><th class="text-right">จำนวนเงิน</th><th>สถานะ</th><th></th></tr>
  <?php foreach ($rows as $r): ?>
  <tr><td class="whitespace-nowrap"><?= e(receipt_label($r)) ?></td><td><?= e(th_date($r['receipt_date'])) ?></td>
    <td><?= e($r['temple']) ?><br><small class="text-stone-500">อ.<?= e($r['district']) ?></small></td><td><?= e($r['donor_name']) ?></td>
    <td class="text-right whitespace-nowrap"><?= money($r['amount']) ?></td><td><?= $r['status'] === 'void' ? '<span class="text-red-700">ยกเลิก</span>' : 'ใช้ได้' ?></td>
    <td><a class="btn btn-sm btn-outline" href="receipt.php?id=<?= (int)$r['id'] ?>">เปิด/พิมพ์</a></td></tr>
  <?php endforeach; ?>
  <?php if (!$rows): ?><tr><td colspan="7" class="text-stone-500">ยังไม่มีอนุโมทนาบัตรในปีที่เลือก</td></tr><?php endif; ?>
</table></div>
<?php require __DIR__ . '/includes/footer.php'; ?>
