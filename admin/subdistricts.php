<?php
/**
 * เจ้าคณะตำบล — ผู้ใช้ที่ดูข้อมูลวัดในตำบลที่ดูแลได้อย่างเดียว (1 บัญชีดูได้หลายตำบล)
 * ไม่นับในโควตาผู้ใช้ระดับจังหวัด/อำเภอ (จัดการโควตาที่ ผู้ใช้ระดับจังหวัด/อำเภอ)
 */
require __DIR__ . '/../includes/bootstrap.php';
$me = require_admin();
$pdo = db();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $act = $_POST['action'] ?? '';
    $id = (int)($_POST['id'] ?? 0);
    $own = function (int $uid) use ($pdo): bool {
        $st = $pdo->prepare("SELECT 1 FROM users WHERE id = ? AND role = 'subdistrict'");
        $st->execute([$uid]);
        return (bool)$st->fetchColumn();
    };
    if ($act === 'create') {
        [$ok, $msg] = subdistrict_create($pdo, trim($_POST['username'] ?? ''), trim($_POST['full_name'] ?? ''),
            (string)($_POST['password'] ?? ''), trim($_POST['email'] ?? ''), (array)($_POST['areas'] ?? []));
        flash($msg, $ok ? 'ok' : 'err');
    } elseif ($act === 'areas' && $own($id)) {
        [$items, $err] = subdistrict_parse_selection($pdo, (array)($_POST['areas'] ?? []));
        if ($err !== '') {
            flash($err, 'err');
        } else {
            subdistrict_save_assignments($pdo, $id, $items);
            audit('set_subdistricts', 'user', $id, 'x' . count($items));
            flash('บันทึก' . unit_subdistrict() . 'ที่ดูแลแล้ว (' . count($items) . ' ' . unit_subdistrict() . ')');
        }
    } elseif ($act === 'set_email' && $own($id)) {
        $email = trim($_POST['email'] ?? '');
        if (!valid_email($email)) {
            flash('รูปแบบอีเมลไม่ถูกต้อง', 'err');
        } else {
            $pdo->prepare('UPDATE users SET email = ?, email_verified_at = NULL WHERE id = ?')->execute([$email, $id]);
            otp_invalidate_all($pdo, $id);
            audit('set_staff_email', 'user', $id);
            flash('บันทึกอีเมลแล้ว');
        }
    } elseif ($act === 'toggle' && $own($id)) {
        $pdo->prepare('UPDATE users SET is_active = 1 - is_active WHERE id = ?')->execute([$id]);
        audit('toggle', 'user', $id);
    } elseif ($act === 'reset' && $own($id)) {
        $pw = (string)($_POST['password'] ?? '');
        if (mb_strlen($pw) < 10 || !preg_match('/[A-Za-z]/', $pw) || !preg_match('/\d/', $pw)) {
            flash('รหัสผ่านใหม่ต้องยาวอย่างน้อย 10 ตัว และมีทั้งตัวอักษรและตัวเลข', 'err');
        } else {
            $pdo->prepare('UPDATE users SET password_hash = ?, password_changed_at = NOW(), failed_logins = 0, locked_until = NULL WHERE id = ?')
                ->execute([password_hash($pw, PASSWORD_DEFAULT), $id]);
            audit('reset_password', 'user', $id);
            flash('เปลี่ยนรหัสผ่านแล้ว');
        }
    } elseif ($act === 'delete' && $own($id)) {
        $pdo->prepare('DELETE FROM users WHERE id = ?')->execute([$id]);   // user_subdistricts ถูกลบตาม (ON DELETE CASCADE)
        audit('delete', 'user', $id, 'subdistrict');
        flash('ลบบัญชีเจ้าคณะ' . unit_subdistrict() . 'แล้ว');
    }
    redirect('subdistricts.php');
}

$options = subdistrict_options($pdo);
$users = $pdo->query("SELECT id, username, full_name, email, is_active FROM users WHERE role = 'subdistrict' ORDER BY id")->fetchAll();

/** ช่องเลือกตำบล จัดกลุ่มตามอำเภอ — $picked = ชุดคีย์ "districtId|ตำบล" ที่ติ๊กไว้ */
$areaPicker = function (array $picked, string $idPrefix) use ($options): string {
    $by = [];
    foreach ($options as $o) {
        $by[$o['district']][] = $o;
    }
    $h = '';
    foreach ($by as $dn => $list) {
        $h .= '<fieldset class="rounded-xl border border-stone-200 p-2"><legend class="px-1 text-sm font-semibold">' . unit_district() . e($dn) . '</legend><div class="flex flex-wrap gap-x-4 gap-y-1">';
        foreach ($list as $o) {
            $k = $o['district_id'] . '|' . $o['subdistrict'];
            $h .= '<label class="!mb-0 text-sm"><input type="checkbox" name="areas[]" value="' . e($k) . '"' . (isset($picked[$k]) ? ' checked' : '') . '> ' . abbr_subdistrict() . e($o['subdistrict'])
                . ' <span class="text-stone-500">(' . $o['n'] . ' วัด)</span></label>';
        }
        $h .= '</div></fieldset>';
    }
    return $h ?: '<p class="text-sm text-stone-500">ยังไม่มีข้อมูล' . unit_subdistrict() . 'ของวัด — นำเข้าทะเบียนวัดก่อน</p>';
};

