<?php
/** ข้อมูลวัด: โลโก้ (ใช้ในเอกสาร) และที่อยู่/เบอร์โทร — แอดมินวัดแก้ของวัดตน, แอดมินหลักแก้ได้ทุกวัด */
require __DIR__ . '/includes/bootstrap.php';
$u = require_login();
$pdo = db();

if ($u['role'] === 'temple') {
    $tid = (int)$u['temple_id'];
} elseif ($u['role'] === 'admin') {
    $tid = (int)($_GET['temple'] ?? 0);
} else {
    http_response_code(403);
    exit('หน้านี้สำหรับแอดมินวัดและแอดมินหลักเท่านั้น');
}
$t = $tid ? temple_row($tid) : null;
if (!$t) {
    flash('เลือกวัดที่ต้องการแก้ไขข้อมูลจากหน้า จัดการวัด', 'err');
    redirect(BASE . 'admin/temples.php');
}
$self = 'profile.php' . ($u['role'] === 'admin' ? '?temple=' . $tid : '');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $act = $_POST['action'] ?? '';
    if ($act === 'save_profile') {
        $f = fn(string $k, int $max) => mb_substr(trim($_POST[$k] ?? ''), 0, $max) ?: null;
        $phone = preg_replace('/[^0-9+]/', '', $_POST['phone'] ?? '');
        $post = trim($_POST['postcode'] ?? '');
        $taxId = preg_replace('/[\s-]/', '', $_POST['tax_id'] ?? '');
        if ($phone !== '' && !preg_match('/^\+?\d{9,15}$/', $phone)) {
            flash('เบอร์โทรศัพท์ไม่ถูกต้อง (9–15 หลัก)', 'err');
        } elseif ($post !== '' && !preg_match('/^\d{5}$/', $post)) {
            flash('รหัสไปรษณีย์ต้องเป็นตัวเลข 5 หลัก', 'err');
        } elseif ($taxId !== '' && !preg_match('/^\d{13}$/', $taxId)) {
            flash('เลขประจำตัวผู้เสียภาษีต้องเป็นตัวเลข 13 หลัก', 'err');
        } else {
            $pdo->prepare('UPDATE temples SET phone=?, village=?, house_no=?, moo=?, soi=?, road=?, postcode=?, tax_id=? WHERE id=?')
                ->execute([$phone ?: null, $f('village', 100), $f('house_no', 50), $f('moo', 20), $f('soi', 100), $f('road', 100), $post ?: null, $taxId ?: null, $tid]);
            audit('update', 'temple_profile', $tid);
            flash('บันทึกข้อมูลวัดแล้ว');
        }
    } elseif ($act === 'logo_upload') {
        [$mid, $err] = media_save_upload($pdo, $_FILES['logo'] ?? null, 'logo', $u['id']);
        if ($err !== '') {
            flash($err, 'err');
        } elseif (!$mid) {
            flash('กรุณาเลือกไฟล์โลโก้ก่อน', 'err');
        } else {
            $old = $t['logo_media_id'] ? (int)$t['logo_media_id'] : null;
            $pdo->prepare('UPDATE temples SET logo_media_id = ? WHERE id = ?')->execute([$mid, $tid]);
            media_delete($pdo, $old);
            audit('logo_upload', 'temple', $tid);
            flash('อัปโหลดโลโก้วัดเรียบร้อยแล้ว จะแสดงในเอกสารที่พิมพ์จากระบบ');
        }
    } elseif ($act === 'logo_remove' && $t['logo_media_id']) {
        $pdo->prepare('UPDATE temples SET logo_media_id = NULL WHERE id = ?')->execute([$tid]);
        media_delete($pdo, (int)$t['logo_media_id']);
        audit('logo_remove', 'temple', $tid);
        flash('ลบโลโก้แล้ว');
    }
    redirect($self);
}

$d = $pdo->prepare('SELECT name FROM districts WHERE id = ?');
$d->execute([$t['district_id']]);
$districtName = $d->fetchColumn();
$check = temple_checklist($pdo, $tid);
$peopleUrl = 'people.php?temple=' . $tid;

