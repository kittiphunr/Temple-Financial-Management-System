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
        flash('รหัสผ่านใหม่ต้องไม่ซ้ำกับรหัสเดิม', 'err');
    } else {
        $pdo->prepare('UPDATE users SET password_hash = ?, password_changed_at = NOW(), failed_logins = 0 WHERE id = ?')
            ->execute([password_hash($new, PASSWORD_DEFAULT), $me['id']]);
        session_regenerate_id(true);
        audit('change_password', 'user', (int)$me['id']);
        flash('เปลี่ยนรหัสผ่านเรียบร้อยแล้ว');
    }
    redirect('account.php');
}

$roleTh = ['admin' => 'ผู้ดูแลระบบหลัก', 'district' => 'เจ้าหน้าที่อำเภอ', 'temple' => 'ผู้ดูแลวัด'];
$pageTitle = 'บัญชีของฉัน';
require __DIR__ . '/includes/header.php';
?>
<h2>บัญชีของฉัน</h2>
<div class="grid gap-4 lg:grid-cols-2">
  <div class="rounded-2xl border border-stone-200 bg-white p-4 shadow-sm">
    <h3>ข้อมูลบัญชี</h3>
    <dl class="grid grid-cols-[8rem_1fr] gap-y-2">
      <dt class="text-stone-500">ชื่อผู้ใช้</dt><dd class="font-mono font-semibold"><?= e($me['username']) ?> <span class="badge">ล็อก — เปลี่ยนไม่ได้</span></dd>
      <dt class="text-stone-500">บทบาท</dt><dd><?= e($roleTh[$me['role']]) ?></dd>
      <?php if ($me['role'] === 'temple'): ?>
      <dt class="text-stone-500">วัด</dt><dd><?= e($me['temple_name']) ?> <span class="text-stone-500">(อ.<?= e($me['district_name']) ?>)</span></dd>
      <dt class="text-stone-500">อีเมลวัด</dt><dd><?= $me['temple_email'] ? e(mask_email($me['temple_email'])) : '<span class="text-red-700">ยังไม่ได้ลงทะเบียน</span>' ?></dd>
      <?php endif; ?>
      <dt class="text-stone-500">เข้าสู่ระบบล่าสุด</dt><dd><?= $me['last_login_at'] ? e(th_date($me['last_login_at'])) . ' ' . e(substr($me['last_login_at'], 11, 5)) . ' น.' : '-' ?></dd>
    </dl>
    <?php if ($me['role'] === 'temple'): ?>
    <p class="mt-4 rounded-lg bg-gold-50 p-3 text-sm text-stone-700">
      อีเมลของวัดใช้รับรหัสยืนยัน 6 หลักตอนเข้าสู่ระบบ ผู้ดูแลวัดแก้ไขเองไม่ได้ หากต้องการเปลี่ยนอีเมล กรุณาแจ้งผู้ดูแลระบบหลัก
    </p>
    <?php endif; ?>
  </div>

  <div class="rounded-2xl border border-stone-200 bg-white p-4 shadow-sm">
    <h3>เปลี่ยนรหัสผ่าน</h3>
    <form method="post" class="grid gap-3">
      <?= csrf_field() ?>
      <div><label>รหัสผ่านเดิม</label><input type="password" name="current_password" required autocomplete="current-password" class="w-full"></div>
      <div><label>รหัสผ่านใหม่ (อย่างน้อย 8 ตัวอักษร)</label><input type="password" name="new_password" required minlength="8" autocomplete="new-password" class="w-full"></div>
      <div><label>ยืนยันรหัสผ่านใหม่</label><input type="password" name="confirm_password" required minlength="8" autocomplete="new-password" class="w-full"></div>
      <div><button class="btn">บันทึกรหัสผ่านใหม่</button></div>
    </form>
  </div>
</div>
<?php require __DIR__ . '/includes/footer.php'; ?>
