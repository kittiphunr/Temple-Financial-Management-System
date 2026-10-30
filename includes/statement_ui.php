<?php
/** แบบฟอร์มรายงานประจำเดือนของวัด: แบบที่ 1 เงินสด / แบบที่ 2 เงินฝากธนาคาร / แบบที่ 3 รายงานเงินคงเหลือ (ตามแบบตัวอย่างของคณะสงฆ์) */

const TH_MONTHS_SHORT = ['', 'ม.ค.', 'ก.พ.', 'มี.ค.', 'เม.ย.', 'พ.ค.', 'มิ.ย.', 'ก.ค.', 'ส.ค.', 'ก.ย.', 'ต.ค.', 'พ.ย.', 'ธ.ค.'];
const STMT_MIN_ROWS = 8;

function stmt_dots(string $v, string $cls = ''): string
{
    return '<span class="dots ' . $cls . '">' . ($v !== '' ? e($v) : '&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;') . '</span>';
}

function stmt_amount(float $v, bool $dashZero = false): string
{
    if ($dashZero && abs($v) < 0.005) return '–';
    return ($v < 0 ? '–&nbsp;' : '') . number_format(abs($v), abs($v - round($v)) < 0.005 ? 0 : 2);
}

/** ข้อมูลวัดสำหรับหัวแบบฟอร์ม + ชื่อผู้ลงนาม */
function stmt_context(PDO $pdo, int $templeId): array
{
    global $CONFIG;
    $t = temple_row($templeId);
    $d = $pdo->prepare('SELECT name FROM districts WHERE id = ?');
    $d->execute([$t['district_id']]);
    $who = function (string $pos) use ($pdo, $templeId): string {
        $st = $pdo->prepare('SELECT title, first_name, last_name FROM temple_people WHERE temple_id = ? AND position = ? AND is_active = 1 ORDER BY id LIMIT 1');
        $st->execute([$templeId, $pos]);
        $p = $st->fetch();
        return $p ? trim(trim((string)$p['title']) . $p['first_name'] . ' ' . $p['last_name']) : '';   // นาย/พระครู ติดชื่อ ตามแบบเอกสาร
    };
    return ['t' => $t, 'district' => (string)$d->fetchColumn(), 'province' => $CONFIG['province_name'] ?? 'แพร่', 'steward' => $who('steward'), 'abbot' => $who('abbot')];
}

function stmt_place(array $c): string
{
    return '<p><b>วัด</b>' . stmt_dots($c['t']['name']) . ' <b>ตำบล</b>' . stmt_dots((string)$c['t']['subdistrict'])
        . ' <b>อำเภอ</b>' . stmt_dots($c['district']) . ' <b>จังหวัด</b>' . stmt_dots($c['province']) . '</p>';
}

function stmt_logo(array $c): string
{
    $src = $c['t']['logo_media_id'] ? media_url((int)$c['t']['logo_media_id']) : BASE . 'assets/img/logo.svg';
    return '<img class="stmt-logo" src="' . e($src) . '" alt="">';
}

function stmt_signatures(array $c, bool $balanceForm): string
{
    $name = fn(string $n) => '(' . ($n !== '' ? e($n) : str_repeat('&hellip;', 12)) . ')';
    $dots = '<span class="dots wide">&nbsp;</span>';
    $abbotRole = 'เจ้าอาวาส' . e($c['t']['name']);
    if ($balanceForm) {
        return '<div class="stmt-sign stmt-sign-center"><div>ลงชื่อ' . $dots . ' ผู้จัดทำบัญชี</div><div>' . $name($c['steward']) . '</div><div>ไวยาวัจกร / ผู้ที่ได้รับมอบหมาย</div>'
            . '<div class="mt-8">ลงชื่อ' . $dots . ' ผู้รับรอง</div><div>' . $name($c['abbot']) . '</div><div>' . $abbotRole . '</div></div>';
    }
    return '<div class="stmt-sign stmt-sign-two"><div><div>ลงชื่อ ' . $dots . ' ผู้จัดทำบัญชี</div><div>' . $name($c['steward']) . '</div><div>ไวยาวัจกร / ผู้ที่ได้รับมอบหมาย</div></div>'
        . '<div><div>ตรวจถูกต้องแล้ว</div><div class="mt-2">ลงชื่อ ' . $dots . '</div><div>' . $name($c['abbot']) . '</div><div>' . $abbotRole . '</div></div></div>';
}

