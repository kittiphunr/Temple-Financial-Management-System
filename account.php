<?php
require __DIR__ . '/includes/bootstrap.php';
$u = require_login();
$pdo = db();

$st = $pdo->prepare('SELECT u.*, t.name AS temple_name, t.email AS temple_email, d.name AS district_name
    FROM users u LEFT JOIN temples t ON t.id = u.temple_id
    LEFT JOIN districts d ON d.id = COALESCE(t.district_id, u.district_id)
    WHERE u.id = ?');
$st->execute([$u['id']]);
$me = $st->fetch();

// ต้องเปลี่ยนรหัสผ่านก่อนใช้งาน (ดูจากฐานข้อมูลเป็นหลัก แล้วซิงก์กับ session)
$forced = (bool)$me['must_change_password'];
$_SESSION['user']['must_change'] = $forced;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    // หมายเหตุ: ชื่อผู้ใช้และอีเมลแก้จากหน้านี้ไม่ได้ (ไม่รับค่าจากฟอร์ม) — ชื่อผู้ใช้ถูกล็อก อีเมลแก้ได้โดยแอดมินหลักเท่านั้น
    $cur = $_POST['current_password'] ?? '';
    $new = $_POST['new_password'] ?? '';
    $confirm = $_POST['confirm_password'] ?? '';

    if (account_locked($me)) {
        flash('ลองรหัสผ่านเดิมผิดหลายครั้ง บัญชีถูกล็อกชั่วคราว กรุณาลองใหม่ภายหลัง', 'err');
    } elseif (!password_verify($cur, $me['password_hash'])) {
        register_failed_login($pdo, (int)$me['id']);
        flash('รหัสผ่านเดิมไม่ถูกต้อง', 'err');
    } elseif (mb_strlen($new) < 8) {
        flash('รหัสผ่านใหม่ต้องมีอย่างน้อย 8 ตัวอักษร', 'err');
    } elseif ($new !== $confirm) {
        flash('รหัสผ่านใหม่และการยืนยันไม่ตรงกัน', 'err');
    } elseif ($new === $cur) {
        flash('รหัสผ่านใหม่ต้องไม่ซ้ำกับรหัสเดิม' . ($forced ? ' (รหัสผ่านเริ่มต้นที่ผู้ดูแลระบบเตรียมไว้ให้)' : ''), 'err');
    } elseif (strcasecmp($new, $me['username']) === 0) {
        flash('รหัสผ่านใหม่ต้องไม่เหมือนชื่อผู้ใช้', 'err');
    } else {
        $pdo->prepare('UPDATE users SET password_hash = ?, password_changed_at = NOW(), must_change_password = 0, failed_logins = 0 WHERE id = ?')
            ->execute([password_hash($new, PASSWORD_DEFAULT), $me['id']]);
        session_regenerate_id(true);
        audit($forced ? 'first_password_change' : 'change_password', 'user', (int)$me['id']);
        $_SESSION['user']['must_change'] = false;
        if ($forced) {
            // แอดมินวัดที่ยังไม่ได้ใส่โลโก้ → พาไปใส่ต่อทันที (จำเป็นสำหรับการออกเอกสาร)
            if ($me['role'] === 'temple' && !temple_checklist($pdo, (int)$me['temple_id'])['logo']) {
                flash('ตั้งรหัสผ่านใหม่เรียบร้อยแล้ว ขั้นต่อไป: ใส่โลโก้วัดเพื่อใช้ในเอกสารที่ออกจากระบบ');
                redirect('profile.php#logo');
            }
            flash('ตั้งรหัสผ่านใหม่เรียบร้อยแล้ว เริ่มใช้งานระบบได้เลย');
            redirect('index.php');
        }
        flash('เปลี่ยนรหัสผ่านเรียบร้อยแล้ว');
    }
    redirect('account.php');
}

$roleTh = ['admin' => 'ผู้ดูแลระบบหลัก', 'province' => 'เจ้าคณะจังหวัด (ดูข้อมูลอย่างเดียว)', 'district' => 'แอดมินประจำอำเภอ', 'subdistrict' => 'เจ้าคณะตำบล (ดูข้อมูลอย่างเดียว)', 'temple' => 'ผู้ดูแลวัด'];
$pageTitle = $forced ? 'ตั้งรหัสผ่านใหม่' : 'บัญชีของฉัน';
require __DIR__ . '/includes/header.php';
?>
<h2><?= $forced ? 'ตั้งรหัสผ่านใหม่ก่อนเริ่มใช้งาน' : 'บัญชีของฉัน' ?></h2>
<?php if ($forced): ?>
<div class="mb-4 rounded-2xl border border-amber-300 bg-amber-50 p-4 text-amber-950">
  <p class="font-bold">ยืนยันตัวตนทางอีเมลสำเร็จแล้ว — ขั้นตอนสุดท้ายคือตั้งรหัสผ่านของคุณเอง</p>
  <p class="mt-1 text-sm">รหัสผ่านที่ใช้เข้าสู่ระบบครั้งนี้เป็นรหัสเริ่มต้นที่ผู้ดูแลระบบหลักเตรียมไว้ให้ จึงต้องเปลี่ยนก่อนใช้งานส่วนอื่นของระบบ
    กรอกรหัสผ่านเริ่มต้นในช่อง "รหัสผ่านเดิม" แล้วตั้งรหัสผ่านใหม่ (อย่างน้อย 8 ตัวอักษร และต้องไม่ซ้ำกับรหัสเดิมหรือชื่อผู้ใช้)</p>
