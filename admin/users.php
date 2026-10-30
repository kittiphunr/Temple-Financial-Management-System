<?php
require __DIR__ . '/../includes/bootstrap.php';
$me = require_admin();
$pdo = db();

// หน้านี้จัดการเฉพาะแอดมินหลักและเจ้าหน้าที่อำเภอ — บัญชีวัด (1 วัด = 1 บัญชี) จัดการที่ temple_accounts.php
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $act = $_POST['action'] ?? '';
    $id = (int)($_POST['id'] ?? 0);
    if ($act === 'create') {
        $role = ($_POST['role'] ?? '') === 'admin' ? 'admin' : 'district';
        $uname = trim($_POST['username'] ?? '');
        $pw = $_POST['password'] ?? '';
        $did = $role === 'district' ? (int)$_POST['district_id'] : null;
        if (!preg_match('/^[A-Za-z0-9_.-]{3,50}$/', $uname) || strlen($pw) < 8 || trim($_POST['full_name'] ?? '') === ''
            || ($role === 'district' && !$did)) {
            flash('ข้อมูลไม่ครบ: ชื่อผู้ใช้ 3+ ตัว (a-z 0-9), รหัสผ่านอย่างน้อย 8 ตัว, ระบุอำเภอสำหรับเจ้าหน้าที่อำเภอ', 'err');
        } else {
            try {
                $pdo->prepare('INSERT INTO users (username,password_hash,full_name,role,district_id,password_changed_at) VALUES (?,?,?,?,?,NOW())')
                    ->execute([$uname, password_hash($pw, PASSWORD_DEFAULT), trim($_POST['full_name']), $role, $did]);
                audit('create', 'user', (int)$pdo->lastInsertId(), $uname);
                flash('เพิ่มผู้ใช้แล้ว');
            } catch (PDOException) {
                flash('ชื่อผู้ใช้นี้มีอยู่แล้ว', 'err');
            }
        }
    } elseif ($act === 'toggle' && $id && $id !== $me['id']) {
        $pdo->prepare("UPDATE users SET is_active = 1 - is_active WHERE id = ? AND role <> 'temple'")->execute([$id]);
        audit('toggle', 'user', $id);
    } elseif ($act === 'reset' && $id && strlen($_POST['password'] ?? '') >= 8) {
        $pdo->prepare("UPDATE users SET password_hash = ?, password_changed_at = NOW(), failed_logins = 0, locked_until = NULL WHERE id = ? AND role <> 'temple'")
            ->execute([password_hash($_POST['password'], PASSWORD_DEFAULT), $id]);
        audit('reset_password', 'user', $id);
        flash('เปลี่ยนรหัสผ่านแล้ว');
    }
    redirect('users.php');
}

$districts = $pdo->query('SELECT id, name FROM districts ORDER BY sort_order')->fetchAll();
$rows = $pdo->query("SELECT u.*, d.name dn FROM users u LEFT JOIN districts d ON d.id=u.district_id WHERE u.role <> 'temple' ORDER BY u.id")->fetchAll();
$roleTh = ['admin' => 'ผู้ดูแลระบบหลัก', 'district' => 'เจ้าหน้าที่อำเภอ'];

$pageTitle = 'ผู้ใช้งาน';
require __DIR__ . '/../includes/header.php';
?>
<h2>ผู้ใช้งาน (แอดมินหลัก / เจ้าหน้าที่อำเภอ)</h2>
<p class="mb-3 text-sm text-stone-600">บัญชีของแต่ละวัดสร้างอัตโนมัติและจัดการที่ <a class="font-semibold" href="temple_accounts.php">บัญชีวัด</a></p>
<div class="rounded-2xl border border-stone-200 bg-white p-4 shadow-sm">
  <h3>เพิ่มผู้ใช้</h3>
  <form method="post" class="grid gap-3 sm:grid-cols-2 lg:grid-cols-3 [&_input:not([type=checkbox])]:w-full [&_select]:w-full">
    <?= csrf_field() ?><input type="hidden" name="action" value="create">
    <div><label>ชื่อผู้ใช้</label><input name="username" required></div>
    <div><label>ชื่อ-นามสกุล</label><input name="full_name" required></div>
    <div><label>รหัสผ่าน (8 ตัวขึ้นไป)</label><input type="password" name="password" minlength="8" required autocomplete="new-password"></div>
    <div><label>บทบาท</label><select name="role"><?php foreach ($roleTh as $k => $v): ?><option value="<?= $k ?>"><?= $v ?></option><?php endforeach; ?></select></div>
    <div><label>อำเภอ (สำหรับเจ้าหน้าที่อำเภอ)</label><select name="district_id"><option value="0">-</option><?php foreach ($districts as $d): ?><option value="<?= (int)$d['id'] ?>"><?= e($d['name']) ?></option><?php endforeach; ?></select></div>
    <div class="col-span-full"><button class="btn">เพิ่มผู้ใช้</button></div>
  </form>
</div>
<div class="mt-4 overflow-x-auto rounded-2xl border border-stone-200 bg-white shadow-sm"><table class="data-table">
  <tr><th>ผู้ใช้</th><th>ชื่อ</th><th>บทบาท</th><th>ขอบเขต</th><th>สถานะ</th><th></th></tr>
  <?php foreach ($rows as $r): ?>
  <tr>
    <td><?= e($r['username']) ?></td><td><?= e($r['full_name']) ?></td><td><?= e($roleTh[$r['role']]) ?></td>
    <td><?= e($r['role'] === 'district' ? 'อ.' . $r['dn'] : 'ทุกวัด') ?></td>
    <td><?= $r['is_active'] ? 'ใช้งาน' : 'ปิดใช้งาน' ?></td>
    <td>
      <?php if ((int)$r['id'] !== $me['id']): ?>
      <form method="post" class="inline"><?= csrf_field() ?><input type="hidden" name="action" value="toggle"><input type="hidden" name="id" value="<?= (int)$r['id'] ?>"><button class="btn btn-sm btn-outline"><?= $r['is_active'] ? 'ปิด' : 'เปิด' ?></button></form>
      <?php endif; ?>
      <form method="post" class="inline"><?= csrf_field() ?><input type="hidden" name="action" value="reset"><input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
        <input type="password" name="password" placeholder="รหัสผ่านใหม่" minlength="8" class="w-32" autocomplete="new-password"><button class="btn btn-sm btn-outline ml-1">เปลี่ยนรหัส</button></form>
    </td>
  </tr>
  <?php endforeach; ?>
</table></div>
<?php require __DIR__ . '/../includes/footer.php'; ?>
