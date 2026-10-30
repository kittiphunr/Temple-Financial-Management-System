<?php
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
