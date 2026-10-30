<?php
/**
 * สร้างบัญชีผู้ใช้ระดับจังหวัด/อำเภอ/ตำบลให้ครบอัตโนมัติจากทะเบียนวัดที่นำเข้า (ทำซ้ำได้ ข้ามตำแหน่งที่มีผู้ใช้แล้ว)
 *  - เจ้าคณะจังหวัด (ดูอย่างเดียว) 1 บัญชี: province
 *  - แอดมินประจำอำเภอ อำเภอละ 1 บัญชี: amphoe<รหัสอำเภอ> เช่น amphoe5401
 *  - เจ้าคณะตำบล ตำบลละ 1 บัญชี (ดูอย่างเดียว): tambon<รหัสตำบล 6 หลักจากรหัสวัด> เช่น tambon540207
 * รหัสผ่านเริ่มต้นสุ่ม (ในฐานข้อมูลเก็บเฉพาะแฮช) บังคับเปลี่ยนตอนเข้าสู่ระบบครั้งแรก — ยังไม่มีอีเมล: แอดมินหลักต้องลงอีเมลก่อนจึงเข้าสู่ระบบได้ (รหัสยืนยัน 2 ชั้น)
 * ตำบลมาจากฐานข้อมูลจังหวัด (data/thai_geo.json ผ่าน $geoProvince) ร่วมกับตำบลที่พบในทะเบียนวัด (กรณีสะกดต่างจากฐานข้อมูล) ตัวที่ไม่มีรหัสตำบลใช้ชื่อสำรอง
 * ต้องโหลด accounts.php (gen_password) subdistricts.php staff.php และ thai_geo.php ก่อน
 */

/** รหัสผ่านสุ่มสำหรับผู้ใช้ระดับจังหวัด/อำเภอ/ตำบล: 12 ตัว มีทั้งตัวอักษรและตัวเลข (ตามเงื่อนไขของบัญชีที่ไม่ใช่วัด) */
function staff_gen_password(): string
{
    do {
        $pw = gen_password(12);
    } while (!preg_match('/[A-Za-z]/', $pw) || !preg_match('/\d/', $pw));
    return $pw;
}

/** รหัสตำบล 6 หลักที่พบมากที่สุดในรหัสวัดของตำบลนั้น (หลักที่ 3–8 ของรหัสวัด 11 หลัก ต้องขึ้นต้นด้วยรหัสอำเภอ) หรือ null */
function staff_tambon_geocode(PDO $pdo, int $districtId, string $districtCode, string $subdistrict): ?string
{
    $st = $pdo->prepare('SELECT temple_code FROM temples WHERE district_id = ? AND subdistrict = ? AND is_active = 1 AND temple_code IS NOT NULL');
    $st->execute([$districtId, $subdistrict]);
    $count = [];
    foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $code) {
        $code = str_pad((string)$code, 11, '0', STR_PAD_LEFT);
        $g = substr($code, 2, 6);
        if (strlen($code) === 11 && ctype_digit($g) && str_starts_with($g, $districtCode)) {
            $count[$g] = ($count[$g] ?? 0) + 1;
        }
    }
    if (!$count) {
        return null;
    }
    arsort($count);
    return (string)array_key_first($count);
}

/**
 * @param array|null $geoProvince ข้อมูลจังหวัดจาก thai_geo_province() — ใส่แล้วสร้างเจ้าคณะตำบลครบทุกตำบลของจังหวัด แม้ยังไม่มีวัดในตำบลนั้น
 * @return array<int,array{0:string,1:string,2:string,3:string,4:string}> แถว [ตำแหน่ง, ขอบเขต, ชื่อ-สกุลในระบบ, ชื่อผู้ใช้, รหัสผ่านเริ่มต้น]
 */
