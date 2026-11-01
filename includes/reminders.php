<?php
/**
 * แจ้งเตือนอัตโนมัติทางอีเมล (รันด้วย cron วันละครั้ง: php cron_reminders.php)
 * เดือนรายงานที่ตรวจ = เดือนก่อนหน้าของวันที่รัน · กำหนดส่ง = วันที่ N ของเดือนถัดไป (report_due_day)
 * ชนิดการแจ้งเตือน (ส่งครั้งเดียวต่อผู้รับต่อเดือนรายงาน บันทึกใน report_notices):
 *  - due_soon        วัดที่ยังไม่ส่งรายงาน เมื่อเหลือไม่เกิน N วันก่อนกำหนด (ค่าเริ่มต้น 3 วัน)
 *  - overdue         วัดที่เกินกำหนดแล้วยังไม่ส่ง (ข้ามถ้าเคยกดเตือนด้วยมือเดือนนี้แล้ว)
 *  - unclosed        รายงานที่อำเภอรับรองครบแล้ว (หรือเกินกำหนดถ้ายังไม่มีระบบรับรอง) แต่ยังไม่ปิดงวด
 *  - returned_nudge  รายงานที่ถูกส่งกลับแก้ไขเกิน 3 วันแล้ววัดยังไม่ส่งใหม่ (ต่อรอบที่ส่ง)
 *  - digest          สรุปให้แอดมินหลัก/เจ้าคณะจังหวัด/แอดมินอำเภอ/เจ้าคณะตำบล (ตามขอบเขตของแต่ละคน) หลังเกินกำหนด 1 วัน
 * ตั้งค่าที่หน้า ติดตามรายงาน (แอดมินหลัก): เปิด/ปิด และจำนวนวันก่อนกำหนด · ไม่รัน migration 2026_report_notices.sql = ฟีเจอร์ปิด
 */

const REMINDER_DAYS_DEFAULT = 3;
const REMINDER_RETURN_NUDGE_DAYS = 3;

function reminder_available(): bool
{
    static $ok = null;
    if ($ok === null) {
        try {
            $ok = report_available();
            $ok && db()->query('SELECT 1 FROM report_notices LIMIT 1');
        } catch (PDOException) {
            $ok = false;
        }
    }
    return $ok;
}

function reminder_enabled(): bool
{
    return setting('reminder_auto', '1') !== '0';
}

function reminder_days_before(): int
{
    $d = (int)setting('reminder_days_before', (string)REMINDER_DAYS_DEFAULT);
    return $d >= 1 && $d <= 20 ? $d : REMINDER_DAYS_DEFAULT;
}

/** ข้อความสถานะการรันล่าสุดสำหรับหน้าติดตามรายงาน (หรือ null ถ้ายังไม่เคยรัน) */
function reminder_last_run(): ?array
{
    $raw = setting('reminder_last_run');
    $d = $raw ? json_decode($raw, true) : null;
    return is_array($d) && isset($d['at']) ? $d : null;
}

/** เดือนรายงานของวันที่รัน = เดือนก่อนหน้า @return array{0:int,1:int} [ปี พ.ศ., เดือน] */
function reminder_report_month(string $today): array
{
    $prev = date('Y-m-01', strtotime(substr($today, 0, 8) . '01 -1 month'));
    return [(int)substr($prev, 0, 4) + 543, (int)substr($prev, 5, 2)];
}

/** จองสิทธิ์ส่ง (กันส่งซ้ำ) @return bool true = จองได้ ส่งได้ */
function reminder_reserve(PDO $pdo, string $recipient, int $y, int $m, string $kind): bool
{
    $st = $pdo->prepare('INSERT IGNORE INTO report_notices (recipient, report_year, report_month, kind, sent_at) VALUES (?,?,?,?,NOW())');
    $st->execute([$recipient, $y, $m, $kind]);
    return $st->rowCount() > 0;
}

function reminder_release(PDO $pdo, string $recipient, int $y, int $m, string $kind): void
{
    $pdo->prepare('DELETE FROM report_notices WHERE recipient = ? AND report_year = ? AND report_month = ? AND kind = ?')->execute([$recipient, $y, $m, $kind]);
}

function reminder_already(PDO $pdo, string $recipient, int $y, int $m, string $kind): bool
{
    $st = $pdo->prepare('SELECT 1 FROM report_notices WHERE recipient = ? AND report_year = ? AND report_month = ? AND kind = ?');
    $st->execute([$recipient, $y, $m, $kind]);
    return (bool)$st->fetchColumn();
}

function reminder_link(string $page): string
{
    global $CONFIG;
    $link = trim((string)($CONFIG['app_url'] ?? ''));
    return $link !== '' ? "\n" . rtrim($link, '/') . '/' . $page : '';
}

