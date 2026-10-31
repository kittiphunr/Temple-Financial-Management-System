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
    if ($old && !empty($old['closed_at'])) {
        return [false, 'เดือนนี้ปิดงวดแล้ว ส่งรายงานใหม่ไม่ได้ (ต้องเปิดงวดก่อน)'];
    }
    if ($old) {
        // ส่งใหม่ = การรับรองและการส่งกลับของรอบก่อนหมดผล (ประวัติยังอยู่ใน report_review_events)
        $reset = report_approval_available() ? ', sub_approved_at = NULL, sub_approved_by = NULL, dist_approved_at = NULL, dist_approved_by = NULL, returned_at = NULL, returned_by = NULL, return_reason = NULL' : '';
        $pdo->prepare('UPDATE monthly_reports SET submitted_at = NOW(), submitted_by = ?, revision = revision + 1, txn_count = ?, income_total = ?, expense_total = ?, note = ?' . $reset . '
            WHERE id = ?')->execute([$userId, $snap['count'], $snap['income'], $snap['expense'], $note ?: null, $old['id']]);
        return [true, 'ส่งรายงานใหม่แล้ว (ครั้งที่ ' . ((int)$old['revision'] + 1) . ')'];
    }
    $pdo->prepare('INSERT INTO monthly_reports (temple_id, report_year, report_month, submitted_at, submitted_by, txn_count, income_total, expense_total, note)
        VALUES (?,?,?,NOW(),?,?,?,?,?)')->execute([$templeId, $beYear, $month, $userId, $snap['count'], $snap['income'], $snap['expense'], $note ?: null]);
    return [true, 'ส่งรายงานประจำเดือนแล้ว'];
}

/**
 * รายชื่อวัดในขอบเขตของผู้ใช้ พร้อมสถานะการส่งของเดือนนั้น
 * @param array{district?:int,status?:string,q?:string} $f  status: all|sent|unsent|late|changed|closed
 */
function report_list(PDO $pdo, int $beYear, int $month, array $f = []): array
{
    [$from, $to] = report_month_range($beYear, $month);
    [$sc, $sp] = scope_sql('t');
    $sql = "SELECT t.id, t.name, t.subdistrict, t.email, d.id AS district_id, d.name AS district, d.sort_order,
            r.submitted_at, r.revision, " . (month_close_available() ? 'r.closed_at' : 'NULL') . " AS closed_at,
            " . (report_approval_available() ? 'r.sub_approved_at, r.dist_approved_at, r.returned_at, r.return_reason' : 'NULL AS sub_approved_at, NULL AS dist_approved_at, NULL AS returned_at, NULL AS return_reason') . ", r.txn_count AS r_count, r.income_total AS r_income, r.expense_total AS r_expense, r.note, ub.full_name AS by_name,
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
        $r['approval'] = report_approval_state($r, $r['state']);
        $keep = match ($status) {
            'awaiting' => in_array($r['approval'], ['waiting', 'sub'], true),
            'returned' => $r['approval'] === 'returned',
            'approved' => $r['approval'] === 'done',
            'sent' => in_array($r['state'], ['ok', 'changed'], true),
            'unsent' => in_array($r['state'], ['pending', 'late', 'future'], true),
            'late' => $r['state'] === 'late',
            'changed' => $r['state'] === 'changed',
            'closed' => !empty($r['closed_at']),
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
    $s = ['total' => count($rows), 'sent' => 0, 'unsent' => 0, 'late' => 0, 'changed' => 0, 'closed' => 0, 'awaiting' => 0, 'returned' => 0, 'approved' => 0];
    foreach ($rows as $r) {
        $a = $r['approval'] ?? 'none';
        if (in_array($a, ['waiting', 'sub'], true)) $s['awaiting']++;
        elseif ($a === 'returned') $s['returned']++;
        elseif ($a === 'done') $s['approved']++;
        if (!empty($r['closed_at'])) $s['closed']++;
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

// ===================== รับรองรายงานเป็นลำดับชั้น =====================
// วัดส่งรายงาน → เจ้าคณะตำบลตรวจและ "รับรอง" → อำเภอ "รับรอง" (หรือส่งกลับแก้ไขพร้อมเหตุผลในขั้นใดก็ได้)
// - รับรองได้เมื่อยอดปัจจุบันตรงกับตอนส่ง (state = ok) และยังไม่ถูกส่งกลับ · วัดส่งใหม่ = การรับรองรอบก่อนหมดผล
// - อำเภอรับรองได้หลังเจ้าคณะตำบลรับรองแล้ว ยกเว้นตำบลนั้นยังไม่มีเจ้าคณะตำบลในระบบ (ข้ามขั้นได้) · แอดมินหลักรับรองในนามอำเภอได้เสมอ
// - ส่งกลับ: ล้างการรับรอง เปิดงวดให้อัตโนมัติถ้าปิดอยู่ (บันทึกประวัติ) และแจ้งอีเมลวัด · เจ้าคณะตำบลส่งกลับไม่ได้หลังอำเภอรับรองแล้ว

/** ฐานข้อมูลมีคอลัมน์รับรองหรือยัง (ยังไม่รัน migration 2026_report_approval.sql = ไม่มีขั้นตอนรับรอง ระบบทำงานแบบเดิม) */
function report_approval_available(): bool
{
    static $ok = null;
    if ($ok === null) {
        try {
            $ok = report_available();
            if ($ok) {
                db()->query('SELECT sub_approved_at, dist_approved_at, returned_at, return_reason FROM monthly_reports LIMIT 1');
                db()->query('SELECT 1 FROM report_review_events LIMIT 1');
            }
        } catch (PDOException) {
            $ok = false;
        }
    }
    return $ok;
}

const REPORT_APPROVAL_LABEL = [
    'none' => ['', ''], 'waiting' => ['รอรับรอง', 'badge-mute'], 'sub' => ['ตำบลรับรองแล้ว รออำเภอ', 'badge-mute'],
    'done' => ['อำเภอรับรองแล้ว', 'badge-ok'], 'returned' => ['ส่งกลับแก้ไข', 'badge-err'], 'stale' => ['แก้ไขหลังรับรอง', ''],
];

/**
 * สถานะการรับรอง: none = ยังไม่ส่ง/ยังไม่มีขั้นตอนรับรอง · waiting = รอเจ้าคณะตำบล · sub = ตำบลรับรองแล้วรออำเภอ · done = อำเภอรับรองแล้ว
 *                 returned = ถูกส่งกลับแก้ไข · stale = เคยรับรองแต่มีการแก้ไขรายการหลังส่ง (ต้องส่งใหม่)
 * @param array|null $r แถวที่มี sub_approved_at/dist_approved_at/returned_at  @param string $state ผลของ report_state()
 */
function report_approval_state(?array $r, string $state): string
{
    if (!$r || !in_array($state, ['ok', 'changed'], true) || !report_approval_available()) {
        return 'none';
    }
    if (!empty($r['returned_at'])) {
        return 'returned';
    }
    $approved = !empty($r['sub_approved_at']) || !empty($r['dist_approved_at']);
    if ($state === 'changed') {
        return $approved ? 'stale' : 'waiting';
    }
    return !empty($r['dist_approved_at']) ? 'done' : (!empty($r['sub_approved_at']) ? 'sub' : 'waiting');
}

/** ตำบลของวัดนี้มีเจ้าคณะตำบล (บัญชีที่ใช้งานอยู่) ดูแลอยู่หรือไม่ */
function report_has_subdistrict_chief(PDO $pdo, int $templeId): bool
{
    $st = $pdo->prepare("SELECT 1 FROM temples t JOIN user_subdistricts us ON us.district_id = t.district_id AND us.subdistrict = t.subdistrict
        JOIN users u ON u.id = us.user_id AND u.role = 'subdistrict' AND u.is_active = 1 WHERE t.id = ? LIMIT 1");
    $st->execute([$templeId]);
    return (bool)$st->fetchColumn();
}

/**
 * ขั้นที่ผู้ใช้ปัจจุบันตรวจรายงานของวัดนี้ได้: 'subdistrict' | 'district' | null (admin ถือเป็นขั้นอำเภอ)
 * province / temple ไม่มีสิทธิ์รับรอง
 */
function report_review_level(int $templeId): ?string
{
    $u = user();
    if (!$u || !report_approval_available()) {
        return null;
    }
    $t = temple_row($templeId);
    if (!$t || empty($t['is_active'])) {
        return null;
    }
    return match ($u['role']) {
        'admin' => 'district',
        'district' => (int)$t['district_id'] === (int)$u['district_id'] ? 'district' : null,
        'subdistrict' => subdistrict_can_view_temple($templeId, (int)$u['id']) ? 'subdistrict' : null,
        default => null,
    };
}

/** @return array{0:bool,1:string} [ผ่าน, ข้อความผิดพลาด] ตรวจเงื่อนไขกดรับรอง */
function report_can_approve(PDO $pdo, int $templeId, int $beYear, int $month, ?array $rep, ?string $level): array
{
    if ($level === null) {
        return [false, 'ไม่มีสิทธิ์รับรองรายงานของวัดนี้'];
    }
    if (!$rep) {
        return [false, 'วัดยังไม่ได้ส่งรายงานเดือนนี้'];
    }
    if (!empty($rep['returned_at'])) {
        return [false, 'รายงานถูกส่งกลับแก้ไข รอวัดส่งใหม่ก่อนจึงจะรับรองได้'];
    }
    if (report_state($rep, report_snapshot($pdo, $templeId, $beYear, $month), $beYear, $month) !== 'ok') {
        return [false, 'มีการแก้ไขรายการหลังวัดส่งรายงาน — รอวัดส่งรายงานใหม่ก่อนจึงจะรับรองได้'];
    }
    if ($level === 'subdistrict') {
        return empty($rep['sub_approved_at']) ? [true, ''] : [false, 'รับรองในขั้นตำบลแล้ว'];
    }
    if (!empty($rep['dist_approved_at'])) {
        return [false, 'อำเภอรับรองแล้ว'];
    }
    if (empty($rep['sub_approved_at']) && (user()['role'] ?? '') !== 'admin' && report_has_subdistrict_chief($pdo, $templeId)) {
        return [false, 'ต้องให้เจ้าคณะ' . unit_subdistrict() . 'รับรองก่อน จึงจะรับรองในขั้น' . unit_district() . 'ได้'];
    }
    return [true, ''];
}

/** รับรองรายงาน (ขั้นตามบทบาทของผู้ใช้ปัจจุบัน) @return array{0:bool,1:string} */
function report_approve(PDO $pdo, int $templeId, int $beYear, int $month, int $userId): array
{
    $level = report_review_level($templeId);
    $rep = report_approval_available() ? report_row($pdo, $templeId, $beYear, $month) : null;
    [$ok, $err] = report_can_approve($pdo, $templeId, $beYear, $month, $rep, $level);
    if (!$ok) {
        return [false, $err];
    }
    $col = $level === 'subdistrict' ? 'sub' : 'dist';
    // เงื่อนไข IS NULL กันกดซ้ำพร้อมกัน
    $up = $pdo->prepare("UPDATE monthly_reports SET {$col}_approved_at = NOW(), {$col}_approved_by = ? WHERE id = ? AND {$col}_approved_at IS NULL AND returned_at IS NULL");
    $up->execute([$userId, $rep['id']]);
    if (!$up->rowCount()) {
        return [false, 'รายงานนี้มีการเปลี่ยนแปลงสถานะแล้ว กรุณาโหลดหน้าใหม่'];
    }
    $pdo->prepare('INSERT INTO report_review_events (temple_id, report_year, report_month, revision, action, by_user, created_at) VALUES (?,?,?,?,?,?,NOW())')
        ->execute([$templeId, $beYear, $month, (int)$rep['revision'], $level === 'subdistrict' ? 'sub_approve' : 'dist_approve', $userId]);
    return [true, 'รับรองรายงานเดือน' . TH_MONTHS[$month] . ' ' . $beYear . ' ในขั้น' . ($level === 'subdistrict' ? unit_subdistrict() : unit_district()) . 'แล้ว'];
}

/** ส่งรายงานกลับให้วัดแก้ไข (ต้องมีเหตุผลอย่างน้อย 5 ตัวอักษร) @return array{0:bool,1:string} */
function report_return(PDO $pdo, int $templeId, int $beYear, int $month, int $userId, string $reason): array
{
    $level = report_review_level($templeId);
    if ($level === null) {
        return [false, 'ไม่มีสิทธิ์ส่งรายงานของวัดนี้กลับ'];
    }
    $reason = mb_substr(trim($reason), 0, 255);
    if (mb_strlen($reason) < 5) {
        return [false, 'กรุณาระบุเหตุผลที่ส่งกลับ (อย่างน้อย 5 ตัวอักษร)'];
    }
    $rep = report_row($pdo, $templeId, $beYear, $month);
    if (!$rep) {
        return [false, 'วัดยังไม่ได้ส่งรายงานเดือนนี้'];
    }
    if (!empty($rep['returned_at'])) {
        return [false, 'รายงานนี้ถูกส่งกลับไปแล้ว รอวัดส่งใหม่'];
    }
    if ($level === 'subdistrict' && !empty($rep['dist_approved_at'])) {
        return [false, unit_district() . 'รับรองรายงานนี้แล้ว เจ้าคณะ' . unit_subdistrict() . 'ส่งกลับไม่ได้'];
    }
    $reopened = false;
    $pdo->beginTransaction();
    try {
        $up = $pdo->prepare('UPDATE monthly_reports SET sub_approved_at = NULL, sub_approved_by = NULL, dist_approved_at = NULL, dist_approved_by = NULL,
            returned_at = NOW(), returned_by = ?, return_reason = ? WHERE id = ? AND returned_at IS NULL');
        $up->execute([$userId, $reason, $rep['id']]);
        if (!$up->rowCount()) {
            $pdo->rollBack();
            return [false, 'รายงานนี้มีการเปลี่ยนแปลงสถานะแล้ว กรุณาโหลดหน้าใหม่'];
        }
        if (month_close_available() && !empty($rep['closed_at'])) {   // ส่งกลับ = ต้องแก้ไขได้ จึงเปิดงวดให้
            $pdo->prepare('UPDATE monthly_reports SET closed_at = NULL, closed_by = NULL WHERE id = ?')->execute([$rep['id']]);
            $pdo->prepare("INSERT INTO month_close_events (temple_id, report_year, report_month, action, reason, by_user, created_at) VALUES (?,?,?,'reopen',?,?,NOW())")
                ->execute([$templeId, $beYear, $month, 'ส่งกลับแก้ไข: ' . $reason, $userId]);
            $reopened = true;
        }
        $pdo->prepare("INSERT INTO report_review_events (temple_id, report_year, report_month, revision, action, reason, by_user, created_at) VALUES (?,?,?,?,'return',?,?,NOW())")
            ->execute([$templeId, $beYear, $month, (int)$rep['revision'], $reason, $userId]);
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->inTransaction() && $pdo->rollBack();
        throw $e;
    }
    report_notify_returned($templeId, $beYear, $month, $reason);
    return [true, 'ส่งรายงานเดือน' . TH_MONTHS[$month] . ' ' . $beYear . ' กลับให้วัดแก้ไขแล้ว' . ($reopened ? ' (เปิดงวดให้อัตโนมัติ)' : '')];
}

/** แจ้งอีเมลวัดว่ารายงานถูกส่งกลับ (ไม่มีอีเมลหรือส่งไม่สำเร็จก็ไม่ขัดขวางการส่งกลับ) */
function report_notify_returned(int $templeId, int $beYear, int $month, string $reason): void
{
    global $CONFIG;
    $t = temple_row($templeId);
    if (!$t || trim((string)($t['email'] ?? '')) === '') {
        return;
    }
    $link = trim((string)($CONFIG['app_url'] ?? ''));
    $body = "เรียน ผู้ดูแล" . $t['name'] . "\n\nรายงานประจำเดือน" . TH_MONTHS[$month] . ' พ.ศ. ' . $beYear . " ของวัดท่านถูกส่งกลับให้แก้ไข\nเหตุผล: " . $reason
        . "\n\nกรุณาเข้าสู่ระบบ แก้ไขรายการให้ถูกต้อง แล้วกดส่งรายงานประจำเดือนใหม่"
        . ($link !== '' ? "\n" . rtrim($link, '/') . '/statements.php' : '') . "\n\nอีเมลนี้ส่งจากระบบอัตโนมัติ";
    try {
        send_mail(trim($t['email']), 'รายงานประจำเดือน' . TH_MONTHS[$month] . ' ' . $beYear . ' ถูกส่งกลับให้แก้ไข', $body);
    } catch (Throwable) {
    }
}

/** ประวัติรับรอง/ส่งกลับของวัดในเดือนนั้น (ใหม่ → เก่า) */
function report_review_events(PDO $pdo, int $templeId, int $beYear, int $month): array
{
    if (!report_approval_available()) {
        return [];
    }
    $st = $pdo->prepare('SELECT e.*, u.full_name FROM report_review_events e LEFT JOIN users u ON u.id = e.by_user
        WHERE e.temple_id = ? AND e.report_year = ? AND e.report_month = ? ORDER BY e.id DESC');
    $st->execute([$templeId, $beYear, $month]);
    return $st->fetchAll();
}

// ===================== ปิดงวดรายเดือน =====================
// เดือนที่ปิดงวดแล้ว: เพิ่ม/แก้ไข/ลบรายการ (รายรับ-รายจ่าย โอน/ฝาก-ถอน) ของวัดนั้นในเดือนนั้นไม่ได้ จนกว่าแอดมินหลัก/แอดมินอำเภอจะ "เปิดงวด" พร้อมเหตุผล
// ปิดงวดได้เมื่อ: ส่งรายงานเดือนนั้นแล้วและยอดตรงกับปัจจุบัน และสิ้นเดือนแล้ว · แก้ยอดยกมาของบัญชีไม่ได้ถ้าวัดมีเดือนที่ปิดงวดอยู่

/** ฐานข้อมูลมีคอลัมน์ปิดงวดหรือยัง (ยังไม่รัน migration 2026_month_close.sql = ไม่มีการล็อกงวด ระบบทำงานแบบเดิม) */
function month_close_available(): bool
{
    static $ok = null;
    if ($ok === null) {
        try {
            $ok = report_available();
            if ($ok) {
                db()->query('SELECT closed_at, closed_by FROM monthly_reports LIMIT 1');
                db()->query('SELECT 1 FROM month_close_events LIMIT 1');
            }
        } catch (PDOException) {
            $ok = false;
        }
    }
    return $ok;
}

/** วันที่ (Y-m-d ค.ศ.) อยู่ในเดือนที่ปิดงวดของวัดนี้หรือไม่ */
function period_closed(PDO $pdo, int $templeId, ?string $ymd): bool
{
    if (!$ymd || !month_close_available() || !preg_match('/^(\d{4})-(\d{2})-\d{2}$/', $ymd, $m)) {
        return false;
    }
    $st = $pdo->prepare('SELECT 1 FROM monthly_reports WHERE temple_id = ? AND report_year = ? AND report_month = ? AND closed_at IS NOT NULL');
    $st->execute([$templeId, (int)$m[1] + 543, (int)$m[2]]);
    return (bool)$st->fetchColumn();
}

/** ข้อความผิดพลาดถ้าวันที่อยู่ในงวดที่ปิดแล้ว ไม่งั้น '' */
function period_lock_error(PDO $pdo, int $templeId, ?string $ymd): string
{
    if (!period_closed($pdo, $templeId, $ymd)) {
        return '';
    }
    [, $mo] = explode('-', $ymd);
    return 'เดือน' . TH_MONTHS[(int)$mo] . ' ' . ((int)substr($ymd, 0, 4) + 543) . ' ปิดงวดแล้ว แก้ไขรายการไม่ได้ — ให้แอดมินหลักหรือแอดมินอำเภอเปิดงวดก่อน';
}

/** วัดนี้มีเดือนที่ปิดงวดอยู่หรือไม่ (ใช้กันการแก้ยอดยกมา) */
function temple_has_closed_month(PDO $pdo, int $templeId): bool
{
    if (!month_close_available()) {
        return false;
    }
    $st = $pdo->prepare('SELECT 1 FROM monthly_reports WHERE temple_id = ? AND closed_at IS NOT NULL LIMIT 1');
    $st->execute([$templeId]);
    return (bool)$st->fetchColumn();
}

/** ปิดงวด @return array{0:bool,1:string} */
function report_close(PDO $pdo, int $templeId, int $beYear, int $month, ?int $userId): array
{
    if (!month_close_available()) {
        return [false, 'ยังไม่ได้รัน migration ปิดงวด (sql/migrations/2026_month_close.sql)'];
    }
    [, $last] = report_month_range($beYear, $month);
    if ($last >= date('Y-m-d')) {
        return [false, 'ปิดงวดได้หลังสิ้นเดือนเท่านั้น'];
    }
    $rep = report_row($pdo, $templeId, $beYear, $month);
    if (!$rep) {
        return [false, 'ต้องส่งรายงานประจำเดือนก่อนจึงจะปิดงวดได้'];
    }
    if (!empty($rep['closed_at'])) {
        return [false, 'เดือนนี้ปิดงวดแล้ว'];
    }
    if (report_state($rep, report_snapshot($pdo, $templeId, $beYear, $month), $beYear, $month) !== 'ok') {
        return [false, 'มีการแก้ไขรายการหลังส่งรายงาน — ส่งรายงานใหม่ก่อนจึงจะปิดงวดได้'];
    }
    $pdo->prepare('UPDATE monthly_reports SET closed_at = NOW(), closed_by = ? WHERE id = ? AND closed_at IS NULL')->execute([$userId, $rep['id']]);
    $pdo->prepare("INSERT INTO month_close_events (temple_id, report_year, report_month, action, by_user, created_at) VALUES (?,?,?,'close',?,NOW())")->execute([$templeId, $beYear, $month, $userId]);
    return [true, 'ปิดงวดเดือน' . TH_MONTHS[$month] . ' ' . $beYear . ' แล้ว'];
}

/** เปิดงวด (ต้องมีเหตุผลอย่างน้อย 5 ตัวอักษร) @return array{0:bool,1:string} */
function report_reopen(PDO $pdo, int $templeId, int $beYear, int $month, ?int $userId, string $reason): array
{
    $reason = mb_substr(trim($reason), 0, 255);
    if (mb_strlen($reason) < 5) {
        return [false, 'กรุณาระบุเหตุผลในการเปิดงวด (อย่างน้อย 5 ตัวอักษร)'];
    }
    $rep = month_close_available() ? report_row($pdo, $templeId, $beYear, $month) : null;
    if (!$rep || empty($rep['closed_at'])) {
        return [false, 'เดือนนี้ยังไม่ได้ปิดงวด'];
    }
    $pdo->prepare('UPDATE monthly_reports SET closed_at = NULL, closed_by = NULL WHERE id = ?')->execute([$rep['id']]);
    $pdo->prepare("INSERT INTO month_close_events (temple_id, report_year, report_month, action, reason, by_user, created_at) VALUES (?,?,?,'reopen',?,?,NOW())")
        ->execute([$templeId, $beYear, $month, $reason, $userId]);
    return [true, 'เปิดงวดเดือน' . TH_MONTHS[$month] . ' ' . $beYear . ' แล้ว (บันทึกเหตุผลไว้)'];
}

/** ประวัติปิด/เปิดงวดของวัดในเดือนนั้น (ใหม่ → เก่า) */
function report_close_events(PDO $pdo, int $templeId, int $beYear, int $month): array
{
    if (!month_close_available()) {
        return [];
    }
    $st = $pdo->prepare('SELECT e.*, u.full_name FROM month_close_events e LEFT JOIN users u ON u.id = e.by_user
        WHERE e.temple_id = ? AND e.report_year = ? AND e.report_month = ? ORDER BY e.id DESC');
    $st->execute([$templeId, $beYear, $month]);
    return $st->fetchAll();
}
