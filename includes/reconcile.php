<?php
/**
 * กระทบยอดบัญชีธนาคารรายเดือน: เทียบยอดตามสมุดของวัด (ledger) กับยอดตาม statement
 * ยอดที่ธนาคารควรแสดง = ยอดสมุด - เงินฝากระหว่างทาง + รายจ่ายที่ธนาคารยังไม่หัก
 * ส่วนต่าง = ยอด statement - ยอดที่ควรแสดง (0 = ตรงกัน)
 * รายการที่ติ๊กว่า "ผ่านรายการธนาคารแล้ว" เก็บใน bank_cleared พร้อมเดือนที่ติ๊ก
 * ถ้ายังไม่ได้รัน migration (sql/migrations/2026_bank_reconcile.sql) ฟีเจอร์นี้จะถูกซ่อน
 */

function reconcile_available(): bool
{
    static $ok = null;
    if ($ok === null) {
        try {
            db()->query('SELECT id, difference FROM bank_reconciliations LIMIT 1');
            db()->query('SELECT transaction_id FROM bank_cleared LIMIT 1');
            $ok = true;
        } catch (PDOException) {
            $ok = false;
        }
    }
    return $ok;
}

function reconcile_month_end(int $beYear, int $month): string
{
    return date('Y-m-t', strtotime(sprintf('%04d-%02d-01', $beYear - 543, $month)));
}

/**
 * รายการของบัญชีที่ต้องพิจารณาในการกระทบยอดเดือนนี้: ทุกรายการถึงสิ้นเดือนที่ยังไม่ผ่านรายการในเดือนก่อนหน้า
 * @return array<int,array> แต่ละรายการมี cleared_ym (ปี*100+เดือน ที่ติ๊ก หรือ null)
 */
