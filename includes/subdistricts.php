<?php
/**
 * เจ้าคณะตำบล (role = subdistrict): ดูข้อมูลวัดในตำบลที่ได้รับมอบหมายได้อย่างเดียว (เหมือนเจ้าคณะจังหวัดแต่จำกัดตำบล)
 * 1 บัญชีดูได้หลายตำบล (ตาราง user_subdistricts) แอดมินหลักเป็นผู้สร้าง ไม่นับในโควตาผู้ใช้ระดับจังหวัด/อำเภอ
 * ตำบลอ้างอิงจากคอลัมน์ temples.subdistrict ร่วมกับอำเภอ (ชื่อตำบลซ้ำข้ามอำเภอได้)
 */

/** ตำบลทั้งหมดที่มีวัดอยู่ พร้อมจำนวนวัด จัดกลุ่มตามอำเภอ @return array<int,array{district_id:int,district:string,subdistrict:string,n:int}> */
function subdistrict_options(PDO $pdo): array
{
    $rows = $pdo->query("SELECT d.id AS district_id, d.name AS district, t.subdistrict, COUNT(*) AS n
        FROM temples t JOIN districts d ON d.id = t.district_id
        WHERE t.is_active = 1 AND t.subdistrict IS NOT NULL AND t.subdistrict <> ''
        GROUP BY d.id, d.name, d.sort_order, t.subdistrict ORDER BY d.sort_order, d.id, t.subdistrict")->fetchAll();
    return array_map(fn($r) => ['district_id' => (int)$r['district_id'], 'district' => $r['district'], 'subdistrict' => $r['subdistrict'], 'n' => (int)$r['n']], $rows);
}

/** ตำบลที่ผู้ใช้ดูแล @return array<int,array{district_id:int,district:string,subdistrict:string}> */
function subdistrict_assignments(PDO $pdo, int $userId): array
{
    $st = $pdo->prepare('SELECT s.district_id, d.name AS district, s.subdistrict FROM user_subdistricts s
        JOIN districts d ON d.id = s.district_id WHERE s.user_id = ? ORDER BY d.sort_order, d.id, s.subdistrict');
    $st->execute([$userId]);
    return array_map(fn($r) => ['district_id' => (int)$r['district_id'], 'district' => $r['district'], 'subdistrict' => $r['subdistrict']], $st->fetchAll());
}

/** ชื่อย่อของตำบลที่ดูแล เช่น "ต.ช่อแฮ อ.เมืองแพร่" (ใช้แสดงในหัวหน้าจอ) */
function subdistrict_label(array $assign): string
{
    $by = [];
    foreach ($assign as $a) {
        $by[$a['district']][] = $a['subdistrict'];
    }
    $parts = [];
    foreach ($by as $dn => $subs) {
        $parts[] = abbr_subdistrict() . implode(', ', $subs) . ' ' . abbr_district() . $dn;
    }
    return implode(' · ', $parts);
}

/**
 * ตรวจและแปลงค่าตำบลที่เลือก ("districtId|ชื่อตำบล") ให้เหลือเฉพาะตำบลที่มีวัดจริง
 * @return array{0:array,1:string} [รายการ {district_id,subdistrict}, ข้อความผิดพลาด]
 */
function subdistrict_parse_selection(PDO $pdo, array $raw): array
{
    $valid = [];
    foreach (subdistrict_options($pdo) as $o) {
        $valid[$o['district_id'] . '|' . $o['subdistrict']] = $o;
    }
    $out = [];
    foreach ($raw as $v) {
        $v = (string)$v;
        if (!isset($valid[$v])) {
            return [[], 'มี' . unit_subdistrict() . 'ที่เลือกไม่ถูกต้องหรือไม่มีวัดใน' . unit_subdistrict() . 'นั้น'];
        }
        $out[$v] = ['district_id' => $valid[$v]['district_id'], 'subdistrict' => $valid[$v]['subdistrict']];
    }
    if (!$out) {
        return [[], 'กรุณาเลือก' . unit_subdistrict() . 'ที่ดูแลอย่างน้อย 1 ' . unit_subdistrict()];
    }
    return [array_values($out), ''];
}

function subdistrict_save_assignments(PDO $pdo, int $userId, array $items): void
{
    $pdo->beginTransaction();
    try {
        $pdo->prepare('DELETE FROM user_subdistricts WHERE user_id = ?')->execute([$userId]);
        $ins = $pdo->prepare('INSERT INTO user_subdistricts (user_id, district_id, subdistrict) VALUES (?,?,?)');
        foreach ($items as $it) {
            $ins->execute([$userId, $it['district_id'], $it['subdistrict']]);
        }
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
}

/** สร้างบัญชีเจ้าคณะตำบล @return array{0:bool,1:string} */
function subdistrict_create(PDO $pdo, string $username, string $fullName, string $password, string $email, array $rawSelection): array
{
    if (!preg_match('/^[A-Za-z0-9_.-]{3,50}$/', $username) || str_starts_with(strtolower($username), 'wat')) {
        return [false, 'ชื่อผู้ใช้ใช้ได้เฉพาะ a-z A-Z 0-9 _ . - (3–50 ตัว) และห้ามขึ้นต้นด้วย wat (สงวนไว้สำหรับบัญชีวัด)'];
    }
    if (mb_strlen($password) < 10 || !preg_match('/[A-Za-z]/', $password) || !preg_match('/\d/', $password)) {
        return [false, 'รหัสผ่านต้องยาวอย่างน้อย 10 ตัว และมีทั้งตัวอักษรและตัวเลข'];
    }
    $email = trim($email);
    if ($email === '' ? staff_2fa_enabled() : !valid_email($email)) {
        return [false, $email === '' ? 'กรุณากรอกอีเมลของเจ้าคณะ' . unit_subdistrict() . ' (ใช้รับรหัสยืนยันตัวตนและกู้รหัสผ่าน)' : 'รูปแบบอีเมลไม่ถูกต้อง'];
    }
    if (trim($fullName) === '') {
        return [false, 'กรุณากรอกชื่อ-นามสกุล'];
    }
    [$items, $err] = subdistrict_parse_selection($pdo, $rawSelection);
    if ($err !== '') {
        return [false, $err];
    }
    try {
        $pdo->beginTransaction();
        $pdo->prepare("INSERT INTO users (username, password_hash, full_name, email, role, password_changed_at) VALUES (?,?,?,?,'subdistrict',NOW())")
            ->execute([$username, password_hash($password, PASSWORD_DEFAULT), trim($fullName), $email ?: null]);
        $id = (int)$pdo->lastInsertId();
        $ins = $pdo->prepare('INSERT INTO user_subdistricts (user_id, district_id, subdistrict) VALUES (?,?,?)');
        foreach ($items as $it) {
            $ins->execute([$id, $it['district_id'], $it['subdistrict']]);
        }
        $pdo->commit();
        audit('create', 'user', $id, 'subdistrict:' . $username . ' x' . count($items));
        return [true, 'สร้างบัญชีเจ้าคณะ' . unit_subdistrict() . 'แล้ว (ดูแล ' . count($items) . ' ' . unit_subdistrict() . ')'];
    } catch (PDOException $e) {
        $pdo->inTransaction() && $pdo->rollBack();
        return [false, $e->getCode() === '23000' ? 'ชื่อผู้ใช้นี้มีอยู่แล้ว' : 'สร้างผู้ใช้ไม่สำเร็จ'];
    }
}

/** เงื่อนไข SQL จำกัดวัดตามตำบลที่ดูแล (ใช้ใน scope_sql) */
function subdistrict_scope_sql(string $alias, int $userId): array
{
    return [" AND EXISTS (SELECT 1 FROM user_subdistricts us WHERE us.user_id = ? AND us.district_id = $alias.district_id AND us.subdistrict = $alias.subdistrict)", [$userId]];
}

/** วัดนี้อยู่ในตำบลที่ผู้ใช้ปัจจุบัน (เจ้าคณะตำบล) ดูแลหรือไม่ */
function subdistrict_can_view_temple(int $templeId, int $userId): bool
{
    [$sc, $p] = subdistrict_scope_sql('t', $userId);
    $st = db()->prepare("SELECT 1 FROM temples t WHERE t.id = ? AND t.is_active = 1 $sc");
    $st->execute(array_merge([$templeId], $p));
    return (bool)$st->fetchColumn();
}