/** ข้อความอีเมลถึงวัดตามชนิด @return array{0:string,1:string} [หัวเรื่อง, เนื้อหา] */
function reminder_temple_message(string $kind, array $r, int $y, int $m, string $due): array
{
    $mo = TH_MONTHS[$m] . ' ' . $y;
    $hi = 'เรียน ผู้ดูแล' . $r['name'] . "\n\n";
    $foot = "\n\nอีเมลนี้ส่งจากระบบอัตโนมัติ";
    switch (explode(':', $kind)[0]) {
        case 'due_soon':
            return ['ใกล้ครบกำหนดส่งรายงานประจำเดือน' . $mo, $hi . 'รายงานประจำเดือน' . $mo . ' ของวัดท่านยังไม่ได้ส่ง และจะครบกำหนดวันที่ ' . th_date($due)
                . "\nกรุณาตรวจรายงานแบบที่ 1–3 แล้วกดปุ่ม \"ส่งรายงานประจำเดือน\"" . reminder_link('statements.php') . $foot];
        case 'overdue':
            return ['เกินกำหนดส่งรายงานประจำเดือน' . $mo, $hi . 'รายงานประจำเดือน' . $mo . ' ของวัดท่านเกินกำหนดส่ง (' . th_date($due) . ") แล้วและยังไม่ได้ส่ง\nกรุณาตรวจและส่งรายงานโดยเร็ว" . reminder_link('statements.php') . $foot];
        case 'unclosed':
            return ['รายงานประจำเดือน' . $mo . ' พร้อมปิดงวด', $hi . 'รายงานประจำเดือน' . $mo . " ของวัดท่านผ่านการรับรองครบแล้ว\nกรุณาปิดงวดเพื่อล็อกตัวเลขของเดือนนั้น (ที่หน้ารายงานแบบที่ 1–3 กดปุ่ม \"ปิดงวดเดือนนี้\")" . reminder_link('statements.php') . $foot];
        default:
            return ['รายงานประจำเดือน' . $mo . ' ยังรอการแก้ไข', $hi . 'รายงานประจำเดือน' . $mo . ' ของวัดท่านถูกส่งกลับให้แก้ไข แต่ยังไม่ได้ส่งใหม่'
                . (!empty($r['return_reason']) ? "\nเหตุผลที่ส่งกลับ: " . $r['return_reason'] : '') . "\nกรุณาแก้ไขรายการแล้วกดส่งรายงานประจำเดือนใหม่" . reminder_link('statements.php') . $foot];
    }
}

/** แถวของวัดในขอบเขตของผู้ใช้ staff (ไม่มี session: report_list ได้ทุกวัด แล้วกรองเอง) */
function reminder_rows_for_user(array $rows, array $u, PDO $pdo): array
{
    switch ($u['role']) {
        case 'district':
            return array_values(array_filter($rows, fn($r) => (int)$r['district_id'] === (int)$u['district_id']));
        case 'subdistrict':
            $st = $pdo->prepare('SELECT district_id, subdistrict FROM user_subdistricts WHERE user_id = ?');
            $st->execute([(int)$u['id']]);
            $own = array_map(fn($a) => $a['district_id'] . '|' . $a['subdistrict'], $st->fetchAll());
            return array_values(array_filter($rows, fn($r) => in_array($r['district_id'] . '|' . $r['subdistrict'], $own, true)));
        default:
            return $rows;
    }
}

function reminder_digest_message(array $u, array $rows, int $y, int $m, string $due): array
{
    $c = ['total' => count($rows), 'sent' => 0, 'late' => 0, 'waiting' => 0, 'returned' => 0, 'done' => 0];
    $late = [];
    foreach ($rows as $r) {
        if (in_array($r['state'], ['ok', 'changed'], true)) $c['sent']++;
        if ($r['state'] === 'late') { $c['late']++; $late[] = $r['name']; }
        $ap = $r['approval'] ?? 'none';
        if (in_array($ap, ['waiting', 'sub'], true)) $c['waiting']++;
        if ($ap === 'returned') $c['returned']++;
        if ($ap === 'done') $c['done']++;
    }
    $mo = TH_MONTHS[$m] . ' ' . $y;
    $body = 'เรียน ' . $u['full_name'] . "\n\nสรุปการส่งรายงานประจำเดือน" . $mo . ' (กำหนดส่ง ' . th_date($due) . ")\n\n"
        . 'วัดในความดูแลทั้งหมด ' . $c['total'] . " วัด\n"
        . 'ส่งแล้ว ' . $c['sent'] . " วัด\n"
        . 'ยังไม่ส่งและเกินกำหนด ' . $c['late'] . " วัด\n"
        . 'รอรับรอง ' . $c['waiting'] . " วัด\n"
        . 'ส่งกลับแก้ไข ' . $c['returned'] . " วัด\n"
        . unit_district() . 'รับรองแล้ว ' . $c['done'] . " วัด\n";
    if ($late) {
        $body .= "\nวัดที่เกินกำหนดและยังไม่ส่ง" . (count($late) > 15 ? ' (แสดง 15 วัดแรก)' : '') . ":\n- " . implode("\n- ", array_slice($late, 0, 15)) . "\n";
    }
    return ['สรุปการส่งรายงานประจำเดือน' . $mo, $body . reminder_link('report_status.php') . "\n\nอีเมลนี้ส่งจากระบบอัตโนมัติ"];
}

