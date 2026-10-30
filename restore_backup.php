<?php
// กู้คืนข้อมูลจากไฟล์สำรอง:  php restore_backup.php storage/backups/tfms-....sql.gz --yes [--key=ไฟล์กุญแจ.json]
// คำเตือน: คำสั่งนี้ลบและสร้างตารางทั้งหมดในฐานข้อมูลที่ตั้งค่าไว้ (config.php / config.local.php / TFMS_DB_*) ใหม่ตามไฟล์สำรอง จึงต้องใส่ --yes
// --key=ไฟล์ที่ส่งออกจากหน้าสำรองข้อมูล จะถอดรหัสด้วยรหัสผ่านสำรอง (ตัวแปร TFMS_KEY_PASSPHRASE หรือพิมพ์ตอนถาม) แล้วเขียน storage/app.key
//          (ไม่เขียนทับถ้ามีอยู่แล้ว) — จำเป็นเมื่อกู้คืนลงเซิร์ฟเวอร์ใหม่ ไม่เช่นนั้นเลขบัตรและข้อมูลที่เข้ารหัสจะถอดรหัสไม่ได้
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
// ไม่โหลด bootstrap.php เพราะบนเซิร์ฟเวอร์ใหม่ที่ยังไม่ได้ติดตั้ง bootstrap จะพาไปตัวช่วยติดตั้ง — ใช้ตัวเชื่อมต่อของตัวติดตั้งแทน
$cfg = require __DIR__ . '/config.php';
require __DIR__ . '/includes/install_lib.php';
require __DIR__ . '/includes/backup.php';
$file = null;
$key = null;
foreach (array_slice($argv, 1) as $a) {
    if (str_starts_with($a, '--key=')) {
        $key = substr($a, 6);
    } elseif ($a !== '--yes') {
        $file = $a;
    }
}
if (!$file || !is_file($file)) {
    fwrite(STDERR, "ใช้: php restore_backup.php ไฟล์.sql.gz --yes [--key=ไฟล์กุญแจ.json]\n");
    exit(2);
}
if (!in_array('--yes', $argv, true)) {
    fwrite(STDERR, "คำสั่งนี้จะลบและสร้างตารางทั้งหมดในฐานข้อมูลใหม่ตามไฟล์สำรอง — ยืนยันด้วย --yes\n");
    exit(2);
}
if ($key) {
    $keyFile = __DIR__ . '/storage/app.key';
    if (!is_file($key)) {
        fwrite(STDERR, "ไม่พบไฟล์กุญแจ $key\n");
        exit(2);
    }
    $pass = getenv('TFMS_KEY_PASSPHRASE') ?: (function () {
        fwrite(STDERR, 'รหัสผ่านสำรองของกุญแจ: ');
        return rtrim((string)fgets(STDIN), "\r\n");
    })();
    $raw = backup_key_import((string)file_get_contents($key), $pass);
    if ($raw === null) {
        fwrite(STDERR, "รหัสผ่านสำรองไม่ถูกต้องหรือไฟล์กุญแจเสียหาย\n");
        exit(1);
    }
    if (is_file($keyFile)) {
        fwrite(STDERR, "มี storage/app.key อยู่แล้ว ไม่เขียนทับ (ลบไฟล์เดิมเองถ้าต้องการใช้กุญแจจากไฟล์สำรอง)\n");
    } else {
        file_put_contents($keyFile, base64_encode($raw));
        @chmod($keyFile, 0600);
        echo "เขียน storage/app.key จากไฟล์กุญแจแล้ว\n";
    }
}
try {
    if (!preg_match('/^[A-Za-z0-9_]{1,64}$/', (string)$cfg['db_name'])) {
        throw new RuntimeException('ชื่อฐานข้อมูลไม่ถูกต้อง (ใช้ได้เฉพาะ a-z A-Z 0-9 _)');
    }
    install_pdo($cfg, false)->exec('CREATE DATABASE IF NOT EXISTS `' . $cfg['db_name'] . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
    $n = backup_restore(install_pdo($cfg), $file);
    mark_installed();   // กู้คืนลงเซิร์ฟเวอร์ใหม่แล้ว ไม่ต้องผ่านตัวช่วยติดตั้งอีก
} catch (Throwable $e) {
    fwrite(STDERR, 'กู้คืนไม่สำเร็จ: ' . $e->getMessage() . "\n");
    exit(1);
}
echo "กู้คืนสำเร็จ: รัน $n คำสั่ง\n";
