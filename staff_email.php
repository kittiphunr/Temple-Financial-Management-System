<?php
/**
 * อีเมลรับรหัสยืนยันของแอดมินหลัก (แอดมินอำเภอ/เจ้าคณะจังหวัด: แอดมินหลักเป็นผู้ลงอีเมลให้ที่ จัดการระบบ → ผู้ใช้)
 *  - ลงทะเบียนครั้งแรก: หลังกรอกรหัสผ่านถูก ถ้ายังไม่มีอีเมล (session pending_enroll)
 *  - เปลี่ยนอีเมล: ผู้ที่เข้าสู่ระบบแล้ว ต้องกรอกรหัสผ่านปัจจุบัน
 * ทั้งสองกรณีต้องยืนยันด้วยรหัส 6 หลักที่ส่งไปยัง "อีเมลใหม่" ก่อนจึงบันทึก
 */
require __DIR__ . '/includes/bootstrap.php';
$pdo = db();

$cur = user();
$enroll = $cur ? null : ($_SESSION['pending_enroll'] ?? null);
if ($enroll && time() - (int)$enroll['t'] > OTP_PENDING_SECONDS) {
    unset($_SESSION['pending_enroll'], $_SESSION['email_change']);
    flash('หมดเวลา กรุณาเข้าสู่ระบบใหม่', 'err');
    redirect('login.php');
}
$uid = $cur ? (int)$cur['id'] : ($enroll ? (int)$enroll['uid'] : 0);
if (!$uid) {
    redirect('login.php');
}
$st = $pdo->prepare("SELECT * FROM users WHERE id = ? AND is_active = 1 AND role = 'admin'");
$st->execute([$uid]);
$me = $st->fetch();
if (!$me) {
    http_response_code(403);
    exit('เฉพาะแอดมินหลักเท่านั้น');
}
$mode = $cur ? 'change' : 'enroll';
$pending = $_SESSION['email_change'] ?? null;
if ($pending && ((int)$pending['uid'] !== $uid || time() - (int)$pending['t'] > OTP_PURPOSES['enroll']['ttl'])) {
    $pending = null;
    unset($_SESSION['email_change']);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = $_POST['action'] ?? '';
    if ($action === 'cancel') {
        unset($_SESSION['email_change']);
        if ($mode === 'enroll') {
            unset($_SESSION['pending_enroll']);
            redirect('login.php');
        }
        redirect('account.php');
    }
    if ($action === 'send') {
        $email = trim($_POST['email'] ?? '');
        if ($mode === 'change' && !password_verify($_POST['current_password'] ?? '', $me['password_hash'])) {
            register_failed_login($pdo, $uid);
            flash('รหัสผ่านปัจจุบันไม่ถูกต้อง', 'err');
        } elseif (!valid_email($email)) {
            flash('รูปแบบอีเมลไม่ถูกต้อง', 'err');
        } else {
            [$sent, $why] = otp_issue($pdo, $me, $email, 'enroll');
            if ($sent) {
                $_SESSION['email_change'] = ['uid' => $uid, 'email' => $email, 't' => time()];
                audit('otp_sent', 'user', $uid, 'enroll');
                flash('ส่งรหัสยืนยันไปที่อีเมลแล้ว');
            } else {
                flash($why, 'err');
            }
        }
        redirect('staff_email.php');
    }
    if ($action === 'verify' && $pending) {
        $code = preg_replace('/\D/', '', $_POST['code'] ?? '');
        switch (strlen($code) === 6 ? otp_verify($pdo, $uid, $code, 'enroll') : 'bad') {
            case 'ok':
                $pdo->prepare('UPDATE users SET email = ?, email_verified_at = NOW() WHERE id = ?')->execute([$pending['email'], $uid]);
                audit($mode === 'enroll' ? 'enroll_email' : 'change_email', 'user', $uid);
                unset($_SESSION['email_change']);
                if ($mode === 'enroll') {
                    unset($_SESSION['pending_enroll']);
                    $me['email'] = $pending['email'];
                    complete_login($pdo, $me);
                    flash('ยืนยันอีเมลเรียบร้อยแล้ว — ครั้งต่อไประบบจะส่งรหัสยืนยันไปที่อีเมลนี้ทุกครั้งที่เข้าสู่ระบบ');
                    redirect('index.php');
                }
                flash('เปลี่ยนอีเมลยืนยันตัวตนเรียบร้อยแล้ว');
                redirect('account.php');
            case 'locked':
                unset($_SESSION['email_change']);
                flash('กรอกรหัสผิดเกินกำหนด กรุณาขอรหัสใหม่', 'err');
                break;
            case 'expired':
                unset($_SESSION['email_change']);
                flash('รหัสหมดอายุแล้ว กรุณาขอรหัสใหม่', 'err');
                break;
            default:
                flash('รหัสไม่ถูกต้อง', 'err');
        }
        redirect('staff_email.php');
    }
    redirect('staff_email.php');
}

