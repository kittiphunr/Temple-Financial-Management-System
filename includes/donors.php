<?php
/**
 * ทะเบียนผู้บริจาค (ไม่บังคับ) ตาม PDPA: เก็บเฉพาะผู้ที่ให้ความยินยอม (บันทึกวันเวลา ช่องทาง และผู้บันทึก)
 * ที่อยู่และเบอร์โทรเก็บแบบเข้ารหัสด้วยกุญแจระบบ · ผู้บริจาคถอนความยินยอมได้ตลอด ระบบลบข้อมูลส่วนบุคคลในทะเบียนทันที
 * (ใบอนุโมทนาที่ออกไปแล้วยังเก็บสำเนาชื่อ/ที่อยู่ไว้ตามหลักฐานทางบัญชี) · ถ้ายังไม่ได้รัน migration (sql/migrations/2026_donors.sql) ฟีเจอร์นี้จะถูกซ่อน
 */

const DONOR_CHANNELS = ['written' => 'เป็นลายลักษณ์อักษร (แบบฟอร์ม/ข้อความ)', 'verbal' => 'ด้วยวาจา (บันทึกโดยเจ้าหน้าที่วัด)', 'at_receipt' => 'ตอนรับอนุโมทนาบัตร'];

function donor_available(): bool
{
    static $ok = null;
    if ($ok === null) {
        try {
            db()->query('SELECT id, temple_id, name, consent_at FROM donors LIMIT 1');
            db()->query('SELECT donor_id FROM donation_receipts LIMIT 1');
            $ok = true;
        } catch (PDOException) {
            $ok = false;
        }
    }
    return $ok;
}

/** ผู้ใช้จัดการทะเบียนผู้บริจาคของวัดนี้ได้หรือไม่ (ข้อมูลส่วนบุคคล: เฉพาะแอดมินวัด/อำเภอ/หลัก ไม่รวมเจ้าคณะจังหวัด/ตำบล) */
function donor_can_manage(int $templeId): bool
{
    return $templeId > 0 && can_access_temple($templeId);
}

/** ถอดรหัสฟิลด์ที่เข้ารหัส */
function donor_dec(?string $blob): string
{
    return $blob === null || $blob === '' ? '' : (string)(crypto_decrypt($blob) ?? '');
}

/** แถวผู้บริจาคพร้อมข้อมูลถอดรหัสแล้ว ('address','phone') */
function donor_expand(array $d): array
{
    $d['address'] = donor_dec($d['address_enc'] ?? null);
    $d['phone'] = donor_dec($d['phone_enc'] ?? null);
    unset($d['address_enc'], $d['phone_enc']);
    return $d;
}

function donor_get(PDO $pdo, int $id, int $templeId): ?array
{
    $st = $pdo->prepare('SELECT * FROM donors WHERE id = ? AND temple_id = ?');
    $st->execute([$id, $templeId]);
    $d = $st->fetch();
    return $d ? donor_expand($d) : null;
}

/** รายการผู้บริจาค (ค้นหาจากชื่อ) พร้อมจำนวน/ยอดที่บริจาคตามใบที่ออก @return array<int,array> */
function donor_list(PDO $pdo, int $templeId, string $q = '', bool $withdrawn = true, int $limit = 300): array
{
    $sql = "SELECT d.*, (SELECT COUNT(*) FROM donation_receipts r WHERE r.donor_id = d.id AND r.status = 'issued') AS n,
        (SELECT COALESCE(SUM(r.amount),0) FROM donation_receipts r WHERE r.donor_id = d.id AND r.status = 'issued') AS total
        FROM donors d WHERE d.temple_id = ?";
    $p = [$templeId];
    if (!$withdrawn) {
        $sql .= ' AND d.withdrawn_at IS NULL';
    }
    if ($q !== '') {
        $sql .= ' AND d.name LIKE ?';
        $p[] = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $q) . '%';
    }
    $st = $pdo->prepare($sql . ' ORDER BY (d.withdrawn_at IS NOT NULL), d.name LIMIT ' . (int)$limit);
    $st->execute($p);
    return array_map('donor_expand', $st->fetchAll());
}

/**
 * เพิ่ม/แก้ไขผู้บริจาค — การเพิ่มต้องยืนยันความยินยอม
 * @return array{0:?int,1:string} [รหัส, ข้อผิดพลาด]
 */
