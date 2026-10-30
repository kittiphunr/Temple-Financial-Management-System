<?php
/**
 * นำเข้ารายชื่อวัด (แอดมินหลัก): Excel (.xlsx) ทะเบียนวัดจาก binfo.onab.go.th หรือ CSV
 * ขั้นตอน: อัปโหลด -> ตรวจไฟล์และดูสรุป (ยังไม่บันทึก) -> ยืนยันนำเข้า
 * ใช้กับจังหวัดอื่นได้: สร้างอำเภอใหม่จากไฟล์ ตั้งชื่อจังหวัดของระบบ และลบอำเภอตัวอย่างเดิมที่ไม่มีวัด
 */
require __DIR__ . '/../includes/bootstrap.php';
$me = require_admin();
require __DIR__ . '/../includes/temple_import.php';
$pdo = db();
$dir = dirname(__DIR__) . '/storage';
$result = null;

/** ลบไฟล์พักข้อมูลนำเข้าที่เก่ากว่า 2 ชั่วโมง */
foreach (glob($dir . '/import_*.json') ?: [] as $old) {
    if (filemtime($old) < time() - 7200) {
        @unlink($old);
    }
}
$tokenFile = fn(string $t) => preg_match('/^[a-f0-9]{24}$/', $t) ? "$dir/import_$t.json" : null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $act = $_POST['action'] ?? 'preview';

    if ($act === 'preview') {
        $f = $_FILES['file'] ?? null;
        $isXlsx = $f && strtolower(pathinfo((string)$f['name'], PATHINFO_EXTENSION)) === 'xlsx';
        if (!$f || $f['error'] !== UPLOAD_ERR_OK || $f['size'] > 10 * 1024 * 1024 || !is_uploaded_file($f['tmp_name'])) {
            flash('อัปโหลดไฟล์ไม่สำเร็จ (ขนาดไม่เกิน 10 MB)', 'err');
            redirect('import.php');
        }
        try {
            $parsed = $isXlsx ? temple_import_parse_xlsx($f['tmp_name']) : temple_import_parse_csv($f['tmp_name']);
        } catch (RuntimeException $e) {
            flash($e->getMessage(), 'err');
            redirect('import.php');
        }
        if (!$parsed['rows']) {
            flash('ไม่พบรายชื่อวัดที่นำเข้าได้ในไฟล์', 'err');
            redirect('import.php');
        }
        $token = bin2hex(random_bytes(12));
        $parsed['name'] = basename((string)$f['name']);
        $parsed['kind'] = $isXlsx ? 'Excel (ทะเบียนวัด)' : 'CSV';
        $parsed['verified'] = isset($_POST['verified']);
        $parsed['set_emails'] = isset($_POST['set_emails']);
        file_put_contents("$dir/import_$token.json", json_encode($parsed, JSON_UNESCAPED_UNICODE), LOCK_EX);
        @chmod("$dir/import_$token.json", 0600);
        $_SESSION['import_token'] = $token;
        redirect('import.php?review=' . $token);
    }

    if ($act === 'cancel') {
        $file = $tokenFile((string)($_POST['token'] ?? ''));
        $file && is_file($file) && @unlink($file);
        unset($_SESSION['import_token']);
        redirect('import.php');
    }

    if ($act === 'confirm') {
        $token = (string)($_POST['token'] ?? '');
        $file = $tokenFile($token);
        if (!$file || !is_file($file) || !hash_equals((string)($_SESSION['import_token'] ?? ''), $token)) {
            flash('หมดเวลาหรือไม่พบข้อมูลที่ตรวจไว้ กรุณาอัปโหลดไฟล์ใหม่', 'err');
            redirect('import.php');
        }
        $parsed = json_decode((string)file_get_contents($file), true);
        @unlink($file);
        unset($_SESSION['import_token']);
        $rows = $parsed['rows'] ?? [];
        $res = temple_import_apply($pdo, $rows, [
            'verified' => !empty($parsed['verified']), 'source' => 'นำเข้า ' . ($parsed['kind'] ?? 'ไฟล์') . ' ' . mb_substr((string)($parsed['name'] ?? ''), 0, 60),
            'create_districts' => isset($_POST['create_districts']), 'set_emails' => !empty($parsed['set_emails']),
        ]);
        $pruned = [];
        $newProvince = trim((string)($_POST['province'] ?? ''));
        if (isset($_POST['set_province']) && $newProvince !== '' && mb_strlen($newProvince) <= 60) {
            setting_set('province_name', $newProvince);
            setting_set('app_sub', 'สำหรับคณะสงฆ์จังหวัด' . $newProvince);
        }
        if (isset($_POST['prune_districts'])) {
            $pruned = districts_prune_empty($pdo);
        }
        // วัดที่เพิ่งนำเข้าได้บัญชีอัตโนมัติ (1 วัด = 1 บัญชี) รหัสผ่านเริ่มต้นดาวน์โหลดได้ครั้งเดียวที่หน้า บัญชีวัด
        $created = provision_temple_accounts($pdo);
        if ($created) {
            $_SESSION['new_creds'] = array_merge($_SESSION['new_creds'] ?? [], $created);
        }
        $res['accounts'] = count($created);
        $res['pruned'] = $pruned;
        $res['bad'] = array_merge($parsed['bad'] ?? [], $res['skipped']);
        audit('import', 'temple', null, 'new=' . $res['inserted'] . ' upd=' . $res['updated'] . ' districts=' . count($res['districts_created']) . ' pruned=' . count($pruned) . ' bad=' . count($res['bad']));
        $_SESSION['import_result'] = $res;
        redirect('import.php?done=1');
    }

    // นำเข้า CSV แบบเดิมถูกแทนที่ด้วยขั้นตอนตรวจก่อนนำเข้า
    redirect('import.php');
}