$pageTitle = $mode === 'enroll' ? 'ยืนยันอีเมลผู้ดูแลระบบ' : 'เปลี่ยนอีเมลยืนยันตัวตน';
require __DIR__ . '/includes/header.php';
?>
<div class="card mx-auto my-8 max-w-md">
  <h2><?= $mode === 'enroll' ? 'ลงทะเบียนอีเมลผู้ดูแลระบบ' : 'เปลี่ยนอีเมลยืนยันตัวตน' ?></h2>
  <?php if ($mode === 'enroll'): ?>
  <p class="mb-4 text-stone-600">เพื่อความปลอดภัย บัญชีผู้ดูแลระบบต้องยืนยันตัวตนด้วยรหัส 6 หลักทางอีเมลทุกครั้งที่เข้าสู่ระบบ กรุณาระบุอีเมลที่ใช้ได้จริงและเข้าถึงได้เฉพาะคุณ
    (ใช้กู้รหัสผ่านที่ลืมด้วย)</p>
  <?php endif; ?>
  <?php if (!$pending): ?>
  <form method="post" class="grid gap-3">
    <?= csrf_field() ?><input type="hidden" name="action" value="send">
    <?php if ($me['email'] && $mode === 'change'): ?><p class="text-sm text-stone-600">อีเมลปัจจุบัน: <strong><?= e(mask_email($me['email'])) ?></strong></p><?php endif; ?>
    <div><label for="email"><?= $mode === 'change' ? 'อีเมลใหม่' : 'อีเมล' ?></label><input id="email" type="email" name="email" required maxlength="190" class="w-full" autofocus></div>
    <?php if ($mode === 'change'): ?>
    <div><label for="cp">รหัสผ่านปัจจุบัน</label><input id="cp" type="password" name="current_password" required class="w-full" autocomplete="current-password"></div>
    <?php endif; ?>
    <button class="btn">ส่งรหัสยืนยันไปที่อีเมลนี้</button>
  </form>
  <?php else: ?>
  <p class="mb-4 text-stone-600">ส่งรหัส 6 หลักไปที่ <strong class="whitespace-nowrap"><?= e(mask_email($pending['email'])) ?></strong> แล้ว (หมดอายุใน <?= OTP_PURPOSES['enroll']['ttl'] / 60 ?> นาที)</p>
  <form method="post" class="grid gap-3">
    <?= csrf_field() ?><input type="hidden" name="action" value="verify">
    <input name="code" inputmode="numeric" pattern="[0-9]{6}" maxlength="6" autocomplete="one-time-code" required autofocus class="w-full text-center text-2xl tracking-[0.5em]">
    <button class="btn">ยืนยัน</button>
  </form>
  <?php endif; ?>
  <form method="post" class="mt-4 text-sm"><?= csrf_field() ?><input type="hidden" name="action" value="cancel"><button class="text-stone-500 underline"><?= $mode === 'enroll' ? 'ยกเลิกและกลับไปหน้าเข้าสู่ระบบ' : 'ยกเลิก' ?></button></form>
</div>
<?php require __DIR__ . '/includes/footer.php'; ?>
