<?php
/**
 * นำเข้ารายการจากไฟล์ statement ธนาคาร (CSV หรือ Excel .xlsx) เข้าบัญชีธนาคารของวัด
 * ขั้นตอน: อ่านไฟล์ → เดาคอลัมน์จากหัวตาราง (แก้ได้) → ดูตัวอย่าง จัดหมวด/ตัดรายการซ้ำ → นำเข้า
 * รองรับวันที่ ค.ศ./พ.ศ. หลายรูปแบบ (รวมเลขวันที่ของ Excel และเดือนภาษาไทย) ช่องฝาก-ถอนแยกกันหรือช่องจำนวนเงินแบบมีเครื่องหมายช่องเดียว
 * ไม่ต้องใช้ migration และไม่ต้องใช้ไลบรารีเพิ่ม
 */

const BANK_IMPORT_MAX_BYTES = 5 * 1024 * 1024;
const BANK_IMPORT_MAX_ROWS = 3000;

/** คำที่ใช้เดาหัวคอลัมน์ (ตัดช่องว่าง/ตัวพิมพ์เล็ก) */
const BANK_COLUMN_HINTS = [
    'date' => ['วันที่', 'วันที่ทำรายการ', 'วัน/เดือน/ปี', 'วันเดือนปี', 'date', 'transactiondate', 'txndate', 'postingdate', 'valuedate'],
    'desc' => ['รายการ', 'รายละเอียด', 'รายละเอียดรายการ', 'ประเภทรายการ', 'description', 'details', 'detail', 'particulars', 'transaction', 'narrative', 'memo', 'หมายเหตุ'],
    'debit' => ['ถอน', 'ถอนเงิน', 'จำนวนเงินถอน', 'จ่าย', 'เงินออก', 'เดบิต', 'debit', 'withdrawal', 'withdraw', 'withdrawals', 'paidout', 'payment', 'dr'],
    'credit' => ['ฝาก', 'ฝากเงิน', 'จำนวนเงินฝาก', 'รับ', 'เงินเข้า', 'เครดิต', 'credit', 'deposit', 'deposits', 'paidin', 'receipt', 'cr'],
    'amount' => ['จำนวนเงิน', 'จำนวน', 'amount', 'amt', 'ยอดเงิน'],
    'balance' => ['ยอดคงเหลือ', 'คงเหลือ', 'ยอดเงินคงเหลือ', 'balance', 'runningbalance'],
    'ref' => ['เลขที่อ้างอิง', 'อ้างอิง', 'เลขที่เอกสาร', 'ref', 'reference', 'refno', 'chequeno', 'เช็คเลขที่'],
];

/** อ่านไฟล์เป็นแถวของข้อความ @return array{0:?array,1:string} [แถว, ข้อผิดพลาด] */
function bank_import_read(string $path, string $filename): array
{
    if (!is_file($path) || filesize($path) > BANK_IMPORT_MAX_BYTES) {
        return [null, 'ไฟล์ใหญ่เกินไป (ไม่เกิน 5 MB)'];
    }
    $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
    try {
        if ($ext === 'xlsx') {
            $rows = xlsx_read_rows($path);
        } elseif (in_array($ext, ['csv', 'txt'], true)) {
            $rows = bank_import_read_csv($path);
        } else {
            return [null, 'รองรับเฉพาะไฟล์ .csv หรือ .xlsx (ไฟล์ .xls เก่าให้บันทึกเป็น .xlsx หรือ CSV ก่อน)'];
        }
    } catch (RuntimeException $e) {
        return [null, $e->getMessage()];
    }
    $rows = array_values(array_filter(array_map(fn($r) => array_map(fn($c) => trim((string)$c), $r), $rows), fn($r) => implode('', $r) !== ''));
    if (count($rows) < 2) {
        return [null, 'ไม่พบข้อมูลในไฟล์ (ต้องมีหัวตารางและอย่างน้อย 1 รายการ)'];
    }
    if (count($rows) > BANK_IMPORT_MAX_ROWS + 40) {
        return [null, 'ไฟล์มีรายการมากเกินไป (ไม่เกิน ' . number_format(BANK_IMPORT_MAX_ROWS) . ' แถว) แบ่งไฟล์เป็นช่วง ๆ แล้วนำเข้าทีละไฟล์'];
    }
    return [$rows, ''];
}