$result = null;
if (isset($_GET['done'])) {
    $result = $_SESSION['import_result'] ?? null;
    unset($_SESSION['import_result']);
}
$review = null;
$rtoken = (string)($_GET['review'] ?? '');
if ($rtoken !== '' && ($rf = $tokenFile($rtoken)) && is_file($rf) && hash_equals((string)($_SESSION['import_token'] ?? ''), $rtoken)) {
    $review = json_decode((string)file_get_contents($rf), true);
    $review['preview'] = temple_import_preview($pdo, $review['rows']);
    $current = province_name();
    $fileProvince = ($review['province'][0] ?? '');
    $provinceDiffers = $fileProvince !== '' && $fileProvince !== $current;
    $anyNewDistrict = (bool)array_filter($review['preview']['districts'], fn($d) => !$d['exists']);
}

$pageTitle = 'นำเข้ารายชื่อวัด';
require __DIR__ . '/../includes/header.php';
?>
<h2>นำเข้ารายชื่อวัด (Excel / CSV)</h2>

<?php if ($result): ?>
  <div class="mb-4 rounded-lg px-4 py-2.5 bg-emerald-50 text-emerald-900">
    นำเข้าสำเร็จ: วัดใหม่ <strong><?= (int)$result['inserted'] ?></strong> วัด · อัปเดต <strong><?= (int)$result['updated'] ?></strong> วัด
    <?php if ($result['districts_created']): ?> · สร้างอำเภอใหม่ <?= count($result['districts_created']) ?> อำเภอ (<?= e(implode(', ', $result['districts_created'])) ?>)<?php endif; ?>
    <?php if ($result['pruned']): ?> · ลบอำเภอที่ไม่มีวัด <?= count($result['pruned']) ?> อำเภอ (<?= e(implode(', ', $result['pruned'])) ?>)<?php endif; ?>
    <?php if ($result['emails']): ?> · ลงอีเมลวัด <?= (int)$result['emails'] ?> วัด<?php endif; ?>
    <?php if ($result['accounts']): ?><br>สร้างบัญชีแอดมินวัดใหม่ <?= (int)$result['accounts'] ?> บัญชี — <a class="font-semibold underline" href="temple_accounts.php">ไปดาวน์โหลดรหัสผ่านเริ่มต้นที่หน้า บัญชีวัด</a> (ดาวน์โหลดได้ครั้งเดียว)<?php endif; ?>
    <br><span class="text-sm">ขั้นต่อไป: <a class="underline" href="districts.php">ใส่โลโก้อำเภอ</a> · <a class="underline" href="users.php">สร้างแอดมินอำเภอ/เจ้าคณะจังหวัด (โควตาคิดจากจำนวนอำเภอ + 2)</a> · <a class="underline" href="temple_accounts.php">ลงอีเมลวัด</a></span>
  </div>
  <?php if ($result['bad']): ?><div class="mb-4 rounded-lg px-4 py-2.5 bg-red-50 text-red-900">ข้ามรายการ <?= count($result['bad']) ?> รายการ:<br><?= e(implode(' | ', array_slice($result['bad'], 0, 20))) ?></div><?php endif; ?>
<?php endif; ?>