function donor_save(PDO $pdo, int $templeId, int $id, array $in, ?int $userId): array
{
    $name = mb_substr(trim((string)($in['name'] ?? '')), 0, 200);
    $addr = mb_substr(trim((string)($in['address'] ?? '')), 0, 500);
    $phone = mb_substr(trim((string)($in['phone'] ?? '')), 0, 20);
    $note = mb_substr(trim((string)($in['note'] ?? '')), 0, 255);
    $ch = (string)($in['consent_channel'] ?? '');
    if (mb_strlen($name) < 2) {
        return [null, 'กรุณากรอกชื่อผู้บริจาค (อย่างน้อย 2 ตัวอักษร)'];
    }
    if ($phone !== '' && !preg_match('/^[0-9+\-\s()]{6,20}$/', $phone)) {
        return [null, 'เบอร์โทรไม่ถูกต้อง'];
    }
    $encA = $addr === '' ? null : crypto_encrypt($addr);
    $encP = $phone === '' ? null : crypto_encrypt($phone);
    if ($id) {
        $cur = donor_get($pdo, $id, $templeId);
        if (!$cur || $cur['withdrawn_at']) {
            return [null, 'ไม่พบผู้บริจาค หรือถอนความยินยอมไปแล้ว'];
        }
        $st = $pdo->prepare('UPDATE donors SET name=?, address_enc=?, phone_enc=?, note=? WHERE id=? AND temple_id=?');
        $st->bindValue(1, $name);
        $st->bindValue(2, $encA, $encA === null ? PDO::PARAM_NULL : PDO::PARAM_LOB);
        $st->bindValue(3, $encP, $encP === null ? PDO::PARAM_NULL : PDO::PARAM_LOB);
        $st->bindValue(4, $note ?: null);
        $st->bindValue(5, $id, PDO::PARAM_INT);
        $st->bindValue(6, $templeId, PDO::PARAM_INT);
        $st->execute();
        audit('update', 'donor', $id);
        return [$id, ''];
    }
    if (empty($in['consent'])) {
        return [null, 'ต้องยืนยันว่าผู้บริจาคให้ความยินยอมในการเก็บข้อมูลส่วนบุคคลก่อนบันทึก'];
    }
    if (!isset(DONOR_CHANNELS[$ch])) {
        return [null, 'กรุณาเลือกช่องทางที่ได้รับความยินยอม'];
    }
    $st = $pdo->prepare('INSERT INTO donors (temple_id, name, address_enc, phone_enc, note, consent_at, consent_channel, consent_by, created_at) VALUES (?,?,?,?,?,NOW(),?,?,NOW())');
    $st->bindValue(1, $templeId, PDO::PARAM_INT);
    $st->bindValue(2, $name);
    $st->bindValue(3, $encA, $encA === null ? PDO::PARAM_NULL : PDO::PARAM_LOB);
    $st->bindValue(4, $encP, $encP === null ? PDO::PARAM_NULL : PDO::PARAM_LOB);
    $st->bindValue(5, $note ?: null);
    $st->bindValue(6, $ch);
    $st->bindValue(7, $userId, $userId === null ? PDO::PARAM_NULL : PDO::PARAM_INT);
    $st->execute();
    $new = (int)$pdo->lastInsertId();
    audit('create', 'donor', $new);
    return [$new, ''];
}

/** ถอนความยินยอม: ลบชื่อ/ที่อยู่/เบอร์/หมายเหตุในทะเบียน (คงบันทึกการยินยอมและการถอน) */
function donor_withdraw(PDO $pdo, int $id, int $templeId, ?int $userId): bool
{
    $cur = donor_get($pdo, $id, $templeId);
    if (!$cur || $cur['withdrawn_at']) {
        return false;
    }
    $pdo->prepare("UPDATE donors SET name = '(ถอนความยินยอมแล้ว)', address_enc = NULL, phone_enc = NULL, note = NULL, withdrawn_at = NOW(), withdrawn_by = ? WHERE id = ? AND temple_id = ?")
        ->execute([$userId, $id, $templeId]);
    audit('withdraw', 'donor', $id);
    return true;
}

/** ใบอนุโมทนาของผู้บริจาค */
function donor_receipts(PDO $pdo, int $donorId): array
{
    $st = $pdo->prepare('SELECT id, receipt_no, receipt_year, receipt_date, amount, status, purpose FROM donation_receipts WHERE donor_id = ? ORDER BY receipt_date DESC, id DESC');
    $st->execute([$donorId]);
    return $st->fetchAll();
}

/**
 * ตัวเลือกผู้บริจาคสำหรับหน้าออกอนุโมทนาบัตร (ผู้ที่ยังไม่ถอนความยินยอม) พร้อมที่อยู่ที่ถอดรหัสแล้ว
 * @return array<int,array{id:int,name:string,address:string}>
 */
function donor_choices(PDO $pdo, int $templeId): array
{
    $out = [];
    foreach (donor_list($pdo, $templeId, '', false, 500) as $d) {
        $out[] = ['id' => (int)$d['id'], 'name' => $d['name'], 'address' => $d['address']];
    }
    return $out;
}

/**
 * ใช้ตอนออกอนุโมทนาบัตร: คืนรหัสผู้บริจาคที่จะผูกกับใบ (เลือกจากทะเบียน หรือสร้างใหม่ถ้าติ๊กบันทึกและยืนยันความยินยอม)
 * @return array{0:?int,1:string} [donor_id, ข้อผิดพลาด]
 */
function donor_for_receipt(PDO $pdo, int $templeId, array $in, ?int $userId): array
{
    if (!donor_available()) {
        return [null, ''];
    }
    $pick = (int)($in['donor_id'] ?? 0);
    if ($pick > 0) {
        $d = donor_get($pdo, $pick, $templeId);
        return $d && !$d['withdrawn_at'] ? [$pick, ''] : [null, 'ผู้บริจาคที่เลือกไม่อยู่ในทะเบียนของวัดนี้หรือถอนความยินยอมแล้ว'];
    }
    if (!empty($in['save_donor'])) {
        return donor_save($pdo, $templeId, 0, [
            'name' => $in['donor_name'] ?? '', 'address' => $in['donor_address'] ?? '', 'phone' => '', 'note' => '',
            'consent' => $in['donor_consent'] ?? '', 'consent_channel' => $in['donor_channel'] ?? 'at_receipt',
        ], $userId);
    }
    return [null, ''];
}
