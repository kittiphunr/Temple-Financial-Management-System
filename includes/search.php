<?php
/**
 * ค้นหารายการรายรับ-รายจ่ายขั้นสูง (search.php): ข้อความ ประเภท ช่วงวันที่ ช่วงจำนวนเงิน วัด/อำเภอ หมวด บัญชี กองทุน และมีไฟล์แนบหรือไม่
 * ขอบเขตข้อมูลตามบทบาทเสมอ (scope_sql) · ไม่นับรายการที่ลบแล้วและรายการโอน/ฝาก-ถอน
 * ชุดกรองที่บันทึกไว้ (ตาราง saved_searches ต้องรัน migration 2026_saved_searches.sql) เก็บต่อผู้ใช้ สูงสุด 20 ชุด
 */

const SEARCH_PAGE_SIZE = 100;
const SEARCH_EXPORT_MAX = 20000;
const SEARCH_SAVED_MAX = 20;
const SEARCH_SORTS = [
    'date_desc' => ['วันที่ใหม่ → เก่า', 'x.txn_date DESC, x.id DESC'],
    'date_asc' => ['วันที่เก่า → ใหม่', 'x.txn_date ASC, x.id ASC'],
    'amount_desc' => ['จำนวนเงินมาก → น้อย', 'x.amount DESC, x.id DESC'],
    'amount_asc' => ['จำนวนเงินน้อย → มาก', 'x.amount ASC, x.id ASC'],
];

function search_saved_available(): bool
{
    static $ok = null;
    if ($ok === null) {
        try {
            db()->query('SELECT 1 FROM saved_searches LIMIT 1');
            $ok = true;
        } catch (PDOException) {
            $ok = false;
        }
    }
    return $ok;
}

/** วันที่แบบ พ.ศ. จากช่องเลือก (ว่างทั้งหมด = ไม่กำหนด) @return array{0:?string,1:string} [Y-m-d หรือ null, ข้อผิดพลาด] */
function search_date(array $in, string $name, string $label): array
{
    $parts = array_map(fn($s) => trim((string)($in[$name . '_' . $s] ?? '')), ['d', 'm', 'y']);
    if ($parts === ['', '', '']) {
        return [null, ''];
    }
    $d = date_be_parse($in, $name);
    return $d === null ? [null, "วันที่$label ไม่ถูกต้อง (เลือกวัน เดือน และปี พ.ศ. ให้ครบ หรือเว้นว่างทั้งหมด)"] : [$d, ''];
}

function search_num(mixed $v): ?float
{
    $v = str_replace(',', '', trim((string)$v));
    return $v !== '' && is_numeric($v) && (float)$v >= 0 ? round((float)$v, 2) : null;
}

/**
 * ตรวจและทำเงื่อนไขกรองให้เป็นรูปมาตรฐานจากพารามิเตอร์ (GET/ชุดที่บันทึก)
 * @return array{0:array,1:string[]} [เงื่อนไข, ข้อผิดพลาด]
 */
function search_filters(array $in): array
{
    $errs = [];
    $f = [
        'q' => mb_substr(trim((string)($in['q'] ?? '')), 0, 100),
        'type' => in_array($in['type'] ?? 'all', ['income', 'expense'], true) ? $in['type'] : 'all',
        'min' => search_num($in['min'] ?? ''),
        'max' => search_num($in['max'] ?? ''),
        'district' => max(0, (int)($in['district'] ?? 0)),
        'temple' => max(0, (int)($in['temple'] ?? 0)),
        'category' => max(0, (int)($in['category'] ?? 0)),
        'account' => in_array($in['account'] ?? 'all', ['cash', 'bank'], true) ? $in['account'] : 'all',
        'fund' => ($in['fund'] ?? 'all') === 'none' ? 'none' : (ctype_digit((string)($in['fund'] ?? '')) && (int)$in['fund'] > 0 ? (string)(int)$in['fund'] : 'all'),
        'att' => in_array($in['att'] ?? 'all', ['yes', 'no'], true) ? $in['att'] : 'all',
        'sort' => isset(SEARCH_SORTS[$in['sort'] ?? '']) ? $in['sort'] : 'date_desc',
        'page' => max(1, (int)($in['page'] ?? 1)),
    ];
    [$f['from'], $e1] = search_date($in, 'from', 'เริ่มต้น');
    [$f['to'], $e2] = search_date($in, 'to', 'สิ้นสุด');
    $e1 !== '' && $errs[] = $e1;
    $e2 !== '' && $errs[] = $e2;
    if ($f['from'] && $f['to'] && $f['from'] > $f['to']) {
        $errs[] = 'วันที่เริ่มต้นต้องไม่หลังวันที่สิ้นสุด';
    }
    if ($f['min'] !== null && $f['max'] !== null && $f['min'] > $f['max']) {
        $errs[] = 'จำนวนเงินต่ำสุดต้องไม่มากกว่าสูงสุด';
    }
    return [$f, $errs];
}

