<?php
require_once __DIR__ . '/xlsx_reader.php';

/**
 * นำเข้ารายชื่อวัดจากไฟล์ CSV (UTF-8) คอลัมน์:
 * อำเภอ, ชื่อวัด, ตำบล, หมู่ที่, เจ้าอาวาส, โทรศัพท์, [รหัสวัด, ประเภทวัด, นิกาย, ชื่อหมู่บ้าน, บ้านเลขที่, ซอย, ถนน, รหัสไปรษณีย์, ปีที่ตั้งวัด(พ.ศ.)]
 * ถ้ามีรหัสวัดซ้ำ หรือ (อำเภอ+ชื่อ+ตำบล) ซ้ำ จะอัปเดตแทนการเพิ่มใหม่
 * @return array{ok:int,bad:string[]}
 */
function import_temples_csv(PDO $pdo, string $path, bool $verified, string $source): array
{
    $dmap = [];
    foreach ($pdo->query('SELECT id, name FROM districts') as $d) {
        $dmap[$d['name']] = (int)$d['id'];
    }
    $h = fopen($path, 'r');
    if (fread($h, 3) !== "\xEF\xBB\xBF") {
        rewind($h);
    }
    $ins = $pdo->prepare('INSERT INTO temples (district_id,name,subdistrict,moo,abbot,phone,temple_code,temple_type,sect,village,house_no,soi,road,postcode,founded_be,verified,source)
        VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)
        ON DUPLICATE KEY UPDATE district_id=VALUES(district_id), name=VALUES(name), subdistrict=COALESCE(VALUES(subdistrict),subdistrict),
        moo=COALESCE(VALUES(moo),moo), abbot=COALESCE(VALUES(abbot),abbot), phone=COALESCE(VALUES(phone),phone),
        temple_code=COALESCE(VALUES(temple_code),temple_code), temple_type=COALESCE(VALUES(temple_type),temple_type),
        sect=COALESCE(VALUES(sect),sect), village=COALESCE(VALUES(village),village), house_no=COALESCE(VALUES(house_no),house_no),
        soi=COALESCE(VALUES(soi),soi), road=COALESCE(VALUES(road),road), postcode=COALESCE(VALUES(postcode),postcode),
        founded_be=COALESCE(VALUES(founded_be),founded_be), verified=GREATEST(verified, VALUES(verified))');
    $ok = 0;
    $bad = [];
    $line = 0;
    while (($r = fgetcsv($h)) !== false) {
        $line++;
        if ($line === 1 && in_array(trim($r[0] ?? ''), ['อำเภอ', 'district'], true)) {
            continue;
        }
        $dn = preg_replace('/^อำเภอ|^อ\./u', '', trim($r[0] ?? ''));
        $name = trim($r[1] ?? '');
        if ($name === '' || !isset($dmap[$dn])) {
            $bad[] = "บรรทัด $line: " . implode(',', $r);
            continue;
        }
        $n = fn(int $i) => (isset($r[$i]) && trim($r[$i]) !== '') ? trim($r[$i]) : null;
        try {
            $postcode = $n(13);
            $founded = $n(14);
            $ins->execute([$dmap[$dn], $name, $n(2), $n(3), $n(4), $n(5), $n(6), $n(7), $n(8), $n(9), $n(10), $n(11), $n(12),
                $postcode !== null && preg_match('/^\d{5}$/', $postcode) ? $postcode : null,
                $founded !== null && preg_match('/^\d{4}$/', $founded) ? (int)$founded : null,
                $verified ? 1 : 0, $source]);
            $ok++;
        } catch (PDOException $ex) {
            $bad[] = "บรรทัด $line: " . $ex->getMessage();
        }
    }
    fclose($h);
    return ['ok' => $ok, 'bad' => $bad];
}


// =====================================================================================
// นำเข้าจากไฟล์ Excel (.xlsx) ทะเบียนวัดของสำนักงานพระพุทธศาสนาแห่งชาติ (binfo.onab.go.th) — ใช้กับจังหวัดใดก็ได้
// =====================================================================================