/**
 * แบบที่ 1 (บัญชีเงินสด) หรือแบบที่ 2 (บัญชีธนาคาร) ประจำเดือน: 1 ตาราง = 1 บัญชี
 * @param int $seq ลำดับบัญชีธนาคาร (แสดง "N. ชื่อบัญชี...")
 */
function stmt_account_sheet(PDO $pdo, array $c, array $a, int $ceYear, int $month, int $seq = 1): string
{
    $m = ledger_month($pdo, $a, $ceYear, $month);
    $be = $ceYear + 543;
    $mName = TH_MONTHS[$month];
    $mShort = TH_MONTHS_SHORT[$month];
    $last = (int)date('t', strtotime(sprintf('%04d-%02d-01', $ceYear, $month)));
    $isBank = $a['kind'] === 'bank';

    $h = '<section class="sheet sheet-land stmt-sheet">' . stmt_logo($c)
        . '<div class="stmt-form-no">' . ($isBank ? 'แบบที่ 2 เงินฝากธนาคาร' : 'แบบที่ 1 เงินสด') . '</div>'
        . '<div class="stmt-head"><p><b>บัญชีรายรับ – บัญชีรายจ่าย (' . ($isBank ? 'เงินฝากธนาคาร' : 'เงินสด') . ')</b></p>' . stmt_place($c)
        . '<p><b>ประจำเดือน</b>' . stmt_dots($mName) . ' <b>พ.ศ.</b> ' . stmt_dots((string)$be) . '</p>'
        . '<p>(<b>ตั้งแต่วันที่</b>' . stmt_dots('1') . ' <b>เดือน</b>' . stmt_dots($mName) . ' <b>พ.ศ.</b> ' . stmt_dots((string)$be)
        . ' <b>ถึงวันที่</b>' . stmt_dots((string)$last) . ' <b>เดือน</b>' . stmt_dots($mName) . ' <b>พ.ศ.</b> ' . stmt_dots((string)$be) . ')</p>';
    if ($isBank) {
        $h .= '<p><b>' . $seq . '. ชื่อบัญชี</b>' . stmt_dots($a['name']) . ' <b>ธนาคาร</b>' . stmt_dots((string)$a['bank_name'])
            . ' <b>สาขา</b> ' . stmt_dots((string)$a['branch']) . ' <b>เลขที่บัญชี</b>' . stmt_dots((string)$a['account_no']) . '</p>';
    }
    $h .= '</div>';
    if ($m['before_open']) {
        $h .= '<p class="mb-2 rounded border border-amber-300 bg-amber-50 px-3 py-1 text-sm text-amber-950 print:hidden">เดือนนี้อยู่ก่อนวันเริ่มบัญชี (' . e(th_date($a['opening_date'])) . ') — ยอดยกมาจึงเป็น 0 และไม่นับรายการก่อนวันเริ่มบัญชี</p>';
    }

    if ($m['closing'] < -0.004) {
        $h .= '<p class="mb-2 rounded border border-red-300 bg-red-50 px-3 py-1 text-sm text-red-900 print:hidden">ยอดคงเหลือติดลบ — ตรวจสอบยอดยกมาของบัญชีนี้ หรือรายการที่บันทึก (เช่น ลืมบันทึกการนำฝาก/ถอนระหว่างบัญชี)</p>';
    }
    $h .= '<div class="overflow-x-auto"><table class="stmt"><colgroup><col class="w-12"><col class="w-12"><col><col class="w-20"><col class="w-24"><col class="w-12"><col class="w-12"><col><col class="w-20"><col class="w-24"><col class="w-28"></colgroup>'
        . '<thead><tr><th colspan="2">พ.ศ. ' . $be . '</th><th rowspan="2">รายการรับ</th><th rowspan="2">ใบเสร็จ<br>เลขที่</th><th rowspan="2">จำนวนเงิน</th>'
        . '<th colspan="2" class="stmt-sep">พ.ศ. ' . $be . '</th><th rowspan="2">รายการจ่าย</th><th rowspan="2">ใบเสร็จ<br>เลขที่</th><th rowspan="2">จำนวนเงิน</th><th rowspan="2">เงินคงเหลือ</th></tr>'
        . '<tr><th>เดือน</th><th>วันที่</th><th class="stmt-sep">เดือน</th><th>วันที่</th></tr></thead><tbody>';
    // แถวยกมา
    $h .= '<tr><td class="c muted">' . e($mShort) . '</td><td class="c muted">1</td><td><b>ยอดคงเหลือยกมาจากเดือนก่อน</b></td><td></td><td></td>'
        . '<td class="c muted stmt-sep">' . e($mShort) . '</td><td></td><td></td><td></td><td></td><td class="r"><b>' . stmt_amount($m['carry']) . '</b></td></tr>';
    $n = 1;
    foreach ($m['rows'] as $r) {
        $day = (int)substr($r['txn_date'], 8, 2);
        $text = e($r['description'] ?: $r['category']);
        $doc = e((string)$r['doc_no']);
        if ($r['type'] === 'income') {
            $h .= '<tr><td></td><td class="c">' . $day . '</td><td>' . $text . '</td><td class="c">' . $doc . '</td><td class="r">' . stmt_amount((float)$r['amount']) . '</td>'
                . '<td class="stmt-sep"></td><td></td><td></td><td></td><td></td><td class="r">' . stmt_amount($r['balance']) . '</td></tr>';
        } else {
            $h .= '<tr><td></td><td></td><td></td><td></td><td></td><td class="stmt-sep"></td><td class="c">' . $day . '</td><td>' . $text . '</td><td class="c">' . $doc . '</td>'
                . '<td class="r">' . stmt_amount((float)$r['amount']) . '</td><td class="r">' . stmt_amount($r['balance']) . '</td></tr>';
        }
        $n++;
    }
    for (; $n < STMT_MIN_ROWS; $n++) {
        $h .= '<tr class="blank"><td></td><td></td><td></td><td></td><td></td><td class="stmt-sep"></td><td></td><td></td><td></td><td></td><td></td></tr>';
    }
    $net = $m['inc'] - $m['exp'];
    $h .= '</tbody><tfoot>'
        . '<tr><td class="nb"></td><td class="nb"></td><td class="nb"><b>รวมรายรับเดือนนี้</b></td><td class="nb"></td><td class="r box"><span class="total-income">' . stmt_amount($m['inc'], true) . '</span></td>'
        . '<td class="nb"></td><td class="nb"></td><td class="nb"><b>รวมรายจ่ายเดือนนี้</b></td><td class="nb"></td><td class="r box"><span class="total-expense">' . stmt_amount($m['exp'], true) . '</span></td><td class="r box"><span class="total-net">' . stmt_amount($net, true) . '</span></td></tr>'
        . '<tr><td colspan="7" class="nb"></td><td colspan="3" class="nb"><b>ยอดคงเหลือยกไป</b></td><td class="r box strong"><span class="total-closing">' . stmt_amount($m['closing']) . '</span></td></tr>'
        . '</tfoot></table></div>'
        . stmt_signatures($c, false)
        . '<p class="stmt-note"><b>หมายเหตุ :</b> กำหนดให้รายงาน 1 ตาราง เท่ากับ ' . ($isBank ? '1 บัญชี (รายงานทุกบัญชี)' : '1 เดือน') . '</p></section>';
    return $h;
}

