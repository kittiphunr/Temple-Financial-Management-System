<?php
/**
 * แดชบอร์ดความผิดปกติ (anomalies.php): ช่วยเจ้าคณะ/แอดมินเห็นวัดที่ควรติดตามก่อน ใช้ข้อมูลที่มีอยู่แล้ว ไม่ต้องใช้ migration
 * ตรวจเฉพาะวัดในขอบเขตของผู้ใช้ (scope_sql) ในเดือนที่เลือก · เป็น "ธง" ให้ตรวจ ไม่ได้ตัดสินว่าผิด
 * กฎ (เกณฑ์ปรับได้ที่ค่าคงที่ด้านล่าง):
 *  late        ยังไม่ส่งรายงานเดือนนั้นและเกินกำหนดแล้ว                                  (สูง)
 *  quiet       ไม่มีรายการเลยทั้ง 3 เดือนติดกัน (เดือนที่เลือกและ 2 เดือนก่อนหน้า)        (สูง)
 *  negative    มีบัญชี (เงินสด/ธนาคาร) ที่ยอดคงเหลือสิ้นเดือนติดลบ                       (สูง)
 *  spike       รายจ่ายเดือนนั้นตั้งแต่ 2 เท่าของค่าเฉลี่ย 3 เดือนก่อนหน้า และไม่ต่ำกว่า 5,000 บาท (กลาง)
 *  noevidence  มีรายจ่ายรายการละ 10,000 บาทขึ้นไปที่ไม่มีหลักฐานแนบ                       (กลาง)
 *  returned    รายงานถูกส่งกลับแก้ไขแล้ววัดยังไม่ส่งใหม่                                (กลาง)
 *  cash_high   เงินสดคงเหลือสิ้นเดือน 200,000 บาทขึ้นไป                                   (ต่ำ)
 */

const ANOMALY_QUIET_MONTHS = 3;
const ANOMALY_SPIKE_RATIO = 2.0;
const ANOMALY_SPIKE_MIN = 5000;
const ANOMALY_EVIDENCE_MIN = 10000;
const ANOMALY_CASH_HIGH = 200000;
const ANOMALY_WEIGHT = ['high' => 3, 'mid' => 2, 'low' => 1];
const ANOMALY_KINDS = [
    'late' => ['ยังไม่ส่งรายงาน', 'high'], 'quiet' => ['ไม่มีรายการหลายเดือน', 'high'], 'negative' => ['ยอดติดลบ', 'high'],
    'spike' => ['รายจ่ายพุ่ง', 'mid'], 'noevidence' => ['รายจ่ายสูงไม่มีหลักฐาน', 'mid'], 'returned' => ['รายงานถูกส่งกลับ', 'mid'], 'cash_high' => ['เงินสดสูง', 'low'],
];
const ANOMALY_SEVERITY_LABEL = ['high' => ['สูง', 'badge-err'], 'mid' => ['กลาง', ''], 'low' => ['ต่ำ', 'badge-mute']];

/** วันแรกของเดือน (Y-m-d ค.ศ.) ที่ห่างจากเดือน $beYear/$month ไป $offset เดือน */
function anomaly_month_start(int $beYear, int $month, int $offset = 0): string
{
    return date('Y-m-01', strtotime(sprintf('%04d-%02d-01 %+d months', $beYear - 543, $month, $offset)));
}

/**
 * ตรวจวัดในขอบเขตของผู้ใช้ในเดือนที่เลือก
 * @param array{district?:int,kind?:string,today?:string} $opt
 * @return array{scanned:int,rows:array<int,array{temple:array,flags:array,score:int}>,counts:array<string,int>}
 */
