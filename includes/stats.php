<?php
/**
 * สรุปรายรับ/รายจ่ายตามปี พ.ศ. ภายในขอบเขตสิทธิ์ของผู้ใช้
 * ตัวกรองเสริม: district_id, temple_id
 */
function year_range(int $beYear): array
{
    $ce = $beYear - 543;
    return ["$ce-01-01", "$ce-12-31"];
}

function summary(int $beYear, int $districtId = 0, int $templeId = 0): array
{
    [$from, $to] = year_range($beYear);
    [$sc, $sp] = scope_sql('t');
    $sql = "SELECT
        COALESCE(SUM(CASE WHEN x.type='income' THEN x.amount END),0) AS income,
        COALESCE(SUM(CASE WHEN x.type='expense' THEN x.amount END),0) AS expense,
        SUM(x.type='income') AS n_income, SUM(x.type='expense') AS n_expense
        FROM transactions x JOIN temples t ON t.id = x.temple_id
        WHERE x.is_deleted = 0 AND x.txn_date BETWEEN ? AND ? $sc";
    $p = array_merge([$from, $to], $sp);
    if ($districtId) { $sql .= ' AND t.district_id = ?'; $p[] = $districtId; }
    if ($templeId)   { $sql .= ' AND t.id = ?'; $p[] = $templeId; }
    $st = db()->prepare($sql);
    $st->execute($p);
    $r = $st->fetch();
    return [
        'income' => (float)$r['income'], 'expense' => (float)$r['expense'],
        'balance' => (float)$r['income'] - (float)$r['expense'],
        'n_income' => (int)$r['n_income'], 'n_expense' => (int)$r['n_expense'],
    ];
}

function summary_by_district(int $beYear): array
{
    [$from, $to] = year_range($beYear);
    [$sc, $sp] = scope_sql('t');
    $st = db()->prepare("SELECT d.id, d.name,
        COUNT(DISTINCT t.id) AS temples,
        COALESCE(SUM(CASE WHEN x.type='income' THEN x.amount END),0) AS income,
        COALESCE(SUM(CASE WHEN x.type='expense' THEN x.amount END),0) AS expense
        FROM districts d
        JOIN temples t ON t.district_id = d.id AND t.is_active = 1
        LEFT JOIN transactions x ON x.temple_id = t.id AND x.is_deleted = 0 AND x.txn_date BETWEEN ? AND ?
        WHERE 1=1 $sc
        GROUP BY d.id, d.name, d.sort_order ORDER BY d.sort_order");
    $st->execute(array_merge([$from, $to], $sp));
    return $st->fetchAll();
}

function temple_count(): int
{
    [$sc, $sp] = scope_sql('t');
    $st = db()->prepare("SELECT COUNT(*) FROM temples t WHERE t.is_active = 1 $sc");
    $st->execute($sp);
    return (int)$st->fetchColumn();
}

/** จำนวนวัดที่ใช้งานอยู่ ภายในขอบเขตสิทธิ์ (กรองอำเภอได้) */
function temple_count_in(int $districtId = 0): int
{
    [$sc, $sp] = scope_sql('t');
    $sql = "SELECT COUNT(*) FROM temples t WHERE t.is_active = 1 $sc";
    if ($districtId) {
        $sql .= ' AND t.district_id = ?';
        $sp[] = $districtId;
    }
    $st = db()->prepare($sql);
    $st->execute($sp);
    return (int)$st->fetchColumn();
}

/** รายเดือน (เดือน 1–12) รายรับ/รายจ่าย @return array<int,array{inc:float,exp:float}> */
function monthly_series(int $beYear, int $districtId = 0): array
{
    [$from, $to] = year_range($beYear);
    [$sc, $sp] = scope_sql('t');
    $sql = "SELECT MONTH(x.txn_date) AS m,
        COALESCE(SUM(CASE WHEN x.type='income' THEN x.amount END),0) AS inc,
        COALESCE(SUM(CASE WHEN x.type='expense' THEN x.amount END),0) AS exp
        FROM transactions x JOIN temples t ON t.id = x.temple_id
        WHERE x.is_deleted = 0 AND x.txn_date BETWEEN ? AND ? $sc";
    $p = array_merge([$from, $to], $sp);
    if ($districtId) {
        $sql .= ' AND t.district_id = ?';
        $p[] = $districtId;
    }
    $st = db()->prepare($sql . ' GROUP BY m');
    $st->execute($p);
    $out = array_fill(1, 12, ['inc' => 0.0, 'exp' => 0.0]);
    foreach ($st->fetchAll() as $r) {
        $out[(int)$r['m']] = ['inc' => (float)$r['inc'], 'exp' => (float)$r['exp']];
    }
    return $out;
}

/** หมวดที่มียอดสูงสุด @return array<int,array{name:string,total:float,n:int}> */
function top_categories(int $beYear, string $type, int $districtId = 0, int $limit = 6): array
{
    [$from, $to] = year_range($beYear);
    [$sc, $sp] = scope_sql('t');
    $sql = "SELECT c.name, SUM(x.amount) AS total, COUNT(*) AS n
        FROM transactions x JOIN temples t ON t.id = x.temple_id JOIN categories c ON c.id = x.category_id
        WHERE x.is_deleted = 0 AND x.type = ? AND x.txn_date BETWEEN ? AND ? $sc";
    $p = array_merge([$type === 'expense' ? 'expense' : 'income', $from, $to], $sp);
    if ($districtId) {
        $sql .= ' AND t.district_id = ?';
        $p[] = $districtId;
    }
    $st = db()->prepare($sql . ' GROUP BY c.id, c.name ORDER BY total DESC LIMIT ' . max(1, $limit));
    $st->execute($p);
    return array_map(fn($r) => ['name' => $r['name'], 'total' => (float)$r['total'], 'n' => (int)$r['n']], $st->fetchAll());
}

/** วัดทุกแห่งในอำเภอ (รวมวัดที่ยังไม่มีรายการ) พร้อมยอดรวมและวันที่บันทึกล่าสุด */
function temple_breakdown(int $beYear, int $districtId = 0): array
{
    [$from, $to] = year_range($beYear);
    [$sc, $sp] = scope_sql('t');
    $sql = "SELECT t.id, t.name, t.subdistrict, d.name AS district_name,
        COALESCE(SUM(CASE WHEN x.type='income' THEN x.amount END),0) AS income,
        COALESCE(SUM(CASE WHEN x.type='expense' THEN x.amount END),0) AS expense,
        COUNT(x.id) AS n, MAX(x.txn_date) AS last_date
        FROM temples t JOIN districts d ON d.id = t.district_id
        LEFT JOIN transactions x ON x.temple_id = t.id AND x.is_deleted = 0 AND x.txn_date BETWEEN ? AND ?
        WHERE t.is_active = 1 $sc";
    $p = array_merge([$from, $to], $sp);
    if ($districtId) {
        $sql .= ' AND t.district_id = ?';
        $p[] = $districtId;
    }
    $st = db()->prepare($sql . ' GROUP BY t.id, t.name, t.subdistrict, d.name ORDER BY income DESC, t.name');
    $st->execute($p);
    return $st->fetchAll();
}

/** วันที่ "ณ วันที่" ของยอดคงเหลือ: ปีปัจจุบัน = วันนี้, ปีอื่น = 31 ธ.ค. ของปีนั้น */
function balance_as_of(int $beYear): string
{
    return $beYear === be_year() ? th_date(date('Y-m-d')) : th_date(($beYear - 543) . '-12-31');
}
