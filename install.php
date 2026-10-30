<?php
/**
 * ตัวช่วยติดตั้งระบบ (เหมือน WordPress):
 * 1 ตรวจความพร้อม → 2 ฐานข้อมูล → 3 จังหวัดที่ดูแล + ข้อมูลเบื้องต้น + แอดมินหลัก → 4 อีเมล → 5 ติดตั้ง
 * ติดตั้งเสร็จจะสร้าง storage/installed.lock และตัวช่วยนี้จะใช้งานไม่ได้อีก (ลบไฟล์ล็อกเองเท่านั้นจึงจะติดตั้งใหม่ได้)
 */
declare(strict_types=1);

$CONFIG = require __DIR__ . '/config.php';
date_default_timezone_set($CONFIG['timezone']);
require __DIR__ . '/includes/install_lib.php';
require __DIR__ . '/includes/mailer.php';
require __DIR__ . '/includes/accounts.php';
require __DIR__ . '/includes/temple_import.php';
require __DIR__ . '/includes/crypto.php';

session_set_cookie_params(['httponly' => true, 'samesite' => 'Lax']);
session_start();

function h($v): string
{
    return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
}

function csrf(): string
{
    return $_SESSION['inst_csrf'] ??= bin2hex(random_bytes(32));
}

function csrf_input(): string
{
    return '<input type="hidden" name="csrf" value="' . h(csrf()) . '">';
}

function go(int|string $step): never
{
    header('Location: install.php?step=' . $step);
    exit;
}

function notice(?string $msg = null, string $type = 'err'): ?array
{
    if ($msg !== null) {
        $_SESSION['inst_notice'] = [$msg, $type];
        return null;
    }
    $n = $_SESSION['inst_notice'] ?? null;
    unset($_SESSION['inst_notice']);
    return $n;
}

function field(string $label, string $name, string $value = '', string $type = 'text', string $attrs = '', string $hint = ''): string
{
    return '<div><label for="f_' . h($name) . '">' . h($label) . '</label>'
        . '<input id="f_' . h($name) . '" class="w-full" type="' . h($type) . '" name="' . h($name) . '" value="' . h($value) . '" ' . $attrs . '>'
        . ($hint !== '' ? '<p class="mt-1 text-xs text-stone-500">' . h($hint) . '</p>' : '') . '</div>';
}

function page(string $title, int $step, string $body, int $status = 200): never
{
    http_response_code($status);
    $labels = ['ตรวจความพร้อม', 'ฐานข้อมูล', 'จังหวัดและแอดมิน', 'อีเมล', 'ติดตั้ง'];
    $n = notice();
    ?>
<!doctype html>
<html lang="th">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex,nofollow">
<title><?= h($title) ?> | ตัวช่วยติดตั้งระบบ</title>
<link href="https://fonts.googleapis.com/css2?family=Sarabun:wght@400;600;700&display=swap" rel="stylesheet">
<link rel="icon" type="image/svg+xml" href="assets/img/logo.svg">
<link rel="stylesheet" href="assets/css/tailwind.css">
</head>
<body class="min-h-screen">
<main class="mx-auto max-w-2xl px-4 py-8">
  <div class="mb-6 text-center">
    <img class="mx-auto h-20 w-20" src="assets/img/logo.svg" alt="">
    <h1 class="mt-2 text-2xl font-bold text-brand-700">ตัวช่วยติดตั้งระบบบัญชีรายรับ-รายจ่ายวัด</h1>
    <p class="text-stone-600"><?= !empty($_SESSION['inst']['site']['province']) ? 'สำหรับคณะสงฆ์จังหวัด' . h($_SESSION['inst']['site']['province']) : 'สำหรับคณะสงฆ์ระดับจังหวัด' ?></p>
  </div>
  <?php if ($step >= 1 && $step <= 5): ?>
  <ol class="mb-6 flex items-start justify-between gap-1 text-center text-xs sm:text-sm">
    <?php foreach ($labels as $i => $l): $k = $i + 1; ?>
    <li class="flex-1">
      <span class="mx-auto flex h-8 w-8 items-center justify-center rounded-full font-bold <?= $k < $step ? 'bg-emerald-600 text-white' : ($k === $step ? 'bg-brand-600 text-white' : 'bg-stone-200 text-stone-600') ?>"><?= $k < $step ? '✓' : $k ?></span>
      <span class="mt-1 block <?= $k === $step ? 'font-bold text-brand-700' : 'text-stone-500' ?>"><?= h($l) ?></span>
    </li>
    <?php endforeach; ?>
  </ol>
  <?php endif; ?>
  <?php if ($n): ?><div class="mb-4 rounded-lg px-4 py-2.5 <?= $n[1] === 'ok' ? 'bg-emerald-50 text-emerald-900' : 'bg-red-50 text-red-900' ?>"><?= h($n[0]) ?></div><?php endif; ?>
  <div class="rounded-2xl border border-stone-200 bg-white p-6 shadow-sm"><?= $body ?></div>
</main>
</body>
</html>
<?php
    exit;
}

