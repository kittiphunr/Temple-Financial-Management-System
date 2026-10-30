<?php
// ติดตั้งระบบ (รันจากบรรทัดคำสั่งเท่านั้น):  php setup.php [รหัสผ่านผู้ดูแล]
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
$cfg = require __DIR__ . '/config.php';
$pw = $argv[1] ?? bin2hex(random_bytes(6));
if (strlen($pw) < 8) {
    fwrite(STDERR, "รหัสผ่านต้องมีอย่างน้อย 8 ตัวอักษร\n");
    exit(1);
}

$pdo = new PDO(sprintf('mysql:host=%s;port=%s;charset=utf8mb4', $cfg['db_host'], $cfg['db_port']),
    $cfg['db_user'], $cfg['db_pass'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);

foreach (['schema.sql', 'seed.sql'] as $file) {
    $sql = file_get_contents(__DIR__ . '/sql/' . $file);
    $sql = preg_replace('/^\s*--.*$/m', '', $sql); // ตัดคอมเมนต์
    foreach (array_filter(array_map('trim', explode(';', $sql))) as $stmt) {
        $pdo->exec($stmt);
    }
    echo "นำเข้า $file แล้ว\n";
}

$pdo->exec('USE `' . $cfg['db_name'] . '`');

require __DIR__ . '/includes/temple_import.php';
$res = import_temples_csv($pdo, __DIR__ . '/data/temples_phrae.csv', true, 'ทะเบียนวัด พศ. (Report_Temple.xlsx)');
echo "นำเข้าทะเบียนวัด {$res['ok']} วัด" . ($res['bad'] ? ' (ข้าม ' . count($res['bad']) . ' บรรทัด)' : '') . "\n";

require __DIR__ . '/includes/mailer.php';
require __DIR__ . '/includes/accounts.php';
$created = provision_temple_accounts($pdo);
echo 'สร้างบัญชีวัดอัตโนมัติ ' . count($created) . " บัญชี (1 วัด = 1 บัญชี)\n";
if ($created) {
    $dir = __DIR__ . '/storage';
    is_dir($dir) || mkdir($dir, 0750, true);
    $file = $dir . '/temple_accounts_' . date('Ymd_His') . '.csv';
    $fh = fopen($file, 'w');
    write_credentials_csv($fh, $created);
    fclose($fh);
    chmod($file, 0600);
    echo "บันทึกชื่อผู้ใช้/รหัสผ่านเริ่มต้นไว้ที่ $file\n";
    echo "→ นำไปแจกจ่ายให้วัด แล้วย้ายหรือลบไฟล์นี้ออกจากเซิร์ฟเวอร์ (ในฐานข้อมูลเก็บเฉพาะค่าแฮช)\n";
}

$st = $pdo->prepare("INSERT INTO users (username, password_hash, full_name, role) VALUES ('admin', ?, 'ผู้ดูแลระบบ', 'admin')
    ON DUPLICATE KEY UPDATE password_hash = VALUES(password_hash)");
$st->execute([password_hash($pw, PASSWORD_DEFAULT)]);
echo "สร้างผู้ใช้ admin แล้ว รหัสผ่าน: $pw\n(กรุณาเปลี่ยนรหัสผ่านหลังเข้าสู่ระบบ)\n";