/** หัวคอลัมน์ที่รู้จัก (เทียบแบบตัดช่องว่าง) */
const TEMPLE_XLSX_COLUMNS = [
    'code' => ['รหัสวัด'], 'name' => ['ชื่อวัด'], 'type' => ['ประเภทวัด'], 'sect' => ['นิกาย'],
    'founded' => ['วันที่ตั้งวัด', 'ปีที่ตั้งวัด', 'ปีที่ตั้งวัด(พ.ศ.)'], 'subdistrict' => ['แขวง/ตำบล', 'ตำบล', 'แขวง'],
    'district' => ['เขต/อำเภอ', 'อำเภอ', 'เขต'], 'province' => ['จังหวัด'], 'postcode' => ['รหัสไปรษณีย์'],
    'phone' => ['เบอร์โทรศัพท์', 'โทรศัพท์'], 'email' => ['อีเมล์', 'อีเมล'], 'village' => ['ชื่อหมู่บ้าน'],
    'house_no' => ['บ้านเลขที่'], 'moo' => ['หมู่', 'หมู่ที่'], 'soi' => ['ซอย'], 'road' => ['ถนน'], 'abbot' => ['เจ้าอาวาส'],
];

function temple_import_nospace(string $v): string
{
    return preg_replace('/\s+/u', '', $v);
}

/** ปรับค่าหนึ่งแถว (ตามคอลัมน์ของไฟล์) ให้เป็นข้อมูลวัดที่ใช้บันทึก — คืน null ถ้าแถวใช้ไม่ได้ */
function temple_import_normalize(array $d): ?array
{
    $s = fn(string $k) => trim((string)($d[$k] ?? ''));
    $name = $s('name');
    $district = preg_replace('/^(อำเภอ|อ\.|เขต)\s*/u', '', $s('district'));
    if ($name === '' || $district === '') {
        return null;
    }
    $type = $s('type');
    if (!preg_match('/^(วัด|สำนักสงฆ์|ที่พักสงฆ์|สำนักปฏิบัติธรรม|อาราม)/u', $name)) {
        $name = (str_contains($type, 'สำนักสงฆ์') ? 'สำนักสงฆ์' : (str_contains($type, 'ที่พักสงฆ์') ? 'ที่พักสงฆ์' : 'วัด')) . $name;
    }
    $founded = null;
    if (preg_match('/(\d{4})\s*$/', $s('founded'), $m)) {
        $y = (int)$m[1];
        $founded = $y >= 1000 && $y <= 2700 ? $y : null;   // ทะเบียนวัดใช้ปี พ.ศ. — เก็บตามที่ไฟล์ระบุ ไม่แปลงเอง (ปีเก่า เช่น 1906 อาจถูกต้อง)
    }
    $postcode = $s('postcode');
    $phone = preg_replace('/[^0-9+,\-\/ ]/', '', $s('phone'));
    $email = strtolower($s('email'));
    $code = $s('code');
    $nz = fn(string $v, int $max = 100) => $v === '' ? null : mb_substr($v, 0, $max);
    return [
        'district' => mb_substr($district, 0, 100), 'name' => mb_substr($name, 0, 150),
        'subdistrict' => $nz(preg_replace('/^(ตำบล|ต\.|แขวง)\s*/u', '', $s('subdistrict'))),
        'moo' => $nz(preg_replace('/^(หมู่ที่|หมู่|ม\.)\s*/u', '', $s('moo')), 20),
        'abbot' => $nz($s('abbot')), 'phone' => $nz(trim($phone), 50),
        'code' => preg_match('/^\d{1,11}$/', $code) ? $code : null,
        'type' => $nz($type), 'sect' => $nz($s('sect')), 'village' => $nz($s('village')), 'house_no' => $nz($s('house_no'), 50),
        'soi' => $nz($s('soi')), 'road' => $nz($s('road')), 'postcode' => preg_match('/^\d{5}$/', $postcode) ? $postcode : null,
        'founded' => $founded, 'email' => ($email !== '' && function_exists('valid_email') && valid_email($email)) ? mb_substr($email, 0, 190) : null,
        'province' => preg_replace('/^(จังหวัด|จ\.)\s*/u', '', $s('province')),
    ];
}