$pageTitle = 'เจ้าคณะ' . unit_subdistrict();
require __DIR__ . '/../includes/header.php';
?>
<h2>เจ้าคณะ<?= e(unit_subdistrict()) ?> (ดูข้อมูลอย่างเดียว)</h2>
<p class="mb-4 text-sm text-stone-600">บัญชีนี้ดูแดชบอร์ด รายงาน รายงานประจำเดือนแบบที่ 1–3 และรายชื่อบุคลากรของ <strong>วัดใน<?= e(unit_subdistrict()) ?>ที่ได้รับมอบหมายเท่านั้น</strong> — บันทึก/แก้ไขข้อมูลหรือออกอนุโมทนาบัตรไม่ได้
  1 บัญชีดูได้หลาย<?= e(unit_subdistrict()) ?> และไม่นับในโควตาผู้ใช้ระดับจังหวัด/<?= e(unit_district()) ?> ต้องมีอีเมลสำหรับรับรหัสยืนยันตัวตน 2 ชั้น</p>

<div class="card">
  <h3>เพิ่มบัญชีเจ้าคณะ<?= e(unit_subdistrict()) ?></h3>
  <form method="post" class="grid gap-3 sm:grid-cols-2">
    <?= csrf_field() ?><input type="hidden" name="action" value="create">
    <div><label for="username">ชื่อผู้ใช้</label><input id="username" name="username" required class="w-full" autocomplete="off"></div>
    <div><label for="full_name">ชื่อ-นามสกุล</label><input id="full_name" name="full_name" required class="w-full"></div>
    <div><label for="email">อีเมล (รับรหัสยืนยันตัวตนและกู้รหัสผ่าน)</label><input id="email" type="email" name="email" maxlength="190" class="w-full" autocomplete="off"></div>
    <div><label for="password">รหัสผ่านเริ่มต้น (อย่างน้อย 10 ตัว มีตัวอักษรและตัวเลข)</label><input id="password" type="password" name="password" minlength="10" required class="w-full" autocomplete="new-password"></div>
    <div class="sm:col-span-2"><label><?= e(unit_subdistrict()) ?>ที่ดูแล (เลือกได้หลาย<?= e(unit_subdistrict()) ?>)</label><div class="grid gap-2"><?= $areaPicker([], 'new') ?></div></div>
    <div class="sm:col-span-2"><button class="btn">สร้างบัญชี</button></div>
  </form>
</div>

<?php if (!$users): ?>
<p class="card text-stone-500">ยังไม่มีบัญชีเจ้าคณะ<?= e(unit_subdistrict()) ?></p>
<?php endif; ?>
<?php foreach ($users as $r):
    $assign = subdistrict_assignments($pdo, (int)$r['id']);
    $picked = [];
    foreach ($assign as $a) {
        $picked[$a['district_id'] . '|' . $a['subdistrict']] = true;
    } ?>
<div class="card">
  <div class="flex flex-wrap items-start justify-between gap-3">
    <div>
      <div class="font-semibold"><?= e($r['full_name']) ?> <span class="font-mono text-sm text-stone-500">(<?= e($r['username']) ?>)</span>
        <?= $r['is_active'] ? '<span class="badge badge-ok">ใช้งาน</span>' : '<span class="badge">ปิดใช้งาน</span>' ?></div>
      <div class="mt-1 text-sm text-stone-600">ดูแล: <?= $assign ? e(subdistrict_label($assign)) : '<span class="text-red-700">ยังไม่ได้กำหนด' . unit_subdistrict() . '</span>' ?></div>
      <div class="text-sm"><?= $r['email'] ? 'อีเมล ' . e(mask_email($r['email'])) : '<span class="text-red-700">ยังไม่มีอีเมล — เข้าสู่ระบบไม่ได้</span>' ?></div>
    </div>
    <div class="flex flex-wrap items-center gap-2">
      <form method="post" class="inline"><?= csrf_field() ?><input type="hidden" name="action" value="toggle"><input type="hidden" name="id" value="<?= (int)$r['id'] ?>"><button class="btn btn-sm btn-outline"><?= $r['is_active'] ? 'ปิดใช้งาน' : 'เปิดใช้งาน' ?></button></form>
      <form method="post" class="inline" data-confirm="ลบบัญชีนี้?"><?= csrf_field() ?><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= (int)$r['id'] ?>"><button class="btn btn-sm btn-danger"><?= icon('trash-2') ?> ลบ</button></form>
    </div>
  </div>
  <details class="mt-3">
    <summary class="cursor-pointer text-sm font-semibold text-brand-700">แก้ไข<?= e(unit_subdistrict()) ?> / อีเมล / รหัสผ่าน</summary>
    <form method="post" class="mt-3 grid gap-2"><?= csrf_field() ?><input type="hidden" name="action" value="areas"><input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
      <?= $areaPicker($picked, 'u' . $r['id']) ?>
      <div><button class="btn btn-sm">บันทึก<?= e(unit_subdistrict()) ?>ที่ดูแล</button></div></form>
    <div class="mt-3 flex flex-wrap gap-4">
      <form method="post" class="flex items-center gap-1"><?= csrf_field() ?><input type="hidden" name="action" value="set_email"><input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
        <input type="email" name="email" placeholder="<?= $r['email'] ? 'เปลี่ยนอีเมล' : 'อีเมล' ?>" maxlength="190" required class="w-52 ctl-sm"><button class="btn btn-sm btn-outline">บันทึกอีเมล</button></form>
      <form method="post" class="flex items-center gap-1"><?= csrf_field() ?><input type="hidden" name="action" value="reset"><input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
        <input type="password" name="password" placeholder="รหัสผ่านใหม่ (10+ ตัว)" minlength="10" class="w-48 ctl-sm" autocomplete="new-password"><button class="btn btn-sm btn-outline">เปลี่ยนรหัส</button></form>
    </div>
  </details>
</div>
<?php endforeach; ?>
<?php require __DIR__ . '/../includes/footer.php'; ?>
