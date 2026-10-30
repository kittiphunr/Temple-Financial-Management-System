<?php
// แปลงรูปที่เก็บไว้เดิม (โลโก้ รูปบุคลากร/ทรัพย์สิน รูปหลักฐานแนบ) เป็น WebP ให้เล็กลง:  php convert_images.php [--apply]
// ไม่ใส่ --apply = ทดลองรันเฉยๆ แสดงว่าจะประหยัดได้เท่าไร ไม่แก้ฐานข้อมูล · ควรสำรองฐานข้อมูลก่อนรันจริง
// ใช้ค่าฐานข้อมูลจาก config.php / config.local.php / ตัวแปรสภาพแวดล้อม TFMS_DB_*
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
require __DIR__ . '/includes/bootstrap.php';
if (!image_webp_supported()) {
    fwrite(STDERR, "เซิร์ฟเวอร์นี้ไม่รองรับการเข้ารหัส WebP ใน GD (imagewebp) จึงแปลงไม่ได้\n");
    exit(1);
}
$apply = in_array('--apply', $argv, true);
$pdo = db();

$targets = [['media', 'id', ['logo' => 'logo', 'photo' => 'photo'], 'kind']];
{
    try {
        $pdo->query('SELECT 1 FROM transaction_attachments LIMIT 1');
        $targets[] = ['transaction_attachments', 'id', null, null];
    } catch (PDOException) {
    }
}
$total = ['n' => 0, 'before' => 0, 'after' => 0, 'skip' => 0];
foreach ($targets as [$table, $pk, $kindMap, $kindCol]) {
    $ids = $pdo->query("SELECT $pk FROM $table WHERE mime IN ('image/jpeg','image/png')")->fetchAll(PDO::FETCH_COLUMN);
    foreach ($ids as $id) {
        $st = $pdo->prepare("SELECT * FROM $table WHERE $pk = ?");
        $st->execute([$id]);
        $r = $st->fetch();
        $profile = $kindMap ? ($kindMap[$r[$kindCol]] ?? 'photo') : 'evidence';
        [$data, $mime, $err] = image_process((string)$r['data'], $profile);
        if ($data === null || strlen($data) >= strlen((string)$r['data'])) {   // แปลงไม่ได้หรือไม่เล็กลง = คงของเดิม
            $total['skip']++;
            continue;
        }
        $total['n']++;
        $total['before'] += strlen((string)$r['data']);
        $total['after'] += strlen($data);
        if ($apply) {
            $fn = isset($r['filename']) ? ', filename = ?' : '';
            $up = $pdo->prepare("UPDATE $table SET mime = ?, size = ?, data = ?$fn WHERE $pk = ?");
            $up->bindValue(1, $mime);
            $up->bindValue(2, strlen($data), PDO::PARAM_INT);
            $up->bindValue(3, $data, PDO::PARAM_LOB);
            $i = 4;
            if ($fn) {
                $up->bindValue($i++, preg_replace('/\.(jpe?g|png)$/i', '', (string)$r['filename']) . '.webp');
            }
            $up->bindValue($i, (int)$id, PDO::PARAM_INT);
            $up->execute();
        }
    }
}
printf("%s: %d รูป เล็กลงจาก %s เป็น %s KB (ข้าม %d รูปที่แปลงแล้วไม่เล็กลง)\n", $apply ? 'แปลงแล้ว' : 'ทดลองรัน', $total['n'], number_format($total['before'] / 1024, 1), number_format($total['after'] / 1024, 1), $total['skip']);
if (!$apply && $total['n']) {
    echo "รันจริงด้วย: php convert_images.php --apply\n";
}
