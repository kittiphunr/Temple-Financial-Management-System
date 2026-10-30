<?php
/** สำรองข้อมูล (แอดมินหลัก): สร้าง/ดาวน์โหลด/ลบไฟล์สำรอง ตั้งสำรองอัตโนมัติ และส่งออกกุญแจระบบแบบเข้ารหัส */
require __DIR__ . '/../includes/bootstrap.php';
$u = require_admin();
$pdo = db();

// ดาวน์โหลดไฟล์สำรอง (ชื่อไฟล์ต้องตรงรูปแบบที่ระบบสร้างเท่านั้น กัน path traversal)
if (isset($_GET['download'])) {
    $name = (string)$_GET['download'];
    $path = backup_dir() . '/' . $name;
    if (!backup_valid_name($name) || !is_file($path)) {
        http_response_code(404);
        exit('ไม่พบไฟล์สำรอง');
    }
    audit('backup_download', 'system', null, $name);
    header_remove('Pragma');
    header_remove('Expires');
    header('Cache-Control: private, no-store');
    header('Content-Type: application/gzip');
    header('Content-Length: ' . filesize($path));
    header('Content-Disposition: attachment; filename="' . $name . '"');
    header('X-Content-Type-Options: nosniff');
    readfile($path);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $act = $_POST['action'] ?? '';
    if ($act === 'create') {
        try {
            $r = backup_create($pdo);
            backup_prune(max(1, (int)setting('backup_keep', '14')));
            setting_set('backup_last', (string)time());
            audit('backup_create', 'system', null, $r['name']);
            flash('สร้างไฟล์สำรองแล้ว: ' . $r['name'] . ' (' . number_format($r['size'] / 1024, 1) . ' KB, ' . $r['tables'] . ' ตาราง, ' . number_format($r['rows']) . ' แถว)');
        } catch (Throwable $e) {
            flash('สร้างไฟล์สำรองไม่สำเร็จ: ' . $e->getMessage(), 'err');
        }
    } elseif ($act === 'delete' && backup_valid_name((string)($_POST['name'] ?? '')) && @unlink(backup_dir() . '/' . $_POST['name'])) {
        audit('backup_delete', 'system', null, (string)$_POST['name']);
        flash('ลบไฟล์สำรองแล้ว');
    } elseif ($act === 'settings') {
        $days = (int)($_POST['auto_days'] ?? 0);
        $keep = (int)($_POST['keep'] ?? 14);
        if (!in_array($days, [0, 1, 3, 7, 14, 30], true) || $keep < 1 || $keep > 100) {
            flash('ค่าที่ตั้งไม่ถูกต้อง', 'err');
        } else {
            setting_set('backup_auto_days', (string)$days);
            setting_set('backup_keep', (string)$keep);
            audit('backup_settings', 'system', null, "days $days keep $keep");
            flash($days ? "ตั้งสำรองอัตโนมัติทุก $days วัน เก็บ $keep ไฟล์ล่าสุดแล้ว" : 'ปิดการสำรองอัตโนมัติแล้ว');
        }
    } elseif ($act === 'key') {
        $pass = (string)($_POST['passphrase'] ?? '');
        $me = $pdo->prepare('SELECT password_hash FROM users WHERE id = ?');
        $me->execute([(int)$u['id']]);
        if (!password_verify((string)($_POST['password'] ?? ''), (string)$me->fetchColumn())) {
            flash('รหัสผ่านปัจจุบันของคุณไม่ถูกต้อง', 'err');
        } elseif ($pass !== (string)($_POST['passphrase2'] ?? '')) {
            flash('รหัสผ่านสำรองสองช่องไม่ตรงกัน', 'err');
        } elseif (mb_strlen($pass) < 12) {
            flash('รหัสผ่านสำรองต้องยาวอย่างน้อย 12 ตัวอักษร', 'err');
        } else {
            audit('backup_key_export', 'system');
            header_remove('Pragma');
            header_remove('Expires');
            header('Cache-Control: private, no-store');
            header('Content-Type: application/json; charset=utf-8');
            header('Content-Disposition: attachment; filename="tfms-app-key-' . date('Ymd') . '.json"');
            header('X-Content-Type-Options: nosniff');
            echo backup_key_export($pass);
            exit;
        }
    }
    redirect('backup.php');
}

