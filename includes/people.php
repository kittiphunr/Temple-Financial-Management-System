<?php
/** บุคลากรของวัด (เจ้าอาวาส / พระลูกวัด / ไวยาวัจกร): สิทธิ์การเข้าถึง, การตรวจข้อมูล, ตัวช่วยอื่น ๆ */

const PERSON_POSITIONS = ['abbot' => 'เจ้าอาวาส', 'monk' => 'พระลูกวัด', 'steward' => 'ไวยาวัจกร'];

function temple_row(int $templeId): ?array
{
    $st = db()->prepare('SELECT * FROM temples WHERE id = ?');
    $st->execute([$templeId]);
    return $st->fetch() ?: null;
}

/**
 * ใครดูข้อมูลบุคลากรของวัดได้: แอดมินหลัก/เจ้าคณะจังหวัด = ทุกวัด, แอดมินอำเภอ = วัดในอำเภอตน, แอดมินวัด = วัดตน
 * (เลขบัตรเต็มดูได้เฉพาะผู้ที่ people_can_reveal ผ่าน — คนอื่นเห็นแบบปิดบังเสมอ)
 */
function people_can_view(int $templeId): bool
{
    $u = user();
    $t = $u ? temple_row($templeId) : null;
    if (!$t) {
        return false;
    }
    return match ($u['role']) {
        'admin', 'province' => true,
        'district' => (int)$t['district_id'] === (int)$u['district_id'],
        'temple' => $templeId === (int)$u['temple_id'],
        default => false,
    };
}

/** เพิ่ม/แก้ไข/ลบ: แอดมินหลัก และแอดมินของวัดนั้นเท่านั้น */
function people_can_edit(int $templeId): bool
{
    $u = user();
    if (!$u || !temple_row($templeId)) {
        return false;
    }
    return $u['role'] === 'admin' || ($u['role'] === 'temple' && $templeId === (int)$u['temple_id']);
}

function people_can_reveal(int $templeId): bool
{
    return people_can_edit($templeId);
}

function person_full_name(array $p, bool $withDhamma = true): string
{
    $name = trim(($p['title'] ?? '') . ' ' . $p['first_name'] . ' ' . $p['last_name']);
    if ($withDhamma && !empty($p['dhamma_name'])) {
        $name .= ' (ฉายา ' . $p['dhamma_name'] . ')';
    }
    return $name;
}

/**
 * ตรวจข้อมูลจากฟอร์ม
 * @param array|null $existing แถวเดิม (แก้ไข) — เลขบัตรเว้นว่างได้เพื่อคงค่าเดิม
 * @return array{0:string[],1:array} [ข้อผิดพลาด, ข้อมูลที่ทำความสะอาดแล้ว]
 */
function person_validate(PDO $pdo, array $in, int $templeId, ?array $existing): array
{
    $errs = [];
    $c = [];
    $c['position'] = array_key_exists($in['position'] ?? '', PERSON_POSITIONS) ? $in['position'] : '';
    $c['title'] = mb_substr(trim($in['title'] ?? ''), 0, 60) ?: null;
    $c['first_name'] = trim($in['first_name'] ?? '');
    $c['last_name'] = trim($in['last_name'] ?? '');
    $c['dhamma_name'] = in_array($c['position'], ['abbot', 'monk'], true) ? (mb_substr(trim($in['dhamma_name'] ?? ''), 0, 100) ?: null) : null;
    $c['home_address'] = trim($in['home_address'] ?? '');
    $c['phone'] = preg_replace('/[^0-9+]/', '', $in['phone'] ?? '');

    if ($c['position'] === '') $errs[] = 'กรุณาเลือกตำแหน่ง';
    if ($c['first_name'] === '' || mb_strlen($c['first_name']) > 100) $errs[] = 'กรุณากรอกชื่อ (ไม่เกิน 100 ตัวอักษร)';
    if ($c['last_name'] === '' || mb_strlen($c['last_name']) > 100) $errs[] = 'กรุณากรอกนามสกุล (ไม่เกิน 100 ตัวอักษร)';
    if (mb_strlen($c['home_address']) < 10 || mb_strlen($c['home_address']) > 500) $errs[] = 'กรุณากรอกที่อยู่ตามภูมิลำเนาเดิมให้ครบ (10–500 ตัวอักษร)';
    if (!preg_match('/^\+?\d{9,15}$/', $c['phone'])) $errs[] = 'เบอร์โทรศัพท์ไม่ถูกต้อง (9–15 หลัก)';

    $idRaw = preg_replace('/[\s-]/', '', $in['national_id'] ?? '');
    if ($idRaw === '' && $existing) {
        $c['national_id'] = null; // คงเลขบัตรเดิม
    } elseif (!thai_id_valid($idRaw)) {
        $errs[] = 'เลขบัตรประชาชนไม่ถูกต้อง (ต้องเป็นตัวเลข 13 หลัก และหลักตรวจสอบต้องถูกต้อง)';
        $c['national_id'] = null;
    } else {
        $c['national_id'] = $idRaw;
    }

    if ($c['position'] === 'abbot') {
        $st = $pdo->prepare("SELECT COUNT(*) FROM temple_people WHERE temple_id = ? AND position = 'abbot' AND is_active = 1 AND id <> ?");
        $st->execute([$templeId, (int)($existing['id'] ?? 0)]);
        if ((int)$st->fetchColumn() > 0) {
            $errs[] = 'วัดนี้มีเจ้าอาวาสอยู่แล้ว — แก้ไขข้อมูลเดิม หรือเปลี่ยนสถานะท่านเดิมเป็น "พ้นตำแหน่ง" ก่อน';
        }
    }
    if (!$existing && empty($in['consent'])) {
        $errs[] = 'ต้องยืนยันว่าได้รับความยินยอมจากเจ้าของข้อมูลในการเก็บรวบรวมข้อมูลส่วนบุคคลแล้ว';
    }
    return [$errs, $c];
}

/** อัปเดตชื่อเจ้าอาวาสในตาราง temples (ใช้แสดงในรายการ/เอกสาร) ให้ตรงกับบุคลากรที่เป็นเจ้าอาวาสปัจจุบัน */
function sync_abbot_name(PDO $pdo, int $templeId): void
{
    $st = $pdo->prepare("SELECT title, first_name, last_name, dhamma_name FROM temple_people
        WHERE temple_id = ? AND position = 'abbot' AND is_active = 1 ORDER BY id LIMIT 1");
    $st->execute([$templeId]);
    $p = $st->fetch();
    $pdo->prepare('UPDATE temples SET abbot = ? WHERE id = ?')->execute([$p ? person_full_name($p, false) : null, $templeId]);
}

/** รายการที่วัดควรกรอกให้ครบ (ใช้แสดงแบนเนอร์เตือนแอดมินวัด) */
function temple_checklist(PDO $pdo, int $templeId): array
{
    $st = $pdo->prepare("SELECT t.logo_media_id IS NOT NULL AS has_logo,
        (SELECT COUNT(*) FROM temple_people p WHERE p.temple_id = t.id AND p.is_active = 1 AND p.position = 'abbot') AS abbots,
        (SELECT COUNT(*) FROM temple_people p WHERE p.temple_id = t.id AND p.is_active = 1 AND p.position = 'steward') AS stewards
        FROM temples t WHERE t.id = ?");
    $st->execute([$templeId]);
    $r = $st->fetch() ?: ['has_logo' => 1, 'abbots' => 1, 'stewards' => 1];
    return ['logo' => (bool)$r['has_logo'], 'abbot' => (int)$r['abbots'] > 0, 'steward' => (int)$r['stewards'] > 0];
}