/** พารามิเตอร์มาตรฐานของเงื่อนไข (ใช้สร้างลิงก์/บันทึกชุดกรอง) ไม่รวมหน้า และไม่รวมค่าเริ่มต้น (ทั้งหมด/เรียงวันที่ใหม่→เก่า) */
function search_params(array $f): array
{
    $p = ['q' => $f['q'], 'type' => $f['type'], 'min' => $f['min'] ?? '', 'max' => $f['max'] ?? '', 'district' => $f['district'] ?: '', 'temple' => $f['temple'] ?: '',
        'category' => $f['category'] ?: '', 'account' => $f['account'], 'fund' => $f['fund'], 'att' => $f['att'], 'sort' => $f['sort'] === 'date_desc' ? '' : $f['sort']];
    foreach (['from', 'to'] as $k) {
        if ($f[$k]) {
            [$y, $m, $d] = array_map('intval', explode('-', $f[$k]));
            $p[$k . '_d'] = $d;
            $p[$k . '_m'] = $m;
            $p[$k . '_y'] = $y + 543;
        }
    }
    return array_filter($p, fn($v) => $v !== '' && $v !== 'all' && $v !== null);
}

/** @return array{0:string,1:array} [FROM … WHERE …, พารามิเตอร์] */
function search_from_where(array $f): array
{
    [$sc, $sp] = scope_sql('t');
    $sql = ' FROM transactions x JOIN temples t ON t.id = x.temple_id JOIN districts d ON d.id = t.district_id JOIN categories c ON c.id = x.category_id
        LEFT JOIN accounts a ON a.id = x.account_id WHERE x.is_deleted = 0 AND x.transfer_id IS NULL AND t.is_active = 1' . $sc;
    $p = $sp;
    if ($f['q'] !== '') {
        $like = '%' . addcslashes($f['q'], '\\%_') . '%';
        $cond = 'x.description LIKE ? OR x.doc_no LIKE ?';
        $p[] = $like;
        $p[] = $like;
        $num = search_num($f['q']);
        if ($num !== null) {
            $cond .= ' OR x.amount = ?';
            $p[] = $num;
        }
        $sql .= " AND ($cond)";
    }
    if ($f['type'] !== 'all') { $sql .= ' AND x.type = ?'; $p[] = $f['type']; }
    if ($f['from']) { $sql .= ' AND x.txn_date >= ?'; $p[] = $f['from']; }
    if ($f['to']) { $sql .= ' AND x.txn_date <= ?'; $p[] = $f['to']; }
    if ($f['min'] !== null) { $sql .= ' AND x.amount >= ?'; $p[] = $f['min']; }
    if ($f['max'] !== null) { $sql .= ' AND x.amount <= ?'; $p[] = $f['max']; }
    if ($f['district']) { $sql .= ' AND t.district_id = ?'; $p[] = $f['district']; }
    if ($f['temple']) { $sql .= ' AND t.id = ?'; $p[] = $f['temple']; }
    if ($f['category']) { $sql .= ' AND x.category_id = ?'; $p[] = $f['category']; }
    if ($f['account'] === 'cash') { $sql .= " AND (x.account_id IS NULL OR a.kind = 'cash')"; }
    if ($f['account'] === 'bank') { $sql .= " AND a.kind = 'bank'"; }
    if (fund_available()) {
        if ($f['fund'] === 'none') { $sql .= ' AND x.fund_id IS NULL'; }
        elseif ($f['fund'] !== 'all') { $sql .= ' AND x.fund_id = ?'; $p[] = (int)$f['fund']; }
    }
    if (attachments_available() && $f['att'] !== 'all') {
        $sql .= ($f['att'] === 'yes' ? ' AND' : ' AND NOT') . ' EXISTS (SELECT 1 FROM transaction_attachments ta WHERE ta.transaction_id = x.id)';
    }
    return [$sql, $p];
}

