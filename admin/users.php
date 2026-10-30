<?php
/**
 * ผู้ใช้ระดับจังหวัด/อำเภอ — จำกัดจำนวนด้วย "ช่องตำแหน่ง":
 * แอดมินประจำอำเภอ (อำเภอละ 1) + แอดมินหลัก (1) + ผู้ใช้เจ้าคณะจังหวัด (1) = จำนวนอำเภอ + 2 (จังหวัดแพร่ = 10 คน)
 * บัญชีแอดมินวัดไม่นับ (สร้างอัตโนมัติและจัดการที่หน้า บัญชีวัด)
 */
require __DIR__ . '/../includes/bootstrap.php';
$me = require_admin();
$pdo = db();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $act = $_POST['action'] ?? '';
    $id = (int)($_POST['id'] ?? 0);
    if ($act === 'create') {
        [$ok, $msg] = staff_create($pdo, (string)($_POST['slot'] ?? ''), trim($_POST['username'] ?? ''),
            trim($_POST['full_name'] ?? ''), (string)($_POST['password'] ?? ''), trim($_POST['email'] ?? ''));
        flash($msg, $ok ? 'ok' : 'err');
    } elseif ($act === 'set_email' && $id) {
        $email = trim($_POST['email'] ?? '');
        if (!valid_email($email)) {
            flash('รูปแบบอีเมลไม่ถูกต้อง', 'err');
        } else {
            // แอดมินหลักเปลี่ยนอีเมลตัวเองที่ "บัญชีของฉัน" (ต้องยืนยันรหัส) — ที่นี่แก้ได้เฉพาะแอดมินอำเภอและเจ้าคณะจังหวัด
            $n = $pdo->prepare("UPDATE users SET email = ?, email_verified_at = NULL WHERE id = ? AND role IN ('district','province')");
            $n->execute([$email, $id]);
            if ($n->rowCount()) {
                otp_invalidate_all($pdo, $id);
                audit('set_staff_email', 'user', $id);
                flash('บันทึกอีเมลแล้ว');
            }
        }
    } elseif ($act === 'toggle' && $id && $id !== $me['id']) {
        $pdo->prepare("UPDATE users SET is_active = 1 - is_active WHERE id = ? AND role <> 'temple'")->execute([$id]);
        audit('toggle', 'user', $id);
    } elseif ($act === 'reset' && $id && mb_strlen($_POST['password'] ?? '') >= 10) {
        $pdo->prepare("UPDATE users SET password_hash = ?, password_changed_at = NOW(), failed_logins = 0, locked_until = NULL WHERE id = ? AND role <> 'temple'")
            ->execute([password_hash($_POST['password'], PASSWORD_DEFAULT), $id]);
        audit('reset_password', 'user', $id);
        flash('เปลี่ยนรหัสผ่านแล้ว');
    } elseif ($act === 'reset') {
        flash('รหัสผ่านใหม่ต้องยาวอย่างน้อย 10 ตัวอักษร', 'err');
    } elseif ($act === 'delete_extra' && $id && $id !== $me['id']) {
        // ลบเฉพาะผู้ใช้ที่เกินโควตา (ไม่ได้ครองช่องใด) เพื่อให้ยอดกลับมาอยู่ในเกณฑ์
        foreach (staff_extras($pdo) as $x) {
            if ((int)$x['id'] === $id) {
                $pdo->prepare('DELETE FROM users WHERE id = ?')->execute([$id]);
                audit('delete', 'user', $id, 'extra');
                flash('ลบผู้ใช้ที่เกินโควตาแล้ว');
            }
        }
    }
    redirect('users.php');
}

$slots = staff_slots($pdo);
$extras = staff_extras($pdo);
$cap = staff_capacity($pdo);
$used = staff_count($pdo);
$vacant = array_values(array_filter($slots, fn($s) => !$s['user']));

$pageTitle = 'ผู้ใช้งาน';
require __DIR__ . '/../includes/header.php';
?>
<div class="mb-3 flex flex-wrap items-end justify-between gap-3">
  <h2 class="!my-0">ผู้ใช้ระดับจังหวัดและอำเภอ</h2>
  <span class="rounded-full border px-4 py-1.5 text-sm font-semibold <?= $used > $cap ? 'border-red-300 bg-red-50 text-red-800' : 'border-stone-300 bg-white' ?>">ใช้ไป <?= $used ?> จาก <?= $cap ?> คน</span>
</div>
<p class="mb-4 text-sm text-stone-600">โควตา = แอดมินประจำอำเภอ (อำเภอละ 1 คน) + แอดมินหลัก 1 คน + ผู้ใช้เจ้าคณะจังหวัด 1 คน = จำนวนอำเภอ + 2
  บัญชีแอดมินประจำวัดไม่นับ — จัดการที่ <a class="font-semibold" href="temple_accounts.php">บัญชีวัด</a></p>

