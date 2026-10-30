<?php
// ตั้งค่าเริ่มต้น — กำหนดผ่านตัวแปรสภาพแวดล้อม หรือให้ตัวช่วยติดตั้ง (install.php) สร้าง config.local.php มาตั้งทับ
$config = [
    'db_host' => getenv('TFMS_DB_HOST') ?: '127.0.0.1',
    'db_port' => getenv('TFMS_DB_PORT') ?: '3306',
    'db_name' => getenv('TFMS_DB_NAME') ?: 'tfms_phrae',
    'db_user' => getenv('TFMS_DB_USER') ?: 'root',
    'db_pass' => getenv('TFMS_DB_PASS') ?: '',
    'app_name' => 'ระบบบัญชีรายรับ-รายจ่ายวัด',
    'app_sub'  => 'สำหรับคณะสงฆ์จังหวัดแพร่',
    'timezone' => 'Asia/Bangkok',
    'province_name' => 'แพร่',   // ใช้ในหัวเอกสาร (ถ้านำไปใช้จังหวัดอื่น ตั้งค่านี้ใน config.local.php)
    // การส่งอีเมล (รหัส 2FA): smtp | mail (PHP mail()) | log (ทดสอบเท่านั้น เขียนลง storage/mail.log)
    'mail_driver'    => getenv('TFMS_MAIL_DRIVER') ?: 'smtp',
    'smtp_host'      => getenv('TFMS_SMTP_HOST') ?: '',
    'smtp_port'      => (int)(getenv('TFMS_SMTP_PORT') ?: 587),
    'smtp_secure'    => getenv('TFMS_SMTP_SECURE') ?: 'tls',   // tls (STARTTLS) | ssl | none
    'smtp_user'      => getenv('TFMS_SMTP_USER') ?: '',
    'smtp_pass'      => getenv('TFMS_SMTP_PASS') ?: '',
    'mail_from'      => getenv('TFMS_MAIL_FROM') ?: 'no-reply@example.org',
    // ยืนยันตัวตนสองชั้นทางอีเมลสำหรับแอดมินหลัก/อำเภอ/เจ้าคณะจังหวัด (เปิดเป็นค่าเริ่มต้น) — ปิดได้เฉพาะกรณีฉุกเฉิน เช่น ตั้ง SMTP ไม่ได้ (TFMS_STAFF_2FA=0)
    'staff_2fa' => in_array(strtolower((string)getenv('TFMS_STAFF_2FA')), ['0', 'false', 'off', 'no'], true) ? false : true,
    // ที่อยู่เว็บสาธารณะของระบบ (ใช้ใน QR code ตรวจสอบใบอนุโมทนา) เช่น https://wat.example.org/ — ถ้าไม่ตั้ง จะใช้ที่อยู่ที่ผู้ออกใบเปิดอยู่
    'app_url' => getenv('TFMS_APP_URL') ?: '',
    'mail_from_name' => 'ระบบบัญชีวัด จังหวัดแพร่',
];

if (is_file(__DIR__ . '/config.local.php')) {
    $config = array_replace($config, (array)require __DIR__ . '/config.local.php');
}

return $config;
