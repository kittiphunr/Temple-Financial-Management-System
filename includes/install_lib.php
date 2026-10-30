<?php
/** ตัวช่วยติดตั้ง: ตรวจสถานะการติดตั้ง, ตรวจความพร้อมของเซิร์ฟเวอร์, รัน SQL, เขียน config.local.php */

function install_root(): string
{
    return dirname(__DIR__);
}

function install_lock_path(): string
{
    return install_root() . '/storage/installed.lock';
}

function install_dsn(array $db, bool $withDb = true): string
{
    return sprintf('mysql:host=%s;port=%s;%scharset=utf8mb4', $db['db_host'], $db['db_port'],
        $withDb ? 'dbname=' . $db['db_name'] . ';' : '');
}

function install_pdo(array $db, bool $withDb = true): PDO
{
    return new PDO(install_dsn($db, $withDb), $db['db_user'], $db['db_pass'], [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_TIMEOUT => 5,
    ]);
}

/**
 * ติดตั้งแล้วหรือยัง: มีไฟล์ล็อก หรือ (ฐานข้อมูลตาม config เชื่อมต่อได้และมีผู้ใช้อยู่แล้ว — กรณีติดตั้งไว้ด้วย setup.php/ตัวแปรสภาพแวดล้อม)
 * กรณีหลังจะสร้างไฟล์ล็อกให้ เพื่อกันตัวช่วยติดตั้งถูกเปิดซ้ำและเขียนทับระบบที่ใช้งานอยู่
 */
function is_installed(array $config): bool
{
    if (is_file(install_lock_path())) {
        return true;
    }
    try {
        $pdo = install_pdo($config);
        if ((int)$pdo->query('SELECT COUNT(*) FROM users')->fetchColumn() > 0) {
            mark_installed();
            return true;
        }
    } catch (Throwable) {
        // ยังไม่มีฐานข้อมูล/ตาราง = ยังไม่ได้ติดตั้ง
    }
    return false;
}

function mark_installed(): bool
{
    $dir = dirname(install_lock_path());
    if (!is_dir($dir)) {
        @mkdir($dir, 0750, true);
    }
    return @file_put_contents(install_lock_path(), 'installed ' . date('c') . "\n") !== false;
}