<?php if ($review): $pv = $review['preview']; ?>
<div class="rounded-2xl border border-stone-200 bg-white p-4 shadow-sm">
  <h3>ตรวจไฟล์ก่อนนำเข้า <span class="text-base font-normal text-stone-500">— <?= e($review['name']) ?> (<?= e($review['kind']) ?>)</span></h3>
  <p class="mb-3 text-sm text-stone-600">ยังไม่มีการบันทึกใด ๆ ตรวจสรุปด้านล่างแล้วกด "ยืนยันนำเข้า" (ไม่ลบข้อมูลเดิมของระบบ: วัดที่มีอยู่แล้วจะถูกอัปเดตเฉพาะช่องที่ไฟล์มีค่า)</p>
  <div class="mb-3 grid gap-2 sm:grid-cols-4">
    <div class="rounded-xl border border-stone-200 p-3"><div class="text-xs text-stone-500">วัดที่นำเข้าได้</div><div class="text-2xl font-bold"><?= count($review['rows']) ?></div></div>
    <div class="rounded-xl border border-emerald-200 bg-emerald-50 p-3"><div class="text-xs text-stone-500">วัดใหม่</div><div class="text-2xl font-bold text-emerald-800"><?= (int)$pv['new'] ?></div></div>
    <div class="rounded-xl border border-sky-200 bg-sky-50 p-3"><div class="text-xs text-stone-500">มีอยู่แล้ว (อัปเดต)</div><div class="text-2xl font-bold text-sky-800"><?= (int)$pv['update'] ?></div></div>
    <div class="rounded-xl border <?= $review['bad'] ? 'border-red-200 bg-red-50' : 'border-stone-200' ?> p-3"><div class="text-xs text-stone-500">ข้าม (ไม่ถูกต้อง/ซ้ำ)</div><div class="text-2xl font-bold"><?= count($review['bad']) ?></div></div>
  </div>
  <?php if ($review['bad']): ?><p class="mb-3 rounded-lg bg-red-50 px-3 py-2 text-sm text-red-900"><?= e(implode(' | ', array_slice($review['bad'], 0, 10))) ?><?= count($review['bad']) > 10 ? ' …' : '' ?></p><?php endif; ?>
  <?php if ($review['province']): ?>
  <p class="mb-3 text-sm">จังหวัดในไฟล์: <strong><?= e(implode(', ', $review['province'])) ?></strong> · จังหวัดที่ตั้งไว้ในระบบ: <strong><?= e($current) ?></strong>
    <?php if ($provinceDiffers): ?><span class="badge">ต่างกัน</span><?php endif; ?></p>
  <?php endif; ?>
  <div class="mb-4 overflow-x-auto rounded-xl border border-stone-200"><table class="data-table">
    <tr><th>อำเภอ</th><th class="text-right">จำนวนวัดในไฟล์</th><th>สถานะในระบบ</th></tr>
    <?php foreach ($pv['districts'] as $d): ?>
    <tr><td><?= e($d['name']) ?></td><td class="text-right"><?= (int)$d['count'] ?></td><td><?= $d['exists'] ? 'มีอยู่แล้ว' : '<span class="text-emerald-800">อำเภอใหม่ (สร้างให้)</span>' ?></td></tr>
    <?php endforeach; ?>
  </table></div>
  <form method="post" class="grid gap-2">
    <?= csrf_field() ?><input type="hidden" name="action" value="confirm"><input type="hidden" name="token" value="<?= e($rtoken) ?>"><input type="hidden" name="province" value="<?= e($fileProvince) ?>">
    <label><input type="checkbox" name="create_districts" <?= $anyNewDistrict ? 'checked' : '' ?>> สร้างอำเภอที่ยังไม่มีในระบบจากไฟล์ (ถ้าไม่ติ๊ก วัดในอำเภอใหม่จะถูกข้าม)</label>
    <?php if ($provinceDiffers): ?>
    <label><input type="checkbox" name="set_province" checked> ตั้งชื่อจังหวัดของระบบเป็น "<?= e($fileProvince) ?>" (ใช้ในหัวเอกสาร รายงาน และข้อความใต้ชื่อระบบ)</label>
    <?php endif; ?>
    <label><input type="checkbox" name="prune_districts" <?= $provinceDiffers ? 'checked' : '' ?>> ลบอำเภอที่ไม่มีวัดและไม่มีผู้ใช้หลังนำเข้า (เช่น อำเภอตัวอย่างของจังหวัดแพร่ที่ติดมากับระบบ)</label>
    <?php if ($review['set_emails'] || $pv['emails']): ?>
    <p class="text-sm text-stone-600">อีเมลวัดในไฟล์: <?= (int)$pv['emails'] ?> วัด — <?= $review['set_emails'] ? 'จะลงอีเมลให้เฉพาะวัดที่ยังไม่มีอีเมล' : 'ไม่นำเข้า (ไม่ได้ติ๊กตอนอัปโหลด)' ?></p>
    <?php endif; ?>
    <div class="mt-2 flex flex-wrap gap-2"><button class="btn">ยืนยันนำเข้า <?= count($review['rows']) ?> วัด</button></div>
  </form>
  <form method="post" class="mt-2"><?= csrf_field() ?><input type="hidden" name="action" value="cancel"><input type="hidden" name="token" value="<?= e($rtoken) ?>"><button class="btn btn-outline btn-sm">ยกเลิก</button></form>
