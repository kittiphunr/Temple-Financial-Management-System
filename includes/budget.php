<?php
/**
 * งบประมาณประจำปี (ปี พ.ศ.) รายหมวดต่อวัด เทียบกับผลจริงจากรายการที่บันทึก (ไม่นับรายการที่ลบและรายการโอน/ฝาก-ถอน)
 * ถ้ายังไม่ได้รัน migration (sql/migrations/2026_budget.sql) ฟีเจอร์นี้จะถูกซ่อน
 */

const BUDGET_WARN_PCT = 80;

function budget_available(): bool
{
    static $ok = null;
    if ($ok === null) {
        try {
            db()->query('SELECT id, temple_id, budget_year, category_id, amount FROM budgets LIMIT 1');
            $ok = true;
        } catch (PDOException) {
            $ok = false;
        }
    }
    return $ok;
}

/**
 * แถวงบรายหมวด: งบ ผลจริง และร้อยละ — แสดงหมวดที่ใช้งานอยู่ หรือที่มีงบ/ผลจริงในปีนั้น
 * @return array{income:array,expense:array}
 */
function budget_rows(PDO $pdo, int $templeId, int $beYear): array
{
    [$from, $to] = [sprintf('%04d-01-01', $beYear - 543), sprintf('%04d-12-31', $beYear - 543)];
    $st = $pdo->prepare("SELECT c.id, c.type, c.name, c.is_active, b.amount AS budget,
          (SELECT COALESCE(SUM(x.amount),0) FROM transactions x WHERE x.category_id = c.id AND x.temple_id = ? AND x.is_deleted = 0 AND x.transfer_id IS NULL AND x.txn_date BETWEEN ? AND ?) AS actual
        FROM categories c LEFT JOIN budgets b ON b.category_id = c.id AND b.temple_id = ? AND b.budget_year = ?
        WHERE c.is_transfer = 0 ORDER BY c.type, c.sort_order, c.name");
    $st->execute([$templeId, $from, $to, $templeId, $beYear]);
    $out = ['income' => [], 'expense' => []];
    foreach ($st->fetchAll() as $r) {
        $r['budget'] = $r['budget'] === null ? null : (float)$r['budget'];
        $r['actual'] = (float)$r['actual'];
        if (!$r['is_active'] && $r['budget'] === null && $r['actual'] == 0.0) {
            continue;
        }
        $r['pct'] = $r['budget'] ? $r['actual'] / $r['budget'] * 100 : null;
        $out[$r['type']][] = $r;
    }
    return $out;
}

/** ยอดงบ/ผลจริงรวมของแต่ละประเภท (เฉพาะหมวดที่ตั้งงบ ใช้เทียบกัน) */
function budget_totals(array $rows): array
{
    $t = ['budget' => 0.0, 'actual' => 0.0, 'actual_all' => 0.0];
    foreach ($rows as $r) {
        $t['actual_all'] += $r['actual'];
        if ($r['budget'] !== null) {
            $t['budget'] += $r['budget'];
            $t['actual'] += $r['actual'];
        }
    }
    return $t;
}

/** @param array<int,string> $amounts category_id => ข้อความจำนวนเงิน (ว่าง/0 = ลบงบ) @return int จำนวนหมวดที่ตั้งงบอยู่หลังบันทึก */
function budget_save(PDO $pdo, int $templeId, int $beYear, array $amounts, ?int $userId): int
{
    $cats = $pdo->query('SELECT id FROM categories WHERE is_transfer = 0')->fetchAll(PDO::FETCH_COLUMN);
    $valid = array_flip(array_map('intval', $cats));
    $ins = $pdo->prepare('INSERT INTO budgets (temple_id, budget_year, category_id, amount, updated_by, updated_at) VALUES (?,?,?,?,?,NOW())
        ON DUPLICATE KEY UPDATE amount = VALUES(amount), updated_by = VALUES(updated_by), updated_at = NOW()');
    $del = $pdo->prepare('DELETE FROM budgets WHERE temple_id = ? AND budget_year = ? AND category_id = ?');
    foreach ($amounts as $cid => $raw) {
        $cid = (int)$cid;
        if (!isset($valid[$cid])) {
            continue;
        }
        $raw = trim(str_replace(',', '', (string)$raw));
        if ($raw !== '' && !is_numeric($raw)) {
            continue;   // ค่าที่ไม่ใช่ตัวเลข: คงงบเดิมไว้
        }
        $amt = $raw === '' ? 0.0 : round((float)$raw, 2);
        if ($amt > 0 && $amt <= 999999999999) {
            $ins->execute([$templeId, $beYear, $cid, $amt, $userId]);
        } elseif ($amt == 0.0) {
            $del->execute([$templeId, $beYear, $cid]);
        }
    }
    $n = $pdo->prepare('SELECT COUNT(*) FROM budgets WHERE temple_id = ? AND budget_year = ?');
    $n->execute([$templeId, $beYear]);
    return (int)$n->fetchColumn();
}

/** คัดลอกงบจากปีก่อนเฉพาะหมวดที่ปีนี้ยังไม่ได้ตั้ง @return int จำนวนหมวดที่คัดลอก */
function budget_copy_prev(PDO $pdo, int $templeId, int $beYear, ?int $userId): int
{
    $st = $pdo->prepare('INSERT IGNORE INTO budgets (temple_id, budget_year, category_id, amount, updated_by, updated_at)
        SELECT temple_id, ?, category_id, amount, ?, NOW() FROM budgets WHERE temple_id = ? AND budget_year = ?');
    $st->execute([$beYear, $userId, $templeId, $beYear - 1]);
    return $st->rowCount();
}

/** เตือนหลังบันทึกรายจ่าย: หมวดนี้ใช้ถึง/เกินงบของปีที่รายการนั้นอยู่หรือไม่ @return string ข้อความเตือน ('' = ไม่เตือน) */
function budget_warning(PDO $pdo, int $templeId, int $categoryId, string $ymd): string
{
    if (!budget_available()) {
        return '';
    }
    $be = (int)substr($ymd, 0, 4) + 543;
    $st = $pdo->prepare('SELECT b.amount, c.name FROM budgets b JOIN categories c ON c.id = b.category_id WHERE b.temple_id = ? AND b.budget_year = ? AND b.category_id = ?');
    $st->execute([$templeId, $be, $categoryId]);
    $b = $st->fetch();
    if (!$b) {
        return '';
    }
    $a = $pdo->prepare('SELECT COALESCE(SUM(amount),0) FROM transactions WHERE temple_id = ? AND category_id = ? AND is_deleted = 0 AND transfer_id IS NULL AND txn_date BETWEEN ? AND ?');
    $a->execute([$templeId, $categoryId, sprintf('%04d-01-01', $be - 543), sprintf('%04d-12-31', $be - 543)]);
    $used = (float)$a->fetchColumn();
    $pct = $used / (float)$b['amount'] * 100;
    if ($pct >= 100) {
        return 'หมวด "' . $b['name'] . '" เกินงบปี ' . $be . ' แล้ว (ใช้ ' . money($used) . ' จากงบ ' . money($b['amount']) . ' บาท)';
    }
    if ($pct >= BUDGET_WARN_PCT) {
        return 'หมวด "' . $b['name'] . '" ใช้ไปแล้ว ' . number_format($pct, 0) . '% ของงบปี ' . $be . ' (' . money($used) . ' จาก ' . money($b['amount']) . ' บาท)';
    }
    return '';
}