$pageTitle = 'ข้อมูลวัด';
require __DIR__ . '/includes/header.php';
?>
<h2><?= e($t['name']) ?> <span class="text-lg font-semibold text-stone-600"><?= e(unit_district()) ?><?= e($districtName) ?></span></h2>

<div class="card">
  <h3>สิ่งที่ควรกรอกให้ครบ</h3>
  <ul class="grid gap-1.5 text-sm">
    <?php foreach ([
        [$check['logo'], 'โลโก้วัด', 'จำเป็นสำหรับการออกเอกสาร — ใส่ด้านล่าง', '#logo'],
        [$check['abbot'], 'ข้อมูลเจ้าอาวาส', 'ชื่อ เลขบัตรประชาชน ที่อยู่ภูมิลำเนาเดิม เบอร์โทร รูปถ่าย', $peopleUrl],
        [$check['steward'], 'ข้อมูลไวยาวัจกร', 'ผู้มีหน้าที่บริหารวัด (เพิ่มได้หลายท่าน)', $peopleUrl],
        [$check['phone'], 'โทรศัพท์และที่อยู่วัด', 'ใช้เป็นหัวเอกสารอนุโมทนาบัตรและรายงาน — กรอกด้านล่าง', '#contact'],
        [$check['tax_id'], 'เลขประจำตัวผู้เสียภาษีของวัด', 'พิมพ์ในอนุโมทนาบัตร (ถ้ามี) — กรอกด้านล่าง', '#contact'],
        [$check['cash_opening'], 'ยอดยกมาเงินสด/เช็ค', 'ใช้ออกรายงานแบบที่ 1 และ 3 — กรอกที่ "บัญชีของวัด" ด้านล่าง', '#accounts'],
        [$check['bank'], 'บัญชีเงินฝากธนาคาร', 'ธนาคาร สาขา เลขที่บัญชี ยอดยกมา (ถ้าวัดมีบัญชีธนาคาร)', '#accounts'],
    ] as [$ok, $label, $hint, $href]): ?>
      <li class="flex items-start gap-2">
        <span class="mt-0.5 font-bold <?= $ok ? 'text-emerald-600' : 'text-amber-600' ?>"><?= $ok ? '✓' : '○' ?></span>
        <span><a class="font-semibold" href="<?= e($href) ?>"><?= e($label) ?></a> <span class="text-stone-500">— <?= $ok ? 'กรอกแล้ว' : e($hint) ?></span></span>
      </li>
    <?php endforeach; ?>
  </ul>
</div>

