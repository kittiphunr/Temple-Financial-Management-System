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
$st = $pdo->prepare("INSERT INTO users (username, password_hash, full_name, role) VALUES ('admin', ?, 'ผู้ดูแลระบบ', 'admin')
    ON DUPLICATE KEY UPDATE password_hash = VALUES(password_hash)");
$st->execute([password_hash($pw, PASSWORD_DEFAULT)]);
echo "สร้างผู้ใช้ admin แล้ว รหัสผ่าน: $pw\n(กรุณาเปลี่ยนรหัสผ่านหลังเข้าสู่ระบบ)\n";