function bank_import_read_csv(string $path): array
{
    $raw = (string)file_get_contents($path);
    if (str_starts_with($raw, "\xEF\xBB\xBF")) {
        $raw = substr($raw, 3);
    } elseif (!mb_check_encoding($raw, 'UTF-8')) {
        // ไฟล์จากโปรแกรมธนาคารเก่ามักเป็น TIS-620/Windows-874
        $conv = function_exists('iconv') ? @iconv('CP874', 'UTF-8//IGNORE', $raw) : false;
        $raw = $conv !== false && $conv !== '' ? $conv : (string)mb_convert_encoding($raw, 'UTF-8', 'ISO-8859-11');
    }
    $first = strtok($raw, "\n") ?: '';
    $delim = ',';
    foreach (["\t", ';'] as $d) {
        if (substr_count($first, $d) > substr_count($first, $delim)) {
            $delim = $d;
        }
    }
    $h = fopen('php://temp', 'r+');
    fwrite($h, $raw);
    rewind($h);
    $rows = [];
    while (($r = fgetcsv($h, 0, $delim, '"', '')) !== false) {
        $rows[] = $r;
        if (count($rows) > BANK_IMPORT_MAX_ROWS + 60) {
            break;
        }
    }
    fclose($h);
    return $rows;
}

function bank_import_norm(string $s): string
{
    return mb_strtolower(preg_replace('/[\s\.\(\)\[\]_:\-]+/u', '', $s));
}

/**
 * เดาแถวหัวตารางและคอลัมน์ของแต่ละช่อง
 * @return array{header:int,map:array<string,?int>} header = ลำดับแถวหัวตาราง (-1 = ไม่พบ)
 */
function bank_import_detect(array $rows): array
{
    $best = ['score' => 0, 'header' => -1, 'map' => []];
    foreach (array_slice($rows, 0, 40, true) as $i => $r) {
        $map = [];
        foreach ($r as $ci => $cell) {
            $n = bank_import_norm((string)$cell);
            if ($n === '') continue;
            foreach (BANK_COLUMN_HINTS as $field => $hints) {
                if (!isset($map[$field]) && in_array($n, array_map('bank_import_norm', $hints), true)) {
                    $map[$field] = $ci;
                }
            }
        }
        $score = (isset($map['date']) ? 3 : 0) + (isset($map['debit']) || isset($map['credit']) ? 3 : 0) + (isset($map['amount']) ? 2 : 0) + count($map);
        if (isset($map['date']) && (isset($map['debit']) || isset($map['credit']) || isset($map['amount'])) && $score > $best['score']) {
            $best = ['score' => $score, 'header' => $i, 'map' => $map];
        }
    }
    if ($best['header'] < 0) {
        return ['header' => -1, 'map' => []];
    }
    // ช่อง "จำนวนเงิน" ที่ซ้ำกับฝาก/ถอนแยกช่องแล้ว ไม่ต้องใช้
    if (isset($best['map']['amount']) && isset($best['map']['debit']) && isset($best['map']['credit'])) {
        unset($best['map']['amount']);
    }
    return ['header' => $best['header'], 'map' => $best['map']];
}

const BANK_THAI_MONTHS = ['ม.ค.' => 1, 'ก.พ.' => 2, 'มี.ค.' => 3, 'เม.ย.' => 4, 'พ.ค.' => 5, 'มิ.ย.' => 6, 'ก.ค.' => 7, 'ส.ค.' => 8, 'ก.ย.' => 9, 'ต.ค.' => 10, 'พ.ย.' => 11, 'ธ.ค.' => 12,
    'มกราคม' => 1, 'กุมภาพันธ์' => 2, 'มีนาคม' => 3, 'เมษายน' => 4, 'พฤษภาคม' => 5, 'มิถุนายน' => 6, 'กรกฎาคม' => 7, 'สิงหาคม' => 8, 'กันยายน' => 9, 'ตุลาคม' => 10, 'พฤศจิกายน' => 11, 'ธันวาคม' => 12,
    'jan' => 1, 'feb' => 2, 'mar' => 3, 'apr' => 4, 'may' => 5, 'jun' => 6, 'jul' => 7, 'aug' => 8, 'sep' => 9, 'oct' => 10, 'nov' => 11, 'dec' => 12];

/** แปลงปี: พ.ศ. (>2400) → ค.ศ. · 2 หลัก 50–99 = พ.ศ. 25xx · 00–49 = ค.ศ. 20xx */
function bank_import_year(int $y): int
{
    if ($y < 100) {
        return $y >= 50 ? 2500 + $y - 543 : 2000 + $y;
    }
    return $y > 2400 ? $y - 543 : $y;
}

