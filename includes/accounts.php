<?php
/** บัญชีผู้ดูแลของแต่ละวัด: สร้างอัตโนมัติ (1 วัด = 1 บัญชี) และตัวช่วยเกี่ยวกับการล็อกอิน */

const LOGIN_MAX_FAILS = 5;
const LOGIN_LOCK_MINUTES = 15;

/** รหัสผ่านสุ่มที่อ่านง่าย (ไม่มี 0/O/1/l/I) */
function gen_password(int $len = 10): string
{
    $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghjkmnpqrstuvwxyz23456789';
    $max = strlen($alphabet) - 1;
    $out = '';
    for ($i = 0; $i < $len; $i++) {
        $out .= $alphabet[random_int(0, $max)];
    }
    return $out;
}

/** ชื่อผู้ใช้ของบัญชีวัด: wat + รหัสวัด 11 หลัก (ถ้าไม่มีรหัสใช้ watm + เลข id) — ตั้งครั้งเดียว ห้ามเปลี่ยน */
function temple_username(array $t): string
{
    return !empty($t['temple_code'])
        ? 'wat' . $t['temple_code']
        : 'watm' . str_pad((string)$t['id'], 6, '0', STR_PAD_LEFT);
}

/**
 * สร้างบัญชีให้วัดที่ยังไม่มีบัญชี พร้อมรหัสผ่านเริ่มต้นแบบสุ่ม
 * คืนรายการ [รหัสวัด, อำเภอ, วัด, ชื่อผู้ใช้, รหัสผ่าน] — รหัสผ่านดูได้ตอนนี้ครั้งเดียว (ในฐานข้อมูลเก็บเฉพาะแฮช)
 * @param int[] $templeIds ถ้าว่าง = ทุกวัดที่ยังไม่มีบัญชี
 */
function provision_temple_accounts(PDO $pdo, array $templeIds = []): array
{
    @set_time_limit(0);
    $sql = 'SELECT t.*, d.name AS district_name FROM temples t
            JOIN districts d ON d.id = t.district_id
            LEFT JOIN users u ON u.temple_id = t.id
            WHERE u.id IS NULL AND t.is_active = 1';
    $params = [];
    if ($templeIds) {
        $sql .= ' AND t.id IN (' . implode(',', array_fill(0, count($templeIds), '?')) . ')';
        $params = array_values(array_map('intval', $templeIds));
    }
    $sql .= ' ORDER BY d.sort_order, t.name';
    $st = $pdo->prepare($sql);
    $st->execute($params);

    $ins = $pdo->prepare("INSERT INTO users (username, password_hash, full_name, role, temple_id, password_changed_at)
                          VALUES (?,?,?,'temple',?,NOW())");
    $rows = [];
    foreach ($st->fetchAll() as $t) {
        $pw = gen_password();
        try {
            $ins->execute([temple_username($t), password_hash($pw, PASSWORD_DEFAULT), 'ผู้ดูแล' . $t['name'], $t['id']]);
        } catch (PDOException $e) {
            continue; // ชื่อซ้ำ/มีบัญชีอยู่แล้ว (unique) — ข้าม
        }
        $rows[] = [$t['temple_code'] ?? '', $t['district_name'], $t['name'], temple_username($t), $pw];
    }
    return $rows;
}

/** เขียน CSV รหัสผ่านเริ่มต้นลงไฟล์ (ใช้ใน CLI) หรือส่งออกให้ดาวน์โหลด */
function write_credentials_csv($handle, array $rows): void
{
    fwrite($handle, "\xEF\xBB\xBF");
    fputcsv($handle, ['รหัสวัด', 'อำเภอ', 'วัด', 'ชื่อผู้ใช้', 'รหัสผ่านเริ่มต้น', 'อีเมลวัด (แอดมินหลักกรอกในระบบ)']);
    foreach ($rows as $r) {
        fputcsv($handle, array_merge($r, ['']));
    }
}

function valid_email(string $e): bool
{
    return mail_valid_address($e);
}

/** abc@gmail.com -> a**@g****.com */
function mask_email(string $e): string
{
    [$local, $domain] = array_pad(explode('@', $e, 2), 2, '');
    $dot = strrpos($domain, '.');
    $name = $dot === false ? $domain : substr($domain, 0, $dot);
    $tld = $dot === false ? '' : substr($domain, $dot);
    return mb_substr($local, 0, 1) . str_repeat('*', max(2, strlen($local) - 1))
        . '@' . mb_substr($name, 0, 1) . str_repeat('*', max(2, strlen($name) - 1)) . $tld;
}

// ---------- ล็อกอิน: นับครั้งที่ผิดและล็อกบัญชีชั่วคราว ----------
function account_locked(array $userRow): bool
{
    return !empty($userRow['locked_until']) && strtotime($userRow['locked_until']) > time();
}

function register_failed_login(PDO $pdo, int $userId): void
{
    $pdo->prepare('UPDATE users SET failed_logins = failed_logins + 1 WHERE id = ?')->execute([$userId]);
    $pdo->prepare('UPDATE users SET failed_logins = 0, locked_until = DATE_ADD(NOW(), INTERVAL ' . LOGIN_LOCK_MINUTES . ' MINUTE)
                   WHERE id = ? AND failed_logins >= ' . LOGIN_MAX_FAILS)->execute([$userId]);
}

/** เข้าสู่ระบบสำเร็จ (ผ่านทุกขั้นตอนแล้ว): สร้าง session และล้างตัวนับ */
function complete_login(PDO $pdo, array $row): void
{
    session_regenerate_id(true);
    unset($_SESSION['pending_2fa'], $_SESSION['login_fail']);
    $_SESSION['user'] = [
        'id' => (int)$row['id'], 'username' => $row['username'], 'full_name' => $row['full_name'],
        'role' => $row['role'], 'district_id' => $row['district_id'], 'temple_id' => $row['temple_id'],
    ];
    $pdo->prepare('UPDATE users SET failed_logins = 0, locked_until = NULL, last_login_at = NOW() WHERE id = ?')->execute([$row['id']]);
    audit('login', 'user', (int)$row['id']);
}