<div class="grid gap-4 lg:grid-cols-2">
  <section id="logo" class="card">
    <h3>โลโก้วัด</h3>
    <div class="mb-4 flex items-center gap-4">
      <?php if ($t['logo_media_id']): ?>
        <img class="h-28 w-28 rounded-xl border border-stone-200 bg-white object-contain p-1" src="<?= e(media_url((int)$t['logo_media_id'])) ?>" alt="โลโก้ <?= e($t['name']) ?>">
      <?php else: ?>
        <div class="flex h-28 w-28 items-center justify-center rounded-xl border-2 border-dashed border-amber-400 bg-amber-50 p-2 text-center text-sm text-amber-800">ยังไม่มีโลโก้</div>
      <?php endif; ?>
      <p class="text-sm text-stone-600">ใช้เป็นหัวกระดาษของเอกสารที่พิมพ์จากระบบ (รายงานบัญชี ฯลฯ)<br>
        ไฟล์ JPEG, PNG หรือ WebP ไม่เกิน 2 MB (ระบบย่อและแปลงภาพให้อัตโนมัติ)</p>
    </div>
    <form method="post" enctype="multipart/form-data" class="flex flex-wrap items-center gap-2">
      <?= csrf_field() ?><input type="hidden" name="action" value="logo_upload">
      <input type="file" name="logo" accept="image/jpeg,image/png,image/webp" required class="max-w-[16rem] text-sm">
      <button class="btn btn-sm"><?= $t['logo_media_id'] ? 'เปลี่ยนโลโก้' : 'อัปโหลดโลโก้' ?></button>
    </form>
    <?php if ($t['logo_media_id']): ?>
    <form method="post" class="mt-2" data-confirm="ลบโลโก้วัด?">
      <?= csrf_field() ?><input type="hidden" name="action" value="logo_remove"><button class="text-sm text-red-700 underline">ลบโลโก้</button>
    </form>
    <?php endif; ?>
  </section>

  <section class="card">
    <h3>ข้อมูลทะเบียนวัด <span class="badge">แก้ไขไม่ได้</span></h3>
    <dl class="grid grid-cols-[8rem_1fr] gap-y-1.5 text-sm">
      <dt class="text-stone-500">รหัสวัด</dt><dd class="font-mono"><?= e($t['temple_code'] ?? '-') ?></dd>
      <dt class="text-stone-500"><?= e(unit_subdistrict()) ?></dt><dd><?= e($t['subdistrict'] ?? '-') ?></dd>
      <dt class="text-stone-500">ประเภท / นิกาย</dt><dd><?= e(trim(($t['temple_type'] ?? '') . ' / ' . ($t['sect'] ?? ''), ' /') ?: '-') ?></dd>
      <dt class="text-stone-500">ปีที่ตั้งวัด</dt><dd><?= $t['founded_be'] ? 'พ.ศ. ' . (int)$t['founded_be'] : '-' ?></dd>
      <dt class="text-stone-500">อีเมลวัด</dt><dd><?= $t['email'] ? e($u['role'] === 'admin' ? $t['email'] : mask_email($t['email'])) : '<span class="text-red-700">ยังไม่ได้ลงทะเบียน</span>' ?></dd>
    </dl>
    <p class="mt-3 text-xs text-stone-500">ข้อมูลนี้มาจากทะเบียนวัด หากไม่ถูกต้อง กรุณาแจ้งผู้ดูแลระบบหลัก</p>
  </section>
</div>

<section id="contact" class="card">
  <h3>ที่อยู่และการติดต่อ</h3>
  <form method="post" class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
    <?= csrf_field() ?><input type="hidden" name="action" value="save_profile">
    <?php foreach ([['phone', 'โทรศัพท์วัด', 50], ['house_no', 'บ้านเลขที่', 50], ['village', 'ชื่อหมู่บ้าน', 100], ['moo', 'หมู่ที่', 20], ['soi', 'ซอย', 100], ['road', 'ถนน', 100], ['postcode', 'รหัสไปรษณีย์', 5], ['tax_id', 'เลขประจำตัวผู้เสียภาษีของวัด (ใช้ในอนุโมทนาบัตร)', 17]] as [$k, $label, $max]): ?>
      <div><label for="f_<?= $k ?>"><?= e($label) ?></label><input id="f_<?= $k ?>" name="<?= $k ?>" value="<?= e($t[$k]) ?>" maxlength="<?= $max ?>" class="w-full"></div>
    <?php endforeach; ?>
    <div class="col-span-full"><button class="btn">บันทึก</button></div>
  </form>
</section>

<section id="accounts" class="mt-6">
  <div class="flex flex-wrap items-center justify-between gap-2">
    <h3 class="!my-0">บัญชีของวัด: เงินสด และเงินฝากธนาคาร</h3>
    <a class="btn btn-outline btn-sm" href="accounts.php<?= $u['role'] === 'admin' ? '?temple=' . $tid : '' ?>">โอน/นำฝาก/ถอนระหว่างบัญชี →</a>
  </div>
  <p class="my-2 text-sm text-stone-600">กรอกรายละเอียดบัญชีของวัด (ธนาคาร สาขา เลขที่บัญชี ยอดยกมา) เพื่อใช้บันทึกรายรับ-รายจ่ายแยกตามบัญชี และออกรายงานประจำเดือนแบบที่ 1–3 ได้ตรงตามแบบของคณะสงฆ์ · <a href="statements.php<?= $u['role'] === 'admin' ? '?temple=' . $tid : '' ?>">ดูรายงานแบบที่ 1–3</a></p>
  <?= ledger_accounts_panel($pdo, $t, true, 'profile') ?>
</section>
<?php require __DIR__ . '/includes/footer.php'; ?>