/** แปลงข้อความวันที่เป็น Y-m-d (ค.ศ.) หรือ null */
function bank_import_parse_date(string $s): ?string
{
    $s = trim($s);
    if ($s === '') return null;
    if (preg_match('/^\d{4,6}(\.\d+)?$/', $s) && (float)$s > 20000 && (float)$s < 80000) {   // เลขวันที่ของ Excel
        return gmdate('Y-m-d', (int)(((float)$s - 25569) * 86400));
    }
    $s = preg_replace('/[T\s]+\d{1,2}:\d{2}(:\d{2})?.*$/u', '', $s);      // ตัดเวลา
    $d = $m = $y = null;
    if (preg_match('/^(\d{4})[\-\/.](\d{1,2})[\-\/.](\d{1,2})$/', $s, $x)) {
        [$y, $m, $d] = [(int)$x[1], (int)$x[2], (int)$x[3]];
    } elseif (preg_match('/^(\d{1,2})[\-\/.](\d{1,2})[\-\/.](\d{2,4})$/', $s, $x)) {
        [$d, $m, $y] = [(int)$x[1], (int)$x[2], (int)$x[3]];
    } elseif (preg_match('/^(\d{1,2})\s*([ก-๙A-Za-z.]+)\s*(\d{2,4})$/u', $s, $x)) {
        $tok = mb_strtolower($x[2]);
        $mm = BANK_THAI_MONTHS[$tok] ?? BANK_THAI_MONTHS[rtrim($tok, '.') . '.'] ?? BANK_THAI_MONTHS[rtrim($tok, '.')]
            ?? (preg_match('/^[a-z]+$/', $tok) ? (BANK_THAI_MONTHS[substr($tok, 0, 3)] ?? null) : null);
        if ($mm === null) return null;
        [$d, $m, $y] = [(int)$x[1], $mm, (int)$x[3]];
    } else {
        return null;
    }
    $y = bank_import_year($y);
    return ($y >= 1990 && $y <= 2100 && checkdate($m, $d, $y)) ? sprintf('%04d-%02d-%02d', $y, $m, $d) : null;
}

/** แปลงข้อความจำนวนเงิน: 1,234.50 · (1,234.50) · -1,234.50 · 1,234.50- · "฿ 100" → ทศนิยม · ว่าง/ไม่ใช่ตัวเลข = null */
function bank_import_parse_amount(string $s): ?float
{
    $s = trim(str_replace(['฿', 'บาท', ' ', "\xC2\xA0"], '', $s));
    if ($s === '' || $s === '-') return null;
    $neg = false;
    if (preg_match('/^\((.*)\)$/', $s, $x)) {
        $neg = true;
        $s = $x[1];
    }
    if (str_ends_with($s, '-')) {
        $neg = true;
        $s = substr($s, 0, -1);
    }
    if (str_starts_with($s, '-')) {
        $neg = !$neg;
        $s = substr($s, 1);
    }
    $s = str_replace(',', '', $s);
    if (!preg_match('/^\d+(\.\d+)?$/', $s)) return null;
    $v = round((float)$s, 2);
    return $neg ? -$v : $v;
}

/**
 * แปลงแถวข้อมูลเป็นรายการตามแมปคอลัมน์
 * @param array<string,?int> $map date, desc, debit, credit, amount, ref, balance
 * @return array<int,array{line:int,date:?string,desc:string,ref:string,amount:float,error:string}> amount: บวก=เงินเข้า ลบ=เงินออก
 */
function bank_import_rows(array $rows, array $map, int $header): array
{
    $out = [];
    $cell = fn(array $r, string $f) => isset($map[$f]) && $map[$f] !== null ? (string)($r[$map[$f]] ?? '') : '';
    foreach ($rows as $i => $r) {
        if ($i <= $header) continue;
        if (count($out) >= BANK_IMPORT_MAX_ROWS) break;
        $dateTxt = $cell($r, 'date');
        $date = bank_import_parse_date($dateTxt);
        $debit = bank_import_parse_amount($cell($r, 'debit'));
        $credit = bank_import_parse_amount($cell($r, 'credit'));
        $amt = bank_import_parse_amount($cell($r, 'amount'));
        $err = '';
        $amount = 0.0;
        if ($debit !== null && abs($debit) > 0 && $credit !== null && abs($credit) > 0) {
            $err = 'มีทั้งยอดฝากและถอนในแถวเดียว';
        } elseif ($debit !== null && abs($debit) > 0) {
            $amount = -abs($debit);
        } elseif ($credit !== null && abs($credit) > 0) {
            $amount = abs($credit);
        } elseif ($amt !== null && $amt != 0.0) {
            $amount = $amt;
        } elseif ($dateTxt === '' && implode('', $r) === '') {
            continue;
        }
        if ($date === null && $dateTxt === '' && $amount == 0.0 && $err === '') {
            continue;   // แถวว่าง
        }
        if ($date === null && preg_match('/รวม|ยกมา|ยกไป|สรุป|total|forward|brought|carried|opening|closing/iu', $dateTxt . ' ' . $cell($r, 'desc'))) {
            continue;   // แถวสรุปยอด/ยอดยกมา ไม่ใช่รายการ
        }
        if ($err === '' && $date === null) $err = 'วันที่อ่านไม่ได้ (' . mb_substr($dateTxt, 0, 20) . ')';
        elseif ($err === '' && $amount == 0.0) $err = 'ไม่มีจำนวนเงิน';
        elseif ($err === '' && abs($amount) > 999999999999) $err = 'จำนวนเงินสูงเกินไป';
        $out[] = ['line' => $i + 1, 'date' => $date, 'desc' => mb_substr($cell($r, 'desc'), 0, 255), 'ref' => mb_substr($cell($r, 'ref'), 0, 50), 'amount' => $amount, 'error' => $err];
    }
    return $out;
}

