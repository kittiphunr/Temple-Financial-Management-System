<?php
/**
 * ฐานข้อมูลจังหวัด/อำเภอ/ตำบลของประเทศไทย (data/thai_geo.json) จากโปรเจค kongvut/thai-province-data (MIT)
 * ใช้ตอนติดตั้ง: ให้เลือกจังหวัดของตน แล้วระบบสร้างอำเภอและผู้ใช้ (เจ้าคณะจังหวัด/แอดมินอำเภอ/เจ้าคณะตำบล) ให้อัตโนมัติ
 * สร้างไฟล์ใหม่ด้วย tools/build_thai_geo.py
 */

/** @return array{source:string,provinces:array}|null */
function thai_geo_load(): ?array
{
    static $geo = false;
    if ($geo === false) {
        $file = dirname(__DIR__) . '/data/thai_geo.json';
        $j = is_file($file) ? json_decode((string)file_get_contents($file), true) : null;
        $geo = is_array($j) && !empty($j['provinces']) ? $j : null;
    }
    return $geo;
}

/** ชื่อจังหวัดพร้อมคำนำหน้า: "จังหวัดแพร่" · กรุงเทพมหานครไม่มีคำว่า "จังหวัด" */
function province_phrase_of(string $province): string
{
    return $province === 'กรุงเทพมหานคร' ? $province : 'จังหวัด' . $province;
}

/** ชื่อจังหวัดทั้งหมด (ไม่มีคำว่า "จังหวัด") เรียงตามตัวอักษร ถ้าไม่มีไฟล์ข้อมูลคืน [] */
function thai_geo_province_names(): array
{
    return array_column(thai_geo_load()['provinces'] ?? [], 'name');
}

/** ข้อมูลจังหวัดตามชื่อ (ตัดคำว่า "จังหวัด"/"จ." นำหน้า) หรือ null */
function thai_geo_province(string $name): ?array
{
    $name = preg_replace('/^(จังหวัด|จ\.)\s*/u', '', trim($name));
    foreach (thai_geo_load()['provinces'] ?? [] as $p) {
        if ($p['name'] === $name) {
            return $p;
        }
    }
    return null;
}

/** สรุปจำนวนอำเภอ/ตำบลของทุกจังหวัด ใช้แสดงในตัวช่วยติดตั้ง @return array<string,array{d:int,s:int}> */
function thai_geo_counts(): array
{
    $out = [];
    foreach (thai_geo_load()['provinces'] ?? [] as $p) {
        $out[$p['name']] = ['d' => count($p['districts']), 's' => array_sum(array_map(fn($d) => count($d['subs']), $p['districts']))];
    }
    return $out;
}

/**
 * เพิ่มอำเภอของจังหวัดนั้นลงตาราง districts (ข้ามอำเภอที่มีรหัสหรือชื่อเดียวกันอยู่แล้ว)
 * @return int จำนวนอำเภอที่เพิ่มใหม่
 */
function thai_geo_seed_districts(PDO $pdo, array $province): int
{
    $have = [];
    foreach ($pdo->query('SELECT code, name FROM districts') as $r) {
        $have['c:' . $r['code']] = true;
        $have['n:' . $r['name']] = true;
    }
    $next = (int)$pdo->query('SELECT COALESCE(MAX(sort_order), 0) FROM districts')->fetchColumn();
    $ins = $pdo->prepare('INSERT INTO districts (code, name, sort_order) VALUES (?,?,?)');
    $n = 0;
    foreach ($province['districts'] as $d) {
        if (isset($have['c:' . $d['code']]) || isset($have['n:' . $d['name']])) {
            continue;
        }
        $ins->execute([$d['code'], $d['name'], ++$next]);
        $n++;
    }
    return $n;
}

/**
 * ตำบลของจังหวัดนั้นที่จับคู่กับอำเภอในระบบได้ (จับด้วยรหัสอำเภอ ไม่งั้นด้วยชื่อ)
 * @return array<int,array{district_id:int,district:string,subdistrict:string,code:string,zip:string}>
 */
function thai_geo_subdistricts(PDO $pdo, array $province): array
{
    $byCode = $byName = [];
    foreach ($pdo->query('SELECT id, code, name FROM districts') as $r) {
        $byCode[$r['code']] = $r;
        $byName[$r['name']] = $r;
    }
    $out = [];
    foreach ($province['districts'] as $d) {
        $row = $byCode[$d['code']] ?? $byName[$d['name']] ?? null;
        if (!$row) {
            continue;
        }
        foreach ($d['subs'] as [$code, $name, $zip]) {
            $out[] = ['district_id' => (int)$row['id'], 'district' => $row['name'], 'subdistrict' => $name, 'code' => $code, 'zip' => $zip];
        }
    }
    return $out;
}

/** จังหวัดปัจจุบันของระบบ: จากตาราง settings/config (province_name()) หรือค่า $CONFIG ถ้ายังไม่ได้โหลด settings (ตัวติดตั้ง/setup.php) */
function geo_current_province(): string
{
    return function_exists('province_name') ? province_name() : (string)($GLOBALS['CONFIG']['province_name'] ?? 'แพร่');
}

/** กรุงเทพมหานครใช้คำว่า "เขต/แขวง" แทน "อำเภอ/ตำบล" (จังหวัดอื่นใช้ อำเภอ/ตำบล) */
function is_bangkok(): bool
{
    return geo_current_province() === 'กรุงเทพมหานคร';
}

/** คำเรียกหน่วยปกครองระดับอำเภอ: "อำเภอ" หรือ "เขต" (กรุงเทพฯ) */
function unit_district(): string
{
    return is_bangkok() ? 'เขต' : 'อำเภอ';
}

/** คำเรียกหน่วยปกครองระดับตำบล: "ตำบล" หรือ "แขวง" (กรุงเทพฯ) */
function unit_subdistrict(): string
{
    return is_bangkok() ? 'แขวง' : 'ตำบล';
}

/** ชื่อจังหวัดพร้อมคำนำหน้า: "จังหวัดแพร่" · กรุงเทพมหานครไม่มีคำว่า "จังหวัด" */
function province_phrase(): string
{
    return province_phrase_of(geo_current_province());
}

/** ตัวย่อหน่วยปกครองในที่อยู่/รายการ: "ต." "อ." (กรุงเทพฯ ใช้คำเต็ม "แขวง" "เขต") */
function abbr_subdistrict(): string
{
    return is_bangkok() ? 'แขวง' : 'ต.';
}

function abbr_district(): string
{
    return is_bangkok() ? 'เขต' : 'อ.';
}

/** ที่อยู่ท้ายเอกสาร: "ต.ในเวียง อ.เมืองแพร่ จ.แพร่" หรือ "แขวงบางรัก เขตบางรัก กรุงเทพมหานคร" */
function address_tail(?string $subdistrict, string $district): string
{
    if (is_bangkok()) {
        return trim(($subdistrict ? 'แขวง' . $subdistrict . ' ' : '') . 'เขต' . $district . ' ' . geo_current_province());
    }
    return trim(($subdistrict ? 'ต.' . $subdistrict . ' ' : '') . 'อ.' . $district . ' จ.' . geo_current_province());
}

