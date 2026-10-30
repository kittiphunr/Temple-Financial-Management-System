<?php
/**
 * บัญชีวัด (เฉพาะแอดมินหลัก): 1 วัด = 1 บัญชี
 * - ชื่อผู้ใช้ตั้งอัตโนมัติและล็อก เปลี่ยนไม่ได้
 * - อีเมลวัดตั้ง/แก้ได้เฉพาะที่นี่ (วัดที่ยังไม่มีอีเมลเข้าสู่ระบบไม่ได้)
 */
require __DIR__ . '/../includes/bootstrap.php';
require_admin();
$pdo = db();

// ---------- ดาวน์โหลด ----------
if (($_GET['download'] ?? '') === 'creds') {
    $rows = $_SESSION['new_creds'] ?? [];
    unset($_SESSION['new_creds']); // ให้ดาวน์โหลดได้ครั้งเดียว
    if (!$rows) {
        flash('ไม่มีรายการรหัสผ่านเริ่มต้นที่รอดาวน์โหลด (ดาวน์โหลดได้ครั้งเดียวหลังสร้างบัญชี)', 'err');
        redirect('temple_accounts.php');
    }
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="temple-accounts-' . ((int)date('Y') + 543) . date('md-His') . '.csv"');
    header('Cache-Control: no-store');
    write_credentials_csv(fopen('php://output', 'w'), $rows);
    audit('download_credentials', 'user', null, count($rows) . ' accounts');
    exit;
}
if (($_GET['download'] ?? '') === 'email_template') {
    $rows = $pdo->query("SELECT t.temple_code, t.name, d.name dn, t.subdistrict, t.email FROM temples t
        JOIN districts d ON d.id = t.district_id WHERE t.is_active = 1 ORDER BY d.sort_order, t.subdistrict, t.name")->fetchAll();
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="temple-emails.csv"');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF");
    fputcsv($out, ['รหัสวัด', 'อีเมล', 'ชื่อวัด (อ้างอิง)', unit_district(), unit_subdistrict()]);
    foreach ($rows as $r) {
        fputcsv($out, [$r['temple_code'], $r['email'], $r['name'], $r['dn'], $r['subdistrict']]);
    }
    exit;
}

// ---------- การกระทำ ----------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $act = $_POST['action'] ?? '';
    $back = 'temple_accounts.php' . (!empty($_POST['return']) ? '?' . ltrim((string)$_POST['return'], '?') : '');

    if ($act === 'provision') {
        $created = provision_temple_accounts($pdo);
        if ($created) {
            $_SESSION['new_creds'] = $created;
            audit('provision', 'user', null, count($created) . ' accounts');
            flash('สร้างบัญชีใหม่ ' . count($created) . ' บัญชี — กดดาวน์โหลดรหัสผ่านเริ่มต้นด้านล่าง (ดาวน์โหลดได้ครั้งเดียว)');
        } else {
            flash('ทุกวัดมีบัญชีครบแล้ว');
        }
    } elseif ($act === 'save_email') {
        $tid = (int)($_POST['temple_id'] ?? 0);
        $email = strtolower(trim($_POST['email'] ?? ''));
        $st = $pdo->prepare('SELECT t.name, t.email FROM temples t WHERE t.id = ?');
        $st->execute([$tid]);
        $t = $st->fetch();
        if (!$t) {
            flash('ไม่พบวัด', 'err');
        } elseif ($email !== '' && !valid_email($email)) {
            flash('รูปแบบอีเมลไม่ถูกต้อง', 'err');
        } else {
            $pdo->prepare('UPDATE temples SET email = ?, email_updated_at = NOW() WHERE id = ?')->execute([$email === '' ? null : $email, $tid]);
            // เปลี่ยน/ลบอีเมลแล้ว รหัสที่ส่งไปอีเมลเดิมที่ยังค้างอยู่ต้องใช้ไม่ได้
            $pdo->prepare("UPDATE login_otps o JOIN users u ON u.id = o.user_id SET o.used_at = NOW()
                           WHERE u.temple_id = ? AND o.used_at IS NULL")->execute([$tid]);
            audit('email_change', 'temple', $tid, ($t['email'] ?: '-') . ' -> ' . ($email ?: '-'));
            flash('บันทึกอีเมลของ' . $t['name'] . ($email === '' ? ' (ลบอีเมลแล้ว วัดนี้จะเข้าสู่ระบบไม่ได้)' : ' แล้ว'));
        }
    } elseif ($act === 'reset_password') {
        $uid = (int)($_POST['user_id'] ?? 0);
        $st = $pdo->prepare("SELECT u.username, t.name FROM users u JOIN temples t ON t.id = u.temple_id WHERE u.id = ? AND u.role = 'temple'");
        $st->execute([$uid]);
        if ($u = $st->fetch()) {
            $pw = gen_password();
            $pdo->prepare('UPDATE users SET password_hash = ?, password_changed_at = NOW(), must_change_password = 1, failed_logins = 0, locked_until = NULL WHERE id = ?')
                ->execute([password_hash($pw, PASSWORD_DEFAULT), $uid]);
            otp_invalidate_all($pdo, $uid);
            audit('reset_password', 'user', $uid);
            flash("รหัสผ่านใหม่ของ {$u['name']} ({$u['username']}) คือ  $pw  — แสดงครั้งเดียว กรุณาจดและแจ้งวัด (วัดต้องตั้งรหัสผ่านใหม่เองตอนเข้าสู่ระบบครั้งแรก)");
        }
    } elseif ($act === 'toggle') {
        $uid = (int)($_POST['user_id'] ?? 0);
        $pdo->prepare("UPDATE users SET is_active = 1 - is_active WHERE id = ? AND role = 'temple'")->execute([$uid]);
        audit('toggle', 'user', $uid);
        flash('เปลี่ยนสถานะบัญชีแล้ว');
    } elseif ($act === 'import_emails') {
        $f = $_FILES['csv'] ?? null;
        if (!$f || $f['error'] !== UPLOAD_ERR_OK || $f['size'] > 2 * 1024 * 1024) {
            flash('อัปโหลดไฟล์ไม่สำเร็จ (ขนาดไม่เกิน 2 MB)', 'err');
        } else {
            $h = fopen($f['tmp_name'], 'r');
            if (fread($h, 3) !== "\xEF\xBB\xBF") rewind($h);
            $find = $pdo->prepare('SELECT id FROM temples WHERE temple_code = ?');
            $upd = $pdo->prepare('UPDATE temples SET email = ?, email_updated_at = NOW() WHERE id = ?');
            $kill = $pdo->prepare("UPDATE login_otps o JOIN users u ON u.id = o.user_id SET o.used_at = NOW() WHERE u.temple_id = ? AND o.used_at IS NULL");
            $ok = 0; $bad = []; $line = 0;
            while (($r = fgetcsv($h)) !== false) {
                $line++;
                $code = trim($r[0] ?? '');
                $email = strtolower(trim($r[1] ?? ''));
                if ($line === 1 && !preg_match('/^\d{11}$/', $code)) continue; // หัวตาราง
                if ($code === '' && $email === '') continue;
                if ($email === '') continue; // ช่องอีเมลว่าง = ไม่เปลี่ยนแปลง
                $find->execute([$code]);
                $tid = $find->fetchColumn();
                if (!$tid) { $bad[] = "บรรทัด $line: ไม่พบรหัสวัด $code"; continue; }
                if (!valid_email($email)) { $bad[] = "บรรทัด $line: อีเมลไม่ถูกต้อง ($email)"; continue; }
                $upd->execute([$email, $tid]);
                $kill->execute([$tid]);
                $ok++;
            }
            fclose($h);
            audit('import_emails', 'temple', null, "ok=$ok bad=" . count($bad));
            flash("นำเข้าอีเมลสำเร็จ $ok วัด" . ($bad ? ' · ข้าม ' . count($bad) . ' บรรทัด: ' . implode(' | ', array_slice($bad, 0, 5)) : ''), $bad ? 'err' : 'ok');
        }
    }
    redirect($back);
}

// ---------- แสดงรายการ ----------
$fDist = (int)($_GET['district'] ?? 0);
$fStatus = in_array($_GET['status'] ?? '', ['no_email', 'has_email'], true) ? $_GET['status'] : '';
$q = trim($_GET['q'] ?? '');
$page = max(1, (int)($_GET['page'] ?? 1));
$per = 40;

$where = ' WHERE t.is_active = 1';
$p = [];
if ($fDist) { $where .= ' AND t.district_id = ?'; $p[] = $fDist; }
if ($fStatus === 'no_email') { $where .= " AND (t.email IS NULL OR t.email = '')"; }
if ($fStatus === 'has_email') { $where .= " AND t.email <> ''"; }
if ($q !== '') {
    $where .= ' AND (t.name LIKE ? OR t.temple_code LIKE ? OR u.username LIKE ?)';
    $like = '%' . str_replace(['%', '_'], ['\\%', '\\_'], $q) . '%';
    array_push($p, $like, $like, $like);
}
$from = ' FROM temples t JOIN districts d ON d.id = t.district_id LEFT JOIN users u ON u.temple_id = t.id AND u.role = \'temple\'';

$cnt = $pdo->prepare('SELECT COUNT(*)' . $from . $where);
$cnt->execute($p);
$total = (int)$cnt->fetchColumn();
$pages = max(1, (int)ceil($total / $per));
$page = min($page, $pages);

$st = $pdo->prepare('SELECT t.id, t.name, t.temple_code, t.subdistrict, t.email, (t.logo_media_id IS NOT NULL) AS has_logo, d.name AS dn,
    u.id AS uid, u.username, u.is_active AS ua, u.last_login_at, (u.locked_until > NOW()) AS locked'
    . $from . $where . ' ORDER BY d.sort_order, t.subdistrict, t.name LIMIT ' . $per . ' OFFSET ' . (($page - 1) * $per));
$st->execute($p);
$rows = $st->fetchAll();

$stat = $pdo->query("SELECT COUNT(*) AS n, SUM(t.email IS NOT NULL AND t.email <> '') AS with_email, SUM(u.id IS NULL) AS no_acct, SUM(t.logo_media_id IS NULL) AS no_logo
    FROM temples t LEFT JOIN users u ON u.temple_id = t.id AND u.role = 'temple' WHERE t.is_active = 1")->fetch();
$districts = $pdo->query('SELECT id, name FROM districts ORDER BY sort_order')->fetchAll();
$hasCreds = !empty($_SESSION['new_creds']);

$qs = fn(array $o = []) => http_build_query(array_filter(array_merge(['district' => $fDist, 'status' => $fStatus, 'q' => $q, 'page' => $page], $o), fn($v) => $v !== '' && $v !== 0 && $v !== null));
$return = $qs();

$pageTitle = 'บัญชีวัด';
require __DIR__ . '/../includes/header.php';
?>
<h2>บัญชีวัด (1 วัด = 1 บัญชี)</h2>

<div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
  <div class="card"><div class="text-sm text-stone-500">วัดทั้งหมดในระบบ</div><div class="text-2xl font-bold"><?= (int)$stat['n'] ?></div></div>
  <div class="card"><div class="text-sm text-stone-500">ลงทะเบียนอีเมลแล้ว (เข้าสู่ระบบได้)</div><div class="text-2xl font-bold text-emerald-700"><?= (int)$stat['with_email'] ?></div></div>
  <div class="card"><div class="text-sm text-stone-500">ยังไม่มีอีเมล (เข้าสู่ระบบไม่ได้)</div><div class="text-2xl font-bold text-red-700"><?= (int)$stat['n'] - (int)$stat['with_email'] ?></div></div>
  <div class="card"><div class="text-sm text-stone-500">ยังไม่ได้ใส่โลโก้วัด</div><div class="text-2xl font-bold text-amber-700"><?= (int)$stat['no_logo'] ?></div></div>
</div>

<div class="mt-4 grid gap-4 lg:grid-cols-2">
  <div class="card">
    <h3>สร้างบัญชีและรหัสผ่านเริ่มต้น</h3>
    <p class="mb-4 text-sm text-stone-600">สร้างบัญชีให้วัดที่ยังไม่มีบัญชีอัตโนมัติ (<?= (int)$stat['no_acct'] ?> วัดที่ยังไม่มี) ชื่อผู้ใช้ = <code>wat</code> + รหัสวัด 11 หลัก
      รหัสผ่านเริ่มต้นสุ่มให้ และดาวน์โหลดเป็น CSV ได้<strong>ครั้งเดียว</strong> หลังสร้าง (ในระบบเก็บเฉพาะค่าแฮช ดูย้อนหลังไม่ได้)</p>
    <div class="flex flex-wrap gap-2">
      <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="provision"><input type="hidden" name="return" value="<?= e($return) ?>">
        <button class="btn" <?= (int)$stat['no_acct'] === 0 ? 'disabled' : '' ?>>สร้างบัญชีให้วัดที่ยังไม่มี</button></form>
      <?php if ($hasCreds): ?><a class="btn btn-outline" href="?download=creds">⇩ ดาวน์โหลดรหัสผ่านเริ่มต้น (<?= count($_SESSION['new_creds']) ?> บัญชี)</a><?php endif; ?>
    </div>
  </div>
  <div class="card">
    <h3>นำเข้าอีเมลวัดจากไฟล์ CSV</h3>
    <p class="mb-4 text-sm text-stone-600">ดาวน์โหลดแม่แบบ (รหัสวัด, อีเมล, ...) กรอกอีเมลที่สืบเสาะมา แล้วอัปโหลดกลับ ช่องอีเมลที่ว่างจะไม่ถูกแก้ไข</p>
    <form method="post" enctype="multipart/form-data" class="flex flex-wrap items-center gap-2">
      <?= csrf_field() ?><input type="hidden" name="action" value="import_emails"><input type="hidden" name="return" value="<?= e($return) ?>">
      <input type="file" name="csv" accept=".csv,text/csv" required class="max-w-[14rem] text-sm">
      <button class="btn btn-sm">นำเข้า</button>
      <a class="btn btn-outline btn-sm" href="?download=email_template">⇩ แม่แบบ CSV</a>
    </form>
  </div>
</div>

<form class="my-4 flex flex-wrap items-end gap-3" method="get">
  <div><label><?= e(unit_district()) ?></label><select name="district"><option value="0">ทุก<?= e(unit_district()) ?></option>
    <?php foreach ($districts as $d): ?><option value="<?= (int)$d['id'] ?>" <?= $fDist === (int)$d['id'] ? 'selected' : '' ?>><?= e($d['name']) ?></option><?php endforeach; ?></select></div>
  <div><label>สถานะอีเมล</label><select name="status">
    <option value="">ทั้งหมด</option><option value="no_email" <?= $fStatus === 'no_email' ? 'selected' : '' ?>>ยังไม่มีอีเมล</option><option value="has_email" <?= $fStatus === 'has_email' ? 'selected' : '' ?>>มีอีเมลแล้ว</option></select></div>
  <div><label>ค้นหา (ชื่อวัด / รหัส / ชื่อผู้ใช้)</label><input name="q" value="<?= e($q) ?>" class="w-56"></div>
  <button class="btn">แสดง</button>
</form>

<div class="card-table"><table class="data-table">
  <tr><th>วัด</th><th>ชื่อผู้ใช้ (ล็อก)</th><th>อีเมลวัด (แก้ได้เฉพาะแอดมินหลัก)</th><th>สถานะ</th><th>เข้าสู่ระบบล่าสุด</th><th></th></tr>
  <?php foreach ($rows as $r): ?>
  <tr>
    <td><div class="font-semibold"><?= e($r['name']) ?></div><div class="text-xs text-stone-500"><?= e(abbr_district()) ?><?= e($r['dn']) ?><?= $r['subdistrict'] ? ' · ' . abbr_subdistrict() . e($r['subdistrict']) : '' ?> · <?= $r['has_logo'] ? '<span class="text-emerald-700">มีโลโก้</span>' : '<span class="text-amber-700">ยังไม่มีโลโก้</span>' ?></div></td>
    <td class="font-mono text-sm"><?= $r['username'] ? e($r['username']) : '<span class="text-stone-400">ยังไม่มีบัญชี</span>' ?></td>
    <td>
      <form method="post" class="flex gap-1">
        <?= csrf_field() ?><input type="hidden" name="action" value="save_email"><input type="hidden" name="temple_id" value="<?= (int)$r['id'] ?>"><input type="hidden" name="return" value="<?= e($return) ?>">
        <input type="email" name="email" value="<?= e($r['email']) ?>" maxlength="190" placeholder="ยังไม่ได้ลงทะเบียน" class="w-56 !py-1 text-sm">
        <button class="btn btn-outline btn-sm">บันทึก</button>
      </form>
    </td>
    <td>
      <?php if (!$r['uid']): ?><span class="badge">ไม่มีบัญชี</span>
      <?php elseif (!$r['ua']): ?><span class="badge">ปิดใช้งาน</span>
      <?php elseif ($r['locked']): ?><span class="badge">ถูกล็อกชั่วคราว</span>
      <?php elseif (trim((string)$r['email']) === ''): ?><span class="badge">รออีเมล</span>
      <?php else: ?><span class="badge badge-ok">พร้อมใช้งาน</span><?php endif; ?>
    </td>
    <td class="whitespace-nowrap text-sm"><?= $r['last_login_at'] ? e(th_date($r['last_login_at'])) : '-' ?></td>
    <td class="whitespace-nowrap">
      <?php if ($r['uid']): ?>
      <form method="post" class="inline" data-confirm="สร้างรหัสผ่านใหม่ให้ <?= e(($r['name'])) ?> ? รหัสเดิมจะใช้ไม่ได้ทันที">
        <?= csrf_field() ?><input type="hidden" name="action" value="reset_password"><input type="hidden" name="user_id" value="<?= (int)$r['uid'] ?>"><input type="hidden" name="return" value="<?= e($return) ?>">
        <button class="btn btn-outline btn-sm">รีเซ็ตรหัสผ่าน</button></form>
      <form method="post" class="inline">
        <?= csrf_field() ?><input type="hidden" name="action" value="toggle"><input type="hidden" name="user_id" value="<?= (int)$r['uid'] ?>"><input type="hidden" name="return" value="<?= e($return) ?>">
        <button class="btn btn-outline btn-sm"><?= $r['ua'] ? 'ปิด' : 'เปิด' ?></button></form>
      <?php endif; ?>
    </td>
  </tr>
  <?php endforeach; ?>
  <?php if (!$rows): ?><tr><td colspan="6" class="text-stone-500">ไม่พบรายการ</td></tr><?php endif; ?>
</table></div>

<?php if ($pages > 1): ?>
<div class="mt-4 flex flex-wrap items-center justify-center gap-1 text-sm">
  <?php for ($i = 1; $i <= $pages; $i++): ?>
    <a class="rounded-lg px-3 py-1 no-underline <?= $i === $page ? 'bg-brand-600 text-white' : 'border border-stone-200 bg-white hover:bg-gold-100' ?>" href="?<?= e($qs(['page' => $i])) ?>"><?= $i ?></a>
  <?php endfor; ?>
  <span class="ml-2 text-stone-500">ทั้งหมด <?= $total ?> วัด</span>
</div>
<?php endif; ?>
<?php require __DIR__ . '/../includes/footer.php'; ?>
