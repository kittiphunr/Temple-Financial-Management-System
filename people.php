<?php
/** บุคลากรของวัด: เจ้าอาวาส / พระลูกวัด / ไวยาวัจกร */
require __DIR__ . '/includes/bootstrap.php';
$u = require_login();
$pdo = db();

$tid = $u['role'] === 'temple' ? (int)$u['temple_id'] : (int)($_GET['temple'] ?? 0);

// แอดมินหลัก/เจ้าคณะจังหวัด/แอดมินอำเภอ: เลือกวัดก่อน
if (!$tid) {
    $groups = temples_grouped();
    $pageTitle = 'บุคลากรวัด';
    require __DIR__ . '/includes/header.php';
    ?>
    <h2>บุคลากรวัด</h2>
    <p class="mb-3 text-sm text-stone-600">เลือกวัดเพื่อดูรายชื่อเจ้าอาวาส พระลูกวัด และไวยาวัจกร</p>
    <form method="get" class="flex flex-wrap items-end gap-3">
      <div><label for="temple">วัด</label>
        <select id="temple" name="temple" class="w-72" required>
          <option value="">— เลือกวัด —</option>
          <?php foreach ($groups as $dn => $list): ?><optgroup label="อำเภอ<?= e($dn) ?>"><?php foreach ($list as $t): ?><option value="<?= (int)$t['id'] ?>"><?= e($t['name']) ?></option><?php endforeach; ?></optgroup><?php endforeach; ?>
        </select></div>
      <button class="btn">แสดง</button>
    </form>
    <?php
    require __DIR__ . '/includes/footer.php';
    exit;
}

if (!people_can_view($tid)) {
    http_response_code(403);
    exit('ไม่มีสิทธิ์ดูข้อมูลบุคลากรของวัดนี้');
}
$t = temple_row($tid);
$canEdit = people_can_edit($tid);
$self = 'people.php' . ($u['role'] === 'temple' ? '' : '?temple=' . $tid);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    if (!$canEdit) {
        http_response_code(403);
        exit('ไม่มีสิทธิ์แก้ไขข้อมูลบุคลากร');
    }
    $act = $_POST['action'] ?? '';
    $pid = (int)($_POST['id'] ?? 0);
    $st = $pdo->prepare('SELECT * FROM temple_people WHERE id = ? AND temple_id = ?');
    $st->execute([$pid, $tid]);
    $p = $st->fetch();
    if ($p && $act === 'toggle') {
        if (!$p['is_active'] && $p['position'] === 'abbot') {
            $c = $pdo->prepare("SELECT COUNT(*) FROM temple_people WHERE temple_id = ? AND position = 'abbot' AND is_active = 1");
            $c->execute([$tid]);
            if ((int)$c->fetchColumn() > 0) {
                flash('วัดนี้มีเจ้าอาวาสอยู่แล้ว ไม่สามารถให้อีกท่านกลับเข้าตำแหน่งเจ้าอาวาสพร้อมกันได้', 'err');
                redirect($self);
            }
        }
        $pdo->prepare('UPDATE temple_people SET is_active = 1 - is_active WHERE id = ?')->execute([$pid]);
        sync_abbot_name($pdo, $tid);
        audit('toggle', 'person', $pid);
        flash($p['is_active'] ? 'เปลี่ยนสถานะเป็น "พ้นตำแหน่ง" แล้ว' : 'ให้กลับเข้าตำแหน่งแล้ว');
    } elseif ($p && $act === 'delete') {
        $pdo->prepare('DELETE FROM temple_people WHERE id = ?')->execute([$pid]);
        media_delete($pdo, $p['photo_media_id'] ? (int)$p['photo_media_id'] : null);
        sync_abbot_name($pdo, $tid);
        audit('delete', 'person', $pid);   // บันทึกประวัติโดยไม่เก็บข้อมูลส่วนบุคคล
        flash('ลบข้อมูลบุคคลแล้ว (รวมเลขบัตรประชาชนและรูปถ่าย)');
    }
    redirect($self);
}