/** @return array{n:int,income:float,expense:float} */
function search_totals(PDO $pdo, array $f): array
{
    [$from, $p] = search_from_where($f);
    $st = $pdo->prepare("SELECT COUNT(*) n, COALESCE(SUM(CASE WHEN x.type='income' THEN x.amount END),0) i, COALESCE(SUM(CASE WHEN x.type='expense' THEN x.amount END),0) e" . $from);
    $st->execute($p);
    $r = $st->fetch();
    return ['n' => (int)$r['n'], 'income' => (float)$r['i'], 'expense' => (float)$r['e']];
}

function search_select(): string
{
    return "SELECT x.id, x.temple_id, x.type, x.txn_date, x.doc_no, x.description, x.amount, t.name AS temple, d.name AS district, c.name AS category, a.kind AS acc_kind, a.bank_name AS acc_bank, a.account_no AS acc_no,
        " . (fund_available() ? '(SELECT fu.name FROM funds fu WHERE fu.id = x.fund_id)' : 'NULL') . " AS fund_name,
        " . (attachments_available() ? '(SELECT COUNT(*) FROM transaction_attachments ta WHERE ta.transaction_id = x.id)' : '0') . ' AS att_n';
}

function search_rows(PDO $pdo, array $f, int $limit, int $offset): array
{
    [$from, $p] = search_from_where($f);
    $st = $pdo->prepare(search_select() . $from . ' ORDER BY ' . SEARCH_SORTS[$f['sort']][1] . ' LIMIT ' . (int)$limit . ' OFFSET ' . (int)$offset);
    $st->execute($p);
    return $st->fetchAll();
}

// ---- ชุดกรองที่บันทึกไว้ ----
function search_saved_list(PDO $pdo, int $userId): array
{
    $st = $pdo->prepare('SELECT id, name, params FROM saved_searches WHERE user_id = ? ORDER BY name, id');
    $st->execute([$userId]);
    return array_map(fn($r) => ['id' => (int)$r['id'], 'name' => $r['name'], 'params' => (array)json_decode($r['params'], true)], $st->fetchAll());
}

/** @return array{0:bool,1:string} */
function search_saved_add(PDO $pdo, int $userId, string $name, array $params): array
{
    $name = mb_substr(trim($name), 0, 60);
    if (mb_strlen($name) < 2) {
        return [false, 'ตั้งชื่อชุดกรองอย่างน้อย 2 ตัวอักษร'];
    }
    if (!$params) {
        return [false, 'ยังไม่ได้กรองอะไร จึงไม่มีอะไรให้บันทึก'];
    }
    $n = $pdo->prepare('SELECT COUNT(*) FROM saved_searches WHERE user_id = ?');
    $n->execute([$userId]);
    if ((int)$n->fetchColumn() >= SEARCH_SAVED_MAX) {
        return [false, 'บันทึกชุดกรองได้สูงสุด ' . SEARCH_SAVED_MAX . ' ชุด กรุณาลบชุดเก่าก่อน'];
    }
    $pdo->prepare('INSERT INTO saved_searches (user_id, name, params, created_at) VALUES (?,?,?,NOW())')->execute([$userId, $name, json_encode($params, JSON_UNESCAPED_UNICODE)]);
    return [true, 'บันทึกชุดกรอง "' . $name . '" แล้ว'];
}

function search_saved_delete(PDO $pdo, int $userId, int $id): bool
{
    $st = $pdo->prepare('DELETE FROM saved_searches WHERE id = ? AND user_id = ?');
    $st->execute([$id, $userId]);
    return $st->rowCount() > 0;
}
