<?php
/** อนุโมทนาบัตร: ออกให้รายรับในหมวดรับบริจาค (categories.is_donation = 1) เลขที่รันต่อวัดต่อปี พ.ศ. */

/** แปลงเลขอารบิกเป็นเลขไทย (๐-๙) เฉพาะข้อความ — ไม่แตะแท็ก/แอตทริบิวต์/เอนทิตี (เช่น &#039;) ของ HTML ที่ส่งเข้ามา */
function thai_digits_html(string $html): string
{
    $map = ['0' => '๐', '1' => '๑', '2' => '๒', '3' => '๓', '4' => '๔', '5' => '๕', '6' => '๖', '7' => '๗', '8' => '๘', '9' => '๙'];
    return preg_replace_callback('/(<[^>]*>|&#?\w+;)|([^<&]+|&)/u', fn($m) => ($m[1] ?? '') !== '' ? $m[1] : strtr($m[2], $map), $html);
}

/** จำนวนเงินเป็นตัวอักษรไทย เช่น 1,250.50 => หนึ่งพันสองร้อยห้าสิบบาทห้าสิบสตางค์ */
function thai_baht_text(string|float $amount): string
{
    $digits = ['ศูนย์', 'หนึ่ง', 'สอง', 'สาม', 'สี่', 'ห้า', 'หก', 'เจ็ด', 'แปด', 'เก้า'];
    $places = ['', 'สิบ', 'ร้อย', 'พัน', 'หมื่น', 'แสน'];
    $int = function (string $n) use (&$int, $digits, $places): string {
        $n = ltrim($n, '0');
        if ($n === '') {
            return '';
        }
        if (strlen($n) > 6) {   // แยกล้าน
            $head = substr($n, 0, -6);
            return $int($head) . 'ล้าน' . $int(substr($n, -6));
        }
        $out = '';
        $len = strlen($n);
        for ($i = 0; $i < $len; $i++) {
            $d = (int)$n[$i];
            $pos = $len - $i - 1;
            if ($d === 0) {
                continue;
            }
            if ($pos === 1) {
                $out .= ($d === 1 ? '' : ($d === 2 ? 'ยี่' : $digits[$d])) . 'สิบ';
            } elseif ($pos === 0 && $d === 1 && $len > 1) {
                $out .= 'เอ็ด';
            } else {
                $out .= $digits[$d] . $places[$pos];
            }
        }
        return $out;
    };
    [$b, $s] = explode('.', number_format((float)$amount, 2, '.', ''));
    $text = $int($b) ?: 'ศูนย์';
    $text .= 'บาท';
    return $s === '00' ? $text . 'ถ้วน' : $text . $int($s) . 'สตางค์';
}

/** อนุโมทนาบัตรที่ยังใช้ได้ของรายการ (ถ้ามี) */
function receipt_active_for(PDO $pdo, int $txnId): ?array
{
    $st = $pdo->prepare("SELECT * FROM donation_receipts WHERE transaction_id = ? AND status = 'issued' ORDER BY id DESC LIMIT 1");
    $st->execute([$txnId]);
    return $st->fetch() ?: null;
}

function receipt_label(array $r): string
{
    return 'เล่มที่ ' . $r['book_no'] . ' เลขที่ ' . $r['receipt_no'] . '/' . $r['receipt_year'];
}

/**
 * ออกอนุโมทนาบัตร
 * @return array{0:?int,1:string} [id, ข้อผิดพลาด]
 */
function receipt_issue(PDO $pdo, int $txnId, array $in, ?int $userId): array
{
    $st = $pdo->prepare("SELECT x.*, c.name AS category FROM transactions x JOIN categories c ON c.id = x.category_id
        WHERE x.id = ? AND x.type = 'income' AND x.is_deleted = 0 AND c.is_donation = 1");
    $st->execute([$txnId]);
    $x = $st->fetch();
    if (!$x) {
        return [null, 'รายการนี้ไม่ใช่รายรับประเภทรับบริจาค จึงออกอนุโมทนาบัตรไม่ได้'];
    }
    $templeId = (int)$x['temple_id'];
    $donor = trim($in['donor_name'] ?? '');
    $addr = trim($in['donor_address'] ?? '');
    $purpose = trim($in['purpose'] ?? '');
    $book = trim($in['book_no'] ?? '');
    $rname = trim($in['receiver_name'] ?? '');
    $rpos = trim($in['receiver_position'] ?? '');
    $date = date_be_parse($in, 'receipt_date') ?? '';
    $errs = [];
    if (mb_strlen($donor) < 2 || mb_strlen($donor) > 200) $errs[] = 'กรุณากรอกชื่อผู้บริจาค (2–200 ตัวอักษร)';
    if (mb_strlen($addr) > 500) $errs[] = 'ที่อยู่ผู้บริจาคยาวเกินไป';
    if (mb_strlen($purpose) > 255) $errs[] = 'วัตถุประสงค์ยาวเกินไป';
    if (!preg_match('/^[0-9A-Za-zก-๙\-\/]{1,20}$/u', $book)) $errs[] = 'เล่มที่ใช้ได้เฉพาะตัวอักษร ตัวเลข - / ไม่เกิน 20 ตัว';
    if (mb_strlen($rname) > 200 || mb_strlen($rpos) > 100) $errs[] = 'ชื่อ/ตำแหน่งผู้รับเงินยาวเกินไป';
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) || !strtotime($date) || $date > date('Y-m-d')) $errs[] = 'วันที่ออกไม่ถูกต้อง (เลือกวัน เดือน และปี พ.ศ. ห้ามเป็นวันในอนาคต)';
    if ($errs) {
        return [null, implode(', ', $errs)];
    }
    $abbot = $pdo->prepare("SELECT title, first_name, last_name FROM temple_people WHERE temple_id = ? AND position = 'abbot' AND is_active = 1 ORDER BY id LIMIT 1");
    $abbot->execute([$templeId]);
    $a = $abbot->fetch();
    $year = (int)substr($date, 0, 4) + 543;

    $lock = 'tfms_rcpt_' . $templeId;
    $got = (int)$pdo->query('SELECT GET_LOCK(' . $pdo->quote($lock) . ', 5)')->fetchColumn();
    if (!$got) {
        return [null, 'ระบบกำลังออกเลขที่ กรุณาลองใหม่'];
    }
    try {
        if (receipt_active_for($pdo, $txnId)) {
            return [null, 'รายการนี้ออกอนุโมทนาบัตรแล้ว'];
        }
        $n = $pdo->prepare('SELECT COALESCE(MAX(receipt_no), 0) + 1 FROM donation_receipts WHERE temple_id = ? AND receipt_year = ?');
        $n->execute([$templeId, $year]);
        $no = (int)$n->fetchColumn();
        $pdo->prepare('INSERT INTO donation_receipts (temple_id, transaction_id, receipt_year, book_no, receipt_no, receipt_date, donor_name, donor_address,
            purpose, amount, receiver_name, receiver_position, abbot_name, issued_by) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)')
            ->execute([$templeId, $txnId, $year, $book, $no, $date, $donor, $addr ?: null, $purpose ?: null, $x['amount'],
                $rname ?: null, $rpos ?: null, $a ? person_full_name($a, false) : null, $userId]);
        return [(int)$pdo->lastInsertId(), ''];
    } finally {
        $pdo->query('SELECT RELEASE_LOCK(' . $pdo->quote($lock) . ')');
    }
}
