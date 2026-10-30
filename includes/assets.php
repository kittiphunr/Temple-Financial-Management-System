<?php
/**
 * ทะเบียนทรัพย์สินของวัด: ที่ดิน อาคาร/สิ่งปลูกสร้าง ครุภัณฑ์ ยานพาหนะ และอื่น ๆ
 * เก็บวันที่ได้มา มูลค่า สภาพ สถานที่ ผู้รับผิดชอบ รูป และผูกรายจ่ายที่ซื้อ/ซ่อมกับทรัพย์สิน
 * การจำหน่ายทำเป็นสถานะ "จำหน่ายแล้ว" (ไม่ลบข้อมูล) ถ้ายังไม่ได้รัน migration (sql/migrations/2026_assets.sql) ฟีเจอร์นี้จะถูกซ่อน
 */

const ASSET_CATEGORIES = ['land' => 'ที่ดิน', 'building' => 'อาคาร/สิ่งปลูกสร้าง', 'equipment' => 'ครุภัณฑ์', 'vehicle' => 'ยานพาหนะ', 'other' => 'อื่น ๆ'];
const ASSET_STATUS = ['good' => 'ใช้งานได้ดี', 'fair' => 'พอใช้', 'poor' => 'ชำรุด/ต้องซ่อม', 'disposed' => 'จำหน่ายแล้ว'];
const ASSET_KINDS = ['purchase' => 'ซื้อ/จัดหา', 'repair' => 'ซ่อมบำรุง'];

function asset_available(): bool
{
    static $ok = null;
    if ($ok === null) {
        try {
            db()->query('SELECT id, temple_id, asset_no, name, category, value, status FROM assets LIMIT 1');
            db()->query('SELECT asset_id, asset_kind FROM transactions LIMIT 1');
            $ok = true;
        } catch (PDOException) {
            $ok = false;
        }
    }
    return $ok;
}

/** ทรัพย์สินของวัดพร้อมต้นทุนซื้อ/ค่าซ่อมที่ผูกไว้ @param array{category?:string,status?:string,q?:string} $f */
function asset_list(PDO $pdo, int $templeId, array $f = []): array
{
    $sql = "SELECT a.*,
          COALESCE((SELECT SUM(x.amount) FROM transactions x WHERE x.asset_id = a.id AND x.asset_kind = 'purchase' AND x.is_deleted = 0), 0) AS purchase_cost,
          COALESCE((SELECT SUM(x.amount) FROM transactions x WHERE x.asset_id = a.id AND x.asset_kind = 'repair' AND x.is_deleted = 0), 0) AS repair_cost
        FROM assets a WHERE a.temple_id = ?";
    $p = [$templeId];
    if (!empty($f['category']) && isset(ASSET_CATEGORIES[$f['category']])) {
        $sql .= ' AND a.category = ?';
        $p[] = $f['category'];
    }
    if (!empty($f['status']) && isset(ASSET_STATUS[$f['status']])) {
        $sql .= ' AND a.status = ?';
        $p[] = $f['status'];
    }
    if (!empty($f['q'])) {
        $sql .= ' AND (a.name LIKE ? OR a.asset_no LIKE ? OR a.detail LIKE ? OR a.holder LIKE ?)';
        $like = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $f['q']) . '%';
        array_push($p, $like, $like, $like, $like);
    }
    $st = $pdo->prepare($sql . ' ORDER BY (a.status = "disposed"), a.category, a.asset_no, a.name');
    $st->execute($p);
    return $st->fetchAll();
}

function asset_get(PDO $pdo, int $assetId, int $templeId): ?array
{
    foreach (asset_list($pdo, $templeId) as $a) {
        if ((int)$a['id'] === $assetId) {
            return $a;
        }
    }
    return null;
}

/**
 * ตรวจและบันทึกทรัพย์สินจากฟอร์ม
 * @return array{0:?int,1:string} [รหัสทรัพย์สิน, ข้อผิดพลาด] ('' = สำเร็จ)
 */
