<?php
/**
 * รายการประจำ: ตั้งแม่แบบรายรับ-รายจ่ายที่เกิดซ้ำทุกเดือน (ค่าน้ำ ค่าไฟ เงินเดือน เงินสังฆทานรายเดือน ฯลฯ)
 * ระบบ "ไม่บันทึกเอง" — เมื่อถึงกำหนดจะขึ้นในรายการรอยืนยัน ผู้ใช้ตรวจ/แก้จำนวนเงินแล้วกดบันทึก หรือกด "ข้าม" เดือนนั้น
 * - ถึงกำหนด = วันที่ตั้งไว้ของเดือนนั้นมาถึงแล้ว (เดือนที่ไม่มีวันนั้นใช้วันสุดท้ายของเดือน) นับย้อนหลังไม่เกิน 12 เดือน
 * - เดือนนั้นบันทึกแล้ว (มีรายการที่ยังไม่ถูกลบ) หรือข้ามแล้ว จะไม่ขึ้นอีก · ลบรายการที่บันทึกแล้ว เดือนนั้นจะกลับมารอยืนยันใหม่
 * - เดือนที่ปิดงวดแล้วบันทึกไม่ได้ (ขึ้นสถานะ "ปิดงวดแล้ว")
 * ถ้ายังไม่ได้รัน migration (sql/migrations/2026_recurring.sql) ฟีเจอร์นี้จะถูกซ่อน
 */

const RECURRING_LOOKBACK_MONTHS = 12;

function recurring_available(): bool
{
    static $ok = null;
    if ($ok === null) {
        try {
            db()->query('SELECT id, temple_id, day_of_month FROM recurring_items LIMIT 1');
            db()->query('SELECT recurring_id FROM recurring_skips LIMIT 1');
            db()->query('SELECT recurring_id, recurring_period FROM transactions LIMIT 1');
            $ok = true;
        } catch (PDOException) {
            $ok = false;
        }
    }
    return $ok;
}