function anomaly_scan(PDO $pdo, int $beYear, int $month, array $opt = []): array
{
    $first = anomaly_month_start($beYear, $month);
    $last = date('Y-m-t', strtotime($first));
    $from3 = anomaly_month_start($beYear, $month, -3);
    $ym = fn(int $off) => substr(anomaly_month_start($beYear, $month, $off), 0, 7);
    [$sc, $sp] = scope_sql('t');
    $dist = (int)($opt['district'] ?? 0);
    $dsql = $dist ? ' AND t.district_id = ?' : '';
    $dp = $dist ? [$dist] : [];

    $st = $pdo->prepare("SELECT t.id, t.name, t.subdistrict, t.district_id, d.name AS district FROM temples t JOIN districts d ON d.id = t.district_id
        WHERE t.is_active = 1 $sc$dsql ORDER BY d.sort_order, d.id, t.subdistrict, t.name");
    $st->execute(array_merge($sp, $dp));
    $temples = [];
    foreach ($st->fetchAll() as $t) {
        $temples[(int)$t['id']] = $t;
    }
    $flags = array_fill_keys(array_keys($temples), []);
    $add = function (int $tid, string $kind, string $detail) use (&$flags) {
        if (isset($flags[$tid])) {
            $flags[$tid][] = ['kind' => $kind, 'severity' => ANOMALY_KINDS[$kind][1], 'label' => ANOMALY_KINDS[$kind][0], 'detail' => $detail];
        }
    };

    // รายการและรายจ่ายรายเดือน (ไม่นับรายการที่ลบ/รายการโอน)
    $st = $pdo->prepare("SELECT x.temple_id, DATE_FORMAT(x.txn_date, '%Y-%m') AS ym, COUNT(*) AS n, COALESCE(SUM(CASE WHEN x.type = 'expense' THEN x.amount END), 0) AS e
        FROM transactions x JOIN temples t ON t.id = x.temple_id WHERE x.is_deleted = 0 AND x.transfer_id IS NULL AND x.txn_date BETWEEN ? AND ? AND t.is_active = 1 $sc$dsql GROUP BY x.temple_id, ym");
    $st->execute(array_merge([$from3, $last], $sp, $dp));
    $by = [];
    foreach ($st->fetchAll() as $r) {
        $by[(int)$r['temple_id']][$r['ym']] = ['n' => (int)$r['n'], 'e' => (float)$r['e']];
    }
    foreach ($temples as $tid => $t) {
        $n = fn(int $off) => $by[$tid][$ym($off)]['n'] ?? 0;
        if (array_sum(array_map($n, range(-(ANOMALY_QUIET_MONTHS - 1), 0))) === 0) {
            $add($tid, 'quiet', 'ไม่มีรายการเลยตั้งแต่เดือน' . TH_MONTHS[(int)substr($ym(-(ANOMALY_QUIET_MONTHS - 1)), 5, 2)] . ' ถึง' . TH_MONTHS[$month]);
        }
        $cur = $by[$tid][$ym(0)]['e'] ?? 0.0;
        $avg = array_sum(array_map(fn($o) => $by[$tid][$ym($o)]['e'] ?? 0.0, [-1, -2, -3])) / 3;
        if ($avg > 0 && $cur >= ANOMALY_SPIKE_MIN && $cur >= ANOMALY_SPIKE_RATIO * $avg) {
            $add($tid, 'spike', 'รายจ่ายเดือนนี้ ' . money($cur) . ' บาท (ค่าเฉลี่ย 3 เดือนก่อน ' . money($avg) . ' บาท · ' . number_format($cur / $avg, 1) . ' เท่า)');
        }
    }

    // ยอดคงเหลือสิ้นเดือนของทุกบัญชีที่ใช้งานอยู่ (สูตรเดียวกับ ledger_balance_on)
    $st = $pdo->prepare("SELECT a.id, a.temple_id, a.kind, a.name, a.bank_name, a.account_no, a.opening_balance, a.opening_date,
          COALESCE((SELECT SUM(CASE WHEN x.type = 'income' THEN x.amount ELSE -x.amount END) FROM transactions x WHERE x.temple_id = a.temple_id AND x.is_deleted = 0 AND x.txn_date <= ?
            AND (a.opening_date IS NULL OR x.txn_date >= a.opening_date) AND (x.account_id = a.id OR (a.kind = 'cash' AND x.account_id IS NULL))), 0) AS flow
        FROM accounts a JOIN temples t ON t.id = a.temple_id WHERE a.is_active = 1 AND t.is_active = 1 $sc$dsql");
    $st->execute(array_merge([$last], $sp, $dp));
    foreach ($st->fetchAll() as $a) {
        $bal = ($a['opening_date'] === null || $a['opening_date'] <= $last ? (float)$a['opening_balance'] : 0.0) + (float)$a['flow'];
        $label = ledger_account_label($a);
        if ($bal < -0.004) {
            $add((int)$a['temple_id'], 'negative', $label . ' ติดลบ ' . money(-$bal) . ' บาท ณ สิ้นเดือน');
        } elseif ($a['kind'] === 'cash' && $bal >= ANOMALY_CASH_HIGH) {
            $add((int)$a['temple_id'], 'cash_high', 'เงินสดคงเหลือ ' . money($bal) . ' บาท ณ สิ้นเดือน');
        }
    }

    // รายจ่ายสูงที่ไม่มีหลักฐานแนบ
    if (attachments_available()) {
        $st = $pdo->prepare("SELECT x.temple_id, COUNT(*) AS n, SUM(x.amount) AS s FROM transactions x JOIN temples t ON t.id = x.temple_id
            WHERE x.type = 'expense' AND x.is_deleted = 0 AND x.transfer_id IS NULL AND x.amount >= ? AND x.txn_date BETWEEN ? AND ? AND t.is_active = 1 $sc$dsql
              AND NOT EXISTS (SELECT 1 FROM transaction_attachments ta WHERE ta.transaction_id = x.id) GROUP BY x.temple_id");
        $st->execute(array_merge([ANOMALY_EVIDENCE_MIN, $first, $last], $sp, $dp));
        foreach ($st->fetchAll() as $r) {
            $add((int)$r['temple_id'], 'noevidence', (int)$r['n'] . ' รายการ รวม ' . money($r['s']) . ' บาท (รายการละ ' . number_format(ANOMALY_EVIDENCE_MIN) . ' บาทขึ้นไป)');
        }
    }

    // สถานะรายงานของเดือนนั้น
    if (report_available()) {
        foreach (report_list($pdo, $beYear, $month, ['district' => $dist, 'today' => $opt['today'] ?? null]) as $r) {
            if ($r['state'] === 'late') {
                $add((int)$r['id'], 'late', 'เกินกำหนดส่งรายงานเดือน' . TH_MONTHS[$month] . ' (กำหนด ' . th_date(report_due_date($beYear, $month)) . ')');
            }
            if (($r['approval'] ?? '') === 'returned') {
                $add((int)$r['id'], 'returned', 'ถูกส่งกลับแก้ไข: ' . (string)($r['return_reason'] ?? ''));
            }
        }
    }

    $kind = $opt['kind'] ?? '';
    $rows = [];
    $counts = array_fill_keys(array_keys(ANOMALY_KINDS), 0);
    foreach ($temples as $tid => $t) {
        $fl = $flags[$tid];
        foreach ($fl as $f) {
            $counts[$f['kind']]++;
        }
        if ($kind !== '' && !in_array($kind, array_column($fl, 'kind'), true)) {
            continue;
        }
        if ($fl) {
            usort($fl, fn($a, $b) => ANOMALY_WEIGHT[$b['severity']] <=> ANOMALY_WEIGHT[$a['severity']]);
            $rows[] = ['temple' => $t, 'flags' => $fl, 'score' => array_sum(array_map(fn($f) => ANOMALY_WEIGHT[$f['severity']], $fl))];
        }
    }
    usort($rows, fn($a, $b) => [$b['score'], $a['temple']['name']] <=> [$a['score'], $b['temple']['name']]);
    return ['scanned' => count($temples), 'rows' => $rows, 'counts' => $counts];
}
