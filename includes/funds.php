<?php
/**
 * กองทุน/โครงการของวัด (กฐิน ผ้าป่า สร้างอาคาร กองทุนการศึกษา ฯลฯ): ผูกรายการรายรับ-รายจ่ายเข้ากับกองทุน
 * เพื่อดูยอดรับ ยอดใช้ และคงเหลือของแต่ละกองทุน (ไม่นับรายการที่ลบและรายการโอน/ฝาก-ถอน)
 * ถ้ายังไม่ได้รัน migration (sql/migrations/2026_funds.sql) ฟีเจอร์นี้จะถูกซ่อน
 */

function fund_available(): bool
{
    static $ok = null;
    if ($ok === null) {
        try {
            db()->query('SELECT id, temple_id, name, target_amount FROM funds LIMIT 1');
            db()->query('SELECT fund_id FROM transactions LIMIT 1');
            $ok = true;
        } catch (PDOException) {
            $ok = false;
        }
    }
    return $ok;
}

/** กองทุนของวัดพร้อมยอดรับ/จ่ายสะสม (ทุกปี) @return array<int,array> */
function fund_list(PDO $pdo, int $templeId, bool $activeOnly = false): array
{
    $st = $pdo->prepare("SELECT f.*,
          COALESCE((SELECT SUM(x.amount) FROM transactions x WHERE x.fund_id = f.id AND x.type = 'income' AND x.is_deleted = 0 AND x.transfer_id IS NULL), 0) AS raised,
          COALESCE((SELECT SUM(x.amount) FROM transactions x WHERE x.fund_id = f.id AND x.type = 'expense' AND x.is_deleted = 0 AND x.transfer_id IS NULL), 0) AS spent
        FROM funds f WHERE f.temple_id = ?" . ($activeOnly ? ' AND f.is_active = 1' : '') . ' ORDER BY f.is_active DESC, f.name');
    $st->execute([$templeId]);
    $rows = $st->fetchAll();
    foreach ($rows as &$r) {
        $r['raised'] = (float)$r['raised'];
        $r['spent'] = (float)$r['spent'];
        $r['balance'] = $r['raised'] - $r['spent'];
        $r['target_amount'] = $r['target_amount'] === null ? null : (float)$r['target_amount'];
        $r['pct'] = $r['target_amount'] ? $r['raised'] / $r['target_amount'] * 100 : null;
    }
    return $rows;
}

function fund_get(PDO $pdo, int $fundId, int $templeId): ?array
{
    foreach (fund_list($pdo, $templeId) as $f) {
        if ((int)$f['id'] === $fundId) {
            return $f;
        }
    }
    return null;
}

/** รหัสกองทุนจากฟอร์มใช้ได้กับวัดนี้หรือไม่ (ต้องเป็นของวัดและยังใช้งานอยู่ ยกเว้นเป็นกองทุนเดิมของรายการที่กำลังแก้) @return ?int NULL = ไม่ผูกกองทุน */
function fund_resolve(PDO $pdo, $raw, int $templeId, ?int $currentFund = null): array
{
    $id = (int)$raw;
    if ($id <= 0) {
        return [null, ''];
    }
    $st = $pdo->prepare('SELECT is_active FROM funds WHERE id = ? AND temple_id = ?');
    $st->execute([$id, $templeId]);
    $row = $st->fetch();
    if (!$row || (!$row['is_active'] && $id !== $currentFund)) {
        return [null, 'กองทุน/โครงการที่เลือกไม่ถูกต้องหรือปิดใช้งานแล้ว'];
    }
    return [$id, ''];
}

/** กองทุนที่ใช้งานอยู่ของทุกวัดในขอบเขตผู้ใช้ สำหรับช่องเลือกในฟอร์มรายการ {รหัสวัด: [[รหัสกองทุน, ชื่อ], ...]} */
function fund_map(PDO $pdo): array
{
    [$sc, $sp] = scope_sql('t');
    $st = $pdo->prepare("SELECT f.id, f.temple_id, f.name FROM funds f JOIN temples t ON t.id = f.temple_id WHERE f.is_active = 1 AND t.is_active = 1 $sc ORDER BY f.name");
    $st->execute($sp);
    $map = [];
    foreach ($st->fetchAll() as $f) {
        $map[(int)$f['temple_id']][] = [(string)$f['id'], $f['name']];
    }
    return $map;
}

/** รายการของกองทุน (ทุกปี) เรียงตามวันที่ */
function fund_transactions(PDO $pdo, int $fundId): array
{
    $st = $pdo->prepare("SELECT x.id, x.txn_date, x.type, x.amount, x.description, x.doc_no, c.name AS category
        FROM transactions x JOIN categories c ON c.id = x.category_id
        WHERE x.fund_id = ? AND x.is_deleted = 0 AND x.transfer_id IS NULL ORDER BY x.txn_date, x.id");
    $st->execute([$fundId]);
    return $st->fetchAll();
}
