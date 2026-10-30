<?php
// ตั้งค่าการเชื่อมต่อฐานข้อมูล — แก้ได้ หรือกำหนดผ่านตัวแปรสภาพแวดล้อม
return [
    'db_host' => getenv('TFMS_DB_HOST') ?: '127.0.0.1',
    'db_port' => getenv('TFMS_DB_PORT') ?: '3306',
    'db_name' => getenv('TFMS_DB_NAME') ?: 'tfms_phrae',
    'db_user' => getenv('TFMS_DB_USER') ?: 'root',
    'db_pass' => getenv('TFMS_DB_PASS') ?: '',
    'app_name' => 'ระบบบัญชีรายรับ-รายจ่ายวัด',
    'app_sub'  => 'สำหรับคณะสงฆ์จังหวัดแพร่',
    'timezone' => 'Asia/Bangkok',
];