function staff_provision_all(PDO $pdo, bool $province = true, bool $districts = true, bool $tambons = true, ?array $geoProvince = null): array
{
    @set_time_limit(0);
    $rows = [];
    $st = $pdo->query("SELECT v FROM settings WHERE k = 'province_name'");
    $pv = (string)($st ? $st->fetchColumn() : '');
    if ($pv === '') {
        $pv = (string)($GLOBALS['CONFIG']['province_name'] ?? 'แพร่');
    }
    $ins = $pdo->prepare('INSERT INTO users (username, password_hash, full_name, role, district_id, password_changed_at, must_change_password) VALUES (?,?,?,?,?,NOW(),1)');
    $insRow = function (string $role, ?int $districtId, string $username, string $fullName, string $label, string $scope) use ($ins, &$rows, $pdo): ?int {
        $pw = staff_gen_password();
        try {
            $ins->execute([$username, password_hash($pw, PASSWORD_DEFAULT), $fullName, $role, $districtId]);
        } catch (PDOException) {
            return null;   // ชื่อผู้ใช้ซ้ำ / ช่องนี้มีผู้ใช้แล้ว (unique) — ข้าม
        }
        $rows[] = [$label, $scope, $fullName, $username, $pw];
        return (int)$pdo->lastInsertId();
    };
    $cap = staff_capacity($pdo);
    $count = staff_count($pdo);

    if ($province && !(int)$pdo->query("SELECT COUNT(*) FROM users WHERE role = 'province'")->fetchColumn() && $count < $cap) {
        if ($insRow('province', null, 'province', 'เจ้าคณะ' . province_phrase_of($pv), 'เจ้าคณะจังหวัด (ดูอย่างเดียว)', 'ทั้ง' . province_phrase_of($pv)) !== null) {
            $count++;
        }
    }
    $dist = $pdo->query('SELECT id, code, name FROM districts ORDER BY sort_order, id')->fetchAll();
    if ($districts) {
        $have = array_map('intval', $pdo->query("SELECT district_id FROM users WHERE role = 'district'")->fetchAll(PDO::FETCH_COLUMN));
        foreach ($dist as $d) {
            if (in_array((int)$d['id'], $have, true) || $count >= $cap) {
                continue;
            }
            $u = 'amphoe' . preg_replace('/[^A-Za-z0-9]/', '', (string)$d['code']);
            if ($insRow('district', (int)$d['id'], $u, 'แอดมินประจำ' . unit_district() . $d['name'], 'แอดมินประจำ' . unit_district(), unit_district() . $d['name']) !== null) {
                $count++;
            }
        }
    }
    if ($tambons) {
        // ตำบลที่ต้องมีเจ้าคณะ: จากฐานข้อมูลจังหวัด (มีรหัสตำบล) + ตำบลในทะเบียนวัดที่ชื่อไม่ตรงกับฐานข้อมูล (ไม่มีรหัส)
        $cands = [];
        $seen = [];
        $byGeo = [];
        $dcode = array_column($dist, 'code', 'id');
        foreach ($geoProvince ? thai_geo_subdistricts($pdo, $geoProvince) : [] as $g) {
            $k = $g['district_id'] . '|' . $g['subdistrict'];
            if (!isset($seen[$k])) {
                $seen[$k] = true;
                $byGeo[$g['code']] = count($cands);
                $cands[] = ['district_id' => $g['district_id'], 'subdistrict' => $g['subdistrict'], 'code' => $g['code'], 'aliases' => []];
            }
        }
        foreach (subdistrict_options($pdo) as $o) {
            $k = $o['district_id'] . '|' . $o['subdistrict'];
            if (isset($seen[$k])) {
                continue;
            }
            $seen[$k] = true;
            // ชื่อตำบลในทะเบียนวัดสะกดต่างจากฐานข้อมูล (เช่น วังหงษ์/วังหงส์) แต่รหัสตำบลในรหัสวัดตรงกัน = ตำบลเดียวกัน: ให้เจ้าคณะตำบลคนเดิมดูแลทั้งสองชื่อ
            $dc = preg_replace('/[^0-9]/', '', (string)($dcode[$o['district_id']] ?? ''));
            $geo = $dc !== '' ? staff_tambon_geocode($pdo, $o['district_id'], $dc, $o['subdistrict']) : null;
            if ($geo !== null && isset($byGeo[$geo]) && $cands[$byGeo[$geo]]['district_id'] === $o['district_id']) {
                $cands[$byGeo[$geo]]['aliases'][] = $o['subdistrict'];
                continue;
            }
            $cands[] = ['district_id' => $o['district_id'], 'subdistrict' => $o['subdistrict'], 'code' => null, 'aliases' => []];
        }
        $assigned = [];
        foreach ($pdo->query('SELECT district_id, subdistrict FROM user_subdistricts') as $a) {
            $assigned[$a['district_id'] . '|' . $a['subdistrict']] = true;
        }
        $dname = array_column($dist, 'name', 'id');
        $used = array_flip($pdo->query("SELECT username FROM users WHERE role = 'subdistrict'")->fetchAll(PDO::FETCH_COLUMN));
        $seq = [];
        foreach ($cands as $o) {
            if (isset($assigned[$o['district_id'] . '|' . $o['subdistrict']])) {
                continue;
            }
            $dc = preg_replace('/[^0-9]/', '', (string)($dcode[$o['district_id']] ?? ''));
            $geo = $o['code'] ?: ($dc !== '' ? staff_tambon_geocode($pdo, $o['district_id'], $dc, $o['subdistrict']) : null);
            $username = $geo ? 'tambon' . $geo : '';
            if ($username === '' || isset($used[$username])) {
                do {
                    $seq[$o['district_id']] = ($seq[$o['district_id']] ?? 0) + 1;
                    $username = 'tambon' . ($dc !== '' ? $dc : 'd' . $o['district_id']) . 'x' . str_pad((string)$seq[$o['district_id']], 2, '0', STR_PAD_LEFT);
                } while (isset($used[$username]));
            }
            $pdo->beginTransaction();
            $id = $insRow('subdistrict', null, $username, 'เจ้าคณะ' . unit_subdistrict() . $o['subdistrict'], 'เจ้าคณะ' . unit_subdistrict() . ' (ดูอย่างเดียว)', unit_subdistrict() . $o['subdistrict'] . ' ' . unit_district() . ($dname[$o['district_id']] ?? ''));
            if ($id === null) {
                $pdo->commit();
                continue;
            }
            $insArea = $pdo->prepare('INSERT INTO user_subdistricts (user_id, district_id, subdistrict) VALUES (?,?,?)');
            foreach (array_merge([$o['subdistrict']], $o['aliases']) as $nm) {
                $insArea->execute([$id, $o['district_id'], $nm]);
            }
            $pdo->commit();
            $used[$username] = true;
        }
    }
    return $rows;
}

/** CSV รหัสผ่านเริ่มต้นของผู้ใช้ระดับจังหวัด/อำเภอ/ตำบล (UTF-8 BOM เปิดด้วย Excel ได้) */
function write_staff_credentials_csv($handle, array $rows): void
{
    fwrite($handle, "\xEF\xBB\xBF");
    fputcsv($handle, ['ตำแหน่ง', 'ขอบเขตที่ดูแล', 'ชื่อในระบบ', 'ชื่อผู้ใช้', 'รหัสผ่านเริ่มต้น', 'อีเมล (แอดมินหลักกรอกในระบบ)']);
    foreach ($rows as $r) {
        fputcsv($handle, array_merge($r, ['']));
    }
}
