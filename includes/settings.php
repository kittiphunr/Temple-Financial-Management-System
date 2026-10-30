<?php
/**
 * ค่าตั้งที่แอดมินหลักเปลี่ยนได้ในระบบ (ตาราง settings) ซ้อนทับค่าใน config:
 *  province_name = ชื่อจังหวัด (หัวเอกสาร), app_sub = คำบรรยายใต้ชื่อระบบ
 * ใช้เมื่อนำระบบไปใช้ในจังหวัดอื่น — ตั้งอัตโนมัติจากการนำเข้าทะเบียนวัด (admin/import.php)
 */

function settings_all(bool $refresh = false): array
{
    static $cache = null;
    if ($cache === null || $refresh) {
        try {
            $cache = db()->query('SELECT k, v FROM settings')->fetchAll(PDO::FETCH_KEY_PAIR);
        } catch (PDOException) {
            $cache = [];   // ฐานข้อมูลเก่ายังไม่มีตาราง settings (ยังไม่รัน migration)
        }
    }
    return $cache;
}

function setting(string $key, ?string $default = null): ?string
{
    $v = settings_all()[$key] ?? null;
    return ($v !== null && $v !== '') ? $v : $default;
}

function setting_set(string $key, string $value): void
{
    db()->prepare('INSERT INTO settings (k, v) VALUES (?, ?) ON DUPLICATE KEY UPDATE v = VALUES(v)')->execute([$key, $value]);
    settings_all(true);
}

/** ชื่อจังหวัด (ไม่มีคำว่า "จังหวัด") */
function province_name(): string
{
    global $CONFIG;
    return setting('province_name', (string)($CONFIG['province_name'] ?? 'แพร่'));
}

/** คำบรรยายใต้ชื่อระบบ เช่น "สำหรับคณะสงฆ์จังหวัดแพร่" */
function app_subtitle(): string
{
    global $CONFIG;
    return setting('app_sub', (string)($CONFIG['app_sub'] ?? ''));
}
