<?php
/**
 * โควตาผู้ใช้ระดับจังหวัด/อำเภอ (ไม่นับแอดมินประจำวัดและเจ้าคณะตำบล)
 * = แอดมินประจำอำเภอ (อำเภอละ 1 คน) + แอดมินหลัก 1 คน + ผู้ใช้เจ้าคณะจังหวัด 1 คน = จำนวนอำเภอ + 2 (จังหวัดแพร่ 8 อำเภอ = 10 คน)
 */

function staff_capacity(PDO $pdo): int
{
    return (int)$pdo->query('SELECT COUNT(*) FROM districts')->fetchColumn() + 2;
}

function staff_count(PDO $pdo): int
{
    return (int)$pdo->query("SELECT COUNT(*) FROM users WHERE role NOT IN ('temple','subdistrict')")->fetchColumn();
}

/** ช่องตำแหน่งทั้งหมดพร้อมผู้ใช้ที่ครองอยู่ (หรือ null = ว่าง) */
function staff_slots(PDO $pdo): array
{
    $byRole = [];
    foreach ($pdo->query("SELECT * FROM users WHERE role IN ('admin','province') ORDER BY id") as $u) {
        $byRole[$u['role']] ??= $u;   // คนแรกครองช่อง ส่วนที่เกินจะแสดงเป็น "เกินโควตา"
    }
    $slots = [
        ['key' => 'admin', 'role' => 'admin', 'district_id' => null, 'label' => 'แอดมินหลัก', 'user' => $byRole['admin'] ?? null],
        ['key' => 'province', 'role' => 'province', 'district_id' => null, 'label' => 'ผู้ใช้เจ้าคณะจังหวัด (ดูข้อมูลอย่างเดียว)', 'user' => $byRole['province'] ?? null],
    ];
    $byDistrict = [];
    foreach ($pdo->query("SELECT * FROM users WHERE role = 'district' ORDER BY id") as $u) {
        $byDistrict[(int)$u['district_id']] ??= $u;
    }
    foreach ($pdo->query('SELECT id, name FROM districts ORDER BY sort_order, id') as $d) {
        $slots[] = ['key' => 'district:' . $d['id'], 'role' => 'district', 'district_id' => (int)$d['id'],
            'label' => 'แอดมินประจำอำเภอ' . $d['name'], 'user' => $byDistrict[(int)$d['id']] ?? null];
    }
    return $slots;
}

/** ผู้ใช้ที่ไม่ได้ครองช่องใดเลย (เกินโควตา เช่น มาจากข้อมูลเก่า) */
function staff_extras(PDO $pdo): array
{
    $used = [];
    foreach (staff_slots($pdo) as $s) {
        if ($s['user']) $used[(int)$s['user']['id']] = true;
    }
    return array_values(array_filter(
        $pdo->query("SELECT id, username, full_name, role, district_id, is_active FROM users WHERE role NOT IN ('temple','subdistrict') ORDER BY id")->fetchAll(),
        fn($u) => !isset($used[(int)$u['id']])
    ));
}

/**
 * สร้างผู้ใช้ในช่องตำแหน่งที่ว่าง (ใช้ล็อกชื่อ GET_LOCK กันสร้างซ้อนกันพร้อมกัน)
 * @return array{0:bool,1:string} [สำเร็จ, ข้อความ]
 */
function staff_create(PDO $pdo, string $slotKey, string $username, string $fullName, string $password, string $email = ''): array
{
    if (!preg_match('/^[A-Za-z0-9_.-]{3,50}$/', $username) || str_starts_with(strtolower($username), 'wat')) {
        return [false, 'ชื่อผู้ใช้ใช้ได้เฉพาะ a-z A-Z 0-9 _ . - (3–50 ตัว) และห้ามขึ้นต้นด้วย wat (สงวนไว้สำหรับบัญชีวัด)'];
    }
    if (mb_strlen($password) < 10 || !preg_match('/[A-Za-z]/', $password) || !preg_match('/\d/', $password)) {
        return [false, 'รหัสผ่านต้องยาวอย่างน้อย 10 ตัว และมีทั้งตัวอักษรและตัวเลข'];
    }
    $email = trim($email);
    if ($email !== '' && !valid_email($email)) {
        return [false, 'รูปแบบอีเมลไม่ถูกต้อง'];
    }
    if (trim($fullName) === '') {
        return [false, 'กรุณากรอกชื่อ-นามสกุล'];
    }
    $lock = $pdo->query("SELECT GET_LOCK('tfms_staff', 5)")->fetchColumn();
    if (!$lock) {
        return [false, 'ระบบไม่ว่าง กรุณาลองใหม่'];
    }
    try {
        if (staff_count($pdo) >= staff_capacity($pdo)) {
            return [false, 'ผู้ใช้ระดับจังหวัด/อำเภอครบโควตาแล้ว (' . staff_capacity($pdo) . ' คน)'];
        }
        $slot = null;
        foreach (staff_slots($pdo) as $s) {
            if ($s['key'] === $slotKey) $slot = $s;
        }
        if (!$slot) {
            return [false, 'ไม่พบตำแหน่งที่เลือก'];
        }
        if ($slot['user']) {
            return [false, 'ตำแหน่งนี้มีผู้ใช้แล้ว — ปิดใช้งานหรือรีเซ็ตรหัสผ่านของผู้ใช้เดิมแทนการสร้างใหม่'];
        }
        if ($email === '' && $slot['role'] !== 'admin' && staff_2fa_enabled()) {
            return [false, 'กรุณากรอกอีเมลของผู้ใช้ (ใช้รับรหัสยืนยันตัวตนและกู้รหัสผ่าน)'];
        }
        $pdo->prepare('INSERT INTO users (username, password_hash, full_name, email, role, district_id, password_changed_at) VALUES (?,?,?,?,?,?,NOW())')
            ->execute([$username, password_hash($password, PASSWORD_DEFAULT), trim($fullName), $email ?: null, $slot['role'], $slot['district_id']]);
        audit('create', 'user', (int)$pdo->lastInsertId(), $slot['key'] . ':' . $username);
        return [true, 'สร้างผู้ใช้ตำแหน่ง ' . $slot['label'] . ' แล้ว'];
    } catch (PDOException $e) {
        return [false, $e->getCode() === '23000' ? 'ชื่อผู้ใช้นี้มีอยู่แล้ว' : 'สร้างผู้ใช้ไม่สำเร็จ'];
    } finally {
        $pdo->query("SELECT RELEASE_LOCK('tfms_staff')");
    }
}