// ---------- ติดตั้งเสร็จแล้ว ----------
if (is_installed($CONFIG)) {
    $done = $_SESSION['inst_done'] ?? null;
    if ($done && ($_GET['download'] ?? '') === 'creds' && !empty($done['creds'])) {
        $rows = $done['creds'];
        unset($_SESSION['inst_done']['creds']); // ดาวน์โหลดได้ครั้งเดียว
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="temple-accounts-' . ((int)date('Y') + 543) . date('md-His') . '.csv"');
        header('Cache-Control: no-store');
        write_credentials_csv(fopen('php://output', 'w'), $rows);
        exit;
    }
    if ($done && ($_GET['step'] ?? '') === 'done') {
        ob_start(); ?>
        <h2 class="mb-2 text-2xl font-bold text-emerald-700">✓ ติดตั้งเสร็จเรียบร้อย</h2>
        <p class="mb-4 text-stone-700">ระบบพร้อมใช้งานแล้ว เข้าสู่ระบบด้วยชื่อผู้ใช้ <strong class="font-mono"><?= h($done['admin']) ?></strong> และรหัสผ่านที่คุณตั้งไว้</p>
        <ul class="mb-4 grid gap-1 text-sm text-stone-700">
          <li>• จังหวัดที่ดูแล: <strong><?= h($done['province'] ?? 'แพร่') ?></strong> (<?= (int)($done['districts'] ?? 0) ?> อำเภอ)</li>
          <li>• นำเข้าทะเบียนวัด: <strong><?= (int)$done['temples'] ?></strong> วัด</li>
          <li>• สร้างบัญชีแอดมินประจำวัด: <strong><?= (int)$done['accounts'] ?></strong> บัญชี (1 วัด = 1 บัญชี)</li>
        </ul>
        <?php if (!empty($done['creds'])): ?>
        <div class="mb-4 rounded-xl border border-amber-300 bg-amber-50 p-4 text-amber-950">
          <p class="font-bold">ดาวน์โหลดรหัสผ่านเริ่มต้นของแต่ละวัด (ทำได้ครั้งเดียว)</p>
          <p class="mb-3 mt-1 text-sm">ในฐานข้อมูลเก็บเฉพาะค่าแฮช ดูย้อนหลังไม่ได้ — เก็บไฟล์ไว้ในที่ปลอดภัย นำไปแจกวัด แล้วลบทิ้ง
            วัดต้องตั้งรหัสผ่านใหม่เองตอนเข้าสู่ระบบครั้งแรกหลังยืนยันอีเมล</p>
          <a class="btn" href="install.php?download=creds">⇩ ดาวน์โหลด CSV รหัสผ่านเริ่มต้น</a>
        </div>
        <?php endif; ?>
        <div class="mb-4 rounded-xl bg-gold-50 p-4 text-sm text-stone-800">
          <p class="mb-1 font-bold">สิ่งที่ต้องทำต่อ</p>
          <ol class="ml-5 list-decimal">
            <?php if (($done['source'] ?? '') === 'later' || empty($done['temples'])): ?>
            <li><strong>นำเข้าทะเบียนวัด</strong> ที่ จัดการระบบ → นำเข้ารายชื่อวัด (ไฟล์ Excel จาก binfo.onab.go.th) — ระบบจะสร้างอำเภอ วัด และบัญชีแอดมินวัดให้</li>
            <?php endif; ?>
            <?php if (($done['province'] ?? 'แพร่') !== 'แพร่'): ?>
            <li>เปลี่ยนตราระบบ <code>assets/img/logo.svg</code> (ยังเป็นตราจังหวัดแพร่) เป็นของจังหวัด<?= h($done['province']) ?></li>
            <?php endif; ?>
            <li>เข้าสู่ระบบ → จัดการระบบ → <strong>บัญชีวัด</strong> แล้วลงอีเมลของแต่ละวัด (วัดที่ยังไม่มีอีเมลจะเข้าสู่ระบบไม่ได้)</li>
            <li>แจกชื่อผู้ใช้/รหัสผ่านเริ่มต้นให้วัด</li>
            <li><strong>เข้าสู่ระบบครั้งแรกด้วยแอดมินหลัก</strong> ระบบจะให้ลงทะเบียนและยืนยันอีเมลของคุณ (ส่งรหัส 6 หลัก) ก่อนเข้าใช้งาน — ต้องตั้ง SMTP ให้ส่งอีเมลได้ และต่อไปทุกบัญชีต้องยืนยันรหัสทางอีเมลทุกครั้ง</li>
            <li><strong>สำรองไฟล์ <code>storage/app.key</code></strong> ไว้ในที่ปลอดภัย — เป็นกุญแจเข้ารหัสเลขบัตรประชาชนของบุคลากรวัด ถ้าสูญหายจะถอดรหัสไม่ได้อีก</li>
            <li>ผู้ใช้ระดับจังหวัด/อำเภอสร้างได้ไม่เกิน (จำนวนอำเภอ + 2) คน = แอดมินประจำอำเภอ อำเภอละ 1 + แอดมินหลัก 1 + เจ้าคณะจังหวัด 1 ที่ จัดการระบบ → ผู้ใช้</li>
            <li>ตามหาโลโก้ของแต่ละอำเภอมาใส่ที่ จัดการระบบ → โลโก้อำเภอ และแจ้งแอดมินวัดให้ใส่โลโก้วัดของตน (ระบบเตือนอัตโนมัติ)</li>
            <li>ตัวช่วยติดตั้งถูกล็อกแล้ว แนะนำให้ลบไฟล์ <code>install.php</code> ออกจากเซิร์ฟเวอร์</li>
          </ol>
        </div>
        <a class="btn" href="login.php">ไปหน้าเข้าสู่ระบบ →</a>
        <?php page('ติดตั้งเสร็จแล้ว', 6, ob_get_clean());
    }
    page('ติดตั้งแล้ว', 0, '<h2 class="mb-2 text-xl font-bold">ระบบนี้ติดตั้งเรียบร้อยแล้ว</h2>'
        . '<p class="mb-4 text-stone-700">ตัวช่วยติดตั้งถูกล็อกเพื่อความปลอดภัย หากต้องการติดตั้งใหม่ทั้งหมด ให้ผู้ดูแลเซิร์ฟเวอร์ลบไฟล์ <code>storage/installed.lock</code> '
        . 'และ <code>config.local.php</code> ด้วยตนเอง (ข้อมูลในฐานข้อมูลเดิมต้องจัดการแยกต่างหาก)</p><a class="btn" href="index.php">ไปหน้าแรก</a>', 403);
}

// ---------- รหัสป้องกันตัวช่วยติดตั้ง (ไม่บังคับ: ตั้งตัวแปรสภาพแวดล้อม TFMS_INSTALL_KEY) ----------
$installKey = (string)getenv('TFMS_INSTALL_KEY');
if ($installKey !== '' && empty($_SESSION['inst_auth'])) {
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && hash_equals(csrf(), (string)($_POST['csrf'] ?? ''))) {
        $_SESSION['inst_key_fail'] = ($_SESSION['inst_key_fail'] ?? 0) + 1;
        if ($_SESSION['inst_key_fail'] <= 5 && hash_equals($installKey, (string)($_POST['key'] ?? ''))) {
            $_SESSION['inst_auth'] = true;
            go(1);
        }
        notice('รหัสติดตั้งไม่ถูกต้อง');
    }
    page('รหัสติดตั้ง', 0, '<h2 class="mb-2 text-xl font-bold">ยืนยันว่าคุณเป็นผู้ดูแลเซิร์ฟเวอร์</h2>'
        . '<p class="mb-3 text-sm text-stone-600">เซิร์ฟเวอร์นี้ตั้งรหัสป้องกันการติดตั้งไว้ (ตัวแปรสภาพแวดล้อม <code>TFMS_INSTALL_KEY</code>)</p>'
        . '<form method="post" class="grid gap-3">' . csrf_input() . field('รหัสติดตั้ง', 'key', '', 'password', 'required autofocus')
        . '<div><button class="btn">ต่อไป</button></div></form>');
}

