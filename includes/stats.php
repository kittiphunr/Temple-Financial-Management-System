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