/** @return array<int,array{0:string,1:bool,2:string,3:bool}> [หัวข้อ, ผ่านไหม, รายละเอียด, จำเป็นไหม] */
function install_requirements(): array
{
    $root = install_root();
    $storage = $root . '/storage';
    is_dir($storage) || @mkdir($storage, 0750, true);
    $cfgFile = $root . '/config.local.php';
    $cfgWritable = is_file($cfgFile) ? is_writable($cfgFile) : is_writable($root);

    return [
        ['PHP 8.1 ขึ้นไป', version_compare(PHP_VERSION, '8.1.0', '>='), 'พบ PHP ' . PHP_VERSION, true],
        ['ส่วนขยาย pdo_mysql (เชื่อมต่อ MySQL/MariaDB)', extension_loaded('pdo_mysql'), extension_loaded('pdo_mysql') ? 'พร้อมใช้งาน' : 'ไม่พบ — ติดตั้ง php-mysql', true],
        ['ส่วนขยาย mbstring (ภาษาไทย)', extension_loaded('mbstring'), extension_loaded('mbstring') ? 'พร้อมใช้งาน' : 'ไม่พบ — ติดตั้ง php-mbstring', true],
        ['เขียนไฟล์ตั้งค่า config.local.php ได้', $cfgWritable, $cfgWritable ? 'เขียนได้' : 'เขียนไม่ได้ — ให้สิทธิ์เขียนโฟลเดอร์โปรเจกต์ให้ผู้ใช้เว็บเซิร์ฟเวอร์ชั่วคราว', true],
        ['เขียนโฟลเดอร์ storage/ ได้', is_dir($storage) && is_writable($storage), is_writable($storage) ? 'เขียนได้' : 'เขียนไม่ได้ — chmod โฟลเดอร์ storage/', true],
        ['ส่วนขยาย sodium (เข้ารหัสเลขบัตรประชาชนของบุคลากรวัด)', extension_loaded('sodium'), extension_loaded('sodium') ? 'พร้อมใช้งาน' : 'ไม่พบ — ติดตั้ง php-sodium (มากับ PHP 7.2+ ส่วนใหญ่)', true],
        ['ส่วนขยาย zip (นำเข้าทะเบียนวัดจากไฟล์ Excel .xlsx)', extension_loaded('zip'), extension_loaded('zip') ? 'พร้อมใช้งาน' : 'ไม่พบ — นำเข้า Excel ไม่ได้ (ใช้ CSV แทนได้ หรือติดตั้ง php-zip)', false],
        ['ส่วนขยาย gd (ย่อและแปลงรูปโลโก้/รูปถ่าย)', extension_loaded('gd'), extension_loaded('gd') ? 'พร้อมใช้งาน' : 'ไม่พบ — อัปโหลดโลโก้/รูปถ่ายจะใช้ไม่ได้ (ติดตั้ง php-gd)', false],
        ['ทะเบียนวัด data/temples_phrae.csv', is_file($root . '/data/temples_phrae.csv'), is_file($root . '/data/temples_phrae.csv') ? 'พบไฟล์ (381 วัด)' : 'ไม่พบไฟล์ — จะติดตั้งโดยไม่มีรายชื่อวัด', false],
        ['ส่วนขยาย openssl (ส่งอีเมลแบบเข้ารหัส TLS/SSL)', extension_loaded('openssl'), extension_loaded('openssl') ? 'พร้อมใช้งาน' : 'ไม่พบ — SMTP แบบเข้ารหัสจะใช้ไม่ได้', false],
        ['HTTPS', (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off'), (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'ใช้ HTTPS แล้ว' : 'ยังไม่ใช้ HTTPS — ควรเปิดใช้ก่อนใช้งานจริง (รหัสผ่านจะถูกส่งแบบไม่เข้ารหัส)', false],
    ];
}

/** รัน SQL จากไฟล์ โดยข้าม CREATE DATABASE / USE (ใช้ฐานข้อมูลที่เชื่อมต่ออยู่) */
function install_run_sql(PDO $pdo, string $file): void
{
    $sql = preg_replace('/^\s*--.*$/m', '', file_get_contents($file));
    foreach (array_filter(array_map('trim', explode(';', $sql))) as $stmt) {
        if (preg_match('/^(CREATE\s+DATABASE|USE)\b/i', $stmt)) {
            continue;
        }
        $pdo->exec($stmt);
    }
}

/** เขียน config.local.php (ค่าที่ตั้งทับ config.php) @return bool */
function install_write_config(array $values): bool
{
    $file = install_root() . '/config.local.php';
    $body = "<?php\n// สร้างโดยตัวช่วยติดตั้ง — ค่าในไฟล์นี้ตั้งทับ config.php (ห้าม commit / ห้ามเปิดเผย มีรหัสผ่านฐานข้อมูล)\nreturn "
        . var_export($values, true) . ";\n";
    if (@file_put_contents($file, $body, LOCK_EX) === false) {
        return false;
    }
    @chmod($file, 0640);
    return true;
}

/** @return string[] ปัญหาของรหัสผ่าน (ว่าง = ผ่าน) */
function install_password_issues(string $pw, string $username): array
{
    $issues = [];
    if (mb_strlen($pw) < 10) {
        $issues[] = 'รหัสผ่านแอดมินหลักต้องยาวอย่างน้อย 10 ตัวอักษร';
    }
    if (!preg_match('/[A-Za-z]/', $pw) || !preg_match('/\d/', $pw)) {
        $issues[] = 'รหัสผ่านต้องมีทั้งตัวอักษรและตัวเลข';
    }
    if ($username !== '' && stripos($pw, $username) !== false) {
        $issues[] = 'รหัสผ่านต้องไม่มีชื่อผู้ใช้อยู่ในนั้น';
    }
    return $issues;
}

/** รายชื่อจังหวัดของประเทศไทย 77 จังหวัด (ไม่มีคำว่า "จังหวัด") ใช้เลือกจังหวัดที่ระบบนี้ดูแลตอนติดตั้ง */
function install_provinces(): array
{
    return ['กระบี่', 'กรุงเทพมหานคร', 'กาญจนบุรี', 'กาฬสินธุ์', 'กำแพงเพชร', 'ขอนแก่น', 'จันทบุรี', 'ฉะเชิงเทรา', 'ชลบุรี', 'ชัยนาท', 'ชัยภูมิ', 'ชุมพร',
        'เชียงราย', 'เชียงใหม่', 'ตรัง', 'ตราด', 'ตาก', 'นครนายก', 'นครปฐม', 'นครพนม', 'นครราชสีมา', 'นครศรีธรรมราช', 'นครสวรรค์', 'นนทบุรี', 'นราธิวาส', 'น่าน',
        'บึงกาฬ', 'บุรีรัมย์', 'ปทุมธานี', 'ประจวบคีรีขันธ์', 'ปราจีนบุรี', 'ปัตตานี', 'พระนครศรีอยุธยา', 'พะเยา', 'พังงา', 'พัทลุง', 'พิจิตร', 'พิษณุโลก',
        'เพชรบุรี', 'เพชรบูรณ์', 'แพร่', 'ภูเก็ต', 'มหาสารคาม', 'มุกดาหาร', 'แม่ฮ่องสอน', 'ยโสธร', 'ยะลา', 'ร้อยเอ็ด', 'ระนอง', 'ระยอง', 'ราชบุรี',
        'ลพบุรี', 'ลำปาง', 'ลำพูน', 'เลย', 'ศรีสะเกษ', 'สกลนคร', 'สงขลา', 'สตูล', 'สมุทรปราการ', 'สมุทรสงคราม', 'สมุทรสาคร', 'สระแก้ว', 'สระบุรี',
        'สิงห์บุรี', 'สุโขทัย', 'สุพรรณบุรี', 'สุราษฎร์ธานี', 'สุรินทร์', 'หนองคาย', 'หนองบัวลำภู', 'อ่างทอง', 'อำนาจเจริญ', 'อุดรธานี', 'อุตรดิตถ์', 'อุทัยธานี', 'อุบลราชธานี'];
}

/** ชื่อไฟล์ชั่วคราวของรายชื่อวัดที่อัปโหลดตอนติดตั้ง (storage/install_temples_<24hex>.json) */
function install_stage_path(string $token): ?string
{
    return preg_match('/^[a-f0-9]{24}$/', $token) ? install_root() . '/storage/install_temples_' . $token . '.json' : null;
}
