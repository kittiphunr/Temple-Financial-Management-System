<?php
/** หน้าต่าง "เกี่ยวกับ" (เปิดด้วยปุ่มที่ท้ายหน้า): หน่วยงาน ผู้พัฒนา และคำอธิบายระบบ — แก้ข้อมูลผู้พัฒนาได้ที่ ABOUT_DEVELOPER */

const ABOUT_ORG = 'มหาวิทยาลัยมหาจุฬาลงกรณราชวิทยาลัย วิทยาเขตแพร่';
const ABOUT_DEVELOPER = [
    'name' => 'นายกิตติพันธ์ รัตนคร',
    'name_en' => 'Kittiphun Ruttanakorn',
    'title' => 'นักวิชาการคอมพิวเตอร์',
    'tags' => 'Network · Server · Full-Stack',
    'email' => 'kittiphun.rut@mcu.ac.th',
    'phone' => '089-955-4194',
    'github' => 'github.com/kittiphunr',
    'website' => 'kittiphunr.github.io',
];
const ABOUT_POINTS = [
    'บันทึกบัญชีรายรับ-รายจ่ายของวัดทุกแห่งในจังหวัด จัดกลุ่มตามอำเภอ พร้อมแดชบอร์ดและรายงานสรุปรายปี รายเดือน รายหมวด รายวัด',
    'หนึ่งวัดหนึ่งบัญชี ทุกบัญชียืนยันตัวตนสองชั้นด้วยรหัสทางอีเมล และกู้รหัสผ่านที่ลืมทางอีเมลได้',
    'ออกอนุโมทนาบัตรสำหรับเงินบริจาค พร้อมหัวกระดาษและโลโก้วัด/อำเภอ พิมพ์ A4 หรือบันทึกเป็น PDF ได้',
    'เก็บข้อมูลบุคลากรวัด (เจ้าอาวาส พระลูกวัด ไวยาวัจกร) โดยเข้ารหัสเลขบัตรประชาชนและแสดงแบบปิดบังตามสิทธิ์ของแต่ละบทบาท',
    'ใช้งานได้ทั้งคอมพิวเตอร์ แท็บเล็ต และโทรศัพท์มือถือ',
];

/** HTML ของหน้าต่าง (ใส่ใน <template> ให้ SweetAlert2 นำไปแสดง) */
function about_html(): string
{
    global $CONFIG;
    $d = ABOUT_DEVELOPER;
    $row = fn(string $label, string $href, string $text, bool $ext = true) =>
        '<dt>' . e($label) . '</dt><dd><a href="' . e($href) . '"' . ($ext ? ' target="_blank" rel="noopener noreferrer"' : '') . '>' . e($text) . '</a></dd>';
    $li = implode('', array_map(fn($p) => '<li>' . e($p) . '</li>', ABOUT_POINTS));
    return '<div class="about">'
        . '<div class="about-head"><img src="' . e(BASE) . 'assets/img/logo.svg" alt="" width="72" height="72"><div><h3>เกี่ยวกับ ' . e($CONFIG['app_name']) . '</h3>'
        . '<div class="about-sub">' . e($CONFIG['app_sub']) . '</div></div></div>'
        . '<h4>ผู้พัฒนา</h4>'
        . '<div class="about-dev"><img class="about-photo" src="' . e(BASE) . 'assets/img/developer.jpg" alt="' . e($d['name']) . '" width="96" height="96">'
        . '<div><div class="about-name">' . e($d['name']) . '</div><div class="about-sub">' . e($d['name_en']) . '</div>'
        . '<div class="about-sub">' . e($d['title']) . '</div><div class="about-sub">' . e(ABOUT_ORG) . '</div>'
        . '<span class="about-tag">' . e($d['tags']) . '</span></div></div>'
        . '<dl class="about-contact">'
        . $row('อีเมล', 'mailto:' . $d['email'], $d['email'], false)
        . $row('โทร', 'tel:' . preg_replace('/\D/', '', $d['phone']), $d['phone'], false)
        . $row('GitHub', 'https://' . $d['github'], $d['github'])
        . $row('เว็บไซต์', 'https://' . $d['website'], $d['website'])
        . '</dl>'
        . '<h4>เกี่ยวกับเว็บไซต์</h4>'
        . '<p>' . e($CONFIG['app_name']) . ' ' . e($CONFIG['app_sub']) . ' — บันทึก สรุป และออกเอกสารบัญชีของวัดทุกแห่งในจังหวัด' . e($CONFIG['province_name'] ?? '') . ' ในที่เดียว</p>'
        . '<ul>' . $li . '</ul></div>';
}

/** ข้อความแจ้งเตือนที่แสดงด้วย SweetAlert2 (ไม่มี JS จะเป็นแถบข้อความบนหน้า) type: ok | err | info */
function alert_box(string $msg, string $type = 'ok'): string
{
    $cls = match ($type) {
        'err' => 'bg-red-50 text-red-900',
        'info' => 'bg-sky-50 text-sky-900',
        default => 'bg-emerald-50 text-emerald-900',
    };
    return '<div class="mb-4 rounded-lg px-4 py-2.5 ' . $cls . '" role="alert" data-swal="' . e($type) . '">' . e($msg) . '</div>';
}