/**
 * อ่านไฟล์ .xlsx ทะเบียนวัด
 * @return array{rows:array,bad:string[],province:string[],total:int}
 * @throws RuntimeException ไฟล์ผิดรูปแบบ / ไม่พบคอลัมน์ที่จำเป็น (ชื่อวัด, อำเภอ)
 */
function temple_import_parse_xlsx(string $path): array
{
    $all = xlsx_read_rows($path);
    $headRow = null;
    $map = [];
    foreach (array_slice($all, 0, 10, true) as $i => $row) {
        $m = [];
        foreach ($row as $col => $label) {
            $key = temple_import_nospace((string)$label);
            foreach (TEMPLE_XLSX_COLUMNS as $field => $aliases) {
                if (!isset($m[$field]) && in_array($key, array_map('temple_import_nospace', $aliases), true)) {
                    $m[$field] = $col;
                }
            }
        }
        if (isset($m['name'], $m['district'])) {
            $headRow = $i;
            $map = $m;
            break;
        }
    }
    if ($headRow === null) {
        throw new RuntimeException('ไม่พบหัวคอลัมน์ "ชื่อวัด" และ "เขต/อำเภอ" ในไฟล์ — ใช้ไฟล์ส่งออกจาก binfo.onab.go.th (รายงานทะเบียนวัด) โดยไม่แก้หัวตาราง');
    }
    $rows = [];
    $bad = [];
    $provinces = [];
    $seen = [];
    foreach ($all as $i => $r) {
        if ($i <= $headRow) {
            continue;
        }
        $d = [];
        foreach ($map as $field => $col) {
            $d[$field] = $r[$col] ?? '';
        }
        $n = temple_import_normalize($d);
        if (!$n) {
            $bad[] = 'แถว ' . ($i + 1) . ': ไม่มีชื่อวัดหรืออำเภอ';
            continue;
        }
        $key = $n['code'] ?: ($n['district'] . '|' . $n['name'] . '|' . $n['subdistrict']);
        if (isset($seen[$key])) {
            $bad[] = 'แถว ' . ($i + 1) . ': ซ้ำกับแถวก่อนหน้า (' . $n['name'] . ')';
            continue;
        }
        $seen[$key] = true;
        if ($n['province'] !== '') {
            $provinces[$n['province']] = ($provinces[$n['province']] ?? 0) + 1;
        }
        $rows[] = $n;
    }
    arsort($provinces);
    return ['rows' => $rows, 'bad' => $bad, 'province' => array_keys($provinces), 'total' => count($all) - $headRow - 1];
}

/** รหัสอำเภอจากรหัสวัด 11 หลัก (หลักที่ 3-6 = รหัสจังหวัด+อำเภอ เช่น 5406) */
function temple_import_district_code(array $rows, string $district): ?string
{
    foreach ($rows as $r) {
        if ($r['district'] === $district && $r['code'] && strlen($r['code']) === 11) {
            return substr($r['code'], 2, 4);
        }
    }
    return null;
}

/**
 * สรุปก่อนนำเข้า: อำเภอ (มี/ใหม่) จำนวนวัด วัดที่จะเพิ่มใหม่/อัปเดต อีเมลในไฟล์
 * @return array{districts:array,new:int,update:int,emails:int}
 */
function temple_import_preview(PDO $pdo, array $rows): array
{
    $dmap = [];
    foreach ($pdo->query('SELECT id, name FROM districts') as $d) {
        $dmap[$d['name']] = (int)$d['id'];
    }
    $byDistrict = [];
    foreach ($rows as $r) {
        $byDistrict[$r['district']] = ($byDistrict[$r['district']] ?? 0) + 1;
    }
    ksort($byDistrict, SORT_STRING);
    $districts = [];
    foreach ($byDistrict as $name => $n) {
        $districts[] = ['name' => $name, 'count' => $n, 'exists' => isset($dmap[$name])];
    }
    $byCode = $pdo->prepare('SELECT 1 FROM temples WHERE temple_code = ?');
    $byKey = $pdo->prepare('SELECT 1 FROM temples WHERE district_id = ? AND name = ? AND subdistrict <=> ?');
    $upd = 0;
    foreach ($rows as $r) {
        $exists = false;
        if ($r['code']) {
            $byCode->execute([$r['code']]);
            $exists = (bool)$byCode->fetchColumn();
        }
        if (!$exists && isset($dmap[$r['district']])) {
            $byKey->execute([$dmap[$r['district']], $r['name'], $r['subdistrict']]);
            $exists = (bool)$byKey->fetchColumn();
        }
        $upd += $exists ? 1 : 0;
    }
    return ['districts' => $districts, 'new' => count($rows) - $upd, 'update' => $upd, 'emails' => count(array_filter($rows, fn($r) => $r['email']))];
}

