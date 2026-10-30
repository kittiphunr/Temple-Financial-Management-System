<?php
/** เพิ่ม/แก้ไขข้อมูลบุคลากรวัด + ดูเลขบัตรประชาชนเต็ม (บันทึกประวัติทุกครั้ง) */
require __DIR__ . '/includes/bootstrap.php';
$u = require_login();
$pdo = db();

$pid = (int)($_GET['id'] ?? $_POST['id'] ?? 0);
$person = null;
if ($pid) {
    $st = $pdo->prepare('SELECT * FROM temple_people WHERE id = ?');
    $st->execute([$pid]);
    $person = $st->fetch();
    if (!$person) {
        http_response_code(404);
        exit('ไม่พบข้อมูลบุคคล');
    }
    $tid = (int)$person['temple_id'];
} else {
    $tid = (int)($_GET['temple'] ?? $_POST['temple'] ?? ($u['role'] === 'temple' ? $u['temple_id'] : 0));
}
if (!$tid || !people_can_edit($tid)) {
    http_response_code(403);
    exit('ไม่มีสิทธิ์เพิ่มหรือแก้ไขข้อมูลบุคลากรของวัดนี้');
}
$t = temple_row($tid);
$back = 'people.php' . ($u['role'] === 'temple' ? '' : '?temple=' . $tid);
$revealed = null;
$form = $person ?: ['position' => array_key_exists($_GET['position'] ?? '', PERSON_POSITIONS) ? $_GET['position'] : 'monk',
    'title' => '', 'first_name' => '', 'last_name' => '', 'dhamma_name' => '', 'home_address' => '', 'phone' => ''];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $act = $_POST['action'] ?? 'save';

    if ($act === 'reveal' && $person && people_can_reveal($tid)) {
        $plain = crypto_decrypt($person['national_id_enc']);
        audit('reveal_national_id', 'person', $pid);   // บันทึกว่าใครดูเลขบัตรของใคร (ไม่บันทึกเลขบัตร)
        $revealed = $plain !== null ? format_thai_id($plain) : 'ถอดรหัสไม่ได้ (กุญแจเข้ารหัสไม่ตรง)';
    } elseif ($act === 'save') {
        [$errs, $c] = person_validate($pdo, $_POST, $tid, $person);
        $form = array_merge($form, $_POST);
        if (!$person && $c['national_id'] === null && !$errs) {
            $errs[] = 'กรุณากรอกเลขบัตรประชาชน';
        }
        $newMedia = null;
        if (!$errs) {
            [$newMedia, $imgErr] = media_save_upload($pdo, $_FILES['photo'] ?? null, 'photo', $u['id']);
            if ($imgErr !== '') {
                $errs[] = $imgErr;
            }
        }
        if (!$errs) {
            try {
                $removePhoto = !empty($_POST['remove_photo']) && !$newMedia;
                $photoId = $newMedia ?: ($removePhoto ? null : ($person['photo_media_id'] ?? null));
                if ($person) {
                    $sets = 'position=?, title=?, first_name=?, last_name=?, dhamma_name=?, home_address=?, phone=?, photo_media_id=?';
                    $args = [$c['position'], $c['title'], $c['first_name'], $c['last_name'], $c['dhamma_name'], $c['home_address'], $c['phone'], $photoId];
                    if ($c['national_id'] !== null) {
                        $sets .= ', national_id_enc=?, national_id_idx=?, national_id_last4=?';
                        array_push($args, crypto_encrypt($c['national_id']), national_id_index($c['national_id']), substr($c['national_id'], -4));
                    }
                    $args[] = $pid;
                    $pdo->prepare("UPDATE temple_people SET $sets WHERE id = ?")->execute($args);
                    if (($newMedia || $removePhoto) && $person['photo_media_id']) {
                        media_delete($pdo, (int)$person['photo_media_id']);
                    }
                    audit('update', 'person', $pid);
                } else {
                    $pdo->prepare('INSERT INTO temple_people (temple_id, position, title, first_name, last_name, dhamma_name,
                        national_id_enc, national_id_idx, national_id_last4, home_address, phone, photo_media_id, consent_at, created_by)
                        VALUES (?,?,?,?,?,?,?,?,?,?,?,?,NOW(),?)')
                        ->execute([$tid, $c['position'], $c['title'], $c['first_name'], $c['last_name'], $c['dhamma_name'],
                            crypto_encrypt($c['national_id']), national_id_index($c['national_id']), substr($c['national_id'], -4),
                            $c['home_address'], $c['phone'], $photoId, $u['id']]);
                    audit('create', 'person', (int)$pdo->lastInsertId());
                }
                sync_abbot_name($pdo, $tid);
                flash('บันทึกข้อมูลบุคคลเรียบร้อยแล้ว');
                redirect($back);
            } catch (PDOException $e) {
                media_delete($pdo, $newMedia);
                $errs[] = $e->getCode() === '23000' ? 'มีบุคคลที่ใช้เลขบัตรประชาชนนี้ในวัดนี้แล้ว' : 'บันทึกไม่สำเร็จ';
            }
        } else {
            media_delete($pdo, $newMedia);
        }
        flash(implode(' · ', $errs), 'err');
    }
}

$pageTitle = $person ? 'แก้ไขข้อมูลบุคคล' : 'เพิ่มบุคคล';
require __DIR__ . '/includes/header.php';
$v = fn(string $k) => $form[$k] ?? '';
?>
<h2><?= $person ? 'แก้ไขข้อมูลบุคคล' : 'เพิ่มบุคคล' ?> <span class="text-lg font-semibold text-stone-600">— <?= e($t['name']) ?></span></h2>