$list = backup_list();
$days = (int)setting('backup_auto_days', '0');
$keep = (int)setting('backup_keep', '14');
$last = (int)setting('backup_last', '0');
$root = dirname(__DIR__);
$pageTitle = 'สำรองข้อมูล';
require __DIR__ . '/../includes/header.php';
?>
<h2>สำรองข้อมูล</h2>
<p class="mb-3 text-sm text-stone-600">ไฟล์สำรองคือสำเนาฐานข้อมูลทั้งหมด (บัญชี ผู้ใช้ รูป หลักฐานแนบ ฯลฯ) เก็บใน <code>storage/backups/</code> ซึ่งปิดไม่ให้เปิดจากเว็บ ควรดาวน์โหลดเก็บไว้นอกเซิร์ฟเวอร์เป็นประจำ
  ข้อมูลอ่อนไหว (เลขบัตรประชาชน ที่อยู่ผู้บริจาค) ในไฟล์สำรองเข้ารหัสด้วย <strong>กุญแจระบบ</strong> — ต้องเก็บกุญแจสำรองแยกต่างหากด้านล่าง ไม่เช่นนั้นกู้คืนแล้วจะอ่านข้อมูลเหล่านั้นไม่ได้</p>

<section class="rounded-2xl border border-stone-200 bg-white p-4 shadow-sm">
  <div class="flex flex-wrap items-center justify-between gap-2"><h3 class="!mb-0">ไฟล์สำรอง (<?= count($list) ?>)</h3>
    <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="create"><button class="btn btn-sm">สร้างไฟล์สำรองตอนนี้</button></form></div>
  <p class="mt-1 text-xs text-stone-500">สำรองล่าสุด: <?= $last ? e(th_datetime(date('Y-m-d H:i:s', $last))) : 'ยังไม่เคย' ?></p>
  <div class="mt-2 overflow-x-auto"><table class="data-table">
    <tr><th>ไฟล์</th><th>สร้างเมื่อ</th><th class="text-right">ขนาด</th><th></th></tr>
    <?php foreach ($list as $b): ?>
    <tr><td class="font-mono text-sm"><?= e($b['name']) ?></td><td class="whitespace-nowrap"><?= e(th_datetime(date('Y-m-d H:i:s', $b['mtime']))) ?></td><td class="text-right"><?= number_format($b['size'] / 1024, 1) ?> KB</td>
      <td class="whitespace-nowrap"><a class="btn btn-sm btn-outline" href="backup.php?download=<?= e(rawurlencode($b['name'])) ?>">ดาวน์โหลด</a>
        <form method="post" class="inline" data-confirm="ลบไฟล์สำรองนี้?"><?= csrf_field() ?><input type="hidden" name="action" value="delete"><input type="hidden" name="name" value="<?= e($b['name']) ?>"><button class="btn btn-sm btn-danger">ลบ</button></form></td></tr>
    <?php endforeach; ?>
    <?php if (!$list): ?><tr><td colspan="4" class="text-stone-500">ยังไม่มีไฟล์สำรอง</td></tr><?php endif; ?>
  </table></div>
</section>