/**
 * บันทึกวัดที่ปรับแล้วลงฐานข้อมูล (เพิ่มใหม่หรืออัปเดตตามรหัสวัด / อำเภอ+ชื่อ+ตำบล — ไม่ลบอะไร)
 * @param array{verified:bool,source:string,create_districts:bool,set_emails:bool} $opt
 * @return array{inserted:int,updated:int,skipped:string[],districts_created:string[],emails:int}
 */
function temple_import_apply(PDO $pdo, array $rows, array $opt): array
{
    @set_time_limit(0);
    $dmap = [];
    foreach ($pdo->query('SELECT id, name FROM districts') as $d) {
        $dmap[$d['name']] = (int)$d['id'];
    }
    $created = [];
    if (!empty($opt['create_districts'])) {
        $names = array_values(array_unique(array_column($rows, 'district')));
        sort($names, SORT_STRING);
        $next = (int)$pdo->query('SELECT COALESCE(MAX(sort_order), 0) FROM districts')->fetchColumn();
        $codes = array_column($pdo->query('SELECT code FROM districts')->fetchAll(), 'code');
        $ins = $pdo->prepare('INSERT INTO districts (code, name, sort_order) VALUES (?,?,?)');
        foreach ($names as $n) {
            if (isset($dmap[$n])) {
                continue;
            }
            $code = temple_import_district_code($rows, $n);
            for ($k = 1; $code === null || in_array($code, $codes, true); $k++) {
                $code = 'X' . str_pad((string)($next + $k), 3, '0', STR_PAD_LEFT);
            }
            $ins->execute([$code, $n, ++$next]);
            $codes[] = $code;
            $dmap[$n] = (int)$pdo->lastInsertId();
            $created[] = $n;
        }
    }
    $sql = $pdo->prepare('INSERT INTO temples (district_id,name,subdistrict,moo,abbot,phone,temple_code,temple_type,sect,village,house_no,soi,road,postcode,founded_be,verified,source)
        VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)
        ON DUPLICATE KEY UPDATE district_id=VALUES(district_id), name=VALUES(name), subdistrict=COALESCE(VALUES(subdistrict),subdistrict),
        moo=COALESCE(VALUES(moo),moo), abbot=COALESCE(VALUES(abbot),abbot), phone=COALESCE(VALUES(phone),phone),
        temple_code=COALESCE(VALUES(temple_code),temple_code), temple_type=COALESCE(VALUES(temple_type),temple_type),
        sect=COALESCE(VALUES(sect),sect), village=COALESCE(VALUES(village),village), house_no=COALESCE(VALUES(house_no),house_no),
        soi=COALESCE(VALUES(soi),soi), road=COALESCE(VALUES(road),road), postcode=COALESCE(VALUES(postcode),postcode),
        founded_be=COALESCE(VALUES(founded_be),founded_be), verified=GREATEST(verified, VALUES(verified))');
    $setEmail = $pdo->prepare("UPDATE temples SET email = ?, email_updated_at = NOW() WHERE id = ? AND (email IS NULL OR email = '')");
    $find = $pdo->prepare('SELECT id FROM temples WHERE district_id = ? AND name = ? AND subdistrict <=> ?');
    $inserted = $updated = $emails = 0;
    $skipped = [];
    foreach ($rows as $i => $r) {
        if (!isset($dmap[$r['district']])) {
            $skipped[] = $r['name'] . ': ไม่มีอำเภอ ' . $r['district'] . ' ในระบบ';
            continue;
        }
        try {
            $sql->execute([$dmap[$r['district']], $r['name'], $r['subdistrict'], $r['moo'], $r['abbot'], $r['phone'], $r['code'], $r['type'], $r['sect'],
                $r['village'], $r['house_no'], $r['soi'], $r['road'], $r['postcode'], $r['founded'], !empty($opt['verified']) ? 1 : 0, $opt['source']]);
            $n = $sql->rowCount();
            $n === 1 ? $inserted++ : $updated++;   // 1 = เพิ่มใหม่; 2 = อัปเดต; 0 = มีอยู่แล้วและข้อมูลเหมือนเดิม (นับเป็นอัปเดต)
            if (!empty($opt['set_emails']) && $r['email']) {
                $tid = null;
                if ($r['code']) {
                    $q = $pdo->prepare('SELECT id FROM temples WHERE temple_code = ?');
                    $q->execute([$r['code']]);
                    $tid = $q->fetchColumn() ?: null;
                }
                if (!$tid) {
                    $find->execute([$dmap[$r['district']], $r['name'], $r['subdistrict']]);
                    $tid = $find->fetchColumn() ?: null;
                }
                if ($tid) {
                    $setEmail->execute([$r['email'], $tid]);
                    $emails += $setEmail->rowCount();
                }
            }
        } catch (PDOException $ex) {
            $skipped[] = $r['name'] . ': ' . $ex->getMessage();
        }
    }
    return ['inserted' => $inserted, 'updated' => $updated, 'skipped' => $skipped, 'districts_created' => $created, 'emails' => $emails];
}

