<?php
require __DIR__ . '/../includes/bootstrap.php';
require_admin();
$pdo = db();
$result = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $f = $_FILES['csv'] ?? null;
    if (!$f || $f['error'] !== UPLOAD_ERR_OK || $f['size'] > 2 * 1024 * 1024) {
        flash('อัปโหลดไฟล์ไม่สำเร็จ (ขนาดไม่เกิน 2 MB)', 'err');
        redirect('import.php');
    }
    $verified = isset($_POST['verified']) ? 1 : 0;
    $dmap = [];
    foreach ($pdo->query('SELECT id, name FROM districts') as $d) $dmap[$d['name']] = (int)$d['id'];

    $h = fopen($f['tmp_name'], 'r');
    $first = fread($h, 3);
    if ($first !== "\xEF\xBB\xBF") rewind($h);
    $ins = $pdo->prepare('INSERT INTO temples (district_id,name,subdistrict,moo,abbot,phone,verified,source) VALUES (?,?,?,?,?,?,?,?)
        ON DUPLICATE KEY UPDATE subdistrict=COALESCE(VALUES(subdistrict),subdistrict), moo=COALESCE(VALUES(moo),moo),
        abbot=COALESCE(VALUES(abbot),abbot), phone=COALESCE(VALUES(phone),phone), verified=GREATEST(verified, VALUES(verified))');
    $ok = 0; $bad = [];
    $line = 0;
    while (($r = fgetcsv($h)) !== false) {
        $line++;
        if ($line === 1 && in_array(trim($r[0] ?? ''), ['อำเภอ', 'district'], true)) continue; // ข้ามหัวตาราง
        $dn = preg_replace('/^อำเภอ|^อ\./u', '', trim($r[0] ?? ''));
        $name = trim($r[1] ?? '');
        if ($name === '' || !isset($dmap[$dn])) { $bad[] = "บรรทัด $line: " . implode(',', $r); continue; }
        $n = fn($i) => (isset($r[$i]) && trim($r[$i]) !== '') ? trim($r[$i]) : null;
        $ins->execute([$dmap[$dn], $name, $n(2), $n(3), $n(4), $n(5), $verified, 'นำเข้า CSV']);
        $ok++;
    }
    fclose($h);
    audit('import', 'temple', null, "ok=$ok bad=" . count($bad));
    $result = ['ok' => $ok, 'bad' => $bad];
}

$pageTitle = 'นำเข้ารายชื่อวัด';
require __DIR__ . '/../includes/header.php';
?>
<h2>นำเข้ารายชื่อวัด (CSV)</h2>
<?php if ($result): ?>
  <div class="alert alert-ok">นำเข้า/อัปเดตสำเร็จ <?= $result['ok'] ?> วัด</div>
  <?php if ($result['bad']): ?><div class="alert alert-err">ข้ามรายการที่ไม่ถูกต้อง <?= count($result['bad']) ?> บรรทัด:<br><?= e(implode(' | ', array_slice($result['bad'], 0, 20))) ?></div><?php endif; ?>
<?php endif; ?>
<div class="card">
  <p>ไฟล์ CSV (UTF-8) คอลัมน์ตามลำดับ: <code>อำเภอ, ชื่อวัด, ตำบล, หมู่ที่, เจ้าอาวาส, โทรศัพท์</code><br>
  ชื่ออำเภอต้องเป็นหนึ่งใน 8 อำเภอของจังหวัดแพร่ (เมืองแพร่, ร้องกวาง, ลอง, สูงเม่น, เด่นชัย, สอง, วังชิ้น, หนองม่วงไข่)
  วัดที่ชื่อซ้ำในอำเภอเดียวกันจะถูกอัปเดตแทนการเพิ่มใหม่ ดูตัวอย่างที่ <code>data/temples_template.csv</code></p>
  <form method="post" enctype="multipart/form-data" class="form-grid">
    <?= csrf_field() ?>
    <div class="full"><input type="file" name="csv" accept=".csv,text/csv" required></div>
    <div class="full"><label><input type="checkbox" name="verified" style="width:auto"> ไฟล์นี้มาจากทะเบียนวัดอย่างเป็นทางการ (ทำเครื่องหมาย "ตรวจสอบแล้ว")</label></div>
    <div class="full"><button class="btn">นำเข้า</button></div>
  </form>
</div>
<?php require __DIR__ . '/../includes/footer.php'; ?>
