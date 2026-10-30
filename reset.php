<?php
/** ตั้งรหัสผ่านใหม่ด้วยรหัส 8 หลักจากอีเมล (ต่อจาก forgot.php) */
require __DIR__ . '/includes/bootstrap.php';
if (user()) {
    redirect('account.php');
}
$pdo = db();
$p = $_SESSION['pending_reset'] ?? null;
if (!$p || time() - (int)$p['t'] > OTP_PURPOSES['reset']['ttl']) {
    unset($_SESSION['pending_reset']);
    flash('กรุณาขอรหัสใหม่ที่หน้าลืมรหัสผ่าน', 'err');
    redirect('forgot.php');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $code = preg_replace('/\D/', '', $_POST['code'] ?? '');
    $new = (string)($_POST['new_password'] ?? '');
    $confirm = (string)($_POST['confirm_password'] ?? '');

    $st = $pdo->prepare('SELECT u.*, t.email AS temple_email, t.is_active AS temple_active FROM users u
        LEFT JOIN temples t ON t.id = u.temple_id WHERE u.username = ? AND u.is_active = 1');
    $st->execute([$p['u']]);
    $row = $st->fetch();
    $bad = 'รหัสไม่ถูกต้องหรือหมดอายุ กรุณาตรวจสอบหรือขอรหัสใหม่';

    if (strlen($code) !== 8) {
        flash('กรุณากรอกรหัส 8 หลักจากอีเมล', 'err');
    } elseif ($row && ($pwErr = password_policy_error($new, $row['username'], $row['role'])) !== '') {
        flash($pwErr, 'err');   // ตรวจรหัสผ่านก่อนตรวจรหัสยืนยัน เพื่อไม่ให้พิมพ์รหัสผ่านผิดแล้วต้องขอรหัสใหม่
    } elseif ($new !== $confirm) {
        flash('รหัสผ่านใหม่และการยืนยันไม่ตรงกัน', 'err');
    } elseif (!$row || user_mail_address($row) === '') {
        flash($bad, 'err');
    } else {
        switch (otp_verify($pdo, (int)$row['id'], $code, 'reset')) {
            case 'ok':
                $pdo->prepare('UPDATE users SET password_hash = ?, password_changed_at = NOW(), must_change_password = 0, failed_logins = 0, locked_until = NULL WHERE id = ?')
                    ->execute([password_hash($new, PASSWORD_DEFAULT), $row['id']]);
                otp_invalidate_all($pdo, (int)$row['id']);
                audit('password_reset', 'user', (int)$row['id']);
                send_mail(user_mail_address($row), 'รหัสผ่านของคุณถูกเปลี่ยนแล้ว',
                    "รหัสผ่านของบัญชี {$row['username']} ถูกเปลี่ยนผ่านระบบกู้รหัสผ่านเมื่อ " . th_datetime(date('Y-m-d H:i:s')) . "\nหากคุณไม่ได้ดำเนินการ กรุณาแจ้งผู้ดูแลระบบทันที\n");
                unset($_SESSION['pending_reset'], $_SESSION['login_fail']);
                flash('ตั้งรหัสผ่านใหม่เรียบร้อยแล้ว เข้าสู่ระบบด้วยรหัสผ่านใหม่ได้เลย');
                redirect('login.php');
            case 'locked':
                unset($_SESSION['pending_reset']);
                flash('กรอกรหัสผิดเกินกำหนด กรุณาขอรหัสใหม่', 'err');
                redirect('forgot.php');
            default:
                flash($bad, 'err');
        }
    }
    redirect('reset.php');
}

$pageTitle = 'ตั้งรหัสผ่านใหม่';
require __DIR__ . '/includes/header.php';
?>
<div class="card mx-auto my-10 max-w-sm">
  <h2>ตั้งรหัสผ่านใหม่</h2>
  <p class="mb-4 text-sm text-stone-600">กรอกรหัส 8 หลักที่ส่งไปทางอีเมล (ใช้ได้ครั้งเดียว หมดอายุใน <?= OTP_PURPOSES['reset']['ttl'] / 60 ?> นาที) แล้วตั้งรหัสผ่านใหม่
    — บัญชีวัดอย่างน้อย 8 ตัว, แอดมินหลัก/<?= e(unit_district()) ?>/เจ้าคณะจังหวัดอย่างน้อย 10 ตัวและมีทั้งตัวอักษรกับตัวเลข</p>
  <form method="post" class="grid gap-3">
    <?= csrf_field() ?>
    <div><label for="code">รหัส 8 หลักจากอีเมล</label><input id="code" name="code" inputmode="numeric" pattern="[0-9]{8}" maxlength="8" autocomplete="one-time-code" required autofocus class="w-full text-center text-xl tracking-[0.3em]"></div>
    <div><label for="np">รหัสผ่านใหม่</label><input id="np" type="password" name="new_password" required minlength="8" autocomplete="new-password" class="w-full"></div>
    <div><label for="cp">ยืนยันรหัสผ่านใหม่</label><input id="cp" type="password" name="confirm_password" required minlength="8" autocomplete="new-password" class="w-full"></div>
    <button class="btn w-full">ตั้งรหัสผ่านใหม่</button>
  </form>
  <p class="mt-4 text-sm"><a href="forgot.php">ขอรหัสใหม่</a> · <a href="login.php">กลับไปหน้าเข้าสู่ระบบ</a></p>
</div>
<?php require __DIR__ . '/includes/footer.php'; ?>