/** ลบอำเภอที่ไม่มีวัดและไม่มีผู้ใช้ (เช่น อำเภอตัวอย่างของจังหวัดแพร่ เมื่อนำระบบไปใช้จังหวัดอื่น) คืนชื่ออำเภอที่ลบ */
function districts_prune_empty(PDO $pdo): array
{
    $rows = $pdo->query('SELECT d.id, d.name, d.logo_media_id FROM districts d
        WHERE NOT EXISTS (SELECT 1 FROM temples t WHERE t.district_id = d.id) AND NOT EXISTS (SELECT 1 FROM users u WHERE u.district_id = d.id)
        AND NOT EXISTS (SELECT 1 FROM user_subdistricts s WHERE s.district_id = d.id)')->fetchAll();
    $names = [];
    foreach ($rows as $r) {
        $pdo->prepare('DELETE FROM districts WHERE id = ?')->execute([$r['id']]);
        if ($r['logo_media_id']) {
            media_delete($pdo, (int)$r['logo_media_id']);
        }
        $names[] = $r['name'];
    }
    return $names;
}

/** อ่านไฟล์ CSV (คอลัมน์ตามลำดับเดิมของระบบ) เป็นแถวที่ปรับแล้ว เพื่อใช้ขั้นตอนตรวจก่อนนำเข้าเดียวกับ Excel */
function temple_import_parse_csv(string $path): array
{
    $h = fopen($path, 'r');
    if (fread($h, 3) !== "\xEF\xBB\xBF") {
        rewind($h);
    }
    $fields = ['district', 'name', 'subdistrict', 'moo', 'abbot', 'phone', 'code', 'type', 'sect', 'village', 'house_no', 'soi', 'road', 'postcode', 'founded'];
    $rows = [];
    $bad = [];
    $seen = [];
    $line = 0;
    while (($r = fgetcsv($h)) !== false) {
        $line++;
        if ($line === 1 && in_array(trim($r[0] ?? ''), ['อำเภอ', 'district'], true)) {
            continue;
        }
        $d = [];
        foreach ($fields as $i => $f) {
            $d[$f] = $r[$i] ?? '';
        }
        $n = temple_import_normalize($d);
        if (!$n) {
            $bad[] = "บรรทัด $line: ไม่มีชื่อวัดหรืออำเภอ";
            continue;
        }
        $key = $n['code'] ?: ($n['district'] . '|' . $n['name'] . '|' . $n['subdistrict']);
        if (isset($seen[$key])) {
            $bad[] = "บรรทัด $line: ซ้ำกับบรรทัดก่อนหน้า ({$n['name']})";
            continue;
        }
        $seen[$key] = true;
        $rows[] = $n;
    }
    fclose($h);
    return ['rows' => $rows, 'bad' => $bad, 'province' => [], 'total' => $line - 1];
}