<?php if ($vacant): ?>
<div class="mb-4 rounded-2xl border border-stone-200 bg-white p-4 shadow-sm">
  <h3>เพิ่มผู้ใช้ในตำแหน่งที่ว่าง (<?= count($vacant) ?> ตำแหน่ง)</h3>
  <form method="post" class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
    <?= csrf_field() ?><input type="hidden" name="action" value="create">
    <div class="sm:col-span-2"><label for="slot">ตำแหน่ง</label>
      <select id="slot" name="slot" class="w-full" required><?php foreach ($vacant as $s): ?><option value="<?= e($s['key']) ?>"><?= e($s['label']) ?></option><?php endforeach; ?></select></div>
    <div><label for="username">ชื่อผู้ใช้</label><input id="username" name="username" required class="w-full" autocomplete="off"></div>
    <div><label for="full_name">ชื่อ-นามสกุล</label><input id="full_name" name="full_name" required class="w-full"></div>
    <div class="sm:col-span-2"><label for="email">อีเมล (รับรหัสยืนยันตัวตนและกู้รหัสผ่าน<?= staff_2fa_enabled() ? ' — จำเป็นสำหรับอำเภอ/เจ้าคณะจังหวัด' : '' ?>)</label><input id="email" type="email" name="email" maxlength="190" class="w-full" autocomplete="off"></div>
    <div class="sm:col-span-2"><label for="password">รหัสผ่าน (อย่างน้อย 10 ตัว มีตัวอักษรและตัวเลข)</label><input id="password" type="password" name="password" minlength="10" required class="w-full" autocomplete="new-password"></div>
    <div class="flex items-end"><button class="btn">สร้างผู้ใช้</button></div>
  </form>
</div>
<?php else: ?>
<div class="mb-4 rounded-2xl border border-emerald-200 bg-emerald-50 p-3 text-sm text-emerald-900">ครบทุกตำแหน่งแล้ว (<?= $cap ?> คน) — ต้องการเปลี่ยนผู้ดำรงตำแหน่ง ให้ปิดใช้งานผู้ใช้เดิมหรือรีเซ็ตรหัสผ่านให้ผู้สืบทอด</div>
<?php endif; ?>

<div class="overflow-x-auto rounded-2xl border border-stone-200 bg-white shadow-sm"><table class="data-table">
  <thead><tr><th>ตำแหน่ง</th><th>ผู้ใช้</th><th>ชื่อ</th><th>อีเมลยืนยัน</th><th>สถานะ</th><th></th></tr></thead>
  <tbody>
  <?php foreach ($slots as $s): $r = $s['user']; ?>
  <tr>
    <td class="font-semibold"><?= e($s['label']) ?></td>
    <?php if ($r): ?>
    <td class="font-mono text-sm"><?= e($r['username']) ?></td><td><?= e($r['full_name']) ?></td>
    <td class="text-sm">
      <?php if ($r['email']): ?><span class="whitespace-nowrap"><?= e(mask_email($r['email'])) ?></span><?php else: ?><span class="text-red-700"><?= $r['role'] === 'admin' ? 'ยังไม่ยืนยัน (ยืนยันตอนเข้าสู่ระบบ)' : 'ยังไม่มีอีเมล — เข้าสู่ระบบไม่ได้' ?></span><?php endif; ?>
      <?php if (in_array($r['role'], ['district', 'province'], true)): ?>
      <form method="post" class="mt-1 flex gap-1"><?= csrf_field() ?><input type="hidden" name="action" value="set_email"><input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
        <input type="email" name="email" placeholder="<?= $r['email'] ? 'เปลี่ยนอีเมล' : 'อีเมล' ?>" maxlength="190" required class="w-44 !py-1 text-sm"><button class="btn btn-sm btn-outline">บันทึก</button></form>
      <?php endif; ?>
    </td>
    <td><?= $r['is_active'] ? '<span class="badge badge-ok">ใช้งาน</span>' : '<span class="badge">ปิดใช้งาน</span>' ?></td>
    <td class="whitespace-nowrap">
      <?php if ((int)$r['id'] !== $me['id']): ?>
      <form method="post" class="inline"><?= csrf_field() ?><input type="hidden" name="action" value="toggle"><input type="hidden" name="id" value="<?= (int)$r['id'] ?>"><button class="btn btn-sm btn-outline"><?= $r['is_active'] ? 'ปิด' : 'เปิด' ?></button></form>
      <?php endif; ?>
      <form method="post" class="inline"><?= csrf_field() ?><input type="hidden" name="action" value="reset"><input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
        <input type="password" name="password" placeholder="รหัสผ่านใหม่ (10+ ตัว)" minlength="10" class="w-40 !py-1 text-sm" autocomplete="new-password"><button class="btn btn-sm btn-outline ml-1">เปลี่ยนรหัส</button></form>
    </td>
    <?php else: ?>
    <td colspan="5" class="text-stone-500">— ว่าง —</td>
    <?php endif; ?>
  </tr>
  <?php endforeach; ?>
  </tbody>
</table></div>

<?php if ($extras): ?>
<div class="mt-4 rounded-2xl border border-red-200 bg-red-50 p-4">
  <h3 class="text-red-900">ผู้ใช้ที่เกินโควตา (<?= count($extras) ?>)</h3>
  <p class="mb-2 text-sm text-red-900">ผู้ใช้เหล่านี้ไม่ได้อยู่ในตำแหน่งใดเลย (มักมาจากข้อมูลเก่า) ควรลบเพื่อให้จำนวนอยู่ในเกณฑ์</p>
  <ul class="grid gap-1 text-sm">
    <?php foreach ($extras as $x): ?>
    <li class="flex items-center justify-between gap-2 rounded-lg bg-white px-3 py-2"><span><span class="font-mono"><?= e($x['username']) ?></span> — <?= e($x['full_name']) ?> (<?= e($x['role']) ?>)</span>
      <?php if ((int)$x['id'] !== $me['id']): ?>
      <form method="post" data-confirm="ลบผู้ใช้นี้?"><?= csrf_field() ?><input type="hidden" name="action" value="delete_extra"><input type="hidden" name="id" value="<?= (int)$x['id'] ?>"><button class="btn btn-sm btn-danger">ลบ</button></form>
      <?php endif; ?></li>
    <?php endforeach; ?>
  </ul>
</div>
<?php endif; ?>
<?php require __DIR__ . '/../includes/footer.php'; ?>