<?php if ($revealed !== null): ?>
<div class="card border-amber-300 bg-amber-50 text-amber-950">
  <p class="text-sm">เลขบัตรประชาชน (การดูครั้งนี้ถูกบันทึกในประวัติการใช้งาน)</p>
  <p class="font-mono text-2xl font-bold tracking-wider"><?= e($revealed) ?></p>
</div>
<?php endif; ?>

<form method="post" enctype="multipart/form-data" class="grid gap-4 lg:grid-cols-[1fr_16rem]">
  <?= csrf_field() ?><input type="hidden" name="action" value="save"><input type="hidden" name="id" value="<?= $pid ?>"><input type="hidden" name="temple" value="<?= $tid ?>">
  <div class="card grid gap-3 sm:grid-cols-2">
    <div><label for="position">ตำแหน่ง</label>
      <select id="position" name="position" class="w-full">
        <?php foreach (PERSON_POSITIONS as $k => $label): ?><option value="<?= $k ?>" <?= $v('position') === $k ? 'selected' : '' ?>><?= e($label) ?></option><?php endforeach; ?>
      </select></div>
    <div><label for="title">คำนำหน้า / สมณศักดิ์</label><input id="title" name="title" value="<?= e($v('title')) ?>" maxlength="60" placeholder="เช่น พระ, พระครู, นาย" class="w-full"></div>
    <div><label for="first_name">ชื่อ</label><input id="first_name" name="first_name" value="<?= e($v('first_name')) ?>" maxlength="100" required class="w-full"></div>
    <div><label for="last_name">นามสกุล</label><input id="last_name" name="last_name" value="<?= e($v('last_name')) ?>" maxlength="100" required class="w-full"></div>
    <div class="sm:col-span-2"><label for="dhamma_name">ฉายา (เฉพาะพระภิกษุ)</label><input id="dhamma_name" name="dhamma_name" value="<?= e($v('dhamma_name')) ?>" maxlength="100" class="w-full"></div>
    <div><label for="national_id">เลขบัตรประชาชน 13 หลัก</label>
      <input id="national_id" name="national_id" inputmode="numeric" autocomplete="off" maxlength="17" value="" <?= $person ? '' : 'required' ?> class="w-full font-mono"
             placeholder="<?= $person ? e(mask_thai_id($person['national_id_last4'])) . ' (เว้นว่างเพื่อคงเดิม)' : 'X-XXXX-XXXXX-XX-X' ?>">
      <p class="mt-1 text-xs text-stone-500">เก็บแบบเข้ารหัส และแสดงแบบปิดบังเสมอ</p></div>
    <div><label for="phone">เบอร์โทรศัพท์</label><input id="phone" name="phone" value="<?= e($v('phone')) ?>" inputmode="tel" maxlength="20" required class="w-full"></div>
    <div class="sm:col-span-2"><label for="home_address">ที่อยู่ตามภูมิลำเนาเดิม</label><textarea id="home_address" name="home_address" rows="3" maxlength="500" required class="w-full"><?= e($v('home_address')) ?></textarea></div>
    <?php if (!$person): ?>
    <label class="!mb-0 flex items-start gap-2 rounded-xl bg-gold-50 p-3 !text-sm !text-stone-800 sm:col-span-2">
      <input type="checkbox" name="consent" value="1" class="mt-1" <?= !empty($_POST['consent']) ? 'checked' : '' ?>>
      <span>ข้าพเจ้ายืนยันว่าได้รับความยินยอมจากเจ้าของข้อมูลในการเก็บรวบรวมและใช้ข้อมูลส่วนบุคคลนี้ (เลขบัตรประชาชน ที่อยู่ เบอร์โทรศัพท์ รูปถ่าย) เพื่อการบริหารงานของวัด</span>
    </label>
    <?php else: ?>
    <p class="text-xs text-stone-500 sm:col-span-2">ยืนยันความยินยอมแล้วเมื่อ <?= e(th_date($person['consent_at'])) ?></p>
    <?php endif; ?>
    <div class="flex flex-wrap items-center gap-2 sm:col-span-2">
      <button class="btn">บันทึก</button><a class="btn btn-outline" href="<?= e($back) ?>">ยกเลิก</a>
    </div>
  </div>

  <aside class="card">
    <h3>รูปถ่าย</h3>
    <?php if ($person && $person['photo_media_id']): ?>
      <img class="mb-2 h-48 w-36 rounded-lg border border-stone-200 object-cover" src="<?= e(media_url((int)$person['photo_media_id'])) ?>" alt="รูปถ่าย">
      <label class="!mb-2 flex items-center gap-2 !text-sm"><input type="checkbox" name="remove_photo" value="1"> ลบรูปปัจจุบัน</label>
    <?php endif; ?>
    <input type="file" name="photo" accept="image/jpeg,image/png,image/webp" class="max-w-full text-sm">
    <p class="mt-1 text-xs text-stone-500">JPEG / PNG / WebP ไม่เกิน 2 MB (ระบบย่อและแปลงภาพให้อัตโนมัติ)</p>
    <?php if ($person): ?>
    <hr class="my-4 border-stone-200">
    <button class="btn btn-outline btn-sm" type="submit" name="action" value="reveal" formnovalidate
            data-confirm="ดูเลขบัตรประชาชนฉบับเต็ม? การดูจะถูกบันทึกในประวัติการใช้งาน">แสดงเลขบัตรเต็ม</button>
    <?php endif; ?>
  </aside>
</form>
<?php require __DIR__ . '/includes/footer.php'; ?>
