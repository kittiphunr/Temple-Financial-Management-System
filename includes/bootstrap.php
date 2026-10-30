<?php
declare(strict_types=1);

$CONFIG = require __DIR__ . '/../config.php';
date_default_timezone_set($CONFIG['timezone']);

session_set_cookie_params(['httponly' => true, 'samesite' => 'Lax']);
session_start();

// เส้นทางสัมพัทธ์ไปยังโฟลเดอร์หลัก (หน้าใน admin/ ต้องถอยขึ้น 1 ระดับ)
define('BASE', basename(dirname($_SERVER['SCRIPT_FILENAME'])) === 'admin' ? '../' : '');

// ยังไม่ได้ติดตั้ง: ส่งไปตัวช่วยติดตั้ง (เหมือน WordPress)
require_once __DIR__ . '/install_lib.php';
if (!is_installed($CONFIG) && basename($_SERVER['SCRIPT_NAME']) !== 'install.php') {
    header('Location: ' . BASE . 'install.php');
    exit;
}

function db(): PDO
{
    static $pdo = null;
    global $CONFIG;
    if ($pdo === null) {
        $dsn = sprintf('mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4',
            $CONFIG['db_host'], $CONFIG['db_port'], $CONFIG['db_name']);
        $pdo = new PDO($dsn, $CONFIG['db_user'], $CONFIG['db_pass'], [
            // ให้ NOW() ในฐานข้อมูลใช้เขตเวลาเดียวกับ PHP (เวลาล็อกบัญชี/รหัส 2FA/เวลาเข้าสู่ระบบจะตรงกัน)
            PDO::MYSQL_ATTR_INIT_COMMAND => "SET time_zone = '" . date('P') . "'",
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
    }
    return $pdo;
}

function e($v): string
{
    return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
}

function money($v): string
{
    return number_format((float)$v, 2);
}

function redirect(string $url): never
{
    header('Location: ' . $url);
    exit;
}

// ---------- ปี/วันที่แบบไทย ----------
const TH_MONTHS = ['', 'มกราคม', 'กุมภาพันธ์', 'มีนาคม', 'เมษายน', 'พฤษภาคม', 'มิถุนายน',
    'กรกฎาคม', 'สิงหาคม', 'กันยายน', 'ตุลาคม', 'พฤศจิกายน', 'ธันวาคม'];

function be_year(): int
{
    return (int)date('Y') + 543;
}

function th_date(?string $ymd): string
{
    if (!$ymd) {
        return '';
    }
    [$y, $m, $d] = array_map('intval', explode('-', substr($ymd, 0, 10)));
    return $d . ' ' . TH_MONTHS[$m] . ' ' . ($y + 543);
}

/** "30 ตุลาคม 2569 13:45 น." จากเวลาค.ศ. ในฐานข้อมูล (Y-m-d H:i[:s]) */
function th_datetime(?string $dt): string
{
    if (!$dt) {
        return '';
    }
    return th_date($dt) . (strlen($dt) >= 16 ? ' ' . substr($dt, 11, 5) . ' น.' : '');
}

/** "30/10/2569" (สำหรับส่งออก CSV) */
function th_date_short(?string $ymd): string
{
    if (!$ymd) {
        return '';
    }
    [$y, $m, $d] = array_map('intval', explode('-', substr($ymd, 0, 10)));
    return sprintf('%02d/%02d/%04d', $d, $m, $y + 543);
}

/**
 * ช่องกรอกวันที่แบบ พ.ศ. (วัน / เดือน / ปี พ.ศ.) แทน <input type="date"> ซึ่งแสดงปีตามภาษาของเบราว์เซอร์ (มักเป็น ค.ศ.)
 * ส่งค่ามาเป็น {name}_d, {name}_m, {name}_y (ปี พ.ศ.) — แปลงกลับเป็น Y-m-d ด้วย date_be_parse()
 * @param string|null $iso วันที่ตั้งต้น (Y-m-d ค.ศ.) ถ้าไม่ระบุใช้วันนี้
 */
function date_be_field(string $name, ?string $iso = null, int $yearsBack = 10, int $yearsAhead = 1): string
{
    $iso = $iso && preg_match('/^\d{4}-\d{2}-\d{2}/', $iso) ? substr($iso, 0, 10) : date('Y-m-d');
    [$sy, $sm, $sd] = array_map('intval', explode('-', $iso));
    $sy += 543;
    $years = range(be_year() + $yearsAhead, be_year() - $yearsBack);
    if (!in_array($sy, $years, true)) {
        $years[] = $sy;
        rsort($years);
    }
    $sel = function (string $suffix, string $label, array $opts, int $cur, string $cls) use ($name): string {
        $h = '<select name="' . e($name . '_' . $suffix) . '" aria-label="' . e($label) . '" required class="' . $cls . '">';
        foreach ($opts as $v => $text) {
            $h .= '<option value="' . (int)$v . '"' . ((int)$v === $cur ? ' selected' : '') . '>' . e((string)$text) . '</option>';
        }
        return $h . '</select>';
    };
    $days = array_combine(range(1, 31), range(1, 31));
    $months = array_slice(TH_MONTHS, 1, null, true);
    $yearOpts = array_combine($years, $years);
    return '<span class="grid grid-cols-[4.5rem_1fr_6rem] gap-1">'
        . $sel('d', 'วันที่', $days, $sd, 'w-full') . $sel('m', 'เดือน', $months, $sm, 'w-full') . $sel('y', 'ปี พ.ศ.', $yearOpts, $sy, 'w-full') . '</span>';
}

/** อ่านช่องวันที่ พ.ศ. จาก $src (เช่น $_POST) แล้วคืน Y-m-d (ค.ศ.) หรือ null ถ้าไม่ครบ/ไม่มีวันนั้นจริง/ปีไม่ใช่ พ.ศ. ที่สมเหตุสมผล */
function date_be_parse(array $src, string $name): ?string
{
    $d = $src[$name . '_d'] ?? null;
    $m = $src[$name . '_m'] ?? null;
    $y = $src[$name . '_y'] ?? null;
    if (!is_scalar($d) || !is_scalar($m) || !is_scalar($y) || !ctype_digit((string)$d) || !ctype_digit((string)$m) || !ctype_digit((string)$y)) {
        return null;
    }
    [$d, $m, $y] = [(int)$d, (int)$m, (int)$y - 543];
    return ($y >= 1900 && $y <= 2200 && checkdate($m, $d, $y)) ? sprintf('%04d-%02d-%02d', $y, $m, $d) : null;
}

// ---------- CSRF ----------
function csrf_token(): string
{
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf'];
}

function csrf_field(): string
{
    return '<input type="hidden" name="csrf" value="' . e(csrf_token()) . '">';
}

function csrf_check(): void
{
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $ok = isset($_POST['csrf']) && hash_equals($_SESSION['csrf'] ?? '', (string)$_POST['csrf']);
        if (!$ok) {
            http_response_code(400);
            exit('คำขอไม่ถูกต้อง (CSRF) กรุณากลับไปโหลดหน้าใหม่');
        }
    }
}

// ---------- ผู้ใช้/สิทธิ์ ----------
function user(): ?array
{
    return $_SESSION['user'] ?? null;
}

function require_login(): array
{
    $u = user();
    if (!$u) {
        redirect(BASE . 'login.php');
    }
    return $u;
}

function require_admin(): array
{
    $u = require_login();
    if ($u['role'] !== 'admin') {
        http_response_code(403);
        exit('ต้องเป็นผู้ดูแลระบบเท่านั้น');
    }
    return $u;
}

/**
 * เงื่อนไข SQL จำกัดขอบเขตข้อมูลตามบทบาท
 * admin = ทุกวัด, province = ทุกวัด (ดูอย่างเดียว), district = วัดในอำเภอตนเอง, subdistrict = วัดในตำบลที่ดูแล (ดูอย่างเดียว), temple = เฉพาะวัดตนเอง
 * @return array{0:string,1:array} [sql ที่ขึ้นต้นด้วย AND, params]
 */
function scope_sql(string $templeAlias = 't'): array
{
    $u = user();
    // แอดมินหลักและเจ้าคณะจังหวัดเห็นข้อมูลทุกวัด (เจ้าคณะจังหวัดดูได้อย่างเดียว — ห้ามเขียนผ่าน can_access_temple)
    if (!$u || in_array($u['role'], ['admin', 'province'], true)) {
        return ['', []];
    }
    if ($u['role'] === 'district') {
        return [" AND $templeAlias.district_id = ?", [(int)$u['district_id']]];
    }
    if ($u['role'] === 'subdistrict') {   // เจ้าคณะตำบล: เฉพาะวัดในตำบลที่ได้รับมอบหมาย (ดูอย่างเดียว)
        return subdistrict_scope_sql($templeAlias, (int)$u['id']);
    }
    return [" AND $templeAlias.id = ?", [(int)$u['temple_id']]];
}

function can_access_temple(int $templeId): bool
{
    $u = user();
    if (!$u) {
        return false;
    }
    $st = db()->prepare('SELECT district_id FROM temples WHERE id = ? AND is_active = 1');
    $st->execute([$templeId]);
    $row = $st->fetch();
    if (!$row) {
        return false;
    }
    if ($u['role'] === 'admin') {
        return true;
    }
    if (in_array($u['role'], ['province', 'subdistrict'], true)) {
        return false; // เจ้าคณะจังหวัด/เจ้าคณะตำบลดูข้อมูลได้อย่างเดียว
    }
    return $u['role'] === 'district'
        ? (int)$row['district_id'] === (int)$u['district_id']
        : $templeId === (int)$u['temple_id'];
}

function audit(string $action, string $entity, ?int $entityId = null, string $detail = ''): void
{
    $st = db()->prepare('INSERT INTO audit_log (user_id, action, entity, entity_id, detail, ip) VALUES (?,?,?,?,?,?)');
    $st->execute([user()['id'] ?? null, $action, $entity, $entityId, $detail, $_SERVER['REMOTE_ADDR'] ?? null]);
}

function flash(?string $msg = null, string $type = 'ok'): ?array
{
    if ($msg !== null) {
        $_SESSION['flash'] = [$msg, $type];
        return null;
    }
    $f = $_SESSION['flash'] ?? null;
    unset($_SESSION['flash']);
    return $f;
}

/** รายชื่อวัดที่ผู้ใช้เข้าถึงได้ จัดกลุ่มตามอำเภอ */
function temples_grouped(): array
{
    [$sc, $p] = scope_sql('t');
    $st = db()->prepare("SELECT t.*, d.name AS district_name, d.sort_order
        FROM temples t JOIN districts d ON d.id = t.district_id
        WHERE t.is_active = 1 $sc ORDER BY d.sort_order, t.name");
    $st->execute($p);
    $out = [];
    foreach ($st->fetchAll() as $r) {
        $out[$r['district_name']][] = $r;
    }
    return $out;
}

require_once __DIR__ . '/mailer.php';
require_once __DIR__ . '/accounts.php';
require_once __DIR__ . '/twofactor.php';
require_once __DIR__ . '/crypto.php';
require_once __DIR__ . '/images.php';
require_once __DIR__ . '/people.php';
require_once __DIR__ . '/staff.php';
require_once __DIR__ . '/subdistricts.php';
require_once __DIR__ . '/letterhead.php';
require_once __DIR__ . '/receipts.php';
require_once __DIR__ . '/about.php';
require_once __DIR__ . '/settings.php';
require_once __DIR__ . '/ledger.php';
require_once __DIR__ . '/ledger_ui.php';

// ต้องเปลี่ยนรหัสผ่านก่อนใช้งาน: ทุกหน้า (ยกเว้นหน้าเปลี่ยนรหัสผ่านและออกจากระบบ) ถูกส่งไปหน้าเปลี่ยนรหัสผ่าน
if (!empty($_SESSION['user']['must_change'])
    && !in_array(basename($_SERVER['SCRIPT_NAME']), ['account.php', 'logout.php'], true)) {
    redirect(BASE . 'account.php');
}
