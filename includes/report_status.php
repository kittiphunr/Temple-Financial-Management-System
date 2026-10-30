<?php
/**
 * ติดตามการส่งรายงานประจำเดือน
 * วัด (หรือแอดมินหลัก/แอดมินอำเภอแทนวัด) กด "ส่งรายงานประจำเดือน" หลังตรวจแบบที่ 1–3 ระบบเก็บจำนวนรายการและยอดรวม ณ ตอนส่ง
 * เจ้าคณะ/แอดมินดูได้ว่าวัดใดส่งแล้ว ยังไม่ส่ง เกินกำหนด หรือมีการแก้ไขหลังส่ง (ตามขอบเขตของตน) และส่งอีเมลเตือนวัดที่ค้างได้
 * ปี = พ.ศ. เดือน 1–12 · กำหนดส่ง = วันที่ N ของเดือนถัดไป (ตั้งค่า report_due_day ค่าเริ่มต้น 10)
 */

const REPORT_DUE_DEFAULT = 10;

/** ตารางติดตามรายงานพร้อมใช้หรือยัง (ฐานข้อมูลเก่าที่ยังไม่รัน migration 2026_monthly_reports.sql จะซ่อนฟีเจอร์นี้) */
function report_available(): bool
{
    static $ok = null;
    if ($ok === null) {
        try {
            db()->query('SELECT 1 FROM monthly_reports LIMIT 1');
            db()->query('SELECT 1 FROM report_reminders LIMIT 1');
            $ok = true;
        } catch (PDOException) {
            $ok = false;
        }
    }
    return $ok;
}

function report_due_day(): int
{
    $d = (int)setting('report_due_day', (string)REPORT_DUE_DEFAULT);
    return $d >= 1 && $d <= 28 ? $d : REPORT_DUE_DEFAULT;
}

/** ช่วงวันของเดือนรายงาน (ค.ศ.) @return array{0:string,1:string} [วันแรก, วันสุดท้าย] */
function report_month_range(int $beYear, int $month): array
{
    $first = sprintf('%04d-%02d-01', $beYear - 543, $month);
    return [$first, date('Y-m-t', strtotime($first))];
}

/** วันครบกำหนดส่ง (Y-m-d ค.ศ.) = วันที่ N ของเดือนถัดไป */
function report_due_date(int $beYear, int $month, ?int $dueDay = null): string
{
    $next = $month === 12 ? sprintf('%04d-01-01', $beYear - 543 + 1) : sprintf('%04d-%02d-01', $beYear - 543, $month + 1);
    return substr($next, 0, 8) . sprintf('%02d', $dueDay ?? report_due_day());
}