/** รายการประจำของวัด พร้อมชื่อหมวด/บัญชี/กองทุน */
function recurring_list(PDO $pdo, int $templeId): array
{
    $st = $pdo->prepare("SELECT r.*, c.name AS category, a.kind AS acc_kind, a.bank_name AS acc_bank, a.account_no AS acc_no,
            " . (fund_available() ? '(SELECT f.name FROM funds f WHERE f.id = r.fund_id)' : 'NULL') . " AS fund_name
        FROM recurring_items r JOIN categories c ON c.id = r.category_id LEFT JOIN accounts a ON a.id = r.account_id
        WHERE r.temple_id = ? ORDER BY r.is_active DESC, r.type, r.day_of_month, r.description");
    $st->execute([$templeId]);
    return $st->fetchAll();
}

function recurring_row(PDO $pdo, int $id, int $templeId): ?array
{
    $st = $pdo->prepare('SELECT * FROM recurring_items WHERE id = ? AND temple_id = ?');
    $st->execute([$id, $templeId]);
    return $st->fetch() ?: null;
}

/** วันที่ (Y-m-d) ของเดือน $period (Y-m-01) ตามวันที่ตั้งไว้ ปรับให้ไม่เกินวันสุดท้ายของเดือน */
function recurring_date(string $period, int $day): string
{
    $last = (int)date('t', strtotime($period));
    return substr($period, 0, 8) . sprintf('%02d', max(1, min($day, $last)));
}

/**
 * ตรวจและบันทึกแม่แบบรายการประจำ ($id = 0 สร้างใหม่)
 * @param array $in type, category_id, account_id ('cash'|id), fund_id, description, amount, day_of_month, start_m, start_y (พ.ศ.), end_m, end_y (ว่าง = ไม่สิ้นสุด)
 * @return array{0:bool,1:string}
 */
function recurring_save(PDO $pdo, int $templeId, array $in, int $id, ?int $userId): array
{
    $type = ($in['type'] ?? '') === 'expense' ? 'expense' : 'income';
    $catId = (int)($in['category_id'] ?? 0);
    $cs = $pdo->prepare('SELECT COUNT(*) FROM categories WHERE id = ? AND type = ? AND is_active = 1 AND is_transfer = 0');
    $cs->execute([$catId, $type]);
    if (!$cs->fetchColumn()) {
        return [false, 'หมวดไม่ถูกต้อง'];
    }
    $accIn = (string)($in['account_id'] ?? 'cash');
    $acc = $accIn === 'cash' || $accIn === '' ? ledger_account($pdo, ledger_cash_account($pdo, $templeId)) : ledger_account($pdo, (int)$accIn, $templeId);
    if (!$acc || !$acc['is_active']) {
        return [false, 'เลือกบัญชีของวัดนี้ (เงินสดหรือบัญชีธนาคาร)'];
    }
    $old = $id ? recurring_row($pdo, $id, $templeId) : null;
    if ($id && !$old) {
        return [false, 'ไม่พบรายการประจำนี้'];
    }
    $fundId = null;
    if (fund_available()) {
        [$fundId, $fe] = fund_resolve($pdo, $in['fund_id'] ?? 0, $templeId, $old && $old['fund_id'] ? (int)$old['fund_id'] : null);
        if ($fe !== '') {
            return [false, $fe];
        }
    }
    $desc = mb_substr(trim((string)($in['description'] ?? '')), 0, 255);
    if ($desc === '') {
        return [false, 'กรุณากรอกชื่อรายการ เช่น ค่าไฟฟ้า'];
    }
    $amount = round((float)str_replace(',', '', (string)($in['amount'] ?? '0')), 2);
    if ($amount <= 0 || $amount > 999999999999) {
        return [false, 'จำนวนเงินต้องมากกว่า 0'];
    }
    $day = (int)($in['day_of_month'] ?? 0);
    if ($day < 1 || $day > 31) {
        return [false, 'วันที่ของเดือนต้องอยู่ระหว่าง 1–31'];
    }
    $sm = (int)($in['start_m'] ?? 0);
    $sy = (int)($in['start_y'] ?? 0) - 543;
    if ($sm < 1 || $sm > 12 || $sy < 1900 || $sy > 2200) {
        return [false, 'เดือนที่เริ่มไม่ถูกต้อง'];
    }
    $start = sprintf('%04d-%02d-01', $sy, $sm);
    $end = null;
    if ((int)($in['end_m'] ?? 0) > 0 || (int)($in['end_y'] ?? 0) > 0) {
        $em = (int)($in['end_m'] ?? 0);
        $ey = (int)($in['end_y'] ?? 0) - 543;
        if ($em < 1 || $em > 12 || $ey < 1900 || $ey > 2200) {
            return [false, 'เดือนสิ้นสุดไม่ถูกต้อง (เว้นว่างทั้งเดือนและปีถ้าไม่มีกำหนดสิ้นสุด)'];
        }
        $end = sprintf('%04d-%02d-01', $ey, $em);
        if ($end < $start) {
            return [false, 'เดือนสิ้นสุดต้องไม่ก่อนเดือนที่เริ่ม'];
        }
    }
    $isActive = isset($in['is_active']) ? (int)(bool)$in['is_active'] : 1;
    if ($id) {
        $pdo->prepare('UPDATE recurring_items SET type=?, category_id=?, account_id=?, fund_id=?, description=?, amount=?, day_of_month=?, start_month=?, end_month=?, is_active=? WHERE id=? AND temple_id=?')
            ->execute([$type, $catId, $acc['id'], $fundId, $desc, $amount, $day, $start, $end, $isActive, $id, $templeId]);
        return [true, 'แก้ไขรายการประจำแล้ว'];
    }
    $pdo->prepare('INSERT INTO recurring_items (temple_id, type, category_id, account_id, fund_id, description, amount, day_of_month, start_month, end_month, is_active, created_by) VALUES (?,?,?,?,?,?,?,?,?,?,?,?)')
        ->execute([$templeId, $type, $catId, $acc['id'], $fundId, $desc, $amount, $day, $start, $end, $isActive, $userId]);
    return [true, 'เพิ่มรายการประจำแล้ว'];
}

/**
 * รายการประจำที่ถึงกำหนดและยังไม่ได้บันทึก/ข้าม เรียงตามวันที่
 * @return array<int,array{item:array,period:string,date:string,locked:bool}>
 */
function recurring_due(PDO $pdo, int $templeId, ?string $today = null): array
{
    $today ??= date('Y-m-d');
    $st = $pdo->prepare('SELECT * FROM recurring_items WHERE temple_id = ? AND is_active = 1');
    $st->execute([$templeId]);
    $items = $st->fetchAll();
    if (!$items) {
        return [];
    }
    $thisMonth = substr($today, 0, 8) . '01';
    $floor = date('Y-m-01', strtotime($thisMonth . ' -' . (RECURRING_LOOKBACK_MONTHS - 1) . ' months'));
    $done = [];
    $q = $pdo->prepare('SELECT recurring_id, recurring_period FROM transactions WHERE temple_id = ? AND recurring_id IS NOT NULL AND is_deleted = 0 AND recurring_period >= ?');
    $q->execute([$templeId, $floor]);
    foreach ($q->fetchAll() as $r) {
        $done[$r['recurring_id'] . '|' . $r['recurring_period']] = true;
    }
    $q = $pdo->prepare('SELECT s.recurring_id, s.period FROM recurring_skips s JOIN recurring_items r ON r.id = s.recurring_id WHERE r.temple_id = ? AND s.period >= ?');
    $q->execute([$templeId, $floor]);
    foreach ($q->fetchAll() as $r) {
        $done[$r['recurring_id'] . '|' . $r['period']] = true;
    }
    $out = [];
    foreach ($items as $it) {
        $p = max($floor, $it['start_month']);
        for ($i = 0; $i < 40 && $p <= $thisMonth; $i++, $p = date('Y-m-01', strtotime($p . ' +1 month'))) {
            if ($it['end_month'] !== null && $p > $it['end_month']) {
                break;
            }
            $date = recurring_date($p, (int)$it['day_of_month']);
            if ($date > $today || isset($done[$it['id'] . '|' . $p])) {
                continue;
            }
            $out[] = ['item' => $it, 'period' => $p, 'date' => $date, 'locked' => period_closed($pdo, $templeId, $date)];
        }
    }
    usort($out, fn($a, $b) => [$a['date'], $a['item']['id']] <=> [$b['date'], $b['item']['id']]);
    return $out;
}

function recurring_due_count(PDO $pdo, int $templeId): int
{
    return recurring_available() ? count(array_filter(recurring_due($pdo, $templeId), fn($d) => !$d['locked'])) : 0;
}

/** ตรวจรูปแบบ period (Y-m-01) */
function recurring_valid_period(string $p): bool
{
    return (bool)preg_match('/^\d{4}-(0[1-9]|1[0-2])-01$/', $p);
}

/**
 * ยืนยันบันทึกรายการประจำของเดือน $period ($amount = null ใช้จำนวนตั้งต้น)
 * @return array{0:bool,1:string}
 */
function recurring_post(PDO $pdo, int $templeId, int $itemId, string $period, ?float $amount, ?int $userId): array
{
    if (!recurring_valid_period($period)) {
        return [false, 'เดือนไม่ถูกต้อง'];
    }
    $pdo->beginTransaction();
    try {
        // ล็อกแถวแม่แบบ กันกดซ้ำพร้อมกันแล้วบันทึกสองครั้ง
        $st = $pdo->prepare('SELECT * FROM recurring_items WHERE id = ? AND temple_id = ? FOR UPDATE');
        $st->execute([$itemId, $templeId]);
        $it = $st->fetch();
        if (!$it || !$it['is_active']) {
            $pdo->rollBack();
            return [false, 'ไม่พบรายการประจำนี้หรือปิดใช้งานแล้ว'];
        }
        $due = null;
        foreach (recurring_due($pdo, $templeId) as $d) {
            if ((int)$d['item']['id'] === $itemId && $d['period'] === $period) {
                $due = $d;
                break;
            }
        }
        if (!$due) {
            $pdo->rollBack();
            return [false, $it['description'] . ': เดือนนี้บันทึกหรือข้ามไปแล้ว หรือยังไม่ถึงกำหนด'];
        }
        if ($due['locked']) {
            $pdo->rollBack();
            return [false, $it['description'] . ': ' . period_lock_error($pdo, $templeId, $due['date'])];
        }
        $amt = round($amount ?? (float)$it['amount'], 2);
        if ($amt <= 0 || $amt > 999999999999) {
            $pdo->rollBack();
            return [false, $it['description'] . ': จำนวนเงินต้องมากกว่า 0'];
        }
        $cs = $pdo->prepare('SELECT COUNT(*) FROM categories WHERE id = ? AND type = ? AND is_active = 1 AND is_transfer = 0');
        $cs->execute([$it['category_id'], $it['type']]);
        if (!$cs->fetchColumn()) {
            $pdo->rollBack();
            return [false, $it['description'] . ': หมวดของรายการนี้ปิดใช้งานแล้ว กรุณาแก้ไขรายการประจำ'];
        }
        if ($it['account_id']) {
            $acc = ledger_account($pdo, (int)$it['account_id'], $templeId);
            if (!$acc || !$acc['is_active']) {
                $pdo->rollBack();
                return [false, $it['description'] . ': บัญชีของรายการนี้ปิดใช้งานแล้ว กรุณาแก้ไขรายการประจำ'];
            }
        }
        if ($it['fund_id'] && fund_available()) {
            [, $fe] = fund_resolve($pdo, $it['fund_id'], $templeId, null);
            if ($fe !== '') {
                $pdo->rollBack();
                return [false, $it['description'] . ': ' . $fe . ' กรุณาแก้ไขรายการประจำ'];
            }
        }
        $pdo->prepare('INSERT INTO transactions (temple_id,type,category_id,txn_date,doc_no,description,amount,account_id,fund_id,created_by,recurring_id,recurring_period) VALUES (?,?,?,?,?,?,?,?,?,?,?,?)')
            ->execute([$templeId, $it['type'], $it['category_id'], $due['date'], '', $it['description'], $amt, $it['account_id'] ?: ledger_cash_account($pdo, $templeId), fund_available() ? $it['fund_id'] : null, $userId, $itemId, $period]);
        $newId = (int)$pdo->lastInsertId();
        $pdo->commit();
        audit('create', 'transaction', $newId, "{$it['type']} $amt recurring#$itemId $period");
        return [true, $it['description']];
    } catch (Throwable $e) {
        $pdo->inTransaction() && $pdo->rollBack();
        throw $e;
    }
}

/** ข้ามรายการประจำของเดือนนั้น (ไม่บันทึก) @return array{0:bool,1:string} */
function recurring_skip(PDO $pdo, int $templeId, int $itemId, string $period, ?int $userId): array
{
    if (!recurring_valid_period($period) || !recurring_row($pdo, $itemId, $templeId)) {
        return [false, 'ไม่พบรายการประจำหรือเดือนไม่ถูกต้อง'];
    }
    foreach (recurring_due($pdo, $templeId) as $d) {
        if ((int)$d['item']['id'] === $itemId && $d['period'] === $period) {
            $pdo->prepare('INSERT IGNORE INTO recurring_skips (recurring_id, period, skipped_by, skipped_at) VALUES (?,?,?,NOW())')->execute([$itemId, $period, $userId]);
            audit('recurring_skip', 'recurring', $itemId, $period);
            return [true, 'ข้าม ' . $d['item']['description'] . ' เดือนนี้แล้ว'];
        }
    }
    return [false, 'เดือนนี้บันทึกหรือข้ามไปแล้ว หรือยังไม่ถึงกำหนด'];
}
