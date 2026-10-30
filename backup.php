<?php
// สำรองข้อมูลจากบรรทัดคำสั่ง (ใช้กับ cron):  php backup.php [--keep=14]
// สร้างไฟล์ storage/backups/tfms-วันที่-เวลา.sql.gz แล้วลบไฟล์เก่า เหลือ --keep ไฟล์ล่าสุด (ค่าเริ่มต้น 14)
// ตัวอย่าง cron ทุกวัน 02:00:  0 2 * * * cd /path/to/tfms && php backup.php --keep=14
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
require __DIR__ . '/includes/bootstrap.php';
$keep = 14;
foreach ($argv as $a) {
    if (preg_match('/^--keep=(\d{1,3})$/', $a, $m)) {
        $keep = max(1, (int)$m[1]);
    }
}
try {
    $r = backup_create(db());
    $pruned = backup_prune($keep);
    setting_set('backup_last', (string)time());
} catch (Throwable $e) {
    fwrite(STDERR, 'สำรองข้อมูลไม่สำเร็จ: ' . $e->getMessage() . "\n");
    exit(1);
}
printf("สำเร็จ: storage/backups/%s (%s KB, %d ตาราง, %d แถว) ลบไฟล์เก่า %d ไฟล์\n", $r['name'], number_format($r['size'] / 1024, 1), $r['tables'], $r['rows'], $pruned);
