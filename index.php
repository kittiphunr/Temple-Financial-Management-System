<?php
require __DIR__ . '/includes/bootstrap.php';
require __DIR__ . '/includes/stats.php';

$u = user();
$year = (int)($_GET['year'] ?? be_year());
$pageTitle = 'หน้าแรก';
require __DIR__ . '/includes/header.php';
?>
<section class="hero">
  <img class="logo" src="<?= BASE ?>assets/img/logo.svg" alt="ตราสัญลักษณ์">
  <h1><?= e($CONFIG['app_name']) ?></h1>
  <p><?= e($CONFIG['app_sub']) ?></p>
  <div class="pill"><span>จัดเก็บข้อมูลบัญชี</span><span>·</span><span>ตรวจสอบได้</span><span>·</span><span>พิมพ์และส่งออกเอกสารได้</span></div>
  <div class="actions">
    <a class="btn" href="<?= $u ? 'transactions.php?type=income' : 'login.php' ?>">เริ่มใช้งาน</a>
    <a class="btn btn-outline" href="<?= $u ? 'reports.php' : 'login.php' ?>">ดูรายงาน</a>
  </div>
</section>

<div class="grid grid-4">
  <a class="card feature" href="<?= $u ? 'transactions.php?type=income' : 'login.php' ?>"><div class="ttl">จัดการบัญชีรายรับ</div>บันทึกรายรับจากการบริจาค การทำบุญ และรายได้อื่น ๆ ของวัด</a>
  <a class="card feature" href="<?= $u ? 'transactions.php?type=expense' : 'login.php' ?>"><div class="ttl">จัดการบัญชีรายจ่าย</div>บันทึกค่าบูรณปฏิสังขรณ์ ค่าสาธารณูปโภค และค่าใช้จ่ายอื่น ๆ</a>
  <a class="card feature" href="<?= $u ? 'reports.php' : 'login.php' ?>"><div class="ttl">ดูรายงานและสรุปผล</div>สรุปรายรับ-รายจ่าย รายเดือน รายปี แยกตามอำเภอและวัด</a>
  <a class="card feature" href="<?= $u ? 'reports.php?export=csv' : 'login.php' ?>"><div class="ttl">พิมพ์และส่งออกเอกสาร</div>พิมพ์รายงาน และส่งออกไฟล์ CSV (เปิดด้วย Excel ได้)</a>
</div>

<?php if ($u):
    $sum = summary($year);
    $rows = summary_by_district($year);
    $max = 1;
    foreach ($rows as $r) { $max = max($max, $r['income'], $r['expense']); }
?>
<form class="toolbar" method="get">
  <div><label>ปี พ.ศ.</label>
    <select name="year" onchange="this.form.submit()">
      <?php for ($y = be_year() + 1; $y >= be_year() - 6; $y--): ?>
        <option value="<?= $y ?>" <?= $y === $year ? 'selected' : '' ?>><?= $y ?></option>
      <?php endfor; ?>
    </select></div>
</form>
<h2>ภาพรวมบัญชีวัด (ปี พ.ศ. <?= $year ?>)</h2>
<div class="grid grid-4">
  <div class="card stat"><div class="label">รายรับรวม</div><div class="num inc"><?= money($sum['income']) ?> บาท</div><div class="muted">รายการทั้งหมด <?= $sum['n_income'] ?> รายการ</div></div>
  <div class="card stat"><div class="label">รายจ่ายรวม</div><div class="num exp"><?= money($sum['expense']) ?> บาท</div><div class="muted">รายการทั้งหมด <?= $sum['n_expense'] ?> รายการ</div></div>
  <div class="card stat"><div class="label">ยอดคงเหลือ</div><div class="num bal"><?= money($sum['balance']) ?> บาท</div><div class="muted">รายรับ − รายจ่าย</div></div>
  <div class="card stat"><div class="label">จำนวนวัดในระบบ</div><div class="num"><?= temple_count() ?> วัด</div><div class="muted">ในสังกัดจังหวัดแพร่</div></div>
</div>

<h2>สรุปตามอำเภอ</h2>
<div class="table-wrap"><table>
  <tr><th>อำเภอ</th><th class="r">วัด</th><th>รายรับ</th><th>รายจ่าย</th><th class="r">คงเหลือ</th></tr>
  <?php foreach ($rows as $r): ?>
  <tr>
    <td><a href="reports.php?year=<?= $year ?>&district=<?= (int)$r['id'] ?>"><?= e($r['name']) ?></a></td>
    <td class="r"><?= (int)$r['temples'] ?></td>
    <td><div class="bar"><i style="width:<?= round($r['income'] / $max * 100) ?>%"></i></div><span class="inc"><?= money($r['income']) ?></span></td>
    <td><div class="bar e"><i style="width:<?= round($r['expense'] / $max * 100) ?>%"></i></div><span class="exp"><?= money($r['expense']) ?></span></td>
    <td class="r bal"><?= money($r['income'] - $r['expense']) ?></td>
  </tr>
  <?php endforeach; ?>
</table></div>
<?php else: ?>
<p class="muted" style="margin-top:20px">กรุณาเข้าสู่ระบบเพื่อดูข้อมูลบัญชี</p>
<?php endif; ?>
<?php require __DIR__ . '/includes/footer.php'; ?>
