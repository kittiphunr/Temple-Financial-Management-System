<?php
// ติดตั้งระบบจากบรรทัดคำสั่ง (ทางเลือกแทนตัวช่วยติดตั้ง install.php):  php setup.php [รหัสผ่านแอดมินหลัก] [--no-staff]
// ค่าเริ่มต้นสร้างบัญชีวัดทุกวัด + เจ้าคณะจังหวัด + แอดมินอำเภอ + เจ้าคณะตำบล พร้อมรหัสผ่านเริ่มต้นสุ่ม (--no-staff = ข้ามกลุ่มจังหวัด/อำเภอ/ตำบล)
// ใช้ค่าฐานข้อมูลจาก config.php / config.local.php / ตัวแปรสภาพแวดล้อม TFMS_DB_*
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
$cfg = require __DIR__ . '/config.php';
require __DIR__ . '/includes/install_lib.php';
require __DIR__ . '/includes/mailer.php';
require __DIR__ . '/includes/accounts.php';
require __DIR__ . '/includes/temple_import.php';
require __DIR__ . '/includes/crypto.php';
require __DIR__ . '/includes/staff.php';
require __DIR__ . '/includes/subdistricts.php';
require __DIR__ . '/includes/staff_provision.php';
$CONFIG = $cfg;   // crypto.php อ่านค่ากุญแจจากตัวแปร $CONFIG

$noStaff = in_array('--no-staff', $argv, true);   // ไม่สร้างผู้ใช้ระดับจังหวัด/อำเภอ/ตำบลอัตโนมัติ
$args = array_values(array_filter(array_slice($argv, 1), fn($a) => $a !== '--no-staff'));
$pw = $args[0] ?? bin2hex(random_bytes(6));
if (strlen($pw) < 8) {
    fwrite(STDERR, "รหัสผ่านต้องมีอย่างน้อย 8 ตัวอักษร\n");
    exit(1);
}
if (!preg_match('/^[A-Za-z0-9_]{1,64}$/', $cfg['db_name'])) {
    fwrite(STDERR, "ชื่อฐานข้อมูลไม่ถูกต้อง (ใช้ได้เฉพาะ a-z A-Z 0-9 _)\n");
    exit(1);
}

install_pdo($cfg, false)->exec('CREATE DATABASE IF NOT EXISTS `' . $cfg['db_name'] . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
$pdo = install_pdo($cfg);

foreach (['schema.sql', 'seed.sql'] as $file) {
    install_run_sql($pdo, __DIR__ . '/sql/' . $file);
    echo "นำเข้า $file แล้ว\n";
}

$res = import_temples_csv($pdo, __DIR__ . '/data/temples_phrae.csv', true, 'ทะเบียนวัด พศ. (Report_Temple.xlsx)');
echo "นำเข้าทะเบียนวัด {$res['ok']} วัด" . ($res['bad'] ? ' (ข้าม ' . count($res['bad']) . ' บรรทัด)' : '') . "\n";

$created = provision_temple_accounts($pdo);
echo 'สร้างบัญชีวัดอัตโนมัติ ' . count($created) . " บัญชี (1 วัด = 1 บัญชี)\n";
if ($created) {
    $dir = __DIR__ . '/storage';
    is_dir($dir) || mkdir($dir, 0750, true);
    $file = $dir . '/temple_accounts_' . ((int)date('Y') + 543) . date('md_His') . '.csv';
    $fh = fopen($file, 'w');
    write_credentials_csv($fh, $created);
    fclose($fh);
    chmod($file, 0600);
    echo "บันทึกชื่อผู้ใช้/รหัสผ่านเริ่มต้นไว้ที่ $file\n";
    echo "→ นำไปแจกจ่ายให้วัด แล้วย้ายหรือลบไฟล์นี้ออกจากเซิร์ฟเวอร์ (ในฐานข้อมูลเก็บเฉพาะค่าแฮช)\n";
    echo "→ วัดต้องตั้งรหัสผ่านใหม่เองตอนเข้าสู่ระบบครั้งแรก (หลังยืนยันอีเมล)\n";
}

$staff = $noStaff ? [] : staff_provision_all($pdo);
if ($staff) {
    $dir = __DIR__ . '/storage';
    is_dir($dir) || mkdir($dir, 0750, true);
    $file = $dir . '/staff_accounts_' . ((int)date('Y') + 543) . date('md_His') . '.csv';
    $fh = fopen($file, 'w');
    write_staff_credentials_csv($fh, $staff);
    fclose($fh);
    chmod($file, 0600);
    echo 'สร้างผู้ใช้ระดับจังหวัด/อำเภอ/ตำบล ' . count($staff) . " บัญชี (เจ้าคณะจังหวัด 1 · แอดมินอำเภอและเจ้าคณะตำบลตามทะเบียนวัด)\n";
    echo "บันทึกชื่อผู้ใช้/รหัสผ่านเริ่มต้นไว้ที่ $file — ต้องลงอีเมลของแต่ละบัญชีในระบบก่อนจึงเข้าสู่ระบบได้ แล้วลบไฟล์นี้หลังแจกจ่าย\n";
}

$st = $pdo->prepare("INSERT INTO users (username, password_hash, full_name, role, password_changed_at) VALUES ('admin', ?, 'ผู้ดูแลระบบ', 'admin', NOW())
    ON DUPLICATE KEY UPDATE password_hash = VALUES(password_hash)");
$st->execute([password_hash($pw, PASSWORD_DEFAULT)]);
mark_installed();
app_key();
echo "กุญแจเข้ารหัสข้อมูลส่วนบุคคลอยู่ที่ storage/app.key — สำรองไว้ในที่ปลอดภัย (ถ้าสูญหายจะถอดรหัสเลขบัตรประชาชนไม่ได้)\n";
echo "สร้างผู้ใช้ admin แล้ว รหัสผ่าน: $pw\n(กรุณาเปลี่ยนรหัสผ่านหลังเข้าสู่ระบบ)\n";