/** แบบที่ 3 รายงานเงินคงเหลือของวัด ณ วันสุดท้ายของเดือน */
function stmt_balance_sheet(PDO $pdo, array $c, int $ceYear, int $month): string
{
    $tid = (int)$c['t']['id'];
    $end = date('Y-m-t', strtotime(sprintf('%04d-%02d-01', $ceYear, $month)));
    $mName = TH_MONTHS[$month];
    $be = $ceYear + 543;
    $cash = ledger_balance_on($pdo, ledger_account($pdo, ledger_cash_account($pdo, $tid)), $end);
    $banks = ledger_report_accounts($pdo, $tid, $ceYear, $month, 'bank');
    $total = $cash;
    $line = function (string $label, string $v) { return '<div class="bal-line"><span>' . $label . '</span><span class="bal-amt">' . $v . '</span><span class="bal-unit">บาท</span></div>'; };
    $h = '<section class="sheet sheet-port stmt-sheet stmt-balance">' . stmt_logo($c) . '<div class="stmt-form-no">แบบที่ 3 รายงานเงินคงเหลือ</div>'
        . '<div class="stmt-head"><p><b>รายงานเงินคงเหลือของวัด</b></p>' . stmt_place($c)
        . '<p><b>ณ วันที่</b>' . stmt_dots((string)(int)date('j', strtotime($end))) . ' <b>เดือน</b>' . stmt_dots($mName) . ' <b>พ.ศ.</b> ' . stmt_dots((string)$be) . ' <b>(ณ วันสุดท้ายของเดือน)</b></p>'
        . '<p>---------------------------</p></div>'
        . $line('<b>1. เงินสด / เช็ค</b> &nbsp; <b>จำนวน</b>', '<span class="bal-cash">' . stmt_amount($cash) . '</span>')
        . '<p class="mt-4"><b>2. เงินฝากธนาคาร</b></p>';
    $slots = max(3, count($banks));
    for ($i = 0; $i < $slots; $i++) {
        $a = $banks[$i] ?? null;
        $h .= '<div class="bal-bank"><div><b>2.' . ($i + 1) . ' ธนาคาร</b>' . stmt_dots($a ? (string)$a['bank_name'] : '', 'wide') . ' <b>สาขา</b>' . stmt_dots($a ? (string)$a['branch'] : '') . '</div>';
        if ($a) {
            $bal = ledger_balance_on($pdo, $a, $end);
            $total += $bal;
            $h .= $line('&nbsp;&nbsp;&nbsp;<b>เลขที่บัญชี</b>' . stmt_dots((string)$a['account_no'], 'wide') . ' <b>จำนวน</b>', '<span class="bal-bank-amt">' . stmt_amount($bal) . '</span>');
        } else {
            $h .= $line('&nbsp;&nbsp;&nbsp;<b>เลขที่บัญชี</b>' . stmt_dots('', 'wide') . ' (ถ้ามี) <b>จำนวน</b>', '&nbsp;');
        }
        $h .= '</div>';
    }
    $h .= $line('<b>3. รวมทั้งสิ้น</b> &nbsp; <b>จำนวน</b>', '<span class="bal-total">' . stmt_amount($total) . '</span>')
        . '<p class="bal-words"><b>ตัวอักษร</b> (<span class="bal-words-text">' . e(thai_baht_text(round($total, 2))) . '</span>)</p>'
        . stmt_signatures($c, true)
        . '<p class="stmt-note"><b>หมายเหตุ :</b> 1. รายงานเฉพาะส่วนที่เป็นเงินสดของวัด / เช็คในนามชื่อวัด / บัญชีเงินฝากธนาคารชื่อวัด<br>&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;2. รายงานทุกบัญชีที่ชื่อบัญชีเป็นชื่อวัด</p></section>';
    return $h;
}
