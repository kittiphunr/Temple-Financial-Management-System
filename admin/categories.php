<?php
require __DIR__ . '/../includes/bootstrap.php';
require_admin();
$pdo = db();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $act = $_POST['action'] ?? '';
    if ($act === 'create') {
        $type = ($_POST['type'] ?? '') === 'expense' ? 'expense' : 'income';
        $name = trim($_POST['name'] ?? '');
        if ($name !== '') {
            try {
                $pdo->prepare('INSERT INTO categories (type, name, sort_order, is_donation) VALUES (?,?,?,?)')->execute([$type, $name, (int)($_POST['sort_order'] ?? 5), $type === 'income' && !empty($_POST['is_donation']) ? 1 : 0]);
                audit('create', 'category', (int)$pdo->lastInsertId(), $name);
                flash('เพิ่มหมวดแล้ว');
            } catch (PDOException) {
                flash('มีหมวดชื่อนี้แล้ว', 'err');
            }
        }
    } elseif ($act === 'toggle_donation') {
        $pdo->prepare("UPDATE categories SET is_donation = 1 - is_donation WHERE id = ? AND type = 'income'")->execute([(int)$_POST['id']]);
    } elseif ($act === 'toggle') {
        $pdo->prepare('UPDATE categories SET is_active = 1 - is_active WHERE id = ?')->execute([(int)$_POST['id']]);
    }
    redirect('categories.php');
}
$rows = $pdo->query('SELECT * FROM categories ORDER BY type, sort_order, name')->fetchAll();
$pageTitle = 'หมวดบัญชี';
require __DIR__ . '/../includes/header.php';
?>
<h2>หมวดรายรับ-รายจ่าย</h2>
<div class="rounded-2xl border border-stone-200 bg-white p-4 shadow-sm">
  <form method="post" class="grid gap-3 sm:grid-cols-2 lg:grid-cols-3 [&_input:not([type=checkbox])]:w-full [&_select]:w-full">
    <?= csrf_field() ?><input type="hidden" name="action" value="create">
    <div><label>ประเภท</label><select name="type"><option value="income">รายรับ</option><option value="expense">รายจ่าย</option></select></div>
    <div><label>ชื่อหมวด</label><input name="name" required></div>
    <div><label>ลำดับ</label><input type="number" name="sort_order" value="5"></div>
    <div class="col-span-full"><label class="inline-flex items-center gap-2"><input type="checkbox" name="is_donation" value="1"> เป็นหมวดรับบริจาค (ออกอนุโมทนาบัตรได้ — ใช้กับรายรับเท่านั้น)</label></div>
    <div class="col-span-full"><button class="btn">เพิ่มหมวด</button></div>
  </form>
</div>
<div class="mt-4 overflow-x-auto rounded-2xl border border-stone-200 bg-white shadow-sm"><table class="data-table">
  <tr><th>ประเภท</th><th>หมวด</th><th>รับบริจาค</th><th>สถานะ</th><th></th></tr>
  <?php foreach ($rows as $r): ?>
  <tr><td><?= $r['type'] === 'income' ? 'รายรับ' : 'รายจ่าย' ?></td><td><?= e($r['name']) ?></td><td><?php if ($r['type'] === 'income'): ?><form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="toggle_donation"><input type="hidden" name="id" value="<?= (int)$r['id'] ?>"><button class="btn btn-sm btn-outline"><?= $r['is_donation'] ? 'ใช่ (ออกอนุโมทนาบัตรได้)' : 'ไม่' ?></button></form><?php else: ?>–<?php endif; ?></td><td><?= $r['is_active'] ? 'ใช้งาน' : 'ปิด' ?></td>
    <td><form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="toggle"><input type="hidden" name="id" value="<?= (int)$r['id'] ?>"><button class="btn btn-sm btn-outline"><?= $r['is_active'] ? 'ปิดใช้งาน' : 'เปิดใช้งาน' ?></button></form></td></tr>
  <?php endforeach; ?>
</table></div>
<?php require __DIR__ . '/../includes/footer.php'; ?>
