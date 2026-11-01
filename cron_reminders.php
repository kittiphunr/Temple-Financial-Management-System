<?php
/**
 * แจ้งเตือนอัตโนมัติทางอีเมล (รันด้วย cron วันละครั้ง ช่วงเช้า)
 *   php cron_reminders.php               ส่งตามกติกาของวันนี้
 *   php cron_reminders.php --dry-run     แสดงว่าจะส่งอะไรบ้าง โดยไม่ส่งและไม่บันทึก
 *   php cron_reminders.php --today=2026-11-12   ใช้ทดสอบ (YYYY-MM-DD ปี ค.ศ.) เลือกวันที่ที่จะถือเป็นวันนี้
 * ตัวอย่าง crontab: 0 8 * * * cd /path/to/tfms && php cron_reminders.php >> storage/cron.log 2>&1
 * ใช้ได้เฉพาะบรรทัดคำสั่ง (เรียกผ่านเว็บจะได้ 404) · ตั้งเปิด/ปิดและจำนวนวันที่หน้า ติดตามรายงาน
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
require __DIR__ . '/includes/bootstrap.php';

$dry = in_array('--dry-run', $argv, true);
$today = date('Y-m-d');
foreach (array_slice($argv, 1) as $a) {
    if (str_starts_with($a, '--today=')) {
        $t = substr($a, 8);
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $t) || !checkdate((int)substr($t, 5, 2), (int)substr($t, 8, 2), (int)substr($t, 0, 4))) {
            fwrite(STDERR, "รูปแบบ --today ต้องเป็น YYYY-MM-DD (ค.ศ.)\n");
            exit(2);
        }
        $today = $t;
    }
}
$pdo = db();
if (!reminder_available()) {
    echo "ยังไม่ได้รัน migration sql/migrations/2026_report_notices.sql (และ 2026_monthly_reports.sql) — ไม่มีอะไรให้ทำ\n";
    exit(0);
}
if (!reminder_enabled()) {
    echo "การแจ้งเตือนอัตโนมัติถูกปิดอยู่ (ตั้งที่หน้า ติดตามรายงาน)\n";
    exit(0);
}
$res = reminders_run($pdo, $today, $dry);
$list = $dry ? $res['planned'] : $res['sent'];
echo ($dry ? '[ทดลอง] ' : '') . 'วันที่ ' . $today . ' · เดือนรายงาน ' . $res['month'] . ': '
    . ($list ? implode(', ', array_map(fn($k, $v) => "$k=$v", array_keys($list), $list)) : 'ไม่มีรายการที่ต้องส่ง')
    . ' · ไม่มีอีเมล ' . $res['skipped_no_email'] . ' · ส่งไม่สำเร็จ ' . $res['failed'] . "\n";
if (!$dry) {
    try {
        setting_set('reminder_last_run', json_encode(['at' => date('Y-m-d H:i:s'), 'month' => $res['month'], 'sent' => $res['sent'], 'failed' => $res['failed']], JSON_UNESCAPED_UNICODE));
    } catch (PDOException) {
        // ยังไม่ได้รัน migration ตารางค่าตั้ง (2026_settings.sql): ข้ามการบันทึกเวลารันล่าสุด
    }
}
exit($res['failed'] > 0 ? 1 : 0);
