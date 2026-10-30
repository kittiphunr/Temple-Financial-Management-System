<?php
require __DIR__ . '/../includes/bootstrap.php';
require_admin();
$pdo = db();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $id = (int)($_POST['id'] ?? 0);
    $act = $_POST['action'] ?? '';
    if ($act === 'toggle' && $id) {
        $pdo->prepare('UPDATE temples SET is_active = 1 - is_active WHERE id = ?')->execute([$id]);
        audit('toggle', 'temple', $id);
    } elseif ($act === 'save') {
        $f = [
            (int)$_POST['district_id'], trim($_POST['name'] ?? ''), trim($_POST['subdistrict'] ?? '') ?: null,
            trim($_POST['moo'] ?? '') ?: null, trim($_POST['abbot'] ?? '') ?: null, trim($_POST['phone'] ?? '') ?: null,
            isset($_POST['verified']) ? 1 : 0,
        ];
        if ($f[1] === '' || !$f[0]) {
            flash('กรุณากรอกชื่อวัดและเลือก' . unit_district(), 'err');
        } else {
            try {
                if ($id) {
                    $pdo->prepare('UPDATE temples SET district_id=?, name=?, subdistrict=?, moo=?, abbot=?, phone=?, verified=? WHERE id=?')->execute([...$f, $id]);
                    audit('update', 'temple', $id, $f[1]);
                } else {
                    $pdo->prepare('INSERT INTO temples (district_id,name,subdistrict,moo,abbot,phone,verified) VALUES (?,?,?,?,?,?,?)')->execute($f);
                    $newId = (int)$pdo->lastInsertId();
                    audit('create', 'temple', $newId, $f[1]);
                    // เปิดบัญชีให้วัดใหม่อัตโนมัติ (1 วัด = 1 บัญชี)
                    if ($acct = provision_temple_accounts($pdo, [$newId])) {
                        [, , , $accUser, $accPass] = $acct[0];
                        flash("บันทึกวัดแล้ว และสร้างบัญชีให้อัตโนมัติ: ชื่อผู้ใช้ $accUser รหัสผ่านเริ่มต้น  $accPass  (แสดงครั้งเดียว) — วัดนี้จะเข้าสู่ระบบได้เมื่อลงอีเมลที่เมนู บัญชีวัด");
                        redirect('temples.php');
                    }
                }
                flash('บันทึกข้อมูลวัดแล้ว');
            } catch (PDOException $ex) {
                flash($ex->getCode() === '23000' ? 'มีวัดชื่อนี้ใน' . unit_district() . 'นี้แล้ว' : 'บันทึกไม่สำเร็จ', 'err');
            }
        }
    }
    redirect('temples.php');
}

$districts = $pdo->query('SELECT id, name FROM districts ORDER BY sort_order')->fetchAll();
$edit = null;
if (!empty($_GET['edit'])) {
    $st = $pdo->prepare('SELECT * FROM temples WHERE id = ?');
    $st->execute([(int)$_GET['edit']]);
    $edit = $st->fetch() ?: null;
}
$rows = $pdo->query('SELECT t.*, d.name dn FROM temples t JOIN districts d ON d.id = t.district_id ORDER BY d.sort_order, t.name')->fetchAll();

$pageTitle = 'จัดการวัด';
require __DIR__ . '/../includes/header.php';
?>
<h2>จัดการวัด</h2>
<div class="rounded-2xl border border-stone-200 bg-white p-4 shadow-sm">
  <h3><?= $edit ? 'แก้ไขวัด' : 'เพิ่มวัด' ?></h3>
  <form method="post" class="grid gap-3 sm:grid-cols-2 lg:grid-cols-3 [&_input:not([type=checkbox])]:w-full [&_select]:w-full">
    <?= csrf_field() ?><input type="hidden" name="action" value="save"><input type="hidden" name="id" value="<?= (int)($edit['id'] ?? 0) ?>">
    <div><label><?= e(unit_district()) ?></label><select name="district_id" required>
      <?php foreach ($districts as $d): ?><option value="<?= (int)$d['id'] ?>" <?= ($edit['district_id'] ?? 0) == $d['id'] ? 'selected' : '' ?>><?= e($d['name']) ?></option><?php endforeach; ?></select></div>
    <div><label>ชื่อวัด</label><input name="name" required value="<?= e($edit['name'] ?? '') ?>"></div>
    <div><label><?= e(unit_subdistrict()) ?></label><input name="subdistrict" value="<?= e($edit['subdistrict'] ?? '') ?>"></div>
    <div><label>หมู่ที่</label><input name="moo" value="<?= e($edit['moo'] ?? '') ?>"></div>
    <div><label>เจ้าอาวาส</label><input name="abbot" value="<?= e($edit['abbot'] ?? '') ?>"></div>
    <div><label>โทรศัพท์</label><input name="phone" value="<?= e($edit['phone'] ?? '') ?>"></div>
    <div><label><input type="checkbox" name="verified" <?= !empty($edit['verified']) ? 'checked' : '' ?>> ตรวจสอบกับทะเบียนวัดแล้ว</label></div>
    <div class="col-span-full"><button class="btn">บันทึก</button> <?php if ($edit): ?><a class="btn btn-outline" href="temples.php">ยกเลิก</a><?php endif; ?></div>
  </form>
</div>
<div class="mt-4 overflow-x-auto rounded-2xl border border-stone-200 bg-white shadow-sm"><table class="data-table">
  <tr><th><?= e(unit_district()) ?></th><th>วัด</th><th><?= e(unit_subdistrict()) ?></th><th>สถานะ</th><th></th></tr>
  <?php foreach ($rows as $r): ?>
  <tr class="<?= $r['is_active'] ? '' : 'opacity-50' ?>">
    <td><?= e($r['dn']) ?></td><td><?= e($r['name']) ?></td><td><?= e($r['subdistrict']) ?></td>
    <td><?= $r['verified'] ? '<span class="badge badge-ok">ตรวจสอบแล้ว</span>' : '<span class="badge">รอตรวจสอบ</span>' ?></td>
    <td class="whitespace-nowrap"><a class="btn btn-sm btn-outline" href="?edit=<?= (int)$r['id'] ?>">แก้ไข</a>
      <a class="btn btn-sm btn-outline" href="../profile.php?temple=<?= (int)$r['id'] ?>">โลโก้/ที่อยู่</a>
      <a class="btn btn-sm btn-outline" href="../people.php?temple=<?= (int)$r['id'] ?>">บุคลากร</a>
      <form method="post" class="inline"><?= csrf_field() ?><input type="hidden" name="action" value="toggle"><input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
        <button class="btn btn-sm btn-outline"><?= $r['is_active'] ? 'ปิดใช้งาน' : 'เปิดใช้งาน' ?></button></form></td>
  </tr>
  <?php endforeach; ?>
</table></div>
<?php require __DIR__ . '/../includes/footer.php'; ?>