</div>
<?php endif; ?>
<div class="grid gap-4 <?= $forced ? 'mx-auto max-w-xl' : 'lg:grid-cols-2' ?>">
  <?php if (!$forced): ?>
  <div class="rounded-2xl border border-stone-200 bg-white p-4 shadow-sm">
    <h3>ข้อมูลบัญชี</h3>
    <dl class="grid grid-cols-[8rem_1fr] gap-y-2">
      <dt class="text-stone-500">ชื่อผู้ใช้</dt><dd class="font-mono font-semibold"><?= e($me['username']) ?> <span class="badge">ล็อก — เปลี่ยนไม่ได้</span></dd>
      <dt class="text-stone-500">บทบาท</dt><dd><?= e($roleTh[$me['role']]) ?></dd>
      <?php if ($me['role'] === 'subdistrict'): ?>
      <dt class="text-stone-500">ตำบลที่ดูแล</dt><dd><?= e(subdistrict_label(subdistrict_assignments($pdo, (int)$me['id']))) ?></dd>
      <?php endif; ?>
      <?php if ($me['role'] === 'temple'): ?>
      <dt class="text-stone-500">วัด</dt><dd><?= e($me['temple_name']) ?> <span class="text-stone-500">(อ.<?= e($me['district_name']) ?>)</span></dd>
      <dt class="text-stone-500">อีเมลวัด</dt><dd><?= $me['temple_email'] ? e(mask_email($me['temple_email'])) : '<span class="text-red-700">ยังไม่ได้ลงทะเบียน</span>' ?></dd>
      <?php endif; ?>
      <?php if ($me['role'] !== 'temple'): ?>
      <dt class="text-stone-500">อีเมลยืนยันตัวตน</dt><dd><?= $me['email'] ? e(mask_email($me['email'])) : '<span class="text-red-700">ยังไม่ได้ลงทะเบียน</span>' ?>
        <?= $me['role'] === 'admin' ? ' <a class="text-sm underline" href="staff_email.php">เปลี่ยน</a>' : '' ?></dd>
      <?php endif; ?>
      <dt class="text-stone-500">เข้าสู่ระบบล่าสุด</dt><dd><?= $me['last_login_at'] ? e(th_datetime($me['last_login_at'])) : '-' ?></dd>
    </dl>
    <?php if ($me['role'] === 'temple'): ?>
    <p class="mt-4 rounded-lg bg-gold-50 p-3 text-sm text-stone-700">
      อีเมลของวัดใช้รับรหัสยืนยัน 6 หลักตอนเข้าสู่ระบบและรหัสกู้รหัสผ่าน ผู้ดูแลวัดแก้ไขเองไม่ได้ หากต้องการเปลี่ยนอีเมล กรุณาแจ้งผู้ดูแลระบบหลัก
    </p>
    <?php elseif ($me['role'] !== 'admin'): ?>
    <p class="mt-4 rounded-lg bg-gold-50 p-3 text-sm text-stone-700">อีเมลนี้ใช้รับรหัสยืนยันตัวตนและกู้รหัสผ่าน แก้ไขโดยผู้ดูแลระบบหลักเท่านั้น</p>
    <?php endif; ?>
  </div>
  <?php endif; ?>

  <div class="rounded-2xl border border-stone-200 bg-white p-4 shadow-sm">
    <h3><?= $forced ? 'ตั้งรหัสผ่านใหม่' : 'เปลี่ยนรหัสผ่าน' ?></h3>
    <form method="post" class="grid gap-3">
      <?= csrf_field() ?>
      <div><label><?= $forced ? 'รหัสผ่านเดิม (รหัสเริ่มต้นที่ได้รับ)' : 'รหัสผ่านเดิม' ?></label><input type="password" name="current_password" required autocomplete="current-password" class="w-full"></div>
      <div><label>รหัสผ่านใหม่ (อย่างน้อย 8 ตัวอักษร)</label><input type="password" name="new_password" required minlength="8" autocomplete="new-password" class="w-full"></div>
      <div><label>ยืนยันรหัสผ่านใหม่</label><input type="password" name="confirm_password" required minlength="8" autocomplete="new-password" class="w-full"></div>
      <div><button class="btn"><?= $forced ? 'บันทึกและเริ่มใช้งาน' : 'บันทึกรหัสผ่านใหม่' ?></button></div>
    </form>
  </div>
</div>
<?php require __DIR__ . '/includes/footer.php'; ?>