$st = $pdo->prepare("SELECT * FROM temple_people WHERE temple_id = ?
    ORDER BY is_active DESC, FIELD(position,'abbot','monk','steward'), id");
$st->execute([$tid]);
$byPos = ['abbot' => [], 'monk' => [], 'steward' => []];
foreach ($st->fetchAll() as $p) {
    $byPos[$p['position']][] = $p;
}
$d = $pdo->prepare('SELECT name FROM districts WHERE id = ?');
$d->execute([$t['district_id']]);

$pageTitle = 'บุคลากรวัด';
require __DIR__ . '/includes/header.php';
?>
<div class="mb-3 flex flex-wrap items-end justify-between gap-3">
  <div>
    <h2 class="!my-0">บุคลากร<?= e($t['name']) ?></h2>
    <p class="text-sm text-stone-600">อำเภอ<?= e($d->fetchColumn()) ?><?= $canEdit ? '' : ' · ดูข้อมูลได้อย่างเดียว (เลขบัตรประชาชนและที่อยู่ไม่แสดงให้ผู้ใช้ระดับนี้)' ?></p>
  </div>
  <?php if ($canEdit): ?>
  <div class="flex flex-wrap gap-2">
    <?php foreach (PERSON_POSITIONS as $k => $label): ?>
      <a class="btn btn-sm" href="person.php?temple=<?= $tid ?>&amp;position=<?= $k ?>">＋ <?= e($label) ?></a>
    <?php endforeach; ?>
    <a class="btn btn-outline btn-sm" href="profile.php<?= $u['role'] === 'admin' ? '?temple=' . $tid : '' ?>">ข้อมูล/โลโก้วัด</a>
  </div>
  <?php endif; ?>
</div>

<?php foreach (PERSON_POSITIONS as $pos => $label): $list = $byPos[$pos]; ?>
<section class="mb-6">
  <h3 class="mb-2 mt-5 rounded-md border-l-4 border-gold-500 bg-gold-100 px-3 py-1.5"><?= e($label) ?> <span class="text-sm font-normal text-stone-600">(<?= count($list) ?> ท่าน)</span></h3>
  <?php if (!$list): ?>
    <p class="text-sm text-stone-500">ยังไม่มีข้อมูล<?= $canEdit ? ' — กดปุ่ม "＋ ' . e($label) . '" ด้านบนเพื่อเพิ่ม' : '' ?></p>
  <?php endif; ?>
  <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
    <?php foreach ($list as $p): ?>
    <article class="flex gap-3 rounded-2xl border border-stone-200 bg-white p-3 shadow-sm <?= $p['is_active'] ? '' : 'opacity-60' ?>">
      <?php if ($p['photo_media_id']): ?>
        <img class="h-24 w-[4.5rem] shrink-0 rounded-lg border border-stone-200 object-cover" src="<?= e(media_url((int)$p['photo_media_id'])) ?>" alt="รูปถ่าย <?= e(person_full_name($p, false)) ?>">
      <?php else: ?>
        <div class="flex h-24 w-[4.5rem] shrink-0 items-center justify-center rounded-lg bg-stone-100 text-xs text-stone-400">ไม่มีรูป</div>
      <?php endif; ?>
      <div class="min-w-0 text-sm">
        <div class="font-bold text-brand-700"><?= e(person_full_name($p, false)) ?></div>
        <?php if ($p['dhamma_name']): ?><div class="text-stone-600">ฉายา <?= e($p['dhamma_name']) ?></div><?php endif; ?>
        <div>โทร <?= e($p['phone']) ?></div>
        <div class="font-mono text-xs text-stone-600" title="ปิดบังเพื่อความปลอดภัย"><?= e(mask_thai_id($p['national_id_last4'])) ?></div>
        <?php if ($canEdit): ?><div class="mt-1 line-clamp-2 text-xs text-stone-500" title="ที่อยู่ตามภูมิลำเนาเดิม"><?= e($p['home_address']) ?></div><?php endif; ?>
        <?php if (!$p['is_active']): ?><span class="badge mt-1">พ้นตำแหน่ง</span><?php endif; ?>
        <?php if ($canEdit): ?>
        <div class="mt-2 flex flex-wrap gap-1">
          <a class="btn btn-outline btn-sm" href="person.php?id=<?= (int)$p['id'] ?>">แก้ไข</a>
          <form method="post" class="inline"><?= csrf_field() ?><input type="hidden" name="action" value="toggle"><input type="hidden" name="id" value="<?= (int)$p['id'] ?>">
            <button class="btn btn-outline btn-sm"><?= $p['is_active'] ? 'พ้นตำแหน่ง' : 'กลับเข้าตำแหน่ง' ?></button></form>
          <form method="post" class="inline" onsubmit="return confirm('ลบข้อมูลบุคคลนี้ถาวร (รวมเลขบัตรและรูปถ่าย)?')"><?= csrf_field() ?><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= (int)$p['id'] ?>">
            <button class="btn btn-danger btn-sm">ลบ</button></form>
        </div>
        <?php endif; ?>
      </div>
    </article>
    <?php endforeach; ?>
  </div>
</section>
<?php endforeach; ?>
<?php require __DIR__ . '/includes/footer.php'; ?>
