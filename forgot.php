<?php
/**
 * ลืมรหัสผ่าน: กรอกชื่อผู้ใช้ → ระบบส่งรหัส 8 หลักไปที่อีเมลที่ลงทะเบียนไว้ (บัญชีวัด = อีเมลวัด) → กรอกรหัสที่ reset.php
 * ตอบเหมือนกันเสมอไม่ว่าจะมีชื่อผู้ใช้นี้หรือไม่ (กันการไล่เดาชื่อผู้ใช้) และใช้รหัสแทนลิงก์เพื่อไม่ต้องพึ่งชื่อโดเมนจากคำขอ
 */
require __DIR__ . '/includes/bootstrap.php';
if (user()) {
    redirect('account.php');
}
$pdo = db();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $username = trim($_POST['username'] ?? '');
    $_SESSION['forgot_n'] = ($_SESSION['forgot_n'] ?? 0) + 1;
    if ($_SESSION['forgot_n'] > 8) {
        flash('ขอรหัสบ่อยเกินไป กรุณาปิดเบราว์เซอร์แล้วลองใหม่ภายหลัง', 'err');
        redirect('forgot.php');
    }
    $st = $pdo->prepare('SELECT u.*, t.email AS temple_email, t.is_active AS temple_active FROM users u
        LEFT JOIN temples t ON t.id = u.temple_id WHERE u.username = ? AND u.is_active = 1');
    $st->execute([$username]);
    $row = $st->fetch();
    if ($row && !($row['role'] === 'temple' && !$row['temple_active']) && ($to = user_mail_address($row)) !== '') {
        [$sent, $why] = otp_issue($pdo, $row, $to, 'reset');
        audit($sent ? 'reset_requested' : 'reset_not_sent', 'user', (int)$row['id'], $sent ? '' : $why);
    } elseif ($row) {
        audit('reset_no_email', 'user', (int)$row['id']);
    }
    $_SESSION['pending_reset'] = ['u' => $username, 't' => time()];
    flash('หากชื่อผู้ใช้นี้มีอยู่และมีอีเมลลงทะเบียนไว้ ระบบได้ส่งรหัส 8 หลักไปให้แล้ว (บัญชีวัดส่งไปที่อีเมลของวัด)');
    redirect('reset.php');
}

$pageTitle = 'ลืมรหัสผ่าน';
require __DIR__ . '/includes/header.php';
?>
<div class="mx-auto my-10 max-w-sm rounded-2xl border border-stone-200 bg-white p-6 shadow-sm">
  <h2>ลืมรหัสผ่าน</h2>
  <p class="mb-4 text-sm text-stone-600">กรอกชื่อผู้ใช้ ระบบจะส่งรหัสสำหรับตั้งรหัสผ่านใหม่ไปที่อีเมลที่ลงทะเบียนไว้ (บัญชีวัด = อีเมลวัดที่ผู้ดูแลระบบหลักลงไว้)
    หากไม่มีอีเมลในระบบ ให้ติดต่อผู้ดูแลระบบหลักเพื่อรีเซ็ตให้</p>
  <form method="post" class="grid gap-3">
    <?= csrf_field() ?>
    <div><label for="username">ชื่อผู้ใช้</label><input id="username" name="username" required autofocus autocomplete="username" class="w-full"></div>
    <button class="btn w-full">ส่งรหัสไปที่อีเมล</button>
  </form>
  <p class="mt-4 text-sm"><a href="login.php">กลับไปหน้าเข้าสู่ระบบ</a> · <a href="reset.php">มีรหัสอยู่แล้ว</a></p>
</div>
<?php require __DIR__ . '/includes/footer.php'; ?>
