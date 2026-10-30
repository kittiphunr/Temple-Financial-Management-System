<?php
require __DIR__ . '/includes/bootstrap.php';

if (user()) {
    redirect('index.php');
}

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $pdo = db();
    $_SESSION['login_fail'] = $_SESSION['login_fail'] ?? 0;
    unset($_SESSION['pending_2fa']);

    if ($_SESSION['login_fail'] >= 10) {
        $error = 'ลองเข้าสู่ระบบผิดหลายครั้ง กรุณาปิดเบราว์เซอร์แล้วลองใหม่อีกครั้ง';
    } else {
        $st = $pdo->prepare('SELECT u.*, t.email AS temple_email, t.is_active AS temple_active
            FROM users u LEFT JOIN temples t ON t.id = u.temple_id
            WHERE u.username = ? AND u.is_active = 1');
        $st->execute([trim($_POST['username'] ?? '')]);
        $row = $st->fetch();

        // ตรวจรหัสผ่านทุกครั้ง (แม้ไม่พบผู้ใช้) เพื่อไม่ให้เวลาตอบสนองบอกได้ว่าชื่อผู้ใช้มีอยู่หรือไม่
        $hash = $row['password_hash'] ?? password_hash('dummy', PASSWORD_DEFAULT);
        $pwOk = password_verify($_POST['password'] ?? '', $hash) && $row;

        if ($row && account_locked($row)) {
            $error = 'ลองเข้าสู่ระบบผิดหลายครั้ง บัญชีถูกล็อกชั่วคราว กรุณาลองใหม่ภายหลัง ' . LOGIN_LOCK_MINUTES . ' นาที';
        } elseif (!$pwOk) {
            if ($row) {
                register_failed_login($pdo, (int)$row['id']);
            }
            $_SESSION['login_fail']++;
            $error = 'ชื่อผู้ใช้หรือรหัสผ่านไม่ถูกต้อง';
        } elseif ($row['role'] === 'temple') {
            if (!$row['temple_active']) {
                $error = 'บัญชีวัดนี้ถูกปิดใช้งาน กรุณาติดต่อผู้ดูแลระบบ';
            } elseif (trim((string)$row['temple_email']) === '') {
                // วัดที่ยังไม่มีอีเมลในระบบ เข้าสู่ระบบไม่ได้จนกว่าแอดมินหลักจะลงข้อมูล
                audit('login_blocked_no_email', 'user', (int)$row['id']);
                $error = 'ยังเข้าสู่ระบบไม่ได้ เนื่องจากวัดนี้ยังไม่ได้ลงทะเบียนอีเมลกับผู้ดูแลระบบ กรุณาแจ้งอีเมลของวัดให้ผู้ดูแลระบบหลักเพื่อลงข้อมูลก่อน';
            } else {
                [$sent, $why] = otp_issue($pdo, $row, user_mail_address($row));
                if ($sent) {
                    session_regenerate_id(true);
                    $_SESSION['pending_2fa'] = ['uid' => (int)$row['id'], 't' => time()];
                    audit('otp_sent', 'user', (int)$row['id']);
                    redirect('login_verify.php');
                }
                $error = $why;
            }
        } elseif (!staff_2fa_enabled()) {
            complete_login($pdo, $row);
            redirect('index.php');
        } elseif (user_mail_address($row) === '') {
            if ($row['role'] === 'admin') {
                // แอดมินหลักที่ยังไม่มีอีเมล: ลงทะเบียนและยืนยันอีเมลก่อนเข้าระบบ (ครั้งแรกหลังติดตั้ง/อัปเกรด)
                session_regenerate_id(true);
                $_SESSION['pending_enroll'] = ['uid' => (int)$row['id'], 't' => time()];
                redirect('staff_email.php');
            }
            audit('login_blocked_no_email', 'user', (int)$row['id']);
            $error = 'ยังเข้าสู่ระบบไม่ได้ เนื่องจากบัญชีนี้ยังไม่ได้ลงทะเบียนอีเมล กรุณาแจ้งผู้ดูแลระบบหลักให้ลงอีเมลที่ จัดการระบบ → ผู้ใช้';
        } else {
            [$sent, $why] = otp_issue($pdo, $row, user_mail_address($row));
            if ($sent) {
                session_regenerate_id(true);
                $_SESSION['pending_2fa'] = ['uid' => (int)$row['id'], 't' => time()];
                audit('otp_sent', 'user', (int)$row['id']);
                redirect('login_verify.php');
            }
            $error = $why;
        }
    }
}

$pageTitle = 'เข้าสู่ระบบ';
require __DIR__ . '/includes/header.php';
?>
<div class="card mx-auto my-10 max-w-sm">
  <h2>เข้าสู่ระบบ</h2>
  <?php if ($error): ?><?= alert_box($error, 'err') ?><?php endif; ?>
  <form method="post" class="grid gap-3">
    <?= csrf_field() ?>
    <div><label>ชื่อผู้ใช้</label><input name="username" required autofocus autocomplete="username" value="<?= e($_POST["username"] ?? "") ?>" class="w-full"></div>
    <div><label>รหัสผ่าน</label><input type="password" name="password" required autocomplete="current-password" class="w-full"></div>
    <div><button class="btn w-full" type="submit">เข้าสู่ระบบ</button></div>
  </form>
  <p class="mt-4 text-sm"><a href="forgot.php">ลืมรหัสผ่าน?</a></p>
  <p class="mt-2 text-sm text-stone-500">ทุกบัญชีต้องยืนยันตัวตนด้วยรหัส 6 หลักที่ส่งไปยังอีเมลที่ลงทะเบียนไว้ (อีเมลวัดสำหรับบัญชีวัด) หลังกรอกรหัสผ่าน</p>
</div>
<?php require __DIR__ . '/includes/footer.php'; ?>
