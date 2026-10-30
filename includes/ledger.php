<?php
/**
 * บัญชีของวัด (เงินสด/เช็ค + เงินฝากธนาคาร) ยอดยกมา และรายการโอน/ฝาก-ถอนระหว่างบัญชี
 * ใช้ออกรายงานแบบที่ 1 (เงินสด) แบบที่ 2 (เงินฝากธนาคาร) แบบที่ 3 (เงินคงเหลือ)
 * หลักการ: ยอดคงเหลือ = ยอดยกมา ณ วันเริ่มบัญชี + รายรับ - รายจ่าย ของรายการตั้งแต่วันเริ่มบัญชีเป็นต้นไป
 * การนำเงินสดฝากธนาคาร = รายจ่ายของบัญชีเงินสด + รายรับของบัญชีธนาคาร (จับคู่ด้วย transfer_id) และไม่นับเป็นรายรับ-รายจ่ายในสถิติ
 */

/** รหัสบัญชีเงินสดของวัด (สร้างให้อัตโนมัติถ้ายังไม่มี) */
function ledger_cash_account(PDO $pdo, int $templeId): int
{
    $st = $pdo->prepare('SELECT id FROM accounts WHERE temple_id = ? AND cash_flag = 1');
    $st->execute([$templeId]);
    if ($id = $st->fetchColumn()) {
        return (int)$id;
    }
    $pdo->prepare("INSERT IGNORE INTO accounts (temple_id, kind, cash_flag, name) VALUES (?, 'cash', 1, 'เงินสด')")->execute([$templeId]);
    $st->execute([$templeId]);
    return (int)$st->fetchColumn();
}

/** บัญชีทั้งหมดของวัด: เงินสดก่อน แล้วธนาคารตามลำดับที่เพิ่ม */
function ledger_accounts(PDO $pdo, int $templeId, bool $activeOnly = false): array
{
    ledger_cash_account($pdo, $templeId);
    $st = $pdo->prepare('SELECT * FROM accounts WHERE temple_id = ?' . ($activeOnly ? ' AND is_active = 1' : '') . " ORDER BY kind = 'cash' DESC, id");
    $st->execute([$templeId]);
    return $st->fetchAll();
}

function ledger_account(PDO $pdo, int $accountId, int $templeId = 0): ?array
{
    $st = $pdo->prepare('SELECT * FROM accounts WHERE id = ?' . ($templeId ? ' AND temple_id = ?' : ''));
    $st->execute($templeId ? [$accountId, $templeId] : [$accountId]);
    return $st->fetch() ?: null;
}

function ledger_account_label(array $a): string
{
    return $a['kind'] === 'cash' ? 'เงินสด' : trim(($a['bank_name'] ?: 'ธนาคาร') . ($a['account_no'] ? ' ' . $a['account_no'] : ''));
}

/** เงื่อนไข SQL ให้รายการเป็นของบัญชีนี้ (บัญชีเงินสดรวมรายการเก่าที่ account_id เป็น NULL) */
function ledger_account_cond(array $a, string $alias = 'x'): array
{
    return $a['kind'] === 'cash'
        ? ["($alias.account_id = ? OR $alias.account_id IS NULL)", [(int)$a['id']]]
        : ["$alias.account_id = ?", [(int)$a['id']]];
}

/** ยอดคงเหลือก่อนวันที่ $before (Y-m-d) = ยอดยกมา + รายการตั้งแต่วันเริ่มบัญชีจนถึงก่อนวันนั้น; ก่อนวันเริ่มบัญชีถือเป็น 0 */
function ledger_balance_before(PDO $pdo, array $a, string $before): float
{
    $open = $a['opening_date'];
    if ($open && $before <= $open) {
        return $before === $open ? (float)$a['opening_balance'] : 0.0;
    }
    [$cond, $p] = ledger_account_cond($a);
    $sql = "SELECT COALESCE(SUM(CASE WHEN x.type='income' THEN x.amount ELSE -x.amount END),0) FROM transactions x
        WHERE x.temple_id = ? AND $cond AND x.is_deleted = 0 AND x.txn_date < ?" . ($open ? ' AND x.txn_date >= ?' : '');
    $params = array_merge([(int)$a['temple_id']], $p, [$before], $open ? [$open] : []);
    $st = $pdo->prepare($sql);
    $st->execute($params);
    return (float)$a['opening_balance'] + (float)$st->fetchColumn();
}

/**
 * รายเดือนของบัญชี: ยอดยกมา รายการ (เรียงวันที่/รหัส) พร้อมยอดคงเหลือหลังแต่ละรายการ ยอดรวมรับ/จ่าย และยอดยกไป
 * @return array{carry:float,rows:array,inc:float,exp:float,closing:float,before_open:bool}
 */
