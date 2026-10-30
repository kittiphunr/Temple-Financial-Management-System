<?php
require __DIR__ . '/includes/bootstrap.php';

if (user()) {
    redirect('index.php');
}

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $_SESSION['login_fail'] = $_SESSION['login_fail'] ?? 0;
    if ($_SESSION['login_fail'] >= 5) {
        $error = 'ลองเข้าสู่ระบบผิดหลายครั้ง กรุณาปิดเบราว์เซอร์แล้วลองใหม่อีกครั้ง';
    } else {
        $st = db()->prepare('SELECT * FROM users WHERE username = ? AND is_active = 1');
        $st->execute([trim($_POST['username'] ?? '')]);
        $row = $st->fetch();
        if ($row && password_verify($_POST['password'] ?? '', $row['password_hash'])) {
            session_regenerate_id(true);
            $_SESSION['login_fail'] = 0;
            $_SESSION['user'] = [
                'id' => (int)$row['id'], 'username' => $row['username'], 'full_name' => $row['full_name'],
                'role' => $row['role'], 'district_id' => $row['district_id'], 'temple_id' => $row['temple_id'],
            ];
            audit('login', 'user', (int)$row['id']);
            redirect('index.php');
        }
        $_SESSION['login_fail']++;
        $error = 'ชื่อผู้ใช้หรือรหัสผ่านไม่ถูกต้อง';
    }
}

$pageTitle = 'เข้าสู่ระบบ';
require __DIR__ . '/includes/header.php';
?>
<div class="card login-box">
  <h2>เข้าสู่ระบบ</h2>
  <?php if ($error): ?><div class="alert alert-err"><?= e($error) ?></div><?php endif; ?>
  <form method="post" class="form-grid">
    <?= csrf_field() ?>
    <div class="full"><label>ชื่อผู้ใช้</label><input name="username" required autofocus></div>
    <div class="full"><label>รหัสผ่าน</label><input type="password" name="password" required></div>
    <div class="full"><button class="btn" type="submit">เข้าสู่ระบบ</button></div>
  </form>
</div>
<?php require __DIR__ . '/includes/footer.php'; ?>
