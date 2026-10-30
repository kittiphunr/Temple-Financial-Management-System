<?php
require __DIR__ . '/includes/bootstrap.php';

if (user()) {
    redirect('index.php');
}

$pdo = db();
$p = $_SESSION['pending_2fa'] ?? null;
if (!$p || time() - (int)$p['t'] > OTP_PENDING_SECONDS) {
    unset($_SESSION['pending_2fa']);
    flash('หมดเวลายืนยันตัวตน กรุณาเข้าสู่ระบบใหม่', 'err');
    redirect('login.php');
}

$st = $pdo->prepare("SELECT u.*, t.email AS temple_email, t.is_active AS temple_active
    FROM users u JOIN temples t ON t.id = u.temple_id
    WHERE u.id = ? AND u.is_active = 1 AND u.role = 'temple'");
$st->execute([(int)$p['uid']]);
$row = $st->fetch();
if (!$row || !$row['temple_active'] || trim((string)$row['temple_email']) === '') {
    unset($_SESSION['pending_2fa']);
    flash('ไม่สามารถยืนยันตัวตนได้ กรุณาติดต่อผู้ดูแลระบบ', 'err');
    redirect('login.php');
}
$uid = (int)$row['id'];

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = $_POST['action'] ?? 'verify';

    if ($action === 'cancel') {
        unset($_SESSION['pending_2fa']);
        redirect('login.php');
    }
    if ($action === 'resend') {
        [$sent, $why] = otp_issue($pdo, $row, $row['temple_email']);
        $sent ? flash('ส่งรหัสใหม่ไปยังอีเมลของวัดแล้ว') : flash($why, 'err');
        $sent && audit('otp_sent', 'user', $uid);
        redirect('login_verify.php');
    }

    $code = preg_replace('/\D/', '', $_POST['code'] ?? '');
    if (strlen($code) !== 6) {
        $error = 'กรุณากรอกรหัส 6 หลัก';
    } else {
        switch (otp_verify($pdo, $uid, $code)) {
            case 'ok':
                complete_login($pdo, $row);
                redirect('index.php');
            case 'locked':
                unset($_SESSION['pending_2fa']);
                audit('otp_locked', 'user', $uid);
                flash('กรอกรหัสผิดเกินกำหนด กรุณาเข้าสู่ระบบใหม่เพื่อขอรหัสใหม่', 'err');
                redirect('login.php');
            case 'expired':
                $error = 'รหัสหมดอายุแล้ว กรุณากด "ขอรหัสใหม่"';
                break;
            default:
                $error = 'รหัสไม่ถูกต้อง';
        }
    }
}

$wait = otp_cooldown_left($pdo, $uid);
$pageTitle = 'ยืนยันตัวตน';
require __DIR__ . '/includes/header.php';
?>
<div class="mx-auto my-10 max-w-sm rounded-2xl border border-stone-200 bg-white p-6 shadow-sm">
  <h2>ยืนยันตัวตนสองชั้น</h2>
  <p class="mb-4 text-stone-600">ระบบส่งรหัส 6 หลักไปที่อีเมลของวัด <strong class="whitespace-nowrap"><?= e(mask_email($row['temple_email'])) ?></strong>
    รหัสใช้ได้ครั้งเดียวและหมดอายุใน <?= OTP_TTL_SECONDS / 60 ?> นาที</p>
  <?php if ($error): ?><div class="mb-4 rounded-lg bg-red-50 px-4 py-2.5 text-red-900"><?= e($error) ?></div><?php endif; ?>
  <form method="post" class="grid gap-3">
    <?= csrf_field() ?><input type="hidden" name="action" value="verify">
    <div><label for="code">รหัส 6 หลัก</label>
      <input id="code" name="code" inputmode="numeric" pattern="[0-9]{6}" maxlength="6" autocomplete="one-time-code" required autofocus
             class="w-full text-center text-2xl tracking-[0.5em]"></div>
    <button class="btn w-full" type="submit">ยืนยัน</button>
  </form>
  <div class="mt-4 flex items-center justify-between gap-2 text-sm">
    <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="resend">
      <button class="btn btn-outline btn-sm disabled:cursor-not-allowed disabled:opacity-50" <?= $wait > 0 ? 'disabled' : '' ?>>
        ขอรหัสใหม่<?= $wait > 0 ? " (รอ $wait วินาที)" : '' ?></button></form>
    <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="cancel">
      <button class="text-stone-500 underline">กลับไปหน้าเข้าสู่ระบบ</button></form>
  </div>
</div>
<?php require __DIR__ . '/includes/footer.php'; ?>