<section class="mt-4 rounded-2xl border border-stone-200 bg-white p-4 shadow-sm">
  <h3>สำรองอัตโนมัติ</h3>
  <form method="post" class="flex flex-wrap items-end gap-3">
    <?= csrf_field() ?><input type="hidden" name="action" value="settings">
    <div><label>สำรองทุก</label><select name="auto_days"><?php foreach ([0 => 'ปิด', 1 => 'ทุกวัน', 3 => 'ทุก 3 วัน', 7 => 'ทุกสัปดาห์', 14 => 'ทุก 2 สัปดาห์', 30 => 'ทุกเดือน'] as $d => $lab): ?><option value="<?= $d ?>" <?= $days === $d ? 'selected' : '' ?>><?= e($lab) ?></option><?php endforeach; ?></select></div>
    <div><label>เก็บไฟล์ล่าสุด (ไฟล์)</label><input type="number" name="keep" min="1" max="100" value="<?= $keep ?>" class="w-24"></div>
    <button class="btn btn-sm">บันทึก</button>
  </form>
  <p class="mt-2 text-sm text-stone-600">ระบบสำรองให้เองเมื่อแอดมินหลักเปิดหน้า "จัดการระบบ" และถึงกำหนด · ถ้าเซิร์ฟเวอร์ตั้ง cron ได้ แนะนำให้ใช้ cron แทน (สำรองตรงเวลา ไม่ต้องรอใครเปิดหน้า):</p>
  <pre class="mt-1 overflow-x-auto rounded-lg bg-stone-100 p-2 text-xs">0 2 * * * cd <?= e($root) ?> &amp;&amp; php backup.php --keep=<?= $keep ?></pre>
</section>

<section class="mt-4 rounded-2xl border border-stone-200 bg-white p-4 shadow-sm">
  <h3>สำรองกุญแจระบบ (แยกจากไฟล์สำรองข้อมูล)</h3>
  <p class="mb-2 text-sm text-stone-600">ดาวน์โหลดกุญแจระบบ (<code>storage/app.key</code>) แบบ<strong>เข้ารหัสด้วยรหัสผ่านสำรอง</strong> ที่คุณตั้งเอง เก็บไฟล์นี้และจดรหัสผ่านสำรองไว้คนละที่กับไฟล์สำรองข้อมูล ถ้าทำกุญแจหาย เลขบัตรประชาชนและข้อมูลที่เข้ารหัสจะกู้คืนไม่ได้อีก</p>
  <form method="post" class="grid gap-3 sm:grid-cols-3 [&_input]:w-full" autocomplete="off">
    <?= csrf_field() ?><input type="hidden" name="action" value="key">
    <div><label>รหัสผ่านสำรองของกุญแจ (อย่างน้อย 12 ตัวอักษร)</label><input type="password" name="passphrase" minlength="12" required autocomplete="new-password"></div>
    <div><label>ยืนยันรหัสผ่านสำรอง</label><input type="password" name="passphrase2" minlength="12" required autocomplete="new-password"></div>
    <div><label>รหัสผ่านเข้าสู่ระบบของคุณ (ยืนยันตัวตน)</label><input type="password" name="password" required autocomplete="current-password"></div>
    <div class="col-span-full"><button class="btn btn-sm">ดาวน์โหลดไฟล์กุญแจ (เข้ารหัส)</button></div>
  </form>
</section>

<section class="mt-4 rounded-2xl border border-stone-200 bg-white p-4 text-sm text-stone-700 shadow-sm">
  <h3>กู้คืนข้อมูล</h3>
  <p>กู้คืนทำผ่านบรรทัดคำสั่งเท่านั้น (ป้องกันการเขียนทับข้อมูลโดยไม่ตั้งใจ):</p>
  <pre class="mt-1 overflow-x-auto rounded-lg bg-stone-100 p-2 text-xs">php restore_backup.php storage/backups/ไฟล์.sql.gz --yes --key=tfms-app-key-วันที่.json</pre>
  <p class="mt-1">คำสั่งนี้ลบและสร้างตารางในฐานข้อมูลที่ตั้งค่าไว้ใหม่ทั้งหมด · <code>--key</code> ใช้เมื่อกู้คืนลงเซิร์ฟเวอร์ใหม่ที่ยังไม่มีกุญแจ (ถามรหัสผ่านสำรองตอนรัน)</p>
</section>
<?php require __DIR__ . '/../includes/footer.php'; ?>