function ledger_month(PDO $pdo, array $a, int $ceYear, int $month): array
{
    $from = sprintf('%04d-%02d-01', $ceYear, $month);
    $to = date('Y-m-t', strtotime($from));
    $open = $a['opening_date'];
    $carry = ledger_balance_before($pdo, $a, $from);
    [$cond, $p] = ledger_account_cond($a);
    $st = $pdo->prepare("SELECT x.*, c.name AS category FROM transactions x JOIN categories c ON c.id = x.category_id
        WHERE x.temple_id = ? AND $cond AND x.is_deleted = 0 AND x.txn_date BETWEEN ? AND ?" . ($open ? ' AND x.txn_date >= ?' : '') . ' ORDER BY x.txn_date, x.id');
    $st->execute(array_merge([(int)$a['temple_id']], $p, [$from, $to], $open ? [$open] : []));
    $bal = $carry;
    $inc = $exp = 0.0;
    $rows = [];
    foreach ($st->fetchAll() as $r) {
        $amt = (float)$r['amount'];
        if ($r['type'] === 'income') { $inc += $amt; $bal += $amt; } else { $exp += $amt; $bal -= $amt; }
        $r['balance'] = $bal;
        $rows[] = $r;
    }
    return ['carry' => $carry, 'rows' => $rows, 'inc' => $inc, 'exp' => $exp, 'closing' => $bal, 'before_open' => (bool)($open && $to < $open)];
}

/** ยอดคงเหลือ ณ สิ้นวัน $date */
function ledger_balance_on(PDO $pdo, array $a, string $date): float
{
    return ledger_balance_before($pdo, $a, date('Y-m-d', strtotime($date . ' +1 day')));
}

/** บัญชีที่ควรแสดงในรายงานเดือนนั้น: ที่ใช้งานอยู่ หรือเคยมีรายการ/ยอดคงเหลือ */
function ledger_report_accounts(PDO $pdo, int $templeId, int $ceYear, int $month, string $kind): array
{
    $out = [];
    foreach (ledger_accounts($pdo, $templeId) as $a) {
        if ($a['kind'] !== $kind) continue;
        if ($a['is_active'] || ledger_month($pdo, $a, $ceYear, $month)['rows'] || abs(ledger_balance_on($pdo, $a, date('Y-m-t', strtotime(sprintf('%04d-%02d-01', $ceYear, $month))))) > 0.004) {
            $out[] = $a;
        }
    }
    return $out;
}

/**
 * โอน/ฝาก-ถอนระหว่างบัญชีของวัดเดียวกัน (สร้างสองรายการจับคู่: จ่ายออกจากต้นทาง + รับเข้าปลายทาง)
 * @return array{0:?int,1:string} [รหัสกลุ่มโอน, ข้อผิดพลาด]
 */
function ledger_transfer(PDO $pdo, int $templeId, int $fromId, int $toId, string $date, float $amount, string $desc, ?int $userId, string $docNo = ''): array
{
    $from = ledger_account($pdo, $fromId, $templeId);
    $to = ledger_account($pdo, $toId, $templeId);
    if (!$from || !$to || !$from['is_active'] || !$to['is_active']) return [null, 'ไม่พบบัญชีต้นทางหรือปลายทาง'];
    if ($fromId === $toId) return [null, 'บัญชีต้นทางและปลายทางต้องไม่ใช่บัญชีเดียวกัน'];
    if ($amount <= 0 || $amount > 999999999999) return [null, 'จำนวนเงินต้องมากกว่า 0'];
    $cat = fn(string $type) => (int)$pdo->query("SELECT id FROM categories WHERE type = '$type' AND is_transfer = 1 ORDER BY id LIMIT 1")->fetchColumn();
    $cOut = $cat('expense');
    $cIn = $cat('income');
    if (!$cOut || !$cIn) return [null, 'ไม่พบหมวดโอนระหว่างบัญชีของระบบ (ตรวจว่ารัน migration แล้ว)'];
    $desc = mb_substr(trim($desc), 0, 255) ?: ('โอน/นำฝาก ' . ledger_account_label($from) . ' → ' . ledger_account_label($to));
    $pdo->beginTransaction();
    try {
        $ins = $pdo->prepare('INSERT INTO transactions (temple_id,type,category_id,txn_date,doc_no,description,amount,account_id,created_by) VALUES (?,?,?,?,?,?,?,?,?)');
        $ins->execute([$templeId, 'expense', $cOut, $date, $docNo ?: null, $desc, $amount, $fromId, $userId]);
        $gid = (int)$pdo->lastInsertId();
        $ins->execute([$templeId, 'income', $cIn, $date, $docNo ?: null, $desc, $amount, $toId, $userId]);
        $pdo->prepare('UPDATE transactions SET transfer_id = ? WHERE id IN (?, ?)')->execute([$gid, $gid, (int)$pdo->lastInsertId()]);
        $pdo->commit();
        return [$gid, ''];
    } catch (Throwable $e) {
        $pdo->rollBack();
        return [null, 'บันทึกรายการโอนไม่สำเร็จ'];
    }
}