function reconcile_items(PDO $pdo, array $a, int $beYear, int $month): array
{
    $end = reconcile_month_end($beYear, $month);
    [$cond, $p] = ledger_account_cond($a);
    $open = $a['opening_date'];
    $st = $pdo->prepare("SELECT x.id, x.txn_date, x.type, x.amount, x.description, x.doc_no, c.name AS category, (b.recon_year * 100 + b.recon_month) AS cleared_ym
        FROM transactions x JOIN categories c ON c.id = x.category_id LEFT JOIN bank_cleared b ON b.transaction_id = x.id
        WHERE x.temple_id = ? AND $cond AND x.is_deleted = 0 AND x.txn_date <= ?" . ($open ? ' AND x.txn_date >= ?' : '') . '
          AND (b.transaction_id IS NULL OR (b.recon_year * 100 + b.recon_month) >= ?)
        ORDER BY x.txn_date, x.id');
    $st->execute(array_merge([(int)$a['temple_id']], $p, [$end], $open ? [$open] : [], [$beYear * 100 + $month]));
    return $st->fetchAll();
}

/** รายการนี้ถือว่าผ่านรายการธนาคารแล้ว ณ สิ้นเดือนที่กระทบยอดหรือไม่ (ติ๊กในเดือนนี้หรือก่อนหน้า) */
function reconcile_is_cleared(array $item, int $beYear, int $month): bool
{
    return $item['cleared_ym'] !== null && (int)$item['cleared_ym'] <= $beYear * 100 + $month;
}

/** คำนวณผลจากยอด statement ที่กรอก @return array{book:float,out_in:float,out_exp:float,expected:float,difference:float} */
function reconcile_compute(PDO $pdo, array $a, int $beYear, int $month, float $statement): array
{
    $book = ledger_balance_on($pdo, $a, reconcile_month_end($beYear, $month));
    $in = $exp = 0.0;
    foreach (reconcile_items($pdo, $a, $beYear, $month) as $it) {
        if (reconcile_is_cleared($it, $beYear, $month)) {
            continue;
        }
        if ($it['type'] === 'income') { $in += (float)$it['amount']; } else { $exp += (float)$it['amount']; }
    }
    $expected = round($book - $in + $exp, 2);
    return ['book' => round($book, 2), 'out_in' => round($in, 2), 'out_exp' => round($exp, 2), 'expected' => $expected, 'difference' => round($statement - $expected, 2)];
}

function reconcile_row(PDO $pdo, int $accountId, int $beYear, int $month): ?array
{
    $st = $pdo->prepare('SELECT r.*, u.full_name AS by_name FROM bank_reconciliations r LEFT JOIN users u ON u.id = r.reconciled_by
        WHERE r.account_id = ? AND r.recon_year = ? AND r.recon_month = ?');
    $st->execute([$accountId, $beYear, $month]);
    return $st->fetch() ?: null;
}

/** ผลที่บันทึกไว้ยังตรงกับสมุดปัจจุบันหรือไม่ (ถ้ามีการแก้รายการภายหลังจะเป็น false) */
function reconcile_is_current(PDO $pdo, array $a, array $row): bool
{
    $now = reconcile_compute($pdo, $a, (int)$row['recon_year'], (int)$row['recon_month'], (float)$row['statement_balance']);
    return abs($now['book'] - (float)$row['book_balance']) < 0.005 && abs($now['difference'] - (float)$row['difference']) < 0.005;
}

/**
 * บันทึกผลกระทบยอด: ติ๊กรายการ (ids) + ยอด statement
 * @param int[] $ids รายการที่ผ่านรายการธนาคารแล้ว ณ เดือนนี้
 * @return string ข้อผิดพลาด ('' = สำเร็จ)
 */
function reconcile_save(PDO $pdo, array $a, int $beYear, int $month, float $statement, array $ids, string $note, ?int $userId): string
{
    if ($a['kind'] !== 'bank') {
        return 'กระทบยอดได้เฉพาะบัญชีธนาคาร';
    }
    $end = reconcile_month_end($beYear, $month);
    if ($end > date('Y-m-d')) {
        return 'ยังไม่สิ้นเดือน กระทบยอดได้หลังสิ้นเดือนที่เลือก';
    }
    $ym = $beYear * 100 + $month;
    $valid = [];
    foreach (reconcile_items($pdo, $a, $beYear, $month) as $it) {
        if ($it['cleared_ym'] === null || (int)$it['cleared_ym'] === $ym) {
            $valid[(int)$it['id']] = true;      // ติ๊กได้เฉพาะรายการที่ยังไม่ผ่านหรือที่ติ๊กไว้ในเดือนนี้
        }
    }
    $pdo->beginTransaction();
    try {
        $pdo->prepare('DELETE FROM bank_cleared WHERE account_id = ? AND recon_year = ? AND recon_month = ?')->execute([(int)$a['id'], $beYear, $month]);
        $ins = $pdo->prepare('INSERT INTO bank_cleared (transaction_id, account_id, recon_year, recon_month, marked_by, marked_at) VALUES (?,?,?,?,?,NOW())');
        foreach (array_unique(array_map('intval', $ids)) as $id) {
            if (isset($valid[$id])) {
                $ins->execute([$id, (int)$a['id'], $beYear, $month, $userId]);
            }
        }
        $c = reconcile_compute($pdo, $a, $beYear, $month, $statement);
        $pdo->prepare('REPLACE INTO bank_reconciliations (temple_id, account_id, recon_year, recon_month, statement_balance, book_balance, outstanding_in, outstanding_out, difference, note, reconciled_by, reconciled_at)
            VALUES (?,?,?,?,?,?,?,?,?,?,?,NOW())')
            ->execute([(int)$a['temple_id'], (int)$a['id'], $beYear, $month, $statement, $c['book'], $c['out_in'], $c['out_exp'], $c['difference'], $note !== '' ? $note : null, $userId]);
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
    audit('reconcile', 'account', (int)$a['id'], "$beYear-$month diff " . $c['difference']);
    return '';
}