// ---------- สถานะตัวช่วย ----------
$S = &$_SESSION['inst'];
$S ??= ['max' => 1];
$step = max(1, min((int)($_GET['step'] ?? $S['max']), (int)$S['max']));

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!hash_equals(csrf(), (string)($_POST['csrf'] ?? ''))) {
        http_response_code(400);
        exit('คำขอไม่ถูกต้อง (CSRF) กรุณากลับไปโหลดหน้าใหม่');
    }
    $act = $_POST['action'] ?? '';

    // ขั้น 1
    if ($act === 'requirements') {
        foreach (install_requirements() as $r) {
            if ($r[3] && !$r[1]) {
                notice('ยังมีรายการที่จำเป็นไม่ผ่าน กรุณาแก้ไขแล้วกด "ตรวจอีกครั้ง"');
                go(1);
            }
        }
        $S['max'] = max($S['max'], 2);
        go(2);
    }

    // ขั้น 2
    if ($act === 'db') {
        $db = [
            'db_host' => trim($_POST['db_host'] ?? ''),
            'db_port' => trim($_POST['db_port'] ?? '3306'),
            'db_name' => trim($_POST['db_name'] ?? ''),
            'db_user' => trim($_POST['db_user'] ?? ''),
            'db_pass' => (string)($_POST['db_pass'] ?? ''),
        ];
        if ($db['db_pass'] === '' && !empty($S['db']['db_pass']) && ($S['db']['db_user'] ?? '') === $db['db_user']) {
            $db['db_pass'] = $S['db']['db_pass']; // ช่องรหัสผ่านว่าง = ใช้ค่าเดิมที่เคยกรอก
        }
        $S['db_form'] = $db;
        unset($S['db_form']['db_pass']);
        if (!preg_match('/^[A-Za-z0-9._:-]+$/', $db['db_host']) || !preg_match('/^\d{1,5}$/', $db['db_port'])
            || !preg_match('/^[A-Za-z0-9_]{1,64}$/', $db['db_name']) || $db['db_user'] === '') {
            notice('ข้อมูลไม่ถูกต้อง: โฮสต์/พอร์ต/ชื่อฐานข้อมูล (ใช้ได้เฉพาะ a-z A-Z 0-9 _) /ชื่อผู้ใช้');
            go(2);
        }
        try {
            $srv = install_pdo($db, false);
        } catch (Throwable $e) {
            notice('เชื่อมต่อเซิร์ฟเวอร์ฐานข้อมูลไม่ได้ — ตรวจโฮสต์ พอร์ต ชื่อผู้ใช้ และรหัสผ่าน (' . $e->getMessage() . ')');
            go(2);
        }
        $q = $srv->prepare('SELECT 1 FROM information_schema.SCHEMATA WHERE SCHEMA_NAME = ?');
        $q->execute([$db['db_name']]);
        if (!$q->fetchColumn()) {
            try {
                $srv->exec('CREATE DATABASE `' . $db['db_name'] . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
            } catch (Throwable) {
                notice('ยังไม่มีฐานข้อมูล "' . $db['db_name'] . '" และผู้ใช้นี้ไม่มีสิทธิ์สร้าง — กรุณาสร้างฐานข้อมูลเองแล้วกดตรวจอีกครั้ง');
                go(2);
            }
        }
        try {
            $pdo = install_pdo($db);
            $n = 0;
            try {
                $n = (int)$pdo->query('SELECT COUNT(*) FROM users')->fetchColumn();
            } catch (Throwable) {
                // ยังไม่มีตาราง = ฐานข้อมูลว่าง ปกติ
            }
            if ($n > 0) {
                notice('ฐานข้อมูลนี้มีข้อมูลผู้ใช้ของระบบอยู่แล้ว ตัวช่วยติดตั้งจะไม่เขียนทับ — ใช้ฐานข้อมูลอื่น หรือสำรองแล้วล้างข้อมูลก่อน');
                go(2);
            }
        } catch (Throwable $e) {
            notice('เปิดฐานข้อมูลไม่ได้: ' . $e->getMessage());
            go(2);
        }
        $S['db'] = $db;
        $S['max'] = max($S['max'], 3);
        notice('เชื่อมต่อฐานข้อมูลสำเร็จ', 'ok');
        go(3);
    }

    // ขั้น 3
    if ($act === 'site') {
        $provinces = install_provinces();
        $province = trim((string)($_POST['province'] ?? 'แพร่'));
        $province = preg_replace('/^(จังหวัด|จ\.)\s*/u', '', $province);
        $source = $_POST['temple_source'] ?? (isset($_POST['import_temples']) ? 'builtin' : 'later');   // import_temples = ชื่อฟิลด์แบบเดิม
        $source = in_array($source, ['builtin', 'file', 'later'], true) ? $source : 'later';
        $f = [
            'province' => $province,
            'site_name' => trim($_POST['site_name'] ?? ''),
            'site_sub' => trim($_POST['site_sub'] ?? ''),
            'admin_user' => trim($_POST['admin_user'] ?? ''),
            'admin_name' => trim($_POST['admin_name'] ?? ''),
            'temple_source' => $source,
            'make_accounts' => isset($_POST['make_accounts']),
        ];
        $S['site_form'] = $f;
        $pw = (string)($_POST['admin_pass'] ?? '');
        $errs = [];
        if (!in_array($province, $provinces, true)) $errs[] = 'กรุณาเลือกจังหวัดที่ระบบนี้ดูแลจากรายการ';
        if ($f['site_name'] === '' || mb_strlen($f['site_name']) > 100) $errs[] = 'กรุณากรอกชื่อระบบ (ไม่เกิน 100 ตัวอักษร)';
        if (mb_strlen($f['site_sub']) > 150) $errs[] = 'คำอธิบายใต้ชื่อยาวเกินไป';
        if (!preg_match('/^[A-Za-z0-9_.-]{3,50}$/', $f['admin_user'])) $errs[] = 'ชื่อผู้ใช้แอดมินหลักใช้ได้เฉพาะ a-z A-Z 0-9 _ . - (3–50 ตัว)';
        if (str_starts_with(strtolower($f['admin_user']), 'wat')) $errs[] = 'ชื่อผู้ใช้ที่ขึ้นต้นด้วย wat สงวนไว้สำหรับบัญชีวัด';
        if ($f['admin_name'] === '') $errs[] = 'กรุณากรอกชื่อ-นามสกุลแอดมินหลัก';
        if ($pw === '' && !empty($S['site']['admin_hash'])) {
            $f['admin_hash'] = $S['site']['admin_hash'];
        } else {
            $errs = array_merge($errs, install_password_issues($pw, $f['admin_user']));
            if ($pw !== (string)($_POST['admin_pass2'] ?? '')) $errs[] = 'รหัสผ่านและการยืนยันไม่ตรงกัน';
            $f['admin_hash'] = $pw === '' ? '' : password_hash($pw, PASSWORD_DEFAULT);
        }

        // รายชื่อวัด: ทะเบียนที่มากับชุด (เฉพาะแพร่) / ไฟล์ Excel-CSV ของจังหวัดนั้น / นำเข้าภายหลัง
        $oldStage = $S['site']['stage'] ?? null;
        $f['stage'] = null;
        if ($source === 'builtin') {
            if ($province !== 'แพร่') $errs[] = 'ทะเบียนวัดที่มากับชุดเป็นของจังหวัดแพร่เท่านั้น — จังหวัดอื่นให้อัปโหลดไฟล์ Excel ทะเบียนวัด หรือเลือกนำเข้าภายหลัง';
            elseif (!is_file(__DIR__ . '/data/temples_phrae.csv')) $errs[] = 'ไม่พบไฟล์ data/temples_phrae.csv';
        } elseif ($source === 'file') {
            $up = $_FILES['temple_file'] ?? null;
            if ($up && $up['error'] === UPLOAD_ERR_OK) {
                $isXlsx = strtolower(pathinfo((string)$up['name'], PATHINFO_EXTENSION)) === 'xlsx';
                try {
                    if ($up['size'] > 10 * 1024 * 1024 || !is_uploaded_file($up['tmp_name'])) {
                        throw new RuntimeException('อัปโหลดไฟล์ไม่สำเร็จ (ขนาดไม่เกิน 10 MB)');
                    }
                    $parsed = $isXlsx ? temple_import_parse_xlsx($up['tmp_name']) : temple_import_parse_csv($up['tmp_name']);
                    if (!$parsed['rows']) {
                        throw new RuntimeException('ไม่พบรายชื่อวัดที่นำเข้าได้ในไฟล์');
                    }
                    if ($parsed['province'] && $parsed['province'][0] !== $province) {
                        throw new RuntimeException('ไฟล์นี้เป็นทะเบียนวัดของจังหวัด' . $parsed['province'][0] . ' แต่เลือกจังหวัด' . $province . ' ไว้ — ตรวจว่าเลือกไฟล์/จังหวัดถูกต้อง');
                    }
                    $token = bin2hex(random_bytes(12));
                    $parsed['name'] = basename((string)$up['name']);
                    $parsed['kind'] = $isXlsx ? 'Excel (ทะเบียนวัด)' : 'CSV';
                    file_put_contents(install_stage_path($token), json_encode($parsed, JSON_UNESCAPED_UNICODE), LOCK_EX);
                    @chmod(install_stage_path($token), 0600);
                    $f['stage'] = ['token' => $token, 'name' => $parsed['name'], 'count' => count($parsed['rows']),
                        'districts' => count(array_unique(array_column($parsed['rows'], 'district'))), 'bad' => count($parsed['bad'])];
                } catch (RuntimeException $e) {
                    $errs[] = $e->getMessage();
                }
            } elseif ($oldStage) {
                $f['stage'] = $oldStage;   // ไม่ได้เลือกไฟล์ใหม่ = ใช้ไฟล์ที่อัปโหลดไว้ก่อนหน้า
            } else {
                $errs[] = 'กรุณาเลือกไฟล์ทะเบียนวัด (.xlsx จาก binfo.onab.go.th หรือ .csv) หรือเปลี่ยนเป็น "นำเข้าภายหลัง"';
            }
        }
        if ($oldStage && (!$f['stage'] || $f['stage']['token'] !== $oldStage['token'])
            && ($p = install_stage_path((string)$oldStage['token'])) && is_file($p)) {
            @unlink($p);   // ไฟล์เก่าที่ไม่ใช้แล้ว
        }
        if ($errs) {
            $S['site']['stage'] = $f['stage'];   // เก็บไฟล์ที่ผ่านการตรวจแล้วไว้ แม้ข้อมูลส่วนอื่นยังไม่ผ่าน
            notice(implode(' · ', $errs));
            go(3);
        }
        $S['site'] = $f;
        $S['max'] = max($S['max'], 4);
        go(4);
    }

    // ขั้น 4
    if ($act === 'mail_test' || $act === 'mail_save') {
        $m = [
            'mail_driver' => in_array($_POST['mail_driver'] ?? '', ['smtp', 'mail', 'log'], true) ? $_POST['mail_driver'] : 'smtp',
            'smtp_host' => trim($_POST['smtp_host'] ?? ''),
            'smtp_port' => (int)($_POST['smtp_port'] ?? 587),
            'smtp_secure' => in_array($_POST['smtp_secure'] ?? '', ['tls', 'ssl', 'none'], true) ? $_POST['smtp_secure'] : 'tls',
            'smtp_user' => trim($_POST['smtp_user'] ?? ''),
            'smtp_pass' => (string)($_POST['smtp_pass'] ?? ''),
            'mail_from' => strtolower(trim($_POST['mail_from'] ?? '')),
        ];
        if ($m['smtp_pass'] === '' && !empty($S['mail']['smtp_pass']) && ($S['mail']['smtp_user'] ?? '') === $m['smtp_user']) {
            $m['smtp_pass'] = $S['mail']['smtp_pass'];
        }
        $S['mail'] = $m;
        $S['mail_test_to'] = trim($_POST['test_to'] ?? '');
        if ($m['smtp_host'] !== '' && !preg_match('/^[A-Za-z0-9.-]+$/', $m['smtp_host'])) {
            notice('ชื่อโฮสต์ SMTP ไม่ถูกต้อง');
            go(4);
        }
        if ($m['mail_from'] !== '' && !mail_valid_address($m['mail_from'])) {
            notice('อีเมลผู้ส่งไม่ถูกต้อง');
            go(4);
        }
        if ($act === 'mail_test') {
            if (!mail_valid_address($S['mail_test_to'])) {
                notice('กรอกอีเมลปลายทางสำหรับทดสอบให้ถูกต้อง');
                go(4);
            }
            $CONFIG = array_replace($CONFIG, $m, ['mail_from_name' => $S['site']['site_name'] ?? 'ระบบบัญชีวัด']);
            $ok = send_mail($S['mail_test_to'], 'ทดสอบการส่งอีเมลจากตัวช่วยติดตั้ง', "หากคุณได้รับอีเมลนี้ แสดงว่าการตั้งค่าอีเมลของระบบถูกต้อง\nระบบจะใช้การตั้งค่านี้ส่งรหัสยืนยัน 6 หลักให้แอดมินประจำวัด\n");
            $ok ? notice('ส่งอีเมลทดสอบแล้ว กรุณาตรวจกล่องจดหมายของ ' . $S['mail_test_to'], 'ok')
                : notice('ส่งอีเมลทดสอบไม่สำเร็จ — ตรวจโฮสต์ พอร์ต โหมดเข้ารหัส ชื่อผู้ใช้/รหัสผ่าน และอีเมลผู้ส่ง');
            go(4);
        }
        $S['max'] = max($S['max'], 5);
        go(5);
    }

    // ขั้น 5: ติดตั้งจริง
    if ($act === 'install' && isset($S['db'], $S['site'], $S['mail']) && $S['max'] >= 5) {
        @set_time_limit(0);
        try {
            $pdo = install_pdo($S['db']);
            install_run_sql($pdo, __DIR__ . '/sql/schema.sql');
            install_run_sql($pdo, __DIR__ . '/sql/seed.sql');

            $site = $S['site'];
            $nTemples = 0;
            $csv = __DIR__ . '/data/temples_phrae.csv';
            if ($site['temple_source'] === 'builtin' && is_file($csv)) {
                $nTemples = import_temples_csv($pdo, $csv, true, 'ทะเบียนวัด พศ. (ตัวช่วยติดตั้ง)')['ok'];
            } elseif ($site['temple_source'] === 'file' && !empty($site['stage'])) {
                $stageFile = install_stage_path((string)$site['stage']['token']);
                $parsed = $stageFile && is_file($stageFile) ? json_decode((string)file_get_contents($stageFile), true) : null;
                if (!$parsed || empty($parsed['rows'])) {
                    throw new RuntimeException('ไม่พบไฟล์ทะเบียนวัดที่อัปโหลดไว้ (หมดอายุ) — กลับไปขั้นที่ 3 แล้วเลือกไฟล์ใหม่');
                }
                if ($site['province'] !== 'แพร่') {
                    districts_prune_empty($pdo);   // อำเภอตัวอย่างของแพร่จากข้อมูลตั้งต้น — ยังไม่มีวัด/ผู้ใช้ ลบได้
                }
                $res = temple_import_apply($pdo, $parsed['rows'], ['verified' => true, 'create_districts' => true, 'set_emails' => false,
                    'source' => 'นำเข้า ' . ($parsed['kind'] ?? 'ไฟล์') . ' ' . mb_substr((string)($parsed['name'] ?? ''), 0, 60) . ' (ตัวช่วยติดตั้ง)']);
                $nTemples = $res['inserted'] + $res['updated'];
                districts_prune_empty($pdo);
            }
            if ($site['province'] !== 'แพร่') {
                districts_prune_empty($pdo);   // ไม่ได้ใช้อำเภอตัวอย่างของแพร่ (ถ้านำเข้าจากไฟล์ ระบบสร้างอำเภอของจังหวัดนี้ให้แล้ว)
            }
            $pdo->prepare('INSERT INTO settings (k, v) VALUES (?, ?) ON DUPLICATE KEY UPDATE v = VALUES(v)')->execute(['province_name', $site['province']]);

            $pdo->prepare("INSERT INTO users (username, password_hash, full_name, role, password_changed_at) VALUES (?,?,?,'admin',NOW())
                           ON DUPLICATE KEY UPDATE password_hash = VALUES(password_hash), full_name = VALUES(full_name)")
                ->execute([$S['site']['admin_user'], $S['site']['admin_hash'], $S['site']['admin_name']]);

            $creds = [];
            if ($S['site']['make_accounts']) {
                $creds = provision_temple_accounts($pdo);
            }

            $values = $S['db'] + [
                'app_name' => $S['site']['site_name'],
                'app_sub' => $S['site']['site_sub'],
                'province_name' => $S['site']['province'],
            ] + $S['mail'] + ['mail_from_name' => $S['site']['site_name']];
            if ($values['mail_from'] === '') {
                unset($values['mail_from']);
            }
            if (!install_write_config($values)) {
                throw new RuntimeException('เขียนไฟล์ config.local.php ไม่ได้ — ตรวจสิทธิ์เขียนโฟลเดอร์โปรเจกต์ แล้วกดติดตั้งอีกครั้ง (ฐานข้อมูลที่สร้างไว้แล้วจะถูกใช้ต่อ)');
            }
            app_key();   // สร้างกุญแจเข้ารหัสข้อมูลส่วนบุคคล (storage/app.key) ตั้งแต่ตอนติดตั้ง
            if (!empty($site['stage']) && ($sf = install_stage_path((string)$site['stage']['token'])) && is_file($sf)) {
                @unlink($sf);
            }
            if (!mark_installed()) {
                throw new RuntimeException('สร้างไฟล์ storage/installed.lock ไม่ได้ — ตรวจสิทธิ์เขียนโฟลเดอร์ storage/');
            }
        } catch (Throwable $e) {
            notice('ติดตั้งไม่สำเร็จ: ' . $e->getMessage());
            go(5);
        }
        $_SESSION['inst_done'] = ['admin' => $S['site']['admin_user'], 'temples' => $nTemples, 'accounts' => count($creds), 'creds' => $creds, 'province' => $S['site']['province'], 'districts' => (int)$pdo->query('SELECT COUNT(*) FROM districts')->fetchColumn(), 'source' => $S['site']['temple_source']];
        unset($_SESSION['inst']);
        session_regenerate_id(true);
        go('done');
    }
    go($step);
}

// ---------- แสดงแต่ละขั้น ----------
ob_start();
switch ($step) {
    case 1:
        $reqs = install_requirements();
        $blocked = false;
        ?>
        <h2 class="mb-1 text-xl font-bold">ยินดีต้อนรับ — ตรวจความพร้อมของเซิร์ฟเวอร์</h2>
        <p class="mb-4 text-sm text-stone-600">ตัวช่วยนี้จะพาติดตั้งระบบทีละขั้น: ตั้งค่าฐานข้อมูล สร้างแอดมินหลัก ตั้งค่าอีเมล และสร้างบัญชีให้ทุกวัดอัตโนมัติ</p>
        <ul class="mb-4 divide-y divide-stone-100 rounded-xl border border-stone-200">
          <?php foreach ($reqs as [$label, $ok, $detail, $required]): if ($required && !$ok) $blocked = true; ?>
          <li class="flex items-start gap-3 px-3 py-2">
            <span class="mt-0.5 font-bold <?= $ok ? 'text-emerald-600' : ($required ? 'text-red-600' : 'text-amber-600') ?>"><?= $ok ? '✓' : ($required ? '✗' : '!') ?></span>
            <span><span class="font-semibold"><?= h($label) ?></span><br><span class="text-sm text-stone-500"><?= h($detail) ?></span></span>
          </li>
          <?php endforeach; ?>
        </ul>
        <form method="post" class="flex flex-wrap gap-2">
          <?= csrf_input() ?><input type="hidden" name="action" value="requirements">
          <?php if ($blocked): ?>
            <a class="btn" href="install.php?step=1">ตรวจอีกครั้ง</a>
          <?php else: ?>
            <button class="btn">เริ่มติดตั้ง →</button>
          <?php endif; ?>
        </form>
        <?php
        break;

    case 2:
        $d = ($S['db_form'] ?? []) + ['db_host' => '127.0.0.1', 'db_port' => '3306', 'db_name' => 'tfms_phrae', 'db_user' => '']; ?>
        <h2 class="mb-1 text-xl font-bold">ข้อมูลฐานข้อมูล MySQL / MariaDB</h2>
        <p class="mb-4 text-sm text-stone-600">ถ้ายังไม่มีฐานข้อมูล ตัวช่วยจะสร้างให้ (ผู้ใช้ต้องมีสิทธิ์ CREATE) ใช้ฐานข้อมูลที่ว่างเท่านั้น</p>
        <form method="post" class="grid gap-3 sm:grid-cols-2">
          <?= csrf_input() ?><input type="hidden" name="action" value="db">
          <?= field('โฮสต์ฐานข้อมูล', 'db_host', $d['db_host'], 'text', 'required') ?>
          <?= field('พอร์ต', 'db_port', $d['db_port'], 'text', 'required inputmode="numeric"') ?>
          <?= field('ชื่อฐานข้อมูล', 'db_name', $d['db_name'], 'text', 'required') ?>
          <?= field('ชื่อผู้ใช้ฐานข้อมูล', 'db_user', $d['db_user'], 'text', 'required autocomplete="off"') ?>
          <div class="sm:col-span-2"><?= field('รหัสผ่านฐานข้อมูล', 'db_pass', '', 'password', 'autocomplete="off"', !empty($S['db']['db_pass']) ? 'เว้นว่างไว้เพื่อใช้รหัสผ่านที่กรอกไว้ก่อนหน้า' : '') ?></div>
          <div class="flex gap-2 sm:col-span-2"><a class="btn btn-outline" href="install.php?step=1">← กลับ</a><button class="btn">ทดสอบการเชื่อมต่อและไปต่อ →</button></div>
        </form>
        <?php
        break;

    case 3:
        $f = ($S['site_form'] ?? []) + ['province' => 'แพร่', 'site_name' => 'ระบบบัญชีรายรับ-รายจ่ายวัด', 'site_sub' => 'สำหรับคณะสงฆ์จังหวัดแพร่',
            'admin_user' => '', 'admin_name' => '', 'temple_source' => 'builtin', 'make_accounts' => true];
        $stage = $S['site']['stage'] ?? null;
        $hasPw = !empty($S['site']['admin_hash']);
        $hasBuiltin = is_file(__DIR__ . '/data/temples_phrae.csv'); ?>
        <h2 class="mb-1 text-xl font-bold">จังหวัดที่ดูแล และข้อมูลเบื้องต้น</h2>
        <p class="mb-4 text-sm text-stone-600">ระบบนี้ใช้ดูแลบัญชีวัดของ <strong>1 จังหวัด</strong> — ระบุจังหวัด ชื่อระบบ และทะเบียนวัด แล้วสร้างแอดมินหลักที่ดูแลระบบทั้งจังหวัด</p>
        <form method="post" enctype="multipart/form-data" class="grid gap-3 sm:grid-cols-2">
          <?= csrf_input() ?><input type="hidden" name="action" value="site">
          <h3 class="font-bold text-brand-700 sm:col-span-2">1. จังหวัดและชื่อระบบ</h3>
          <div class="sm:col-span-2"><label for="f_province">จังหวัดที่ระบบนี้ดูแล</label>
            <select id="f_province" name="province" class="w-full" required>
              <?php foreach (install_provinces() as $pv): ?><option <?= $pv === $f['province'] ? 'selected' : '' ?>><?= h($pv) ?></option><?php endforeach; ?>
            </select>
            <p class="mt-1 text-xs text-stone-500">ใช้เป็นชื่อจังหวัดในหัวเอกสาร รายงาน อนุโมทนาบัตร และข้อความใต้ชื่อระบบ</p></div>
          <div class="sm:col-span-2"><?= field('ชื่อระบบ', 'site_name', $f['site_name'], 'text', 'required maxlength="100"') ?></div>
          <div class="sm:col-span-2"><?= field('คำอธิบายใต้ชื่อระบบ', 'site_sub', $f['site_sub'], 'text', 'maxlength="150"', 'เปลี่ยนจังหวัดแล้วข้อความนี้จะเปลี่ยนตามให้ ถ้ายังไม่ได้แก้เอง') ?></div>

          <h3 class="mt-2 font-bold text-brand-700 sm:col-span-2">2. ทะเบียนวัดในจังหวัด</h3>
          <div class="grid gap-2 rounded-xl bg-gold-50 p-3 text-sm sm:col-span-2">
            <?php if ($hasBuiltin): ?>
            <label class="!mb-0 !text-stone-800"><input type="radio" name="temple_source" value="builtin" <?= $f['temple_source'] === 'builtin' ? 'checked' : '' ?>> ใช้ทะเบียนวัดจังหวัดแพร่ที่มากับชุดนี้ (381 วัด, 8 อำเภอ) <span class="text-xs text-stone-500">— เฉพาะจังหวัดแพร่</span></label>
            <?php endif; ?>
            <label class="!mb-0 !text-stone-800"><input type="radio" name="temple_source" value="file" <?= $f['temple_source'] === 'file' ? 'checked' : '' ?>> อัปโหลดไฟล์ทะเบียนวัดของจังหวัดนี้ (.xlsx จาก binfo.onab.go.th หรือ .csv)</label>
            <div class="ml-6">
              <input type="file" name="temple_file" accept=".xlsx,.csv">
              <p class="mt-1 text-xs text-stone-500">ส่งออกจาก <strong>binfo.onab.go.th → ทะเบียนวัด</strong> ของจังหวัดนั้นเป็น Excel แล้วอัปโหลดได้เลย ไม่ต้องแก้ไฟล์ — ระบบสร้างอำเภอและวัดให้ ขนาดไม่เกิน 10 MB
                (ต้องเปิดส่วนขยาย PHP <code>zip</code> สำหรับ .xlsx)</p>
              <?php if ($stage): ?><p class="mt-1 text-xs font-semibold text-emerald-700">✓ อัปโหลดแล้ว: <?= h($stage['name']) ?> — <?= (int)$stage['count'] ?> วัด <?= (int)$stage['districts'] ?> อำเภอ<?= $stage['bad'] ? ' (ข้าม ' . (int)$stage['bad'] . ' แถวที่ข้อมูลไม่ครบ)' : '' ?> (เลือกไฟล์ใหม่เพื่อแทนที่)</p><?php endif; ?>
            </div>
            <label class="!mb-0 !text-stone-800"><input type="radio" name="temple_source" value="later" <?= $f['temple_source'] === 'later' ? 'checked' : '' ?>> ยังไม่นำเข้า — ไปนำเข้าภายหลังที่ จัดการระบบ → นำเข้ารายชื่อวัด</label>
            <label class="!mb-0 mt-1 !text-stone-800"><input type="checkbox" name="make_accounts" value="1" <?= $f['make_accounts'] ? 'checked' : '' ?>> สร้างบัญชีแอดมินประจำวัดให้ทุกวัดที่นำเข้าอัตโนมัติ (1 วัด = 1 บัญชี พร้อมรหัสผ่านเริ่มต้นสุ่ม)</label>
          </div>

          <h3 class="mt-2 font-bold text-brand-700 sm:col-span-2">3. แอดมินหลัก</h3>
          <?= field('ชื่อผู้ใช้แอดมินหลัก', 'admin_user', $f['admin_user'], 'text', 'required autocomplete="off" maxlength="50"', 'a-z A-Z 0-9 _ . - (ห้ามขึ้นต้นด้วย wat)') ?>
          <?= field('ชื่อ-นามสกุล', 'admin_name', $f['admin_name'], 'text', 'required maxlength="150"') ?>
          <?= field('รหัสผ่านแอดมินหลัก', 'admin_pass', '', 'password', ($hasPw ? '' : 'required ') . 'autocomplete="new-password" minlength="10"', $hasPw ? 'เว้นว่างไว้เพื่อใช้รหัสผ่านที่ตั้งไว้ก่อนหน้า' : 'อย่างน้อย 10 ตัว มีตัวอักษรและตัวเลข') ?>
          <?= field('ยืนยันรหัสผ่าน', 'admin_pass2', '', 'password', ($hasPw ? '' : 'required ') . 'autocomplete="new-password"') ?>
          <p class="text-xs text-stone-500 sm:col-span-2">หลังติดตั้ง: เปลี่ยนตราระบบ <code>assets/img/logo.svg</code> เป็นของจังหวัดนี้ ใส่โลโก้อำเภอ/วัด และสร้างแอดมินอำเภอ/เจ้าคณะจังหวัดที่ จัดการระบบ → ผู้ใช้</p>
          <div class="flex gap-2 sm:col-span-2"><a class="btn btn-outline" href="install.php?step=2">← กลับ</a><button class="btn">ต่อไป →</button></div>
        </form>
        <script>
        (function () {
          var sel = document.getElementById('f_province'), sub = document.getElementById('f_site_sub');
          var last = 'สำหรับคณะสงฆ์จังหวัด' + sel.value;
          sel.addEventListener('change', function () {
            var next = 'สำหรับคณะสงฆ์จังหวัด' + sel.value;
            if (sub.value === last || sub.value === '') sub.value = next;
            last = next;
            var b = document.querySelector('input[name=temple_source][value=builtin]');
            if (b) { b.disabled = sel.value !== 'แพร่'; if (b.disabled && b.checked) document.querySelector('input[name=temple_source][value=file]').checked = true; }
          });
          sel.dispatchEvent(new Event('change'));
        })();
        </script>
        <?php
        break;

    case 4:
        $m = ($S['mail'] ?? []) + ['mail_driver' => 'smtp', 'smtp_host' => '', 'smtp_port' => 587, 'smtp_secure' => 'tls', 'smtp_user' => '', 'smtp_pass' => '', 'mail_from' => ''];
        $sel = fn(string $cur, string $v) => $cur === $v ? 'selected' : ''; ?>
        <h2 class="mb-1 text-xl font-bold">ตั้งค่าอีเมล (สำหรับรหัสยืนยัน 2 ชั้น)</h2>
        <p class="mb-4 text-sm text-stone-600">แอดมินประจำวัดต้องกรอกรหัส 6 หลักที่ส่งทางอีเมลของวัดก่อนเข้าสู่ระบบ — <strong>ถ้าส่งอีเมลไม่ได้ วัดจะเข้าสู่ระบบไม่ได้</strong>
          ข้ามไปตั้งค่าทีหลังได้ (แก้ <code>config.local.php</code>)</p>
        <form method="post" class="grid gap-3 sm:grid-cols-2">
          <?= csrf_input() ?>
          <button class="sr-only" name="action" value="mail_save" tabindex="-1" aria-hidden="true">ต่อไป</button><?php /* ปุ่มเริ่มต้นเมื่อกด Enter = ต่อไป (ไม่ใช่ส่งอีเมลทดสอบ) */ ?>
          <div class="sm:col-span-2"><label for="f_mail_driver">วิธีส่งอีเมล</label>
            <select id="f_mail_driver" name="mail_driver" class="w-full">
              <option value="smtp" <?= $sel($m['mail_driver'], 'smtp') ?>>SMTP (แนะนำ)</option>
              <option value="mail" <?= $sel($m['mail_driver'], 'mail') ?>>ฟังก์ชัน mail() ของเซิร์ฟเวอร์</option>
              <option value="log" <?= $sel($m['mail_driver'], 'log') ?>>เขียนลงไฟล์ (ทดสอบเท่านั้น — ไม่ปลอดภัยสำหรับใช้งานจริง)</option>
            </select></div>
          <?= field('โฮสต์ SMTP', 'smtp_host', (string)$m['smtp_host'], 'text', 'placeholder="smtp.example.org"') ?>
          <?= field('พอร์ต', 'smtp_port', (string)$m['smtp_port'], 'text', 'inputmode="numeric"') ?>
          <div><label for="f_smtp_secure">การเข้ารหัส</label>
            <select id="f_smtp_secure" name="smtp_secure" class="w-full">
              <option value="tls" <?= $sel($m['smtp_secure'], 'tls') ?>>STARTTLS (พอร์ต 587)</option>
              <option value="ssl" <?= $sel($m['smtp_secure'], 'ssl') ?>>SSL/TLS (พอร์ต 465)</option>
              <option value="none" <?= $sel($m['smtp_secure'], 'none') ?>>ไม่เข้ารหัส (ไม่แนะนำ)</option>
            </select></div>
          <?= field('อีเมลผู้ส่ง', 'mail_from', (string)$m['mail_from'], 'email', 'placeholder="no-reply@your-domain.org"') ?>
          <?= field('ชื่อผู้ใช้ SMTP', 'smtp_user', (string)$m['smtp_user'], 'text', 'autocomplete="off"') ?>
          <?= field('รหัสผ่าน SMTP', 'smtp_pass', '', 'password', 'autocomplete="new-password"', !empty($S['mail']['smtp_pass']) ? 'เว้นว่างไว้เพื่อใช้ค่าเดิม' : '') ?>
          <div class="rounded-xl bg-gold-50 p-3 sm:col-span-2">
            <div class="grid items-end gap-2 sm:grid-cols-[1fr_auto]">
              <?= field('ส่งอีเมลทดสอบไปที่', 'test_to', (string)($S['mail_test_to'] ?? ''), 'email', 'placeholder="your@email.com"') ?>
              <button class="btn btn-outline" name="action" value="mail_test" formnovalidate>ส่งอีเมลทดสอบ</button>
            </div>
          </div>
          <div class="flex gap-2 sm:col-span-2"><a class="btn btn-outline" href="install.php?step=3">← กลับ</a><button class="btn" name="action" value="mail_save">ต่อไป →</button></div>
        </form>
        <?php
        break;

    default:
        $db = $S['db'] ?? null; $site = $S['site'] ?? null; $mail = $S['mail'] ?? null;
        if (!$db || !$site || !$mail) {
            go(1);
        } ?>
        <h2 class="mb-1 text-xl font-bold">ตรวจสอบและติดตั้ง</h2>
        <dl class="mb-4 grid grid-cols-[10rem_1fr] gap-y-1.5 text-sm">
          <dt class="text-stone-500">ฐานข้อมูล</dt><dd><?= h($db['db_user']) ?>@<?= h($db['db_host']) ?>:<?= h($db['db_port']) ?> / <strong><?= h($db['db_name']) ?></strong></dd>
          <dt class="text-stone-500">จังหวัดที่ดูแล</dt><dd><strong><?= h($site['province']) ?></strong></dd>
          <dt class="text-stone-500">ชื่อระบบ</dt><dd><?= h($site['site_name']) ?></dd>
          <dt class="text-stone-500">แอดมินหลัก</dt><dd class="font-mono"><?= h($site['admin_user']) ?> <span class="font-sans text-stone-500">(<?= h($site['admin_name']) ?>)</span></dd>
          <dt class="text-stone-500">ทะเบียนวัด</dt><dd><?php if ($site['temple_source'] === 'builtin'): ?>ใช้ทะเบียนที่มากับชุด (381 วัด)<?php elseif ($site['temple_source'] === 'file' && !empty($site['stage'])): ?>นำเข้าจากไฟล์ <?= h($site['stage']['name']) ?> (<?= (int)$site['stage']['count'] ?> วัด <?= (int)$site['stage']['districts'] ?> อำเภอ)<?php else: ?>ยังไม่นำเข้า (ไปนำเข้าที่ จัดการระบบ → นำเข้ารายชื่อวัด)<?php endif; ?></dd>
          <dt class="text-stone-500">บัญชีวัด</dt><dd><?= $site['make_accounts'] ? 'สร้างอัตโนมัติ 1 วัด 1 บัญชี' : 'ไม่สร้าง (สร้างภายหลังได้ในหน้าจัดการระบบ)' ?></dd>
          <dt class="text-stone-500">อีเมล</dt><dd><?= h(['smtp' => 'SMTP', 'mail' => 'mail()', 'log' => 'เขียนลงไฟล์ (ทดสอบ)'][$mail['mail_driver']]) ?><?= $mail['mail_driver'] === 'smtp' ? ' — ' . ($mail['smtp_host'] !== '' ? h($mail['smtp_host']) : '<span class="text-red-700">ยังไม่ได้ตั้งค่า (วัดจะเข้าสู่ระบบไม่ได้จนกว่าจะตั้งค่า)</span>') : '' ?></dd>
        </dl>
        <form method="post" class="flex flex-wrap items-center gap-2" onsubmit="this.querySelector('button.btn').disabled=true;this.querySelector('button.btn').textContent='กำลังติดตั้ง… (ประมาณ 30 วินาที)';">
          <?= csrf_input() ?><input type="hidden" name="action" value="install">
          <a class="btn btn-outline" href="install.php?step=4">← กลับ</a>
          <button class="btn">ติดตั้งเลย</button>
        </form>
        <?php
}
page('ขั้นที่ ' . $step, $step, ob_get_clean());