/** ยอดรวมของวัดในเดือนนั้น (ไม่นับรายการโอนระหว่างบัญชี) @return array{count:int,income:float,expense:float} */
function report_snapshot(PDO $pdo, int $templeId, int $beYear, int $month): array
{
    [$from, $to] = report_month_range($beYear, $month);
    $st = $pdo->prepare("SELECT COUNT(*) c, COALESCE(SUM(CASE WHEN type='income' THEN amount END),0) i, COALESCE(SUM(CASE WHEN type='expense' THEN amount END),0) e
        FROM transactions WHERE temple_id = ? AND is_deleted = 0 AND transfer_id IS NULL AND txn_date BETWEEN ? AND ?");
    $st->execute([$templeId, $from, $to]);
    $r = $st->fetch();
    return ['count' => (int)$r['c'], 'income' => (float)$r['i'], 'expense' => (float)$r['e']];
}

/** รายงานที่ส่งไว้ของวัดในเดือนนั้น หรือ null */
function report_row(PDO $pdo, int $templeId, int $beYear, int $month): ?array
{
    $st = $pdo->prepare('SELECT r.*, u.full_name AS by_name FROM monthly_reports r LEFT JOIN users u ON u.id = r.submitted_by
        WHERE r.temple_id = ? AND r.report_year = ? AND r.report_month = ?');
    $st->execute([$templeId, $beYear, $month]);
    return $st->fetch() ?: null;
}

/**
 * สถานะ: future = เดือนยังไม่เริ่ม · ok = ส่งแล้วและตรงกับข้อมูลปัจจุบัน · changed = ส่งแล้วแต่มีการแก้ไขหลังส่ง
 *        late = ยังไม่ส่งและเกินกำหนด · pending = ยังไม่ส่ง (ยังไม่เกินกำหนด)
 * @param array|null $report แถว monthly_reports  @param array $now ยอดปัจจุบัน {count,income,expense}
 */
function report_state(?array $report, array $now, int $beYear, int $month, ?string $today = null): string
{
    $today ??= date('Y-m-d');
    if (!$report) {
        if (report_month_range($beYear, $month)[0] > $today) {
            return 'future';
        }
        return $today > report_due_date($beYear, $month) ? 'late' : 'pending';
    }
    $same = (int)$report['txn_count'] === $now['count'] && abs((float)$report['income_total'] - $now['income']) < 0.005
        && abs((float)$report['expense_total'] - $now['expense']) < 0.005;
    return $same ? 'ok' : 'changed';
}

const REPORT_STATE_LABEL = [
    'ok' => ['ส่งแล้ว', 'badge-ok'], 'changed' => ['แก้ไขหลังส่ง', ''], 'late' => ['เกินกำหนด', 'badge-err'],
    'pending' => ['ยังไม่ส่ง', 'badge-mute'], 'future' => ['ยังไม่ถึงเดือน', 'badge-mute'],
];

/**
 * ส่ง (หรือส่งใหม่) รายงานประจำเดือน — ส่งได้เมื่อเดือนนั้นเริ่มแล้ว
 * @return array{0:bool,1:string}
 */
function report_submit(PDO $pdo, int $templeId, int $beYear, int $month, ?int $userId, string $note = ''): array
{
    if ($month < 1 || $month > 12 || $beYear < 2500 || $beYear > 2700) {
        return [false, 'เดือน/ปีไม่ถูกต้อง'];
    }
    if (report_month_range($beYear, $month)[0] > date('Y-m-d')) {
        return [false, 'ยังไม่ถึงเดือนที่เลือก จึงส่งรายงานไม่ได้'];
    }
    $snap = report_snapshot($pdo, $templeId, $beYear, $month);
    $note = mb_substr(trim($note), 0, 255);
    $old = report_row($pdo, $templeId, $beYear, $month);
    if ($old) {
        $pdo->prepare('UPDATE monthly_reports SET submitted_at = NOW(), submitted_by = ?, revision = revision + 1, txn_count = ?, income_total = ?, expense_total = ?, note = ?
            WHERE id = ?')->execute([$userId, $snap['count'], $snap['income'], $snap['expense'], $note ?: null, $old['id']]);
        return [true, 'ส่งรายงานใหม่แล้ว (ครั้งที่ ' . ((int)$old['revision'] + 1) . ')'];
    }
    $pdo->prepare('INSERT INTO monthly_reports (temple_id, report_year, report_month, submitted_at, submitted_by, txn_count, income_total, expense_total, note)
        VALUES (?,?,?,NOW(),?,?,?,?,?)')->execute([$templeId, $beYear, $month, $userId, $snap['count'], $snap['income'], $snap['expense'], $note ?: null]);
    return [true, 'ส่งรายงานประจำเดือนแล้ว'];
}

/**
 * รายชื่อวัดในขอบเขตของผู้ใช้ พร้อมสถานะการส่งของเดือนนั้น
 * @param array{district?:int,status?:string,q?:string} $f  status: all|sent|unsent|late|changed
 */
function report_list(PDO $pdo, int $beYear, int $month, array $f = []): array
{
    [$from, $to] = report_month_range($beYear, $month);
    [$sc, $sp] = scope_sql('t');
    $sql = "SELECT t.id, t.name, t.subdistrict, t.email, d.id AS district_id, d.name AS district, d.sort_order,
            r.submitted_at, r.revision, r.txn_count AS r_count, r.income_total AS r_income, r.expense_total AS r_expense, r.note, ub.full_name AS by_name,
            COALESCE(s.c, 0) AS n_count, COALESCE(s.i, 0) AS n_income, COALESCE(s.e, 0) AS n_expense, m.sent_at AS reminded_at
        FROM temples t JOIN districts d ON d.id = t.district_id
        LEFT JOIN monthly_reports r ON r.temple_id = t.id AND r.report_year = ? AND r.report_month = ?
        LEFT JOIN users ub ON ub.id = r.submitted_by
        LEFT JOIN report_reminders m ON m.temple_id = t.id AND m.report_year = ? AND m.report_month = ?
        LEFT JOIN (SELECT temple_id, COUNT(*) c, SUM(CASE WHEN type='income' THEN amount ELSE 0 END) i, SUM(CASE WHEN type='expense' THEN amount ELSE 0 END) e
                   FROM transactions WHERE is_deleted = 0 AND transfer_id IS NULL AND txn_date BETWEEN ? AND ? GROUP BY temple_id) s ON s.temple_id = t.id
        WHERE t.is_active = 1 $sc";
    $p = array_merge([$beYear, $month, $beYear, $month, $from, $to], $sp);
    if (!empty($f['district'])) {
        $sql .= ' AND t.district_id = ?';
        $p[] = (int)$f['district'];
    }
    if (($f['q'] ?? '') !== '') {
        $sql .= ' AND t.name LIKE ?';
        $p[] = '%' . $f['q'] . '%';
    }
    $st = $pdo->prepare($sql . ' ORDER BY d.sort_order, d.id, t.subdistrict, t.name');
    $st->execute($p);
    $out = [];
    $status = $f['status'] ?? 'all';
    foreach ($st->fetchAll() as $r) {
        $rep = $r['submitted_at'] ? ['txn_count' => $r['r_count'], 'income_total' => $r['r_income'], 'expense_total' => $r['r_expense']] : null;
        $r['state'] = report_state($rep, ['count' => (int)$r['n_count'], 'income' => (float)$r['n_income'], 'expense' => (float)$r['n_expense']], $beYear, $month);
        $keep = match ($status) {
            'sent' => in_array($r['state'], ['ok', 'changed'], true),
            'unsent' => in_array($r['state'], ['pending', 'late', 'future'], true),
            'late' => $r['state'] === 'late',
            'changed' => $r['state'] === 'changed',
            default => true,
        };
        if ($keep) {
            $out[] = $r;
        }
    }
    return $out;
}

/** สรุปจำนวนตามสถานะ @return array{total:int,sent:int,unsent:int,late:int,changed:int} */
function report_summary(array $rows): array
{
    $s = ['total' => count($rows), 'sent' => 0, 'unsent' => 0, 'late' => 0, 'changed' => 0];
    foreach ($rows as $r) {
        if (in_array($r['state'], ['ok', 'changed'], true)) $s['sent']++;
        elseif ($r['state'] !== 'future') $s['unsent']++;
        if ($r['state'] === 'late') $s['late']++;
        if ($r['state'] === 'changed') $s['changed']++;
    }
    return $s;
}

/** ตั้งเตือนได้เฉพาะวัดที่ยังไม่ส่ง (pending/late) มีอีเมลวัด และยังไม่เคยเตือนเดือนนี้ */
function report_remindable(array $r): bool
{
    return in_array($r['state'], ['pending', 'late'], true) && trim((string)$r['email']) !== '' && empty($r['reminded_at']);
}

/**
 * ส่งอีเมลเตือนวัดที่ยังไม่ส่งรายงาน (เฉพาะวัดในรายการ $rows ที่เตือนได้)
 * @return array{sent:int,skipped:int,failed:int}
 */
function report_send_reminders(PDO $pdo, array $rows, int $beYear, int $month, ?int $userId): array
{
    global $CONFIG;
    $res = ['sent' => 0, 'skipped' => 0, 'failed' => 0];
    $link = trim((string)($CONFIG['app_url'] ?? ''));
    $due = report_due_date($beYear, $month);
    $ins = $pdo->prepare('INSERT IGNORE INTO report_reminders (temple_id, report_year, report_month, sent_at, sent_by) VALUES (?,?,?,NOW(),?)');
    foreach ($rows as $r) {
        if (!report_remindable($r)) {
            $res['skipped']++;
            continue;
        }
        // จองสิทธิ์เตือนก่อน (กันส่งซ้ำเมื่อกดพร้อมกัน) ถ้าส่งไม่สำเร็จค่อยคืน
        $ins->execute([$r['id'], $beYear, $month, $userId]);
        if (!$ins->rowCount()) {
            $res['skipped']++;
            continue;
        }
        $body = "เรียน ผู้ดูแล" . $r['name'] . "\n\nระบบยังไม่พบการส่งรายงานประจำเดือน" . TH_MONTHS[$month] . ' พ.ศ. ' . $beYear
            . " ของวัดท่าน\nกำหนดส่งภายในวันที่ " . th_date($due) . "\n\nกรุณาเข้าสู่ระบบ ตรวจรายงานแบบที่ 1–3 ประจำเดือน แล้วกดปุ่ม \"ส่งรายงานประจำเดือน\""
            . ($link !== '' ? "\n" . rtrim($link, '/') . '/statements.php' : '') . "\n\nอีเมลนี้ส่งจากระบบอัตโนมัติ";
        if (send_mail(trim($r['email']), 'แจ้งเตือน: ยังไม่ได้ส่งรายงานประจำเดือน' . TH_MONTHS[$month] . ' ' . $beYear, $body)) {
            $res['sent']++;
        } else {
            $pdo->prepare('DELETE FROM report_reminders WHERE temple_id = ? AND report_year = ? AND report_month = ?')->execute([$r['id'], $beYear, $month]);
            $res['failed']++;
        }
    }
    return $res;
}