function asset_save(PDO $pdo, int $templeId, int $assetId, array $in, ?int $photoMedia, ?int $userId): array
{
    $txt = fn(string $k, int $max) => mb_substr(trim((string)($in[$k] ?? '')), 0, $max);
    $name = $txt('name', 200);
    $cat = (string)($in['category'] ?? '');
    $status = (string)($in['status'] ?? 'good');
    $no = $txt('asset_no', 30);
    $rawV = trim(str_replace(',', '', (string)($in['value'] ?? '')));
    $value = $rawV === '' ? 0.0 : (is_numeric($rawV) ? round((float)$rawV, 2) : -1.0);
    $acq = date_be_parse($in, 'acquired_date');
    if ($name === '') {
        return [null, 'กรุณาระบุชื่อทรัพย์สิน'];
    }
    if (!isset(ASSET_CATEGORIES[$cat])) {
        return [null, 'ประเภททรัพย์สินไม่ถูกต้อง'];
    }
    if (!isset(ASSET_STATUS[$status]) || $status === 'disposed') {
        $status = 'good';   // จำหน่ายทำผ่านปุ่มจำหน่ายเท่านั้น
    }
    if ($value < 0 || $value > 999999999999) {
        return [null, 'มูลค่าต้องเป็นตัวเลขตั้งแต่ 0'];
    }
    if ($no !== '') {
        $dup = $pdo->prepare('SELECT id FROM assets WHERE temple_id = ? AND asset_no = ? AND id <> ?');
        $dup->execute([$templeId, $no, $assetId]);
        if ($dup->fetchColumn()) {
            return [null, 'เลขทะเบียนทรัพย์สินนี้มีอยู่แล้วในวัด'];
        }
    }
    $vals = [$no ?: null, $name, $cat, $acq, $txt('acquired_how', 100) ?: null, $value, $txt('location', 150) ?: null, $txt('holder', 150) ?: null, $txt('detail', 500) ?: null];
    if ($assetId) {
        $cur = asset_get($pdo, $assetId, $templeId);
        if (!$cur) {
            return [null, 'ไม่พบทรัพย์สิน'];
        }
        $disposed = $cur['status'] === 'disposed';
        $pdo->prepare('UPDATE assets SET asset_no=?, name=?, category=?, acquired_date=?, acquired_how=?, value=?, location=?, holder=?, detail=?, status=?' . ($photoMedia !== null ? ', photo_media_id=?' : '') . ' WHERE id=? AND temple_id=?')
            ->execute(array_merge($vals, [$disposed ? 'disposed' : $status], $photoMedia !== null ? [$photoMedia] : [], [$assetId, $templeId]));
        audit('update', 'asset', $assetId);
        return [$assetId, ''];
    }
    $pdo->prepare('INSERT INTO assets (asset_no, name, category, acquired_date, acquired_how, value, location, holder, detail, status, photo_media_id, temple_id, created_by, created_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,NOW())')
        ->execute(array_merge($vals, [$status, $photoMedia, $templeId, $userId]));
    $id = (int)$pdo->lastInsertId();
    audit('create', 'asset', $id);
    return [$id, ''];
}

/** รหัสทรัพย์สินจากฟอร์มรายจ่ายใช้ได้กับวัดนี้หรือไม่ (ของวัด และยังไม่จำหน่าย ยกเว้นเป็นทรัพย์สินเดิมของรายการที่แก้) @return array{0:?int,1:string,2:?string} [asset_id, ข้อผิดพลาด, asset_kind] */
function asset_resolve(PDO $pdo, $raw, $kindRaw, int $templeId, ?int $currentAsset = null): array
{
    $id = (int)$raw;
    if ($id <= 0) {
        return [null, '', null];
    }
    $st = $pdo->prepare('SELECT status FROM assets WHERE id = ? AND temple_id = ?');
    $st->execute([$id, $templeId]);
    $row = $st->fetch();
    if (!$row || ($row['status'] === 'disposed' && $id !== $currentAsset)) {
        return [null, 'ทรัพย์สินที่เลือกไม่ถูกต้องหรือจำหน่ายแล้ว', null];
    }
    $kind = isset(ASSET_KINDS[(string)$kindRaw]) ? (string)$kindRaw : 'repair';
    return [$id, '', $kind];
}

/** ทรัพย์สินที่ยังใช้อยู่ของทุกวัดในขอบเขตผู้ใช้ สำหรับช่องเลือกในฟอร์มรายจ่าย {รหัสวัด: [[รหัส, ชื่อ], ...]} */
function asset_map(PDO $pdo): array
{
    [$sc, $sp] = scope_sql('t');
    $st = $pdo->prepare("SELECT a.id, a.temple_id, a.name, a.asset_no FROM assets a JOIN temples t ON t.id = a.temple_id WHERE a.status <> 'disposed' AND t.is_active = 1 $sc ORDER BY a.name");
    $st->execute($sp);
    $map = [];
    foreach ($st->fetchAll() as $a) {
        $map[(int)$a['temple_id']][] = [(string)$a['id'], ($a['asset_no'] ? $a['asset_no'] . ' · ' : '') . $a['name']];
    }
    return $map;
}

/** รายจ่ายที่ผูกกับทรัพย์สิน */
function asset_transactions(PDO $pdo, int $assetId): array
{
    $st = $pdo->prepare("SELECT x.id, x.txn_date, x.asset_kind, x.amount, x.description, x.doc_no, c.name AS category
        FROM transactions x JOIN categories c ON c.id = x.category_id WHERE x.asset_id = ? AND x.is_deleted = 0 ORDER BY x.txn_date DESC, x.id DESC");
    $st->execute([$assetId]);
    return $st->fetchAll();
}

/** ผู้ใช้ปัจจุบันดูรูปทรัพย์สินนี้ได้หรือไม่ (ใช้ใน media.php) */
function asset_photo_can_view(PDO $pdo, int $mediaId): ?bool
{
    if (!asset_available()) {
        return null;
    }
    $st = $pdo->prepare('SELECT temple_id FROM assets WHERE photo_media_id = ?');
    $st->execute([$mediaId]);
    $tid = $st->fetchColumn();
    return $tid === false ? null : people_can_view((int)$tid);
}