/**
 * เตรียมรายการสำหรับดูตัวอย่าง: หาหมวดที่เคยใช้กับคำอธิบายเดียวกัน และทำเครื่องหมายรายการที่มีอยู่แล้วในระบบ
 * @param array<int,array> $items
 * @return array<int,array> เพิ่มคีย์ type, suggest (category_id), dup (bool), locked (ข้อความ)
 */
function bank_import_prepare(PDO $pdo, int $templeId, array $account, array $items): array
{
    $seen = [];       // จำนวนรายการเดียวกันในไฟล์ (กันนับซ้ำกับของในระบบ)
    $sugg = $pdo->prepare('SELECT category_id FROM transactions WHERE temple_id = ? AND account_id = ? AND type = ? AND description = ? AND is_deleted = 0 AND transfer_id IS NULL ORDER BY id DESC LIMIT 1');
    $cnt = $pdo->prepare('SELECT COUNT(*) FROM transactions WHERE temple_id = ? AND account_id = ? AND type = ? AND txn_date = ? AND amount = ? AND is_deleted = 0');
    foreach ($items as $k => &$it) {
        $it['type'] = $it['amount'] >= 0 ? 'income' : 'expense';
        $it['suggest'] = null;
        $it['dup'] = false;
        $it['locked'] = '';
        if ($it['error'] !== '') continue;
        if ($it['desc'] !== '') {
            $sugg->execute([$templeId, (int)$account['id'], $it['type'], $it['desc']]);
            $it['suggest'] = ($c = $sugg->fetchColumn()) ? (int)$c : null;
        }
        $key = $it['date'] . '|' . $it['type'] . '|' . number_format(abs($it['amount']), 2, '.', '');
        $seen[$key] = ($seen[$key] ?? 0) + 1;
        $cnt->execute([$templeId, (int)$account['id'], $it['type'], $it['date'], abs($it['amount'])]);
        $it['dup'] = (int)$cnt->fetchColumn() >= $seen[$key];
        $it['locked'] = period_lock_error($pdo, $templeId, $it['date']);
    }
    unset($it);
    return $items;
}

/**
 * นำเข้ารายการที่เลือก
 * @param array<int,array> $items รายการที่แยกวิเคราะห์ไว้ (ดัชนีตรงกับที่แสดง)
 * @param array<int,array{include?:string,category?:string,desc?:string}> $sel ค่าที่ผู้ใช้เลือกตามดัชนี
 * @return array{done:int,skipped:array<int,string>}
 */
function bank_import_apply(PDO $pdo, int $templeId, array $account, array $items, array $sel, ?int $userId): array
{
    $cats = ['income' => [], 'expense' => []];
    foreach ($pdo->query('SELECT id, type FROM categories WHERE is_active = 1 AND is_transfer = 0')->fetchAll() as $c) {
        $cats[$c['type']][(int)$c['id']] = true;
    }
    $prepared = bank_import_prepare($pdo, $templeId, $account, $items);
    $ins = $pdo->prepare('INSERT INTO transactions (temple_id, type, category_id, txn_date, doc_no, description, amount, account_id, created_by) VALUES (?,?,?,?,?,?,?,?,?)');
    $done = 0;
    $skipped = [];
    $pdo->beginTransaction();
    try {
        foreach ($prepared as $i => $it) {
            $s = $sel[$i] ?? [];
            if (empty($s['include'])) continue;
            $label = 'แถว ' . $it['line'];
            if ($it['error'] !== '') { $skipped[] = "$label: {$it['error']}"; continue; }
            if ($it['locked'] !== '') { $skipped[] = "$label: เดือนที่ปิดงวดแล้ว"; continue; }
            $cat = (int)($s['category'] ?? 0);
            if (!isset($cats[$it['type']][$cat])) { $skipped[] = "$label: ยังไม่ได้เลือกหมวด" . ($it['type'] === 'income' ? 'รายรับ' : 'รายจ่าย'); continue; }
            $desc = isset($s['desc']) ? mb_substr(trim((string)$s['desc']), 0, 255) : $it['desc'];
            $ins->execute([$templeId, $it['type'], $cat, $it['date'], $it['ref'] ?: null, $desc ?: null, abs($it['amount']), (int)$account['id'], $userId]);
            $done++;
        }
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
    audit('import_bank', 'account', (int)$account['id'], "done $done skipped " . count($skipped));
    return ['done' => $done, 'skipped' => $skipped];
}