/**
 * รันการแจ้งเตือนทั้งหมดของวันที่ $today (Y-m-d) · $dryRun = นับอย่างเดียว ไม่จอง ไม่ส่ง
 * @return array{month:string,sent:array<string,int>,skipped_no_email:int,failed:int,planned:array<string,int>}
 */
function reminders_run(PDO $pdo, string $today, bool $dryRun = false): array
{
    [$y, $m] = reminder_report_month($today);
    $due = report_due_date($y, $m);
    $window = date('Y-m-d', strtotime($due . ' -' . reminder_days_before() . ' days'));
    $rows = report_list($pdo, $y, $m, ['today' => $today]);
    $res = ['month' => TH_MONTHS[$m] . ' ' . $y, 'sent' => [], 'planned' => [], 'skipped_no_email' => 0, 'failed' => 0];
    $bump = function (string $k, string $bucket) use (&$res) { $res[$bucket][$k] = ($res[$bucket][$k] ?? 0) + 1; };
    $approval = report_approval_available();

    $send = function (string $recipient, string $kind, string $to, string $subject, string $body) use ($pdo, $y, $m, $dryRun, &$res, $bump) {
        $base = explode(':', $kind)[0];
        if (trim($to) === '') {
            $res['skipped_no_email']++;
            return;
        }
        if ($dryRun) {
            reminder_already($pdo, $recipient, $y, $m, $kind) || $bump($base, 'planned');
            return;
        }
        if (!reminder_reserve($pdo, $recipient, $y, $m, $kind)) {
            return;
        }
        if (send_mail(trim($to), $subject, $body)) {
            $bump($base, 'sent');
        } else {
            reminder_release($pdo, $recipient, $y, $m, $kind);   // ส่งไม่สำเร็จ: คืนสิทธิ์ ให้รอบหน้าลองใหม่
            $res['failed']++;
        }
    };

    foreach ($rows as $r) {
        $rid = 't' . $r['id'];
        $kinds = [];
        if ($r['state'] === 'pending' && $today >= $window && $today <= $due) {
            $kinds[] = 'due_soon';
        }
        if ($r['state'] === 'late' && empty($r['reminded_at'])) {
            $kinds[] = 'overdue';
        }
        if (in_array($r['state'], ['ok'], true) && empty($r['closed_at']) && month_close_available()
            && (($approval && ($r['approval'] ?? '') === 'done') || (!$approval && $today > $due))) {
            $kinds[] = 'unclosed';
        }
        if ($approval && ($r['approval'] ?? '') === 'returned' && !empty($r['returned_at'])
            && substr((string)$r['returned_at'], 0, 10) <= date('Y-m-d', strtotime($today . ' -' . REMINDER_RETURN_NUDGE_DAYS . ' days'))) {
            $kinds[] = 'returned_nudge:' . (int)($r['revision'] ?? 1);
        }
        foreach ($kinds as $kind) {
            [$subj, $body] = reminder_temple_message($kind, $r, $y, $m, $due);
            $before = array_sum($res['sent']);
            $send($rid, $kind, (string)$r['email'], $subj, $body);
            // เตือนเกินกำหนดแล้ว ทำเครื่องหมายเหมือนการกดเตือนด้วยมือ (ปุ่มเตือนในหน้าติดตามจะไม่ส่งซ้ำ)
            if ($kind === 'overdue' && !$dryRun && array_sum($res['sent']) > $before) {
                $pdo->prepare('INSERT IGNORE INTO report_reminders (temple_id, report_year, report_month, sent_at, sent_by) VALUES (?,?,?,NOW(),NULL)')->execute([$r['id'], $y, $m]);
            }
        }
    }

    // สรุปให้ staff หลังเกินกำหนด 1 วัน
    if ($today > $due) {
        $users = $pdo->query("SELECT id, username, full_name, role, district_id, email FROM users WHERE is_active = 1 AND role IN ('admin','province','district','subdistrict')")->fetchAll();
        foreach ($users as $u) {
            $mine = reminder_rows_for_user($rows, $u, $pdo);
            if (!$mine) {
                continue;
            }
            [$subj, $body] = reminder_digest_message($u, $mine, $y, $m, $due);
            $send('u' . $u['id'], 'digest', (string)$u['email'], $subj, $body);
        }
    }
    return $res;
}