</div>
<?php else: ?>
<div class="rounded-2xl border border-stone-200 bg-white p-4 shadow-sm">
  <h3>นำเข้าจากไฟล์ Excel ของสำนักงานพระพุทธศาสนาแห่งชาติ</h3>
  <p class="text-sm text-stone-700">ใช้เริ่มต้นระบบในจังหวัดใดก็ได้: ส่งออกรายงานทะเบียนวัดของจังหวัดนั้นเป็น Excel (.xlsx) จาก
    <a href="https://binfo.onab.go.th/Temple/Temple-List-view.aspx" target="_blank" rel="noopener noreferrer">binfo.onab.go.th → ทะเบียนวัด</a> แล้วอัปโหลดที่นี่ ไม่ต้องแก้ไฟล์
    ระบบอ่านคอลัมน์จากหัวตารางเอง (รหัสวัด, ชื่อวัด, ประเภทวัด, นิกาย, วันที่ตั้งวัด, แขวง/ตำบล, เขต/อำเภอ, จังหวัด, รหัสไปรษณีย์, เบอร์โทรศัพท์, อีเมล์, ชื่อหมู่บ้าน, บ้านเลขที่, หมู่, ซอย, ถนน)
    สร้างอำเภอและวัดให้ และสร้างบัญชีแอดมินวัดอัตโนมัติ (1 วัด 1 บัญชี) หรืออัปโหลดไฟล์ CSV ตามแบบ <code>data/temples_template.csv</code> ก็ได้ — ทั้งสองแบบมีขั้นตอนตรวจก่อนบันทึก</p>
  <?php if (!xlsx_available()): ?><p class="mt-2 rounded-lg bg-amber-50 px-3 py-2 text-sm text-amber-950">เซิร์ฟเวอร์ยังไม่ได้เปิดส่วนขยาย PHP <code>zip</code> จึงอ่านไฟล์ .xlsx ไม่ได้ (ติดตั้ง php-zip) — ใช้ไฟล์ CSV แทนได้</p><?php endif; ?>
  <form method="post" enctype="multipart/form-data" class="mt-3 grid gap-2">
    <?= csrf_field() ?><input type="hidden" name="action" value="preview">
    <div><input type="file" name="file" accept=".xlsx,.csv,text/csv,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet" required></div>
    <label><input type="checkbox" name="verified" checked> ไฟล์นี้มาจากทะเบียนวัดอย่างเป็นทางการ (ทำเครื่องหมาย "ตรวจสอบแล้ว")</label>
    <label><input type="checkbox" name="set_emails"> นำเข้าอีเมลวัดจากคอลัมน์ "อีเมล์" ด้วย (ลงให้เฉพาะวัดที่ยังไม่มีอีเมล — ระบบส่งรหัสยืนยัน 2FA ไปที่อีเมลนี้ ตรวจว่าเป็นอีเมลที่ถูกต้องก่อน)</label>
    <div><button class="btn">ตรวจไฟล์และดูสรุปก่อนนำเข้า</button></div>
  </form>
</div>
<div class="mt-4 rounded-2xl border border-stone-200 bg-white p-4 text-sm text-stone-600 shadow-sm">
  <h3>รูปแบบ CSV (UTF-8)</h3>
  คอลัมน์ตามลำดับ: <code>อำเภอ, ชื่อวัด, ตำบล, หมู่ที่, เจ้าอาวาส, โทรศัพท์, รหัสวัด, ประเภทวัด, นิกาย, ชื่อหมู่บ้าน, บ้านเลขที่, ซอย, ถนน, รหัสไปรษณีย์, ปีที่ตั้งวัด(พ.ศ.)</code> (คอลัมน์ที่ 7 เป็นต้นไปไม่บังคับ) ตัวอย่างที่ <code>data/temples_template.csv</code>
  — วัดที่รหัสวัดซ้ำ หรือชื่อ+ตำบลซ้ำ จะถูกอัปเดตแทนการเพิ่มใหม่
</div>
<?php endif; ?>
<?php require __DIR__ . '/../includes/footer.php'; ?>
